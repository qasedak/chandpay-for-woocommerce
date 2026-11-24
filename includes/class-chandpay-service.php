<?php
/**
 * Order flow: start a part, handle the provider return, settle, void.
 *
 * @package Chandpay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Chandpay application service.
 */
class Chandpay_Service {

	/**
	 * How many nested lock holders are in this request.
	 *
	 * @var int
	 */
	private static $held = 0;

	/**
	 * Pay-remaining URL for an order.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function pay_url( WC_Order $order ) {
		return add_query_arg(
			array(
				'order' => $order->get_id(),
				'key'   => $order->get_order_key(),
			),
			WC()->api_request_url( 'chandpay_pay' )
		);
	}

	/**
	 * Provider return URL for one part.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $part  Part.
	 * @return string
	 */
	public static function return_url( WC_Order $order, array $part ) {
		return add_query_arg(
			array(
				'order'         => $order->get_id(),
				'key'           => $order->get_order_key(),
				'part'          => $part['id'],
				'transactionId' => $part['transaction_id'],
			),
			WC()->api_request_url( 'chandpay_return' )
		);
	}

	/**
	 * Order from the pay or return query, when the key matches.
	 *
	 * @return WC_Order|null
	 */
	public static function authorized_order() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Order key is the credential for this URL.
		$order_id = isset( $_GET['order'] ) ? absint( wp_unslash( $_GET['order'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Order key is the credential for this URL.
		$key   = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || '' === $key ) {
			return null;
		}
		if ( ! hash_equals( (string) $order->get_order_key(), $key ) ) {
			return null;
		}
		if ( 'chandpay' !== $order->get_payment_method() ) {
			return null;
		}

		return $order;
	}

	/**
	 * Ledger for display, releasing expired holds and settling when the total is covered.
	 *
	 * @param WC_Order $order Order.
	 * @return array state and redirect.
	 */
	public static function prepare( WC_Order $order ) {
		$result = self::with_lock(
			$order->get_id(),
			function () use ( $order ) {
				$order = wc_get_order( $order->get_id() );
				$state = self::load( $order );
				$state = Chandpay_Ledger::expire_stale( $state, time() );
				self::save( $order, $state );

				return self::maybe_finalize( $order, $state );
			}
		);

		if ( is_wp_error( $result ) ) {
			return array(
				'state'    => self::load( $order ),
				'redirect' => '',
			);
		}

		return $result;
	}

	/**
	 * Start a provider payment for an amount typed in store currency.
	 *
	 * @param WC_Order $order       Order.
	 * @param string   $provider_id Provider id.
	 * @param float    $entered     Store-currency amount.
	 * @return array|WP_Error
	 */
	public static function start( WC_Order $order, $provider_id, $entered ) {
		return self::with_lock(
			$order->get_id(),
			function () use ( $order, $provider_id, $entered ) {
				$order = wc_get_order( $order->get_id() );
				$guard = self::guard_collecting( $order );
				if ( is_wp_error( $guard ) ) {
					return $guard;
				}

				$provider = Chandpay_Registry::get( $provider_id );
				if ( ! $provider || ! $provider->is_available() ) {
					return new WP_Error( 'chandpay_provider', __( 'That payment method is not available.', 'chandpay-for-woocommerce' ) );
				}

				$state = self::load( $order );
				$state = Chandpay_Ledger::expire_stale( $state, time() );
				$total = self::order_rial( $order );
				$rial  = Chandpay_Money::charge_rial( $entered, Chandpay_Ledger::remaining( $state, $total ), $order->get_currency(), self::decimals() );
				$min   = (int) $provider->capabilities()['min_rial'];

				if ( ! Chandpay_Ledger::can_start( $state, $rial, $total ) ) {
					return new WP_Error( 'chandpay_amount', __( 'Enter an amount up to the remaining balance.', 'chandpay-for-woocommerce' ) );
				}
				if ( $min > 0 && $rial < $min ) {
					return new WP_Error(
						'chandpay_min',
						sprintf(
							/* translators: %s: formatted minimum amount */
							__( 'This provider needs at least %s.', 'chandpay-for-woocommerce' ),
							wp_strip_all_tags( self::format_rial( $min, $order ) )
						)
					);
				}

				$part    = self::new_part( $order, $provider, $rial );
				$started = $provider->start( $order, $part );
				if ( is_wp_error( $started ) ) {
					return $started;
				}

				$part['token']                   = (string) $started['token'];
				$part['reference']               = (string) $started['reference'];
				$part['payment_url']             = (string) $started['redirect'];
				$part['external_source_amount']  = (int) $started['external'];
				$part['status']                  = 'started';
				$state                           = Chandpay_Ledger::replace( $state, $part );
				self::save( $order, $state );
				$order->add_order_note(
					sprintf(
						/* translators: 1: provider name, 2: amount */
						__( 'Chandpay sent the shopper to %1$s for %2$s.', 'chandpay-for-woocommerce' ),
						$provider->label(),
						wp_strip_all_tags( self::format_rial( $rial, $order ) )
					)
				);
				$order->save();

				return array(
					'redirect' => $part['payment_url'],
					'hosts'    => $provider->redirect_hosts_for( $part['payment_url'] ),
				);
			}
		);
	}

	/**
	 * Pay a failed part again for the same provider and amount.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $part_id Part id.
	 * @return array|WP_Error
	 */
	public static function retry( WC_Order $order, $part_id ) {
		$state = self::load( $order );
		$part  = Chandpay_Ledger::find( $state, $part_id );
		if ( ! $part || 'failed' !== $part['status'] ) {
			return new WP_Error( 'chandpay_retry', __( 'That payment cannot be retried.', 'chandpay-for-woocommerce' ) );
		}

		$store = (float) Chandpay_Money::store_string( (int) $part['amount'], $order->get_currency(), self::decimals() );

		return self::start( $order, $part['provider'], $store );
	}

	/**
	 * Drop a failed part so the shopper can choose another method.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $part_id Part id.
	 * @return array|WP_Error
	 */
	public static function dismiss( WC_Order $order, $part_id ) {
		return self::with_lock(
			$order->get_id(),
			function () use ( $order, $part_id ) {
				$order = wc_get_order( $order->get_id() );
				$state = self::load( $order );
				$part  = Chandpay_Ledger::find( $state, $part_id );
				if ( ! $part || 'failed' !== $part['status'] ) {
					return new WP_Error( 'chandpay_dismiss', __( 'That payment cannot be changed.', 'chandpay-for-woocommerce' ) );
				}
				$part['status'] = 'dismissed';
				self::save( $order, Chandpay_Ledger::replace( $state, $part ) );

				return array( 'redirect' => '' );
			}
		);
	}

	/**
	 * Release a started part that the shopper will not finish.
	 *
	 * The provider rolls an unverified payment back on its own.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $part_id Part id.
	 * @return array|WP_Error
	 */
	public static function abandon( WC_Order $order, $part_id ) {
		return self::with_lock(
			$order->get_id(),
			function () use ( $order, $part_id ) {
				$order = wc_get_order( $order->get_id() );
				$state = self::load( $order );
				$part  = Chandpay_Ledger::find( $state, $part_id );
				if ( ! $part || 'started' !== $part['status'] ) {
					return new WP_Error( 'chandpay_abandon', __( 'There is no payment in progress to cancel.', 'chandpay-for-woocommerce' ) );
				}
				$part['status']     = 'expired';
				$part['expired_at'] = time();
				self::save( $order, Chandpay_Ledger::replace( $state, $part ) );

				return array( 'redirect' => '' );
			}
		);
	}

	/**
	 * Provider callback.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $part_id Part id.
	 * @param array    $query   Query bag.
	 * @param array    $post    POST bag.
	 * @return array|WP_Error redirect URL, or empty to use the pay page.
	 */
	public static function handle_return( WC_Order $order, $part_id, array $query, array $post ) {
		return self::with_lock(
			$order->get_id(),
			function () use ( $order, $part_id, $query, $post ) {
				$order    = wc_get_order( $order->get_id() );
				$state    = Chandpay_Ledger::expire_stale( self::load( $order ), time() );
				$part     = Chandpay_Ledger::find( $state, $part_id );
				$provider = $part ? Chandpay_Registry::get( $part['provider'] ) : null;
				if ( ! $part || ! $provider ) {
					self::flash( $order, 'error', __( 'This payment could not be found.', 'chandpay-for-woocommerce' ) );
					return array( 'redirect' => self::pay_url( $order ) );
				}

				if ( in_array( $part['status'], array( 'verified', 'settled' ), true ) || 'finalized' === $state['phase'] ) {
					$done = self::maybe_finalize( $order, $state );
					return array( 'redirect' => $done['redirect'] ? $done['redirect'] : self::pay_url( $order ) );
				}

				if ( in_array( $part['status'], array( 'voided', 'dismissed' ), true ) ) {
					return array( 'redirect' => self::pay_url( $order ) );
				}

				$parsed = $provider->parse_return( $query, $post );
				$callback_reference = isset( $parsed['provider_id'] ) ? (string) $parsed['provider_id'] : '';
				if ( '' !== $callback_reference && $callback_reference !== (string) $part['reference'] ) {
					$part['status']     = 'failed';
					$part['last_error'] = __( 'The provider reference did not match this payment.', 'chandpay-for-woocommerce' );
					self::save( $order, Chandpay_Ledger::replace( $state, $part ) );
					self::flash( $order, 'error', $part['last_error'] );
					return array( 'redirect' => self::pay_url( $order ) );
				}

				if ( 'incomplete' === $parsed['outcome'] ) {
					self::flash( $order, 'error', __( 'The provider did not confirm this payment. You can continue it, or release the amount and choose again.', 'chandpay-for-woocommerce' ) );
					return array( 'redirect' => self::pay_url( $order ) );
				}

				if ( 'ok' !== $parsed['outcome'] ) {
					if ( ! empty( $provider->capabilities()['revert_on_failed'] ) && method_exists( $provider, 'revert_failed' ) ) {
						$provider->revert_failed( $part );
					}
					$part['status']     = 'failed';
					$part['last_error'] = $parsed['message'] ? $parsed['message'] : __( 'The payment failed.', 'chandpay-for-woocommerce' );
					self::save( $order, Chandpay_Ledger::replace( $state, $part ) );
					self::sync( $order, self::load( $order ) );
					self::flash( $order, 'error', $part['last_error'] );
					return array( 'redirect' => self::pay_url( $order ) );
				}

				$total = self::order_rial( $order );
				if ( null === $parsed['amount'] || (int) $parsed['amount'] !== (int) $part['amount'] ) {
					$part['status']     = 'failed';
					$part['last_error'] = __( 'The amount reported by the provider did not match this payment.', 'chandpay-for-woocommerce' );
					self::save( $order, Chandpay_Ledger::replace( $state, $part ) );
					self::flash( $order, 'error', $part['last_error'] );
					return array( 'redirect' => self::pay_url( $order ) );
				}

				if ( ! Chandpay_Ledger::can_verify_return( $part, $state, $total ) ) {
					self::flash( $order, 'error', __( 'This payment came back after its amount was used by another method, so it was not confirmed.', 'chandpay-for-woocommerce' ) );
					return array( 'redirect' => self::pay_url( $order ) );
				}

				if ( '' !== $parsed['tracking_code'] ) {
					$part['tracking_code'] = $parsed['tracking_code'];
				}
				if ( '' !== $parsed['provider_type'] ) {
					$part['provider_type'] = $parsed['provider_type'];
				}

				$verified = $provider->verify( $part );
				if ( is_wp_error( $verified ) ) {
					$part['status']     = 'failed';
					$part['last_error'] = $verified->get_error_message();
					self::save( $order, Chandpay_Ledger::replace( $state, $part ) );
					self::flash( $order, 'error', $part['last_error'] );
					return array( 'redirect' => self::pay_url( $order ) );
				}
				if ( is_array( $verified ) && ! empty( $verified['pending'] ) ) {
					self::save( $order, Chandpay_Ledger::replace( $state, $part ) );
					self::flash( $order, 'error', isset( $verified['message'] ) ? $verified['message'] : '' );
					return array( 'redirect' => self::pay_url( $order ) );
				}
				if ( ! is_array( $verified ) || empty( $verified['ok'] ) ) {
					$part['status']     = 'failed';
					$part['last_error'] = ( is_array( $verified ) && ! empty( $verified['message'] ) )
						? (string) $verified['message']
						: __( 'The provider did not verify this payment.', 'chandpay-for-woocommerce' );
					self::save( $order, Chandpay_Ledger::replace( $state, $part ) );
					self::flash( $order, 'error', $part['last_error'] );
					return array( 'redirect' => self::pay_url( $order ) );
				}

				$part['status']      = 'verified';
				$part['verified_at'] = time();
				$part['last_error']  = '';
				if ( ! empty( $verified['tracking_code'] ) ) {
					$part['tracking_code'] = $verified['tracking_code'];
				}
				$state = Chandpay_Ledger::replace( $state, $part );
				self::save( $order, $state );
				$order->add_order_note(
					sprintf(
						/* translators: 1: provider name, 2: amount */
						__( 'Chandpay verified %1$s for %2$s.', 'chandpay-for-woocommerce' ),
						$provider->label(),
						wp_strip_all_tags( self::format_rial( (int) $part['amount'], $order ) )
					)
				);
				$order->save();

				$done = self::maybe_finalize( $order, self::load( $order ) );

				return array( 'redirect' => $done['redirect'] ? $done['redirect'] : self::pay_url( $order ) );
			}
		);
	}

	/**
	 * Void every captured part and cancel the order.
	 *
	 * @param WC_Order $order Order.
	 * @return array|WP_Error
	 */
	public static function void_all( WC_Order $order ) {
		return self::with_lock(
			$order->get_id(),
			function () use ( $order ) {
				$order = wc_get_order( $order->get_id() );
				if ( ! $order || 'chandpay' !== $order->get_payment_method() ) {
					return new WP_Error( 'chandpay_void', __( 'This order is not a Chandpay order.', 'chandpay-for-woocommerce' ) );
				}

				$state           = self::load( $order );
				$state['phase']  = 'voiding';
				$errors          = array();
				self::save( $order, $state );

				foreach ( $state['parts'] as $part ) {
					if ( 'started' === $part['status'] ) {
						$part['status']     = 'expired';
						$part['expired_at'] = time();
						$state              = Chandpay_Ledger::replace( $state, $part );
						continue;
					}
					if ( ! in_array( $part['status'], array( 'verified', 'settled' ), true ) ) {
						continue;
					}

					$provider = Chandpay_Registry::get( $part['provider'] );
					if ( ! $provider ) {
						$part['last_error'] = __( 'The provider adapter is missing.', 'chandpay-for-woocommerce' );
						$errors[]           = $part['id'];
						$state              = Chandpay_Ledger::replace( $state, $part );
						continue;
					}

					if ( ! empty( $provider->capabilities()['refund'] ) && empty( $part['refund_reference'] ) ) {
						$part['refund_reference'] = substr( 'rf' . $order->get_id() . $part['id'] . time(), 0, 100 );
						$state                    = Chandpay_Ledger::replace( $state, $part );
						self::save( $order, $state );
					}

					$result = $provider->void( $part );
					if ( is_wp_error( $result ) ) {
						$part['last_error'] = $result->get_error_message();
						$errors[]           = $provider->label() . ': ' . $part['last_error'];
					} else {
						$part['status']     = 'voided';
						$part['voided_at']  = time();
						$part['last_error'] = '';
					}
					$state = Chandpay_Ledger::replace( $state, $part );
				}

				self::save( $order, $state );
				if ( Chandpay_Ledger::has_capture( $state ) ) {
					$state['phase'] = 'collecting';
					self::save( $order, $state );
					$order->add_order_note( __( 'Chandpay could not void every payment, so the order was not cancelled.', 'chandpay-for-woocommerce' ) . ' ' . implode( ' | ', $errors ) );
					$order->save();
					self::sync( $order, $state );

					return new WP_Error( 'chandpay_void', implode( ' ', $errors ) );
				}

				$state['phase'] = 'voided';
				self::save( $order, $state );
				if ( ! in_array( $order->get_status(), array( 'cancelled', 'refunded' ), true ) ) {
					$order->update_status( 'cancelled', __( 'Chandpay voided every captured payment.', 'chandpay-for-woocommerce' ) );
				}

				return array( 'ok' => true );
			}
		);
	}

	/**
	 * Tell providers that need a delivery call once the order is completed.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public static function deliver_completed( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || 'chandpay' !== $order->get_payment_method() ) {
			return;
		}

		self::with_lock(
			$order->get_id(),
			function () use ( $order ) {
				$order = wc_get_order( $order->get_id() );
				$state = self::load( $order );
				foreach ( $state['parts'] as $part ) {
					if ( 'settled' !== $part['status'] || ! empty( $part['delivered_at'] ) ) {
						continue;
					}
					$provider = Chandpay_Registry::get( $part['provider'] );
					if ( ! $provider || empty( $provider->capabilities()['deliver_on_completed'] ) ) {
						continue;
					}
					$result = $provider->deliver( $order, $part );
					if ( is_wp_error( $result ) ) {
						$part['last_error'] = $result->get_error_message();
						$order->add_order_note( $provider->label() . ': ' . $part['last_error'] );
					} else {
						$part['delivered_at'] = time();
						$part['last_error']   = '';
					}
					$state = Chandpay_Ledger::replace( $state, $part );
				}
				self::save( $order, $state );
				$order->save();
			}
		);
	}

	/**
	 * Read and clear the shopper flash message.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function pull_flash( WC_Order $order ) {
		$flash = $order->get_meta( '_chandpay_flash', true );
		if ( ! is_array( $flash ) || empty( $flash['message'] ) ) {
			return array();
		}
		$order->delete_meta_data( '_chandpay_flash' );
		$order->save();

		return array(
			'type'    => isset( $flash['type'] ) ? (string) $flash['type'] : 'error',
			'message' => (string) $flash['message'],
		);
	}

	/**
	 * Store a flash message for the pay page.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $type    error or info.
	 * @param string   $message Message.
	 * @return void
	 */
	public static function flash( WC_Order $order, $type, $message ) {
		$order = wc_get_order( $order->get_id() );
		if ( ! $order ) {
			return;
		}
		$order->update_meta_data(
			'_chandpay_flash',
			array(
				'type'    => 'error' === $type ? 'error' : 'info',
				'message' => wp_strip_all_tags( (string) $message ),
			)
		);
		$order->save();
	}

	/**
	 * Ledger meta.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function load( WC_Order $order ) {
		return Chandpay_Ledger::normalize( $order->get_meta( Chandpay_Ledger::META_KEY, true ) );
	}

	/**
	 * Rial still to collect, after expired holds are released in memory.
	 *
	 * @param WC_Order $order Order.
	 * @return int
	 */
	public static function payable_rial( WC_Order $order ) {
		$state = Chandpay_Ledger::expire_stale( self::load( $order ), time() );

		return Chandpay_Ledger::remaining( $state, self::order_rial( $order ) );
	}

	/**
	 * Order total in rial.
	 *
	 * @param WC_Order $order Order.
	 * @return int
	 */
	public static function order_rial( WC_Order $order ) {
		return Chandpay_Money::to_rial( $order->get_total(), $order->get_currency() );
	}

	/**
	 * Format rial in the order currency.
	 *
	 * @param int      $rial  Rial.
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function format_rial( $rial, WC_Order $order ) {
		$store = (float) Chandpay_Money::store_string( (int) $rial, $order->get_currency(), self::decimals() );

		return wc_price( $store, array( 'currency' => $order->get_currency() ) );
	}

	/**
	 * Price decimals.
	 *
	 * @return int
	 */
	public static function decimals() {
		return function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 0;
	}

	/**
	 * Settle verified parts once the remaining amount is zero.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $state Ledger.
	 * @return array state and redirect.
	 */
	private static function maybe_finalize( WC_Order $order, array $state ) {
		$total = self::order_rial( $order );
		self::sync( $order, $state );

		if ( 'finalized' === $state['phase'] || 'voiding' === $state['phase'] || 'voided' === $state['phase'] ) {
			return array(
				'state'    => $state,
				'redirect' => 'finalized' === $state['phase'] ? $order->get_checkout_order_received_url() : '',
			);
		}

		if ( Chandpay_Ledger::remaining( $state, $total ) !== 0 || Chandpay_Ledger::open_started( $state ) ) {
			return array(
				'state'    => $state,
				'redirect' => '',
			);
		}

		foreach ( $state['parts'] as $part ) {
			if ( 'verified' !== $part['status'] ) {
				continue;
			}
			$provider = Chandpay_Registry::get( $part['provider'] );
			$result   = $provider ? $provider->settle( $part ) : new WP_Error( 'chandpay_provider', __( 'The provider adapter is missing.', 'chandpay-for-woocommerce' ) );
			if ( is_wp_error( $result ) ) {
				$part['last_error'] = $result->get_error_message();
				$state              = Chandpay_Ledger::replace( $state, $part );
				self::save( $order, $state );
				self::sync( $order, $state );
				self::flash( $order, 'error', $part['last_error'] );

				return array(
					'state'    => self::load( $order ),
					'redirect' => '',
				);
			}
			$part['status']     = 'settled';
			$part['settled_at'] = time();
			$part['last_error'] = '';
			$state              = Chandpay_Ledger::replace( $state, $part );
		}

		if ( ! Chandpay_Ledger::ready_to_finalize( $state, $total ) ) {
			self::save( $order, $state );
			self::sync( $order, $state );

			return array(
				'state'    => $state,
				'redirect' => '',
			);
		}

		$state['phase'] = 'finalized';
		self::save( $order, $state );
		$bits = array();
		foreach ( $state['parts'] as $part ) {
			if ( 'settled' !== $part['status'] ) {
				continue;
			}
			$ref    = $part['tracking_code'] ? $part['tracking_code'] : $part['transaction_id'];
			$bits[] = $part['provider'] . ':' . $ref;
		}
		$order->payment_complete( substr( implode( ',', $bits ), 0, 180 ) );

		return array(
			'state'    => self::load( wc_get_order( $order->get_id() ) ),
			'redirect' => $order->get_checkout_order_received_url(),
		);
	}

	/**
	 * Move the WooCommerce status with the captured parts.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $state Ledger.
	 * @return void
	 */
	private static function sync( WC_Order $order, array $state ) {
		if ( in_array( $state['phase'], array( 'finalized', 'voided' ), true ) ) {
			return;
		}
		if ( in_array( $order->get_status(), array( 'cancelled', 'refunded', 'processing', 'completed' ), true ) ) {
			return;
		}
		if ( Chandpay_Ledger::has_capture( $state ) && 'pending' === $order->get_status() ) {
			$order->update_status( 'on-hold', __( 'Chandpay verified the first part. The order stays on hold until the rest is paid.', 'chandpay-for-woocommerce' ) );
		}
	}

	/**
	 * Reject work on an order that is no longer collecting.
	 *
	 * @param WC_Order $order Order.
	 * @return true|WP_Error
	 */
	private static function guard_collecting( WC_Order $order ) {
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'chandpay_order', __( 'The order could not be found.', 'chandpay-for-woocommerce' ) );
		}
		if ( ! Chandpay_Money::supports( $order->get_currency() ) ) {
			return new WP_Error( 'chandpay_currency', __( 'Chandpay only supports Iranian rial and toman totals.', 'chandpay-for-woocommerce' ) );
		}
		if ( in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed', 'processing', 'completed' ), true ) ) {
			return new WP_Error( 'chandpay_closed', __( 'This order is no longer waiting for payment.', 'chandpay-for-woocommerce' ) );
		}
		$state = self::load( $order );
		if ( 'collecting' !== $state['phase'] ) {
			return new WP_Error( 'chandpay_phase', __( 'This order is no longer accepting payments.', 'chandpay-for-woocommerce' ) );
		}

