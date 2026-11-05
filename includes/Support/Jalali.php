<?php
/**
 * Jalali (Solar Hijri) ⇄ Gregorian conversion and formatting.
 *
 * Arithmetic algorithm (the widely used jdf / jalaali-js method): exact for the
 * whole 1-3000 Solar Hijri range and free of intl/ICU dependencies.
 *
 * @package CardToCard
 */

declare( strict_types=1 );

namespace CardToCard\Support;

/**
 * Calendar helpers. All methods are pure (no WordPress calls) so they are unit-testable.
 */
final class Jalali {

	/**
	 * Persian month names (Farvardin … Esfand).
	 */
	public const MONTHS = array(
		1  => 'فروردین',
		2  => 'اردیبهشت',
		3  => 'خرداد',
		4  => 'تیر',
		5  => 'مرداد',
		6  => 'شهریور',
		7  => 'مهر',
		8  => 'آبان',
		9  => 'آذر',
		10 => 'دی',
		11 => 'بهمن',
		12 => 'اسفند',
	);

	/**
	 * Weekday names indexed like PHP's date('w'): 0 = Sunday.
	 */
	public const WEEKDAYS = array( 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه' );

	/**
	 * Gregorian → Jalali.
	 *
	 * @param int $gy Gregorian year.
	 * @param int $gm Gregorian month (1-12).
	 * @param int $gd Gregorian day.
	 * @return array{0:int,1:int,2:int} [ year, month, day ]
	 */
	public static function to_jalali( int $gy, int $gm, int $gd ): array {
		$g_d_m = array( 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334 );
		$gy2   = $gm > 2 ? $gy + 1 : $gy;
		$days  = 355666 + ( 365 * $gy ) + intdiv( $gy2 + 3, 4 ) - intdiv( $gy2 + 99, 100 ) + intdiv( $gy2 + 399, 400 ) + $gd + $g_d_m[ $gm - 1 ];
		$jy    = -1595 + ( 33 * intdiv( $days, 12053 ) );
		$days %= 12053;
		$jy   += 4 * intdiv( $days, 1461 );
		$days %= 1461;
		if ( $days > 365 ) {
			$jy += intdiv( $days - 1, 365 );
			--$days;
			$days %= 365;
		}
		if ( $days < 186 ) {
			$jm = 1 + intdiv( $days, 31 );
			$jd = 1 + ( $days % 31 );
		} else {
			$jm = 7 + intdiv( $days - 186, 30 );
			$jd = 1 + ( ( $days - 186 ) % 30 );
		}
		return array( $jy, $jm, $jd );
	}

	/**
	 * Jalali → Gregorian.
	 *
	 * @param int $jy Jalali year.
	 * @param int $jm Jalali month (1-12).
	 * @param int $jd Jalali day.
	 * @return array{0:int,1:int,2:int} [ year, month, day ]
	 */
	public static function to_gregorian( int $jy, int $jm, int $jd ): array {
		$jy   += 1595;
		$days  = -355668 + ( 365 * $jy ) + ( intdiv( $jy, 33 ) * 8 ) + intdiv( ( $jy % 33 ) + 3, 4 ) + $jd + ( $jm < 7 ? ( $jm - 1 ) * 31 : ( ( $jm - 7 ) * 30 ) + 186 );
		$gy    = 400 * intdiv( $days, 146097 );
		$days %= 146097;
		if ( $days > 36524 ) {
			--$days;
			$gy   += 100 * intdiv( $days, 36524 );
			$days %= 36524;
			if ( $days >= 365 ) {
				++$days;
			}
		}
		$gy   += 4 * intdiv( $days, 1461 );
		$days %= 1461;
		if ( $days > 365 ) {
			$gy += intdiv( $days - 1, 365 );
			--$days;
			$days %= 365;
		}
		$gd    = $days + 1;
		$leap  = ( 0 === $gy % 4 && 0 !== $gy % 100 ) || 0 === $gy % 400;
		$month = array( 0, 31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 );
		for ( $gm = 1; $gm <= 12 && $gd > $month[ $gm ]; $gm++ ) {
			$gd -= $month[ $gm ];
		}
		return array( $gy, $gm, $gd );
	}

	/**
	 * Whether a Jalali date exists.
	 *
	 * @param int $jy Year.
	 * @param int $jm Month.
	 * @param int $jd Day.
	 * @return bool
	 */
	public static function is_valid( int $jy, int $jm, int $jd ): bool {
		if ( $jy < 1 || $jm < 1 || $jm > 12 || $jd < 1 ) {
			return false;
		}
		if ( $jm <= 6 ) {
			return $jd <= 31;
		}
		if ( $jm <= 11 ) {
			return $jd <= 30;
		}
		// Esfand has 30 days only in leap years: check by round-tripping day 30.
		if ( 30 === $jd ) {
			return array( $jy, 12, 30 ) === self::to_jalali( ...self::to_gregorian( $jy, 12, 30 ) );
		}
		return $jd <= 29;
	}

	/**
	 * "2026-12-21" → "1405/09/30" (Latin digits; callers localise digits for display).
	 *
	 * @param string $ymd Gregorian date.
	 * @return string Empty on invalid input.
	 */
	public static function from_gregorian_string( string $ymd ): string {
		if ( ! preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})/', $ymd, $m ) ) {
			return '';
		}
		[ $jy, $jm, $jd ] = self::to_jalali( (int) $m[1], (int) $m[2], (int) $m[3] );
		return sprintf( '%04d/%02d/%02d', $jy, $jm, $jd );
	}

	/**
	 * "۱۴۰۵/۹/۳۰" or "1405-09-30" → "2026-12-21".
	 *
	 * @param string $jalali Jalali date with any digits and / - . separators.
	 * @return string Empty on invalid input.
	 */
	public static function to_gregorian_string( string $jalali ): string {
		$jalali = self::latin_digits( trim( $jalali ) );
		if ( ! preg_match( '/^(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})$/', $jalali, $m ) ) {
			return '';
		}
		$jy = (int) $m[1];
		$jm = (int) $m[2];
		$jd = (int) $m[3];
		if ( ! self::is_valid( $jy, $jm, $jd ) ) {
			return '';
		}
		[ $gy, $gm, $gd ] = self::to_gregorian( $jy, $jm, $jd );
		return sprintf( '%04d-%02d-%02d', $gy, $gm, $gd );
	}

	/**
	 * Format a Unix timestamp in the Jalali calendar.
	 * Tokens: Y (1405), y (05), m (09), n (9), d (07), j (7), F (آذر), l (weekday), H, G, i, s.
	 * Escape a literal character with a backslash.
	 *
	 * @param string $format    Format string.
	 * @param int    $timestamp Unix timestamp.
	 * @param int    $offset    Seconds added before formatting (site timezone offset).
	 * @return string Latin digits.
	 */
	public static function format( string $format, int $timestamp, int $offset = 0 ): string {
		$ts               = $timestamp + $offset;
		[ $jy, $jm, $jd ] = self::to_jalali( (int) gmdate( 'Y', $ts ), (int) gmdate( 'n', $ts ), (int) gmdate( 'j', $ts ) );
		$map              = array(
			'Y' => (string) $jy,
			'y' => substr( (string) $jy, -2 ),
			'm' => sprintf( '%02d', $jm ),
			'n' => (string) $jm,
			'd' => sprintf( '%02d', $jd ),
			'j' => (string) $jd,
			'F' => self::MONTHS[ $jm ],
			'l' => self::WEEKDAYS[ (int) gmdate( 'w', $ts ) ],
			'H' => gmdate( 'H', $ts ),
			'G' => gmdate( 'G', $ts ),
			'i' => gmdate( 'i', $ts ),
			's' => gmdate( 's', $ts ),
		);
		$out              = '';
		$len              = strlen( $format );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $format[ $i ];
			if ( '\\' === $c && $i + 1 < $len ) {
				$out .= $format[ ++$i ];
				continue;
			}
			$out .= $map[ $c ] ?? $c;
		}
		return $out;
	}

	/**
	 * Current Jalali year.
	 *
	 * @param int $offset Timezone offset in seconds.
	 * @return int
	 */
	public static function year( int $offset = 0 ): int {
		return (int) self::format( 'Y', time(), $offset );
	}

	/**
	 * Convert Persian/Arabic-Indic digits to Latin.
	 *
	 * @param string $text Input.
	 * @return string
	 */
	public static function latin_digits( string $text ): string {
		return strtr( $text, array_combine( mb_str_split( '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩' ), str_split( '01234567890123456789' ) ) );
	}
}
