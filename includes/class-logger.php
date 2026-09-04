<?php
/**
 * Structured JSON logging to stderr.
 *
 * @package WPOpsKit
 */

declare(strict_types=1);

namespace WPOpsKit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Structured JSON logging to stderr.
 *
 * In a container, debug.log is a file nobody will ever read on a filesystem that
 * may well be read-only. One JSON object per line on stderr is what the log
 * pipeline actually wants, and it carries a request id so a fatal can be tied
 * back to the request that caused it.
 *
 * Opt in with WP_OPS_LOG_JSON=true. Disabled by default — a plugin that starts
 * rewriting a site's logging the moment it is activated is a bad guest.
 */
final class Logger {

	/**
	 * Per-request correlation id, resolved lazily by request_id().
	 *
	 * @var string|null
	 */
	private static ?string $request_id = null;

	/**
	 * Handle to php://stderr, opened on first write.
	 *
	 * @var resource|null
	 */
	private static $stream = null;

	/**
	 * Install the fatal-error handler and auth-event hooks when WP_OPS_LOG_JSON is on.
	 */
	public static function init(): void {
		if ( ! Config::bool( 'WP_OPS_LOG_JSON', false ) ) {
			return;
		}

		register_shutdown_function( array( self::class, 'capture_fatal' ) );

		add_action(
			'wp_login_failed',
			static function ( $username ): void {
				self::log( 'warning', 'login_failed', array( 'username' => (string) $username ) );
			}
		);

		add_action(
			'wp_login',
			static function ( $login ): void {
				self::log( 'info', 'login_success', array( 'username' => (string) $login ) );
			}
		);
	}

	/**
	 * Fatals never reach an action hook, so the shutdown handler is the only
	 * place they can be caught and rendered as structured output.
	 */
	public static function capture_fatal(): void {
		$error = error_get_last();
		if ( null === $error || ! in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
			return;
		}

		self::log(
			'error',
			'php_fatal',
			array(
				'message' => $error['message'],
				'file'    => $error['file'],
				'line'    => $error['line'],
			)
		);
	}

	/**
	 * Write one JSON record to stderr.
	 *
	 * The reserved keys (ts, level, event, request_id, site) always win over
	 * $context, so a caller cannot spoof them.
	 *
	 * @param string               $level   Severity label: info, warning or error.
	 * @param string               $event   Machine-readable event name.
	 * @param array<string, mixed> $context Extra fields merged into the record.
	 */
	public static function log( string $level, string $event, array $context = array() ): void {
		$record = array(
			'ts'         => gmdate( 'c' ),
			'level'      => $level,
			'event'      => $event,
			'request_id' => self::request_id(),
			'site'       => self::site(),
		) + $context;

		$json = wp_json_encode( $record );
		if ( false === $json ) {
			return;
		}

		$stream = self::stream();
		if ( null !== $stream ) {
			fwrite( $stream, $json . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- php://stderr is a stream, not a file; WP_Filesystem cannot address it.
		}
	}

	/**
	 * The id for this request: adopted from an ingress tracing header when
	 * present, otherwise generated once and reused for the rest of the request.
	 *
	 * @return string
	 */
	private static function request_id(): string {
		if ( null !== self::$request_id ) {
			return self::$request_id;
		}

		// Prefer an id the ingress already assigned so logs correlate across
		// the whole request path rather than only within PHP.
		foreach ( array( 'HTTP_X_REQUEST_ID', 'HTTP_X_AMZN_TRACE_ID', 'HTTP_TRACEPARENT' ) as $header ) {
			$value = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ?? '' ) );
			if ( '' !== $value ) {
				self::$request_id = substr( $value, 0, 128 );

				return self::$request_id;
			}
		}

		self::$request_id = bin2hex( random_bytes( 8 ) );

		return self::$request_id;
	}

	/**
	 * Site label for every record: WP_OPS_SITE_NAME, else the home URL's host.
	 *
	 * @return string
	 */
	private static function site(): string {
		$site = Config::get( 'WP_OPS_SITE_NAME' );
		if ( is_string( $site ) && '' !== $site ) {
			return $site;
		}

		return (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_HOST );
	}

	/**
	 * Lazily opened stderr handle; null when it cannot be opened.
	 *
	 * @return resource|null
	 */
	private static function stream() {
		if ( null === self::$stream ) {
			$stream = fopen( 'php://stderr', 'w' );
			if ( false === $stream ) {
				return null;
			}
			self::$stream = $stream;
		}

		return self::$stream;
	}
}
