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
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Chillcode
 * Author URI: https://github.com/chillcode/
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: auto-coupons-for-woocommerce
 * Domain Path: /i18n/languages/
 *
 * WC requires at least: 6.0.1
 * WC tested up to: 8.5.2
 */

defined( 'ABSPATH' ) || exit;

define( 'ACWC_PLUGIN_PATH', __DIR__ );
define( 'ACWC_PLUGIN_FILE', __FILE__ );
define( 'ACWC_PLUGIN_VERSION', '1.0.0' );

$woocommerce_plugin_path = trailingslashit( WP_PLUGIN_DIR ) . 'woocommerce/woocommerce.php';

if ( in_array( $woocommerce_plugin_path, (array) wp_get_active_and_valid_plugins(), true ) || is_multisite() && in_array( $woocommerce_plugin_path, wp_get_active_network_plugins(), true ) ) {

	require_once ACWC_PLUGIN_PATH . '/includes/class-autocoupons.php';

	/**
	 * Main Instance.
	 *
	 * Ensures only one instance is loaded or can be loaded.
	 *
	 * @since 1.0
	 * @static
	 * @return ACWC\AutoCoupons Main instance.
	 */
	function ACWC(): ACWC\AutoCoupons { //phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
		return ACWC\AutoCoupons::instance();
	}

	/**
	 * Initialize the plugin.
	 */
	ACWC();

} else {
	add_action(
		'admin_notices',
		function () {
			global $pagenow;

			if ( 'plugins.php' === $pagenow ) {
				printf( '<div class="%1$s"><p>%2$s</p></div>', 'notice notice-error is-dismissible', esc_html__( 'Auto Coupons for WooCommerce requires WooCommerce to be installed and active.', 'untappd-ratings-for-woocommerce' ) );
			}
		}
	);
}
