<?php
/**
 * Shared client for the TorobPay and SnappPay online payment API.
 *
 * @package Chandpay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Token, verify, settle, revert, cancel, and status.
 */
abstract class Chandpay_Provider_Cpg implements Chandpay_Provider {

	/**
	 * Provider id.
	 *
	 * @return string
	 */
	abstract public function id();

	/**
	 * Shopper-facing name.
	 *
	 * @return string
	 */
	abstract public function label();

	/**
	 * Credential pack, or null.
	 *
	 * @return array|null
	 */
	abstract protected function credentials();

	/**
	 * Hosts that may host the payment page.
	 *
	 * @return array
	 */
	abstract protected function redirect_hosts();

	/**
	 * Minimum part in rial. Zero means the provider did not publish one.
	 *
	 * @return int
	 */
	abstract protected function min_rial();

	/**
	 * The provider settles on its own shortly after verify.
	 *
	 * @return bool
	 */
	abstract protected function auto_settle();

	/**
	 * A FAILED callback should be followed by revert.
	 *
	 * @return bool
	 */
	abstract protected function revert_on_failed();

	/**
	 * Extra JSON fields on the token request.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $part  Part.
	 * @param array    $cart  Cart payload.
	 * @return array
	 */
	abstract protected function token_extra( WC_Order $order, array $part, array $cart );

