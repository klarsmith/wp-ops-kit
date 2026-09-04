<?php

declare(strict_types=1);

namespace WPOpsKit\Tests\Unit;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use WPOpsKit\Rest;
use WPOpsKit\Tests\TestCase;

/**
 * The HTTP surface. Two properties matter beyond the happy path: readiness must
 * answer 503 (not 200 with a sad body) so kubelet acts on it, and an
 * unauthenticated caller must learn which checks failed but never why.
 */
final class RestTest extends TestCase {

    use \WPOpsKit\Tests\HealthyWordPress;

    protected function setUp(): void {
        parent::setUp();

        $this->bootHealthyWordPress();
    }

    protected function tearDown(): void {
        $this->shutdownHealthyWordPress();

        parent::tearDown();
    }

    /** @param array<string, string> $headers */
    private function request( array $headers = [] ): \WP_REST_Request {
        return new \WP_REST_Request( $headers );
    }

    // ---------------------------------------------------------------- readyz

    public function test_healthy_site_returns_200_and_no_store(): void {
        $response = Rest::readyz( $this->request() );

        self::assertSame( 200, $response->get_status() );
        self::assertSame( 'ok', $response->get_data()['status'] );
        self::assertSame( 'no-store', $response->headers['Cache-Control'] );
    }

    /**
     * 503 rather than 200 is the entire point — a readiness probe only removes a
     * pod from the Service on a non-2xx.
     */
    public function test_failing_check_returns_503(): void {
        $this->options['db_version'] = 57155;

        $response = Rest::readyz( $this->request() );

        self::assertSame( 503, $response->get_status() );
        self::assertSame( 'fail', $response->get_data()['status'] );
    }

    public function test_anonymous_body_names_failing_checks_without_detail(): void {
        $this->options['db_version'] = 57155;

        $data = Rest::readyz( $this->request() )->get_data();

        self::assertSame( ['db_schema'], $data['failed'] );
        self::assertArrayNotHasKey( 'checks', $data );
        self::assertStringNotContainsString( '57155', json_encode( $data, JSON_THROW_ON_ERROR ) );
    }

    public function test_authorized_body_includes_full_check_detail(): void {
        $this->env( 'WP_OPS_TOKEN', 's3cret' );
        $this->options['db_version'] = 57155;

        $data = Rest::readyz( $this->request( ['authorization' => 'Bearer s3cret'] ) )->get_data();

        self::assertArrayHasKey( 'checks', $data );
        self::assertArrayNotHasKey( 'failed', $data );
        self::assertStringContainsString( '57155', $data['checks']['db_schema']['detail'] );
    }

    /** A wrong token must not be treated as "close enough" and leak detail. */
    public function test_wrong_token_gets_the_redacted_body(): void {
        $this->env( 'WP_OPS_TOKEN', 's3cret' );
        $this->options['db_version'] = 57155;

        $data = Rest::readyz( $this->request( ['authorization' => 'Bearer wrong'] ) )->get_data();

        self::assertArrayNotHasKey( 'checks', $data );
        self::assertSame( ['db_schema'], $data['failed'] );
    }

    // --------------------------------------------------------------- metrics

    /**
     * With no token configured the endpoint must not exist at all. An exporter
     * that fails open leaks post counts, user counts and plugin inventory.
     */
    public function test_metrics_is_404_when_no_token_is_configured(): void {
        $response = Rest::metrics( $this->request( ['authorization' => 'Bearer anything'] ) );

        self::assertSame( 404, $response->get_status() );
        self::assertSame( 'rest_no_route', $response->get_data()['code'] );
    }

    /** 404 rather than 401 so a prober cannot even confirm the endpoint exists. */
    public function test_metrics_is_404_not_401_for_a_bad_token(): void {
        $this->env( 'WP_OPS_TOKEN', 's3cret' );

        $response = Rest::metrics( $this->request( ['authorization' => 'Bearer wrong'] ) );

        self::assertSame( 404, $response->get_status() );
    }

