<?php
/**
 * Pay-remaining page and provider return endpoint.
 *
 * @package Chandpay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopper HTTP endpoints.
 */
class Chandpay_Pay_Page {

	/**
	 * Register the WooCommerce API endpoints.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'woocommerce_api_chandpay_pay', array( __CLASS__, 'render' ) );
		add_action( 'woocommerce_api_chandpay_return', array( __CLASS__, 'returned' ) );
	}

	/**
	 * Provider callback. The order key in the query is the credential.
	 *
	 * @return void
	 */
	public static function returned() {
		self::nocache();
		$order = Chandpay_Service::authorized_order();
		if ( ! $order ) {
			wp_die( esc_html__( 'This payment link is not valid.', 'chandpay-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Provider callback authenticated by the order key.
		$part_id = isset( $_GET['part'] ) ? sanitize_text_field( wp_unslash( $_GET['part'] ) ) : '';
		$part_id = preg_replace( '/[^A-Za-z0-9]/', '', (string) $part_id );
		$result  = Chandpay_Service::handle_return( $order, $part_id, self::bag( $_GET ), self::bag( $_POST ) );

		if ( is_wp_error( $result ) ) {
			Chandpay_Service::flash( $order, 'error', $result->get_error_message() );
			wp_safe_redirect( Chandpay_Service::pay_url( $order ) );
			exit;
		}

		wp_safe_redirect( $result['redirect'] ? $result['redirect'] : Chandpay_Service::pay_url( $order ) );
		exit;
	}

	/**
	 * Pay-remaining page.
	 *
	 * @return void
	 */
	public static function render() {
		self::nocache();
		$order = Chandpay_Service::authorized_order();
		if ( ! $order ) {
			wp_die( esc_html__( 'This payment link is not valid.', 'chandpay-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		if ( 'POST' === strtoupper( self::method() ) ) {
			self::post( $order );
		}

		$prepared = Chandpay_Service::prepare( $order );
		if ( ! empty( $prepared['redirect'] ) ) {
			wp_safe_redirect( $prepared['redirect'] );
			exit;
		}

		$order = wc_get_order( $order->get_id() );
		$view  = self::view( $order, $prepared['state'] );

		wp_enqueue_style( 'chandpay-pay', CHANDPAY_URL . 'assets/css/pay.css', array(), CHANDPAY_VERSION );
		wp_enqueue_script( 'chandpay-pay', CHANDPAY_URL . 'assets/js/pay.js', array(), CHANDPAY_VERSION, true );

		status_header( 200 );
		if ( function_exists( 'get_header' ) ) {
			get_header();
		}
		include CHANDPAY_PATH . 'templates/pay-remaining.php';
		if ( function_exists( 'get_footer' ) ) {
			get_footer();
		} else {
			wp_footer();
		}
		exit;
	}

	/**
	 * Handle a shopper action, then redirect.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	private static function post( WC_Order $order ) {
		$nonce = isset( $_POST['chandpay_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['chandpay_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'chandpay_pay_' . $order->get_id() ) ) {
			Chandpay_Service::flash( $order, 'error', __( 'The form expired. Refresh the page and try again.', 'chandpay-for-woocommerce' ) );
			wp_safe_redirect( Chandpay_Service::pay_url( $order ) );
			exit;
		}

		$action  = isset( $_POST['chandpay_action'] ) ? sanitize_key( wp_unslash( $_POST['chandpay_action'] ) ) : '';
		$part_id = isset( $_POST['chandpay_part'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', sanitize_text_field( wp_unslash( $_POST['chandpay_part'] ) ) ) : '';
		$result  = null;

		if ( 'start' === $action ) {
			$provider = isset( $_POST['chandpay_provider'] ) ? sanitize_key( wp_unslash( $_POST['chandpay_provider'] ) ) : '';
			$raw      = isset( $_POST['chandpay_amount'] ) ? wp_unslash( $_POST['chandpay_amount'] ) : '';
			$entered  = Chandpay_Money::parse_amount( $raw );
			if ( null === $entered ) {
				$result = new WP_Error( 'chandpay_amount', __( 'Enter the amount for this payment.', 'chandpay-for-woocommerce' ) );
			} else {
				$result = Chandpay_Service::start( $order, $provider, $entered );
			}
		} elseif ( 'retry' === $action ) {
			$result = Chandpay_Service::retry( $order, $part_id );
		} elseif ( 'change' === $action ) {
			$result = Chandpay_Service::dismiss( $order, $part_id );
		} elseif ( 'abandon' === $action ) {
			$result = Chandpay_Service::abandon( $order, $part_id );
		} else {
			$result = new WP_Error( 'chandpay_action', __( 'That action is not available.', 'chandpay-for-woocommerce' ) );
		}

		if ( is_wp_error( $result ) ) {
			Chandpay_Service::flash( $order, 'error', $result->get_error_message() );
			wp_safe_redirect( Chandpay_Service::pay_url( $order ) );
			exit;
		}

		if ( ! empty( $result['redirect'] ) && ! empty( $result['hosts'] ) ) {
			if ( ! Chandpay_Redirect::away( $result['redirect'], $result['hosts'] ) ) {
				Chandpay_Service::flash( $order, 'error', __( 'The payment page address was rejected.', 'chandpay-for-woocommerce' ) );
				wp_safe_redirect( Chandpay_Service::pay_url( $order ) );
				exit;
			}
		}

		wp_safe_redirect( Chandpay_Service::pay_url( $order ) );
		exit;
	}

	/**
	 * View model for the template.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $state Ledger.
	 * @return array
	 */
	private static function view( WC_Order $order, array $state ) {
		$total     = Chandpay_Service::order_rial( $order );
		$remaining = Chandpay_Ledger::remaining( $state, $total );
		$captured  = Chandpay_Ledger::sum( $state, array( 'verified', 'settled' ) );
		$closed    = in_array( $state['phase'], array( 'voiding', 'voided' ), true )
			|| in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed' ), true );

		$done = array();
		foreach ( $state['parts'] as $part ) {
			if ( ! in_array( $part['status'], array( 'verified', 'settled' ), true ) ) {
				continue;
			}
			$provider = Chandpay_Registry::get( $part['provider'] );
			$done[]   = array(
				'label'  => $provider ? $provider->label() : $part['provider'],
				'amount' => Chandpay_Service::format_rial( (int) $part['amount'], $order ),
			);
		}

		$open = null;
		$live = Chandpay_Ledger::open_started( $state );
		if ( $live ) {
			$provider = Chandpay_Registry::get( $live['provider'] );
			$open     = array(
				'id'      => $live['id'],
				'label'   => $provider ? $provider->label() : $live['provider'],
				'amount'  => Chandpay_Service::format_rial( (int) $live['amount'], $order ),
				'url'     => $live['payment_url'],
			);
		}

		$failed = null;
		$latest = $open ? null : Chandpay_Ledger::latest_failed( $state );
		if ( $latest ) {
			$provider = Chandpay_Registry::get( $latest['provider'] );
			$failed   = array(
				'id'     => $latest['id'],
				'label'  => $provider ? $provider->label() : $latest['provider'],
				'amount' => Chandpay_Service::format_rial( (int) $latest['amount'], $order ),
				'error'  => $latest['last_error'],
			);
		}

		$providers = array();
		foreach ( Chandpay_Registry::available() as $provider ) {
			$min = (int) $provider->capabilities()['min_rial'];
			$providers[] = array(
				'id'    => $provider->id(),
				'label' => $provider->label(),
				'min'   => $min > 0 ? Chandpay_Service::format_rial( $min, $order ) : '',
			);
		}

		return array(
			'order_number'    => $order->get_order_number(),
			'total_html'      => Chandpay_Service::format_rial( $total, $order ),
			'captured_html'   => Chandpay_Service::format_rial( $captured, $order ),
			'remaining_html'  => Chandpay_Service::format_rial( $remaining, $order ),
			'remaining_store' => Chandpay_Money::store_string( $remaining, $order->get_currency(), Chandpay_Service::decimals() ),
			'flash'           => Chandpay_Service::pull_flash( $order ),
			'done'            => $done,
			'open'            => $open,
			'failed'          => $failed,
			'providers'       => $providers,
			'can_start'       => ! $closed && ! $open && ! $failed && $remaining > 0 && $providers,
			'waiting'         => ! $closed && ! $open && ! $failed && 0 === $remaining,
			'closed'          => $closed,
			'pay_url'         => Chandpay_Service::pay_url( $order ),
			'nonce'           => wp_nonce_field( 'chandpay_pay_' . $order->get_id(), 'chandpay_nonce', true, false ),
		);
	}

	/**
	 * Scalar request values, keeping camelCase keys.
	 *
	 * @param array $source Request array.
	 * @return array
	 */
	private static function bag( $source ) {
		$bag = array();
		foreach ( (array) $source as $key => $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$clean = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $key );
			if ( '' === $clean ) {
				continue;
			}
			$bag[ $clean ] = sanitize_text_field( wp_unslash( (string) $value ) );
		}

		return $bag;
	}

	/**
	 * Request method.
	 *
	 * @return string
	 */
	private static function method() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';

		return $method;
	}

	/**
	 * Stop caches from storing a payment page.
	 *
	 * @return void
	 */
	private static function nocache() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
	}
}
