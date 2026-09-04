<?php
/**
 * Minimal stand-ins for the WordPress classes the plugin type-hints against.
 *
 * These are shapes, not reimplementations — just enough surface for the code
 * under test to be exercised. Anything that needs real WordPress behaviour
 * belongs in the integration suite, not here.
 */

declare(strict_types=1);

class WP_REST_Request {

    /** @param array<string, string> $headers */
    public function __construct( private array $headers = [] ) {}

    public function get_header( string $key ): ?string {
        // WordPress normalises header lookups to lowercase, underscore-separated.
        $key = strtolower( str_replace( '-', '_', $key ) );

        return $this->headers[ $key ] ?? null;
    }
}

class WP_REST_Response {

    /** @var array<string, string> */
    public array $headers = [];

    public function __construct( public mixed $data = null, public int $status = 200 ) {}

    public function get_data(): mixed {
        return $this->data;
    }

    public function get_status(): int {
        return $this->status;
    }

    public function header( string $key, string $value ): void {
        $this->headers[ $key ] = $value;
    }
}

/**
 * Stand-in for $wpdb covering only what the plugin touches: suppress_errors(),
 * get_var() and last_error.
 */
class WP_Ops_Fake_Wpdb {

    public string $options    = 'wp_options';
    public string $last_error = '';
    public bool $suppressing  = false;

    /** @var list<string> Every query this instance was asked to run. */
    public array $queries = [];

    /** @param array<string, string|int|null> $responses Query substring => return value. */
    public function __construct( private array $responses = [], private mixed $default = null ) {}

    public function suppress_errors( bool $suppress = true ): bool {
        $previous          = $this->suppressing;
        $this->suppressing = $suppress;

        return $previous;
    }

    public function get_var( string $query ): mixed {
        $this->queries[] = $query;

        foreach ( $this->responses as $needle => $value ) {
            if ( str_contains( $query, $needle ) ) {
                return $value;
            }
        }

        return $this->default;
    }
}

/** Thrown in place of WP_CLI::halt()'s process exit so tests can assert on it. */
class WP_Ops_Cli_Halt extends RuntimeException {}

/**
 * Stand-in for the WP_CLI static facade. Records what the commands did instead
 * of writing to a terminal, and turns halt() into an exception so a non-zero
 * exit is observable rather than killing the test run.
 */
class WP_CLI {

    /** @var array<string, array<string, string>> command name => registration args */
    public static array $commands = [];

    /** @var list<array{0: string, 1: string}> level, message */
    public static array $output = [];

    public static function add_command( string $name, mixed $callable, array $args = [] ): void {
        self::$commands[ $name ] = $args;
    }

    public static function success( string $message ): void {
        self::$output[] = ['success', $message];
    }

    public static function log( string $message ): void {
        self::$output[] = ['log', $message];
    }

    public static function warning( string $message ): void {
        self::$output[] = ['warning', $message];
    }

    public static function line( string $message ): void {
        self::$output[] = ['line', $message];
    }

    public static function halt( int $code ): void {
        throw new WP_Ops_Cli_Halt( 'halt', $code );
    }

    public static function reset(): void {
        self::$commands = [];
        self::$output   = [];
    }
}

// Sanitisation helpers the plugin calls on $_SERVER reads. Brain Monkey only
// stubs the escaping/translation families, so these mirror core semantics:
// wp_unslash() = stripslashes_deep(), sanitize_text_field() = strip tags,
// strip control octets, collapse whitespace, trim.
if ( ! function_exists( 'wp_unslash' ) ) {
    function wp_unslash( mixed $value ): mixed {
        if ( is_array( $value ) ) {
            return array_map( 'wp_unslash', $value );
        }

        return is_string( $value ) ? stripslashes( $value ) : $value;
    }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( mixed $str ): string {
        $filtered = strip_tags( (string) $str );
        $filtered = preg_replace( '/[\r\n\t ]+/', ' ', $filtered ) ?? '';
        $filtered = preg_replace( '/[\x00-\x1F\x7F]/', '', $filtered ) ?? '';

        return trim( $filtered );
    }
}

if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( string $url, int $component = -1 ): mixed {
        return parse_url( $url, $component );
    }
}
