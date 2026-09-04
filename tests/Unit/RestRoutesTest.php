<?php

declare(strict_types=1);

namespace WPOpsKit\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use WPOpsKit\Rest;
use WPOpsKit\Tests\TestCase;

/**
 * Route registration.
 *
 * The namespace is pinned deliberately. Probes address these routes by a URL
 * baked into the Deployment, and on our own fleet wp-fortress only exposes REST
 * namespaces on its allowlist — so renaming "ops" would not break a test
 * elsewhere, it would 404 every probe in the fleet at once.
 */
final class RestRoutesTest extends TestCase {

    public function test_init_defers_registration_to_rest_api_init(): void {
        Actions\expectAdded( 'rest_api_init' )->once();
        Filters\expectAdded( 'rest_authentication_errors' )->once();

        Rest::init();
    }

    /**
     * Priority 1 is load-bearing, not cosmetic: site-level REST lockdowns hook
     * the same filter at the default 10, and whoever runs first decides.
     */
    public function test_the_auth_filter_is_registered_at_priority_one(): void {
        Actions\expectAdded( 'rest_api_init' )->once();
        Filters\expectAdded( 'rest_authentication_errors' )
            ->once()
            ->with( \Mockery::type( 'array' ), 1 );

        Rest::init();
    }

    /** @param array<string, string> $vars */
    private function currentRoute( array $vars = [], string $uri = '' ): void {
        $GLOBALS['wp']             = (object) ['query_vars' => $vars];
        $_SERVER['REQUEST_URI']    = $uri;
    }

    public function test_own_route_is_allowed_through_anonymously(): void {
        $this->currentRoute( ['rest_route' => '/ops/v1/readyz'] );

        self::assertTrue( Rest::allow_anonymous_access( null ) );
    }

    /**
     * WPML and friends prefix the REST root, so query_vars alone is not enough —
     * on imargus-prod rest_url() returns /en/wp-json/ops/v1/readyz.
     */
    public function test_own_route_is_recognised_behind_a_language_prefix(): void {
        $this->currentRoute( [], '/en/wp-json/ops/v1/readyz' );

        self::assertTrue( Rest::allow_anonymous_access( null ) );
    }

    public function test_other_namespaces_are_left_alone(): void {
        $this->currentRoute( ['rest_route' => '/wp/v2/users'], '/wp-json/wp/v2/users' );

        self::assertNull( Rest::allow_anonymous_access( null ) );
    }

    /**
     * A decision another filter already made is final — this must open a door for
     * its own routes, never close or reopen one on someone else's behalf.
     */
    public function test_an_existing_decision_is_never_overridden(): void {
        $this->currentRoute( ['rest_route' => '/ops/v1/readyz'] );

        $error = new \stdClass(); // stands in for a WP_Error from an earlier filter
        self::assertSame( $error, Rest::allow_anonymous_access( $error ) );
        self::assertTrue( Rest::allow_anonymous_access( true ) );
        self::assertFalse( Rest::allow_anonymous_access( false ) );
    }

    public function test_bypass_can_be_switched_off(): void {
        $this->env( 'WP_OPS_REST_BYPASS_AUTH', 'false' );
        $this->currentRoute( ['rest_route' => '/ops/v1/readyz'] );

        self::assertNull( Rest::allow_anonymous_access( null ) );
    }

    public function test_no_route_context_is_not_treated_as_our_route(): void {
        self::assertNull( Rest::allow_anonymous_access( null ) );
    }

    public function test_routes_are_registered_under_the_ops_namespace(): void {
        $routes = [];
        Functions\when( 'register_rest_route' )->alias(
            function ( string $namespace, string $route, array $args ) use ( &$routes ): bool {
                $routes[ $namespace . $route ] = $args;

                return true;
            }
        );

        Rest::register_routes();

        self::assertSame( ['ops/v1/readyz', 'ops/v1/metrics'], array_keys( $routes ) );
    }

    /**
     * Both routes must be reachable without a WordPress session: readiness is
     * called by kubelet and metrics by Prometheus, neither of which carries a
     * cookie or nonce. Authorisation happens inside the handlers instead.
     */
    public function test_both_routes_are_anonymous_get_endpoints(): void {
        $routes = [];
        Functions\when( 'register_rest_route' )->alias(
            function ( string $namespace, string $route, array $args ) use ( &$routes ): bool {
                $routes[ $route ] = $args;

                return true;
            }
        );

        Rest::register_routes();

        foreach ( $routes as $route => $args ) {
            self::assertSame( 'GET', $args['methods'], $route );
            self::assertSame( '__return_true', $args['permission_callback'], $route );
            self::assertIsCallable( $args['callback'], $route );
        }
    }
}
