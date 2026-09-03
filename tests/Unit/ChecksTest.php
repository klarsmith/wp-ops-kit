<?php

declare(strict_types=1);

namespace WPOpsKit\Tests\Unit;

use Brain\Monkey\Functions;
use WPOpsKit\Checks;
use WPOpsKit\Tests\TestCase;

/**
 * The readiness checks decide whether a pod takes traffic, so both directions
 * are failure modes worth pinning: a check that wrongly passes serves a broken
 * site, and a check that wrongly fails pulls every replica out of rotation at
 * once. Each test moves exactly one input away from healthy.
 */
final class ChecksTest extends TestCase {

    use \WPOpsKit\Tests\HealthyWordPress;

    protected function setUp(): void {
        parent::setUp();

        $this->bootHealthyWordPress();
    }

    protected function tearDown(): void {
        $this->shutdownHealthyWordPress();

        parent::tearDown();
    }

    public function test_a_healthy_site_passes_every_check(): void {
        $results = Checks::run();

        self::assertTrue( Checks::all_passed( $results ), var_export( $results, true ) );
        self::assertSame( [], Checks::failed_names( $results ) );
    }

    /**
     * Required-plugin checking is opt-in, so the key must be absent entirely
     * rather than present-and-passing when nothing is configured.
     */
    public function test_required_plugins_check_is_absent_unless_configured(): void {
        self::assertArrayNotHasKey( 'required_plugins', Checks::run() );
    }

    public function test_dead_database_fails(): void {
        $wpdb             = new \WP_Ops_Fake_Wpdb( [], null );
        $wpdb->last_error = 'Connection refused';
        $GLOBALS['wpdb']  = $wpdb;

        $results = Checks::run();

        self::assertFalse( $results['db']['ok'] );
        self::assertStringContainsString( 'Connection refused', $results['db']['detail'] );
        self::assertContains( 'db', Checks::failed_names( $results ) );
    }

    /** Errors must be suppressed around the probe query, and restored after it. */
    public function test_database_check_suppresses_and_restores_error_reporting(): void {
        $wpdb = new \WP_Ops_Fake_Wpdb( ['SELECT 1' => '1'] );
        $wpdb->suppress_errors( false );
        $GLOBALS['wpdb'] = $wpdb;

        Checks::run();

        self::assertFalse( $wpdb->suppressing, 'suppress_errors was not restored' );
    }

    /**
     * The check that justifies the plugin. WordPress serves the "database
     * update required" interstitial with a 200, so fpm-ping, / and a stub
     * health.php all pass while the site is entirely down.
     */
    public function test_pending_core_upgrade_fails_readiness(): void {
        $this->options['db_version'] = 57155;
        $GLOBALS['wp_db_version']    = 58975;

        $results = Checks::run();

        self::assertFalse( $results['db_schema']['ok'] );
        self::assertStringContainsString( '57155', $results['db_schema']['detail'] );
        self::assertStringContainsString( '58975', $results['db_schema']['detail'] );
        self::assertFalse( Checks::all_passed( $results ) );
    }

    public function test_matching_schema_versions_pass(): void {
        $results = Checks::run();

        self::assertTrue( $results['db_schema']['ok'] );
        self::assertSame( '58975', $results['db_schema']['detail'] );
    }

    /**
     * An unreadable db_version must fail rather than compare 0 === 0 and pass —
     * this is the branch that would otherwise turn a dead database into a
     * green schema check.
     */
    public function test_unknown_schema_version_fails_rather_than_comparing_zeroes(): void {
        $this->options['db_version'] = 0;
        $GLOBALS['wp_db_version']    = 0;

        $results = Checks::run();

        self::assertFalse( $results['db_schema']['ok'] );
        self::assertStringContainsString( 'could not determine', $results['db_schema']['detail'] );
    }

    public function test_missing_object_cache_is_tolerated_when_not_expected(): void {
        $results = Checks::run();

        self::assertTrue( $results['object_cache']['ok'] );
        self::assertSame( 'internal', $results['object_cache']['detail'] );
    }

    public function test_missing_object_cache_fails_when_the_deployment_expects_one(): void {
        $this->env( 'WP_OPS_EXPECT_OBJECT_CACHE', 'true' );

        $results = Checks::run();

        self::assertFalse( $results['object_cache']['ok'] );
        self::assertStringContainsString( 'dropin is not active', $results['object_cache']['detail'] );
    }

    public function test_working_external_object_cache_passes(): void {
        $store = [];
        Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
        Functions\when( 'wp_cache_set' )->alias(
            function ( string $key, mixed $value, string $group = '', int $ttl = 0 ) use ( &$store ): bool {
                $store[ $group . ':' . $key ] = $value;

                return true;
            }
        );
        Functions\when( 'wp_cache_get' )->alias(
            function ( string $key, string $group = '' ) use ( &$store ): mixed {
                return $store[ $group . ':' . $key ] ?? false;
            }
        );

        $results = Checks::run();

        self::assertTrue( $results['object_cache']['ok'] );
        self::assertSame( 'external', $results['object_cache']['detail'] );
    }

    /**
     * A dropin that is active but whose backend is gone is the dangerous case:
     * writes silently no-op and every page load falls back to the database.
     */
    public function test_unreachable_object_cache_backend_fails(): void {
        Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
        Functions\when( 'wp_cache_set' )->justReturn( false );
        Functions\when( 'wp_cache_get' )->justReturn( false );

        $results = Checks::run();

        self::assertFalse( $results['object_cache']['ok'] );
        self::assertStringContainsString( 'roundtrip failed', $results['object_cache']['detail'] );
    }

    public function test_uploads_error_reported_by_wordpress_fails(): void {
        Functions\when( 'wp_upload_dir' )->justReturn(
            ['basedir' => $this->uploads, 'error' => 'Unable to create directory.']
        );

        $results = Checks::run();

        self::assertFalse( $results['uploads']['ok'] );
        self::assertStringContainsString( 'Unable to create directory.', $results['uploads']['detail'] );
    }

    public function test_missing_uploads_directory_fails(): void {
        Functions\when( 'wp_upload_dir' )->justReturn(
            ['basedir' => $this->uploads . '/does-not-exist', 'error' => false]
        );

        $results = Checks::run();

        self::assertFalse( $results['uploads']['ok'] );
        self::assertStringContainsString( 'basedir missing', $results['uploads']['detail'] );
    }

    public function test_required_plugins_pass_when_all_are_active(): void {
        $this->env( 'WP_OPS_REQUIRED_PLUGINS', 'redis-cache/redis-cache.php, wp-fortress/wp-fortress.php' );
        Functions\when( 'is_plugin_active' )->justReturn( true );

        $results = Checks::run();

        self::assertTrue( $results['required_plugins']['ok'] );
        self::assertSame( '2 active', $results['required_plugins']['detail'] );
    }

    public function test_required_plugins_fail_and_name_the_inactive_ones(): void {
        $this->env( 'WP_OPS_REQUIRED_PLUGINS', 'redis-cache/redis-cache.php,wp-fortress/wp-fortress.php' );
        Functions\when( 'is_plugin_active' )->alias(
            fn ( string $plugin ): bool => 'redis-cache/redis-cache.php' === $plugin
        );

        $results = Checks::run();

        self::assertFalse( $results['required_plugins']['ok'] );
        self::assertStringContainsString( 'wp-fortress/wp-fortress.php', $results['required_plugins']['detail'] );
        self::assertStringNotContainsString( 'redis-cache', $results['required_plugins']['detail'] );
    }
}
