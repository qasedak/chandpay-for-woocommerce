<?php
/**
 * Order screen: parts and the revert action.
 *
 * @package Chandpay
 */

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce admin for Chandpay orders.
 */
class Chandpay_Admin {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'order_actions' ), 10, 2 );
		add_action( 'woocommerce_order_action_chandpay_revert', array( __CLASS__, 'revert_action' ) );
		add_action( 'admin_post_chandpay_revert', array( __CLASS__, 'revert_post' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/**
	 * Side box on Chandpay orders.
	 *
	 * @param string $screen Screen id.
	 * @return void
	 */
	public static function meta_box( $screen ) {
		if ( ! in_array( $screen, array( 'shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
			return;
		}
		$order = self::current_order();
		if ( ! $order || 'chandpay' !== $order->get_payment_method() ) {
			return;
		}

		add_meta_box(
			'chandpay-parts',
			__( 'Chandpay', 'chandpay-for-woocommerce' ),
			array( __CLASS__, 'render' ),
			$screen,
			'side',
			'high'
		);
	}

	/**
	 * Order-screen styles.
	 *
	 * @return void
	 */
	public static function enqueue() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
			return;
		}

		wp_enqueue_style(
			'chandpay-admin',
			CHANDPAY_URL . 'assets/css/admin.css',
			array(),
			chandpay_asset_version( 'assets/css/admin.css' )
		);
	}

	/**
	 * Box contents.
	 *
	 * @return void
	 */
	public static function render() {
		$order = self::current_order();
		if ( ! $order ) {
			return;
		}
		$state = Chandpay_Service::load( $order );
		echo '<p class="chandpay-admin-link"><a href="' . esc_url( Chandpay_Service::pay_url( $order ) ) . '">' . esc_html__( 'Pay-remaining page', 'chandpay-for-woocommerce' ) . '</a></p>';

		if ( ! $state['parts'] ) {
			echo '<p>' . esc_html__( 'No payments have been started.', 'chandpay-for-woocommerce' ) . '</p>';
			return;
		}

		echo '<ul class="chandpay-admin-parts">';
		foreach ( $state['parts'] as $part ) {
			$provider = Chandpay_Registry::get( $part['provider'] );
			$label    = $provider ? $provider->label() : $part['provider'];
			echo '<li class="is-' . esc_attr( sanitize_html_class( $part['status'] ) ) . '">';
			echo '<span class="chandpay-admin-name">' . esc_html( $label ) . '</span>';
			echo '<span class="chandpay-admin-amount">' . wp_kses_post( Chandpay_Service::format_rial( (int) $part['amount'], $order ) ) . '</span>';
			echo '<span class="chandpay-admin-status">' . esc_html( self::status_label( $part['status'] ) ) . '</span>';
			if ( ! empty( $part['last_error'] ) ) {
				echo '<span class="chandpay-admin-error">' . esc_html( $part['last_error'] ) . '</span>';
			}
			echo '</li>';
		}
		echo '</ul>';

		if ( ! Chandpay_Ledger::has_capture( $state ) || in_array( $order->get_status(), array( 'cancelled', 'refunded' ), true ) ) {
			return;
		}
		if ( ! self::can_edit( $order ) ) {
			return;
		}

		echo '<form class="chandpay-admin-revert" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'chandpay_revert_' . $order->get_id() );
		echo '<input type="hidden" name="action" value="chandpay_revert" />';
		echo '<input type="hidden" name="order_id" value="' . esc_attr( (string) $order->get_id() ) . '" />';
		echo '<button type="submit" class="button" onclick="return confirm(\'' . esc_js( __( 'Void every captured Chandpay payment and cancel this order?', 'chandpay-for-woocommerce' ) ) . '\');">';
		echo esc_html__( 'Revert all and cancel', 'chandpay-for-woocommerce' );
		echo '</button></form>';
	}

	/**
	 * Dropdown action on the order screen.
	 *
	 * @param array         $actions Actions.
	 * @param WC_Order|null $order   Order, when WooCommerce passes it.
	 * @return array
	 */
	public static function order_actions( $actions, $order = null ) {
		if ( ! $order instanceof WC_Order ) {
			$order = self::current_order();
		}
		if ( ! $order || 'chandpay' !== $order->get_payment_method() ) {
			return $actions;
		}
		if ( in_array( $order->get_status(), array( 'cancelled', 'refunded' ), true ) ) {
			return $actions;
		}
		if ( ! Chandpay_Ledger::has_capture( Chandpay_Service::load( $order ) ) ) {
			return $actions;
		}

		$actions['chandpay_revert'] = __( 'Revert all Chandpay payments and cancel', 'chandpay-for-woocommerce' );

		return $actions;
	}

	/**
	 * Order-action dropdown handler. WooCommerce has already checked the order nonce.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public static function revert_action( $order ) {
		if ( ! $order instanceof WC_Order || ! self::can_edit( $order ) ) {
			return;
		}
		self::run_revert( $order );
	}

	/**
	 * Meta box button handler.
	 *
	 * @return void
	 */
	public static function revert_post() {
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		check_admin_referer( 'chandpay_revert_' . $order_id );
		$order = wc_get_order( $order_id );
		if ( ! $order || ! self::can_edit( $order ) ) {
			wp_die( esc_html__( 'You cannot edit this order.', 'chandpay-for-woocommerce' ) );
		}

		self::run_revert( $order );
		wp_safe_redirect( $order->get_edit_order_url() );
		exit;
	}

	/**
	 * Show the result of a revert.
	 *
	 * @return void
	 */
	public static function notice() {
		$key     = 'chandpay_admin_notice_' . get_current_user_id();
		$message = get_transient( $key );
		if ( ! is_string( $message ) || '' === $message ) {
			return;
		}
		delete_transient( $key );
		echo '<div class="notice notice-warning"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Void and remember a failure for the next admin screen.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	private static function run_revert( WC_Order $order ) {
		$result = Chandpay_Service::void_all( $order );
		if ( is_wp_error( $result ) ) {
			set_transient( 'chandpay_admin_notice_' . get_current_user_id(), $result->get_error_message(), 60 );
		}
	}

	/**
	 * Shop manager or anyone who can edit this order.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	private static function can_edit( WC_Order $order ) {
		return current_user_can( 'edit_shop_order', $order->get_id() ) || current_user_can( 'edit_shop_orders' );
	}

	/**
	 * Order being edited.
	 *
	 * @return WC_Order|null
	 */
	private static function current_order() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen id.
		if ( isset( $_GET['id'] ) ) {
			$order = wc_get_order( absint( wp_unslash( $_GET['id'] ) ) );
			if ( $order instanceof WC_Order ) {
				return $order;
			}
		}
		global $post;
		if ( $post instanceof WP_Post ) {
			$order = wc_get_order( $post->ID );
			if ( $order instanceof WC_Order ) {
				return $order;
			}
		}

		return null;
	}

	/**
	 * Persian label for a part status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private static function status_label( $status ) {
		$labels = array(
			'started'   => 'در جریان',
			'failed'    => 'ناموفق',
			'verified'  => 'تأیید شده',
			'settled'   => 'تسویه شده',
			'voided'    => 'برگشت شده',
			'expired'   => 'منقضی',
			'dismissed' => 'تغییر روش',
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}
}
