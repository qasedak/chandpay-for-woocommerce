<?php
/**
 * Checkout block payment method. The first part is chosen in the payment box.
 *
 * @package Chandpay
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Registers Chandpay with the checkout block.
 */
final class Chandpay_Blocks extends AbstractPaymentMethodType {

	/**
	 * Gateway id.
	 *
	 * @var string
	 */
	protected $name = 'chandpay';

	/**
	 * Load settings.
	 *
	 * @return void
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_chandpay_settings', array() );
		if ( ! is_array( $this->settings ) ) {
			$this->settings = array();
		}
	}

	/**
	 * Whether the block should offer Chandpay.
	 *
	 * @return bool
	 */
	public function is_active() {
		if ( empty( $this->settings['enabled'] ) || 'yes' !== $this->settings['enabled'] ) {
			return false;
		}
		if ( ! Chandpay_Money::supports( get_woocommerce_currency() ) ) {
			return false;
		}

		return Chandpay_Registry::has_available();
	}

	/**
	 * Script handles.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles() {
		$deps = array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' );
		$deps = array_values(
			array_filter(
				$deps,
				static function ( $handle ) {
					return wp_script_is( $handle, 'registered' );
				}
			)
		);

		wp_register_script(
			'chandpay-blocks',
			CHANDPAY_URL . 'assets/js/blocks.js',
			$deps,
			CHANDPAY_VERSION,
			true
		);

		return array( 'chandpay-blocks' );
	}

	/**
	 * Data for the block script.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		$total = '';
		if ( function_exists( 'WC' ) && WC()->cart && function_exists( 'get_woocommerce_currency' ) ) {
			$currency = get_woocommerce_currency();
			$total    = Chandpay_Money::store_string(
				Chandpay_Money::to_rial( WC()->cart->get_total( 'edit' ), $currency ),
				$currency,
				Chandpay_Service::decimals()
			);
		}

		return array(
			'title'       => $this->get_setting( 'title', 'پرداخت ترکیبی' ),
			'description' => $this->get_setting( 'description', '' ),
			'supports'    => array( 'products' ),
			'providers'   => Chandpay_Gateway::checkout_provider_rows(),
			'total'       => $total,
		);
	}
}
