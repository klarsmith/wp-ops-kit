<?php
/**
 * Periodic metrics collector backing the wp_ops_site_* family.
 *
 * @package WPOpsKit
 */

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

	/**
	 * Collect every metric group, store the result and return it.
	 *
	 * @return array{ts: int, metrics: array<string, mixed>}
	 */
	public static function collect(): array {
		$snapshot = array(
			'ts'      => time(),
			'metrics' => array(
				'posts'          => self::posts(),
				'users_total'    => self::users_total(),
				'cron'           => self::cron(),
				'updates'        => self::updates(),
				'plugins_active' => self::plugins_active(),
				'autoload_bytes' => self::autoload_bytes(),
			),
		);

		set_transient( self::KEY, $snapshot, 0 );

		return $snapshot;
	}

	/**
	 * The stored snapshot, or null when none has been collected yet.
	 *
	 * @return array{ts: int, metrics: array<string, mixed>}|null
	 */
	public static function get(): ?array {
		$snapshot = get_transient( self::KEY );

		return is_array( $snapshot ) && isset( $snapshot['ts'], $snapshot['metrics'] ) ? $snapshot : null;
	}

	/**
	 * Post counts for every public post type.
	 *
	 * @return array<string, array<string, int>> post_type => status => count.
	 */
	private static function posts(): array {
		$out = array();
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $type ) {
			$counts = (array) wp_count_posts( $type );
			foreach ( array( 'publish', 'draft', 'pending', 'private', 'trash' ) as $status ) {
				if ( ! isset( $counts[ $status ] ) ) {
					continue;
				}

				$count = (int) $counts[ $status ];

				// `publish` is always kept, even at zero: a series that stops
				// being emitted reads as stale in Prometheus rather than as 0,
				// and "the content disappeared" is the one thing here worth
				// alerting on. The other statuses at zero are pure noise — on
				// our own fleet they were 26 of 39 exported series.
				if ( 0 === $count && 'publish' !== $status ) {
					continue;
				}

				$out[ $type ][ $status ] = $count;
			}
		}

		return $out;
	}

	/**
	 * Total registered users.
	 *
	 * @return int
	 */
	private static function users_total(): int {
		$counts = count_users();

		return (int) $counts['total_users'];
	}

	/**
	 * Scheduled cron events, how many are overdue and how old the oldest is.
	 *
	 * @return array{events: int, overdue: int, oldest_overdue_seconds: int}
	 */
	private static function cron(): array {
		/**
		 * Core has returned an array unconditionally since 6.1; the pre-6.1
		 * `false` contract is still honoured because it costs one branch.
		 *
		 * @var array<int, array<string, mixed>>|false $cron
		 */
		$cron = _get_cron_array();
		if ( ! is_array( $cron ) ) {
			return array(
				'events'                 => 0,
				'overdue'                => 0,
				'oldest_overdue_seconds' => 0,
			);
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

		return array(
			'events'                 => $events,
			'overdue'                => $overdue,
			'oldest_overdue_seconds' => $oldest,
		);
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
		foreach ( (array) ( $core->updates ?? array() ) as $update ) {
			if ( 'upgrade' === ( $update->response ?? '' ) ) {
				++$core_count;
			}
		}

		return array(
			'core'   => $core_count,
			'plugin' => count( (array) ( $plugin->response ?? array() ) ),
			'theme'  => count( (array) ( $theme->response ?? array() ) ),
		);
	}

	/**
	 * Number of active plugins.
	 *
	 * @return int
	 */
	private static function plugins_active(): int {
		return count( (array) get_option( 'active_plugins', array() ) );
	}

	/**
	 * Autoloaded option bloat is the classic silent WordPress performance leak:
	 * every request pays for it, and nothing in core surfaces it.
	 *
	 * WordPress 6.6 widened the autoload column beyond yes/no, so match all of
	 * the values that actually mean "load this on every request".
	 *
	 * @return int
	 */
	private static function autoload_bytes(): int {
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate over wp_options with no core API; runs on the collector schedule, never per request, and must reflect the live table.
		$bytes = $wpdb->get_var(
			"SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN ('yes', 'on', 'auto')"
		);
		$wpdb->suppress_errors( $suppress );

		return (int) $bytes;
	}
}
