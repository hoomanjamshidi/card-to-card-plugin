<?php
/**
 * Demo data for screenshots and manual tests: three cards (Mellat, Melli, Saman), SMS in test mode.
 * Run: bin/dev.sh wp eval-file wp-content/plugins/card-to-card/dev/seed.php
 *
 * @package CardToCard
 */

use CardToCard\Settings;
use CardToCard\Support\Banks;

/**
 * A Luhn-valid card number from a 15-digit prefix.
 *
 * @param string $prefix Prefix.
 * @return string
 */
function ctc_seed_card( string $prefix ): string {
	for ( $d = 0; $d <= 9; $d++ ) {
		if ( Banks::card_valid( $prefix . $d ) ) {
			return $prefix . $d;
		}
	}
	return '';
}

/**
 * A mod-97-valid Iranian IBAN for a bank code.
 *
 * @param string $bank Bank code.
 * @return string
 */
function ctc_seed_iban( string $bank ): string {
	$bban = $bank . '0000000101234567890';
	$rest = 0;
	foreach ( str_split( $bban . '182700' ) as $digit ) {
		$rest = ( $rest * 10 + (int) $digit ) % 97;
	}
	return 'IR' . str_pad( (string) ( 98 - $rest ), 2, '0', STR_PAD_LEFT ) . $bban;
}

Settings::save(
	array(
		'cards'       => array(
			array(
				'id'      => 'mellat',
				'number'  => ctc_seed_card( '610433791234567' ),
				'holder'  => 'فروشگاه نمونه',
				'sheba'   => ctc_seed_iban( '012' ),
				'account' => '6543210987',
				'skin'    => 'bank',
				'active'  => true,
			),
			array(
				'id'     => 'melli',
				'number' => ctc_seed_card( '603799181234567' ),
				'holder' => 'هومن جمشیدی',
				'sheba'  => ctc_seed_iban( '017' ),
				'skin'   => 'bank',
				'active' => true,
			),
			array(
				'id'     => 'saman',
				'number' => ctc_seed_card( '621986121234567' ),
				'holder' => 'فروشگاه نمونه',
				'skin'   => 'bank',
				'active' => true,
			),
		),
		'card_mode'   => 'all',
		'sms_driver'  => 'log',
		'sms_admins'  => '09120000000',
		'support'     => 'پاسخ‌گویی: شنبه تا پنجشنبه، ۹ تا ۱۸',
		'support_url' => 'https://t.me/example',
	)
);
$opt            = (array) get_option( 'woocommerce_card_to_card_settings', array() );
$opt['enabled'] = 'yes';
update_option( 'woocommerce_card_to_card_settings', $opt );
echo "seeded\n";
