<?php
/**
 * AutoCoupons
 *
 * @author    Chillcode
 * @copyright Copyright (c) 2003-2024, Chillcode All rights reserved.
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 * @package   Auto Coupons for WooCommerce
 */

namespace ACWC;

use WC_Cart;
use WC_Coupon;
use WC_Discounts;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * AutoCoupons class.
 */
class AutoCoupons {
	/**
	 * Coupons available as discounts.
	 *
	 * @var array
	 */
	private static $discount_available_coupons = array();

	/**
	 * Coupons object array.
	 *
	 * @var array
	 */
	private static $discount_coupon_objects = array();

	/**
	 * Coupons applied as discounts.
	 *
	 * @var array
	 */
	private static $discount_applied_coupons = array();

	/**
	 * Singleton instance.
	 *
	 * @var AutoCoupons
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		/**
		 * Check if Woo is installed and active otherwise show a notice on plugins page.
		 */
		$woocommerce_plugin_path = trailingslashit( WP_PLUGIN_DIR ) . 'woocommerce/woocommerce.php';

		if (
			in_array( $woocommerce_plugin_path, wp_get_active_and_valid_plugins(), true ) ||
			is_multisite() && in_array( $woocommerce_plugin_path, wp_get_active_network_plugins(), true )
		) {
			add_action(
				'plugins_loaded',
				array( __CLASS__, 'plugins_loaded' )
			);
		} else {
			add_action(
				'admin_notices',
				function () {
					global $pagenow;

					if ( 'plugins.php' === $pagenow ) {
						printf( '<div class="%1$s"><p>%2$s</p></div>', 'notice notice-error is-dismissible', esc_html__( 'Auto Coupons for WooCommerce requires WooCommerce to be installed and active.', 'auto-coupons-for-woocommerce' ) );
					}
				}
			);
		}
	}

	/**
	 * Plugins loaded.
	 */
	public static function plugins_loaded() {
		if ( is_admin() ) {
			add_filter( 'woocommerce_general_settings', array( __CLASS__, 'woocommerce_general_settings' ) );
		}

		if ( ! wc_coupons_enabled() || ! self::auto_coupons_enabled() ) {
			return;
		}

		if ( is_admin() ) {
			add_action( 'woocommerce_coupon_options', array( __CLASS__, 'woocommerce_coupon_options' ), 10, 2 );
			add_action( 'woocommerce_coupon_options_save', array( __CLASS__, 'woocommerce_coupon_options_save' ), 10, 1 );
		}

		self::$discount_available_coupons = self::get_automated_coupons();

		if ( ! empty( self::$discount_available_coupons ) ) {

			add_filter( 'woocommerce_coupon_error', array( __CLASS__, 'woocommerce_coupon_error' ), 10, 3 );
			add_filter( 'woocommerce_coupon_message', array( __CLASS__, 'woocommerce_coupon_message' ), 10, 3 );

			add_action( 'woocommerce_after_calculate_totals', array( __CLASS__, 'woocommerce_after_calculate_totals' ) );
			add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'woocommerce_after_checkout_validation' ), -1, 0 );

			add_filter( 'woocommerce_cart_totals_coupon_label', array( __CLASS__, 'woocommerce_cart_totals_coupon_label' ), 10, 2 );
			add_filter( 'woocommerce_cart_totals_coupon_html', array( __CLASS__, 'woocommerce_cart_totals_coupon_html' ), 10, 3 );
			add_filter( 'woocommerce_cart_product_subtotal', array( __CLASS__, 'woocommerce_cart_product_subtotal' ), 10, 4 );
		}
	}

	/**
	 * Check if auto coupons are enabled.
	 * Filterable.
	 *
	 * @since  1.0.2
	 *
	 * @return bool
	 */
	public static function auto_coupons_enabled() {
		return apply_filters( 'woocommerce_enable_auto_coupons', 'yes' === get_option( 'acwc_enable_auto_coupons' ) );
	}

	/**
	 * Check if coupon is set as automatic discount.
	 *
	 * @param WC_Coupon $coupon Coupon.
	 */
	private static function coupon_is_autoapply( WC_Coupon $coupon ) {
		return ( filter_var( $coupon->get_meta( '_acwc_discount_autoapply', true ), FILTER_VALIDATE_BOOLEAN ) ? true : false );
	}

	/***
	 * Get coupon by id
	 *
	 * @param int $coupon_id Coupon ID.
	 */
	private static function get_coupon_object( int $coupon_id ): WC_Coupon {
		if ( ! isset( self::$discount_coupon_objects[ $coupon_id ] ) ) {
			self::$discount_coupon_objects[ $coupon_id ] = new WC_Coupon( $coupon_id );
		}
		return self::$discount_coupon_objects[ $coupon_id ];
	}

	/**
	 * Get all coupons marked as automatic.
	 *
	 * @since 1.0.0
	 *
	 * @return mixed
	 */
	public static function get_automated_coupons() {
		$get_automated_coupons = wp_cache_get( 'get_automated_coupons' );

		if ( false !== $get_automated_coupons ) {
			return $get_automated_coupons;
		}

		$get_automated_coupons = array();

		$args = array(
			'posts_per_page'   => -1,
			'suppress_filters' => 0,
			'orderby'          => 'title',
			'order'            => 'asc',
			'fields'           => 'ids',
			'post_type'        => 'shop_coupon',
			'post_status'      => 'publish',
			// PHPCS:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query'       => array(
				'relation' => 'AND',
				array(
					'key'   => '_acwc_discount_autoapply',
					'value' => 1,
				),
			),
		);

		$get_automated_coupons = get_posts( $args );

		wp_cache_set( 'get_automated_coupons', $get_automated_coupons );

		return $get_automated_coupons;
	}

	/**
	 * Add an additional checkbox to Woo general settings to enable/disable automatic coupons.
	 *
	 * @param array $settings Settings Tab.
	 */
	public static function woocommerce_general_settings( array $settings ) {
		$updated_settings = array();

		foreach ( $settings as $section ) {
			$updated_settings[] = $section;
			if ( isset( $section['id'] ) && 'woocommerce_enable_coupons' === $section['id'] ) {
				$updated_settings[] = array(
					'desc'            => __( 'Allow coupons to apply automatically', 'auto-coupons-for-woocommerce' ),
					'desc_tip'        => __( 'Coupons can be applied automatically without user interaction.', 'auto-coupons-for-woocommerce' ),
					'id'              => 'acwc_enable_auto_coupons',
					'default'         => 'no',
					'type'            => 'checkbox',
					'checkboxgroup'   => '',
					'show_if_checked' => 'yes',
				);
			}
		}

		return $updated_settings;
	}

	/**
	 * Add a checkbox to the coupon page to make it automatic.
	 *
	 * @param int       $coupon_id Coupon ID.
	 * @param WC_Coupon $coupon Coupon.
	 */
	public static function woocommerce_coupon_options( $coupon_id, $coupon ) {
		woocommerce_wp_checkbox(
			array(
				'id'          => 'discount_autoapply',
				'label'       => __( 'Allow automatic application', 'auto-coupons-for-woocommerce' ),
				'description' => __( 'Apply this coupon automatically as a discount.', 'auto-coupons-for-woocommerce' ),
				'value'       => self::coupon_is_autoapply( $coupon ) ? 'yes' : 'no ',
			)
		);
	}

	/**
	 * Save auto coupon options.
	 *
	 * @since 1.0.0
	 *
	 * @param int $coupon_id Coupon ID.
	 */
	public static function woocommerce_coupon_options_save( int $coupon_id ) {
		if (
			! check_ajax_referer( 'woocommerce_save_data', 'woocommerce_meta_nonce', false ) ||
			! current_user_can( 'edit_post', $coupon_id )
		) {
			return;
		}

		$discount_autoapply = ( filter_input( INPUT_POST, 'discount_autoapply', FILTER_VALIDATE_BOOLEAN ) ) ? true : false;

		update_post_meta( $coupon_id, '_acwc_discount_autoapply', $discount_autoapply );
	}

	/**
	 * Main AutoCoupons Instance.
	 *
	 * Ensures only one instance of AutoCoupons is loaded or can be loaded.
	 *
	 * @since 1.0.0
	 *
	 * @return AutoCoupons - Main instance.
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Returns the subtotal for a cart item adding a discount label.
	 *
	 * @since 1.0.0
	 *
	 * @param string     $product_subtotal Subtotal.
	 * @param WC_Product $product Product Object.
	 * @param int        $quantity Quantity.
	 * @param WC_Cart    $cart Cart Object.
	 */
	public static function woocommerce_cart_product_subtotal( string $product_subtotal, WC_Product $product, int $quantity, WC_Cart $cart ) {
		if ( ! isset( self::$discount_applied_coupons[ $product->get_id() ] ) ) {
			return $product_subtotal;
		}

		$price = $product->get_price();

		if ( ! $cart->get_customer()->get_is_vat_exempt() && $product->is_taxable() ) {
			if ( $cart->display_prices_including_tax() ) {
				$row_price = wc_get_price_including_tax( $product, array( 'qty' => $quantity ) );
			} else {
				$row_price = wc_get_price_excluding_tax( $product, array( 'qty' => $quantity ) );
			}
		} else {
			$row_price = $price * $quantity;
		}

		$discount_product_total_amount = 0;

		foreach ( self::$discount_applied_coupons[ $product->get_id() ] as $coupon_id => $coupon_cart_item_key ) {

			$current_cupon = self::get_coupon_object( $coupon_id );

			$discount_product_total_amount += $current_cupon->get_discount_amount( $row_price, $cart->get_cart_item( $coupon_cart_item_key ) );
		}

		$product_subtotal = '<del style="color:red">' . wc_price( $row_price ) . '</del><div>' . wc_price( $row_price - $discount_product_total_amount ) . '</div>';

		return apply_filters( 'acwc_cart_product_subtotal', $product_subtotal, $row_price, $discount_product_total_amount );
	}

	/**
	 * Coupon Label on Cart Page.
	 *
	 * @since 1.0.0
	 *
	 * @param string    $label Label to display.
	 * @param WC_Coupon $coupon Coupon Object.
	 */
	public static function woocommerce_cart_totals_coupon_label( string $label, WC_Coupon $coupon ) {

		if ( $coupon && self::coupon_is_autoapply( $coupon ) ) {
			$label = __( 'Discount type', 'auto-coupons-for-woocommerce' ) . ': ' . $coupon->get_amount() . ( ( 'percent' === $coupon->get_discount_type() ) ? '% ' : get_woocommerce_currency_symbol() );
		}

		return $label;
	}

	/**
	 * Html Label on Cart totals.
	 *
	 * @since 1.0.0
	 *
	 * @param string    $coupon_html Html to display.
	 * @param WC_Coupon $coupon Coupon Object.
	 * @param string    $discount_amount_html Discounted amount to display.
	 */
	public static function woocommerce_cart_totals_coupon_html( string $coupon_html, WC_Coupon $coupon, string $discount_amount_html ) {
		if ( $coupon && self::coupon_is_autoapply( $coupon ) ) {
			$coupon_html = $discount_amount_html;
		}

		return $coupon_html;
	}

	/**
	 * Error Codes:
	 * - 100: Invalid filtered.
	 * - 101: Invalid removed.
	 * - 102: Not yours removed.
	 * - 103: Already applied.
	 * - 104: Individual use only.
	 * - 105: Not exists.
	 * - 106: Usage limit reached.
	 * - 107: Expired.
	 * - 108: Minimum spend limit not met.
	 * - 109: Not applicable.
	 * - 110: Not valid for sale items.
	 * - 111: Missing coupon code.
	 * - 112: Maximum spend limit met.
	 * - 113: Excluded products.
	 * - 114: Excluded categories.
	 * - 115:
	 * - 116:
	 *
	 * @param  string    $error_message Message.
	 * @param  int       $error_code Code.
	 * @param  WC_Coupon $coupon Coupon.
	 * @return string
	 */
	public static function woocommerce_coupon_error( string $error_message, int $error_code, $coupon ) {
		if ( $coupon && self::coupon_is_autoapply( $coupon ) ) {
			switch ( $error_code ) {
				case 100:
				case 101:
				case WC_Coupon::E_WC_COUPON_NOT_YOURS_REMOVED:
				case 103:
				case WC_Coupon::E_WC_COUPON_ALREADY_APPLIED_INDIV_USE_ONLY:
				case 105:
				case 106:
				case 107:
				case 108:
				case 109:
				case 110:
				case 111:
				case 112:
				case 113:
				case 114:
				case 115:
				case 116:
					$error_message = '';
					break;
			}
		}

		return $error_message;
	}

	/**
	 * Error Codes:
	 * - 200: Applied.
	 * - 201: Removed.
	 *
	 * @param  string    $message Message.
	 * @param  int       $message_code Code.
	 * @param  WC_Coupon $coupon Coupon.
	 * @return string
	 */
	public static function woocommerce_coupon_message( string $message, int $message_code, $coupon ) {
		if ( $coupon && self::coupon_is_autoapply( $coupon ) ) {
			switch ( $message_code ) {
				case WC_Coupon::WC_COUPON_REMOVED:
				case WC_Coupon::WC_COUPON_SUCCESS:
					$message = '';
					break;
			}
		}

		return $message;
	}

	/**
	 * Check if Coupon is valid.
	 *
	 * @param WC_Coupon $coupon Coupon.
	 */
	public static function is_valid( WC_Coupon $coupon ) {

		$discounts = new WC_Discounts( WC()->cart );
		$valid     = $discounts->is_coupon_valid( $coupon );

		if ( is_wp_error( $valid ) ) {

			return false;
		}

		return $valid;
	}

	/**
	 * Apply automatic coupons to WC_Cart.
	 *
	 * @param WC_Cart $cart Cart to apply copupons.
	 *
	 * @return void
	 */
	private static function apply_coupons( WC_Cart $cart ) {
		$apply_coupons_noticies = array();

		foreach ( self::$discount_available_coupons as $coupon_id ) {
			$coupon      = self::get_coupon_object( $coupon_id );
			$coupon_code = $coupon->get_code();

			/** Remove all the auto coupons to prevent updated or previously applied coupons. */
			$cart->remove_coupon( $coupon_code );

			if ( ! self::is_valid( $coupon ) || $cart->add_discount( $coupon_code ) !== true ) {
				continue;
			}

			$discount_product = false;
			$discount_symbol  = '%';

			switch ( $coupon->get_discount_type() ) {
				case 'percent':
					$discount_product = true;
					break;
				case 'fixed_product':
					$discount_product = true;
					// Not a product discount but share same symbol.
				case 'fixed':
					$discount_symbol = get_woocommerce_currency_symbol();
			}

			if ( $coupon->is_valid_for_cart() ) {
				if ( true === is_cart() ) {
					// translators: Text to show when cart coupons are applied, %1$s can be amount or percentage quantity.
					$apply_coupons_noticies[] = sprintf( __( 'A %1$s discount has been applied to the cart.', 'auto-coupons-for-woocommerce' ), $coupon->get_amount() . $discount_symbol );
				}
			}

			if ( $discount_product ) {
				foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
					if ( $coupon->is_valid_for_product( $cart_item['data'] ) ) {
						self::$discount_applied_coupons[ $cart_item['data']->get_id() ][ $coupon_id ] = $cart_item_key;

						if ( true === is_cart() ) {
							// translators: Text to show when product coupons are applied, %1$s can be amount or percentage quantity.
							$apply_coupons_noticies[] = sprintf( __( 'A %1$s discount has been applied to the following product %2$s.', 'auto-coupons-for-woocommerce' ), $coupon->get_amount() . $discount_symbol, $cart_item['data']->get_name() );
						}
					}
				}
			}
		}

		array_walk(
			$apply_coupons_noticies,
			function ( $notice ) {
				wc_add_notice( $notice, 'notice' );
			}
		);
	}

	/**
	 * Wrapper to mark products as discounted in cart.
	 *
	 * @param WC_Cart $cart Cart Object.
	 */
	public static function woocommerce_after_calculate_totals( WC_Cart $cart ) {

		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		if ( did_action( 'woocommerce_after_calculate_totals' ) >= 2 ) {
			return;
		}

		self::apply_coupons( $cart );
	}

	/**
	 * Apply coupons after checkout.
	 */
	public static function woocommerce_after_checkout_validation() {
		self::apply_coupons( WC()->cart );
	}

	/**
	 * Delete postmeta data.
	 *
	 * @return int|false
	 */
	public static function delete_meta() {
		/**
		 * WP_Query
		 *
		 * @var WP_Query $wpdb
		 */
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->query( "DELETE FROM $wpdb->postmeta WHERE meta_key LIKE '\_acwc%'" );
	}

	/**
	 * Delete options.
	 *
	 * @return int|false
	 */
	private static function delete_options() {
		/**
		 * WP_Query
		 *
		 * @var WP_Query $wpdb
		 */
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE 'acwc%'" );
	}

	/**
	 * Activate plugin, keep for meta update.
	 *
	 * @return void
	 */
	public static function activate() {
	}

	/**
	 * Deactivate plugin.
	 *
	 * @return void
	 */
	public static function deactivate() {
	}

	/**
	 * Uninstall plugin.
	 *
	 * @return void
	 */
	public static function uninstall() {
		self::delete_meta();
		self::delete_options();
	}
}

register_activation_hook(
	ACWC_PLUGIN_FILE,
	array( 'AutoCoupons', 'activate' )
);

register_deactivation_hook(
	ACWC_PLUGIN_FILE,
	array( 'AutoCoupons', 'deactivate' )
);

register_uninstall_hook(
	ACWC_PLUGIN_FILE,
	array( 'AutoCoupons', 'uninstall' )
);
