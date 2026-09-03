<?php

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

    public static function init(): void {
        if ( ! defined( 'WP_CLI' ) || ! \WP_CLI ) {
            return;
        }

        \WP_CLI::add_command( 'ops collect', [self::class, 'collect'], [
            'shortdesc' => 'Refresh the ops metrics snapshot.',
        ] );

        \WP_CLI::add_command( 'ops check', [self::class, 'check'], [
            'shortdesc' => 'Run the readiness checks; exits non-zero on failure.',
        ] );

        \WP_CLI::add_command( 'ops metrics', [self::class, 'metrics'], [
            'shortdesc' => 'Print the Prometheus exposition text.',
        ] );
    }

    public static function collect(): void {
        $started  = microtime( true );
        $snapshot = Snapshot::collect();
        $elapsed  = ( microtime( true ) - $started ) * 1000;

        \WP_CLI::success( sprintf(
            'Snapshot collected in %.0fms (%d metric groups).',
            $elapsed,
            count( $snapshot['metrics'] )
        ) );
    }

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

    public static function metrics(): void {
        \WP_CLI::line( rtrim( Metrics::render(), "\n" ) );
    }
}
