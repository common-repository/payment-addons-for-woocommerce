<?php
/**
 * Plugin Name: Stripe Payment For WooCommerce
 * Description: Add over 30+ payment methods powered by Stripe to your WooCommerce Store, including popular options such as PayPal, Apple Pay, Google Pay, iDeal and stripe subscription integration, all in one convenient option.
 * Version:     1.13.4
 * Author:    	Payment Addons, support@payaddons.com 
 * Author URI:	https://payaddons.com
 * Text Domain: pay-addons-for-woocommerce
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires Plugins: woocommerce
 * 
  */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
/**
 * Defining plugin constants.
 *
 * @since 1.0.0
 */
define('WSPA_PLUGIN_NAME', 'WooCommerce Stripe Payment Addons');
define('WSPA_PLUGIN_VERSION', '1.13.4');
define('WSPA_PLUGIN_URL', 'https://payaddons.com/');
define('WSPA_ADDONS_REST_API', 'wspa/v1/');
define('WSPA_ADDONS_FILE', __FILE__);
define('WSPA_ADDONS_DIR', plugin_dir_path(__FILE__));
define('WSPA_ADDONS_BASENAME', plugin_basename(__FILE__));
define('WSPA_ADDONS_PATH', trailingslashit(plugin_dir_path(__FILE__)));
define('WSPA_ADDONS_ASSET_PATH', WSPA_ADDONS_PATH . '/assets/');
define('WSPA_ADDONS_URL', trailingslashit(plugins_url('/', __FILE__)));
define('WSPA_ADDONS_ASSET_URL', WSPA_ADDONS_URL . '/assets/');
define('WSPA_ADDONS_LOG_FOLDER', plugin_dir_path(__FILE__) . 'logs');

require_once('freemius-config.php');
if ( ! class_exists( '\Stripe\Stripe' ) ) {
	require_once WSPA_ADDONS_PATH . '/libs/stripe-php/init.php';
}
require_once WSPA_ADDONS_PATH . '/bootstrap.php';

/**
 * Run plugin after all others plugins
 *
 * @since 1.0.0
 */
add_action( 'plugins_loaded', function() {
	\Woo_Stripe_Pay_Addons\Bootstrap::instance();
} );

// Hook in Blocks integration. This action is called in a callback on plugins loaded, so current Stripe plugin class
// implementation is too late.
add_action( 'woocommerce_blocks_loaded', function() {
	\Woo_Stripe_Pay_Addons\Bootstrap::wspa_woocommerce_block_support();
} );

/**
 * Activation hook
 *
 * @since v1.0.0
 */
register_activation_hook(__FILE__, function () {
	register_uninstall_hook( __FILE__, 'wspa_plugin_uninstall' );
});

/**
 * Deactivation hook
 *
 * @since v1.0.0
 */
register_deactivation_hook(__FILE__, function () {
});

/**
 * Handle uninstall
 *
 * @since v1.0.0
 */
if ( !function_exists('wspa_plugin_uninstall') ) {
	function wspa_plugin_uninstall(){
		wspa_fs()->add_action('after_uninstall', 'wspa_fs_uninstall_cleanup');
	}
}
