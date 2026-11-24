<?php
/**
 * Pure payment-part ledger for one order.
 *
 * Amounts are rial. Statuses that reserve money: started, verified, settled.
 *
 * @package Chandpay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Order-level Chandpay state.
 */
class Chandpay_Ledger {

	const META_KEY = '_chandpay';

	/**
	 * Empty ledger.
	 *
	 * @return array
	 */
	public static function blank() {
		return array(
			'version' => 1,
			'phase'   => 'collecting',
			'parts'   => array(),
		);
	}

	/**
	 * Fill missing keys on stored state.
	 *
	 * @param mixed $state Stored value.
	 * @return array
	 */
	public static function normalize( $state ) {
		if ( ! is_array( $state ) ) {
			return self::blank();
		}
		if ( empty( $state['phase'] ) || ! is_string( $state['phase'] ) ) {
			$state['phase'] = 'collecting';
		}
		if ( empty( $state['parts'] ) || ! is_array( $state['parts'] ) ) {
			$state['parts'] = array();
		}
		$state['version'] = 1;

		return $state;
	}

	/**
	 * Sum of parts in the given statuses.
	 *
	 * @param array $state    Ledger.
	 * @param array $statuses Status list.
	 * @return int
	 */
	public static function sum( array $state, array $statuses ) {
		$total = 0;
		foreach ( $state['parts'] as $part ) {
			if ( in_array( $part['status'], $statuses, true ) ) {
				$total += (int) $part['amount'];
			}
		}

		return $total;
	}

	/**
	 * Rial still reserved (in flight, verified, or settled).
	 *
	 * @param array $state Ledger.
	 * @return int
	 */
	public static function reserved( array $state ) {
		return self::sum( $state, array( 'started', 'verified', 'settled' ) );
	}

	/**
	 * Rial still to collect.
	 *
	 * @param array $state       Ledger.
	 * @param int   $order_total Order total in rial.
	 * @return int
	 */
	public static function remaining( array $state, $order_total ) {
		return max( 0, (int) $order_total - self::reserved( $state ) );
	}

	/**
	 * Mark started parts past their reservation window as expired.
	 *
	 * @param array $state Ledger.
	 * @param int   $now   Unix time.
	 * @return array
	 */
	public static function expire_stale( array $state, $now ) {
		foreach ( $state['parts'] as $index => $part ) {
			if ( 'started' !== $part['status'] ) {
				continue;
			}
			$ttl     = isset( $part['reservation_ttl'] ) ? (int) $part['reservation_ttl'] : 1200;
			$created = isset( $part['created_at'] ) ? (int) $part['created_at'] : 0;
			if ( $ttl > 0 && $created > 0 && ( (int) $now - $created ) >= $ttl ) {
				$state['parts'][ $index ]['status']     = 'expired';
				$state['parts'][ $index ]['expired_at'] = (int) $now;
			}
		}

		return $state;
	}

	/**
	 * The in-flight part, if one exists.
	 *
	 * @param array $state Ledger.
	 * @return array|null
	 */
	public static function open_started( array $state ) {
		foreach ( $state['parts'] as $part ) {
			if ( 'started' === $part['status'] ) {
				return $part;
			}
		}

		return null;
	}

	/**
	 * Whether a new part of this rial amount may start.
	 *
	 * @param array $state       Ledger.
	 * @param int   $amount      Part amount in rial.
	 * @param int   $order_total Order total in rial.
	 * @return bool
	 */
	public static function can_start( array $state, $amount, $order_total ) {
		if ( 'collecting' !== $state['phase'] ) {
			return false;
		}
		if ( self::open_started( $state ) ) {
			return false;
		}
		$amount = (int) $amount;
		if ( $amount < 1 ) {
			return false;
		}

		return $amount <= self::remaining( $state, $order_total );
	}

	/**
	 * Find a part by id.
	 *
	 * @param array  $state Ledger.
	 * @param string $id    Part id.
	 * @return array|null
	 */
	public static function find( array $state, $id ) {
		foreach ( $state['parts'] as $part ) {
			if ( isset( $part['id'] ) && (string) $part['id'] === (string) $id ) {
				return $part;
			}
		}

		return null;
	}

	/**
	 * Replace a part.
	 *
	 * @param array $state Ledger.
	 * @param array $part  Updated part.
	 * @return array
	 */
	public static function replace( array $state, array $part ) {
		foreach ( $state['parts'] as $index => $existing ) {
			if ( (string) $existing['id'] === (string) $part['id'] ) {
				$state['parts'][ $index ] = $part;
				return $state;
			}
		}
		$state['parts'][] = $part;

		return $state;
	}

	/**
	 * Latest failed part that the shopper has not dismissed.
	 *
	 * @param array $state Ledger.
	 * @return array|null
	 */
	public static function latest_failed( array $state ) {
		$failed = null;
		foreach ( $state['parts'] as $part ) {
			if ( 'failed' === $part['status'] ) {
				$failed = $part;
			}
		}

		return $failed;
	}

	/**
	 * Verified or settled money is still on the order.
	 *
	 * @param array $state Ledger.
	 * @return bool
	 */
	public static function has_capture( array $state ) {
		return self::sum( $state, array( 'verified', 'settled' ) ) > 0;
	}

	/**
	 * Every captured rial is settled and the order total is covered.
	 *
	 * @param array $state       Ledger.
	 * @param int   $order_total Order total in rial.
	 * @return bool
	 */
	public static function ready_to_finalize( array $state, $order_total ) {
		if ( self::remaining( $state, $order_total ) !== 0 ) {
			return false;
		}
		if ( self::open_started( $state ) ) {
			return false;
		}
		foreach ( $state['parts'] as $part ) {
			if ( 'verified' === $part['status'] || 'started' === $part['status'] ) {
				return false;
			}
		}

		return self::sum( $state, array( 'settled' ) ) === (int) $order_total;
	}

	/**
	 * A return for this part may still be verified.
	 *
	 * Expired and failed parts can be accepted when their amount still fits,
	 * because the provider may have taken the money after we released the hold.
	 *
	 * @param array $part          Part.
	 * @param array $state         Ledger.
	 * @param int   $order_total   Order total in rial.
	 * @return bool
	 */
	public static function can_verify_return( array $part, array $state, $order_total ) {
		$status = $part['status'];
		if ( in_array( $status, array( 'verified', 'settled', 'voided', 'dismissed' ), true ) ) {
			return false;
		}
		if ( 'started' === $status ) {
			return true;
		}
		if ( ! in_array( $status, array( 'failed', 'expired' ), true ) ) {
			return false;
		}

		$free = self::remaining( $state, $order_total );

		return $free >= (int) $part['amount'];
	}
}
