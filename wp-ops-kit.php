<?php
/**
 * Plugin Name: WP Ops Kit
 * Plugin URI:  https://github.com/klarsmith/wp-ops-kit
 * Description: Makes WordPress legible to Kubernetes and Prometheus — honest readiness, snapshot-backed metrics, structured JSON logs.
 * Version:     0.1.2
 * Author:      klarsmith
 * Author URI:  https://klarsmith.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires PHP: 8.3
 *
 * @package WPOpsKit
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WP_OPS_KIT_VERSION', '0.1.2' );

$wp_ops_includes = plugin_dir_path( __FILE__ ) . 'includes/';
require_once $wp_ops_includes . 'class-config.php';
require_once $wp_ops_includes . 'class-checks.php';
require_once $wp_ops_includes . 'class-snapshot.php';
require_once $wp_ops_includes . 'class-metrics.php';
require_once $wp_ops_includes . 'class-rest.php';
require_once $wp_ops_includes . 'class-logger.php';
require_once $wp_ops_includes . 'class-cli.php';

// Logger boots first and outside plugins_loaded — an early fatal is exactly the
// thing we most want captured, and by plugins_loaded we may already be too late.
WPOpsKit\Logger::init();

add_action(
	'plugins_loaded',
	static function (): void {
		WPOpsKit\Rest::init();
		WPOpsKit\Cli::init();
	}
);
