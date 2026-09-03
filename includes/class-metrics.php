<?php

declare(strict_types=1);

namespace WPOpsKit;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Prometheus text exposition.
 *
 * Metric names are split into two families on purpose:
 *
 *   wp_ops_pod_*   genuinely per-process — opcache, peak memory. These legitimately
 *                  differ between replicas, so keep the pod label when scraping.
 *
 *   wp_ops_site_*  derived from the shared snapshot, therefore IDENTICAL across
 *                  every replica of a site. Scraping N pods would otherwise give
 *                  you N duplicate series saying the same thing; drop the pod
 *                  label for these in the scrape relabel config (see README).
 */
final class Metrics {

    public static function render(): string {
        $lines = [];

        self::emit( $lines, 'wp_ops_up', 'gauge', 'Always 1 — the exporter answered.', [
            [[], 1],
        ] );

        self::build_info( $lines );
        self::snapshot_metrics( $lines );
        self::pod_metrics( $lines );

        return implode( "\n", $lines ) . "\n";
    }

    private static function build_info( array &$lines ): void {
        global $wp_version;

        self::emit( $lines, 'wp_ops_build_info', 'gauge', 'Build metadata; value is always 1.', [
            [
                [
                    'wp_version'     => (string) $wp_version,
                    'php_version'    => PHP_VERSION,
                    'plugin_version' => WP_OPS_KIT_VERSION,
                ],
                1,
            ],
        ] );
    }

    private static function snapshot_metrics( array &$lines ): void {
        $snapshot = Snapshot::get();

        // Age is emitted even when there is no snapshot at all (-1), so a
        // collector that has never run is as visible as one that has died.
        self::emit( $lines, 'wp_ops_snapshot_age_seconds', 'gauge',
            'Seconds since the last successful collection; -1 if never collected.', [
                [[], null === $snapshot ? -1 : time() - (int) $snapshot['ts']],
            ] );

        if ( null === $snapshot ) {
            return;
        }

        $m = $snapshot['metrics'];

        $post_samples = [];
        foreach ( (array) ( $m['posts'] ?? [] ) as $type => $statuses ) {
            foreach ( (array) $statuses as $status => $count ) {
                $post_samples[] = [['post_type' => (string) $type, 'status' => (string) $status], (int) $count];
            }
        }
        self::emit( $lines, 'wp_ops_site_posts', 'gauge', 'Posts by type and status.', $post_samples );

        self::emit( $lines, 'wp_ops_site_users_total', 'gauge', 'Registered users.', [
            [[], (int) ( $m['users_total'] ?? 0 )],
        ] );

        $cron = (array) ( $m['cron'] ?? [] );
        self::emit( $lines, 'wp_ops_site_cron_events', 'gauge', 'Scheduled cron events.', [
            [[], (int) ( $cron['events'] ?? 0 )],
        ] );
        self::emit( $lines, 'wp_ops_site_cron_overdue_events', 'gauge',
            'Cron events whose scheduled time has passed.', [
                [[], (int) ( $cron['overdue'] ?? 0 )],
            ] );
        self::emit( $lines, 'wp_ops_site_cron_oldest_overdue_seconds', 'gauge',
            'Age of the most overdue cron event.', [
                [[], (int) ( $cron['oldest_overdue_seconds'] ?? 0 )],
            ] );

        $updates = (array) ( $m['updates'] ?? [] );
        self::emit( $lines, 'wp_ops_site_updates_available', 'gauge',
            'Pending updates, by target type.', [
                [['type' => 'core'], (int) ( $updates['core'] ?? 0 )],
                [['type' => 'plugin'], (int) ( $updates['plugin'] ?? 0 )],
                [['type' => 'theme'], (int) ( $updates['theme'] ?? 0 )],
            ] );

        self::emit( $lines, 'wp_ops_site_plugins_active', 'gauge', 'Active plugins.', [
            [[], (int) ( $m['plugins_active'] ?? 0 )],
        ] );

        self::emit( $lines, 'wp_ops_site_autoload_options_bytes', 'gauge',
            'Total size of autoloaded options — paid on every single request.', [
                [[], (int) ( $m['autoload_bytes'] ?? 0 )],
            ] );
    }

    private static function pod_metrics( array &$lines ): void {
        self::emit( $lines, 'wp_ops_pod_php_memory_peak_bytes', 'gauge',
            'Peak PHP memory for the request serving this scrape.', [
                [[], memory_get_peak_usage( true )],
            ] );

        if ( ! function_exists( 'opcache_get_status' ) ) {
            return;
        }

        // false = skip the per-script list; it is large and we never use it.
        $status = @opcache_get_status( false );
        if ( ! is_array( $status ) || empty( $status['opcache_enabled'] ) ) {
            return;
        }

        $memory = (array) ( $status['memory_usage'] ?? [] );
        $stats  = (array) ( $status['opcache_statistics'] ?? [] );

        self::emit( $lines, 'wp_ops_pod_opcache_memory_used_bytes', 'gauge', 'Opcache memory in use.', [
            [[], (int) ( $memory['used_memory'] ?? 0 )],
        ] );
        self::emit( $lines, 'wp_ops_pod_opcache_memory_free_bytes', 'gauge', 'Opcache memory free.', [
            [[], (int) ( $memory['free_memory'] ?? 0 )],
        ] );
        self::emit( $lines, 'wp_ops_pod_opcache_cached_scripts', 'gauge', 'Scripts held in opcache.', [
            [[], (int) ( $stats['num_cached_scripts'] ?? 0 )],
        ] );
        self::emit( $lines, 'wp_ops_pod_opcache_hits_total', 'counter', 'Opcache hits.', [
            [[], (int) ( $stats['hits'] ?? 0 )],
        ] );
        self::emit( $lines, 'wp_ops_pod_opcache_misses_total', 'counter', 'Opcache misses.', [
            [[], (int) ( $stats['misses'] ?? 0 )],
        ] );
    }

    /**
     * @param list<string>                              $lines
     * @param list<array{0: array<string,string>, 1: int|float}> $samples
     */
    private static function emit( array &$lines, string $name, string $type, string $help, array $samples ): void {
        if ( [] === $samples ) {
            return;
        }

        $lines[] = '# HELP ' . $name . ' ' . str_replace( ["\n", '\\'], [' ', '\\\\'], $help );
        $lines[] = '# TYPE ' . $name . ' ' . $type;

        foreach ( $samples as [$labels, $value] ) {
            $lines[] = $name . self::labels( $labels ) . ' ' . self::value( $value );
        }
    }

    /** @param array<string, string> $labels */
    private static function labels( array $labels ): string {
        if ( [] === $labels ) {
            return '';
        }

        $parts = [];
        foreach ( $labels as $key => $value ) {
            $parts[] = $key . '="' . self::escape( (string) $value ) . '"';
        }

        return '{' . implode( ',', $parts ) . '}';
    }

    private static function escape( string $value ): string {
        return str_replace( ['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value );
    }

    private static function value( int|float $value ): string {
        return is_float( $value ) ? rtrim( rtrim( sprintf( '%.6F', $value ), '0' ), '.' ) : (string) $value;
    }
}
