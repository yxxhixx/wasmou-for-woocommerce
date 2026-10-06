<?php
/**
 * Plugin Name:       Wasmou for WooCommerce
 * Plugin URI:        https://gateway.wasmou.net
 * Description:       Sell Wasmou gift cards, game top-ups and AI subscriptions in your own WooCommerce store. Import products in one click, keep prices and stock in sync, and deliver codes automatically.
 * Version:           1.0.0
 * Author:            Wasmou
 * Author URI:        https://myapp.wasmou.net
 * Text Domain:       wasmou-for-woocommerce
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 7.0
 * WC tested up to:   11.1
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

define( 'WASMOU_WC_VERSION', '1.0.0' );
define( 'WASMOU_WC_FILE', __FILE__ );
define( 'WASMOU_WC_DIR', plugin_dir_path( __FILE__ ) );
define( 'WASMOU_WC_URL', plugin_dir_url( __FILE__ ) );

// Compatible with High-Performance Order Storage (HPOS) and the block checkout.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WASMOU_WC_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WASMOU_WC_FILE, true );
		}
	}
);

require_once WASMOU_WC_DIR . 'includes/class-wasmou-logger.php';
require_once WASMOU_WC_DIR . 'includes/class-wasmou-settings.php';
require_once WASMOU_WC_DIR . 'includes/class-wasmou-pricing.php';
require_once WASMOU_WC_DIR . 'includes/class-wasmou-api.php';
require_once WASMOU_WC_DIR . 'includes/class-wasmou-catalog.php';
require_once WASMOU_WC_DIR . 'includes/class-wasmou-importer.php';
require_once WASMOU_WC_DIR . 'includes/class-wasmou-storefront.php';
require_once WASMOU_WC_DIR . 'includes/class-wasmou-fulfillment.php';
require_once WASMOU_WC_DIR . 'includes/class-wasmou-admin.php';
require_once WASMOU_WC_DIR . 'includes/class-wasmou-plugin.php';

register_activation_hook( __FILE__, array( 'Wasmou_WC_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Wasmou_WC_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Wasmou_WC_Plugin', 'init' ), 20 );
