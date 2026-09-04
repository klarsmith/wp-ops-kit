<?php

declare(strict_types=1);

namespace WPOpsKit;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The HTTP surface: /wp-json/ops/v1/readyz and /wp-json/ops/v1/metrics.
 *
 * Note what is NOT here: liveness. A liveness probe must stay bootstrap-free and
 * must not consult the database — if it did, a single database outage would fail
 * liveness on every pod of every site simultaneously and restart-storm the whole
 * fleet, turning a recoverable dependency blip into a self-inflicted outage.
 * Liveness stays a flat PHP file served by the web server; readiness is where
 * the truth about WordPress belongs.
 */
final class Rest {

    private const NS = 'ops/v1';

    public static function init(): void {
        // Priority 1 so the decision is made before site-level lockdowns run. A
        // theme or security plugin returning a blanket WP_Error for anonymous
        // callers is common on hardened installs, and it would otherwise block
        // the probes silently — the exact failure this plugin exists to remove.
        add_filter( 'rest_authentication_errors', [self::class, 'allow_anonymous_access'], 1 );
        add_action( 'rest_api_init', [self::class, 'register_routes'] );
    }

    /**
     * Assert that this plugin's own two routes are reachable without a session.
     *
     * kubelet carries no cookie and Prometheus carries no nonce, so a blanket
     * "REST is for logged-in users only" filter takes the probes down with it.
     * The scope is deliberately narrow: only this plugin's namespace, only when
     * no earlier filter has already decided, and the routes protect themselves —
     * anonymous readyz returns check names without detail, and metrics 404s
     * unless a token is configured and presented.
     *
     * Returning null here would be a no-op: null is the "undecided" value the
     * chain starts with, so a later filter would still run and block the request.
     * It has to be true.
     *
     * Set WP_OPS_REST_BYPASS_AUTH=false to leave the site's own rules in charge,
     * accepting that the endpoints may then be unreachable.
     *
     * @param  WP_Error|bool|null $result
     * @return WP_Error|bool|null
     */
    public static function allow_anonymous_access( $result ) {
        // Never override a decision another filter has already made.
        if ( null !== $result ) {
            return $result;
        }

        if ( ! Config::bool( 'WP_OPS_REST_BYPASS_AUTH', true ) || ! self::is_own_route() ) {
            return $result;
        }

        return true;
    }

    /**
     * Both signals are needed: query_vars is the reliable one but is not always
     * populated this early, and REQUEST_URI survives prefixes that plugins add
     * to the REST root (WPML, for instance, serves /en/wp-json/...).
     */
    private static function is_own_route(): bool {
        $route = $GLOBALS['wp']->query_vars['rest_route'] ?? '';
        if ( is_string( $route ) && str_starts_with( $route, '/' . self::NS . '/' ) ) {
            return true;
        }

        $uri = $_SERVER['REQUEST_URI'] ?? '';

        return is_string( $uri ) && str_contains( $uri, '/wp-json/' . self::NS . '/' );
    }

    public static function register_routes(): void {
        register_rest_route( self::NS, '/readyz', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'readyz'],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( self::NS, '/metrics', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'metrics'],
            'permission_callback' => '__return_true',
        ] );
    }

    public static function readyz( \WP_REST_Request $request ): \WP_REST_Response {
        $results = Checks::run();
        $passed  = Checks::all_passed( $results );

        // Unauthenticated callers get the verdict and the names of failing
        // checks, but not the detail — "db_version 57155 != core 58975" is
        // useful to an operator and equally useful to an attacker.
        if ( self::authorized( $request ) ) {
            $body = [
                'status' => $passed ? 'ok' : 'fail',
                'checks' => $results,
            ];
        } else {
            $body = [
                'status' => $passed ? 'ok' : 'fail',
                'failed' => Checks::failed_names( $results ),
            ];
        }

        $response = new \WP_REST_Response( $body, $passed ? 200 : 503 );
        $response->header( 'Cache-Control', 'no-store' );

        return $response;
    }

    public static function metrics( \WP_REST_Request $request ): \WP_REST_Response {
        if ( ! self::authorized( $request ) ) {
            // 404 rather than 401: an unauthenticated caller learns nothing
            // about whether this site exports metrics at all.
            return new \WP_REST_Response( ['code' => 'rest_no_route'], 404 );
        }

        $text = Metrics::render();

        // Serve raw exposition text instead of the JSON the REST server would
        // otherwise wrap it in, while still letting WordPress shut down cleanly.
        add_filter( 'rest_pre_serve_request', static function ( $served ) use ( $text ) {
            header( 'Content-Type: text/plain; version=0.0.4; charset=utf-8' );
            header( 'Cache-Control: no-store' );
            echo $text; // phpcs:ignore WordPress.Security.EscapeOutput -- Prometheus exposition, not HTML.

            return true;
        } );

        return new \WP_REST_Response( null, 200 );
    }

    private static function authorized( \WP_REST_Request $request ): bool {
        $expected = Config::token();
        if ( null === $expected ) {
            return false;
        }

        $header = (string) $request->get_header( 'authorization' );
        if ( 0 === stripos( $header, 'bearer ' ) ) {
            $presented = substr( $header, 7 );
        } else {
            $presented = (string) $request->get_header( 'x_ops_token' );
        }

        return '' !== $presented && hash_equals( $expected, $presented );
    }
}
