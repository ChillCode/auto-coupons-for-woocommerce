<?php
/**
 * Auto Coupons for WooCommerce
 *
 * @package   Auto Coupons for WooCommerce
 * @author    Chillcode
 * @copyright Copyright (c) 2003-2024, Chillcode (https://github.com/chillcode/)
 * @license   GPLv3
 *
 * @wordpress-plugin
 * Plugin Name: Auto Coupons for WooCommerce
 * Plugin URI: https://github.com/chillcode/auto-coupons-for-woocommerce
 * Description: Apply WooCommerce coupons automatically as a discount.
 * Version: 1.0.3
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author: Chillcode
 * Author URI: https://github.com/chillcode/
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: auto-coupons-for-woocommerce
 * Domain Path: /i18n/languages/
 *
 * WC requires at least: 8.6
 * WC tested up to: 9.1.2
 */

defined( 'ABSPATH' ) || exit;

define( 'ACWC_PLUGIN_PATH', __DIR__ );
define( 'ACWC_PLUGIN_FILE', __FILE__ );
define( 'ACWC_PLUGIN_VERSION', '1.0.3' );

require_once ACWC_PLUGIN_PATH . '/includes/class-autocoupons.php';

/**
 * Main Instance.
 *
 * Ensures only one instance is loaded or can be loaded.
 *
 * @since 1.0.0
 * @static
 * @return ACWC\AutoCoupons Main instance.
 */
function ACWC(): ACWC\AutoCoupons { //phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
	return ACWC\AutoCoupons::instance();
}

/**
 * HPOS compatibility.
 *
 * More info: https://developer.woocommerce.com/docs/hpos-extension-recipe-book/
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

/**
 * Initialize the plugin.
 */
ACWC();
