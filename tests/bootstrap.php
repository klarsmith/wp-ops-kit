<?php
/**
 * PHPUnit bootstrap.
 *
 * The plugin has no autoloader by design (WordPress plugins are loaded by an
 * explicit require chain from the main file), so the suite mirrors that chain
 * instead of inventing one.
 */

declare(strict_types=1);

// Every include guards on ABSPATH and exits without it.
define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_OPS_KIT_VERSION', '0.1.0-test' );

// Cli::init() is a no-op unless this is truthy; the WP_CLI class stub lives in stubs.php.
define( 'WP_CLI', true );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/stubs.php';

$wp_ops_includes = dirname( __DIR__ ) . '/includes/';
require_once $wp_ops_includes . 'class-config.php';
require_once $wp_ops_includes . 'class-checks.php';
require_once $wp_ops_includes . 'class-snapshot.php';
require_once $wp_ops_includes . 'class-metrics.php';
require_once $wp_ops_includes . 'class-rest.php';
require_once $wp_ops_includes . 'class-logger.php';
require_once $wp_ops_includes . 'class-cli.php';
