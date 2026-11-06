<?php
/**
 * Small WordPress-aware helpers: Jalali dates in the site timezone, amounts, currency words,
 * rate limiting and text tokens.
 *
 * @package CardToCard
 */

namespace CardToCard;

use CardToCard\Support\Jalali;
use CardToCard\Support\Persian;

defined( 'ABSPATH' ) || exit;

/**
 * Helpers.
 */
final class Util {

	/**
	 * Jalali date in the site timezone with Persian digits.
	 *
	 * @param string   $format    Tokens: Y y m n d j F l H G i s.
	 * @param int|null $timestamp Unix time (now when null).
	 * @return string
	 */
	public static function jdate( string $format, ?int $timestamp = null ): string {
		$timestamp = $timestamp ?? time();
		$offset    = (int) wp_timezone()->getOffset( new \DateTimeImmutable( '@' . $timestamp ) );
		return Persian::digits( Jalali::format( $format, $timestamp, $offset ) );
	}

	/**
	 * Amount with thousands separators and Persian digits: 1463000 → «۱٬۴۶۳٬۰۰۰».
	 *
	 * @param float|int|string $amount Amount.
	 * @return string
	 */
	public static function amount( $amount ): string {
		return Persian::digits( number_format( (float) $amount, (int) wc_get_price_decimals() ) );
	}

	/**
	 * Currency unit for people: تومان / ریال / the WooCommerce symbol.
	 *
	 * @return string
	 */
	public static function unit(): string {
		$map  = array(
			'IRT'  => __( 'تومان', 'card-to-card' ),
			'IRR'  => __( 'ریال', 'card-to-card' ),
			'IRHT' => __( 'هزار تومان', 'card-to-card' ),
			'IRHR' => __( 'هزار ریال', 'card-to-card' ),
		);
		$code = get_woocommerce_currency();
		return $map[ $code ] ?? html_entity_decode( get_woocommerce_currency_symbol( $code ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Amount in Persian words with the unit: «یک میلیون و ... تومان».
	 *
	 * @param float|int|string $amount Amount.
	 * @return string
	 */
	public static function amount_words( $amount ): string {
		return Persian::words( $amount ) . ' ' . self::unit();
	}

	/**
	 * Rate limit by hashed IP (+ extra key). True while under the limit.
	 *
	 * @param string $action Action.
	 * @param int    $limit  Hits per window.
	 * @param int    $window Seconds.
	 * @param string $extra  Extra key part (order id …).
	 * @return bool
	 */
	public static function rate_limit( string $action, int $limit, int $window, string $extra = '' ): bool {
		if ( self::limited( $action, $limit, $extra ) ) {
			return false;
		}
		self::hit( $action, $window, $extra );
		return true;
	}

	/**
	 * Whether a rate-limit bucket (hashed IP + action + extra) is full. Read only: nothing is counted.
	 *
	 * @param string $action Action.
	 * @param int    $limit  Hits allowed per window.
	 * @param string $extra  Extra key part (order id …).
	 * @return bool
	 */
	public static function limited( string $action, int $limit, string $extra = '' ): bool {
		return (int) get_transient( self::bucket( $action, $extra ) ) >= $limit;
	}

	/**
	 * Count one hit in a rate-limit bucket.
	 *
	 * @param string $action Action.
	 * @param int    $window Seconds the count is kept.
	 * @param string $extra  Extra key part (order id …).
	 */
	public static function hit( string $action, int $window, string $extra = '' ): void {
		$key = self::bucket( $action, $extra );
		set_transient( $key, (int) get_transient( $key ) + 1, $window );
	}

	/**
	 * Transient name of a rate-limit bucket.
	 *
	 * @param string $action Action.
	 * @param string $extra  Extra key part.
	 * @return string
	 */
	private static function bucket( string $action, string $extra ): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return 'ctc_rl_' . md5( $action . '|' . wp_hash( $ip ) . '|' . $extra );
	}

	/**
	 * Replace {tokens} in a panel text.
	 *
	 * @param string                $text   Text.
	 * @param array<string, string> $tokens Token (without braces) => value.
	 * @return string
	 */
	public static function tokens( string $text, array $tokens ): string {
		$map = array( '{shop}' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		foreach ( $tokens as $key => $value ) {
			$map[ '{' . $key . '}' ] = (string) $value;
		}
		return strtr( $text, $map );
	}

	/**
	 * Inline SVG icon (stroke icons, 24 px grid).
	 *
	 * @param string $name Icon name.
	 * @return string Safe markup.
	 */
	public static function icon( string $name ): string {
		$paths = array(
			'copy'    => '<rect x="9" y="9" width="12" height="12" rx="3"/><path d="M5 15V6a3 3 0 0 1 3-3h9"/>',
			'check'   => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
			'card'    => '<rect x="2.5" y="5" width="19" height="14" rx="3"/><path d="M2.5 10h19M6.5 15h4"/>',
			'upload'  => '<path d="M12 15V4m0 0-4.5 4.5M12 4l4.5 4.5"/><path d="M4 15v2.5A2.5 2.5 0 0 0 6.5 20h11a2.5 2.5 0 0 0 2.5-2.5V15"/>',
			'image'   => '<rect x="3" y="4" width="18" height="16" rx="3"/><circle cx="9" cy="9.5" r="1.8"/><path d="m21 16-5-5-9 9"/>',
			'clock'   => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
			'receipt' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6"/>',
			'alert'   => '<path d="M12 3 2.5 20h19z"/><path d="M12 10v4M12 17.2v.1"/>',
			'x'       => '<path d="m6 6 12 12M18 6 6 18"/>',
			'tick'    => '<circle cx="12" cy="12" r="9"/><path d="m8 12.5 3 3 5-6"/>',
			'chat'    => '<path d="M4 5h16v11H9l-5 4z"/>',
			'eye'     => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
			'shield'  => '<path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6z"/><path d="m9 12 2 2 4-4"/>',
		);
		if ( ! isset( $paths[ $name ] ) ) {
			return '';
		}
		return '<svg class="ctc-ic" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[ $name ] . '</svg>';
	}
}
