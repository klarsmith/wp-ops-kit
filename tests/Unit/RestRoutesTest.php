<?php

declare(strict_types=1);

namespace WPOpsKit\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use WPOpsKit\Rest;
use WPOpsKit\Tests\TestCase;

/**
 * Route registration.
 *
 * The namespace is pinned deliberately. Probes address these routes by a URL
 * baked into the Deployment, and on our own fleet wp-fortress only exposes REST
 * namespaces on its allowlist — so renaming "ops" would not break a test
 * elsewhere, it would 404 every probe in the fleet at once.
 */
final class RestRoutesTest extends TestCase {

    public function test_init_defers_registration_to_rest_api_init(): void {
        Actions\expectAdded( 'rest_api_init' )->once();

        Rest::init();
    }

    public function test_routes_are_registered_under_the_ops_namespace(): void {
        $routes = [];
        Functions\when( 'register_rest_route' )->alias(
            function ( string $namespace, string $route, array $args ) use ( &$routes ): bool {
                $routes[ $namespace . $route ] = $args;

                return true;
            }
        );

        Rest::register_routes();

        self::assertSame( ['ops/v1/readyz', 'ops/v1/metrics'], array_keys( $routes ) );
    }

    /**
     * Both routes must be reachable without a WordPress session: readiness is
     * called by kubelet and metrics by Prometheus, neither of which carries a
     * cookie or nonce. Authorisation happens inside the handlers instead.
     */
    public function test_both_routes_are_anonymous_get_endpoints(): void {
        $routes = [];
        Functions\when( 'register_rest_route' )->alias(
            function ( string $namespace, string $route, array $args ) use ( &$routes ): bool {
                $routes[ $route ] = $args;

                return true;
            }
        );

        Rest::register_routes();

        foreach ( $routes as $route => $args ) {
            self::assertSame( 'GET', $args['methods'], $route );
            self::assertSame( '__return_true', $args['permission_callback'], $route );
            self::assertIsCallable( $args['callback'], $route );
        }
    }
}
