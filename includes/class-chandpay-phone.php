<?php
/**
 * Iranian mobile numbers for provider payloads.
 *
 * @package Chandpay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Normalize a checkout phone.
 */
class Chandpay_Phone {

	/**
	 * +989xxxxxxxxx, or an empty string.
	 *
	 * @param string $raw Billing phone.
	 * @return string
	 */
	public static function international( $raw ) {
		$digits = self::digits( $raw );
		if ( '' === $digits ) {
			return '';
		}

		if ( preg_match( '/^09\d{9}$/', $digits ) ) {
			return '+98' . substr( $digits, 1 );
		}
		if ( preg_match( '/^9\d{9}$/', $digits ) ) {
			return '+98' . $digits;
		}
		if ( preg_match( '/^989\d{9}$/', $digits ) ) {
			return '+' . $digits;
		}
		if ( preg_match( '/^9809\d{9}$/', $digits ) ) {
			return '+98' . substr( $digits, 3 );
		}

		return '';
	}

	/**
	 * 09xxxxxxxxx, or an empty string.
	 *
	 * @param string $raw Billing phone.
	 * @return string
	 */
	public static function local( $raw ) {
		$international = self::international( $raw );
		if ( '' === $international ) {
			return '';
		}

		return '0' . substr( $international, 3 );
	}

	/**
	 * Keep digits and a leading plus, then drop the plus.
	 *
	 * @param string $raw Raw phone.
	 * @return string
	 */
	private static function digits( $raw ) {
		$raw = Chandpay_Money::digits( (string) $raw );
		$raw = preg_replace( '/[^\d+]/', '', $raw );
		if ( ! is_string( $raw ) ) {
			return '';
		}

		return ltrim( $raw, '+' );
	}
}
