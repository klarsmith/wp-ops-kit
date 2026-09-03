<?php

declare(strict_types=1);

namespace WPOpsKit;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Structured JSON logging to stderr.
 *
 * In a container, debug.log is a file nobody will ever read on a filesystem that
 * may well be read-only. One JSON object per line on stderr is what the log
 * pipeline actually wants, and it carries a request id so a fatal can be tied
 * back to the request that caused it.
 *
 * Opt in with WP_OPS_LOG_JSON=true. Disabled by default — a plugin that starts
 * rewriting a site's logging the moment it is activated is a bad guest.
 */
final class Logger {

    private static ?string $request_id = null;
    /** @var resource|null */
    private static $stream = null;

    public static function init(): void {
        if ( ! Config::bool( 'WP_OPS_LOG_JSON', false ) ) {
            return;
        }

        register_shutdown_function( [self::class, 'capture_fatal'] );

        add_action( 'wp_login_failed', static function ( $username ): void {
            self::log( 'warning', 'login_failed', ['username' => (string) $username] );
        } );

        add_action( 'wp_login', static function ( $login ): void {
            self::log( 'info', 'login_success', ['username' => (string) $login] );
        } );
    }

    /**
     * Fatals never reach an action hook, so the shutdown handler is the only
     * place they can be caught and rendered as structured output.
     */
    public static function capture_fatal(): void {
        $error = error_get_last();
        if ( null === $error || ! in_array( $error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true ) ) {
            return;
        }

        self::log( 'error', 'php_fatal', [
            'message' => $error['message'],
            'file'    => $error['file'],
            'line'    => $error['line'],
        ] );
    }

    /** @param array<string, mixed> $context */
    public static function log( string $level, string $event, array $context = [] ): void {
        $record = [
            'ts'         => gmdate( 'c' ),
            'level'      => $level,
            'event'      => $event,
            'request_id' => self::request_id(),
            'site'       => self::site(),
        ] + $context;

        $json = wp_json_encode( $record );
        if ( false === $json ) {
            return;
        }

        $stream = self::stream();
        if ( null !== $stream ) {
            fwrite( $stream, $json . "\n" );
        }
    }

    private static function request_id(): string {
        if ( null !== self::$request_id ) {
            return self::$request_id;
        }

        // Prefer an id the ingress already assigned so logs correlate across
        // the whole request path rather than only within PHP.
        foreach ( ['HTTP_X_REQUEST_ID', 'HTTP_X_AMZN_TRACE_ID', 'HTTP_TRACEPARENT'] as $header ) {
            $value = $_SERVER[ $header ] ?? '';
            if ( is_string( $value ) && '' !== $value ) {
                return self::$request_id = substr( $value, 0, 128 );
            }
        }

        return self::$request_id = bin2hex( random_bytes( 8 ) );
    }

    private static function site(): string {
        $site = Config::get( 'WP_OPS_SITE_NAME' );
        if ( is_string( $site ) && '' !== $site ) {
            return $site;
        }

        return (string) parse_url( (string) get_option( 'home' ), PHP_URL_HOST );
    }

    /** @return resource|null */
    private static function stream() {
        if ( null === self::$stream ) {
            $stream = fopen( 'php://stderr', 'w' );
            if ( false === $stream ) {
                return null;
            }
            self::$stream = $stream;
        }

        return self::$stream;
    }
}
