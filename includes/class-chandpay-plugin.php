<?php
/**
 * Plugin hooks.
 *
 * @package Chandpay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires Chandpay into WooCommerce.
 */
class Chandpay_Plugin {

	/**
	 * Register hooks once.
	 *
	 * @return void
	 */
	public static function init() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;

		add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'gateways' ) );
		add_filter( 'woocommerce_cancel_unpaid_order', array( __CLASS__, 'keep_pending' ), 10, 2 );
		add_filter( 'woocommerce_email_enabled_customer_on_hold_order', array( __CLASS__, 'suppress_on_hold_email' ), 10, 2 );
		add_action( 'woocommerce_order_status_completed', array( 'Chandpay_Service', 'deliver_completed' ) );

		Chandpay_Pay_Page::init();
		Chandpay_Admin::init();
	}

	/**
	 * Register the gateway class.
	 *
	 * @param array $gateways Gateway class names.
	 * @return array
	 */
	public static function gateways( $gateways ) {
		$gateways[] = 'Chandpay_Gateway';

		return $gateways;
	}

	/**
	 * WooCommerce must not cancel a Chandpay order that is still collecting.
	 *
	 * @param bool     $cancel Whether to cancel.
	 * @param WC_Order $order  Order.
	 * @return bool
	 */
	public static function keep_pending( $cancel, $order ) {
		if ( $order instanceof WC_Order && 'chandpay' === $order->get_payment_method() ) {
			return false;
		}

		return $cancel;
	}

	/**
	 * The processing email is the receipt. On-hold is only the gap between parts.
	 *
	 * @param bool          $enabled Whether the email sends.
	 * @param WC_Order|null $order   Order.
	 * @return bool
	 */
	public static function suppress_on_hold_email( $enabled, $order = null ) {
		if ( $order instanceof WC_Order && 'chandpay' === $order->get_payment_method() ) {
			return false;
		}

		return $enabled;
	}
}
