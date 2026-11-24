<?php
/**
 * Uninstall Chandpay.
 *
 * Order payment history stored on orders is kept.
 *
 * @package Chandpay
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'woocommerce_chandpay_settings' );
delete_option( 'chandpay_digipay_oauth' );
