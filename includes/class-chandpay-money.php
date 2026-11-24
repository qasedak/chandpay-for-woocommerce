<?php
/**
 * Store-currency amounts to Iranian rial.
 *
 * @package Chandpay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Currency conversion used by every provider.
 */
class Chandpay_Money {

	/**
	 * Currencies this plugin can send to installment providers.
	 *
	 * @param string $currency Store currency code.
	 * @return bool
	 */
	public static function supports( $currency ) {
		$key = strtolower( trim( (string) $currency ) );

		$known = array(
			'irr',
			'irt',
			'irht',
			'irhr',
			'toman',
			'iran toman',
			'iranian toman',
			'iran-toman',
			'iranian-toman',
			'iran_toman',
			'iranian_toman',
			'تومان',
			'تومان ایران',
		);

		return in_array( $key, $known, true );
	}

	/**
	 * Multiplier from store currency to rial.
	 *
	 * @param string $currency Store currency code.
	 * @return int
	 */
	public static function factor( $currency ) {
		$key = strtolower( trim( (string) $currency ) );

		$toman = array(
			'irt',
			'toman',
			'iran toman',
			'iranian toman',
			'iran-toman',
			'iranian-toman',
			'iran_toman',
			'iranian_toman',
			'تومان',
			'تومان ایران',
		);

		if ( in_array( $key, $toman, true ) ) {
			return 10;
		}
		if ( 'irht' === $key ) {
			return 10000;
		}
		if ( 'irhr' === $key ) {
			return 1000;
		}

		return 1;
	}

	/**
	 * Convert Persian and Arabic digits to ASCII.
	 *
	 * @param string $value Raw text.
	 * @return string
	 */
	public static function digits( $value ) {
		$persian = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );
		$arabic  = array( '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' );
		$latin   = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );
		$value   = str_replace( $persian, $latin, (string) $value );

		return str_replace( $arabic, $latin, $value );
	}

	/**
	 * Parse a shopper-entered amount in store currency.
	 *
	 * @param string $raw Posted amount.
	 * @return float|null
	 */
	public static function parse_amount( $raw ) {
		$raw = self::digits( $raw );
		$raw = str_replace( array( ',', '٬', ' ', '٫' ), array( '', '', '', '.' ), $raw );
		if ( '' === $raw || ! is_numeric( $raw ) ) {
			return null;
		}
		$amount = (float) $raw;
		if ( $amount <= 0 ) {
			return null;
		}

		return $amount;
	}

	/**
	 * Convert a store-currency amount to rial, rounding up.
	 *
	 * @param float  $amount   Store amount.
	 * @param string $currency Store currency.
	 * @return int
	 */
	public static function to_rial( $amount, $currency ) {
		return (int) ceil( ( (float) $amount ) * self::factor( $currency ) );
	}

	/**
	 * Rial amount the provider should charge for what the shopper typed.
	 *
	 * When the typed value is the remaining total as shown with store decimals,
	 * the charge is the exact remaining rial so the last part can close the order.
	 *
	 * @param float  $entered_store   Amount typed in store currency.
	 * @param int    $remaining_rial  Remaining rial.
	 * @param string $currency        Store currency.
	 * @param int    $decimals        Store price decimals.
	 * @return int
	 */
	public static function charge_rial( $entered_store, $remaining_rial, $currency, $decimals ) {
		$factor   = self::factor( $currency );
		$decimals = max( 0, (int) $decimals );
		$shown    = number_format( ( (int) $remaining_rial ) / $factor, $decimals, '.', '' );
		$entered  = number_format( (float) $entered_store, $decimals, '.', '' );

		if ( $shown === $entered ) {
			return (int) $remaining_rial;
		}

		$rial = self::to_rial( $entered_store, $currency );
		if ( $rial > $remaining_rial && ( $rial - $remaining_rial ) < $factor ) {
			return (int) $remaining_rial;
		}

		return $rial;
	}

	/**
	 * Store-currency number for a rial amount, unformatted.
	 *
	 * @param int    $rial     Rial.
	 * @param string $currency Store currency.
	 * @param int    $decimals Price decimals.
	 * @return string
	 */
	public static function store_string( $rial, $currency, $decimals ) {
		$decimals = max( 0, (int) $decimals );

		return number_format( ( (int) $rial ) / self::factor( $currency ), $decimals, '.', '' );
	}
}
