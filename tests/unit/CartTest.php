<?php
/**
 * Cart equation tests.
 *
 * @package Chandpay
 */

defined( 'ABSPATH' ) || exit;

use PHPUnit\Framework\TestCase;

/**
 * amount = cart - discount - external.
 */
class CartTest extends TestCase {

	/**
	 * A partial part leaves the rest as the external source.
	 */
	public function test_slice_equation() {
		$solved = Chandpay_Cart::from_components( 1000000, 900000, 50000, 50000, 400000 );

		$this->assertSame( 400000, $solved['amount'] );
		$this->assertSame( 600000, $solved['external'] );
		$this->assertSame(
			$solved['amount'],
			$solved['cart_total'] - $solved['discount'] - $solved['external']
		);
	}

	/**
	 * Fees that make the order larger than the lines are added to shipping.
	 */
	public function test_gap_is_added_before_the_equation() {
		$solved = Chandpay_Cart::from_components( 1000000, 800000, 0, 0, 250000 );

		$this->assertSame( 1000000, $solved['cart_total'] );
		$this->assertSame( 0, $solved['discount'] );
		$this->assertSame( 750000, $solved['external'] );
		$this->assertSame( 250000, $solved['amount'] );
	}
}
