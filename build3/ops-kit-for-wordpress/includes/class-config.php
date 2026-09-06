<?php
/**
 * Constant/environment-backed configuration reader.
 *
 * @package WPOpsKit
 */

declare(strict_types=1);

namespace WPOpsKit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Configuration is read from constants first, then environment, and never from
 * the database. That keeps the plugin 12-factor and GitOps-friendly: a site's
 * ops behaviour is declared in the deployment, not clicked into wp_options.
 */
final class Config {

	/**
	 * Read a raw setting: a defined constant wins, then a non-empty env var.
	 *
	 * @param string $key      Constant / environment variable name.
	 * @param mixed  $fallback Returned when neither source defines the key.
	 * @return mixed
	 */
	public static function get( string $key, mixed $fallback = null ): mixed {
		if ( defined( $key ) ) {
			return constant( $key );
		}

		$env = getenv( $key );
		if ( false !== $env && '' !== $env ) {
			return $env;
		}

		return $fallback;
	}

	/**
	 * Read a boolean setting; "1", "true", "yes" and "on" (any case) are true.
	 *
	 * @param string $key      Constant / environment variable name.
	 * @param bool   $fallback Returned when the key is unset.
	 * @return bool
	 */
	public static function bool( string $key, bool $fallback = false ): bool {
		$value = self::get( $key );
		if ( null === $value ) {
			return $fallback;
		}
		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Read an integer setting.
	 *
	 * @param string $key      Constant / environment variable name.
	 * @param int    $fallback Returned when the key is unset.
	 * @return int
	 */
	public static function int( string $key, int $fallback ): int {
		$value = self::get( $key );

		return null === $value ? $fallback : (int) $value;
	}

	/**
	 * Read a list setting: a comma-separated string or an array constant,
	 * trimmed with empty entries dropped.
	 *
	 * @param string $key Constant / environment variable name.
	 * @return list<string>
	 */
	public static function list( string $key ): array {
		$value = self::get( $key, '' );
		if ( is_array( $value ) ) {
			return array_values( array_filter( array_map( 'strval', $value ) ) );
		}

		$parts = array_map( 'trim', explode( ',', (string) $value ) );

		return array_values( array_filter( $parts, static fn ( string $p ): bool => '' !== $p ) );
	}

	/**
	 * The bearer token guarding /metrics and the detailed /readyz body. When it
	 * is unset the metrics endpoint is disabled outright rather than served
	 * anonymously — an exporter that fails open is a data leak, not a feature.
	 *
	 * @return string|null
	 */
	public static function token(): ?string {
		$token = self::get( 'WP_OPS_TOKEN' );

		return is_string( $token ) && '' !== $token ? $token : null;
	}
}
