<?php

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

    public static function get( string $key, mixed $default = null ): mixed {
        if ( defined( $key ) ) {
            return constant( $key );
        }

        $env = getenv( $key );
        if ( false !== $env && '' !== $env ) {
            return $env;
        }

        return $default;
    }

    public static function bool( string $key, bool $default = false ): bool {
        $value = self::get( $key );
        if ( null === $value ) {
            return $default;
        }
        if ( is_bool( $value ) ) {
            return $value;
        }

        return in_array( strtolower( (string) $value ), ['1', 'true', 'yes', 'on'], true );
    }

    public static function int( string $key, int $default ): int {
        $value = self::get( $key );

        return null === $value ? $default : (int) $value;
    }

    /** @return list<string> */
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
     */
    public static function token(): ?string {
        $token = self::get( 'WP_OPS_TOKEN' );

        return is_string( $token ) && '' !== $token ? $token : null;
    }
}
