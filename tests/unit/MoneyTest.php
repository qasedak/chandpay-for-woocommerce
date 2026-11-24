<?php
/**
 * Currency conversion tests.
 *
 * @package Chandpay
 */

defined( 'ABSPATH' ) || exit;

use PHPUnit\Framework\TestCase;

/**
 * Rial conversion.
 */
class MoneyTest extends TestCase {

	/**
	 * Toman and rial factors.
	 */
	public function test_factors() {
		$this->assertSame( 10, Chandpay_Money::factor( 'IRT' ) );
		$this->assertSame( 1, Chandpay_Money::factor( 'IRR' ) );
		$this->assertSame( 10000, Chandpay_Money::factor( 'IRHT' ) );
		$this->assertSame( 1000, Chandpay_Money::factor( 'IRHR' ) );
		$this->assertTrue( Chandpay_Money::supports( 'تومان' ) );
		$this->assertFalse( Chandpay_Money::supports( 'USD' ) );
	}

	/**
	 * Rounding up avoids under-charging a fractional toman.
	 */
	public function test_ceil_to_rial() {
		$this->assertSame( 100001, Chandpay_Money::to_rial( 10000.01, 'IRT' ) );
	}

	/**
	 * Typing the displayed remainder closes the order on the exact rial.
	 */
	public function test_displayed_remainder_snaps_to_exact_rial() {
		$this->assertSame( 100001, Chandpay_Money::charge_rial( 10000, 100001, 'IRT', 0 ) );
		$this->assertSame( 500000, Chandpay_Money::charge_rial( 50000, 1000010, 'IRT', 0 ) );
	}

	/**
	 * Persian digits are accepted.
	 */
	public function test_persian_digits() {
		$this->assertSame( 25000.0, Chandpay_Money::parse_amount( '۲۵٬۰۰۰' ) );
	}
}
