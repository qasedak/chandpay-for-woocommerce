<?php
/**
 * DigiPay UPG adapter.
 *
 * The ticket amount is this part. There is no external-source field.
 * Verify confirms the payment and must run quickly. Delivery is reported
 * when the WooCommerce order is completed. Void is a refund of the part.
 *
 * @package Chandpay
 */

defined( 'ABSPATH' ) || exit;

/**
 * DigiPay partial payments.
 */
class Chandpay_Provider_Digipay implements Chandpay_Provider {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'digipay';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return 'دیجی‌پی';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available() {
		return Chandpay_Credentials::provider_enabled( $this->id() ) && is_array( Chandpay_Credentials::digipay() );
	}

	/**
	 * {@inheritDoc}
	 */
	public function capabilities() {
		return array(
			'partial'              => true,
			'external_source'      => false,
			'verify'               => true,
			'settle'               => false,
			'auto_settle'          => false,
			'revert'               => false,
			'cancel'               => false,
			'refund'               => true,
			'revert_on_failed'     => false,
			'min_rial'             => 0,
			'reservation_ttl'      => 20 * MINUTE_IN_SECONDS,
			'deliver_on_completed' => true,
		);
	}

	/**
	 * Hosts that may host the DigiPay payment page.
	 *
	 * @return array
	 */
	public function redirect_hosts_for( $payment_url ) {
		unset( $payment_url );

		return array( 'mydigipay.com', 'mydigipay.info' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function start( WC_Order $order, array $part ) {
		$mobile = Chandpay_Phone::local( $order->get_billing_phone() );
		if ( '' === $mobile ) {
			return new WP_Error(
				'chandpay_phone',
				__( 'DigiPay needs a mobile number in 09xxxxxxxxx form.', 'chandpay-for-woocommerce' )
			);
		}

		$provider_id = $this->provider_id( $order, $part );
		$body        = array(
			'amount'           => (int) $part['amount'],
			'cellNumber'       => $mobile,
			'providerId'       => $provider_id,
			'callbackUrl'      => $part['return_url'],
			'basketDetailsDto' => array(
				'basketId' => substr( 'b' . $order->get_id() . $part['id'], 0, 30 ),
				'items'    => Chandpay_Cart::digipay_items( $order ),
			),
		);

		$response = $this->post( 'tickets/business?type=11', $body, true );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = isset( $response['result']['status'] ) ? (int) $response['result']['status'] : -1;
		if ( 0 !== $status || empty( $response['redirectUrl'] ) ) {
			return new WP_Error( 'chandpay_token', $this->message( $response, __( 'DigiPay did not start the payment.', 'chandpay-for-woocommerce' ) ) );
		}

		$url = $response['redirectUrl'];
		if ( ! Chandpay_Redirect::allowed( $url, $this->redirect_hosts_for( $url ) ) ) {
			return new WP_Error( 'chandpay_redirect', __( 'DigiPay returned an unexpected payment page.', 'chandpay-for-woocommerce' ) );
		}

		return array(
			'redirect'  => $url,
			'token'     => isset( $response['ticket'] ) ? (string) $response['ticket'] : '',
			'reference' => $provider_id,
			'external'  => 0,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function parse_return( array $query, array $post ) {
		unset( $query );
		$result = isset( $post['result'] ) ? strtoupper( (string) $post['result'] ) : '';
		if ( 'SUCCESS' === $result ) {
			$outcome = 'ok';
		} elseif ( 'FAILURE' === $result ) {
			$outcome = 'failed';
		} else {
			$outcome = 'incomplete';
		}

		return array(
			'outcome'        => $outcome,
			'amount'         => isset( $post['amount'] ) && is_numeric( $post['amount'] ) ? (int) $post['amount'] : null,
			'transaction_id' => '',
			'tracking_code'  => isset( $post['trackingCode'] ) ? (string) $post['trackingCode'] : '',
			'provider_type'  => isset( $post['type'] ) ? (string) $post['type'] : '',
			'provider_id'    => isset( $post['providerId'] ) ? (string) $post['providerId'] : '',
			'message'        => 'failed' === $outcome ? __( 'DigiPay reported that this payment failed.', 'chandpay-for-woocommerce' ) : '',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function verify( array $part ) {
		if ( '' === (string) $part['tracking_code'] || '' === (string) $part['provider_type'] ) {
			return new WP_Error( 'chandpay_verify', __( 'DigiPay did not include a tracking code.', 'chandpay-for-woocommerce' ) );
		}

		$response = $this->post(
			'purchases/verify?type=' . rawurlencode( (string) $part['provider_type'] ),
			array(
				'trackingCode' => $part['tracking_code'],
				'providerId'   => $part['reference'],
			),
			true
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = isset( $response['result']['status'] ) ? (int) $response['result']['status'] : -1;
		if ( 9011 === $status ) {
			return array(
				'ok'            => false,
				'pending'       => true,
				'message'       => __( 'DigiPay is still confirming this payment. Refresh this page in a moment.', 'chandpay-for-woocommerce' ),
				'tracking_code' => (string) $part['tracking_code'],
				'provider_type' => (string) $part['provider_type'],
			);
		}
		if ( 0 !== $status ) {
			return new WP_Error( 'chandpay_verify', $this->message( $response, __( 'DigiPay did not verify this payment.', 'chandpay-for-woocommerce' ) ) );
		}

		return array(
			'ok'            => true,
			'pending'       => false,
			'message'       => '',
			'tracking_code' => isset( $response['trackingCode'] ) ? (string) $response['trackingCode'] : (string) $part['tracking_code'],
			'provider_type' => (string) $part['provider_type'],
		);
	}

	/**
	 * Verify already captured the money. Settle is a local step.
	 *
	 * {@inheritDoc}
	 */
	public function settle( array $part ) {
		unset( $part );

		return array( 'ok' => true, 'message' => '' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function void( array $part ) {
		if ( '' === (string) $part['tracking_code'] ) {
			return new WP_Error( 'chandpay_refund', __( 'This DigiPay part has no tracking code to refund.', 'chandpay-for-woocommerce' ) );
		}

		$type = (string) $part['provider_type'];
		if ( '' === $type ) {
			return new WP_Error( 'chandpay_refund', __( 'This DigiPay part has no payment type to refund.', 'chandpay-for-woocommerce' ) );
		}

		$refund_id = isset( $part['refund_reference'] ) ? (string) $part['refund_reference'] : '';
		if ( '' === $refund_id ) {
			return new WP_Error( 'chandpay_refund', __( 'A refund id was not reserved for this DigiPay part.', 'chandpay-for-woocommerce' ) );
		}

		$response = $this->post(
			'refunds?type=' . rawurlencode( $type ),
			array(
				'providerId'       => $refund_id,
				'amount'           => (int) $part['amount'],
				'saleTrackingCode' => (string) $part['tracking_code'],
				'description'      => 'Chandpay refund',
			),
			true
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = isset( $response['result']['status'] ) ? (int) $response['result']['status'] : -1;
		if ( 0 !== $status ) {
			return new WP_Error( 'chandpay_refund', $this->message( $response, __( 'DigiPay did not accept the refund.', 'chandpay-for-woocommerce' ) ) );
		}

		return array(
			'ok'        => true,
			'message'   => '',
			'operation' => 'refund',
			'refund'    => isset( $response['trackingCode'] ) ? (string) $response['trackingCode'] : '',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function deliver( WC_Order $order, array $part ) {
		$type = (string) $part['provider_type'];
		if ( ! in_array( $type, array( '5', '13' ), true ) ) {
			return array( 'ok' => true, 'message' => '' );
		}
		if ( '' === (string) $part['tracking_code'] ) {
			return new WP_Error( 'chandpay_deliver', __( 'DigiPay delivery needs a tracking code.', 'chandpay-for-woocommerce' ) );
		}

		$products = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$name = $item->get_name();
			if ( $name ) {
				$products[] = $name;
			}
		}
		if ( ! $products ) {
			$products[] = 'Order ' . $order->get_order_number();
		}

		$response = $this->post(
			'purchases/deliver?type=' . rawurlencode( $type ),
			array(
				'deliveryDate'  => (int) round( microtime( true ) * 1000 ),
				'invoiceNumber' => (string) $order->get_order_number(),
				'trackingCode'  => (string) $part['tracking_code'],
				'products'      => $products,
			),
			true
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status = isset( $response['result']['status'] ) ? (int) $response['result']['status'] : -1;
		if ( 0 !== $status ) {
			return new WP_Error( 'chandpay_deliver', $this->message( $response, __( 'DigiPay did not record delivery.', 'chandpay-for-woocommerce' ) ) );
		}

		return array( 'ok' => true, 'message' => '' );
	}

	/**
	 * Merchant reference sent as providerId.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $part  Part.
	 * @return string
	 */
	private function provider_id( WC_Order $order, array $part ) {
		return substr( 'cp' . $order->get_id() . $part['id'], 0, 100 );
	}

	/**
	 * JSON POST with one authentication retry.
	 *
	 * @param string $path Path under the DigiPay API base.
	 * @param array  $body JSON body.
	 * @param bool   $retry Retry once after a 401.
	 * @return array|WP_Error
	 */
	private function post( $path, array $body, $retry ) {
		$creds = Chandpay_Credentials::digipay();
		if ( ! $creds ) {
			return new WP_Error( 'chandpay_credentials', __( 'DigiPay credentials are missing.', 'chandpay-for-woocommerce' ) );
		}

		$token = $this->access_token( $creds, false );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = Chandpay_Http::request(
			Chandpay_Http::url( $creds['base_url'], $path ),
			'POST',
			array(
				'Authorization'  => 'Bearer ' . $token,
				'Content-Type'   => 'application/json; charset=UTF-8',
				'Accept'         => 'application/json',
				'Agent'          => 'WEB',
				'Digipay-Version' => '2022-02-02',
			),
			wp_json_encode( $body )
		);

		if ( ! is_wp_error( $response ) && $retry && isset( $response['_http_status'] ) && 401 === (int) $response['_http_status'] ) {
			$fresh = $this->access_token( $creds, true );
			if ( is_wp_error( $fresh ) ) {
				return $fresh;
			}

			return $this->post( $path, $body, false );
		}

		return $response;
	}

	/**
	 * Access token. A stored token is reused until a caller forces a new login.
	 * A refresh grant is tried only for that forced login, and a failed refresh
	 * continues with username and password.
	 *
	 * @param array $creds   Credentials.
	 * @param bool  $refresh Force a new login.
	 * @return string|WP_Error
	 */
	private function access_token( array $creds, $refresh ) {
		if ( ! $refresh && ! empty( $creds['access_token'] ) ) {
			return $creds['access_token'];
		}

		if ( $refresh && ! empty( $creds['refresh_token'] ) ) {
			$refreshed = $this->request_token(
				$creds,
				array(
					'refresh_token' => $creds['refresh_token'],
					'grant_type'    => 'refresh_token',
				)
			);
			if ( ! is_wp_error( $refreshed ) ) {
				return $refreshed;
			}
		}

		return $this->request_token(
			$creds,
			array(
				'username'   => $creds['username'],
				'password'   => $creds['password'],
				'grant_type' => 'password',
			)
		);
	}

	/**
	 * One DigiPay OAuth grant.
	 *
	 * @param array $creds Credentials, including the token store.
	 * @param array $body  Grant body.
	 * @return string|WP_Error
	 */
	private function request_token( array $creds, array $body ) {
		$response = Chandpay_Http::request(
			Chandpay_Http::url( $creds['base_url'], 'oauth/token' ),
			'POST',
			array(
				'Authorization' => 'Basic ' . base64_encode( $creds['client_id'] . ':' . $creds['client_secret'] ),
				'Content-Type'  => 'application/x-www-form-urlencoded',
				'Accept'        => 'application/json',
			),
			$body
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( empty( $response['access_token'] ) ) {
			return new WP_Error( 'chandpay_auth', $this->message( $response, __( 'DigiPay rejected the merchant login.', 'chandpay-for-woocommerce' ) ) );
		}

		$refresh_token = isset( $response['refresh_token'] ) ? (string) $response['refresh_token'] : '';
		Chandpay_Credentials::save_digipay_tokens( $creds, $response['access_token'], $refresh_token );

		return (string) $response['access_token'];
	}

	/**
	 * Shopper-safe error text.
	 *
	 * @param array  $response API response.
	 * @param string $fallback Fallback sentence.
	 * @return string
	 */
	private function message( array $response, $fallback ) {
		if ( isset( $response['result']['message'] ) ) {
			$text = trim( wp_strip_all_tags( (string) $response['result']['message'] ) );
			if ( '' !== $text ) {
				return $text;
			}
		}

		return $fallback;
	}
}
