<?php

declare(strict_types=1);

namespace WPOpsKit;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The periodic collector.
 *
 * Everything expensive lives here and runs on a schedule (a real Kubernetes
 * CronJob calling `wp ops collect`), never on the scrape. /metrics then just
 * serialises the stored result, so scrape cost is O(1) and database load is
 * bounded no matter how many replicas or how aggressive the scrape interval.
 *
 * Storage is a non-expiring transient. With an external object cache active
 * WordPress routes transients to Redis automatically, which is what makes the
 * snapshot shared across every pod of the site — the single code path is
 * correct in both configurations.
 *
 * The snapshot deliberately never expires. If it did, a dead collector would
 * make the site metrics silently vanish; instead they go stale and
 * wp_ops_snapshot_age_seconds climbs, which is alertable.
 */
final class Snapshot {

    private const KEY = 'wp_ops_snapshot';

    /** @return array{ts: int, metrics: array<string, mixed>} */
    public static function collect(): array {
        $snapshot = [
            'ts'      => time(),
            'metrics' => [
                'posts'             => self::posts(),
                'users_total'       => self::users_total(),
                'cron'              => self::cron(),
                'updates'           => self::updates(),
                'plugins_active'    => self::plugins_active(),
                'autoload_bytes'    => self::autoload_bytes(),
            ],
        ];

        set_transient( self::KEY, $snapshot, 0 );

        return $snapshot;
    }

    /** @return array{ts: int, metrics: array<string, mixed>}|null */
    public static function get(): ?array {
        $snapshot = get_transient( self::KEY );

        return is_array( $snapshot ) && isset( $snapshot['ts'], $snapshot['metrics'] ) ? $snapshot : null;
    }

    /** @return array<string, array<string, int>> post_type => status => count */
    private static function posts(): array {
        $out = [];
        foreach ( get_post_types( ['public' => true], 'names' ) as $type ) {
            $counts = (array) wp_count_posts( $type );
            foreach ( ['publish', 'draft', 'pending', 'private', 'trash'] as $status ) {
                if ( isset( $counts[ $status ] ) ) {
                    $out[ $type ][ $status ] = (int) $counts[ $status ];
                }
            }
        }

        return $out;
    }

    private static function users_total(): int {
        $counts = count_users();

        return (int) ( $counts['total_users'] ?? 0 );
    }

    /** @return array{events: int, overdue: int, oldest_overdue_seconds: int} */
    private static function cron(): array {
        $cron = _get_cron_array();
        if ( ! is_array( $cron ) ) {
            return ['events' => 0, 'overdue' => 0, 'oldest_overdue_seconds' => 0];
        }

        $now     = time();
        $events  = 0;
        $overdue = 0;
        $oldest  = 0;

        foreach ( $cron as $timestamp => $hooks ) {
            $count   = array_sum( array_map( 'count', (array) $hooks ) );
            $events += $count;

            if ( $timestamp < $now ) {
                $overdue += $count;
                $age      = $now - (int) $timestamp;
                $oldest   = max( $oldest, $age );
            }
        }

        return [
            'events'                 => $events,
            'overdue'                => $overdue,
            'oldest_overdue_seconds' => $oldest,
        ];
    }

    /**
     * Read the update transients only. Calling the update APIs directly here
     * would turn every collection into an outbound request to wordpress.org,
     * which is both slow and a good way to get rate-limited across a fleet.
     *
     * @return array{core: int, plugin: int, theme: int}
     */
    private static function updates(): array {
        $core   = get_site_transient( 'update_core' );
        $plugin = get_site_transient( 'update_plugins' );
        $theme  = get_site_transient( 'update_themes' );

        $core_count = 0;
        foreach ( (array) ( $core->updates ?? [] ) as $update ) {
            if ( 'upgrade' === ( $update->response ?? '' ) ) {
                ++$core_count;
            }
        }

        return [
            'core'   => $core_count,
            'plugin' => count( (array) ( $plugin->response ?? [] ) ),
            'theme'  => count( (array) ( $theme->response ?? [] ) ),
        ];
    }

    private static function plugins_active(): int {
        return count( (array) get_option( 'active_plugins', [] ) );
    }

    /**
     * Autoloaded option bloat is the classic silent WordPress performance leak:
     * every request pays for it, and nothing in core surfaces it.
     *
     * WordPress 6.6 widened the autoload column beyond yes/no, so match all of
     * the values that actually mean "load this on every request".
     */
    private static function autoload_bytes(): int {
        global $wpdb;

        $suppress = $wpdb->suppress_errors( true );
        $bytes    = $wpdb->get_var(
            "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN ('yes', 'on', 'auto')"
        );
        $wpdb->suppress_errors( $suppress );

        return (int) $bytes;
    }
}