	/**
	 * Optional per-item change, such as a commission type.
	 *
	 * @return callable|null
	 */
	protected function item_mutator() {
		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available() {
		return Chandpay_Credentials::provider_enabled( $this->id() ) && is_array( $this->credentials() );
	}

	/**
	 * {@inheritDoc}
	 */
	public function capabilities() {
		return array(
			'partial'              => true,
			'external_source'      => true,
			'verify'               => true,
			'settle'               => true,
			'auto_settle'          => $this->auto_settle(),
			'revert'               => true,
			'cancel'               => true,
			'refund'               => false,
			'revert_on_failed'     => $this->revert_on_failed(),
			'min_rial'             => $this->min_rial(),
			'reservation_ttl'      => 20 * MINUTE_IN_SECONDS,
			'deliver_on_completed' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function redirect_hosts_for( $payment_url ) {
		$hosts = $this->redirect_hosts();
		$creds = $this->credentials();
		if ( $creds ) {
			$api_host = wp_parse_url( $creds['base_url'], PHP_URL_HOST );
			if ( $api_host ) {
				$hosts[] = $api_host;
			}
		}
		unset( $payment_url );

		return $hosts;
	}

	/**
	 * {@inheritDoc}
	 */
	public function start( WC_Order $order, array $part ) {
		$creds = $this->credentials();
		if ( ! $creds ) {
			return new WP_Error( 'chandpay_credentials', __( 'Provider credentials are missing.', 'chandpay-for-woocommerce' ) );
		}

		$cart = Chandpay_Cart::build( $order, (int) $part['amount'], $this->item_mutator() );
		$data = array(
			'amount'               => (int) $cart['amount'],
			'discountAmount'       => (int) $cart['discount'],
			'externalSourceAmount' => (int) $cart['external'],
			'paymentMethodTypeDto' => 'INSTALLMENT',
			'returnURL'            => $part['return_url'],
			'transactionId'        => $part['transaction_id'],
			'cartList'             => array( $cart['cart'] ),
		);

		$phone = Chandpay_Phone::international( $order->get_billing_phone() );
		if ( $this->mobile_required() && '' === $phone ) {
			return new WP_Error(
				'chandpay_phone',
				__( 'This provider needs a mobile number in 09xxxxxxxxx form.', 'chandpay-for-woocommerce' )
			);
		}
		if ( '' !== $phone ) {
			$data['mobile'] = $phone;
		}

		$data     = array_merge( $data, $this->token_extra( $order, $part, $cart ) );
		$response = $this->post( 'api/online/payment/v1/token', $data );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( empty( $response['successful'] ) || empty( $response['response']['paymentToken'] ) || empty( $response['response']['paymentPageUrl'] ) ) {
			return new WP_Error( 'chandpay_token', $this->message( $response, __( 'The provider did not start the payment.', 'chandpay-for-woocommerce' ) ) );
		}

		if ( isset( $response['response']['amount'] ) && (int) $response['response']['amount'] !== (int) $cart['amount'] ) {
			return new WP_Error(
				'chandpay_amount',
				__( 'The provider stored a different amount, so the payment was stopped.', 'chandpay-for-woocommerce' )
			);
		}

		$url = $response['response']['paymentPageUrl'];
		if ( ! Chandpay_Redirect::allowed( $url, $this->redirect_hosts_for( $url ) ) ) {
			return new WP_Error( 'chandpay_redirect', __( 'The provider returned an unexpected payment page.', 'chandpay-for-woocommerce' ) );
		}

		return array(
			'redirect'  => $url,
			'token'     => (string) $response['response']['paymentToken'],
			'reference' => (string) $response['response']['paymentToken'],
			'external'  => (int) $cart['external'],
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function parse_return( array $query, array $post ) {
		$state = isset( $post['state'] ) ? strtoupper( (string) $post['state'] ) : '';
		if ( 'OK' === $state ) {
			$outcome = 'ok';
		} elseif ( 'FAILED' === $state ) {
			$outcome = 'failed';
		} else {
			$outcome = 'incomplete';
		}

		return array(
			'outcome'        => $outcome,
			'amount'         => isset( $post['amount'] ) && is_numeric( $post['amount'] ) ? (int) $post['amount'] : null,
			'transaction_id' => isset( $post['transactionId'] ) ? (string) $post['transactionId'] : '',
			'tracking_code'  => '',
			'provider_type'  => '',
			'message'        => 'failed' === $outcome ? __( 'The provider reported that this payment failed.', 'chandpay-for-woocommerce' ) : '',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function verify( array $part ) {
		$response = $this->post( 'api/online/payment/v1/verify', array( 'paymentToken' => $part['token'] ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! empty( $response['successful'] ) ) {
			return array(
				'ok'             => true,
				'pending'        => false,
				'message'        => '',
				'tracking_code'  => isset( $response['response']['transactionId'] ) ? (string) $response['response']['transactionId'] : '',
				'provider_type'  => '',
			);
		}

		$status = $this->remote_status( $part );
		if ( in_array( $status, array( 'VERIFY', 'SETTLE' ), true ) ) {
			return array(
				'ok'            => true,
				'pending'       => false,
				'message'       => '',
				'tracking_code' => '',
				'provider_type' => '',
				'already'       => $status,
			);
		}

		return new WP_Error( 'chandpay_verify', $this->message( $response, __( 'The provider did not verify this payment.', 'chandpay-for-woocommerce' ) ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function settle( array $part ) {
		$status = $this->remote_status( $part );
		if ( 'SETTLE' === $status ) {
			return array( 'ok' => true, 'message' => '' );
		}

		$response = $this->post( 'api/online/payment/v1/settle', array( 'paymentToken' => $part['token'] ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! empty( $response['successful'] ) ) {
			return array( 'ok' => true, 'message' => '' );
		}

		$status = $this->remote_status( $part );
		if ( 'SETTLE' === $status ) {
			return array( 'ok' => true, 'message' => '' );
		}

		return new WP_Error( 'chandpay_settle', $this->message( $response, __( 'The provider did not settle this payment.', 'chandpay-for-woocommerce' ) ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function void( array $part ) {
		$status = $this->remote_status( $part );
		if ( in_array( $status, array( 'REVERT', 'CANCEL' ), true ) ) {
			return array( 'ok' => true, 'message' => '' );
		}

		if ( 'SETTLE' === $status || 'settled' === $part['status'] ) {
			return $this->cancel( $part );
		}

		$reverted = $this->post( 'api/online/payment/v1/revert', array( 'paymentToken' => $part['token'] ) );
		if ( ! is_wp_error( $reverted ) && ! empty( $reverted['successful'] ) ) {
			return array( 'ok' => true, 'message' => '' );
		}

		return $this->cancel( $part );
	}

	/**
	 * {@inheritDoc}
	 */
	public function deliver( WC_Order $order, array $part ) {
		unset( $order, $part );

		return array( 'ok' => true, 'message' => '' );
	}

	/**
	 * Best-effort revert after a failed callback. Errors are ignored.
	 *
	 * @param array $part Part.
	 * @return void
	 */
	public function revert_failed( array $part ) {
		if ( empty( $part['token'] ) ) {
			return;
		}
		$this->post( 'api/online/payment/v1/revert', array( 'paymentToken' => $part['token'] ) );
	}

	/**
	 * Mobile is required on the token.
	 *
	 * @return bool
	 */
	protected function mobile_required() {
		return false;
	}

	/**
	 * Cancel a settled payment.
	 *
	 * @param array $part Part.
	 * @return array|WP_Error
	 */
	protected function cancel( array $part ) {
		$response = $this->post( 'api/online/payment/v1/cancel', array( 'paymentToken' => $part['token'] ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! empty( $response['successful'] ) ) {
			return array( 'ok' => true, 'message' => '' );
		}

		$status = $this->remote_status( $part );
		if ( in_array( $status, array( 'REVERT', 'CANCEL' ), true ) ) {
			return array( 'ok' => true, 'message' => '' );
		}

		return new WP_Error( 'chandpay_cancel', $this->message( $response, __( 'The provider did not void this payment.', 'chandpay-for-woocommerce' ) ) );
	}

	/**
	 * Normalized remote status, or an empty string.
	 *
	 * @param array $part Part.
	 * @return string
	 */
	protected function remote_status( array $part ) {
		if ( empty( $part['token'] ) ) {
			return '';
		}
		$creds = $this->credentials();
		if ( ! $creds ) {
			return '';
		}
		$url      = add_query_arg(
			array( 'paymentToken' => $part['token'] ),
			Chandpay_Http::url( $creds['base_url'], 'api/online/payment/v1/status' )
		);
		$response = $this->call( 'GET', $url, null );
		if ( is_wp_error( $response ) || empty( $response['successful'] ) ) {
			return '';
		}
		if ( isset( $response['response']['status'] ) ) {
			return strtoupper( (string) $response['response']['status'] );
		}

		return '';
	}

	/**
	 * Authenticated POST of a JSON body.
	 *
	 * @param string $path Path under the API base.
	 * @param array  $body JSON body.
	 * @return array|WP_Error
	 */
	protected function post( $path, array $body ) {
		$creds = $this->credentials();
		if ( ! $creds ) {
			return new WP_Error( 'chandpay_credentials', __( 'Provider credentials are missing.', 'chandpay-for-woocommerce' ) );
		}

		return $this->call( 'POST', Chandpay_Http::url( $creds['base_url'], $path ), wp_json_encode( $body ) );
	}

	/**
	 * Call the API, refreshing the bearer token once after HTTP 401.
	 *
	 * @param string      $method HTTP method.
	 * @param string      $url    Absolute URL.
	 * @param string|null $json   JSON body.
	 * @return array|WP_Error
	 */
	protected function call( $method, $url, $json ) {
		$headers = $this->bearer_headers();
		if ( is_wp_error( $headers ) ) {
			return $headers;
		}

		$response = Chandpay_Http::request( $url, $method, $headers, $json );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( isset( $response['_http_status'] ) && 401 === (int) $response['_http_status'] ) {
			$this->forget_token();
			$headers = $this->bearer_headers();
			if ( is_wp_error( $headers ) ) {
				return $headers;
			}
			$response = Chandpay_Http::request( $url, $method, $headers, $json );
		}

		return $response;
	}

	/**
	 * Bearer headers, from cache or a new token.
	 *
	 * @return array|WP_Error
	 */
	protected function bearer_headers() {
		$creds = $this->credentials();
		if ( ! $creds ) {
			return new WP_Error( 'chandpay_credentials', __( 'Provider credentials are missing.', 'chandpay-for-woocommerce' ) );
		}

		$cached = get_transient( $this->token_key() );
		if ( is_string( $cached ) && '' !== $cached ) {
			return array(
				'Authorization' => 'Bearer ' . $cached,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			);
		}

		$response = Chandpay_Http::request(
			Chandpay_Http::url( $creds['base_url'], 'api/online/v1/oauth/token' ),
			'POST',
			array(
				'Authorization' => 'Basic ' . base64_encode( $creds['client_id'] . ':' . $creds['client_secret'] ),
				'Content-Type'  => 'application/x-www-form-urlencoded',
				'Accept'        => 'application/json',
			),
			array(
				'grant_type' => 'password',
				'scope'      => 'online-merchant',
				'username'   => $creds['client_username'],
				'password'   => $creds['client_password'],
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( empty( $response['access_token'] ) ) {
			return new WP_Error( 'chandpay_auth', $this->message( $response, __( 'The provider rejected the merchant login.', 'chandpay-for-woocommerce' ) ) );
		}

		$ttl = isset( $response['expires_in'] ) ? (int) $response['expires_in'] : 3300;
		$ttl = max( 60, $ttl - 30 );
		set_transient( $this->token_key(), $response['access_token'], $ttl );

		return array(
			'Authorization' => 'Bearer ' . $response['access_token'],
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
		);
	}

	/**
	 * Drop the cached access token.
	 *
	 * @return void
	 */
	protected function forget_token() {
		delete_transient( $this->token_key() );
	}

	/**
	 * Transient name for this merchant's access token.
	 *
	 * @return string
	 */
	protected function token_key() {
		$creds = $this->credentials();
		$id    = $creds ? $creds['client_id'] : '';

		return 'chandpay_bearer_' . md5( $this->id() . '|' . $id );
	}

	/**
	 * A shopper-safe sentence from an error envelope.
	 *
	 * @param array  $response API response.
	 * @param string $fallback Fallback sentence.
	 * @return string
	 */
	protected function message( array $response, $fallback ) {
		$candidates = array();
		if ( isset( $response['errorData']['message'] ) ) {
			$candidates[] = $response['errorData']['message'];
		}
		if ( isset( $response['error']['user_message'] ) ) {
			$candidates[] = $response['error']['user_message'];
		}
		if ( isset( $response['error']['message'] ) ) {
			$candidates[] = $response['error']['message'];
		}
		foreach ( $candidates as $text ) {
			$text = trim( wp_strip_all_tags( (string) $text ) );
			if ( '' !== $text ) {
				return $text;
			}
		}

		return $fallback;
	}
}
