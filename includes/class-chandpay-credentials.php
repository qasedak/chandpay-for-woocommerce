<?php
/**
 * Credentials from the official plugin, then from Chandpay settings.
 *
 * @package Chandpay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read merchant credentials.
 */
class Chandpay_Credentials {

	/**
	 * TorobPay credentials. The official plugin wins when it has a full set.
	 * Otherwise the Chandpay settings fields are used.
	 *
	 * @return array|null
	 */
	public static function torobpay() {
		$official = self::cpg( 'woocommerce_WC_Gateway_TorobPay_settings', 'https://cpg.torobpay.com/' );
		if ( $official ) {
			return $official;
		}

		$settings = self::chandpay_settings();

		return self::finish_cpg(
			array(
				'client_id'       => isset( $settings['torobpay_client_id'] ) ? $settings['torobpay_client_id'] : '',
				'client_secret'   => isset( $settings['torobpay_client_secret'] ) ? $settings['torobpay_client_secret'] : '',
				'client_username' => isset( $settings['torobpay_client_username'] ) ? $settings['torobpay_client_username'] : '',
				'client_password' => isset( $settings['torobpay_client_password'] ) ? $settings['torobpay_client_password'] : '',
				'base_url'        => isset( $settings['torobpay_base_url'] ) ? $settings['torobpay_base_url'] : '',
			),
			'https://cpg.torobpay.com/'
		);
	}