    /**
     * The short-circuit: the REST server would otherwise JSON-encode the body,
     * so the handler registers a rest_pre_serve_request filter that writes raw
     * exposition text and reports the request as served.
     *
     * This asserts the callback's own behaviour. It cannot prove ordering
     * against other plugins that hook the same filter — that stays on the
     * integration list.
     */
    public function test_authorized_metrics_short_circuits_with_raw_exposition(): void {
        $this->env( 'WP_OPS_TOKEN', 's3cret' );

        $captured = null;
        Filters\expectAdded( 'rest_pre_serve_request' )
            ->once()
            ->whenHappen( function ( callable $callback ) use ( &$captured ): void {
                $captured = $callback;
            } );

        $response = Rest::metrics( $this->request( ['authorization' => 'Bearer s3cret'] ) );

        self::assertSame( 200, $response->get_status() );
        self::assertIsCallable( $captured );

        ob_start();
        $served = $captured( false );
        $output = (string) ob_get_clean();

        self::assertTrue( $served, 'The filter must report the request as served.' );
        self::assertStringContainsString( '# TYPE wp_ops_up gauge', $output );
        self::assertStringStartsWith( '# HELP', $output );
    }

    /**
     * The filter runs in a chain. If something upstream already wrote the
     * response, appending exposition to it would corrupt both payloads.
     */
    public function test_metrics_defers_when_the_request_was_already_served(): void {
        $this->env( 'WP_OPS_TOKEN', 's3cret' );

        $captured = null;
        Filters\expectAdded( 'rest_pre_serve_request' )
            ->once()
            ->whenHappen( function ( callable $callback ) use ( &$captured ): void {
                $captured = $callback;
            } );

        Rest::metrics( $this->request( ['authorization' => 'Bearer s3cret'] ) );

        ob_start();
        $served = $captured( true );
        $output = (string) ob_get_clean();

        self::assertTrue( $served );
        self::assertSame( '', $output, 'Nothing may be written once the request is served.' );
    }

    public function test_x_ops_token_header_is_accepted_as_an_alternative(): void {
        $this->env( 'WP_OPS_TOKEN', 's3cret' );

        Filters\expectAdded( 'rest_pre_serve_request' )->once();

        $response = Rest::metrics( $this->request( ['x_ops_token' => 's3cret'] ) );

        self::assertSame( 200, $response->get_status() );
    }

    // ------------------------------------------------------------ authorized

    public function test_authorization_requires_a_configured_token(): void {
        $authorized = $this->inScope(
            Rest::class,
            static fn ( \WP_REST_Request $r ): bool => Rest::authorized( $r )
        );

        self::assertFalse( $authorized( $this->request( ['authorization' => 'Bearer anything'] ) ) );
    }

    public function test_bearer_prefix_is_matched_case_insensitively(): void {
        $this->env( 'WP_OPS_TOKEN', 's3cret' );
        $authorized = $this->inScope(
            Rest::class,
            static fn ( \WP_REST_Request $r ): bool => Rest::authorized( $r )
        );

        self::assertTrue( $authorized( $this->request( ['authorization' => 'Bearer s3cret'] ) ) );
        self::assertTrue( $authorized( $this->request( ['authorization' => 'bearer s3cret'] ) ) );
        self::assertTrue( $authorized( $this->request( ['authorization' => 'BEARER s3cret'] ) ) );
    }

    public function test_empty_and_missing_credentials_are_rejected(): void {
        $this->env( 'WP_OPS_TOKEN', 's3cret' );
        $authorized = $this->inScope(
            Rest::class,
            static fn ( \WP_REST_Request $r ): bool => Rest::authorized( $r )
        );

        self::assertFalse( $authorized( $this->request() ) );
        self::assertFalse( $authorized( $this->request( ['authorization' => 'Bearer '] ) ) );
        self::assertFalse( $authorized( $this->request( ['x_ops_token' => ''] ) ) );
    }

    /** A token that is merely a prefix of the real one must not authenticate. */
    public function test_prefix_of_the_token_is_rejected(): void {
        $this->env( 'WP_OPS_TOKEN', 's3cret' );
        $authorized = $this->inScope(
            Rest::class,
            static fn ( \WP_REST_Request $r ): bool => Rest::authorized( $r )
        );

        self::assertFalse( $authorized( $this->request( ['authorization' => 'Bearer s3cre'] ) ) );
        self::assertFalse( $authorized( $this->request( ['authorization' => 'Bearer s3cretx'] ) ) );
    }
}
