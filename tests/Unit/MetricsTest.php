<?php

declare(strict_types=1);

namespace WPOpsKit\Tests\Unit;

use Brain\Monkey\Functions;
use WPOpsKit\Metrics;
use WPOpsKit\Tests\TestCase;

/**
 * Exposition format tests. A malformed line does not throw — Prometheus simply
 * drops the scrape and the dashboard goes blank — so the wire format is exactly
 * the kind of thing that has to be asserted rather than eyeballed.
 */
final class MetricsTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();

        $GLOBALS['wp_version'] = '6.9.1';
        Functions\when( 'get_transient' )->justReturn( false );
    }

    public function test_render_always_emits_up_and_build_info(): void {
        $samples = $this->parseExposition( Metrics::render() );

        self::assertSame( '1', $samples['wp_ops_up'] );
        self::assertArrayHasKey(
            'wp_ops_build_info{wp_version="6.9.1",php_version="' . PHP_VERSION . '",plugin_version="0.1.0-test"}',
            $samples
        );
    }

    /**
     * A collector that has never run must be visibly broken rather than absent:
     * a missing series cannot be alerted on, but -1 can.
     */
    public function test_missing_snapshot_reports_age_minus_one_and_no_site_metrics(): void {
        $text    = Metrics::render();
        $samples = $this->parseExposition( $text );

        self::assertSame( '-1', $samples['wp_ops_snapshot_age_seconds'] );
        self::assertStringNotContainsString( 'wp_ops_site_', $text );
    }

    public function test_snapshot_metrics_are_rendered_with_labels(): void {
        Functions\when( 'get_transient' )->justReturn( [
            'ts'      => time() - 90,
            'metrics' => [
                'posts'          => [
                    'post' => ['publish' => 12, 'draft' => 3],
                    'page' => ['publish' => 5],
                ],
                'users_total'    => 4,
                'cron'           => ['events' => 21, 'overdue' => 2, 'oldest_overdue_seconds' => 3600],
                'updates'        => ['core' => 1, 'plugin' => 6, 'theme' => 0],
                'plugins_active' => 18,
                'autoload_bytes' => 812345,
            ],
        ] );

        $samples = $this->parseExposition( Metrics::render() );

        self::assertSame( '12', $samples['wp_ops_site_posts{post_type="post",status="publish"}'] );
        self::assertSame( '3', $samples['wp_ops_site_posts{post_type="post",status="draft"}'] );
        self::assertSame( '5', $samples['wp_ops_site_posts{post_type="page",status="publish"}'] );
        self::assertSame( '4', $samples['wp_ops_site_users_total'] );
        self::assertSame( '21', $samples['wp_ops_site_cron_events'] );
        self::assertSame( '2', $samples['wp_ops_site_cron_overdue_events'] );
        self::assertSame( '3600', $samples['wp_ops_site_cron_oldest_overdue_seconds'] );
        self::assertSame( '1', $samples['wp_ops_site_updates_available{type="core"}'] );
        self::assertSame( '6', $samples['wp_ops_site_updates_available{type="plugin"}'] );
        self::assertSame( '0', $samples['wp_ops_site_updates_available{type="theme"}'] );
        self::assertSame( '18', $samples['wp_ops_site_plugins_active'] );
        self::assertSame( '812345', $samples['wp_ops_site_autoload_options_bytes'] );

        self::assertGreaterThanOrEqual( 90, (int) $samples['wp_ops_snapshot_age_seconds'] );
    }

    /**
     * A partial snapshot — an older schema, or a collector that failed halfway —
     * must still render valid exposition rather than emitting empty values.
     */
    public function test_snapshot_missing_keys_render_as_zero(): void {
        Functions\when( 'get_transient' )->justReturn( ['ts' => time(), 'metrics' => []] );

        $samples = $this->parseExposition( Metrics::render() );

        self::assertSame( '0', $samples['wp_ops_site_users_total'] );
        self::assertSame( '0', $samples['wp_ops_site_cron_events'] );
        self::assertArrayNotHasKey( 'wp_ops_site_posts', $samples );
    }

    public function test_every_rendered_metric_has_help_and_type_lines(): void {
        Functions\when( 'get_transient' )->justReturn( false );

        $text  = Metrics::render();
        $names = [];
        foreach ( explode( "\n", trim( $text ) ) as $line ) {
            if ( '' === $line || str_starts_with( $line, '#' ) ) {
                continue;
            }
            $names[ strtok( strtok( $line, ' ' ), '{' ) ] = true;
        }

        foreach ( array_keys( $names ) as $name ) {
            self::assertStringContainsString( '# HELP ' . $name . ' ', $text );
            self::assertMatchesRegularExpression( '/^# TYPE ' . preg_quote( (string) $name, '/' ) . ' (gauge|counter)$/m', $text );
        }
    }

    public function test_exposition_ends_with_a_newline(): void {
        self::assertStringEndsWith( "\n", Metrics::render() );
    }

    /**
     * Opcache figures are genuinely per-process, which is why they carry the
     * wp_ops_pod_ prefix and keep the pod label at scrape time. CI runs the
     * suite with opcache.enable_cli=1 so this is exercised rather than skipped.
     */
    public function test_pod_metrics_include_opcache_when_it_is_enabled(): void {
        if ( ! function_exists( 'opcache_get_status' ) || ! @opcache_get_status( false ) ) {
            self::markTestSkipped( 'opcache is not enabled for this SAPI.' );
        }

        $samples = $this->parseExposition( Metrics::render() );

        self::assertArrayHasKey( 'wp_ops_pod_opcache_memory_used_bytes', $samples );
        self::assertArrayHasKey( 'wp_ops_pod_opcache_cached_scripts', $samples );
        self::assertArrayHasKey( 'wp_ops_pod_opcache_hits_total', $samples );
    }

    public function test_pod_metrics_always_include_peak_memory(): void {
        $samples = $this->parseExposition( Metrics::render() );

        self::assertGreaterThan( 0, (int) $samples['wp_ops_pod_php_memory_peak_bytes'] );
    }

    /** A metric with no samples is omitted entirely — no orphan HELP/TYPE pair. */
    public function test_emit_skips_metrics_with_no_samples(): void {
        $emit = $this->inScope( Metrics::class, static function ( array &$lines ): void {
            Metrics::emit( $lines, 'wp_ops_nothing', 'gauge', 'Never emitted.', [] );
        } );

        $lines = [];
        $emit( $lines );

        self::assertSame( [], $lines );
    }

    public function test_label_values_are_escaped(): void {
        $emit = $this->inScope( Metrics::class, static function ( array &$lines ): void {
            Metrics::emit( $lines, 'wp_ops_escaping', 'gauge', 'Escaping.', [
                [['name' => 'quote " backslash \\ newline' . "\n" . 'end'], 1],
            ] );
        } );

        $lines = [];
        $emit( $lines );

        self::assertSame(
            'wp_ops_escaping{name="quote \\" backslash \\\\ newline\\nend"} 1',
            $lines[2]
        );
    }

    /** Help text is a comment line, so an embedded newline would corrupt the stream. */
    public function test_help_text_newlines_are_flattened(): void {
        $emit = $this->inScope( Metrics::class, static function ( array &$lines ): void {
            Metrics::emit( $lines, 'wp_ops_help', 'gauge', "first\nsecond", [[[], 1]] );
        } );

        $lines = [];
        $emit( $lines );

        self::assertSame( '# HELP wp_ops_help first second', $lines[0] );
    }

    public function test_float_values_are_rendered_without_trailing_zeroes(): void {
        $value = $this->inScope(
            Metrics::class,
            static fn ( int|float $v ): string => Metrics::value( $v )
        );

        self::assertSame( '1', $value( 1 ) );
        self::assertSame( '0', $value( 0 ) );
        self::assertSame( '-1', $value( -1 ) );
        self::assertSame( '1.5', $value( 1.5 ) );
        self::assertSame( '1', $value( 1.0 ) );
        self::assertSame( '0.000001', $value( 0.000001 ) );
    }
}
