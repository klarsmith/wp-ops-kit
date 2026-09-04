<?php
/**
 * Prometheus text exposition renderer.
 *
 * @package WPOpsKit
 */

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

	/**
	 * Render the full exposition text, newline-terminated.
	 *
	 * @return string
	 */
	public static function render(): string {
		$lines = array();

		self::emit(
			$lines,
			'wp_ops_up',
			'gauge',
			'Always 1 — the exporter answered.',
			array(
				array( array(), 1 ),
			)
		);

		self::build_info( $lines );
		self::snapshot_metrics( $lines );
		self::pod_metrics( $lines );

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Emit wp_ops_build_info with WordPress, PHP and plugin versions as labels.
	 *
	 * @param array<int, string> $lines Exposition lines, appended to in place.
	 */
	private static function build_info( array &$lines ): void {
		global $wp_version;

		self::emit(
			$lines,
			'wp_ops_build_info',
			'gauge',
			'Build metadata; value is always 1.',
			array(
				array(
					array(
						'wp_version'     => (string) $wp_version,
						'php_version'    => PHP_VERSION,
						'plugin_version' => WP_OPS_KIT_VERSION,
					),
					1,
				),
			)
		);
	}

	/**
	 * Emit the wp_ops_site_* family from the stored snapshot, plus its age.
	 *
	 * @param array<int, string> $lines Exposition lines, appended to in place.
	 */
	private static function snapshot_metrics( array &$lines ): void {
		$snapshot = Snapshot::get();

		// Age is emitted even when there is no snapshot at all (-1), so a
		// collector that has never run is as visible as one that has died.
		self::emit(
			$lines,
			'wp_ops_snapshot_age_seconds',
			'gauge',
			'Seconds since the last successful collection; -1 if never collected.',
			array(
				array( array(), null === $snapshot ? -1 : time() - (int) $snapshot['ts'] ),
			)
		);

		if ( null === $snapshot ) {
			return;
		}

		$m = $snapshot['metrics'];

		$post_samples = array();
		foreach ( (array) ( $m['posts'] ?? array() ) as $type => $statuses ) {
			foreach ( (array) $statuses as $status => $count ) {
				$post_samples[] = array(
					array(
						'post_type' => (string) $type,
						'status'    => (string) $status,
					),
					(int) $count,
				);
			}
		}
		self::emit( $lines, 'wp_ops_site_posts', 'gauge', 'Posts by type and status.', $post_samples );

		self::emit(
			$lines,
			'wp_ops_site_users_total',
			'gauge',
			'Registered users.',
			array(
				array( array(), (int) ( $m['users_total'] ?? 0 ) ),
			)
		);

		$cron = (array) ( $m['cron'] ?? array() );
		self::emit(
			$lines,
			'wp_ops_site_cron_events',
			'gauge',
			'Scheduled cron events.',
			array(
				array( array(), (int) ( $cron['events'] ?? 0 ) ),
			)
		);
		self::emit(
			$lines,
			'wp_ops_site_cron_overdue_events',
			'gauge',
			'Cron events whose scheduled time has passed.',
			array(
				array( array(), (int) ( $cron['overdue'] ?? 0 ) ),
			)
		);
		self::emit(
			$lines,
			'wp_ops_site_cron_oldest_overdue_seconds',
			'gauge',
			'Age of the most overdue cron event.',
			array(
				array( array(), (int) ( $cron['oldest_overdue_seconds'] ?? 0 ) ),
			)
		);

		$updates = (array) ( $m['updates'] ?? array() );
		self::emit(
			$lines,
			'wp_ops_site_updates_available',
			'gauge',
			'Pending updates, by target type.',
			array(
				array( array( 'type' => 'core' ), (int) ( $updates['core'] ?? 0 ) ),
				array( array( 'type' => 'plugin' ), (int) ( $updates['plugin'] ?? 0 ) ),
				array( array( 'type' => 'theme' ), (int) ( $updates['theme'] ?? 0 ) ),
			)
		);

		self::emit(
			$lines,
			'wp_ops_site_plugins_active',
			'gauge',
			'Active plugins.',
			array(
				array( array(), (int) ( $m['plugins_active'] ?? 0 ) ),
			)
		);

		self::emit(
			$lines,
			'wp_ops_site_autoload_options_bytes',
			'gauge',
			'Total size of autoloaded options — paid on every single request.',
			array(
				array( array(), (int) ( $m['autoload_bytes'] ?? 0 ) ),
			)
		);
	}

	/**
	 * Emit the wp_ops_pod_* family: peak memory and, when available, opcache stats.
	 *
	 * @param array<int, string> $lines Exposition lines, appended to in place.
	 */
	private static function pod_metrics( array &$lines ): void {
		self::emit(
			$lines,
			'wp_ops_pod_php_memory_peak_bytes',
			'gauge',
			'Peak PHP memory for the request serving this scrape.',
			array(
				array( array(), memory_get_peak_usage( true ) ),
			)
		);

		if ( ! function_exists( 'opcache_get_status' ) ) {
			return;
		}

		// false = skip the per-script list; it is large and we never use it.
		// The silence is for opcache.restrict_api: when the calling script is
		// outside the allowed prefix the call raises E_WARNING rather than
		// returning false, and there is no clean way to pre-check that.
		$status = @opcache_get_status( false ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- restrict_api warns instead of returning false; see above.
		if ( ! is_array( $status ) || empty( $status['opcache_enabled'] ) ) {
			return;
		}

		$memory = (array) ( $status['memory_usage'] ?? array() );
		$stats  = (array) ( $status['opcache_statistics'] ?? array() );

		self::emit(
			$lines,
			'wp_ops_pod_opcache_memory_used_bytes',
			'gauge',
			'Opcache memory in use.',
			array(
				array( array(), (int) ( $memory['used_memory'] ?? 0 ) ),
			)
		);
		self::emit(
			$lines,
			'wp_ops_pod_opcache_memory_free_bytes',
			'gauge',
			'Opcache memory free.',
			array(
				array( array(), (int) ( $memory['free_memory'] ?? 0 ) ),
			)
		);
		self::emit(
			$lines,
			'wp_ops_pod_opcache_cached_scripts',
			'gauge',
			'Scripts held in opcache.',
			array(
				array( array(), (int) ( $stats['num_cached_scripts'] ?? 0 ) ),
			)
		);
		self::emit(
			$lines,
			'wp_ops_pod_opcache_hits_total',
			'counter',
			'Opcache hits.',
			array(
				array( array(), (int) ( $stats['hits'] ?? 0 ) ),
			)
		);
		self::emit(
			$lines,
			'wp_ops_pod_opcache_misses_total',
			'counter',
			'Opcache misses.',
			array(
				array( array(), (int) ( $stats['misses'] ?? 0 ) ),
			)
		);
	}

	/**
	 * Append one metric family (HELP, TYPE, then one line per sample).
	 *
	 * A family with no samples is skipped entirely rather than emitted as a
	 * bare HELP/TYPE pair.
	 *
	 * @param array<int, string>                                 $lines   Exposition lines, appended to in place.
	 * @param string                                             $name    Metric name.
	 * @param string                                             $type    Prometheus type: gauge or counter.
	 * @param string                                             $help    HELP text; newlines are flattened.
	 * @param list<array{0: array<string,string>, 1: int|float}> $samples Label set and value per sample.
	 */
	private static function emit( array &$lines, string $name, string $type, string $help, array $samples ): void {
		if ( array() === $samples ) {
			return;
		}

		$lines[] = '# HELP ' . $name . ' ' . str_replace( array( "\n", '\\' ), array( ' ', '\\\\' ), $help );
		$lines[] = '# TYPE ' . $name . ' ' . $type;

		foreach ( $samples as [$labels, $value] ) {
			$lines[] = $name . self::labels( $labels ) . ' ' . self::value( $value );
		}
	}

	/**
	 * Render a label set as `{k="v",...}`, or an empty string for no labels.
	 *
	 * @param array<string, string> $labels Label name => value.
	 * @return string
	 */
	private static function labels( array $labels ): string {
		if ( array() === $labels ) {
			return '';
		}

		$parts = array();
		foreach ( $labels as $key => $value ) {
			$parts[] = $key . '="' . self::escape( (string) $value ) . '"';
		}

		return '{' . implode( ',', $parts ) . '}';
	}

	/**
	 * Escape a label value per the exposition format (backslash, quote, newline).
	 *
	 * @param string $value Raw label value.
	 * @return string
	 */
	private static function escape( string $value ): string {
		return str_replace( array( '\\', '"', "\n" ), array( '\\\\', '\\"', '\\n' ), $value );
	}

	/**
	 * Format a sample value; floats are printed plainly with trailing zeros trimmed.
	 *
	 * @param int|float $value Sample value.
	 * @return string
	 */
	private static function value( int|float $value ): string {
		return is_float( $value ) ? rtrim( rtrim( sprintf( '%.6F', $value ), '0' ), '.' ) : (string) $value;
	}
}
