<?php
/**
 * Order flow of card-to-card payments.
 *
 * Checkout → «در انتظار رسید» (wc-ctc-receipt, stock held) → private payment page
 * (/card-pay/<id>-<key>/, no login: the key is an HMAC of the order key) → receipt uploaded →
 * «در انتظار بررسی رسید» (wc-ctc-review) → the shop approves (payment complete → processing) or
 * rejects with a reason (back to «در انتظار رسید» with a new deadline). No receipt before the
 * deadline → reminder, then automatic cancellation (stock returns).
 *
 * @package CardToCard
 */

namespace CardToCard;

use CardToCard\Support\Persian;

defined( 'ABSPATH' ) || exit;

/**
 * Statuses, payment link, deadline jobs, approve / reject.
 */
final class Orders {

	public const AWAITING = 'ctc-receipt';
	public const REVIEW   = 'ctc-review';
	public const GROUP    = 'card-to-card';

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_statuses' ) );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'order_statuses' ) );
		add_filter( 'woocommerce_order_is_pending_statuses', array( __CLASS__, 'unpaid_statuses' ) );
		add_filter( 'woocommerce_valid_order_statuses_for_cancel', array( __CLASS__, 'cancellable' ) );
		add_filter( 'woocommerce_order_is_paid_statuses', array( __CLASS__, 'not_paid' ), 99 );
		// Hold stock while the receipt is awaited / reviewed. WooCommerce flags the reduction on the
		// order, so the later payment_complete() does not reduce twice; a cancellation returns it.
		add_action( 'woocommerce_order_status_' . self::AWAITING, array( __CLASS__, 'hold_stock' ) );
		add_action( 'woocommerce_order_status_' . self::REVIEW, array( __CLASS__, 'hold_stock' ) );
		add_action( 'woocommerce_order_status_' . self::AWAITING, array( __CLASS__, 'on_awaiting' ), 10, 2 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 10, 3 );
		add_action( 'woocommerce_before_order_object_save', array( __CLASS__, 'guard_status' ) );
		add_action( 'ctc_remind', array( __CLASS__, 'remind' ) );
		add_action( 'ctc_expire', array( __CLASS__, 'expire' ) );
	}

	/**
	 * Reserve stock while the receipt is awaited / reviewed («رزرو موجودی» on). Off: stock is reduced
	 * only when the payment is approved (payment_complete).
	 *
	 * @param int $order_id Order id.
	 */
	public static function hold_stock( $order_id ): void {
		if ( Settings::get( 'hold_stock' ) ) {
			wc_maybe_reduce_stock_levels( (int) $order_id );
		}
	}

	/**
	 * Our statuses (without wc-, ≤ 20 characters with it).
	 *
	 * @return array<string, string>
	 */
	public static function statuses(): array {
		return array(
			self::AWAITING => __( 'در انتظار رسید', 'card-to-card' ),
			self::REVIEW   => __( 'در انتظار بررسی رسید', 'card-to-card' ),
		);
	}

	/**
	 * Register the statuses (orders list, HPOS and legacy posts).
	 */
	public static function register_statuses(): void {
		$counts = array(
			/* translators: %s: number of orders */
			self::AWAITING => _n_noop( 'در انتظار رسید <span class="count">(%s)</span>', 'در انتظار رسید <span class="count">(%s)</span>', 'card-to-card' ),
			/* translators: %s: number of orders */
			self::REVIEW   => _n_noop( 'در انتظار بررسی رسید <span class="count">(%s)</span>', 'در انتظار بررسی رسید <span class="count">(%s)</span>', 'card-to-card' ),
		);
		foreach ( self::statuses() as $status => $label ) {
			register_post_status(
				'wc-' . $status,
				array(
					'label'                     => $label,
					'public'                    => false,
					'exclude_from_search'       => false,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					'label_count'               => $counts[ $status ],
				)
			);
		}
	}

	/**
	 * Add our statuses right after «در انتظار پرداخت».
	 *
	 * @param array<string, string> $statuses Statuses.
	 * @return array<string, string>
	 */
	public static function order_statuses( $statuses ): array {
		$out = array();
		foreach ( (array) $statuses as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'wc-pending' === $key ) {
				foreach ( self::statuses() as $status => $ours ) {
					$out[ 'wc-' . $status ] = $ours;
				}
			}
		}
		foreach ( self::statuses() as $status => $ours ) {
			$out[ 'wc-' . $status ] = $out[ 'wc-' . $status ] ?? $ours;
		}
		return $out;
	}

	/**
	 * Our statuses are unpaid / open.
	 *
	 * @param string[] $statuses Statuses.
	 * @return string[]
	 */
	public static function unpaid_statuses( $statuses ): array {
		return array_values( array_unique( array_merge( (array) $statuses, array_keys( self::statuses() ) ) ) );
	}

	/**
	 * The customer may cancel while the receipt is awaited (My Account «لغو»).
	 *
	 * @param string[] $statuses Statuses.
	 * @return string[]
	 */
	public static function cancellable( $statuses ): array {
		if ( ! Settings::get( 'customer_cancel' ) ) {
			return (array) $statuses;
		}
		return array_values( array_unique( array_merge( (array) $statuses, array( self::AWAITING ) ) ) );
	}

	/**
	 * Never let another plugin treat our statuses as paid.
	 *
	 * @param string[] $statuses Statuses.
	 * @return string[]
	 */
	public static function not_paid( $statuses ): array {
		return array_values( array_diff( (array) $statuses, array_keys( self::statuses() ) ) );
	}

	/**
	 * Whether an order is paid by card to card.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public static function is_ours( \WC_Order $order ): bool {
		return Gateway::ID === $order->get_payment_method();
	}

	// ---------------------------------------------------------------------
	// Cards of an order
	// ---------------------------------------------------------------------

	/**
	 * Store the destination card(s) on the order, so the payment page always shows what the customer
	 * was given even if the panel changes later.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function assign_cards( \WC_Order $order ): void {
		$cards = self::eligible_cards( $order );
		if ( ! $cards ) {
			$cards = Settings::cards(); // Rules left nothing (e.g. an admin-made order): never leave the page empty.
		}
		if ( ! $cards ) {
			return;
		}
		$mode = (string) Settings::get( 'card_mode', 'all' );
		if ( 'first' === $mode ) {
			$cards = array( $cards[0] );
		} elseif ( 'random' === $mode ) {
			$cards = array( $cards[ array_rand( $cards ) ] );
		} elseif ( 'rotate' === $mode ) {
			$next = (int) get_option( 'ctc_rotate', 0 );
			update_option( 'ctc_rotate', $next + 1, false );
			$cards = array( $cards[ $next % count( $cards ) ] );
		}
		$keep = array( 'id', 'number', 'holder', 'sheba', 'account', 'bank', 'skin', 'color1', 'color2' );
		$out  = array();
		foreach ( $cards as $card ) {
			$out[] = array_intersect_key( $card, array_flip( $keep ) );
		}
		$order->update_meta_data( '_ctc_cards', $out );
		$order->save_meta_data();
		/**
		 * Destination card(s) were given to an order.
		 *
		 * @param \WC_Order                          $order Order.
		 * @param array<int, array<string, mixed>> $cards Cards (stored subset).
		 */
		do_action( 'ctc_cards_assigned', $order, $out );
	}

	/**
	 * Active cards that may be given for this order / amount (modules filter them: limits, rules).
	 *
	 * @param \WC_Order|null $order  Order (null = the current cart).
	 * @param float|null     $amount Amount (defaults to the order total).
	 * @return array<int, array<string, mixed>>
	 */
	public static function eligible_cards( ?\WC_Order $order = null, ?float $amount = null ): array {
		$amount = $amount ?? ( $order ? (float) $order->get_total() : 0.0 );
		/**
		 * Cards that may be shown for an order or the current cart.
		 *
		 * @param array<int, array<string, mixed>> $cards  Active cards.
		 * @param \WC_Order|null                    $order  Order, or null for the cart.
		 * @param float                            $amount Amount.
		 */
		return array_values( (array) apply_filters( 'ctc_eligible_cards', Settings::cards(), $order, $amount ) );
	}

	/**
	 * Cards of an order, resolved for display (falls back to the panel for older orders).
	 *
	 * @param \WC_Order $order Order.
	 * @return array<int, array<string, mixed>>
	 */
	public static function cards( \WC_Order $order ): array {
		$stored = $order->get_meta( '_ctc_cards' );
		if ( ! is_array( $stored ) || ! $stored ) {
			return Settings::cards();
		}
		// Numbers stay as given to the customer; the look (skin, colours, bank) follows the panel.
		$live = array();
		foreach ( (array) Settings::get( 'cards', array() ) as $card ) {
			$live[ (string) ( $card['id'] ?? '' ) ] = $card;
		}
		$look = array( 'skin', 'color1', 'color2', 'bank' );
		foreach ( $stored as $i => $card ) {
			$now = $live[ (string) ( $card['id'] ?? '' ) ] ?? null;
			if ( is_array( $now ) && (string) ( $now['number'] ?? '' ) === (string) ( $card['number'] ?? '' ) ) {
				$stored[ $i ] = array_merge( $card, array_intersect_key( $now, array_flip( $look ) ) );
			}
		}
		return array_map( array( Settings::class, 'resolve_card' ), $stored );
	}

	// ---------------------------------------------------------------------
	// Private payment link
	// ---------------------------------------------------------------------

	/**
	 * Link key: order id + 16 hex chars of an HMAC of the order key. Nothing is stored, the same link
	 * can be sent again and again, and it cannot be guessed from the order number.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	public static function key( \WC_Order $order ): string {
		$mac = hash_hmac( 'sha256', $order->get_id() . '|' . $order->get_order_key(), wp_salt( 'auth' ) );
		return $order->get_id() . '-' . substr( $mac, 0, 16 );
	}

	/**
	 * Token of the receipt form. Plays the nonce's role but is bound to the order, not to the
	 * visitor's login session or the clock, so a page left open for hours or restored by a mobile
	 * browser still submits. Posting the form needs the payment link anyway, which is the real secret.
	 *
	 * @param \WC_Order $order Order.
	 * @return string 20 hex characters.
	 */
	public static function form_token( \WC_Order $order ): string {
		return substr( hash_hmac( 'sha256', 'receipt|' . $order->get_id() . '|' . $order->get_order_key(), wp_salt( 'nonce' ) ), 0, 20 );
	}

	/**
	 * Payment page URL.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	public static function url( \WC_Order $order ): string {
		$key = self::key( $order );
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( user_trailingslashit( Settings::get( 'slug', 'card-pay' ) . '/' . $key ) );
		}
		return add_query_arg( 'ctc_pay', $key, home_url( '/' ) );
	}

	/**
	 * Order of a key (null when wrong).
	 *
	 * @param string $key Key.
	 * @return \WC_Order|null
	 */
	public static function from_key( string $key ): ?\WC_Order {
		if ( ! preg_match( '/^(\d+)-([a-f0-9]{16})$/', $key, $m ) ) {
			return null;
		}
		$order = wc_get_order( (int) $m[1] );
		if ( ! $order instanceof \WC_Order || ! self::is_ours( $order ) || ! hash_equals( self::key( $order ), $key ) ) {
			return null;
		}
		return $order;
	}

	/**
	 * «ارسال رسید» link ('' when no receipt is expected now).
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	public static function receipt_url( \WC_Order $order ): string {
		return Settings::get( 'pro' ) && self::is_ours( $order ) && $order->has_status( self::AWAITING ) ? self::url( $order ) : '';
	}

	// ---------------------------------------------------------------------
	// Deadline, reminder, automatic cancellation
	// ---------------------------------------------------------------------

	/**
	 * Deadline timestamp (0 = none).
	 *
	 * @param \WC_Order $order Order.
	 * @return int
	 */
	public static function deadline( \WC_Order $order ): int {
		return (int) $order->get_meta( '_ctc_deadline' );
	}

	/**
	 * Drop scheduled jobs of an order.
	 *
	 * @param int $order_id Order id.
	 */
	public static function unschedule( int $order_id ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'ctc_remind', array( $order_id ), self::GROUP );
			as_unschedule_all_actions( 'ctc_expire', array( $order_id ), self::GROUP );
		}
	}

	/**
	 * Entering «در انتظار رسید» (placed or rejected): new deadline and jobs; the first time, the
	 * «لینک پرداخت» SMS.
	 *
	 * @param int            $order_id Order id.
	 * @param \WC_Order|null $order    Order.
	 */
	public static function on_awaiting( $order_id, $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order || ! self::is_ours( $order ) ) {
			return;
		}
		$id    = $order->get_id();
		$hours = (int) Settings::get( 'deadline', 24 );
		if ( ! $order->get_meta( '_ctc_cards' ) ) {
			// Moved here by hand (an order made in the admin): give it its card now, like a checkout does.
			self::assign_cards( $order );
		}
		self::unschedule( $id );
		if ( $hours > 0 ) {
			$deadline = time() + $hours * HOUR_IN_SECONDS;
			$order->update_meta_data( '_ctc_deadline', (string) $deadline );
			$order->save_meta_data();
			if ( function_exists( 'as_schedule_single_action' ) ) {
				$remind = (int) Settings::get( 'remind', 6 );
				if ( $remind > 0 && $remind < $hours ) {
					as_schedule_single_action( $deadline - $remind * HOUR_IN_SECONDS, 'ctc_remind', array( $id ), self::GROUP );
				}
				as_schedule_single_action( $deadline, 'ctc_expire', array( $id ), self::GROUP );
			}
		} else {
			$order->delete_meta_data( '_ctc_deadline' );
			$order->save_meta_data();
		}
		Sms\Events::queue( $id, 'placed' ); // Once per order; a rejection sends its own message.
		Sms\Events::queue( $id, 'admin_new' );
	}

	/**
	 * Leaving our statuses: no reminder or cancellation any more.
	 *
	 * @param int    $order_id Order id.
	 * @param string $from     Old status.
	 * @param string $to       New status.
	 */
	public static function on_status_changed( $order_id, $from, $to ): void {
		if ( ( isset( self::statuses()[ $from ] ) && ! isset( self::statuses()[ $to ] ) ) || self::REVIEW === $to ) {
			self::unschedule( (int) $order_id );
		}
	}

	/**
	 * Reminder job.
	 *
	 * @param int $order_id Order id.
	 */
	public static function remind( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( $order instanceof \WC_Order && $order->has_status( self::AWAITING ) ) {
			Sms\Events::queue( $order->get_id(), 'remind', false );
			$order->add_order_note( __( 'یادآوری ارسال رسید برای مشتری فرستاده شد.', 'card-to-card' ) );
			/**
			 * Reminder sent for an order still awaiting its receipt.
			 *
			 * @param \WC_Order $order Order.
			 */
			do_action( 'ctc_reminded', $order );
		}
	}

	/**
	 * Cancellation job: deadline passed without a receipt.
	 *
	 * @param int $order_id Order id.
	 */
	public static function expire( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order || ! $order->has_status( self::AWAITING ) ) {
			return;
		}
		// A deadline moved by a later rejection leaves an old job behind: only the current one cancels.
		if ( self::deadline( $order ) > time() + MINUTE_IN_SECONDS ) {
			return;
		}
		$order->update_meta_data( '_ctc_expired', (string) time() );
		$order->update_status( 'failed' === Settings::get( 'expire_status' ) ? 'failed' : 'cancelled', __( 'مهلت ارسال رسید کارت به کارت تمام شد؛ سفارش خودکار لغو شد.', 'card-to-card' ) );
		// «ناموفق» does not return stock by itself (only «لغو» does).
		if ( 'failed' === Settings::get( 'expire_status' ) ) {
			wc_maybe_increase_stock_levels( $order->get_id() );
		}
		Sms\Events::queue( $order->get_id(), 'cancelled' );
	}

	// ---------------------------------------------------------------------
	// Strict mode
	// ---------------------------------------------------------------------

	/**
	 * Set while the shop approves a receipt, so the guard lets the order move on.
	 *
	 * @param bool|null $set New value.
	 * @return bool
	 */
	public static function approving( ?bool $set = null ): bool {
		static $on = false;
		if ( null !== $set ) {
			$on = $set;
		}
		return $on;
	}

	/**
	 * Strict mode: an order whose receipt is not approved never becomes «در حال انجام» or «تکمیل»
	 * (manual change, bulk action, REST, other plugins). The change is undone before saving.
	 *
	 * @param \WC_Order $order Order being saved.
	 */
	public static function guard_status( $order ): void {
		if ( ! $order instanceof \WC_Order || ! Settings::get( 'pro' ) || ! Settings::get( 'strict' ) || self::approving() ) {
			return;
		}
		$changes = $order->get_changes();
		$from    = (string) ( $order->get_data()['status'] ?? '' );
		$to      = (string) ( $changes['status'] ?? '' );
		if ( ! isset( self::statuses()[ $from ] ) || ! in_array( $to, array( 'processing', 'completed' ), true ) || $order->get_meta( '_ctc_approved' ) ) {
			return;
		}
		$order->set_status( $from );
		if ( empty( $order->get_data()['date_paid'] ) ) {
			$order->set_date_paid( null );
		}
		$order->add_order_note( __( 'تغییر وضعیت انجام نشد: رسید کارت به کارت هنوز تأیید نشده است. از جعبه «رسید کارت به کارت» تأیید کنید.', 'card-to-card' ) );
		if ( is_admin() && get_current_user_id() ) {
			set_transient( 'ctc_guard_' . get_current_user_id(), 1, MINUTE_IN_SECONDS );
		}
	}

	// ---------------------------------------------------------------------
	// Receipts: approve / reject
	// ---------------------------------------------------------------------

	/**
	 * Receipts sent for an order, oldest first.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<int, array<string, mixed>>
	 */
	public static function receipts( \WC_Order $order ): array {
		$list = $order->get_meta( '_ctc_receipts' );
		return is_array( $list ) ? array_values( $list ) : array();
	}

	/**
	 * Approve the latest receipt: payment complete (→ processing), transaction id = bank reference.
	 *
	 * @param \WC_Order $order Order.
	 * @return true|\WP_Error
	 */
	public static function approve( \WC_Order $order ) {
		if ( ! self::is_ours( $order ) || ! $order->has_status( array( self::AWAITING, self::REVIEW, 'on-hold', 'pending' ) ) ) {
			return new \WP_Error( 'ctc_state', __( 'این سفارش در وضعیت قابل تأیید نیست.', 'card-to-card' ) );
		}
		$receipts = self::receipts( $order );
		$last     = count( $receipts ) - 1;
		$ref      = '';
		if ( $last >= 0 ) {
			$receipts[ $last ]['state'] = 'approved';
			$receipts[ $last ]['by']    = get_current_user_id();
			$receipts[ $last ]['at']    = time();
			$ref                        = (string) ( $receipts[ $last ]['ref'] ?? '' );
			$order->update_meta_data( '_ctc_receipts', $receipts );
		}
		$order->update_meta_data( '_ctc_approved', (string) time() );
		$order->save();

		$allow  = static fn( $statuses ): array => array_merge( (array) $statuses, array_keys( self::statuses() ) );
		$target = (string) Settings::get( 'paid_status', 'auto' );
		$status = static fn( $status ) => 'auto' === $target ? $status : $target;
		add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', $allow );
		add_filter( 'woocommerce_payment_complete_order_status', $status, 99 );
		self::approving( true );
		$order->add_order_note( __( 'رسید کارت به کارت تأیید شد.', 'card-to-card' ), (int) (bool) Settings::get( 'customer_note' ), true );
		$order->payment_complete( $ref );
		self::approving( false );
		remove_filter( 'woocommerce_payment_complete_order_status', $status, 99 );
		remove_filter( 'woocommerce_valid_order_statuses_for_payment_complete', $allow );

		Sms\Events::queue( $order->get_id(), 'approved' );
		/**
		 * A receipt was approved.
		 *
		 * @param \WC_Order $order Order.
		 */
		do_action( 'ctc_receipt_approved', $order );
		return true;
	}

	/**
	 * Reject the latest receipt: back to «در انتظار رسید» with a new deadline.
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $reason Reason shown to the customer.
	 * @return true|\WP_Error
	 */
	public static function reject( \WC_Order $order, string $reason ) {
		if ( ! self::is_ours( $order ) || ! $order->has_status( self::REVIEW ) ) {
			return new \WP_Error( 'ctc_state', __( 'فقط رسیدِ در انتظار بررسی را می‌توان رد کرد.', 'card-to-card' ) );
		}
		$reason   = trim( $reason );
		$receipts = self::receipts( $order );
		$last     = count( $receipts ) - 1;
		if ( $last >= 0 ) {
			$receipts[ $last ]['state']  = 'rejected';
			$receipts[ $last ]['reason'] = $reason;
			$receipts[ $last ]['by']     = get_current_user_id();
			$receipts[ $last ]['at']     = time();
			$order->update_meta_data( '_ctc_receipts', $receipts );
		}
		$order->update_meta_data( '_ctc_reason', $reason );
		/* translators: %s: reason */
		$order->add_order_note( sprintf( __( 'رسید کارت به کارت رد شد. دلیل: %s', 'card-to-card' ), '' !== $reason ? $reason : '—' ), (int) (bool) Settings::get( 'customer_note' ), true );
		$order->update_status( self::AWAITING );
		Sms\Events::queue( $order->get_id(), 'rejected', false );
		/**
		 * A receipt was rejected.
		 *
		 * @param \WC_Order $order  Order.
		 * @param string    $reason Reason.
		 */
		do_action( 'ctc_receipt_rejected', $order, $reason );
		return true;
	}

	/**
	 * State of the payment page.
	 *
	 * @param \WC_Order $order Order.
	 * @return string pay | review | paid | cancelled
	 */
	public static function page_state( \WC_Order $order ): string {
		if ( $order->is_paid() || $order->get_meta( '_ctc_approved' ) ) {
			return 'paid';
		}
		if ( $order->has_status( self::REVIEW ) ) {
			return 'review';
		}
		if ( $order->has_status( array( self::AWAITING, 'on-hold', 'pending' ) ) ) {
			return 'pay';
		}
		return 'cancelled';
	}

	/**
	 * Order numbers with Persian digits for texts.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string, string>
	 */
	public static function text_tokens( \WC_Order $order ): array {
		$deadline = self::deadline( $order );
		return array(
			'first_name' => $order->get_billing_first_name(),
			'name'       => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'order'      => (string) $order->get_order_number(),
			'total'      => Util::amount( $order->get_total() ) . ' ' . Util::unit(),
			'deadline'   => $deadline ? Util::jdate( 'j F، ساعت H:i', $deadline ) : __( 'هر چه زودتر', 'card-to-card' ),
		);
	}

	/**
	 * Digits of a typed reference / last-four (Persian digits accepted).
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function digits( string $value ): string {
		return Persian::only_digits( $value );
	}
}
