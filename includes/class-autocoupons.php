<?php
/**
 * AutoCoupons
 *
 * @author    ChillCode
 * @copyright Copyright (c) 2024, ChillCode All rights reserved.
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
	private $discount_available_coupons = array(); // PHPCS:ignore Squiz.Commenting.VariableComment.Missing
	private $discount_coupon_objects    = array(); // PHPCS:ignore Squiz.Commenting.VariableComment.Missing
	private $discount_applied_coupons   = array(); // PHPCS:ignore Squiz.Commenting.VariableComment.Missing

	protected static $instance = null; // PHPCS:ignore Squiz.Commenting.VariableComment.Missing

	/**
	 * Constructor.
	 */
	public function __construct() {

		if ( is_admin() ) {
			add_filter( 'woocommerce_general_settings', array( $this, 'woocommerce_general_settings' ) );
		}

		if ( get_option( '_acwc_enable_auto_coupons' ) !== 'yes' ) {
			return;
		}

		add_filter( 'woocommerce_coupon_error', array( $this, 'woocommerce_coupon_error' ), 10, 3 );

		if ( is_admin() ) {
			add_action( 'woocommerce_coupon_options', array( $this, 'woocommerce_coupon_options' ), 10, 1 );
			add_action( 'woocommerce_coupon_options_save', array( $this, 'woocommerce_coupon_options_save' ), 10, 2 );
		}

		add_action( 'woocommerce_after_calculate_totals', array( $this, 'woocommerce_after_calculate_totals' ) );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'woocommerce_after_checkout_validation' ), -1, 1 );

		add_filter( 'woocommerce_cart_totals_coupon_label', array( $this, 'woocommerce_cart_totals_coupon_label' ), 10, 2 );
		add_filter( 'woocommerce_cart_totals_coupon_html', array( $this, 'woocommerce_cart_totals_coupon_html' ), 10, 3 );
		add_filter( 'woocommerce_cart_product_subtotal', array( $this, 'woocommerce_cart_product_subtotal' ), 10, 4 );

		$this->discount_available_coupons = $this->get_automated_coupons();
	}

	/***
	 * Get an instance of an automated coupon by id
	 *
	 * @param int $coupon_id Coupon ID.
	 */
	private function get_coupon_object( int $coupon_id ): WC_Coupon {
		if ( ! isset( $this->discount_coupon_objects[ $coupon_id ] ) ) {
			$this->discount_coupon_objects[ $coupon_id ] = new WC_Coupon( $coupon_id );
		}
		return $this->discount_coupon_objects[ $coupon_id ];
	}

	/***
	 * Get al list of all coupons marked as automatic.
	 */
	private function get_automated_coupons() {
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
					'key'   => 'discount_autoapply',
					'value' => 1,
				),
			),
		);

		$coupons = get_posts( $args );

		foreach ( $coupons as $coupon_id ) {
			array_push( $get_automated_coupons, $coupon_id );
		}

		wp_cache_set( 'get_automated_coupons', $get_automated_coupons );

		return $get_automated_coupons;
	}

	/**
	 * Add an additional checkbox to enable automatic coupons.
	 *
	 * @param array $settings Settings Tab.
	 */
	public function woocommerce_general_settings( array $settings ) {
		$updated_settings = array();

		foreach ( $settings as $section ) {

			if ( isset( $section['id'] ) && 'general_options' === $section['id'] && isset( $section['type'] ) && 'sectionend' === $section['type'] ) {
				$updated_settings[] = array(
					'desc'     => __( 'Allow coupons to apply automatically', 'auto-coupons-for-woocommerce' ),
					'desc_tip' => __( 'Apply coupons automatically without user interaction.', 'auto-coupons-for-woocommerce' ),
					'id'       => '_acwc_enable_auto_coupons',
					'default'  => 'no',
					'type'     => 'checkbox',
					'css'      => 'width:140px;',
				);
			}

			$updated_settings[] = $section;
		}

		return $updated_settings;
	}

	/**
	 * Add a checkbox to the coupon page to make it automatic.
	 *
	 * @param int $coupon_id Coupon ID.
	 */
	public function woocommerce_coupon_options( $coupon_id ) {
		woocommerce_wp_checkbox(
			array(
				'id'          => 'discount_autoapply',
				'label'       => __( 'Allow automatic application', 'auto-coupons-for-woocommerce' ),
				'description' => __( 'Apply this coupon automatically as a discount.', 'auto-coupons-for-woocommerce' ),
				'value'       => $this->is_autoapply( $coupon_id ) ? 'yes' : 'no ',
			)
		);
	}

	/**
	 * Coupon is Autoapply.
	 *
	 * @param int $coupon_id Coupon ID.
	 */
	private function is_autoapply( int $coupon_id ) {

		return ( filter_var( get_post_meta( $coupon_id, 'discount_autoapply', true ), FILTER_VALIDATE_BOOLEAN ) ? true : false );
	}

	/**
	 * Save settings.
	 *
	 * @param int       $coupon_id Coupon ID.
	 * @param WC_Coupon $coupon Coupon.
	 */
	public function woocommerce_coupon_options_save( int $coupon_id, WC_Coupon $coupon ) {
		if ( empty( $_POST['woocommerce_meta_nonce'] ) || ! wp_verify_nonce( filter_input( INPUT_POST, 'woocommerce_meta_nonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS ), 'woocommerce_save_data' ) ) {
			return;
		}

		if ( ! isset( $_POST['discount_autoapply'] ) ) {
			$discount_autoapply = 0;
		} else {
			$discount_autoapply = ( filter_input( INPUT_POST, 'discount_autoapply', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) === 'yes' ? 1 : 0 );
		}

		$discount_autoapply = ( 1 === $discount_autoapply && in_array( $coupon->get_discount_type(), array_keys( wc_get_coupon_types() ), true ) ) ? 1 : 0;

		update_post_meta( $coupon_id, 'discount_autoapply', $discount_autoapply );
	}

	/**
	 * Main AutoCoupons Instance.
	 *
	 * Ensures only one instance of AutoCoupons is loaded or can be loaded.
	 *
	 * @since 2.1
	 * @static
	 * @see WC()
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
	 * @since 1.0
	 *
	 * @param string     $product_subtotal Subtotal.
	 * @param WC_Product $product Product Object.
	 * @param int        $quantity Quantity.
	 * @param WC_Cart    $cart Cart Object.
	 */
	public function woocommerce_cart_product_subtotal( string $product_subtotal, WC_Product $product, int $quantity, WC_Cart $cart ) {
		if ( ! isset( $this->discount_applied_coupons[ $product->get_id() ] ) ) {
			return $product_subtotal;
		}

		$price = $product->get_price();

		if ( wc_tax_enabled() && ! $cart->get_customer()->get_is_vat_exempt() && $product->is_taxable() ) {
			if ( $cart->display_prices_including_tax() ) {
				$row_price        = wc_get_price_including_tax( $product, array( 'qty' => $quantity ) );
				$product_subtotal = wc_price( $row_price );

				if ( ! wc_prices_include_tax() && $cart->get_subtotal_tax() > 0 ) {
					$product_subtotal .= ' <small class="tax_label">' . WC()->countries->inc_tax_or_vat() . '</small>';
				}
			} else {
				$row_price        = wc_get_price_excluding_tax( $product, array( 'qty' => $quantity ) );
				$product_subtotal = wc_price( $row_price );

				if ( wc_prices_include_tax() && $cart->get_subtotal_tax() > 0 ) {
					$product_subtotal .= ' <small class="tax_label">' . WC()->countries->ex_tax_or_vat() . '</small>';
				}
			}
		} else {
			$row_price        = $price * $quantity;
			$product_subtotal = wc_price( $row_price );
		}

		$discount_product_total_amount = 0;

		foreach ( $this->discount_applied_coupons[ $product->get_id() ] as $coupon_id => $coupon_cart_item_key ) {

			$current_cupon = $this->get_coupon_object( $coupon_id );

			$discount_product_total_amount += $current_cupon->get_discount_amount( $row_price, WC()->cart->get_cart_item( $coupon_cart_item_key ) );
		}

		return '<del style="color:red">' . wc_price( $row_price ) . '</del><div>' . wc_price( $row_price - $discount_product_total_amount ) . ' <small class="tax_label">' . WC()->countries->inc_tax_or_vat() . '</small></div>';
	}

	/**
	 * Coupon Label on Cart Page.
	 *
	 * @since 1.0
	 *
	 * @param string    $label Label to display.
	 * @param WC_Coupon $coupon Coupon Object.
	 */
	public function woocommerce_cart_totals_coupon_label( string $label, WC_Coupon $coupon ) {

		if ( $coupon && $this->is_autoapply( $coupon->get_id() ) ) {
			$label = __( 'Discount type', 'auto-coupons-for-woocommerce' ) . ': ' . $coupon->get_amount() . ( ( 'percent' === $coupon->get_discount_type() ) ? '% ' : '€ ' );
		}

		return $label;
	}

	/**
	 * Html Label on Cart totals.
	 *
	 * @since 1.0
	 *
	 * @param string    $coupon_html Html to display.
	 * @param WC_Coupon $coupon Coupon Object.
	 * @param string    $discount_amount_html Discounted amount to display.
	 */
	public function woocommerce_cart_totals_coupon_html( string $coupon_html, WC_Coupon $coupon, string $discount_amount_html ) {
		if ( $coupon && $this->is_autoapply( $coupon->get_id() ) ) {
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
	 *
	 * @param  string    $error_message Message.
	 * @param  int       $error_code Code.
	 * @param  WC_Coupon $coupon Coupon.
	 * @return string
	 */
	public function woocommerce_coupon_error( string $error_message, int $error_code, $coupon ) {
		if ( $coupon && $this->is_autoapply( $coupon->get_id() ) ) {
			switch ( $error_code ) {
				case WC_Coupon::E_WC_COUPON_NOT_YOURS_REMOVED:
					$error_message = __( 'Sorry, it seems the discount is not yours - it has now been removed from your order.', 'auto-coupons-for-woocommerce' );
					break;
				case WC_Coupon::E_WC_COUPON_ALREADY_APPLIED_INDIV_USE_ONLY:
					$error_message = __( 'Sorry, a discount coupon has already been applied and cannot be used in conjunction with other coupons.', 'auto-coupons-for-woocommerce' );
					break;
				case 100:
				case 101:
				case 103:
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
					$error_message = '';

					break;
			}
		}

		return $error_message;
	}

	/**
	 * Check if Coupon is valid for the current user.
	 *
	 * @param WC_Coupon $coupon Coupon.
	 * @return bool
	 */
	private function is_coupon_valid_for_user( WC_Coupon $coupon ) {
		$restrictions = $coupon->get_email_restrictions();

		if ( is_array( $restrictions ) && count( $restrictions ) > 0 ) {

			$request_method = filter_input( INPUT_SERVER, 'REQUEST_METHOD', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

			switch ( $request_method ) {
				case 'GET':
					$filter_type = INPUT_GET;
					break;
				case 'POST':
					$filter_type = INPUT_POST;
					break;
				case 'COOKIE':
					$filter_type = INPUT_COOKIE;
					break;
			}

			$billing_email = '';

			$request_security = filter_input( $filter_type, 'security', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

			// Get incoming data only when the user input's the billing_email address in the order review page. Check Ajax call only.
			if ( ! empty( $request_security ) && wp_verify_nonce( $request_security, 'update-order-review' ) && isset( $_REQUEST['post_data'] ) && is_string( $_REQUEST['post_data'] ) ) {
				$post_data = filter_input( INPUT_POST, 'post_data', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

				$posted = array();

				parse_str( $post_data, $posted );

				$billing_email = ( ! empty( $posted['billing_email'] ) && filter_var( $posted['billing_email'], FILTER_VALIDATE_EMAIL ) !== false ) ? strtolower( sanitize_email( $posted['billing_email'] ) ) : '';

				unset( $posted );
			}

			// Populate array with current user email.
			$current_user = wp_get_current_user();

			$check_emails = array();

			$check_emails[] = strtolower( sanitize_email( $current_user->user_email ) );

			if ( ! empty( $billing_email ) && ! in_array( $billing_email, $check_emails, true ) ) {
				$check_emails[] = $billing_email;
			}

			if ( ! WC()->cart->is_coupon_emails_allowed( $check_emails, $restrictions ) ) {
				return false;
			} else {
				return true;
			}
		}

		return true;
	}

	/**
	 * Mark product as discounted in Cart.
	 *
	 * @param int  $coupon_id Coupon.
	 * @param bool $print_notice Print notice after product is marked.
	 */
	private function mark_products_as_discounted( int $coupon_id, bool $print_notice = false ) {

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {

			$coupon = $this->get_coupon_object( $coupon_id );

			if ( $this->is_coupon_valid_for_user( $coupon ) && $coupon->is_valid_for_product( $cart_item['data'] ) ) {

				$this->discount_applied_coupons[ $cart_item['data']->get_id() ][ $coupon_id ] = $cart_item_key;

				if ( true === $print_notice ) {
					// translators: Text to show when coupons applied.
					wc_add_notice( sprintf( __( 'We have applied a %1$s%% discount on the following product %2$s.', 'auto-coupons-for-woocommerce' ), $coupon->get_amount(), $cart_item['data']->get_name() ), 'notice' );
				} else {
					wc_clear_notices();
				}

				continue;
			}
		}
	}

	/**
	 * Check if Coupon is valid.
	 *
	 * @param WC_Coupon $coupon Coupon.
	 */
	public function is_valid( WC_Coupon $coupon ) {

		$discounts = new WC_Discounts( WC()->cart );
		$valid     = $discounts->is_coupon_valid( $coupon );

		if ( is_wp_error( $valid ) ) {

			return false;
		}

		return $valid;
	}

	/**
	 * Wrapper to mark products as discounted in cart.
	 *
	 * @param WC_Cart $cart Cart Object.
	 */
	public function woocommerce_after_calculate_totals( WC_Cart $cart ) {

		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		if ( did_action( 'woocommerce_after_calculate_totals' ) >= 2 ) {
			return;
		}

		$applied_coupons = $cart->get_applied_coupons();

		foreach ( $this->discount_available_coupons as $coupon_id ) {
			$coupon = $this->get_coupon_object( $coupon_id );

			$coupon_code = $coupon->get_code();

			if ( $this->is_coupon_valid_for_user( $coupon ) && $this->is_valid( $coupon ) ) {
				if ( ! in_array( $coupon_code, $applied_coupons, true ) ) {
					if ( WC()->cart->add_discount( $coupon_code ) === true ) {
						array_push( $applied_coupons, $coupon_code );

						$this->mark_products_as_discounted( $coupon_id, is_cart() );
					}
				} else {
					$this->mark_products_as_discounted( $coupon_id, is_cart() );
				}

				continue;
			} else {
				$cart->remove_coupon( $coupon_code );
			}
		}
	}

	/**
	 * Validate Coupon for registered users.
	 *
	 * @param array $posted Customer Data.
	 */
	public function woocommerce_after_checkout_validation( $posted ) {

		$applied_coupons = WC()->cart->get_applied_coupons();

		foreach ( $this->discount_available_coupons as $coupon_id ) {
			$coupon = $this->get_coupon_object( $coupon_id );

			if ( $this->is_valid( $coupon ) ) {
				$coupon_code = $coupon->get_code();

				$current_user = wp_get_current_user();
				$check_emails = array();

				$check_emails[] = strtolower( sanitize_email( $current_user->user_email ) );

				if ( ! empty( $posted['billing_email'] ) ) {
					$billing_email = strtolower( sanitize_email( $posted['billing_email'] ) );

					if ( ! in_array( $billing_email, $check_emails, true ) ) {
						$check_emails[] = $billing_email;
					}
				}

				$restrictions = $coupon->get_email_restrictions();

				if ( is_array( $restrictions ) && 0 < count( $restrictions ) && ! WC()->cart->is_coupon_emails_allowed( $check_emails, $restrictions ) ) {
					WC()->cart->remove_coupon( $coupon_code );
				} elseif ( ! in_array( $coupon_code, $applied_coupons, true ) && true === WC()->cart->add_discount( $coupon_code ) ) {
					array_push( $applied_coupons, $coupon_code );

					$this->mark_products_as_discounted( $coupon_id );
				}
			}
		}
	}
}
