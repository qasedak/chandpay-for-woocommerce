<?php
/**
 * Provider lookup.
 *
 * @package Chandpay
 */

defined( 'ABSPATH' ) || exit;

/**
 * The installment providers Chandpay can run.
 */
class Chandpay_Registry {

	/**
	 * Every adapter.
	 *
	 * @return Chandpay_Provider[]
	 */
	public static function all() {
		return array(
			new Chandpay_Provider_Torobpay(),
			new Chandpay_Provider_Snapppay(),
			new Chandpay_Provider_Digipay(),
		);
	}

	/**
	 * Adapters the shopper can use right now.
	 *
	 * @return Chandpay_Provider[]
	 */
	public static function available() {
		$providers = array();
		foreach ( self::all() as $provider ) {
			if ( $provider->is_available() ) {
				$providers[] = $provider;
			}
		}

		return $providers;
	}

	/**
	 * One adapter by id.
	 *
	 * @param string $id Provider id.
	 * @return Chandpay_Provider|null
	 */
	public static function get( $id ) {
		foreach ( self::all() as $provider ) {
			if ( $provider->id() === $id ) {
				return $provider;
			}
		}

		return null;
	}

	/**
	 * At least one provider can take a payment.
	 *
	 * @return bool
	 */
	public static function has_available() {
		return (bool) self::available();
	}
}