	/**
	 * Fixed IP for the TorobPay API host, when DNS lookup times out.
	 *
	 * Chandpay's own field wins. The official plugin's override is the fallback.
	 *
	 * @return string
	 */
	public static function torobpay_dns_ip() {
		$settings = self::chandpay_settings();
		$own      = isset( $settings['torobpay_dns_ip'] ) ? trim( (string) $settings['torobpay_dns_ip'] ) : '';
		if ( filter_var( $own, FILTER_VALIDATE_IP ) ) {
			return $own;
		}

		$official = get_option( 'woocommerce_WC_Gateway_TorobPay_settings', array() );
		if ( ! is_array( $official ) ) {
			return '';
		}
		$ip = isset( $official['dns_ip_override'] ) ? trim( (string) $official['dns_ip_override'] ) : '';

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * SnappPay credentials.
	 *
	 * @return array|null
	 */
	public static function snapppay() {
		$official = self::cpg( 'woocommerce_WC_Gateway_SnappPay_settings', '' );
		if ( $official ) {
			return $official;
		}

		$settings = self::chandpay_settings();
		$pack     = array(
			'client_id'       => isset( $settings['snapppay_client_id'] ) ? $settings['snapppay_client_id'] : '',
			'client_secret'   => isset( $settings['snapppay_client_secret'] ) ? $settings['snapppay_client_secret'] : '',
			'client_username' => isset( $settings['snapppay_client_username'] ) ? $settings['snapppay_client_username'] : '',
			'client_password' => isset( $settings['snapppay_client_password'] ) ? $settings['snapppay_client_password'] : '',
			'base_url'        => isset( $settings['snapppay_base_url'] ) ? $settings['snapppay_base_url'] : '',
		);

		return self::finish_cpg( $pack, '' );
	}

	/**
	 * DigiPay credentials and the option that should store rotated tokens.
	 *
	 * @return array|null
	 */
	public static function digipay() {
		$official = get_option( 'woocommerce_WCDigiPay_settings', array() );
		if ( self::digipay_complete( $official ) ) {
			return self::finish_digipay( $official, 'woocommerce_WCDigiPay_settings' );
		}

		$settings = self::chandpay_settings();
		$pack     = array(
			'username'      => isset( $settings['digipay_username'] ) ? $settings['digipay_username'] : '',
			'password'      => isset( $settings['digipay_password'] ) ? $settings['digipay_password'] : '',
			'client_id'     => isset( $settings['digipay_client_id'] ) ? $settings['digipay_client_id'] : '',
			'client_secret' => isset( $settings['digipay_client_secret'] ) ? $settings['digipay_client_secret'] : '',
		);
		if ( ! self::digipay_complete( $pack ) ) {
			return null;
		}

		$tokens                 = get_option( 'chandpay_digipay_oauth', array() );
		$pack['access_token']   = is_array( $tokens ) && isset( $tokens['access_token'] ) ? $tokens['access_token'] : '';
		$pack['refresh_token']  = is_array( $tokens ) && isset( $tokens['refresh_token'] ) ? $tokens['refresh_token'] : '';
		$pack['token_store']    = 'chandpay_digipay_oauth';

		return self::clean_digipay( $pack );
	}

	/**
	 * Persist rotated DigiPay tokens back to the store that supplied them.
	 *
	 * @param array  $credentials Credentials from digipay().
	 * @param string $access      Access token.
	 * @param string $refresh     Refresh token.
	 * @return void
	 */
	public static function save_digipay_tokens( array $credentials, $access, $refresh ) {
		$store = isset( $credentials['token_store'] ) ? $credentials['token_store'] : '';
		if ( 'chandpay_digipay_oauth' === $store ) {
			update_option(
				'chandpay_digipay_oauth',
				array(
					'access_token'  => $access,
					'refresh_token' => $refresh,
				),
				false
			);
			return;
		}

		if ( 'woocommerce_WCDigiPay_settings' !== $store ) {
			return;
		}

		$settings = get_option( $store, array() );
		if ( ! is_array( $settings ) ) {
			return;
		}
		$settings['access_token']  = $access;
		$settings['refresh_token'] = $refresh;
		update_option( $store, $settings, false );
	}

	/**
	 * A provider checkbox on the Chandpay gateway. Missing settings mean enabled.
	 *
	 * @param string $provider Provider id.
	 * @return bool
	 */
	public static function provider_enabled( $provider ) {
		$settings = self::chandpay_settings();
		$key      = 'provider_' . $provider;
		if ( ! array_key_exists( $key, $settings ) ) {
			return true;
		}

		return 'yes' === $settings[ $key ];
	}

	/**
	 * Chandpay gateway settings.
	 *
	 * @return array
	 */
	private static function chandpay_settings() {
		$settings = get_option( 'woocommerce_chandpay_settings', array() );

		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Read a CPG-shaped settings option.
	 *
	 * @param string $option_key   Option name.
	 * @param string $default_base Fallback base URL.
	 * @return array|null
	 */
	private static function cpg( $option_key, $default_base ) {
		$settings = get_option( $option_key, array() );
		if ( ! is_array( $settings ) ) {
			return null;
		}

		return self::finish_cpg( $settings, $default_base );
	}

	/**
	 * Normalize a CPG credential pack.
	 *
	 * @param array  $settings     Raw settings.
	 * @param string $default_base Fallback base URL.
	 * @return array|null
	 */
	private static function finish_cpg( array $settings, $default_base ) {
		$pack = array(
			'client_id'       => self::secret( isset( $settings['client_id'] ) ? $settings['client_id'] : '' ),
			'client_secret'   => self::secret( isset( $settings['client_secret'] ) ? $settings['client_secret'] : '' ),
			'client_username' => self::secret( isset( $settings['client_username'] ) ? $settings['client_username'] : '' ),
			'client_password' => self::secret( isset( $settings['client_password'] ) ? $settings['client_password'] : '' ),
			'base_url'        => isset( $settings['base_url'] ) ? trim( (string) $settings['base_url'] ) : '',
		);

		if ( '' === $pack['base_url'] ) {
			$pack['base_url'] = $default_base;
		}

		foreach ( array( 'client_id', 'client_secret', 'client_username', 'client_password', 'base_url' ) as $key ) {
			if ( '' === $pack[ $key ] ) {
				return null;
			}
		}

		return $pack;
	}

	/**
	 * Whether DigiPay username, password, and client keys are present.
	 *
	 * @param array $settings Settings.
	 * @return bool
	 */
	private static function digipay_complete( $settings ) {
		if ( ! is_array( $settings ) ) {
			return false;
		}
		foreach ( array( 'username', 'password', 'client_id', 'client_secret' ) as $key ) {
			if ( empty( $settings[ $key ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Normalize official DigiPay settings.
	 *
	 * @param array  $settings Raw settings.
	 * @param string $store    Option name for tokens.
	 * @return array
	 */
	private static function finish_digipay( array $settings, $store ) {
		$settings['token_store'] = $store;

		return self::clean_digipay( $settings );
	}

	/**
	 * Decode secrets that WooCommerce stored as HTML entities.
	 *
	 * @param array $pack Credential pack.
	 * @return array
	 */
	private static function clean_digipay( array $pack ) {
		foreach ( array( 'username', 'password', 'client_id', 'client_secret', 'access_token', 'refresh_token' ) as $key ) {
			if ( isset( $pack[ $key ] ) ) {
				$pack[ $key ] = self::secret( $pack[ $key ] );
			}
		}
		$pack['base_url'] = 'https://api.mydigipay.com/digipay/api/';

		return $pack;
	}

	/**
	 * Undo HTML encoding of a stored secret.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function secret( $value ) {
		$value = html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' );

		return str_replace( 'amp;', '', $value );
	}
}
