<?php
/**
 * JSON and form requests to installment APIs.
 *
 * @package Chandpay
 */

defined( 'ABSPATH' ) || exit;

/**
 * wp_remote_request wrapper.
 */
class Chandpay_Http {

	/**
	 * Perform a request and decode a JSON object.
	 *
	 * @param string     $url     Absolute URL.
	 * @param string     $method  GET or POST.
	 * @param array      $headers Headers.
	 * @param array|string|null $body Body. Arrays are form-encoded.
	 * @param int        $timeout Seconds.
	 * @return array|WP_Error
	 */
	public static function request( $url, $method, array $headers, $body = null, $timeout = 20 ) {
		$args = array(
			'method'      => $method,
			'timeout'     => max( 1, (int) $timeout ),
			'headers'     => $headers,
			'redirection' => 0,
		);

		if ( null !== $body && 'GET' !== strtoupper( $method ) ) {
			$args['body'] = $body;
		}

		$resolve = self::resolve_entries( $url, self::torobpay_api_host(), self::torobpay_dns_ip() );
		if ( $resolve && function_exists( 'add_filter' ) ) {
			$apply = static function ( $handle ) use ( $resolve ) {
				if ( function_exists( 'curl_setopt' ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- CURLOPT_RESOLVE has no WordPress wrapper.
					curl_setopt( $handle, CURLOPT_RESOLVE, $resolve );
				}
				return $handle;
			};
			add_filter( 'http_api_curl', $apply, 20, 1 );
		}

		$response = wp_remote_request( $url, $args );

		if ( isset( $apply ) ) {
			remove_filter( 'http_api_curl', $apply, 20 );
		}
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'chandpay_bad_response',
				__( 'The payment provider returned an unreadable response.', 'chandpay-for-woocommerce' ),
				array( 'status' => $code )
			);
		}

		$data['_http_status'] = $code;

		return $data;
	}

	/**
	 * Join a base URL and a path.
	 *
	 * @param string $base Base URL.
	 * @param string $path Path without a leading slash.
	 * @return string
	 */
	public static function url( $base, $path ) {
		return trailingslashit( $base ) . ltrim( $path, '/' );
	}

	/**
	 * cURL resolve lines for one host, or an empty list.
	 *
	 * @param string $url      Request URL.
	 * @param string $api_host Host that should use the fixed IP.
	 * @param string $ip       IPv4 or IPv6 address.
	 * @return array
	 */
	public static function resolve_entries( $url, $api_host, $ip ) {
		$host = parse_url( (string) $url, PHP_URL_HOST );
		$ip   = trim( (string) $ip );
		if ( ! is_string( $host ) || '' === $host || '' === $api_host || strtolower( $host ) !== strtolower( $api_host ) ) {
			return array();
		}
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return array();
		}

		return array(
			$host . ':443:' . $ip,
			$host . ':80:' . $ip,
		);
	}

	/**
	 * TorobPay API host from saved credentials.
	 *
	 * @return string
	 */
	private static function torobpay_api_host() {
		if ( ! class_exists( 'Chandpay_Credentials' ) ) {
			return 'cpg.torobpay.com';
		}
		$creds = Chandpay_Credentials::torobpay();
		if ( ! is_array( $creds ) || empty( $creds['base_url'] ) ) {
			return 'cpg.torobpay.com';
		}
		$host = parse_url( $creds['base_url'], PHP_URL_HOST );

		return is_string( $host ) ? $host : '';
	}

	/**
	 * Fixed TorobPay IP, if one is saved.
	 *
	 * @return string
	 */
	private static function torobpay_dns_ip() {
		if ( ! class_exists( 'Chandpay_Credentials' ) ) {
			return '';
		}

		return Chandpay_Credentials::torobpay_dns_ip();
	}
}
