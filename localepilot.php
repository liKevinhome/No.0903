<?php
/**
 * Plugin Name:       LocalePilot
 * Plugin URI:        https://example.com/localepilot
 * Description:       Same-URL multilingual storefront extension for WooCommerce using pre-translated local content.
 * Version:           0.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            LocalePilot Contributors
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       localepilot
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:   11.2
 *
 * @package LocalePilot
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'LOCALEPILOT_VERSION' ) ) {
	define( 'LOCALEPILOT_VERSION', '0.1.0' );
}

if ( ! defined( 'LOCALEPILOT_FILE' ) ) {
	define( 'LOCALEPILOT_FILE', __FILE__ );
}

if ( ! defined( 'LOCALEPILOT_PATH' ) ) {
	define( 'LOCALEPILOT_PATH', __DIR__ . '/' );
}

if ( ! defined( 'LOCALEPILOT_BASENAME' ) ) {
	define( 'LOCALEPILOT_BASENAME', function_exists( 'plugin_basename' ) ? plugin_basename( __FILE__ ) : basename( __DIR__ ) . '/' . basename( __FILE__ ) );
}

require_once LOCALEPILOT_PATH . 'includes/Autoloader.php';
\LocalePilot\Autoloader::register( LOCALEPILOT_PATH . 'includes/' );

/**
 * Return the shared LocalePilot plugin instance.
 *
 * @return \LocalePilot\Plugin
 */
function localepilot(): \LocalePilot\Plugin {
	return \LocalePilot\Plugin::instance();
}

if ( function_exists( 'register_activation_hook' ) ) {
	register_activation_hook( __FILE__, array( \LocalePilot\Plugin::class, 'activate' ) );
}

if ( function_exists( 'register_deactivation_hook' ) ) {
	register_deactivation_hook( __FILE__, array( \LocalePilot\Plugin::class, 'deactivate' ) );
}

if ( function_exists( 'add_action' ) ) {
	add_action(
		'before_woocommerce_init',
		static function (): void {
			\LocalePilot\Compatibility::declare_woocommerce_features( LOCALEPILOT_FILE );
		}
	);

	add_action(
		'plugins_loaded',
		static function (): void {
			localepilot()->init();
		},
		20
	);
}
