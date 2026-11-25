<?php
/**
 * Plugin Name:       Chandpay for WooCommerce
 * Description:       Pay one WooCommerce order across several installment providers.
 * Version:           0.1.5
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Chandpay
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       chandpay-for-woocommerce
 * Domain Path:       /languages
 * WC requires at least: 7.1
 * WC tested up to:   11.3.1
 *
 * @package Chandpay
 */

defined( 'ABSPATH' ) || exit;

define( 'CHANDPAY_VERSION', '0.1.5' );
define( 'CHANDPAY_FILE', __FILE__ );
define( 'CHANDPAY_PATH', plugin_dir_path( __FILE__ ) );
define( 'CHANDPAY_URL', plugin_dir_url( __FILE__ ) );

/**
 * Asset version from the file's modification time.
 *
 * @param string $relative Path relative to the plugin root.
 * @return string
 */
function chandpay_asset_version( $relative ) {
	$path = CHANDPAY_PATH . ltrim( $relative, '/' );
	if ( file_exists( $path ) ) {
		return (string) filemtime( $path );
	}

	return CHANDPAY_VERSION;
}

/**
 * Load plugin classes once WooCommerce is available.
 *
 * @return void
 */
function chandpay_load() {
	static $loaded = false;

	if ( $loaded || ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	$loaded = true;

	$files = array(
		'includes/class-chandpay-money.php',
		'includes/class-chandpay-phone.php',
		'includes/class-chandpay-ledger.php',
		'includes/class-chandpay-cart.php',
		'includes/class-chandpay-http.php',
		'includes/class-chandpay-credentials.php',
		'includes/class-chandpay-redirect.php',
		'includes/class-chandpay-provider.php',
		'includes/providers/class-chandpay-provider-cpg.php',
		'includes/providers/class-chandpay-provider-torobpay.php',
		'includes/providers/class-chandpay-provider-snapppay.php',
		'includes/providers/class-chandpay-provider-digipay.php',
		'includes/class-chandpay-registry.php',
		'includes/class-chandpay-service.php',
		'includes/class-chandpay-gateway.php',
		'includes/class-chandpay-pay-page.php',
		'includes/class-chandpay-admin.php',
		'includes/class-chandpay-plugin.php',
	);

	foreach ( $files as $file ) {
		require_once CHANDPAY_PATH . $file;
	}

	Chandpay_Plugin::init();
}

add_action( 'plugins_loaded', 'chandpay_load', 0 );

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', CHANDPAY_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', CHANDPAY_FILE, true );
		}
	}
);

add_action(
	'woocommerce_blocks_loaded',
	static function () {
		chandpay_load();
		if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}
		require_once CHANDPAY_PATH . 'includes/class-chandpay-blocks.php';
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( $registry ) {
				$registry->register( new Chandpay_Blocks() );
			}
		);
	}
);

/**
 * Tell the merchant WooCommerce is required.
 *
 * @return void
 */
function chandpay_woocommerce_missing_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="error"><p>';
	echo esc_html__( 'Chandpay for WooCommerce needs WooCommerce to be active.', 'chandpay-for-woocommerce' );
	echo '</p></div>';
}

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', 'chandpay_woocommerce_missing_notice' );
		}
	},
	20
);
