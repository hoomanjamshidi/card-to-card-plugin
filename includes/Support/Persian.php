<?php
/**
 * Persian text helpers: digits, amounts in words. Pure functions, unit-tested.
 *
 * @package CardToCard
 */

declare( strict_types=1 );

namespace CardToCard\Support;

/**
 * Persian digits and words.
 */
final class Persian {

	/**
	 * Latin → Persian digits, "," → "٬" (for numbers and prices shown to people).
	 *
	 * @param string|int|float $text Text.
	 * @return string
	 */
	public static function digits( $text ): string {
		return strtr(
			(string) $text,
			array(
				'0' => '۰',
				'1' => '۱',
				'2' => '۲',
				'3' => '۳',
				'4' => '۴',
				'5' => '۵',
				'6' => '۶',
				'7' => '۷',
				'8' => '۸',
				'9' => '۹',
				',' => '٬',
			)
		);
	}

	/**
	 * Persian / Arabic digits → Latin (for parsing what people type).
	 *
	 * @param string $text Input.
	 * @return string
	 */
	public static function latin( string $text ): string {
		return Jalali::latin_digits( $text );
	}

	/**
	 * Digits only, Persian/Arabic digits converted («۶۰۳۷-۹۹۷۵» → «60379975»).
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function only_digits( string $value ): string {
		return (string) preg_replace( '/\D+/', '', self::latin( $value ) );
	}

	/**
	 * A whole number in Persian words, as written on receipts and cheques:
	 * 1463000 → «یک میلیون و چهارصد و شصت و سه هزار».
	 *
	 * @param int|float|string $number Non-negative amount (decimals are dropped).
	 * @return string
	 */
	public static function words( $number ): string {
		$number = (int) floor( abs( (float) self::latin( (string) $number ) ) );
		if ( 0 === $number ) {
			return 'صفر';
		}
		$ones     = array( '', 'یک', 'دو', 'سه', 'چهار', 'پنج', 'شش', 'هفت', 'هشت', 'نه' );
		$teens    = array( 'ده', 'یازده', 'دوازده', 'سیزده', 'چهارده', 'پانزده', 'شانزده', 'هفده', 'هجده', 'نوزده' );
		$tens     = array( '', '', 'بیست', 'سی', 'چهل', 'پنجاه', 'شصت', 'هفتاد', 'هشتاد', 'نود' );
		$hundreds = array( '', 'یکصد', 'دویست', 'سیصد', 'چهارصد', 'پانصد', 'ششصد', 'هفتصد', 'هشتصد', 'نهصد' );
		$scales   = array( '', 'هزار', 'میلیون', 'میلیارد', 'هزار میلیارد' );

		$three = static function ( int $n ) use ( $ones, $teens, $tens, $hundreds ): string {
			$parts = array();
			if ( $n >= 100 ) {
				$parts[] = $hundreds[ intdiv( $n, 100 ) ];
				$n      %= 100;
			}
			if ( $n >= 20 ) {
				$parts[] = $tens[ intdiv( $n, 10 ) ];
				$n      %= 10;
			} elseif ( $n >= 10 ) {
				$parts[] = $teens[ $n - 10 ];
				$n       = 0;
			}
			if ( $n > 0 ) {
				$parts[] = $ones[ $n ];
			}
			return implode( ' و ', $parts );
		};

		$groups = array();
		$levels = count( $scales );
		for ( $i = 0; $number > 0 && $i < $levels; $i++ ) {
			$chunk = $number % 1000;
			if ( $chunk > 0 ) {
				$groups[] = trim( $three( $chunk ) . ' ' . $scales[ $i ] );
			}
			$number = intdiv( $number, 1000 );
		}
		return implode( ' و ', array_reverse( $groups ) );
	}

	/**
	 * Normalise an Iranian mobile number to 09xxxxxxxxx ('' when invalid).
	 * Accepts Persian digits, spaces, dashes and +98 / 0098 / 98 prefixes.
	 *
	 * @param string $raw Input.
	 * @return string
	 */
	public static function mobile( string $raw ): string {
		$latin  = trim( self::latin( $raw ) );
		$digits = self::only_digits( $latin );
		// A "+" number must be +98 and ten digits; a cut-off «+98 912 345 67» must not pass.
		if ( str_starts_with( $latin, '+' ) && ! ( str_starts_with( $digits, '98' ) && 12 === strlen( $digits ) ) ) {
			return '';
		}
		if ( str_starts_with( $digits, '0098' ) ) {
			$digits = '0' . substr( $digits, 4 );
		} elseif ( str_starts_with( $digits, '98' ) && 12 === strlen( $digits ) ) {
			$digits = '0' . substr( $digits, 2 );
		} elseif ( str_starts_with( $digits, '9' ) && 10 === strlen( $digits ) ) {
			$digits = '0' . $digits;
		}
		return preg_match( '/^09\d{9}$/', $digits ) ? $digits : '';
	}
}
