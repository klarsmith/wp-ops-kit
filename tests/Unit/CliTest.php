<?php

declare(strict_types=1);

namespace WPOpsKit\Tests\Unit;

use Brain\Monkey\Functions;
use WPOpsKit\Cli;
use WPOpsKit\Tests\HealthyWordPress;
use WPOpsKit\Tests\TestCase;

/**
 * The WP-CLI surface.
 *
 * `wp ops check` is the operator gate — the thing a rollout or a runbook shells
 * out to — so its exit status is the contract, not its output. `wp ops collect`
 * is what the Kubernetes CronJob calls; without it the metrics never refresh.
 */
final class CliTest extends TestCase {

    use HealthyWordPress;

    protected function setUp(): void {
        parent::setUp();

        $this->bootHealthyWordPress();
        \WP_CLI::reset();
    }

    protected function tearDown(): void {
        $this->shutdownHealthyWordPress();
        \WP_CLI::reset();

        parent::tearDown();
    }

    public function test_init_registers_the_three_ops_commands(): void {
        Cli::init();

        self::assertSame(
            ['ops collect', 'ops check', 'ops metrics'],
            array_keys( \WP_CLI::$commands )
        );
    }

    public function test_every_command_has_a_short_description(): void {
        Cli::init();

        foreach ( \WP_CLI::$commands as $name => $args ) {
            self::assertNotEmpty( $args['shortdesc'] ?? '', $name . ' is missing a shortdesc' );
        }
    }

    public function test_check_succeeds_on_a_healthy_site(): void {
        Cli::check();

        self::assertContains( ['success', 'All readiness checks passed.'], \WP_CLI::$output );
    }

    /**
     * The gate. A failing check must exit non-zero — if it only printed a
     * warning, every caller that trusts the exit status would carry on.
     */
    public function test_check_exits_non_zero_when_a_check_fails(): void {
        $this->options['db_version'] = 57155;

        try {
            Cli::check();
            self::fail( 'wp ops check must halt when a readiness check fails.' );
        } catch ( \WP_Ops_Cli_Halt $halt ) {
            self::assertSame( 1, $halt->getCode() );
        }

        $warnings = array_filter( \WP_CLI::$output, static fn ( array $line ): bool => 'warning' === $line[0] );
        self::assertCount( 1, $warnings );
        self::assertStringContainsString( 'db_schema', reset( $warnings )[1] );
    }

    public function test_check_reports_each_check_by_name(): void {
        Cli::check();

        $text = implode( "\n", array_column( \WP_CLI::$output, 1 ) );
        foreach ( ['db', 'db_schema', 'object_cache', 'uploads'] as $name ) {
            self::assertStringContainsString( $name, $text );
        }
    }

    public function test_collect_refreshes_the_snapshot_and_reports_the_group_count(): void {
        $this->stubCollectDependencies();
        Functions\when( 'set_transient' )->justReturn( true );

        Cli::collect();

        $success = end( \WP_CLI::$output );
        self::assertSame( 'success', $success[0] );
        self::assertStringContainsString( '6 metric groups', $success[1] );
    }

    public function test_metrics_prints_exposition_without_a_trailing_blank_line(): void {
        Cli::metrics();

        $line = end( \WP_CLI::$output );
        self::assertSame( 'line', $line[0] );
        self::assertStringContainsString( '# TYPE wp_ops_up gauge', $line[1] );
        self::assertStringEndsNotWith( "\n", $line[1] );
    }

    private function stubCollectDependencies(): void {
        Functions\when( 'get_post_types' )->justReturn( ['post'] );
        Functions\when( 'wp_count_posts' )->justReturn( (object) ['publish' => 1] );
        Functions\when( 'count_users' )->justReturn( ['total_users' => 1] );
        Functions\when( '_get_cron_array' )->justReturn( [] );
        Functions\when( 'get_site_transient' )->justReturn( false );
    }
}
