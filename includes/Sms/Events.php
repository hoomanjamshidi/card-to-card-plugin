<?php
/**
 * SMS events of the card-to-card flow. Each event has (panel «پیامک»): on/off and
 *  - pattern mode: a Melipayamak pattern code, its variable order («{name} {order} {total}» → {0} {1}
 *    {2}, each variable sent separately) and the pattern text itself (with {0} {1} …; a sample is
 *    given to copy into the Melipayamak panel, and the panel previews it — it is never sent);
 *  - line mode: a full text with {tokens}, written by the shop (a sample is given).
 * Messages are queued in the background (Action Scheduler) and sent once per order and event unless
 * the event may repeat (reminder, rejection).
 *
 * @package CardToCard
 */

namespace CardToCard\Sms;

use CardToCard\Orders;
use CardToCard\Settings;
use CardToCard\Support\Banks;
use CardToCard\Support\Persian;
use CardToCard\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Events, tokens and the order queue.
 */
final class Events {

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'ctc_sms_job', array( __CLASS__, 'job' ), 10, 3 );
	}

	/**
	 * Address of the payment page without the key: a pattern holds it as plain text and only {key}
	 * is a variable (Melipayamak refuses links inside variables, error -10).
	 *
	 * @return string
	 */
	public static function pay_base(): string {
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( '/' . Settings::get( 'slug', 'card-pay' ) . '/' );
		}
		return home_url( '/?ctc_pay=' );
	}

	/**
	 * Event definitions. «vars» + «pattern» are the sample pattern (variables in order and the text to
	 * register in Melipayamak), «text» the sample full text of line mode.
	 *
	 * @return array<string, array{label:string, to:string, vars:string, pattern:string, text:string, on:bool, desc:string}>
	 */
	public static function all(): array {
		$base   = self::pay_base();
		$events = array(
			'placed'        => array(
				'label'   => __( 'ثبت سفارش + لینک پرداخت', 'card-to-card' ),
				'to'      => 'customer',
				'vars'    => '{name} {order} {total} {deadline} {key}',
				/* translators: %s: payment page address without the key */
				'pattern' => sprintf( __( "{0} عزیز، سفارش {1} ثبت شد.\nمبلغ {2} را کارت به کارت کنید و رسید را تا {3} از این لینک بفرستید:\n%s{4}", 'card-to-card' ), $base ),
				'text'    => __( "{name} عزیز، سفارش {order} ثبت شد.\nمبلغ {total} را به کارت {card} ({bank}) واریز کنید و رسید را تا {deadline} بفرستید:\n{link}", 'card-to-card' ),
				'on'      => true,
				'desc'    => __( 'الگوهای خدماتی ملی‌پیامک لینکِ داخل متغیر را رد می‌کنند؛ برای همین آدرس صفحه پرداخت در خود متن الگو می‌آید و فقط {key} متغیر است — مثل متن نمونه.', 'card-to-card' ),
			),
			'received'      => array(
				'label'   => __( 'رسید دریافت شد (به مشتری)', 'card-to-card' ),
				'to'      => 'customer',
				'vars'    => '{name} {order}',
				'pattern' => __( '{0} عزیز، رسید سفارش {1} دریافت شد و به‌زودی بررسی می‌شود.', 'card-to-card' ),
				'text'    => __( '{name} عزیز، رسید سفارش {order} رسید و به‌زودی بررسی می‌شود.', 'card-to-card' ),
				'on'      => true,
				'desc'    => '',
			),
			'approved'      => array(
				'label'   => __( 'رسید تأیید شد', 'card-to-card' ),
				'to'      => 'customer',
				'vars'    => '{name} {order}',
				'pattern' => __( '{0} عزیز، پرداخت سفارش {1} تأیید شد و سفارشتان در حال آماده‌سازی است.', 'card-to-card' ),
				'text'    => __( '{name} عزیز، پرداخت سفارش {order} تأیید شد و سفارشتان در حال آماده‌سازی است.', 'card-to-card' ),
				'on'      => true,
				'desc'    => '',
			),
			'rejected'      => array(
				'label'   => __( 'رسید رد شد', 'card-to-card' ),
				'to'      => 'customer',
				'vars'    => '{name} {order} {reason} {key}',
				/* translators: %s: payment page address without the key */
				'pattern' => sprintf( __( "{0} عزیز، رسید سفارش {1} تأیید نشد.\nدلیل: {2}\nارسال دوباره رسید:\n%s{3}", 'card-to-card' ), $base ),
				'text'    => __( "{name} عزیز، رسید سفارش {order} تأیید نشد.\nدلیل: {reason}\nارسال دوباره رسید: {link}", 'card-to-card' ),
				'on'      => true,
				'desc'    => '',
			),
			'remind'        => array(
				'label'   => __( 'یادآوری ارسال رسید', 'card-to-card' ),
				'to'      => 'customer',
				'vars'    => '{name} {order} {deadline} {key}',
				/* translators: %s: payment page address without the key */
				'pattern' => sprintf( __( "{0} عزیز، مهلت ارسال رسید سفارش {1} تا {2} است:\n%s{3}", 'card-to-card' ), $base ),
				'text'    => __( "{name} عزیز، مهلت ارسال رسید سفارش {order} تا {deadline} است:\n{link}", 'card-to-card' ),
				'on'      => true,
				'desc'    => '',
			),
			'cancelled'     => array(
				'label'   => __( 'لغو خودکار (رسید نرسید)', 'card-to-card' ),
				'to'      => 'customer',
				'vars'    => '{name} {order}',
				'pattern' => __( '{0} عزیز، مهلت ارسال رسید سفارش {1} تمام شد و سفارش لغو شد.', 'card-to-card' ),
				'text'    => __( '{name} عزیز، مهلت ارسال رسید سفارش {order} تمام شد و سفارش لغو شد.', 'card-to-card' ),
				'on'      => true,
				'desc'    => '',
			),
			'admin_new'     => array(
				'label'   => __( 'سفارش کارت به کارت جدید (به مدیر)', 'card-to-card' ),
				'to'      => 'admin',
				'vars'    => '{order} {total}',
				'pattern' => __( 'سفارش کارت به کارت جدید {0} به مبلغ {1}', 'card-to-card' ),
				'text'    => __( 'سفارش کارت به کارت جدید {order} به مبلغ {total}', 'card-to-card' ),
				'on'      => false,
				'desc'    => '',
			),
			'admin_receipt' => array(
				'label'   => __( 'رسید جدید برای بررسی (به مدیر)', 'card-to-card' ),
				'to'      => 'admin',
				'vars'    => '{order} {total}',
				'pattern' => __( 'رسید سفارش {0} ({1}) برای بررسی رسید.', 'card-to-card' ),
				'text'    => __( 'رسید سفارش {order} ({total}) برای بررسی رسید.', 'card-to-card' ),
				'on'      => true,
				'desc'    => '',
			),
		);
		/**
		 * SMS events.
		 *
		 * @param array<string, array<string, mixed>> $events Events.
		 */
		return (array) apply_filters( 'ctc_sms_events', $events );
	}

	/**
	 * Token labels shown in the panel.
	 *
	 * @return array<string, string>
	 */
	public static function token_labels(): array {
		return array(
			'name'       => __( 'نام کامل مشتری', 'card-to-card' ),
			'first_name' => __( 'نام مشتری', 'card-to-card' ),
			'order'      => __( 'شماره سفارش', 'card-to-card' ),
			'total'      => __( 'مبلغ سفارش', 'card-to-card' ),
			'link'       => __( 'لینک صفحه پرداخت', 'card-to-card' ),
			'key'        => __( 'کلید لینک (بعد از مسیر صفحه پرداخت)', 'card-to-card' ),
			'deadline'   => __( 'پایان مهلت ارسال رسید', 'card-to-card' ),
			'card'       => __( 'شماره کارت', 'card-to-card' ),
			'bank'       => __( 'نام بانک', 'card-to-card' ),
			'holder'     => __( 'صاحب حساب', 'card-to-card' ),
			'reason'     => __( 'دلیل رد رسید', 'card-to-card' ),
			'shop'       => __( 'نام فروشگاه', 'card-to-card' ),
		);
	}

	/**
	 * Saved configuration of an event over its defaults.
	 *
	 * @param string $event Event key.
	 * @return array{on:bool, body:string, vars:string, pattern:string, text:string}
	 */
	public static function conf( string $event ): array {
		$def   = self::all()[ $event ] ?? array(
			'on'      => false,
			'vars'    => '',
			'pattern' => '',
			'text'    => '',
		);
		$saved = (array) ( ( (array) Settings::get( 'sms_events', array() ) )[ $event ] ?? array() );
		$pick  = static fn( string $key ): string => '' !== trim( (string) ( $saved[ $key ] ?? '' ) ) ? (string) $saved[ $key ] : (string) ( $def[ $key ] ?? '' );
		return array(
			'on'      => (bool) ( $saved['on'] ?? $def['on'] ),
			'body'    => (string) ( $saved['body'] ?? '' ),
			'vars'    => $pick( 'vars' ),
			// A pattern saved before its text existed keeps its variables: no sample text that
			// would not match them.
			'pattern' => '' !== trim( (string) ( $saved['pattern'] ?? '' ) ) || '' === trim( (string) ( $saved['vars'] ?? '' ) ) ? $pick( 'pattern' ) : '',
			'text'    => $pick( 'text' ),
		);
	}

	/**
	 * Token values of an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string, string>
	 */
	public static function tokens( \WC_Order $order ): array {
		$cards    = Orders::cards( $order );
		$card     = $cards[0] ?? array();
		$deadline = Orders::deadline( $order );
		$tokens   = array(
			'name'       => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'first_name' => $order->get_billing_first_name(),
			'order'      => (string) $order->get_order_number(),
			'total'      => Util::amount( $order->get_total() ) . ' ' . Util::unit(),
			'link'       => Orders::url( $order ),
			'key'        => Orders::key( $order ),
			'deadline'   => $deadline ? Util::jdate( 'j F، H:i', $deadline ) : '',
			'card'       => Banks::format_card( (string) ( $card['number'] ?? '' ) ),
			'bank'       => (string) ( $card['bank_name'] ?? '' ),
			'holder'     => (string) ( $card['holder'] ?? '' ),
			'reason'     => (string) $order->get_meta( '_ctc_reason' ),
			'shop'       => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'mobile'     => Persian::mobile( $order->get_billing_phone() ),
		);
		/**
		 * Order tokens for SMS.
		 *
		 * @param array<string, string> $tokens Tokens.
		 * @param \WC_Order             $order  Order.
		 */
		return (array) apply_filters( 'ctc_sms_tokens', $tokens, $order );
	}

	/**
	 * Sample tokens (panel preview and test send).
	 *
	 * @return array<string, string>
	 */
	public static function sample(): array {
		$cards = Settings::cards();
		$card  = $cards[0] ?? array();
		return array(
			'name'       => __( 'سارا محمدی', 'card-to-card' ),
			'first_name' => __( 'سارا', 'card-to-card' ),
			'order'      => '1024',
			'total'      => Util::amount( 1463000 ) . ' ' . Util::unit(),
			'link'       => home_url( '/' . Settings::get( 'slug', 'card-pay' ) . '/1024-0123456789abcdef/' ),
			'key'        => '1024-0123456789abcdef',
			'deadline'   => Util::jdate( 'j F، H:i', time() + DAY_IN_SECONDS ),
			'card'       => '' !== (string) ( $card['number'] ?? '' ) ? Banks::format_card( (string) $card['number'] ) : '6037 9975 9912 3456',
			'bank'       => '' !== (string) ( $card['bank_name'] ?? '' ) ? (string) $card['bank_name'] : __( 'بانک ملی ایران', 'card-to-card' ),
			'holder'     => '' !== (string) ( $card['holder'] ?? '' ) ? (string) $card['holder'] : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'reason'     => __( 'مبلغ واریزی کمتر از مبلغ سفارش است', 'card-to-card' ),
			'shop'       => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		);
	}

	/**
	 * Pattern variables of an event in order.
	 *
	 * @param string                $event  Event key.
	 * @param array<string, string> $tokens Token values.
	 * @return array<int, string>
	 */
	public static function args( string $event, array $tokens ): array {
		preg_match_all( '/\{([a-z0-9_]+)\}/', self::conf( $event )['vars'], $m );
		$out = array();
		foreach ( $m[1] as $name ) {
			$out[] = (string) ( $tokens[ $name ] ?? '' );
		}
		return $out;
	}

	/**
	 * Pattern text with its {0} {1} … filled (send log and preview; the pattern itself is sent by
	 * Melipayamak). '' when the shop has not written the pattern text.
	 *
	 * @param string             $event Event key.
	 * @param array<int, string> $args  Variables in order.
	 * @return string
	 */
	public static function pattern_text( string $event, array $args ): string {
		$pattern = self::conf( $event )['pattern'];
		if ( '' === trim( $pattern ) ) {
			return '';
		}
		return trim( (string) preg_replace_callback( '/\{(\d+)\}/', static fn( array $m ): string => (string) ( $args[ (int) $m[1] ] ?? $m[0] ), $pattern ) );
	}

	/**
	 * Full text of an event (line mode).
	 *
	 * @param string                $event  Event key.
	 * @param array<string, string> $tokens Token values.
	 * @return string
	 */
	public static function text( string $event, array $tokens ): string {
		$map = array();
		foreach ( $tokens as $key => $value ) {
			$map[ '{' . $key . '}' ] = (string) $value;
		}
		return trim( strtr( self::conf( $event )['text'], $map ) );
	}

	/**
	 * Admin numbers.
	 *
	 * @return string[]
	 */
	public static function admins(): array {
		$out = array();
		foreach ( (array) preg_split( '/[,،;\n]+/u', (string) Settings::get( 'sms_admins', '' ) ) as $number ) {
			$number = Persian::mobile( (string) $number );
			if ( '' !== $number ) {
				$out[ $number ] = $number;
			}
		}
		return array_values( $out );
	}

	/**
	 * Queue an order event (background; once per order and event unless $once is false).
	 *
	 * @param int    $order_id Order id.
	 * @param string $event    Event key.
	 * @param bool   $once     Skip when already sent for this order.
	 */
	public static function queue( int $order_id, string $event, bool $once = true ): void {
		if ( 'off' === Settings::get( 'sms_driver', 'off' ) || ! isset( self::all()[ $event ] ) || ! self::conf( $event )['on'] ) {
			return;
		}
		/**
		 * Whether to queue an order event.
		 *
		 * @param bool   $queue    Queue it.
		 * @param int    $order_id Order id.
		 * @param string $event    Event key.
		 */
		if ( ! apply_filters( 'ctc_sms_queue', true, $order_id, $event ) ) {
			return;
		}
		if ( function_exists( 'as_enqueue_async_action' ) && ! defined( 'CTC_SMS_SYNC' ) ) {
			as_enqueue_async_action( 'ctc_sms_job', array( $order_id, $event, $once ), Orders::GROUP );
		} else {
			self::job( $order_id, $event, $once );
		}
	}

	/**
	 * Send an order event (Action Scheduler job).
	 *
	 * @param int    $order_id Order id.
	 * @param string $event    Event key.
	 * @param bool   $once     Once per order.
	 */
	public static function job( $order_id, $event, $once = true ): void {
		$order = wc_get_order( (int) $order_id );
		$event = (string) $event;
		if ( ! $order instanceof \WC_Order || ! isset( self::all()[ $event ] ) ) {
			return;
		}
		$sent = array_filter( (array) $order->get_meta( '_ctc_sms_sent' ) );
		if ( $once && in_array( $event, $sent, true ) ) {
			return; // A status flipping back and forth texts the customer once.
		}
		$tokens = self::tokens( $order );
		$conf   = self::conf( $event );
		$to     = 'admin' === self::all()[ $event ]['to'] ? self::admins() : array( $tokens['mobile'] );
		if ( ! array_filter( $to ) ) {
			return;
		}
		$result = true;
		foreach ( $to as $number ) {
			$one = Sender::send( $number, $conf['body'], self::args( $event, $tokens ), self::text( $event, $tokens ), $event );
			if ( is_wp_error( $one ) ) {
				$result = $one;
			}
		}
		if ( true === $result ) {
			$sent[] = $event;
			$order->update_meta_data( '_ctc_sms_sent', array_values( array_unique( $sent ) ) );
			$order->save_meta_data();
		}
		$order->add_order_note(
			sprintf(
				/* translators: 1: event label, 2: result */
				__( 'پیامک «%1$s»: %2$s', 'card-to-card' ),
				self::all()[ $event ]['label'],
				true === $result ? __( 'ارسال شد', 'card-to-card' ) : $result->get_error_message()
			)
		);
	}
}
