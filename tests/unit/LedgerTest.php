<?php
/**
 * Ledger, money, and cart equation tests.
 *
 * @package Chandpay
 */

defined( 'ABSPATH' ) || exit;

use PHPUnit\Framework\TestCase;

/**
 * Pure rules that do not need WooCommerce.
 */
class LedgerTest extends TestCase {

	/**
	 * Failed and expired parts do not hold money.
	 */
	public function test_remaining_ignores_failed_and_expired() {
		$state = Chandpay_Ledger::blank();
		$state['parts'][] = array( 'status' => 'failed', 'amount' => 400000 );
		$state['parts'][] = array( 'status' => 'expired', 'amount' => 100000 );
		$state['parts'][] = array( 'status' => 'verified', 'amount' => 250000 );

		$this->assertSame( 250000, Chandpay_Ledger::reserved( $state ) );
		$this->assertSame( 750000, Chandpay_Ledger::remaining( $state, 1000000 ) );
	}

	/**
	 * A started part blocks another start and reserves its amount.
	 */
	public function test_open_start_blocks_a_new_part() {
		$state = Chandpay_Ledger::blank();
		$state['parts'][] = array( 'status' => 'started', 'amount' => 300000 );

		$this->assertFalse( Chandpay_Ledger::can_start( $state, 100000, 1000000 ) );
		$this->assertSame( 700000, Chandpay_Ledger::remaining( $state, 1000000 ) );
	}

	/**
	 * An expired hold gives the amount back.
	 */
	public function test_expire_releases_the_hold() {
		$state = Chandpay_Ledger::blank();
		$state['parts'][] = array(
			'status'          => 'started',
			'amount'          => 300000,
			'created_at'      => 1000,
			'reservation_ttl' => 60,
		);

		$state = Chandpay_Ledger::expire_stale( $state, 1060 );

		$this->assertSame( 'expired', $state['parts'][0]['status'] );
		$this->assertTrue( Chandpay_Ledger::can_start( $state, 300000, 300000 ) );
	}

	/**
	 * A late return can be verified only when its amount still fits.
	 */
	public function test_late_return_needs_room() {
		$state = Chandpay_Ledger::blank();
		$expired = array( 'id' => 'a', 'status' => 'expired', 'amount' => 400000 );
		$state['parts'][] = $expired;
		$state['parts'][] = array( 'id' => 'b', 'status' => 'verified', 'amount' => 700000 );

		$this->assertFalse( Chandpay_Ledger::can_verify_return( $expired, $state, 1000000 ) );

		$state['parts'][1]['amount'] = 500000;
		$this->assertTrue( Chandpay_Ledger::can_verify_return( $expired, $state, 1000000 ) );
	}

	/**
	 * Finalize only when settled parts cover the order and nothing is still verified.
	 */
	public function test_ready_to_finalize() {
		$state = Chandpay_Ledger::blank();
		$state['parts'][] = array( 'status' => 'settled', 'amount' => 600000 );
		$state['parts'][] = array( 'status' => 'verified', 'amount' => 400000 );
		$this->assertFalse( Chandpay_Ledger::ready_to_finalize( $state, 1000000 ) );

		$state['parts'][1]['status'] = 'settled';
		$this->assertTrue( Chandpay_Ledger::ready_to_finalize( $state, 1000000 ) );
	}
}
