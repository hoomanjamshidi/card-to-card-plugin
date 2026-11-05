<?php
/**
 * Persian digits, words, mobile numbers and Jalali dates.
 *
 * @package CardToCard
 */

use CardToCard\Support\Jalali;
use CardToCard\Support\Persian;
use PHPUnit\Framework\TestCase;

/**
 * Persian tests.
 */
final class PersianTest extends TestCase {

	public function test_digits_both_ways(): void {
		$this->assertSame( '۱٬۴۶۳٬۰۰۰', Persian::digits( '1,463,000' ) );
		$this->assertSame( '0123456789', Persian::latin( '۰۱۲۳۴۵۶۷۸۹' ) );
		$this->assertSame( '0123456789', Persian::latin( '٠١٢٣٤٥٦٧٨٩' ) );
		$this->assertSame( '60379975', Persian::only_digits( '۶۰۳۷-۹۹۷۵' ) );
	}

	public function test_words(): void {
		$this->assertSame( 'صفر', Persian::words( 0 ) );
		$this->assertSame( 'یکصد', Persian::words( 100 ) );
		$this->assertSame( 'یک میلیون و چهارصد و شصت و سه هزار', Persian::words( 1463000 ) );
		$this->assertSame( 'دوازده هزار و پانصد و یک', Persian::words( '12501' ) );
		$this->assertSame( 'یک میلیارد و یک', Persian::words( 1000000001 ) );
		$this->assertSame( 'نوزده', Persian::words( '۱۹' ) );
	}

	public function test_mobile_normalisation(): void {
		$this->assertSame( '09123456789', Persian::mobile( '+98 912 345 6789' ) );
		$this->assertSame( '09123456789', Persian::mobile( '00989123456789' ) );
		$this->assertSame( '09123456789', Persian::mobile( '۰۹۱۲-۳۴۵-۶۷۸۹' ) );
		$this->assertSame( '09123456789', Persian::mobile( '9123456789' ) );
		$this->assertSame( '', Persian::mobile( '+98 912 345 67' ) );
		$this->assertSame( '', Persian::mobile( '02112345678' ) );
	}

	public function test_jalali(): void {
		$this->assertSame( array( 1405, 8, 14 ), Jalali::to_jalali( 2026, 11, 5 ) );
		$this->assertSame( array( 2026, 3, 21 ), Jalali::to_gregorian( 1405, 1, 1 ) );
		$this->assertSame( '1405/08/14', Jalali::from_gregorian_string( '2026-11-05' ) );
		$ts = gmmktime( 9, 30, 0, 11, 5, 2026 );
		$this->assertSame( '14 آبان 1405 · 13:00', Jalali::format( 'j F Y · H:i', $ts, 12600 ) );
		$this->assertSame( 'پنجشنبه', Jalali::format( 'l', $ts ) );
	}
}
