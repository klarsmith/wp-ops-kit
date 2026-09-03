<?php

declare(strict_types=1);

namespace WPOpsKit\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use WPOpsKit\Snapshot;
use WPOpsKit\Tests\TestCase;

/**
 * The collector. The cron walk is the fiddliest arithmetic in the plugin —
 * WordPress stores cron as timestamp => hook => instance => args, so an event
 * count means summing two levels down, and getting it wrong produces a plausible
 * but quietly meaningless overdue-jobs graph.
 */
final class SnapshotTest extends TestCase {

    /** @return callable(): array{events: int, overdue: int, oldest_overdue_seconds: int} */
    private function cron(): callable {
        return $this->inScope( Snapshot::class, static fn (): array => Snapshot::cron() );
    }

    public function test_cron_returns_zeroes_when_the_array_is_unavailable(): void {
        Functions\when( '_get_cron_array' )->justReturn( false );

        self::assertSame(
            ['events' => 0, 'overdue' => 0, 'oldest_overdue_seconds' => 0],
            ( $this->cron() )()
        );
    }

    public function test_cron_counts_every_instance_of_every_hook(): void {
        $future = time() + 3600;
        Functions\when( '_get_cron_array' )->justReturn( [
            $future => [
                'wp_version_check'  => ['aaa' => ['args' => []]],
                'wp_update_plugins' => ['bbb' => ['args' => []], 'ccc' => ['args' => ['x']]],
            ],
            $future + 60 => [
                'my_hook' => ['ddd' => ['args' => []]],
            ],
        ] );

        $result = ( $this->cron() )();

        self::assertSame( 4, $result['events'] );
        self::assertSame( 0, $result['overdue'] );
        self::assertSame( 0, $result['oldest_overdue_seconds'] );
    }

    public function test_cron_separates_overdue_events_and_reports_the_oldest(): void {
        $now = time();
        Functions\when( '_get_cron_array' )->justReturn( [
            $now - 7200 => ['stuck_hook' => ['aaa' => ['args' => []]]],
            $now - 60   => ['late_hook' => ['bbb' => ['args' => []], 'ccc' => ['args' => []]]],
            $now + 3600 => ['future_hook' => ['ddd' => ['args' => []]]],
        ] );

        $result = ( $this->cron() )();

        self::assertSame( 4, $result['events'] );
        self::assertSame( 3, $result['overdue'] );
        self::assertGreaterThanOrEqual( 7200, $result['oldest_overdue_seconds'] );
        self::assertLessThan( 7300, $result['oldest_overdue_seconds'] );
    }

    public function test_cron_handles_an_empty_schedule(): void {
        Functions\when( '_get_cron_array' )->justReturn( [] );

        $result = ( $this->cron() )();

        self::assertSame( 0, $result['events'] );
    }

    // -------------------------------------------------------------- updates

    public function test_updates_counts_only_core_responses_marked_upgrade(): void {
        $core          = new \stdClass();
        $core->updates = [
            (object) ['response' => 'upgrade'],
            (object) ['response' => 'latest'],
            (object) ['response' => 'upgrade'],
        ];
        $plugins           = new \stdClass();
        $plugins->response = ['a/a.php' => (object) [], 'b/b.php' => (object) []];
        $themes            = new \stdClass();
        $themes->response  = [];

        Functions\when( 'get_site_transient' )->alias(
            fn ( string $key ): mixed => match ( $key ) {
                'update_core'    => $core,
                'update_plugins' => $plugins,
                'update_themes'  => $themes,
                default          => false,
            }
        );

        $updates = $this->inScope( Snapshot::class, static fn (): array => Snapshot::updates() );

        self::assertSame( ['core' => 2, 'plugin' => 2, 'theme' => 0], $updates() );
    }

    /** Update transients are absent on a fresh install; that is zero, not a crash. */
    public function test_updates_are_zero_when_the_transients_are_missing(): void {
        Functions\when( 'get_site_transient' )->justReturn( false );

        $updates = $this->inScope( Snapshot::class, static fn (): array => Snapshot::updates() );

        self::assertSame( ['core' => 0, 'plugin' => 0, 'theme' => 0], $updates() );
    }

    // ------------------------------------------------------------------ get

    public function test_get_returns_null_for_a_missing_or_malformed_snapshot(): void {
        Functions\when( 'get_transient' )->justReturn( false );
        self::assertNull( Snapshot::get() );

        Functions\when( 'get_transient' )->justReturn( ['ts' => 123] );
        self::assertNull( Snapshot::get(), 'A snapshot without metrics must be rejected.' );

        Functions\when( 'get_transient' )->justReturn( 'not-an-array' );
        self::assertNull( Snapshot::get() );
    }

    public function test_get_returns_a_well_formed_snapshot(): void {
        Functions\when( 'get_transient' )->justReturn( ['ts' => 1700000000, 'metrics' => ['users_total' => 3]] );

        self::assertSame( ['ts' => 1700000000, 'metrics' => ['users_total' => 3]], Snapshot::get() );
    }

    // -------------------------------------------------------------- collect

    /**
     * The snapshot must be stored with a zero expiry. If it expired, a dead
     * collector would make the site metrics vanish silently instead of ageing
     * visibly via wp_ops_snapshot_age_seconds.
     */
    public function test_collect_stores_a_non_expiring_transient(): void {
        $this->stubCollectDependencies();

        Functions\expect( 'set_transient' )
            ->once()
            ->with( 'wp_ops_snapshot', Mockery::type( 'array' ), 0 );

        $snapshot = Snapshot::collect();

        self::assertArrayHasKey( 'ts', $snapshot );
        self::assertSame(
            ['posts', 'users_total', 'cron', 'updates', 'plugins_active', 'autoload_bytes'],
            array_keys( $snapshot['metrics'] )
        );
    }

    public function test_collect_shapes_posts_by_type_and_status(): void {
        $this->stubCollectDependencies();
        Functions\when( 'set_transient' )->justReturn( true );

        $metrics = Snapshot::collect()['metrics'];

        self::assertSame( ['publish' => 12, 'draft' => 3], $metrics['posts']['post'] );
        self::assertSame( 4, $metrics['users_total'] );
        self::assertSame( 2, $metrics['plugins_active'] );
        self::assertSame( 812345, $metrics['autoload_bytes'] );
    }

    private function stubCollectDependencies(): void {
        Functions\when( 'get_post_types' )->justReturn( ['post'] );
        Functions\when( 'wp_count_posts' )->justReturn(
            (object) ['publish' => 12, 'draft' => 3, 'future' => 1]
        );
        Functions\when( 'count_users' )->justReturn( ['total_users' => 4] );
        Functions\when( '_get_cron_array' )->justReturn( [] );
        Functions\when( 'get_site_transient' )->justReturn( false );
        Functions\when( 'get_option' )->alias(
            fn ( string $key, mixed $default = false ): mixed
                => 'active_plugins' === $key ? ['a/a.php', 'b/b.php'] : $default
        );

        $GLOBALS['wpdb'] = new \WP_Ops_Fake_Wpdb( ['SUM(LENGTH(option_value))' => '812345'] );
    }
}
