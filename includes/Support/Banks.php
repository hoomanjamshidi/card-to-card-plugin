<?php
/**
 * Iranian banks: names, card BINs, IBAN bank codes, brand colours, plus card / IBAN validation and
 * formatting. Pure (no WordPress calls) so it is unit-tested and shared with the admin app as JSON.
 *
 * Formats:
 *  - Shetab card: 16 digits, Luhn check, shown 4-4-4-4 left to right;
 *  - IBAN (شبا): «IR» + 24 digits, ISO 7064 mod-97 check; digits 5–7 are the bank code.
 *
 * Colours are a gradient that evokes each bank's own card (not an official palette).
 *
 * @package CardToCard
 */

declare( strict_types=1 );

namespace CardToCard\Support;

/**
 * Bank registry and number helpers.
 */
final class Banks {

	/**
	 * Every bank: slug => [ name, bins, iban codes, gradient (3 stops), ink, accent ].
	 *
	 * @return array<string, array{name:string, bins:string[], codes:string[], colors:string[], ink:string, accent:string}>
	 */
	public static function all(): array {
		static $banks = null;
		if ( null !== $banks ) {
			return $banks;
		}
		$b     = static fn( string $name, array $bins, array $codes, array $colors, string $ink = '#ffffff', string $accent = '#f5d27a' ): array => array(
			'name'   => $name,
			'bins'   => $bins,
			'codes'  => $codes,
			'colors' => $colors,
			'ink'    => $ink,
			'accent' => $accent,
		);
		$banks = array(
			'melli'          => $b( 'ملی ایران', array( '603799' ), array( '017' ), array( '#0b2f6b', '#14468f', '#0a2350' ), '#ffffff', '#e7c66b' ),
			'sepah'          => $b( 'سپه', array( '589210' ), array( '015' ), array( '#0a4b8f', '#1767b5', '#06325e' ), '#ffffff', '#f2c230' ),
			'mellat'         => $b( 'ملت', array( '610433', '991975' ), array( '012' ), array( '#b5122e', '#e0283f', '#7d0b1f' ), '#ffffff', '#ffd27d' ),
			'tejarat'        => $b( 'تجارت', array( '627353', '585983' ), array( '018' ), array( '#0f2557', '#27479b', '#0a1a3d' ), '#ffffff', '#8fb7ff' ),
			'saderat'        => $b( 'صادرات ایران', array( '603769' ), array( '019' ), array( '#163a7a', '#2f62c2', '#0d2452' ), '#ffffff', '#ffd659' ),
			'keshavarzi'     => $b( 'کشاورزی', array( '603770', '639217' ), array( '016' ), array( '#0d6b35', '#1c9a4e', '#074822' ), '#ffffff', '#f6d85a' ),
			'maskan'         => $b( 'مسکن', array( '628023' ), array( '014' ), array( '#e36d12', '#f39324', '#a8470a' ), '#ffffff', '#fff1c2' ),
			'refah'          => $b( 'رفاه کارگران', array( '589463' ), array( '013' ), array( '#0a4596', '#1f6fd1', '#062c63' ), '#ffffff', '#ffd25e' ),
			'tosee-saderat'  => $b( 'توسعه صادرات', array( '627648', '207177' ), array( '020' ), array( '#0b6b4a', '#19976a', '#06452f' ), '#ffffff', '#d6f5a3' ),
			'sanat-madan'    => $b( 'صنعت و معدن', array( '627961' ), array( '011' ), array( '#1a2f5e', '#30508f', '#0d1b3a' ), '#ffffff', '#c9d6ff' ),
			'postbank'       => $b( 'پست بانک', array( '627760' ), array( '021' ), array( '#0b7a3e', '#22a35a', '#055129' ), '#ffffff', '#ffe066' ),
			'tosee-taavon'   => $b( 'توسعه تعاون', array( '502908' ), array( '022' ), array( '#036b6b', '#0c9696', '#024545' ), '#ffffff', '#ffd27d' ),
			'karafarin'      => $b( 'کارآفرین', array( '627488', '502910' ), array( '053' ), array( '#0d7a43', '#16a35b', '#06502a' ), '#ffffff', '#c8f2d8' ),
			'parsian'        => $b( 'پارسیان', array( '622106', '639194', '627884' ), array( '054' ), array( '#7a1430', '#a82347', '#4d0a1d' ), '#ffffff', '#f3c77a' ),
			'eghtesad-novin' => $b( 'اقتصاد نوین', array( '627412' ), array( '055' ), array( '#5a2386', '#7f3bb5', '#391458' ), '#ffffff', '#f0c6ff' ),
			'saman'          => $b( 'سامان', array( '621986' ), array( '056' ), array( '#0072bc', '#17a2e6', '#004b80' ), '#ffffff', '#c8ecff' ),
			'pasargad'       => $b( 'پاسارگاد', array( '502229', '639347' ), array( '057' ), array( '#1b1b1b', '#3a3a3a', '#0c0c0c' ), '#ffd200', '#ffd200' ),
			'sarmayeh'       => $b( 'سرمایه', array( '639607' ), array( '058' ), array( '#3b4650', '#5d6b78', '#232a31' ), '#ffffff', '#e1c48a' ),
			'sina'           => $b( 'سینا', array( '639346' ), array( '059' ), array( '#0d4f9e', '#2a7ad6', '#073468' ), '#ffffff', '#bfe0ff' ),
			'mehr-iran'      => $b( 'قرض‌الحسنه مهر ایران', array( '606373' ), array( '060' ), array( '#138a43', '#27b35f', '#0a5c2b' ), '#ffffff', '#ffe28a' ),
			'shahr'          => $b( 'شهر', array( '502806', '504706' ), array( '061' ), array( '#c8102e', '#ea2a3f', '#8a0a1e' ), '#ffffff', '#ffe0a3' ),
			'ayandeh'        => $b( 'آینده', array( '636214' ), array( '062' ), array( '#5b3a1e', '#8a5c33', '#3a2410' ), '#ffffff', '#f4cf8c' ),
			'ansar'          => $b( 'انصار', array( '627381' ), array( '063' ), array( '#0b5f5a', '#13867f', '#063d3a' ), '#ffffff', '#ffd27d' ),
			'gardeshgari'    => $b( 'گردشگری', array( '505416' ), array( '064' ), array( '#b8321a', '#e2572a', '#7c1f10' ), '#ffffff', '#ffe0b0' ),
			'hekmat'         => $b( 'حکمت ایرانیان', array( '636949' ), array( '065' ), array( '#2b3f7a', '#4361b0', '#1a2750' ), '#ffffff', '#ffd27d' ),
			'dey'            => $b( 'دی', array( '502938' ), array( '066' ), array( '#00727d', '#0aa0ad', '#004a52' ), '#ffffff', '#c7f6ff' ),
			'iran-zamin'     => $b( 'ایران زمین', array( '505785' ), array( '069' ), array( '#4b2585', '#6c3fb8', '#2f1556' ), '#ffffff', '#ffd27d' ),
			'resalat'        => $b( 'قرض‌الحسنه رسالت', array( '504172' ), array( '070' ), array( '#00897b', '#14b3a3', '#005a51' ), '#ffffff', '#d6fff8' ),
			'khavar-mianeh'  => $b( 'خاورمیانه', array( '585947' ), array( '078' ), array( '#1d1d1f', '#3a3a3e', '#0d0d0e' ), '#ff8a1f', '#ff8a1f' ),
			'ghavamin'       => $b( 'قوامین', array( '639599' ), array( '052' ), array( '#0b5d2f', '#168443', '#063c1e' ), '#ffffff', '#ffd27d' ),
			'markazi'        => $b( 'مرکزی', array( '636795' ), array( '010' ), array( '#1c2a52', '#2f4482', '#101935' ), '#ffffff', '#e7c66b' ),
			'iran-venezuela' => $b( 'ایران و ونزوئلا', array( '581874' ), array( '095' ), array( '#7a1f2b', '#a83242', '#4f121b' ), '#ffffff', '#ffd27d' ),
			'noor'           => $b( 'موسسه نور', array( '507677' ), array(), array( '#0f6e64', '#1a9a8b', '#084840' ), '#ffffff', '#ffe9a6' ),
			'tosee'          => $b( 'موسسه توسعه', array( '628157' ), array(), array( '#1f4e79', '#2f72b0', '#123150' ), '#ffffff', '#cfe6ff' ),
			'melall'         => $b( 'موسسه ملل', array( '606256' ), array(), array( '#00806e', '#12a892', '#005446' ), '#ffffff', '#d8fff3' ),
			'kosar'          => $b( 'موسسه کوثر', array( '505801' ), array(), array( '#1e5b8a', '#2f80bd', '#123a59' ), '#ffffff', '#ffe28a' ),
			'mehr-eghtesad'  => $b( 'مهر اقتصاد', array( '639370' ), array(), array( '#0e6b3a', '#1c9152', '#074424' ), '#ffffff', '#ffe28a' ),
		);
		return $banks;
	}

