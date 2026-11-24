<?php
/**
 * SnappPay adapter.
 *
 * externalSourceAmount is required. The final amount is
 * cart total - discount - external source. Settle is a separate call.
 * A FAILED callback is followed by revert. Revert does not exist after settle;
 * void then uses cancel.
 *
 * @package Chandpay
 */

defined( 'ABSPATH' ) || exit;

/**
 * SnappPay partial payments.
 */
class Chandpay_Provider_Snapppay extends Chandpay_Provider_Cpg {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'snapppay';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return 'اسنپ‌پی';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function credentials() {
		return Chandpay_Credentials::snapppay();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function redirect_hosts() {
		return array( 'snapppay.ir', 'snapppay.com' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function min_rial() {
		return 0;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function auto_settle() {
		return false;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function revert_on_failed() {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function mobile_required() {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function item_mutator() {
		return static function ( array $row ) {
			$row['commissionType'] = 100;
			return $row;
		};
	}

	/**
	 * {@inheritDoc}
	 */
	protected function token_extra( WC_Order $order, array $part, array $cart ) {
		unset( $order, $part, $cart );

		return array();
	}
}
