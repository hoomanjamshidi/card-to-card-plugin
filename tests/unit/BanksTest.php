<?php
/**
 * Bank detection, card (Luhn) and IBAN (mod-97) validation, formatting.
 *
 * @package CardToCard
 */

use CardToCard\Support\Banks;
use PHPUnit\Framework\TestCase;

/**
 * Banks tests.
 */
final class BanksTest extends TestCase {

	/**
	 * Complete a 15-digit prefix with its Luhn check digit.
	 *
	 * @param string $prefix 15 digits.
	 * @return string
	 */
	private static function luhn( string $prefix ): string {
		for ( $d = 0; $d <= 9; $d++ ) {
			if ( Banks::card_valid( $prefix . $d ) ) {
				return $prefix . $d;
			}
		}
		return '';
	}

	/**
	 * Build a valid Iranian IBAN for a bank code (independent mod-97 with bcmath-free chunks).
	 *
	 * @param string $bank 3-digit bank code.
	 * @return string
	 */
	private static function iban( string $bank ): string {
		$bban = $bank . str_pad( '123456789012345678', 19, '0', STR_PAD_LEFT );
		$bban = substr( $bban, 0, 22 );
		$num  = $bban . '182700';
		$rest = 0;
		foreach ( str_split( $num ) as $digit ) {
			$rest = ( $rest * 10 + (int) $digit ) % 97;
		}
		return 'IR' . str_pad( (string) ( 98 - $rest ), 2, '0', STR_PAD_LEFT ) . $bban;
	}

	public function test_luhn_accepts_valid_and_rejects_typos(): void {
		$card = self::luhn( '603799123456789' );
		$this->assertSame( 16, strlen( $card ) );
		$this->assertTrue( Banks::card_valid( $card ) );
		$this->assertTrue( Banks::card_valid( implode( '-', str_split( $card, 4 ) ) ) );
		$wrong = substr( $card, 0, 15 ) . ( ( (int) substr( $card, -1 ) + 1 ) % 10 );
		$this->assertFalse( Banks::card_valid( $wrong ) );
		$this->assertFalse( Banks::card_valid( '6037991234' ) );
		$this->assertFalse( Banks::card_valid( '' ) );
	}

	public function test_persian_digits_are_accepted(): void {
		$card = self::luhn( '610433123456789' );
		$fa   = strtr( $card, array_combine( str_split( '0123456789' ), mb_str_split( '۰۱۲۳۴۵۶۷۸۹' ) ) );
		$this->assertTrue( Banks::card_valid( $fa ) );
		$this->assertSame( 'mellat', Banks::detect( $fa ) );
	}

	public function test_detect_bank_from_bin(): void {
		$this->assertSame( 'melli', Banks::detect( '6037991234567890' ) );
		$this->assertSame( 'mellat', Banks::detect( '6104 3312 3456 7890' ) );
		$this->assertSame( 'saman', Banks::detect( '6219861234567890' ) );
		$this->assertSame( 'pasargad', Banks::detect( '5022291234567890' ) );
		$this->assertSame( 'tejarat', Banks::detect( '5859831234567890' ) );
		$this->assertSame( '', Banks::detect( '1234561234567890' ) );
	}

	public function test_detect_bank_from_iban_when_bin_unknown(): void {
		$iban = self::iban( '012' );
		$this->assertTrue( Banks::sheba_valid( $iban ) );
		$this->assertSame( 'mellat', Banks::detect( '', $iban ) );
		$this->assertSame( 'melli', Banks::detect( '6037991234567890', $iban ), 'BIN wins over IBAN' );
	}

	public function test_iban_validation(): void {
		$iban = self::iban( '017' );
		$this->assertTrue( Banks::sheba_valid( $iban ) );
		$this->assertTrue( Banks::sheba_valid( strtolower( $iban ) ) );
		$this->assertTrue( Banks::sheba_valid( substr( $iban, 2 ) ), 'without IR' );
		$this->assertTrue( Banks::sheba_valid( implode( ' ', str_split( $iban, 4 ) ) ) );
		$broken = substr( $iban, 0, -1 ) . ( ( (int) substr( $iban, -1 ) + 1 ) % 10 );
		$this->assertFalse( Banks::sheba_valid( $broken ) );
		$this->assertFalse( Banks::sheba_valid( 'DE89370400440532013000' ) );
		$this->assertSame( '', Banks::sheba( 'IR12' ) );
	}

	public function test_formatting(): void {
		$this->assertSame( '6037 9912 3456 7890', Banks::format_card( '6037991234567890' ) );
		$this->assertSame( '6037 •••• •••• 7890', Banks::mask_card( '6037-9912-3456-7890' ) );
		$iban = self::iban( '017' );
		$this->assertSame( 32, strlen( Banks::format_sheba( $iban ) ) );
		$this->assertStringStartsWith( 'IR', Banks::format_sheba( substr( $iban, 2 ) ) );
	}

	public function test_every_bank_is_complete(): void {
		$bins = array();
		foreach ( Banks::all() as $slug => $bank ) {
			$this->assertNotSame( '', $bank['name'], $slug );
			$this->assertCount( 3, $bank['colors'], $slug );
			foreach ( $bank['colors'] as $color ) {
				$this->assertMatchesRegularExpression( '/^#[0-9a-f]{6}$/i', $color, $slug );
			}
			foreach ( $bank['bins'] as $bin ) {
				$this->assertMatchesRegularExpression( '/^\d{6}$/', $bin, $slug );
				$this->assertArrayNotHasKey( $bin, $bins, "duplicate BIN $bin" );
				$bins[ $bin ] = $slug;
			}
			$this->assertFileExists( dirname( __DIR__, 2 ) . "/assets/banks/$slug.svg", "logo of $slug" );
		}
		$this->assertSame( Banks::unknown()['colors'], Banks::get( 'nope' )['colors'] );
	}
}