	/**
	 * Neutral card for an unknown bank.
	 *
	 * @return array{name:string, bins:string[], codes:string[], colors:string[], ink:string, accent:string}
	 */
	public static function unknown(): array {
		return array(
			'name'   => '',
			'bins'   => array(),
			'codes'  => array(),
			'colors' => array( '#1f2433', '#363d52', '#11141d' ),
			'ink'    => '#ffffff',
			'accent' => '#d9dde8',
		);
	}

	/**
	 * Bank slug from a card number (first six digits) or, failing that, an IBAN bank code.
	 *
	 * @param string $card  Card number (any digits / separators).
	 * @param string $sheba IBAN.
	 * @return string Slug or ''.
	 */
	public static function detect( string $card, string $sheba = '' ): string {
		$bin  = substr( Persian::only_digits( $card ), 0, 6 );
		$iban = self::sheba( $sheba );
		$code = '' !== $iban ? substr( $iban, 4, 3 ) : '';
		foreach ( self::all() as $slug => $bank ) {
			if ( 6 === strlen( $bin ) && in_array( $bin, $bank['bins'], true ) ) {
				return $slug;
			}
		}
		if ( '' !== $code ) {
			foreach ( self::all() as $slug => $bank ) {
				if ( in_array( $code, $bank['codes'], true ) ) {
					return $slug;
				}
			}
		}
		return '';
	}

