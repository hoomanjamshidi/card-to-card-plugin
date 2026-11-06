<?php
/**
 * Module «تخفیف یا کارمزد»: a discount (negative fee) or a surcharge when card to card is chosen —
 * a percentage of the items (optionally with shipping) or a fixed amount, with a cap, a minimum and
 * rounding. Shown as a fee line at checkout (classic and blocks), stored on the order, and kept in
 * sync if the customer finally pays with another method.
 *
 * @package CardToCard
 */

namespace CardToCard\Modules;

use CardToCard\Gateway;
use CardToCard\Support\Persian;
use CardToCard\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Discount / fee for card to card.
 */
final class Fee extends Module {

	public const KEY  = 'ctc_fee';
	public const META = '_ctc_fee';

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'fee';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'تخفیف یا کارمزد کارت به کارت', 'card-to-card' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'مثلاً «۲٪ تخفیف با کارت به کارت» یا کارمزد ثابت؛ به‌صورت یک ردیف جدا در جمع سبد و سفارش نمایش داده می‌شود.', 'card-to-card' );
	}

	/**
	 * Icon.
	 *
	 * @return string
	 */
	public function icon(): string {
		return 'fee';
	}

	/**
	 * Defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return array(
			'type'     => 'discount', // discount | fee.
			'mode'     => 'percent', // percent | fixed.
			'percent'  => 2.0,
			'fixed'    => 0,
			'max'      => 0, // Cap of the amount (0 = none).
			'min'      => 0, // Only from this cart amount (0 = always).
			'round'    => 0, // Round to a multiple of this (e.g. 1000).
			'shipping' => false, // Percentage of items + shipping.
			'label'    => '',
			'badge'    => true, // «(۲٪ تخفیف)» after the gateway title.
		);
	}

	/**
	 * Choices.
	 *
	 * @return array<string, string[]>
	 */
	protected function choices(): array {
		return array(
			'type' => array( 'discount', 'fee' ),
			'mode' => array( 'percent', 'fixed' ),
		);
	}

	/**
	 * Sanitise: percentage 0–100 with two decimals.
	 *
	 * @param array<string, mixed> $input Raw.
	 * @return array<string, mixed>
	 */
	public function sanitize( array $input ): array {
		$out            = parent::sanitize( $input );
		$out['percent'] = round( min( 100.0, (float) $out['percent'] ), 2 );
		return $out;
	}

	/**
	 * Hooks.
	 */
	public function boot(): void {
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'cart_fee' ) );
		add_action( 'woocommerce_checkout_create_order_fee_item', array( $this, 'tag_item' ), 10, 3 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'on_create_order' ), 20 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'reconcile_and_save' ), 20 );
		add_action( 'ctc_process_payment', array( $this, 'reconcile_and_save' ) );
		add_filter( 'ctc_gateway_title', array( $this, 'title' ) );
		add_filter( 'ctc_refresh_on_method_change', array( $this, 'is_on' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'classic_refresh' ), 30 );
		add_action( 'woocommerce_blocks_loaded', array( $this, 'store_api' ) );
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			$this->store_api();
		}
	}

	/**
	 * Filter helper: true while on.
	 *
	 * @return bool
	 */
	public function is_on(): bool {
		return $this->enabled();
	}

	/**
	 * Signed amount for a base: negative for a discount, positive for a fee, 0 when not applicable.
	 *
	 * @param float $base Items (and shipping) amount.
	 * @return float
	 */
	public function amount( float $base ): float {
		if ( $base <= 0 ) {
			return 0.0;
		}
		$min = (int) $this->conf( 'min', 0 );
		if ( $min > 0 && $base < $min ) {
			return 0.0;
		}
		$raw = 'fixed' === $this->conf( 'mode' ) ? (float) $this->conf( 'fixed', 0 ) : $base * (float) $this->conf( 'percent', 0 ) / 100;
		$max = (int) $this->conf( 'max', 0 );
		if ( $max > 0 ) {
			$raw = min( $raw, (float) $max );
		}
		$step = (int) $this->conf( 'round', 0 );
		$raw  = $step > 0 ? round( $raw / $step ) * $step : round( $raw, (int) wc_get_price_decimals() );
		if ( 'discount' === $this->conf( 'type' ) ) {
			return -min( $raw, $base );
		}
		return $raw;
	}

	/**
	 * Line name: the panel's label or «تخفیف کارت به کارت» / «کارمزد کارت به کارت».
	 *
	 * @return string
	 */
	public function name(): string {
		$label = trim( (string) $this->conf( 'label', '' ) );
		if ( '' !== $label ) {
			return $label;
		}
		return 'discount' === $this->conf( 'type' ) ? __( 'تخفیف کارت به کارت', 'card-to-card' ) : __( 'کارمزد کارت به کارت', 'card-to-card' );
	}

	/**
	 * Short text for the gateway title: «۲٪ تخفیف» / «۵۰٬۰۰۰ تومان کارمزد».
	 *
	 * @return string
	 */
	public function badge(): string {
		$what = 'discount' === $this->conf( 'type' ) ? __( 'تخفیف', 'card-to-card' ) : __( 'کارمزد', 'card-to-card' );
		if ( 'fixed' === $this->conf( 'mode' ) ) {
			$fixed = (int) $this->conf( 'fixed', 0 );
			return $fixed > 0 ? Util::amount( $fixed ) . ' ' . Util::unit() . ' ' . $what : '';
		}
		$pct = (float) $this->conf( 'percent', 0 );
		return $pct > 0 ? Persian::digits( rtrim( rtrim( number_format( $pct, 2, '.', '' ), '0' ), '.' ) ) . '٪ ' . $what : '';
	}

	/**
	 * Gateway title with the badge.
	 *
	 * @param string $title Title.
	 * @return string
	 */
	public function title( $title ): string {
		$badge = $this->enabled() && $this->conf( 'badge' ) ? $this->badge() : '';
		return '' !== $badge ? $title . ' (' . $badge . ')' : (string) $title;
	}

	/**
	 * Cart: add the line when card to card is the chosen method.
	 *
	 * @param \WC_Cart $cart Cart.
	 */
	public function cart_fee( $cart ): void {
		if ( ! $this->enabled() || ! $cart instanceof \WC_Cart || ! WC()->session || Gateway::ID !== WC()->session->get( 'chosen_payment_method' ) ) {
			return;
		}
		$base = (float) $cart->get_cart_contents_total() + (float) $cart->get_cart_contents_tax();
		if ( $this->conf( 'shipping' ) ) {
			$base += (float) $cart->get_shipping_total() + (float) $cart->get_shipping_tax();
		}
		$amount = $this->amount( $base );
		if ( 0.0 !== $amount ) {
			$cart->fees_api()->add_fee(
				array(
					'id'      => self::KEY,
					'name'    => $this->name(),
					'amount'  => $amount,
					'taxable' => false,
				)
			);
		}
	}

	/**
	 * Mark our line when WooCommerce copies cart fees to the order.
	 *
	 * @param \WC_Order_Item_Fee $item    Item.
	 * @param string             $fee_key Cart fee id.
	 * @param object             $fee     Cart fee.
	 */
	public function tag_item( $item, $fee_key, $fee ): void {
		unset( $fee );
		if ( self::KEY === $fee_key && $item instanceof \WC_Order_Item_Fee ) {
			$item->add_meta_data( self::META, '1', true );
		}
	}

	/**
	 * Our fee lines on an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return \WC_Order_Item_Fee[]
	 */
	public static function items( \WC_Order $order ): array {
		$out = array();
		foreach ( $order->get_fees() as $id => $item ) {
			if ( $item instanceof \WC_Order_Item_Fee && $item->get_meta( self::META ) ) {
				$out[ $id ] = $item;
			}
		}
		return $out;
	}

	/**
	 * Make the order match its payment method: our line only on card-to-card orders; added when the
	 * cart missed it (pay-for-order, admin orders, a stale session). Totals recalculated, not saved.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool Whether anything changed.
	 */
	public function reconcile( $order ): bool {
		if ( ! $order instanceof \WC_Order || ! $this->enabled() ) {
			return false;
		}
		$ours  = Gateway::ID === $order->get_payment_method();
		$items = self::items( $order );
		if ( ! $ours && $items ) {
			foreach ( array_keys( $items ) as $id ) {
				$order->remove_item( $id );
			}
			$order->calculate_totals( false );
			return true;
		}
		if ( $ours && ! $items && ! $order->is_paid() ) {
			$base = (float) $order->get_subtotal() - (float) $order->get_discount_total() + (float) $order->get_cart_tax();
			if ( $this->conf( 'shipping' ) ) {
				$base += (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
			}
			$amount = $this->amount( $base );
			if ( 0.0 === $amount ) {
				return false;
			}
			$item = new \WC_Order_Item_Fee();
			$item->set_name( $this->name() );
			$item->set_amount( (string) $amount );
			$item->set_total( (string) $amount );
			$item->set_tax_status( 'none' );
			$item->add_meta_data( self::META, '1', true );
			$order->add_item( $item );
			$order->calculate_totals( false );
			return true;
		}
		return false;
	}

	/**
	 * Classic checkout: reconcile before WooCommerce saves the new order.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function on_create_order( $order ): void {
		$this->reconcile( $order );
	}

	/**
	 * Reconcile and save (Store API checkout, gateway).
	 *
	 * @param \WC_Order $order Order.
	 */
	public function reconcile_and_save( $order ): void {
		if ( $this->reconcile( $order ) ) {
			$order->save();
		}
	}

	/**
	 * Classic checkout: totals refresh when the payment method changes.
	 */
	public function classic_refresh(): void {
		if ( ! $this->enabled() || ! function_exists( 'is_checkout' ) || ! is_checkout() || ! wp_script_is( 'wc-checkout', 'enqueued' ) ) {
			return;
		}
		wp_add_inline_script( 'wc-checkout', 'jQuery(function($){$(document.body).on("change","input[name=\'payment_method\']",function(){$(document.body).trigger("update_checkout");});});' );
	}

	/**
	 * Block checkout: the script tells the Store API which method is chosen (extensionCartUpdate).
	 */
	public function store_api(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			return;
		}
		woocommerce_store_api_register_update_callback(
			array(
				'namespace' => 'ctc-method',
				'callback'  => static function ( $data ): void {
					if ( WC()->session ) {
						WC()->session->set( 'chosen_payment_method', sanitize_key( (string) ( $data['method'] ?? '' ) ) );
					}
				},
			)
		);
	}
}
