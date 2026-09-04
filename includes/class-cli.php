<?php
/**
 * WP-CLI commands: `wp ops collect|check|metrics`.
 *
 * @package WPOpsKit
 */

declare(strict_types=1);

namespace WPOpsKit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI surface. `wp ops collect` is the one that matters — it is what the
 * Kubernetes CronJob calls, and without it the metrics never refresh.
 */
final class Cli {

	/**
	 * Register the `ops` commands; a no-op outside WP-CLI.
	 */
	public static function init(): void {
		if ( ! defined( 'WP_CLI' ) || ! \WP_CLI ) {
			return;
		}

		\WP_CLI::add_command(
			'ops collect',
			array( self::class, 'collect' ),
			array(
				'shortdesc' => 'Refresh the ops metrics snapshot.',
			)
		);

		\WP_CLI::add_command(
			'ops check',
			array( self::class, 'check' ),
			array(
				'shortdesc' => 'Run the readiness checks; exits non-zero on failure.',
			)
		);

		\WP_CLI::add_command(
			'ops metrics',
			array( self::class, 'metrics' ),
			array(
				'shortdesc' => 'Print the Prometheus exposition text.',
			)
		);
	}

	/**
	 * Refresh the metrics snapshot and report how long it took.
	 */
	public static function collect(): void {
		$started  = microtime( true );
		$snapshot = Snapshot::collect();
		$elapsed  = ( microtime( true ) - $started ) * 1000;

		\WP_CLI::success(
			sprintf(
				'Snapshot collected in %.0fms (%d metric groups).',
				$elapsed,
				count( $snapshot['metrics'] )
			)
		);
	}

	/**
	 * Run the readiness checks, print one line per check and halt(1) on any failure.
	 */
	public static function check(): void {
		$results = Checks::run();

		foreach ( $results as $name => $result ) {
			$line = sprintf( '%-18s %s', $name, $result['detail'] );
			if ( $result['ok'] ) {
				\WP_CLI::log( 'ok:   ' . $line );
			} else {
				\WP_CLI::warning( 'FAIL: ' . $line );
			}
		}

		if ( ! Checks::all_passed( $results ) ) {
			\WP_CLI::halt( 1 );
		}

		\WP_CLI::success( 'All readiness checks passed.' );
	}

	/**
	 * Print the Prometheus exposition text exactly as /metrics would serve it.
	 */
	public static function metrics(): void {
		\WP_CLI::line( rtrim( Metrics::render(), "\n" ) );
	}
}
