<?php
/**
 * WooCommerce gateway. The first part is chosen at checkout.
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
		$this->has_fields         = true;
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		if ( ! self::$hooked ) {
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_checkout_assets' ) );
			add_action( 'woocommerce_rest_checkout_process_payment_with_context', array( $this, 'copy_store_api_payment_data' ), 5, 2 );
			self::$hooked = true;
		}
	}

	/**
	 * First provider and amount, revealed when Chandpay is selected.
	 *
	 * @return void
	 */
	public function payment_fields() {
		$providers    = self::checkout_provider_rows();
		$description  = $this->description;
		$remaining    = '';
		$symbol       = '';
		if ( $providers ) {
			list( $total_rial, $currency ) = $this->payable_context();
			$remaining                     = Chandpay_Money::store_string( $total_rial, $currency, Chandpay_Service::decimals() );
			if ( function_exists( 'get_woocommerce_currency_symbol' ) ) {
				$symbol = get_woocommerce_currency_symbol( $currency );
			}
		}

		include CHANDPAY_PATH . 'templates/checkout-fields.php';
	}

	/**
	 * Reject a classic checkout that has no usable first payment.
	 *
	 * Block checkout checks the same rules in process_payment().
	 *
	 * @return bool
	 */
	public function validate_fields() {
		if ( $this->is_store_api_request() ) {
			return true;
		}

		$choice = $this->posted_choice();
		if ( is_wp_error( $choice ) ) {
			wc_add_notice( $choice->get_error_message(), 'error' );
			return false;
		}

		list( $total_rial, $currency ) = $this->payable_context();
		$error                         = $this->choice_error( $choice['provider'], $choice['amount'], $total_rial, $currency );
		if ( is_wp_error( $error ) ) {
			wc_add_notice( $error->get_error_message(), 'error' );
			return false;
		}

		return true;
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
				'description' => __( 'Shown under the title. The shopper chooses the first provider in this payment box.', 'chandpay-for-woocommerce' ),
				'default'     => 'بخش اول را همین‌جا انتخاب می‌کنید و به درگاه می‌روید. اگر هنوز مبلغی مانده باشد، بعد از بازگشت روش بعدی را انتخاب می‌کنید.',
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
	 * Place the order, start the first part, and send the shopper to that provider.
	 *
	 * A provider error still places the order and opens the pay-remaining page.
	 *
	 * @param int $order_id Order id.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return $this->failure( __( 'The order could not be found.', 'chandpay-for-woocommerce' ) );
		}

		$choice = $this->posted_choice();
		if ( is_wp_error( $choice ) ) {
			return $this->failure( $choice->get_error_message() );
		}

		$error = $this->choice_error( $choice['provider'], $choice['amount'], Chandpay_Service::payable_rial( $order ), $order->get_currency() );
		if ( is_wp_error( $error ) ) {
			return $this->failure( $error->get_error_message() );
		}

		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}

		$started = Chandpay_Service::start( $order, $choice['provider'], $choice['amount'] );
		if ( is_wp_error( $started ) || empty( $started['redirect'] ) || empty( $started['hosts'] ) || ! Chandpay_Redirect::permit( $started['redirect'], $started['hosts'] ) ) {
			$message = is_wp_error( $started ) ? $started->get_error_message() : __( 'The payment page address was rejected.', 'chandpay-for-woocommerce' );
			Chandpay_Service::flash( $order, 'error', $message );
			return array(
				'result'   => 'success',
				'redirect' => Chandpay_Service::pay_url( $order ),
			);
		}

		return array(
			'result'   => 'success',
			'redirect' => $started['redirect'],
		);
	}

	/**
	 * Checkout styles for the first-payment fields.
	 *
	 * @return void
	 */
	public function enqueue_checkout_assets() {
		$is_checkout  = function_exists( 'is_checkout' ) && is_checkout();
		$is_order_pay = function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' );
		if ( ! $is_checkout && ! $is_order_pay ) {
			return;
		}
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			return;
		}

		wp_enqueue_style( 'chandpay-pay', CHANDPAY_URL . 'assets/css/pay.css', array(), chandpay_asset_version( 'assets/css/pay.css' ) );
		wp_enqueue_script( 'chandpay-pay', CHANDPAY_URL . 'assets/js/pay.js', array(), chandpay_asset_version( 'assets/js/pay.js' ), true );
	}

	/**
	 * Copy this gateway's block-checkout fields into POST for process_payment().
	 *
	 * Other payment methods on the same Store API hook are left alone.
	 *
	 * @param object $context Payment context.
	 * @return void
	 */
	public function copy_store_api_payment_data( $context ) {
		if ( 'chandpay' !== $this->context_payment_method( $context ) ) {
			return;
		}

		$data = array();
		if ( method_exists( $context, 'get_payment_data' ) ) {
			$data = $context->get_payment_data();
		} elseif ( isset( $context->payment_data ) ) {
			$data = $context->payment_data;
		}
		if ( ! is_array( $data ) ) {
			return;
		}

		$allowed = array(
			'chandpay_provider' => true,
			'chandpay_amount'   => true,
		);

		foreach ( $data as $key => $value ) {
			if ( is_object( $value ) && isset( $value->key ) ) {
				$key   = $value->key;
				$value = isset( $value->value ) ? $value->value : '';
			}
			if ( ! is_string( $key ) || ! isset( $allowed[ $key ] ) || ! is_scalar( $value ) ) {
				continue;
			}
			$_POST[ $key ] = (string) $value;
		}
	}

	/**
	 * Payment method id on a Store API payment context.
	 *
	 * @param object $context Payment context.
	 * @return string
	 */
	private function context_payment_method( $context ) {
		if ( ! is_object( $context ) ) {
			return '';
		}
		if ( method_exists( $context, 'get_payment_method' ) ) {
			return (string) $context->get_payment_method();
		}
		if ( isset( $context->payment_method ) ) {
			return (string) $context->payment_method;
		}

		return '';
	}

	/**
	 * Providers the shopper can pick, with a plain minimum label.
	 *
	 * @return array
	 */
	public static function checkout_provider_rows() {
		$rows     = array();
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'IRT';
		foreach ( Chandpay_Registry::available() as $provider ) {
			$min   = (int) $provider->capabilities()['min_rial'];
			$label = '';
			if ( $min > 0 && function_exists( 'wc_price' ) ) {
				$store = (float) Chandpay_Money::store_string( $min, $currency, Chandpay_Service::decimals() );
				$label = wp_strip_all_tags( wc_price( $store, array( 'currency' => $currency ) ) );
			}
			$rows[] = array(
				'id'       => $provider->id(),
				'label'    => $provider->label(),
				'logo'     => self::provider_logo( $provider->id() ),
				'min'      => $min,
				'minLabel' => $label,
			);
		}

		return $rows;
	}

	/**
	 * Logo URL for a known provider. Empty when the id has no logo.
	 *
	 * @param string $id Provider id.
	 * @return string
	 */
	public static function provider_logo( $id ) {
		$files = array(
			'digipay'  => 'assets/images/digipay.png',
			'snapppay' => 'assets/images/snapppay.jpg',
			'torobpay' => 'assets/images/torobpay.png',
		);
		if ( ! isset( $files[ $id ] ) ) {
			return '';
		}

		return CHANDPAY_URL . $files[ $id ];
	}

	/**
	 * Provider id and store-currency amount from checkout.
	 *
	 * @return array|WP_Error
	 */
	private function posted_choice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checkout nonce is checked before payment.
		$provider = isset( $_POST['chandpay_provider'] ) ? sanitize_key( wp_unslash( $_POST['chandpay_provider'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checkout nonce is checked before payment.
		$raw = isset( $_POST['chandpay_amount'] ) ? wp_unslash( $_POST['chandpay_amount'] ) : '';
		if ( ! is_string( $raw ) ) {
			$raw = '';
		}
		if ( '' === $provider ) {
			return new WP_Error( 'chandpay_provider', __( 'Choose the first payment method.', 'chandpay-for-woocommerce' ) );
		}

		$entered = Chandpay_Money::parse_amount( $raw );
		if ( null === $entered ) {
			return new WP_Error( 'chandpay_amount', __( 'Enter the amount for this payment.', 'chandpay-for-woocommerce' ) );
		}

		$adapter = Chandpay_Registry::get( $provider );
		if ( ! $adapter || ! $adapter->is_available() ) {
			return new WP_Error( 'chandpay_provider', __( 'That payment method is not available.', 'chandpay-for-woocommerce' ) );
		}

		return array(
			'provider' => $provider,
			'amount'   => $entered,
		);
	}

	/**
	 * Amount rules for the first part, before a provider token is created.
	 *
	 * @param string $provider_id Provider id.
	 * @param float  $entered     Store amount.
	 * @param int    $total_rial  Payable total in rial.
	 * @param string $currency    Store currency.
	 * @return true|WP_Error
	 */
	private function choice_error( $provider_id, $entered, $total_rial, $currency ) {
		$provider = Chandpay_Registry::get( $provider_id );
		if ( ! $provider ) {
			return new WP_Error( 'chandpay_provider', __( 'That payment method is not available.', 'chandpay-for-woocommerce' ) );
		}

		$rial = Chandpay_Money::charge_rial( $entered, (int) $total_rial, $currency, Chandpay_Service::decimals() );
		if ( $rial < 1 || $rial > (int) $total_rial ) {
			return new WP_Error( 'chandpay_amount', __( 'Enter an amount up to the remaining balance.', 'chandpay-for-woocommerce' ) );
		}

		$min = (int) $provider->capabilities()['min_rial'];
		if ( $min > 0 && $rial < $min ) {
			$formatted = (string) $min;
			if ( function_exists( 'wc_price' ) ) {
				$store     = (float) Chandpay_Money::store_string( $min, $currency, Chandpay_Service::decimals() );
				$formatted = wp_strip_all_tags( wc_price( $store, array( 'currency' => $currency ) ) );
			}
			return new WP_Error(
				'chandpay_min',
				sprintf(
					/* translators: %s: formatted minimum amount */
					__( 'This provider needs at least %s.', 'chandpay-for-woocommerce' ),
					$formatted
				)
			);
		}

		return true;
	}

	/**
	 * Cart total during checkout, or the order total on the pay-for-order page.
	 *
	 * @return array rial total and currency.
	 */
	private function payable_context() {
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
			$order_id = absint( get_query_var( 'order-pay' ) );
			$order    = $order_id ? wc_get_order( $order_id ) : false;
			if ( $order instanceof WC_Order ) {
				return array( Chandpay_Service::payable_rial( $order ), $order->get_currency() );
			}
		}

		$currency = get_woocommerce_currency();
		$amount   = ( function_exists( 'WC' ) && WC()->cart ) ? WC()->cart->get_total( 'edit' ) : 0;

		return array( Chandpay_Money::to_rial( $amount, $currency ), $currency );
	}

	/**
	 * Checkout failure payload for classic and block checkout.
	 *
	 * @param string $message Shopper-facing error.
	 * @return array
	 */
	private function failure( $message ) {
		if ( function_exists( 'wc_add_notice' ) && ! $this->is_store_api_request() ) {
			wc_add_notice( $message, 'error' );
		}

		return array(
			'result'   => 'failure',
			'message'  => $message,
			'messages' => $message,
		);
	}

	/**
	 * Whether this request is the checkout Store API.
	 *
	 * @return bool
	 */
	private function is_store_api_request() {
		return function_exists( 'WC' )
			&& is_object( WC() )
			&& method_exists( WC(), 'is_store_api_request' )
			&& WC()->is_store_api_request();
	}
}