		return true;
	}

	/**
	 * A new started-shaped part. The provider call fills the token.
	 *
	 * @param WC_Order          $order    Order.
	 * @param Chandpay_Provider $provider Provider.
	 * @param int               $rial     Part amount.
	 * @return array
	 */
	private static function new_part( WC_Order $order, Chandpay_Provider $provider, $rial ) {
		try {
			$id = substr( bin2hex( random_bytes( 4 ) ), 0, 8 );
		} catch ( Exception $exception ) {
			unset( $exception );
			$id = strtolower( wp_generate_password( 8, false, false ) );
		}

		$part = array(
			'id'                     => $id,
			'provider'               => $provider->id(),
			'amount'                 => (int) $rial,
			'external_source_amount' => 0,
			'status'                 => 'started',
			'token'                  => '',
			'transaction_id'         => 'c' . $order->get_id() . 'p' . $id . wp_rand( 100, 999 ),
			'payment_url'            => '',
			'reference'              => '',
			'tracking_code'          => '',
			'provider_type'          => '',
			'refund_reference'       => '',
			'reservation_ttl'        => (int) $provider->capabilities()['reservation_ttl'],
			'created_at'             => time(),
			'verified_at'            => 0,
			'settled_at'             => 0,
			'voided_at'              => 0,
			'expired_at'             => 0,
			'delivered_at'           => 0,
			'last_error'             => '',
		);
		$part['return_url'] = self::return_url( $order, $part );

		return $part;
	}

	/**
	 * Persist the ledger.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $state Ledger.
	 * @return void
	 */
	private static function save( WC_Order $order, array $state ) {
		$order->update_meta_data( Chandpay_Ledger::META_KEY, $state );
		$order->save();
	}

	/**
	 * Run a callback while holding the order lock.
	 *
	 * Nested calls in the same request reuse the lock so a completed-order
	 * delivery hook can run inside payment completion.
	 *
	 * @param int      $order_id Order id.
	 * @param callable $callback Work.
	 * @return mixed|WP_Error
	 */
	private static function with_lock( $order_id, $callback ) {
		if ( self::$held > 0 ) {
			return call_user_func( $callback );
		}

		$key   = 'chandpay_lock_' . absint( $order_id );
		$token = function_exists( 'wp_generate_password' ) ? wp_generate_password( 12, false, false ) : bin2hex( random_bytes( 6 ) );
		$got   = false;
		for ( $attempt = 0; $attempt < 15; $attempt++ ) {
			$value = $token . '|' . ( time() + 25 );
			if ( add_option( $key, $value, '', false ) ) {
				$got = true;
				break;
			}
			$current = (string) get_option( $key, '' );
			$until   = 0;
			if ( false !== strpos( $current, '|' ) ) {
				$until = (int) substr( $current, strrpos( $current, '|' ) + 1 );
			}
			if ( $until < time() ) {
				delete_option( $key );
				if ( add_option( $key, $token . '|' . ( time() + 25 ), '', false ) ) {
					$got = true;
					break;
				}
			}
			usleep( 200000 );
		}

		if ( ! $got ) {
			return new WP_Error( 'chandpay_busy', __( 'Another payment update is in progress. Refresh and try again.', 'chandpay-for-woocommerce' ) );
		}

		self::$held++;
		try {
			return call_user_func( $callback );
		} finally {
			self::$held--;
			$current = (string) get_option( $key, '' );
			if ( 0 === strpos( $current, $token . '|' ) ) {
				delete_option( $key );
			}
		}
	}
}