	/**
	 * One bank by slug (unknown bank when not found).
	 *
	 * @param string $slug Slug.
	 * @return array{name:string, bins:string[], codes:string[], colors:string[], ink:string, accent:string}
	 */
	public static function get( string $slug ): array {
		return self::all()[ $slug ] ?? self::unknown();
	}

	/**
	 * Luhn check of a 16-digit Shetab card number.
	 *
	 * @param string $card Card number (separators allowed).
	 * @return bool
	 */
	public static function card_valid( string $card ): bool {
		$digits = Persian::only_digits( $card );
		if ( ! preg_match( '/^\d{16}$/', $digits ) ) {
			return false;
		}
		$sum = 0;
		for ( $i = 0; $i < 16; $i++ ) {
			$d = (int) $digits[ $i ];
			if ( 0 === $i % 2 ) {
				$d *= 2;
				$d  = $d > 9 ? $d - 9 : $d;
			}
			$sum += $d;
		}
		return 0 === $sum % 10;
	}

	/**
	 * Normalise an IBAN to «IR» + 24 digits ('' when it is not one).
	 *
	 * @param string $value Typed IBAN (with or without IR, spaces, Persian digits).
	 * @return string
	 */
	public static function sheba( string $value ): string {
		$latin = strtoupper( Persian::latin( $value ) );
		// Letters other than the IR prefix mean it is not an Iranian IBAN.
		if ( preg_match( '/[A-Z]/', (string) preg_replace( '/^\s*IR/', '', $latin ) ) ) {
			return '';
		}
		$digits = Persian::only_digits( $latin );
		return 24 === strlen( $digits ) ? 'IR' . $digits : '';
	}

	/**
	 * ISO 7064 mod-97 check of an Iranian IBAN.
	 *
	 * @param string $value IBAN in any common form.
	 * @return bool
	 */
	public static function sheba_valid( string $value ): bool {
		$sheba = self::sheba( $value );
		if ( '' === $sheba ) {
			return false;
		}
		// Move «IR» + check digits to the end; I = 18, R = 27.
		$number = substr( $sheba, 4 ) . '1827' . substr( $sheba, 2, 2 );
		$rest   = 0;
		foreach ( str_split( $number, 7 ) as $chunk ) {
			$rest = (int) ( $rest . $chunk ) % 97;
		}
		return 1 === $rest;
	}

	/**
	 * Card number for display: «6037 9975 9912 3456».
	 *
	 * @param string $card Card number.
	 * @return string
	 */
	public static function format_card( string $card ): string {
		return trim( chunk_split( Persian::only_digits( $card ), 4, ' ' ) );
	}

	/**
	 * IBAN for display: «IR06 0170 0000 0012 3456 7890 01».
	 *
	 * @param string $sheba IBAN.
	 * @return string
	 */
	public static function format_sheba( string $sheba ): string {
		$sheba = self::sheba( $sheba );
		return '' !== $sheba ? trim( chunk_split( $sheba, 4, ' ' ) ) : '';
	}

	/**
	 * Masked card for lists: «6037 •••• •••• 3456».
	 *
	 * @param string $card Card number.
	 * @return string
	 */
	public static function mask_card( string $card ): string {
		$d = Persian::only_digits( $card );
		return strlen( $d ) >= 8 ? substr( $d, 0, 4 ) . ' •••• •••• ' . substr( $d, -4 ) : $d;
	}
}
