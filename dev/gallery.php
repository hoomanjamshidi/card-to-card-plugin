<?php
/**
 * Writes dev/gallery.html: every bank's card plate (README image).
 * Run: bin/dev.sh wp eval-file wp-content/plugins/card-to-card/dev/gallery.php
 *
 * @package CardToCard
 */

use CardToCard\Payment_Page;
use CardToCard\Settings;
use CardToCard\Support\Banks;

$ctc_pick = array( 'mellat', 'melli', 'saderat', 'tejarat', 'pasargad', 'saman', 'keshavarzi', 'maskan', 'parsian', 'refah', 'ayandeh', 'eghtesad-novin', 'sepah', 'shahr', 'postbank', 'dey' );
ob_start();
foreach ( $ctc_pick as $ctc_slug ) {
	$ctc_bank = Banks::get( $ctc_slug );
	$ctc_bin  = $ctc_bank['bins'][0];
	$ctc_num  = '';
	for ( $d = 0; $d <= 9; $d++ ) {
		if ( Banks::card_valid( $ctc_bin . '123456789' . $d ) ) {
			$ctc_num = $ctc_bin . '123456789' . $d;
		}
	}
	$ctc_card = Settings::resolve_card(
		array(
			'id'      => $ctc_slug,
			'number'  => $ctc_num,
			'holder'  => 'فروشگاه نمونه',
			'sheba'   => '',
			'account' => '',
			'bank'    => $ctc_slug,
			'skin'    => 'bank',
			'color1'  => '',
			'color2'  => '',
		)
	);
	Payment_Page::part( 'card.php', Payment_Page::card_view( $ctc_card ) + array( 'tilt' => false ) );
}
$ctc_cards = (string) ob_get_clean();
$ctc_css   = CTC_URL . 'assets/css/payment.css';
$ctc_font  = CTC_URL . 'assets/fonts/Vazirmatn-Variable.woff2';
$ctc_html  = '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><link rel="stylesheet" href="' . esc_url( $ctc_css ) . '">'
	. '<style>@font-face{font-family:"CTC Vazirmatn";src:url("' . esc_url( $ctc_font ) . '") format("woff2");font-weight:100 900}'
	. 'body{margin:0;background:radial-gradient(circle at 20% 0%,#eef0ff,#f6f7fb 50%);font-family:"CTC Vazirmatn"}'
	. '.ctc{--ctc-font:"CTC Vazirmatn"}.g{display:grid;grid-template-columns:repeat(4,320px);gap:28px;padding:48px;width:max-content}.ctc-plate{animation:none}.ctc-plate:after{display:none}</style>'
	. '<body><div class="ctc g" id="gallery">' . $ctc_cards . '</div></body></html>';
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents( CTC_DIR . 'dev/gallery.html', $ctc_html );
echo "dev/gallery.html\n";
