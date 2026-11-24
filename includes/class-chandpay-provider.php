<?php
/**
 * Provider contract.
 *
 * @package Chandpay
 */

defined( 'ABSPATH' ) || exit;

/**
 * One installment provider.
 */
interface Chandpay_Provider {

	/**
	 * Stable id.
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Shopper-facing name.
	 *
	 * @return string
	 */
	public function label();

	/**
	 * Credentials exist and the provider is enabled in Chandpay.
	 *
	 * @return bool
	 */
	public function is_available();

	/**
	 * Supported actions.
	 *
	 * Keys: partial, external_source, verify, settle, auto_settle,
	 * revert, cancel, refund, revert_on_failed, min_rial,
	 * reservation_ttl, deliver_on_completed.
	 *
	 * @return array
	 */
	public function capabilities();

	/**
	 * Create a payment and return the hosted page URL.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $part  Part, including return_url and transaction_id.
	 * @return array|WP_Error redirect, token, reference.
	 */
	public function start( WC_Order $order, array $part );

	/**
	 * Hosts allowed for this provider's payment page.
	 *
	 * @param string $payment_url Payment page URL returned by start().
	 * @return array
	 */
	public function redirect_hosts_for( $payment_url );

	/**
	 * Read a provider callback.
	 *
	 * @param array $query Query string.
	 * @param array $post  POST body.
	 * @return array outcome ok|failed, amount, transaction_id, tracking_code, provider_type, message.
	 */
	public function parse_return( array $query, array $post );

	/**
	 * Confirm a successful return.
	 *
	 * @param array $part Part.
	 * @return array|WP_Error ok, pending, message, tracking_code, provider_type.
	 */
	public function verify( array $part );

	/**
	 * Capture a verified part. Providers without a settle call report ok.
	 *
	 * @param array $part Part.
	 * @return array|WP_Error
	 */
	public function settle( array $part );

	/**
	 * Revert, cancel, or refund a captured part.
	 *
	 * @param array $part Part.
	 * @return array|WP_Error
	 */
	public function void( array $part );

	/**
	 * Tell the provider the goods were delivered.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $part  Part.
	 * @return array|WP_Error
	 */
	public function deliver( WC_Order $order, array $part );
}
