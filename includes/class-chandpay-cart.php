<?php
/**
 * Cart payload shared by TorobPay and SnappPay.
 *
 * amount = this part
 * external = the rest of the order
 * cart total - discount - external = amount
 *
 * @package Chandpay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Build the cart equation for a partial token.
 */
class Chandpay_Cart {

	/**
	 * Solve cart total, discount, and external source for one part.
	 *
	 * @param int $order_total Order total in rial.
	 * @param int $items_sum   Sum of line amounts in rial.
	 * @param int $shipping    Shipping in rial.
	 * @param int $tax         Tax in rial.
	 * @param int $part        This part in rial.
	 * @return array
	 */
	public static function from_components( $order_total, $items_sum, $shipping, $tax, $part ) {
		$order_total = (int) $order_total;
		$part        = (int) $part;
		$shipping    = (int) $shipping;
		$tax         = (int) $tax;
		$cart_total  = (int) $items_sum + $shipping + $tax;

		if ( $cart_total < $order_total ) {
			$shipping  += $order_total - $cart_total;
			$cart_total = $order_total;
		}

		$discount = $cart_total - $order_total;
		$external = $order_total - $part;

		return array(
			'cart_total' => $cart_total,
			'discount'   => $discount,
			'shipping'   => $shipping,
			'tax'        => $tax,
			'external'   => $external,
			'amount'     => $part,
		);
	}

	/**
	 * Full cart list for a WooCommerce order and one part.
	 *
	 * @param WC_Order $order    Order.
	 * @param int      $part_rial Part amount in rial.
	 * @param callable $mutate   Optional item mutator.
	 * @return array
	 */
	public static function build( WC_Order $order, $part_rial, $mutate = null ) {
		$currency    = $order->get_currency();
		$order_total = Chandpay_Money::to_rial( $order->get_total(), $currency );
		$items       = array();
		$items_sum   = 0;

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$qty = max( 1, (int) $item->get_quantity() );
			$unit = Chandpay_Money::to_rial( ( (float) $item->get_subtotal() ) / $qty, $currency );
			$row  = array(
				'id'       => self::line_id( $item, $item_id ),
				'name'     => self::clip( $item->get_name() ? $item->get_name() : ( '#' . $item_id ) ),
				'count'    => $qty,
				'amount'   => $unit,
				'category' => self::category( $item ),
			);
			if ( is_callable( $mutate ) ) {
				$row = call_user_func( $mutate, $row, $item );
			}
			$items[]    = $row;
			$items_sum += $unit * $qty;
		}

		$shipping = Chandpay_Money::to_rial( $order->get_shipping_total(), $currency );
		$tax_raw  = 0.0;
		foreach ( $order->get_items( 'tax' ) as $tax_item ) {
			$tax_raw += (float) $tax_item->get_tax_total() + (float) $tax_item->get_shipping_tax_total();
		}
		$tax = Chandpay_Money::to_rial( $tax_raw, $currency );

		if ( ! $items ) {
			$items[]   = array(
				'id'       => 'order-' . $order->get_id(),
				'name'     => self::clip( sprintf( 'Order %s', $order->get_order_number() ) ),
				'count'    => 1,
				'amount'   => $order_total,
				'category' => 'General',
			);
			$items_sum = $order_total;
			$shipping  = 0;
			$tax       = 0;
		}

		$solved = self::from_components( $order_total, $items_sum, $shipping, $tax, $part_rial );

		return array(
			'amount'   => $solved['amount'],
			'discount' => $solved['discount'],
			'external' => $solved['external'],
			'cart'     => array(
				'cartId'             => (string) $order->get_id(),
				'totalAmount'        => $solved['cart_total'],
				'taxAmount'          => $solved['tax'],
				'shippingAmount'     => $solved['shipping'],
				'isTaxIncluded'      => $solved['tax'] > 0,
				'isShipmentIncluded' => $solved['shipping'] > 0,
				'cartItems'          => $items,
			),
		);
	}

	/**
	 * Basket rows for a DigiPay ticket. Prices stay on the ticket amount.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function digipay_items( WC_Order $order ) {
		$items = array();
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$product = $item->get_product();
			$code    = ( $product && $product->get_sku() ) ? $product->get_sku() : self::line_id( $item, $item_id );
			$items[] = array(
				'productCode' => self::clip( (string) $code, 50 ),
				'brand'       => '',
				'productType' => 1,
				'count'       => max( 1, (int) $item->get_quantity() ),
			);
		}

		if ( ! $items ) {
			$items[] = array(
				'productCode' => 'order-' . $order->get_id(),
				'brand'       => '',
				'productType' => 1,
				'count'       => 1,
			);
		}

		return $items;
	}

	/**
	 * Stable line id.
	 *
	 * @param WC_Order_Item_Product $item    Line.
	 * @param int                   $item_id Item id.
	 * @return string
	 */
	private static function line_id( $item, $item_id ) {
		$product = $item->get_product();
		if ( $product ) {
			return (string) $product->get_id();
		}

		return 'item-' . $item_id;
	}

	/**
	 * First product category name, or a generic label.
	 *
	 * @param WC_Order_Item_Product $item Line.
	 * @return string
	 */
	private static function category( $item ) {
		$product = $item->get_product();
		if ( ! $product || ! function_exists( 'get_the_terms' ) ) {
			return 'General';
		}
		$terms = get_the_terms( $product->get_id(), 'product_cat' );
		if ( is_array( $terms ) && isset( $terms[0]->name ) && '' !== $terms[0]->name ) {
			return self::clip( $terms[0]->name );
		}

		return 'General';
	}

	/**
	 * Limit a string without requiring mbstring.
	 *
	 * @param string $value Text.
	 * @param int    $limit Max characters.
	 * @return string
	 */
	private static function clip( $value, $limit = 180 ) {
		$value = wp_strip_all_tags( (string) $value );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $limit );
		}

		return substr( $value, 0, $limit );
	}
}
