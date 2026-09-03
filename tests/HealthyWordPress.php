<?php

declare(strict_types=1);

namespace WPOpsKit\Tests;

use Brain\Monkey\Functions;

/**
 * A WordPress that passes every readiness check, as the fixture other tests
 * move exactly one input away from. Shared so that "healthy" means the same
 * thing in the check, HTTP and CLI suites.
 */
trait HealthyWordPress {

    /** @var array<string, mixed> */
    protected array $options = [];

    protected string $uploads = '';

    protected function bootHealthyWordPress(): void {
        $this->options = ['db_version' => 58975, 'home' => 'https://example.test'];
        $this->uploads = sys_get_temp_dir() . '/wp-ops-' . bin2hex( random_bytes( 4 ) );
        mkdir( $this->uploads, 0o755, true );

        $GLOBALS['wp_version']    = '6.9.1';
        $GLOBALS['wpdb']          = new \WP_Ops_Fake_Wpdb( ['SELECT 1' => '1'] );
        $GLOBALS['wp_db_version'] = 58975;

        Functions\when( 'get_option' )->alias(
            fn ( string $key, mixed $default = false ): mixed => $this->options[ $key ] ?? $default
        );
        Functions\when( 'wp_upload_dir' )->justReturn( ['basedir' => $this->uploads, 'error' => false] );
        Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
        Functions\when( 'get_transient' )->justReturn( false );
    }

    protected function shutdownHealthyWordPress(): void {
        if ( '' !== $this->uploads && is_dir( $this->uploads ) ) {
            rmdir( $this->uploads );
        }
    }
}
