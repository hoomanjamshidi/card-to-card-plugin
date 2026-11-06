<?php
/**
 * One entry point for sending: picks the driver (off / log / Melipayamak pattern or line), writes the
 * send log and returns the result. Other drivers can be added with the `ctc_sms_send` filter.
 *
 * @package CardToCard
 */

namespace CardToCard\Sms;

use CardToCard\Settings;
use CardToCard\Support\Persian;

defined( 'ABSPATH' ) || exit;

/**
 * SMS sender.
 */
final class Sender {

	public const LOG = 'ctc_sms_log';

	/**
	 * Driver key: off | log | melipayamak.
	 *
	 * @return string
	 */
	public static function driver(): string {
		return (string) Settings::get( 'sms_driver', 'off' );
	}

	/**
	 * Melipayamak mode: pattern | line.
	 *
	 * @return string
	 */
	public static function mode(): string {
		return 'line' === Settings::get( 'mp_mode' ) ? 'line' : 'pattern';
	}

	/**
	 * Melipayamak client from the settings.
	 *
	 * @return Melipayamak
	 */
	public static function client(): Melipayamak {
		return new Melipayamak( (string) Settings::get( 'mp_user', '' ), (string) Settings::get( 'mp_pass', '' ) );
	}

	/**
	 * Send one message.
	 *
	 * @param string             $to   Mobile in any common format.
	 * @param string             $body Pattern code (pattern mode).
	 * @param array<int, string> $args Pattern variables in order (pattern mode).
	 * @param string             $text Full text (line mode and the log driver).
	 * @param string             $event Event key for the log.
	 * @return true|\WP_Error
	 */
	public static function send( string $to, string $body, array $args, string $text, string $event = '' ) {
		$mobile = Persian::mobile( $to );
		$driver = self::driver();
		$mode   = 'melipayamak' === $driver ? self::mode() : $driver;

		/**
		 * Short-circuit sending with your own provider. Return true or a WP_Error to skip ours.
		 *
		 * @param null|true|\WP_Error $result Result.
		 * @param string              $mobile Normalised number.
		 * @param string              $body   Pattern code.
		 * @param array<int, string>  $args   Pattern variables.
		 * @param string              $text   Full text.
		 * @param string              $event  Event key.
		 */
		$result = apply_filters( 'ctc_sms_send', null, $mobile, $body, $args, $text, $event );
		if ( null === $result ) {
			if ( '' === $mobile ) {
				$result = new \WP_Error( 'ctc_sms_mobile', __( 'شماره موبایل معتبر نیست.', 'card-to-card' ) );
			} elseif ( 'off' === $driver ) {
				$result = new \WP_Error( 'ctc_sms_off', __( 'پیامک خاموش است.', 'card-to-card' ) );
			} elseif ( 'melipayamak' === $driver && 'line' === $mode ) {
				$result = self::client()->line( $mobile, (string) Settings::get( 'mp_from', '' ), $text );
			} elseif ( 'melipayamak' === $driver ) {
				$result = self::client()->pattern( $mobile, $body, $args );
			} else {
				$result = self::log_driver( $mobile, $body, $args, $text );
			}
		}

		self::log(
			array(
				'event' => $event,
				'to'    => '' !== $mobile ? $mobile : Persian::latin( $to ),
				'mode'  => $mode,
				'body'  => $body,
				'text'  => self::log_text( $mode, $event, $args, $text ),
				'ok'    => ! is_wp_error( $result ),
				'msg'   => is_wp_error( $result ) ? $result->get_error_message() : '',
				'rec'   => is_string( $result ) ? $result : '',
			)
		);
		/**
		 * After an SMS attempt.
		 *
		 * @param true|\WP_Error $result Result.
		 * @param string         $mobile Number.
		 * @param string         $event  Event.
		 */
		do_action( 'ctc_sms_sent', is_wp_error( $result ) ? $result : true, $mobile, $event );
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Text kept in the send log: the full text, or for a pattern the pattern text filled with its
	 * variables (or the bare variables when the shop has not written the pattern text).
	 *
	 * @param string             $mode  line | log | pattern | off.
	 * @param string             $event Event key.
	 * @param array<int, string> $args  Variables.
	 * @param string             $text  Full text.
	 * @return string
	 */
	private static function log_text( string $mode, string $event, array $args, string $text ): string {
		if ( 'pattern' !== $mode ) {
			return $text;
		}
		$filled = isset( Events::all()[ $event ] ) ? Events::pattern_text( $event, $args ) : '';
		return '' !== $filled ? $filled : implode( ' ؛ ', $args );
	}

	/**
	 * Test driver: writes to debug.log, never sends. Refused on production sites so a shop never
	 * believes messages went out.
	 *
	 * @param string             $mobile Number.
	 * @param string             $body   Pattern.
	 * @param array<int, string> $args   Variables.
	 * @param string             $text   Text.
	 * @return true|\WP_Error
	 */
	private static function log_driver( string $mobile, string $body, array $args, string $text ) {
		if ( 'production' === wp_get_environment_type() ) {
			return new \WP_Error( 'ctc_sms_log_production', __( 'سرویس پیامک روی «آزمایشی» است؛ ملی‌پیامک را انتخاب کنید.', 'card-to-card' ) );
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the test driver exists for development.
		error_log( sprintf( '[card-to-card sms] to=%s body=%s args=%s text=%s', $mobile, '' !== $body ? $body : '-', wp_json_encode( $args, JSON_UNESCAPED_UNICODE ), $text ) );
		return true;
	}

	/**
	 * Connection test: credit (and optionally a real message to a number).
	 *
	 * @param string $to    Optional test number.
	 * @param string $event Event whose pattern / text is used for the test message.
	 * @return array{ok:bool, credit:float|null, message:string, sent:bool|null}
	 */
	public static function test( string $to = '', string $event = 'placed' ): array {
		$driver = self::driver();
		if ( 'melipayamak' !== $driver ) {
			return array(
				'ok'      => 'log' === $driver,
				'credit'  => null,
				'message' => 'log' === $driver ? __( 'حالت آزمایشی فعال است: پیامک‌ها فقط در debug.log نوشته می‌شوند.', 'card-to-card' ) : __( 'پیامک خاموش است.', 'card-to-card' ),
				'sent'    => null,
			);
		}
		$credit = self::client()->credit();
		if ( is_wp_error( $credit ) ) {
			return array(
				'ok'      => false,
				'credit'  => null,
				'message' => $credit->get_error_message(),
				'sent'    => null,
			);
		}
		$sent    = null;
		$message = __( 'اتصال به ملی‌پیامک برقرار است.', 'card-to-card' );
		if ( '' !== $to ) {
			$event  = isset( Events::all()[ $event ] ) ? $event : 'placed';
			$conf   = Events::conf( $event );
			$sample = Events::sample();
			$text   = '' !== trim( $conf['text'] ) ? Events::text( $event, $sample ) : sprintf( /* translators: %s: shop name */ __( 'پیامک آزمایشی افزونه کارت به کارت — %s', 'card-to-card' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
			$result = self::send( $to, $conf['body'], Events::args( $event, $sample ), $text, 'test' );
			$sent   = true === $result;
			if ( is_wp_error( $result ) ) {
				$message = $result->get_error_message();
			} else {
				$message = __( 'اتصال برقرار است و پیامک آزمایشی ارسال شد.', 'card-to-card' );
			}
		}
		return array(
			'ok'      => null === $sent ? true : $sent,
			'credit'  => (float) $credit,
			'message' => $message,
			'sent'    => $sent,
		);
	}

	/**
	 * Send log, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_log(): array {
		$log = get_option( self::LOG, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Add an attempt to the log (capped, not autoloaded).
	 *
	 * @param array<string, mixed> $entry Entry.
	 */
	public static function log( array $entry ): void {
		if ( ! Settings::get( 'sms_log' ) ) {
			return;
		}
		$log = self::get_log();
		array_unshift( $log, array_merge( array( 't' => time() ), $entry ) );
		update_option( self::LOG, array_slice( $log, 0, 200 ), false );
	}
}
