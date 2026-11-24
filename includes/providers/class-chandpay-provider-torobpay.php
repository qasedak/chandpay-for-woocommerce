<?php
/**
 * TorobPay adapter.
 *
 * Verify is required. About ten minutes after verify the provider settles
 * on its own. externalSourceAmount is the rest of the order.
 *
 * @package Chandpay
 */

defined( 'ABSPATH' ) || exit;

/**
 * TorobPay partial payments.
 */
class Chandpay_Provider_Torobpay extends Chandpay_Provider_Cpg {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'torobpay';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return 'ترب‌پی';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function credentials() {
		return Chandpay_Credentials::torobpay();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function redirect_hosts() {
		return array( 'torobpay.com' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function min_rial() {
		return 200000;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function auto_settle() {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function revert_on_failed() {
		return false;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function token_extra( WC_Order $order, array $part, array $cart ) {
		unset( $part, $cart );

		$first = $order->get_shipping_first_name() ? $order->get_shipping_first_name() : $order->get_billing_first_name();
		$last  = $order->get_shipping_last_name() ? $order->get_shipping_last_name() : $order->get_billing_last_name();
		$lines = array_filter(
			array(
				$order->get_shipping_address_1(),
				$order->get_shipping_address_2(),
			)
		);
		if ( ! $lines ) {
			$lines = array_filter(
				array(
					$order->get_billing_address_1(),
					$order->get_billing_address_2(),
				)
			);
		}

		return array(
			'customer_full_name'        => trim( $first . ' ' . $last ),
			'province'                  => $order->get_shipping_state() ? $order->get_shipping_state() : (string) $order->get_billing_state(),
			'city'                      => $order->get_shipping_city() ? $order->get_shipping_city() : (string) $order->get_billing_city(),
			'address'                   => $lines ? implode( '، ', $lines ) : '',
			'postalCode'                => $order->get_shipping_postcode() ? $order->get_shipping_postcode() : (string) $order->get_billing_postcode(),
			'registration_phone_number' => '',
		);
	}
}
