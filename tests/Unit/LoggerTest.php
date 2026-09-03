<?php

declare(strict_types=1);

namespace WPOpsKit\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use WPOpsKit\Logger;
use WPOpsKit\Tests\TestCase;

/**
 * Structured logging. The output is one JSON object per line on stderr, which
 * means a single unescaped newline or a context key colliding with a reserved
 * field corrupts records for the whole log pipeline, not just one entry.
 */
final class LoggerTest extends TestCase {

    /** @var resource|null */
    private $stream = null;

    protected function setUp(): void {
        parent::setUp();

        Functions\when( 'wp_json_encode' )->alias(
            static fn ( mixed $data ): string|false => json_encode( $data )
        );
        Functions\when( 'get_option' )->justReturn( 'https://site.example.test/blog' );

        // Redirect the logger at an in-memory stream so records can be read back.
        $this->stream = fopen( 'php://memory', 'r+' );
        $stream       = $this->stream;
        ( $this->inScope( Logger::class, static function ( $stream ): void {
            Logger::$stream     = $stream;
            Logger::$request_id = null;
        } ) )( $stream );
    }

    protected function tearDown(): void {
        ( $this->inScope( Logger::class, static function (): void {
            Logger::$stream     = null;
            Logger::$request_id = null;
        } ) )();

        if ( is_resource( $this->stream ) ) {
            fclose( $this->stream );
        }

        unset( $_SERVER['HTTP_X_REQUEST_ID'], $_SERVER['HTTP_TRACEPARENT'], $_SERVER['HTTP_X_AMZN_TRACE_ID'] );

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function lastRecord(): array {
        rewind( $this->stream );
        $lines = array_values( array_filter( explode( "\n", (string) stream_get_contents( $this->stream ) ) ) );
        self::assertNotEmpty( $lines, 'Nothing was written to the log stream.' );

        return json_decode( (string) end( $lines ), true, 512, JSON_THROW_ON_ERROR );
    }

    public function test_a_record_carries_the_reserved_fields(): void {
        Logger::log( 'warning', 'login_failed', ['username' => 'admin'] );

        $record = $this->lastRecord();

        self::assertSame( 'warning', $record['level'] );
        self::assertSame( 'login_failed', $record['event'] );
        self::assertSame( 'admin', $record['username'] );
        self::assertNotEmpty( $record['request_id'] );
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/',
            $record['ts'],
            'Timestamps must be RFC3339 UTC.'
        );
    }

    /**
     * Context is merged with `+`, which keeps the left-hand side. A caller
     * cannot therefore forge the level or event of a record — worth pinning,
     * because switching to array_merge would silently invert it.
     */
    public function test_context_cannot_override_reserved_fields(): void {
        Logger::log( 'info', 'login_success', ['level' => 'error', 'event' => 'spoofed', 'site' => 'evil.test'] );

        $record = $this->lastRecord();

        self::assertSame( 'info', $record['level'] );
        self::assertSame( 'login_success', $record['event'] );
        self::assertSame( 'site.example.test', $record['site'] );
    }

    public function test_each_record_is_a_single_line(): void {
        Logger::log( 'error', 'php_fatal', ['message' => "line one\nline two"] );

        rewind( $this->stream );
        $contents = (string) stream_get_contents( $this->stream );

        self::assertSame( 1, substr_count( $contents, "\n" ), 'A record must occupy exactly one line.' );
        self::assertStringContainsString( '\nline two', $contents );
    }

    public function test_site_name_falls_back_to_the_home_url_host(): void {
        Logger::log( 'info', 'test' );

        self::assertSame( 'site.example.test', $this->lastRecord()['site'] );
    }

    public function test_explicit_site_name_wins_over_the_home_url(): void {
        $this->env( 'WP_OPS_SITE_NAME', 'koorekiht-prod' );

        Logger::log( 'info', 'test' );

        self::assertSame( 'koorekiht-prod', $this->lastRecord()['site'] );
    }

    /**
     * Correlating a PHP fatal with the ingress access log is the main reason the
     * request id exists, so an inbound id must be adopted rather than replaced.
     */
    public function test_request_id_is_adopted_from_the_ingress_header(): void {
        $_SERVER['HTTP_X_REQUEST_ID'] = 'abc-123-from-ingress';

        Logger::log( 'info', 'test' );

        self::assertSame( 'abc-123-from-ingress', $this->lastRecord()['request_id'] );
    }

    public function test_request_id_is_truncated_to_a_sane_length(): void {
        $_SERVER['HTTP_X_REQUEST_ID'] = str_repeat( 'a', 500 );

        Logger::log( 'info', 'test' );

        self::assertSame( 128, strlen( $this->lastRecord()['request_id'] ) );
    }

    public function test_request_id_is_generated_and_stable_within_a_request(): void {
        Logger::log( 'info', 'first' );
        $first = $this->lastRecord()['request_id'];

        Logger::log( 'info', 'second' );
        $second = $this->lastRecord()['request_id'];

        self::assertSame( $first, $second );
        self::assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $first );
    }

    /**
     * The shutdown handler runs after every request, so its type filter is what
     * keeps ordinary warnings out of the log. The E_ERROR branch itself cannot
     * be reached from a unit test — error_get_last() only reports a real fatal —
     * and stays on the integration list.
     */
    public function test_capture_fatal_ignores_non_fatal_errors(): void {
        @file_get_contents( __DIR__ . '/definitely-not-a-file' );
        self::assertNotNull( error_get_last(), 'Precondition: a non-fatal error was recorded.' );

        Logger::capture_fatal();

        rewind( $this->stream );
        self::assertSame( '', (string) stream_get_contents( $this->stream ) );
    }

    /**
     * Logging is opt-in. A plugin that starts rewriting a site's logging the
     * moment it is activated is a bad guest, so init() must be inert by default.
     */
    public function test_init_does_nothing_unless_json_logging_is_enabled(): void {
        Actions\expectAdded( 'wp_login_failed' )->never();
        Actions\expectAdded( 'wp_login' )->never();

        Logger::init();
    }

    public function test_init_registers_login_hooks_when_enabled(): void {
        $this->env( 'WP_OPS_LOG_JSON', 'true' );

        Actions\expectAdded( 'wp_login_failed' )->once();
        Actions\expectAdded( 'wp_login' )->once();

        Logger::init();
    }
}
