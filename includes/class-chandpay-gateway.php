<?php
/**
 * WooCommerce gateway. Checkout only creates the order and opens the pay page.
 *
 * @package Chandpay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Chandpay payment gateway.
 */
class Chandpay_Gateway extends WC_Payment_Gateway {

	/**
	 * Whether the settings hook is registered.
	 *
	 * @var bool
	 */
	private static $hooked = false;

	/**
	 * Set up the gateway.
	 */
	public function __construct() {
		$this->id                 = 'chandpay';
		$this->method_title       = __( 'Chandpay', 'chandpay-for-woocommerce' );
		$this->method_description = __( 'Let a shopper pay one order with several installment providers, one part at a time.', 'chandpay-for-woocommerce' );
		$this->has_fields         = false;
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		if ( ! self::$hooked ) {
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			self::$hooked = true;
		}
	}

	/**
	 * Settings fields.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'                 => array(
				'title'   => __( 'Enable', 'chandpay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Show Chandpay at checkout', 'chandpay-for-woocommerce' ),
				'default' => 'no',
			),
			'title'                   => array(
				'title'       => __( 'Title', 'chandpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Shown to the shopper at checkout.', 'chandpay-for-woocommerce' ),
				'default'     => 'پرداخت ترکیبی',
			),
			'description'             => array(
				'title'       => __( 'Description', 'chandpay-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Shown under the title. The shopper chooses providers on the next page.', 'chandpay-for-woocommerce' ),
				'default'     => 'مبلغ سفارش را با چند درگاه اقساطی پرداخت می‌کنید. هر بار یک بخش را انتخاب و پرداخت می‌کنید.',
			),
			'provider_torobpay'       => array(
				'title'   => 'ترب‌پی',
				'type'    => 'checkbox',
				'label'   => __( 'Offer TorobPay', 'chandpay-for-woocommerce' ),
				'default' => 'yes',
			),
			'torobpay_base_url'       => array(
				'title'       => __( 'TorobPay API base URL', 'chandpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Leave blank to use the official TorobPay plugin, or https://cpg.torobpay.com/ when those credentials are not saved.', 'chandpay-for-woocommerce' ),
				'default'     => 'https://cpg.torobpay.com/',
				'placeholder' => 'https://cpg.torobpay.com/',
			),
			'torobpay_client_id'      => array(
				'title'       => __( 'TorobPay client ID', 'chandpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Used only when the official TorobPay plugin has not saved a full credential set.', 'chandpay-for-woocommerce' ),
				'default'     => '',
			),
			'torobpay_client_secret'  => array(
				'title'   => __( 'TorobPay client secret', 'chandpay-for-woocommerce' ),
				'type'    => 'password',
				'default' => '',
			),
			'torobpay_client_username' => array(
				'title'   => __( 'TorobPay username', 'chandpay-for-woocommerce' ),
				'type'    => 'text',
				'default' => '',
			),
			'torobpay_client_password' => array(
				'title'   => __( 'TorobPay password', 'chandpay-for-woocommerce' ),
				'type'    => 'password',
				'default' => '',
			),
			'torobpay_dns_ip'         => array(
				'title'       => __( 'TorobPay API IP', 'chandpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Optional. If a TorobPay call fails with cURL error 28, enter 81.12.31.29 or the IP TorobPay support gives you. Chandpay uses this for its own TorobPay calls. The notice on the checkout page comes from the official plugin’s eligibility check, which Chandpay does not run.', 'chandpay-for-woocommerce' ),
				'default'     => '',
				'placeholder' => '81.12.31.29',
			),
			'provider_snapppay'       => array(
				'title'   => 'اسنپ‌پی',
				'type'    => 'checkbox',
				'label'   => __( 'Offer SnappPay', 'chandpay-for-woocommerce' ),
				'default' => 'yes',
			),
			'provider_digipay'        => array(
				'title'   => 'دیجی‌پی',
				'type'    => 'checkbox',
				'label'   => __( 'Offer DigiPay', 'chandpay-for-woocommerce' ),
				'default' => 'yes',
			),
			'snapppay_base_url'       => array(
				'title'       => __( 'SnappPay API base URL', 'chandpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Leave blank to use the official SnappPay plugin settings.', 'chandpay-for-woocommerce' ),
				'default'     => '',
			),
			'snapppay_client_id'      => array(
				'title'   => __( 'SnappPay client ID', 'chandpay-for-woocommerce' ),
				'type'    => 'text',
				'default' => '',
			),
			'snapppay_client_secret'  => array(
				'title'   => __( 'SnappPay client secret', 'chandpay-for-woocommerce' ),
				'type'    => 'password',
				'default' => '',
			),
			'snapppay_client_username' => array(
				'title'   => __( 'SnappPay username', 'chandpay-for-woocommerce' ),
				'type'    => 'text',
				'default' => '',
			),
			'snapppay_client_password' => array(
				'title'   => __( 'SnappPay password', 'chandpay-for-woocommerce' ),
				'type'    => 'password',
				'default' => '',
			),
			'digipay_username'        => array(
				'title'       => __( 'DigiPay username', 'chandpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Leave blank to use the official DigiPay plugin settings.', 'chandpay-for-woocommerce' ),
				'default'     => '',
			),
			'digipay_password'        => array(
				'title'   => __( 'DigiPay password', 'chandpay-for-woocommerce' ),
				'type'    => 'password',
				'default' => '',
			),
			'digipay_client_id'       => array(
				'title'   => __( 'DigiPay client ID', 'chandpay-for-woocommerce' ),
				'type'    => 'text',
				'default' => '',
			),
			'digipay_client_secret'   => array(
				'title'   => __( 'DigiPay client secret', 'chandpay-for-woocommerce' ),
				'type'    => 'password',
				'default' => '',
			),
		);
	}

	/**
	 * Chandpay is available when a provider can take a payment.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}
		if ( ! Chandpay_Money::supports( get_woocommerce_currency() ) ) {
			return false;
		}

		return Chandpay_Registry::has_available();
	}

	/**
	 * Create nothing at the provider. Send the shopper to the pay page.
	 *
	 * @param int $order_id Order id.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			$message = __( 'The order could not be found.', 'chandpay-for-woocommerce' );
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( $message, 'error' );
			}
			return array(
				'result'   => 'failure',
				'message'  => $message,
				'messages' => $message,
			);
		}

		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => Chandpay_Service::pay_url( $order ),
		);
	}
}
