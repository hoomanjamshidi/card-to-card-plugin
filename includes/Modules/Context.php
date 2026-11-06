<?php
/**
 * What a rule looks at: product categories, shipping methods, customer roles and the amount — read
 * from the cart (checkout) or from an order (payment, pay-for-order page).
 *
 * @package CardToCard
 */

namespace CardToCard\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * Cart / order facts.
 */
final class Context {

	/**
	 * Product category ids of the items, parents included.
	 *
	 * @var int[]
	 */
	public array $categories = array();

	/**
	 * Category ids (parents included) of each product.
	 *
	 * @var array<int, int[]>
	 */
	public array $items = array();

	/**
	 * Shipping methods: both «flat_rate» and «flat_rate:3».
	 *
	 * @var string[]
	 */
	public array $shipping = array();

	/**
	 * Customer roles («guest» when not logged in).
	 *
	 * @var string[]
	 */
	public array $roles = array();

	/**
	 * Amount.
	 *
	 * @var float
	 */
	public float $total = 0.0;

	/**
	 * Whether there was anything to read.
	 *
	 * @var bool
	 */
	public bool $known = false;

	/**
	 * The current checkout: the pay-for-order order, else the cart.
	 *
	 * @return self
	 */
	public static function current(): self {
		$pay   = function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ? absint( get_query_var( 'order-pay' ) ) : 0;
		$order = $pay ? wc_get_order( $pay ) : null;
		return $order instanceof \WC_Order ? self::from_order( $order ) : self::from_cart();
	}

	/**
	 * From the cart.
	 *
	 * @return self
	 */
	public static function from_cart(): self {
		$ctx  = new self();
		$cart = function_exists( 'WC' ) ? WC()->cart : null;
		if ( ! $cart ) {
			return $ctx;
		}
		$ids = array();
		foreach ( $cart->get_cart() as $item ) {
			$ids[] = (int) ( $item['product_id'] ?? 0 );
		}
		$ctx->fill_categories( $ids );
		$chosen        = WC()->session ? (array) WC()->session->get( 'chosen_shipping_methods', array() ) : array();
		$ctx->shipping = self::methods( array_map( 'strval', $chosen ) );
		$ctx->roles    = self::roles( get_current_user_id() );
		$ctx->total    = (float) $cart->get_total( 'edit' );
		$ctx->known    = (bool) $ids;
		return $ctx;
	}

	/**
	 * From an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return self
	 */
	public static function from_order( \WC_Order $order ): self {
		$ctx = new self();
		$ids = array();
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product ) {
				$ids[] = $item->get_product_id();
			}
		}
		$rates = array();
		foreach ( $order->get_shipping_methods() as $ship ) {
			$rates[] = $ship->get_method_id() . ( $ship->get_instance_id() ? ':' . $ship->get_instance_id() : '' );
		}
		$ctx->fill_categories( $ids );
		$ctx->shipping = self::methods( $rates );
		$ctx->roles    = self::roles( (int) $order->get_customer_id() );
		$ctx->total    = (float) $order->get_total();
		$ctx->known    = true;
		return $ctx;
	}

	/**
	 * Category ids of each product with their ancestors, and their union.
	 *
	 * @param int[] $product_ids Products (parents of variations).
	 */
	private function fill_categories( array $product_ids ): void {
		foreach ( array_unique( array_filter( $product_ids ) ) as $id ) {
			$cats = array();
			foreach ( wc_get_product_term_ids( $id, 'product_cat' ) as $term ) {
				$cats[] = (int) $term;
				foreach ( get_ancestors( (int) $term, 'product_cat', 'taxonomy' ) as $parent ) {
					$cats[] = (int) $parent;
				}
			}
			$this->items[] = array_values( array_unique( $cats ) );
		}
		$this->categories = $this->items ? array_values( array_unique( array_merge( ...$this->items ) ) ) : array();
	}

	/**
	 * Rate ids → method id and method:instance.
	 *
	 * @param string[] $rates Rate ids (flat_rate:3).
	 * @return string[]
	 */
	private static function methods( array $rates ): array {
		$out = array();
		foreach ( $rates as $rate ) {
			if ( '' === $rate ) {
				continue;
			}
			$parts = explode( ':', $rate );
			$out[] = $parts[0];
			if ( isset( $parts[1] ) ) {
				$out[] = $parts[0] . ':' . $parts[1];
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Roles of a user («guest» for 0).
	 *
	 * @param int $user_id User.
	 * @return string[]
	 */
	private static function roles( int $user_id ): array {
		if ( ! $user_id ) {
			return array( 'guest' );
		}
		$user = get_userdata( $user_id );
		return $user ? array_values( (array) $user->roles ) : array( 'guest' );
	}
}
