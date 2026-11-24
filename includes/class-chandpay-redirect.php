<?php
/**
 * Allow only provider payment-page hosts.
 *
 * @package Chandpay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Redirect host check.
 */
class Chandpay_Redirect {

	/**
	 * Whether a URL is an https address on an allowed host.
	 *
	 * @param string $url   Absolute URL.
	 * @param array  $hosts Host suffixes and exact hosts.
	 * @return bool
	 */
	public static function allowed( $url, array $hosts ) {
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$host   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( 'https' !== $scheme || '' === $host ) {
			return false;
		}

		foreach ( $hosts as $allowed ) {
			$allowed = strtolower( (string) $allowed );
			if ( '' === $allowed ) {
				continue;
			}
			if ( $host === $allowed ) {
				return true;
			}
			$suffix = '.' . ltrim( $allowed, '.' );
			$len    = strlen( $suffix );
			if ( strlen( $host ) > $len && substr( $host, -$len ) === $suffix ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Allow a checked provider URL through wp_safe_redirect().
	 *
	 * @param string $url   Destination.
	 * @param array  $hosts Allowed hosts.
	 * @return bool False when the host is rejected.
	 */
	public static function permit( $url, array $hosts ) {
		if ( ! self::allowed( $url, $hosts ) ) {
			return false;
		}

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		add_filter(
			'allowed_redirect_hosts',
			static function ( $list ) use ( $host ) {
				$list[] = $host;
				return $list;
			}
		);

		return true;
	}

	/**
	 * Redirect to a provider after the host check.
	 *
	 * @param string $url   Destination.
	 * @param array  $hosts Allowed hosts.
	 * @return bool False when the host is rejected.
	 */
	public static function away( $url, array $hosts ) {
		if ( ! self::permit( $url, $hosts ) ) {
			return false;
		}

		wp_safe_redirect( $url );
		exit;
	}
}
