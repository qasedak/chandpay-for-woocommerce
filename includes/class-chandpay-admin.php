<?php
/**
 * Order screen: parts and a void action on each captured part.
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
		add_action( 'admin_post_chandpay_void', array( __CLASS__, 'void_post' ) );
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

		$editable = self::can_edit( $order );

		echo '<ul class="chandpay-admin-parts">';
		foreach ( $state['parts'] as $part ) {
			$provider  = Chandpay_Registry::get( $part['provider'] );
			$label     = $provider ? $provider->label() : $part['provider'];
			$operation = isset( $part['void_operation'] ) ? (string) $part['void_operation'] : '';
			echo '<li class="is-' . esc_attr( sanitize_html_class( $part['status'] ) ) . '">';
			echo '<span class="chandpay-admin-name">' . esc_html( $label ) . '</span>';
			echo '<span class="chandpay-admin-amount">' . wp_kses_post( Chandpay_Service::format_rial( (int) $part['amount'], $order ) ) . '</span>';
			echo '<span class="chandpay-admin-status">' . esc_html( self::status_label( $part['status'], $operation ) ) . '</span>';
			if ( ! empty( $part['last_error'] ) ) {
				echo '<span class="chandpay-admin-error">' . esc_html( $part['last_error'] ) . '</span>';
			}
			if ( $editable && $provider && in_array( $part['status'], array( 'verified', 'settled' ), true ) ) {
				self::void_button( $order, $part, $provider );
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Meta box button handler.
	 *
	 * @return void
	 */
	public static function void_post() {
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$part_id  = isset( $_POST['part_id'] ) ? sanitize_key( wp_unslash( $_POST['part_id'] ) ) : '';
		check_admin_referer( 'chandpay_void_' . $order_id . '_' . $part_id );
		$order = wc_get_order( $order_id );
		if ( ! $order || ! self::can_edit( $order ) ) {
			wp_die( esc_html__( 'You cannot edit this order.', 'chandpay-for-woocommerce' ) );
		}

		$result = Chandpay_Service::void_part( $order, $part_id );
		if ( is_wp_error( $result ) ) {
			set_transient( 'chandpay_admin_notice_' . get_current_user_id(), $result->get_error_message(), 60 );
		}
		wp_safe_redirect( $order->get_edit_order_url() );
		exit;
	}

	/**
	 * Show the result of undoing a payment.
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
	 * Button that undoes one captured part.
	 *
	 * @param WC_Order          $order     Order.
	 * @param array             $part      Part.
	 * @param Chandpay_Provider $provider  Provider.
	 * @return void
	 */
	private static function void_button( WC_Order $order, array $part, Chandpay_Provider $provider ) {
		$operation = self::undo_operation( $provider, $part );
		$amount    = wp_strip_all_tags( Chandpay_Service::format_rial( (int) $part['amount'], $order ) );
		$confirm   = self::confirm_text( $operation, $provider->label(), $amount );

		echo '<form class="chandpay-admin-void" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'chandpay_void_' . $order->get_id() . '_' . $part['id'] );
		echo '<input type="hidden" name="action" value="chandpay_void" />';
		echo '<input type="hidden" name="order_id" value="' . esc_attr( (string) $order->get_id() ) . '" />';
		echo '<input type="hidden" name="part_id" value="' . esc_attr( (string) $part['id'] ) . '" />';
		echo '<button type="submit" class="button" onclick="return confirm(\'' . esc_js( $confirm ) . '\');">';
		echo esc_html( self::undo_label( $operation ) );
		echo '</button></form>';
	}

	/**
	 * Gateway action the button names. The provider may still choose another call.
	 *
	 * @param Chandpay_Provider $provider Provider.
	 * @param array             $part     Part.
	 * @return string revert, cancel, or refund.
	 */
	private static function undo_operation( Chandpay_Provider $provider, array $part ) {
		$caps = $provider->capabilities();
		if ( ! empty( $caps['refund'] ) ) {
			return 'refund';
		}
		if ( 'settled' === $part['status'] || empty( $caps['revert'] ) ) {
			return 'cancel';
		}

		return 'revert';
	}

	/**
	 * Button label.
	 *
	 * @param string $operation revert, cancel, or refund.
	 * @return string
	 */
	private static function undo_label( $operation ) {
		if ( 'refund' === $operation ) {
			return __( 'Refund', 'chandpay-for-woocommerce' );
		}
		if ( 'cancel' === $operation ) {
			return __( 'Cancel', 'chandpay-for-woocommerce' );
		}

		return __( 'Revert', 'chandpay-for-woocommerce' );
	}

	/**
	 * Confirm text for one part.
	 *
	 * @param string $operation revert, cancel, or refund.
	 * @param string $name      Provider name.
	 * @param string $amount    Plain amount.
	 * @return string
	 */
	private static function confirm_text( $operation, $name, $amount ) {
		if ( 'refund' === $operation ) {
			return sprintf(
				/* translators: 1: provider name, 2: amount */
				__( 'Refund this %1$s payment of %2$s? The order status will not change.', 'chandpay-for-woocommerce' ),
				$name,
				$amount
			);
		}
		if ( 'cancel' === $operation ) {
			return sprintf(
				/* translators: 1: provider name, 2: amount */
				__( 'Cancel this %1$s payment of %2$s? The order status will not change.', 'chandpay-for-woocommerce' ),
				$name,
				$amount
			);
		}

		return sprintf(
			/* translators: 1: provider name, 2: amount */
			__( 'Revert this %1$s payment of %2$s? The order status will not change.', 'chandpay-for-woocommerce' ),
			$name,
			$amount
		);
	}

	/**
	 * Persian label for a part status.
	 *
	 * @param string $status    Status.
	 * @param string $operation revert, cancel, or refund after a void.
	 * @return string
	 */
	private static function status_label( $status, $operation = '' ) {
		if ( 'voided' === $status && 'cancel' === $operation ) {
			return 'لغو شده';
		}
		if ( 'voided' === $status && 'refund' === $operation ) {
			return 'مسترد شده';
		}

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
