<?php

declare(strict_types=1);

namespace WPOpsKit;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Readiness checks. Every check here must be CHEAP — this runs on every
 * readiness probe, which for a fleet of sites at 10s intervals is a lot of
 * calls. Anything expensive (checksums, remote lookups, table scans) belongs in
 * the periodic Snapshot instead.
 */
final class Checks {

    /**
     * @return array<string, array{ok: bool, detail: string}>
     */
    public static function run(): array {
        $results = [
            'db'            => self::db(),
            'db_schema'     => self::db_schema(),
            'object_cache'  => self::object_cache(),
            'uploads'       => self::uploads_writable(),
        ];

        $required = Config::list( 'WP_OPS_REQUIRED_PLUGINS' );
        if ( [] !== $required ) {
            $results['required_plugins'] = self::required_plugins( $required );
        }

        return $results;
    }

    /** @param array<string, array{ok: bool, detail: string}> $results */
    public static function all_passed( array $results ): bool {
        foreach ( $results as $result ) {
            if ( ! $result['ok'] ) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, array{ok: bool, detail: string}> $results @return list<string> */
    public static function failed_names( array $results ): array {
        $failed = [];
        foreach ( $results as $name => $result ) {
            if ( ! $result['ok'] ) {
                $failed[] = $name;
            }
        }

        return $failed;
    }

    private static function ok( string $detail = '' ): array {
        return ['ok' => true, 'detail' => $detail];
    }

    private static function fail( string $detail ): array {
        return ['ok' => false, 'detail' => $detail];
    }

    private static function db(): array {
        global $wpdb;

        // Suppress errors so a dead DB gives us a clean false rather than a
        // fatal inside the probe handler.
        $suppress = $wpdb->suppress_errors( true );
        $value    = $wpdb->get_var( 'SELECT 1' );
        $wpdb->suppress_errors( $suppress );

        return '1' === (string) $value
            ? self::ok()
            : self::fail( 'SELECT 1 failed: ' . ( $wpdb->last_error ?: 'no response' ) );
    }

    /**
     * The check nothing else does, and the one that matters most.
     *
     * When the schema version recorded in the database is behind the version
     * baked into core, WordPress stops serving the site and redirects every
     * request to the "database update required" interstitial. PHP-FPM is happy,
     * the homepage returns 200, and the site is completely broken — so a pod in
     * this state passes every probe in common use. It must not be ready.
     */
    private static function db_schema(): array {
        global $wp_db_version;

        $installed = (int) get_option( 'db_version' );
        $expected  = (int) $wp_db_version;

        if ( 0 === $installed || 0 === $expected ) {
            return self::fail( 'could not determine db_version' );
        }

        return $installed === $expected
            ? self::ok( (string) $installed )
            : self::fail( sprintf( 'db_version %d != core %d (upgrade pending)', $installed, $expected ) );
    }

    /**
     * A missing object-cache dropin is a performance cliff rather than an
     * outage, so it only fails readiness when the deployment declares that it
     * expects one. A configured-but-unreachable Redis always fails.
     */
    private static function object_cache(): array {
        $expected = Config::bool( 'WP_OPS_EXPECT_OBJECT_CACHE', false );
        $external = wp_using_ext_object_cache();

        if ( ! $external ) {
            return $expected
                ? self::fail( 'external object cache expected but dropin is not active' )
                : self::ok( 'internal' );
        }

        $key   = 'wp_ops_probe';
        $value = (string) microtime( true );

        wp_cache_set( $key, $value, 'wp_ops_kit', 30 );
        $read = wp_cache_get( $key, 'wp_ops_kit' );

        return $read === $value
            ? self::ok( 'external' )
            : self::fail( 'object cache roundtrip failed (backend unreachable?)' );
    }

    private static function uploads_writable(): array {
        // basedir is present even when the dir is unwritable; error is set when
        // WordPress itself already failed to create it.
        $uploads = wp_upload_dir( null, false );

        if ( ! empty( $uploads['error'] ) ) {
            return self::fail( (string) $uploads['error'] );
        }

        $dir = $uploads['basedir'] ?? '';
        if ( '' === $dir || ! is_dir( $dir ) ) {
            return self::fail( 'uploads basedir missing: ' . $dir );
        }

        return is_writable( $dir ) ? self::ok() : self::fail( 'uploads not writable: ' . $dir );
    }

    /** @param list<string> $required */
    private static function required_plugins( array $required ): array {
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $missing = [];
        foreach ( $required as $plugin ) {
            if ( ! is_plugin_active( $plugin ) ) {
                $missing[] = $plugin;
            }
        }

        return [] === $missing
            ? self::ok( sprintf( '%d active', count( $required ) ) )
            : self::fail( 'inactive: ' . implode( ', ', $missing ) );
    }
}
