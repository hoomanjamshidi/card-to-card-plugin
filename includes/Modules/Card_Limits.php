<?php
/**
 * Module «سقف دریافت هر کارت»: a daily and a (Jalali) monthly cap per destination card. A card that
 * would go over its cap with this order is not given; when no card is left, card to card is hidden.
 *
 * The amount of an order counts on its card as soon as the card is given (so two customers cannot
 * both be sent to an almost-full card) and is released when the order is cancelled, fails or is
 * refunded (and counted again if it comes back). Counters live in one small option, by day and month.
 *
 * @package CardToCard
 */

namespace CardToCard\Modules;

use CardToCard\Orders;
use CardToCard\Settings;
use CardToCard\Support\Jalali;

defined( 'ABSPATH' ) || exit;

/**
 * Per-card caps.
 */
final class Card_Limits extends Module {

	public const OPTION = 'ctc_card_usage';
	public const META   = '_ctc_usage';

	/**
	 * Statuses that give the amount back to the card.
	 */
	private const RELEASE = array( 'cancelled', 'failed', 'refunded', 'trash' );

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'limits';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'سقف دریافت هر کارت', 'card-to-card' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'برای هر کارت سقف روزانه و ماهانه بگذارید؛ کارتی که پر شد خودکار کنار می‌رود و سفارش‌ها به کارت بعدی می‌روند.', 'card-to-card' );
	}

	/**
	 * Icon.
	 *
	 * @return string
	 */
	public function icon(): string {
		return 'limit';
	}

	/**
	 * Defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return array(
			'limits' => array(), // card id => { day, month }.
		);
	}

	/**
	 * Sanitise the caps.
	 *
	 * @param array<string, mixed> $input Raw.
	 * @return array<string, mixed>
	 */
	public function sanitize( array $input ): array {
		$out = array();
		foreach ( (array) ( $input['limits'] ?? array() ) as $card => $caps ) {
			$card = sanitize_key( (string) $card );
			if ( '' === $card || ! is_array( $caps ) ) {
				continue;
			}
			$day   = max( 0, (int) \CardToCard\Support\Persian::only_digits( (string) ( $caps['day'] ?? 0 ) ) );
			$month = max( 0, (int) \CardToCard\Support\Persian::only_digits( (string) ( $caps['month'] ?? 0 ) ) );
			if ( $day || $month ) {
				$out[ $card ] = array(
					'day'   => $day,
					'month' => $month,
				);
			}
		}
		return array( 'limits' => $out );
	}

	/**
	 * Hooks.
	 */
	public function boot(): void {
		add_filter( 'ctc_eligible_cards', array( $this, 'filter' ), 20, 3 );
		add_action( 'ctc_cards_assigned', array( $this, 'record' ), 10, 2 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'status_changed' ), 10, 4 );
	}

	/**
	 * Caps of a card.
	 *
	 * @param string $card Card id.
	 * @return array{day:int, month:int}
	 */
	public function caps( string $card ): array {
		$all  = (array) $this->conf( 'limits', array() );
		$caps = isset( $all[ $card ] ) && is_array( $all[ $card ] ) ? $all[ $card ] : array();
		return array(
			'day'   => (int) ( $caps['day'] ?? 0 ),
			'month' => (int) ( $caps['month'] ?? 0 ),
		);
	}

	/**
	 * Day (Gregorian, site timezone) and Jalali month keys of a time.
	 *
	 * @param int|null $time Unix time.
	 * @return array{0:string, 1:string}
	 */
	public static function keys( ?int $time = null ): array {
		$time   = $time ?? time();
		$offset = (int) wp_timezone()->getOffset( new \DateTimeImmutable( '@' . $time ) );
		return array( gmdate( 'Ymd', $time + $offset ), Jalali::format( 'Y-m', $time, $offset ) );
	}

	/**
	 * Received today / this month per card.
	 *
	 * @param string $card Card id.
	 * @return array{day:float, month:float}
	 */
	public static function usage( string $card ): array {
		[ $day, $month ] = self::keys();
		$all             = self::ledger();
		return array(
			'day'   => (float) ( $all['d'][ $day ][ $card ] ?? 0 ),
			'month' => (float) ( $all['m'][ $month ][ $card ] ?? 0 ),
		);
	}

	/**
	 * Counters.
	 *
	 * @return array{d: array<string, array<string, float>>, m: array<string, array<string, float>>}
	 */
	private static function ledger(): array {
		$all = get_option( self::OPTION, array() );
		$all = is_array( $all ) ? $all : array();
		return array(
			'd' => isset( $all['d'] ) && is_array( $all['d'] ) ? $all['d'] : array(),
			'm' => isset( $all['m'] ) && is_array( $all['m'] ) ? $all['m'] : array(),
		);
	}

	/**
	 * Add (or with a negative amount, give back) an amount; old days and months are dropped.
	 *
	 * @param string $card   Card id.
	 * @param string $day    Day key.
	 * @param string $month  Month key.
	 * @param float  $amount Amount.
	 */
	private static function add( string $card, string $day, string $month, float $amount ): void {
		$all = self::ledger();
		if ( $amount > 0 || isset( $all['d'][ $day ] ) ) {
			$all['d'][ $day ][ $card ] = max( 0.0, (float) ( $all['d'][ $day ][ $card ] ?? 0 ) + $amount );
		}
		if ( $amount > 0 || isset( $all['m'][ $month ] ) ) {
			$all['m'][ $month ][ $card ] = max( 0.0, (float) ( $all['m'][ $month ][ $card ] ?? 0 ) + $amount );
		}
		krsort( $all['d'] );
		krsort( $all['m'] );
		$all['d'] = array_slice( $all['d'], 0, 3, true );
		$all['m'] = array_slice( $all['m'], 0, 2, true );
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Drop cards that would go over a cap with this amount.
	 *
	 * @param array<int, array<string, mixed>> $cards  Cards.
	 * @param \WC_Order|null                   $order  Order.
	 * @param float                            $amount Amount.
	 * @return array<int, array<string, mixed>>
	 */
	public function filter( $cards, $order = null, $amount = 0.0 ): array {
		if ( ! $this->enabled() ) {
			return (array) $cards;
		}
		unset( $order );
		$out = array();
		foreach ( (array) $cards as $card ) {
			$id   = (string) ( $card['id'] ?? '' );
			$caps = $this->caps( $id );
			$used = self::usage( $id );
			if ( ( $caps['day'] > 0 && $used['day'] + (float) $amount > $caps['day'] ) || ( $caps['month'] > 0 && $used['month'] + (float) $amount > $caps['month'] ) ) {
				continue;
			}
			$out[] = $card;
		}
		return $out;
	}

	/**
	 * Count the order on the card it was given (the first one when several are shown).
	 *
	 * @param \WC_Order                        $order Order.
	 * @param array<int, array<string, mixed>> $cards Cards.
	 */
	public function record( $order, $cards ): void {
		if ( ! $this->enabled() || ! $order instanceof \WC_Order || ! $cards || $order->get_meta( self::META ) ) {
			return;
		}
		$card            = (string) ( $cards[0]['id'] ?? '' );
		$amount          = (float) $order->get_total();
		[ $day, $month ] = self::keys();
		self::add( $card, $day, $month, $amount );
		$order->update_meta_data(
			self::META,
			array(
				'card'   => $card,
				'amount' => $amount,
				'day'    => $day,
				'month'  => $month,
				'on'     => true,
			)
		);
		$order->save_meta_data();
	}

	/**
	 * Give the amount back on cancel / fail / refund; count it again if the order comes back.
	 *
	 * @param int            $order_id Order id.
	 * @param string         $from     Old status.
	 * @param string         $to       New status.
	 * @param \WC_Order|null $order    Order.
	 */
	public function status_changed( $order_id, $from, $to, $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order || ! Orders::is_ours( $order ) ) {
			return;
		}
		$usage = $order->get_meta( self::META );
		if ( ! is_array( $usage ) || empty( $usage['card'] ) ) {
			return;
		}
		$release = in_array( $to, self::RELEASE, true );
		if ( $release && ! empty( $usage['on'] ) ) {
			self::add( (string) $usage['card'], (string) $usage['day'], (string) $usage['month'], - (float) $usage['amount'] );
			$usage['on'] = false;
		} elseif ( ! $release && in_array( $from, self::RELEASE, true ) && empty( $usage['on'] ) ) {
			self::add( (string) $usage['card'], (string) $usage['day'], (string) $usage['month'], (float) $usage['amount'] );
			$usage['on'] = true;
		} else {
			return;
		}
		$order->update_meta_data( self::META, $usage );
		$order->save_meta_data();
	}

	/**
	 * Panel: today's and this month's amount per card.
	 *
	 * @return array<string, mixed>
	 */
	public function data(): array {
		$usage = array();
		foreach ( (array) Settings::get( 'cards', array() ) as $card ) {
			$id           = (string) ( $card['id'] ?? '' );
			$usage[ $id ] = self::usage( $id );
		}
		return array(
			'usage' => $usage,
			'month' => \CardToCard\Util::jdate( 'F Y' ),
		);
	}
}
