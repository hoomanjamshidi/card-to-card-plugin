<?php
/**
 * Integration tests of the feature modules (run with WP-CLI, after dev/seed.php):
 *   bin/dev.sh wp eval-file wp-content/plugins/card-to-card/tests/integration/modules.php
 *
 * Registry and settings, fee / discount (amounts, cart line, order line, other gateways), per-card
 * caps (filtering, release on cancel, gateway hidden when full), display rules (roles, categories,
 * per-card roles and amounts) and duplicate receipts (same file, same image re-saved, a different
 * receipt of the same layout, references, block mode, same customer re-use, REST flags).
 *
 * @package CardToCard
 */

use CardToCard\Gateway;
use CardToCard\Modules\Card_Limits;
use CardToCard\Modules\Fee;
use CardToCard\Modules\Fraud;
use CardToCard\Modules\Registry;
use CardToCard\Orders;
use CardToCard\Receipts;
use CardToCard\Rest\Controller;
use CardToCard\Settings;

define( 'CTC_TESTING', true );
define( 'CTC_SMS_SYNC', true );

$GLOBALS['ctc_t'] = array(
	'pass' => 0,
	'fail' => array(),
);

/**
 * Assert.
 *
 * @param bool   $ok   Condition.
 * @param string $name Name.
 * @param mixed  $info Extra info on failure.
 */
function ctc_ok( bool $ok, string $name, $info = null ): void {
	if ( $ok ) {
		++$GLOBALS['ctc_t']['pass'];
		return;
	}
	$GLOBALS['ctc_t']['fail'][] = $name . ( null !== $info ? ' → ' . wp_json_encode( $info, JSON_UNESCAPED_UNICODE ) : '' );
}

/**
 * Same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual   Actual.
 * @param string $name     Name.
 */
function ctc_eq( $expected, $actual, string $name ): void {
	ctc_ok( $expected === $actual, $name, array( 'expected' => $expected, 'actual' => $actual ) );
}

/**
 * Order paid by card to card through the gateway.
 *
 * @param int    $product_id Product.
 * @param string $phone      Billing phone.
 * @return WC_Order
 */
function ctc_order( int $product_id, string $phone = '09120000001' ): WC_Order {
	$order = wc_create_order();
	$order->add_product( wc_get_product( $product_id ), 1 );
	$order->set_billing_first_name( 'تست' );
	$order->set_billing_phone( $phone );
	$order->set_billing_email( $phone . '@example.test' );
	$order->set_customer_id( get_current_user_id() );
	$order->set_payment_method( Gateway::ID );
	$order->calculate_totals();
	$order->save();
	WC()->payment_gateways()->payment_gateways()[ Gateway::ID ]->process_payment( $order->get_id() );
	$GLOBALS['ctc_orders'][] = $order->get_id();
	return wc_get_order( $order->get_id() );
}

/**
 * Module settings patch.
 *
 * @param string               $id   Module.
 * @param array<string, mixed> $conf Values.
 */
function ctc_module( string $id, array $conf ): void {
	$all        = (array) Settings::get( 'modules' );
	$all[ $id ] = array_merge( (array) $all[ $id ], $conf );
	Settings::save( array( 'modules' => $all ) );
}

/**
 * The gateway as the checkout sees it.
 *
 * @return Gateway
 */
function ctc_gateway(): Gateway {
	return WC()->payment_gateways()->payment_gateways()[ Gateway::ID ];
}

$GLOBALS['ctc_orders'] = array();
add_filter( 'pre_wp_mail', '__return_true' );
wp_set_current_user( 1 );
WC()->initialize_session();
WC()->initialize_cart();
update_option( 'woocommerce_currency', 'IRT' );
update_option( 'woocommerce_price_num_decimals', '0' );
$saved_settings = get_option( Settings::OPTION );
$saved_usage    = get_option( Card_Limits::OPTION );
delete_option( Card_Limits::OPTION );
Settings::save(
	array(
		'pro'        => true,
		'card_mode'  => 'first',
		'sms_driver' => 'log',
		'min_total'  => 0,
		'max_total'  => 0,
	)
);
ctc_ok( count( Settings::cards() ) >= 2, 'at least two cards (run dev/seed.php first)' );
$cards = array_column( Settings::cards(), 'id' );

$cat = wp_insert_term( 'دسته تست ' . wp_rand(), 'product_cat' );
$cat = is_array( $cat ) ? (int) $cat['term_id'] : 0;
$product = new WC_Product_Simple();
$product->set_name( 'محصول ماژول' );
$product->set_regular_price( '480000' );
$product->set_category_ids( array( $cat ) );
$product->save();
$pid = $product->get_id();

// ---------------------------------------------------------------------
// Registry and settings.
// ---------------------------------------------------------------------
ctc_eq( array( 'fee', 'limits', 'rules', 'fraud' ), array_keys( Registry::all() ), 'four built-in modules' );
foreach ( Registry::all() as $id => $module ) {
	ctc_ok( false === Settings::defaults()['modules'][ $id ]['on'], "module {$id} off by default" );
}
$clean = Settings::sanitize( array_merge( Settings::all(), array( 'modules' => array( 'fee' => array( 'on' => '1', 'type' => 'bogus', 'percent' => '۲۰۰' ), 'nope' => array( 'on' => true ) ) ) ) );
ctc_ok( true === $clean['modules']['fee']['on'], 'module switch sanitised' );
ctc_eq( 'discount', $clean['modules']['fee']['type'], 'unknown choice → default' );
ctc_eq( 100.0, $clean['modules']['fee']['percent'], 'percent capped at 100, Persian digits read' );
ctc_ok( ! isset( $clean['modules']['nope'] ), 'unknown module dropped' );
add_filter(
	'ctc_modules',
	static function ( $modules ) {
		$modules[] = new class() extends \CardToCard\Modules\Module {
			public function id(): string {
				return 'extra'; }
			public function label(): string {
				return 'Extra'; }
			public function description(): string {
				return ''; }
			public function boot(): void {
				$GLOBALS['ctc_extra_booted'] = true; }
		};
		return $modules;
	}
);
Registry::reset();
Settings::flush();
ctc_ok( null !== Registry::get( 'extra' ), 'third-party module registered via ctc_modules' );
ctc_module( 'extra', array( 'on' => true ) );
ctc_ok( ! empty( $GLOBALS['ctc_extra_booted'] ), 'a module switched on boots in the same request' );
ctc_ok( in_array( 'extra', array_column( Registry::meta(), 'id' ), true ), 'module listed for the panel' );

// ---------------------------------------------------------------------
// Fee / discount.
// ---------------------------------------------------------------------
$fee = Registry::get( 'fee' );
ctc_module(
	'fee',
	array(
		'on'      => true,
		'type'    => 'discount',
		'mode'    => 'percent',
		'percent' => 2.0,
		'round'   => 0,
		'max'     => 0,
		'min'     => 0,
		'badge'   => true,
	)
);
ctc_eq( -20000.0, $fee->amount( 1000000 ), '2% discount of 1,000,000' );
ctc_module( 'fee', array( 'round' => 1000 ) );
ctc_eq( -10000.0, $fee->amount( 487000 ), 'rounded to 1,000 (9,740 → 10,000)' );
ctc_module( 'fee', array( 'max' => 5000 ) );
ctc_eq( -5000.0, $fee->amount( 1000000 ), 'capped' );
ctc_module( 'fee', array( 'max' => 0, 'min' => 2000000 ) );
ctc_eq( 0.0, $fee->amount( 1000000 ), 'not under the minimum' );
ctc_module( 'fee', array( 'min' => 0, 'round' => 0, 'type' => 'fee', 'mode' => 'fixed', 'fixed' => 15000 ) );
ctc_eq( 15000.0, $fee->amount( 1000000 ), 'fixed surcharge' );
ctc_ok( str_contains( apply_filters( 'ctc_gateway_title', 'کارت به کارت' ), 'کارمزد' ), 'badge in the gateway title', apply_filters( 'ctc_gateway_title', 'کارت به کارت' ) );
ctc_module( 'fee', array( 'type' => 'discount', 'mode' => 'percent', 'percent' => 2.0 ) );
ctc_eq( 'کارت به کارت (۲٪ تخفیف)', apply_filters( 'ctc_gateway_title', 'کارت به کارت' ), 'discount badge' );

// Cart line follows the chosen method.
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $pid );
WC()->session->set( 'chosen_payment_method', Gateway::ID );
WC()->cart->calculate_totals();
$fees = WC()->cart->get_fees();
ctc_ok( isset( $fees[ Fee::KEY ] ) && -9600.0 === (float) $fees[ Fee::KEY ]->amount, 'cart discount line with card to card', array_map( fn( $f ) => $f->amount, $fees ) );
ctc_eq( 470400.0, (float) WC()->cart->get_total( 'edit' ), 'cart total discounted' );
WC()->session->set( 'chosen_payment_method', 'bacs' );
WC()->cart->calculate_totals();
ctc_ok( ! isset( WC()->cart->get_fees()[ Fee::KEY ] ), 'no line with another method' );
WC()->cart->empty_cart();

// Order line added by the gateway when the cart missed it.
$f1 = ctc_order( $pid );
ctc_eq( 1, count( Fee::items( $f1 ) ), 'gateway adds the discount line to the order' );
ctc_eq( 470400.0, (float) $f1->get_total(), 'order total discounted' );
$f1b = wc_get_order( $f1->get_id() );
WC()->payment_gateways()->payment_gateways()[ Gateway::ID ]->process_payment( $f1b->get_id() );
ctc_eq( 1, count( Fee::items( wc_get_order( $f1->get_id() ) ) ), 'never added twice' );
// Another gateway: the line goes.
$f2 = wc_get_order( $f1->get_id() );
$f2->set_payment_method( 'bacs' );
$fee->reconcile( $f2 );
ctc_eq( 0, count( Fee::items( $f2 ) ), 'line removed for another gateway' );
ctc_eq( 480000.0, (float) $f2->get_total(), 'total restored' );
ctc_module( 'fee', array( 'on' => false ) );
$f3 = ctc_order( $pid );
ctc_eq( 0, count( Fee::items( $f3 ) ), 'module off → no line' );
ctc_eq( 'کارت به کارت', apply_filters( 'ctc_gateway_title', 'کارت به کارت' ), 'module off → plain title' );

// ---------------------------------------------------------------------
// Per-card caps.
// ---------------------------------------------------------------------
delete_option( Card_Limits::OPTION );
ctc_module(
	'limits',
	array(
		'on'     => true,
		'limits' => array(
			$cards[0] => array(
				'day'   => '۱,۰۰۰,۰۰۰',
				'month' => 0,
			),
		),
	)
);
ctc_eq( array( 'day' => 1000000, 'month' => 0 ), Registry::get( 'limits' )->caps( $cards[0] ), 'caps sanitised (Persian digits, separators)' );
$l1 = ctc_order( $pid );
$l2 = ctc_order( $pid );
ctc_eq( $cards[0], $l1->get_meta( '_ctc_cards' )[0]['id'], 'first order → first card' );
ctc_eq( $cards[0], $l2->get_meta( '_ctc_cards' )[0]['id'], 'second order still fits (960,000 ≤ 1,000,000)' );
ctc_eq( 960000.0, Card_Limits::usage( $cards[0] )['day'], 'usage counted' );
$l3 = ctc_order( $pid );
ctc_eq( $cards[1], $l3->get_meta( '_ctc_cards' )[0]['id'], 'full card skipped → next card' );
ctc_ok( ! in_array( $cards[0], array_column( Orders::eligible_cards( null, 480000 ), 'id' ), true ), 'full card not eligible' );
$l1->update_status( 'cancelled' );
ctc_eq( 480000.0, Card_Limits::usage( $cards[0] )['day'], 'cancel gives the amount back' );
ctc_ok( in_array( $cards[0], array_column( Orders::eligible_cards( null, 480000 ), 'id' ), true ), 'card eligible again' );
wc_get_order( $l1->get_id() )->update_status( Orders::AWAITING );
ctc_eq( 960000.0, Card_Limits::usage( $cards[0] )['day'], 'order back from cancelled counts again' );
$caps = array();
foreach ( $cards as $card ) {
	$caps[ $card ] = array(
		'day'   => 100000,
		'month' => 0,
	);
}
ctc_module( 'limits', array( 'limits' => $caps ) );
WC()->cart->add_to_cart( $pid );
WC()->cart->calculate_totals();
ctc_ok( ! ctc_gateway()->is_available(), 'every card full → gateway hidden' );
ctc_module( 'limits', array( 'on' => false ) );
ctc_ok( ctc_gateway()->is_available(), 'module off → gateway back' );

// ---------------------------------------------------------------------
// Display rules.
// ---------------------------------------------------------------------
ctc_module(
	'rules',
	array(
		'on'    => true,
		'roles' => array( 'customer' ),
	)
);
ctc_ok( ! ctc_gateway()->is_available(), 'admin is not a «customer» → hidden' );
ctc_module( 'rules', array( 'roles' => array( 'administrator' ) ) );
ctc_ok( ctc_gateway()->is_available(), 'allowed role → shown' );
ctc_module(
	'rules',
	array(
		'roles'     => array(),
		'cats_mode' => 'except',
		'cats'      => array( $cat ),
	)
);
ctc_ok( ! ctc_gateway()->is_available(), 'excluded category in cart → hidden' );
ctc_module( 'rules', array( 'cats_mode' => 'only' ) );
ctc_ok( ctc_gateway()->is_available(), '«only» category, every item in it → shown' );
$other = new WC_Product_Simple();
$other->set_name( 'بدون دسته' );
$other->set_regular_price( '1000' );
$other->save();
WC()->cart->add_to_cart( $other->get_id() );
WC()->cart->calculate_totals();
ctc_ok( ! ctc_gateway()->is_available(), '«only» category with an item outside → hidden' );
WC()->cart->empty_cart();
ctc_module(
	'rules',
	array(
		'cats_mode' => 'off',
		'cards'     => array(
			$cards[0] => array(
				'roles' => array( 'customer' ),
				'min'   => 0,
				'max'   => 0,
			),
			$cards[1] => array(
				'roles' => array(),
				'min'   => 1000000,
				'max'   => 0,
			),
		),
	)
);
$eligible = array_column( Orders::eligible_cards( null, 480000 ), 'id' );
ctc_ok( ! in_array( $cards[0], $eligible, true ), 'card for «customer» role only: not for admin' );
ctc_ok( ! in_array( $cards[1], $eligible, true ), 'card for orders ≥ 1,000,000: not for 480,000' );
ctc_ok( in_array( $cards[1], array_column( Orders::eligible_cards( null, 2000000 ), 'id' ), true ), 'big order gets the big-order card' );
$r1 = ctc_order( $pid );
ctc_eq( $cards[2] ?? '', $r1->get_meta( '_ctc_cards' )[0]['id'] ?? '', 'order gets the card its rules allow' );
ctc_module( 'rules', array( 'on' => false ) );

// ---------------------------------------------------------------------
// Duplicate / suspicious receipts.
// ---------------------------------------------------------------------
ctc_module(
	'fraud',
	array(
		'on'     => true,
		'action' => 'flag',
		'file'   => true,
		'image'  => true,
		'ref'    => true,
	)
);
global $wpdb;
ctc_ok( Fraud::table() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Fraud::table() ) ), 'fingerprint table created' );
$wpdb->query( 'DELETE FROM ' . Fraud::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

$dir = Receipts::dir() . '/ctc-test';
wp_mkdir_p( $dir );
$font = (string) ( glob( '/usr/share/fonts/**/*.ttf' )[0] ?? '' );
/**
 * A receipt-like image (same layout, given texts) saved as JPEG.
 *
 * @param string $file    File name.
 * @param string $amount  Amount text.
 * @param string $ref     Reference text.
 * @param int    $quality JPEG quality.
 * @param int    $width   Width.
 * @param string $when    Date line.
 * @return array<string, mixed> Stored receipt.
 */
$make = static function ( string $file, string $amount, string $ref, int $quality = 90, int $width = 720, string $when = '1405/08/15 14:32' ) use ( $dir ): array {
	$im = imagecreatetruecolor( 720, 1400 );
	imagefill( $im, 0, 0, imagecolorallocate( $im, 248, 248, 250 ) );
	imagefilledrectangle( $im, 0, 0, 720, 220, imagecolorallocate( $im, 0, 150, 90 ) );
	$ink = imagecolorallocate( $im, 30, 30, 40 );
	$y   = 330;
	foreach ( array( $amount, $ref, $when, '6037-****-1234', 'Mellat' ) as $line ) {
		// Built-in font scaled up: big glyphs like a real receipt.
		$tmp = imagecreatetruecolor( 200, 16 );
		imagefill( $tmp, 0, 0, imagecolorallocate( $tmp, 248, 248, 250 ) );
		imagestring( $tmp, 5, 0, 0, $line, imagecolorallocate( $tmp, 30, 30, 40 ) );
		imagecopyresized( $im, $tmp, 260, $y, 0, 0, 400, 32, 200, 16 );
		imageline( $im, 40, $y + 60, 680, $y + 60, $ink );
		$y += 140;
	}
	if ( 720 !== $width ) {
		$im = imagescale( $im, $width );
	}
	imagejpeg( $im, $dir . '/' . $file, $quality );
	return array(
		'path' => 'ctc-test/' . $file,
		'name' => $file,
		'type' => 'image/jpeg',
		'size' => (int) filesize( $dir . '/' . $file ),
	);
};
/**
 * Run the upload path: check filter, attach (records the fingerprint).
 *
 * @param WC_Order             $order  Order.
 * @param array<string, mixed> $stored Stored receipt.
 * @param string               $ref    Reference.
 * @param string               $last4  Card digits.
 * @return array<string, mixed>|WP_Error Last receipt or the refusal.
 */
$upload = static function ( WC_Order $order, array $stored, string $ref = '', string $last4 = '' ) {
	$checked = apply_filters( 'ctc_receipt_check', $stored, $order, $ref, $last4 );
	if ( is_wp_error( $checked ) ) {
		return $checked;
	}
	Receipts::attach( wc_get_order( $order->get_id() ), $checked, $ref, $last4 );
	$list = Orders::receipts( wc_get_order( $order->get_id() ) );
	return end( $list );
};
$levels = static fn( $receipt ) => is_array( $receipt ) ? array_column( (array) ( $receipt['flags'] ?? array() ), 'level' ) : array( 'error' );
$types  = static fn( $receipt ) => is_array( $receipt ) ? array_merge( ...array_map( static fn( $f ) => (array) $f['types'], (array) ( $receipt['flags'] ?? array() ) ) ?: array( array() ) ) : array();

$d1 = ctc_order( $pid, '09121111111' );
$ra = $upload( $d1, $make( 'a.jpg', '1,250,000', '4829163711' ), '۴۸۲۹۱۶۳۷۱۱', '6104' );
ctc_eq( array(), $levels( $ra ), 'first receipt: no flags' );
ctc_ok( 1 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Fraud::table() ), 'fingerprint stored' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
ctc_eq( '4829163711', $ra['fp']['ref'] ?? '', 'reference normalised (Persian digits)' );
ctc_ok( 64 === strlen( (string) ( $ra['fp']['dhash'] ?? '' ) ), 'image hash made' );

copy( $dir . '/a.jpg', $dir . '/a-copy.jpg' );
$d2 = ctc_order( $pid, '09122222222' );
$rb = $upload(
	$d2,
	array(
		'path' => 'ctc-test/a-copy.jpg',
		'name' => 'a-copy.jpg',
		'type' => 'image/jpeg',
		'size' => 1,
	)
);
ctc_eq( array( 'danger' ), $levels( $rb ), 'same file for another order → danger' );
ctc_ok( in_array( 'file', $types( $rb ), true ), 'flag says «same file»' );
ctc_eq( $d1->get_id(), (int) ( $rb['flags'][0]['order'] ?? 0 ), 'flag points to the first order' );

$d3 = ctc_order( $pid, '09123333333' );
$rc = $upload( $d3, $make( 'a-q35.jpg', '1,250,000', '4829163711', 35 ) );
ctc_ok( in_array( 'image', $types( $rc ), true ) && in_array( 'danger', $levels( $rc ), true ), 'same receipt recompressed (messenger) → same image, danger', $rc['flags'] ?? $rc );
$d3b = ctc_order( $pid, '09123333334' );
$rcb = $upload( $d3b, $make( 'a-small.jpg', '1,250,000', '4829163711', 35, 400 ) );
ctc_ok( in_array( 'similar', $types( $rcb ), true ), 'same receipt resized → very similar', $rcb['flags'] ?? $rcb );
ctc_ok( ! in_array( 'danger', $levels( $rcb ), true ), 'very similar alone is only a warning' );

$d4 = ctc_order( $pid, '09124444444' );
$rd = $upload( $d4, $make( 'other.jpg', '3,780,000', '7712039944', 90, 720, '1405/08/16 09:11' ), '7712039944', '5022' );
ctc_eq( array(), $levels( $rd ), 'another receipt with the same layout → no flag' );

$d5 = ctc_order( $pid, '09125555555' );
$re = $upload( $d5, $make( 'e.jpg', '9,000', '1111111' ), '771203', '5022' );
ctc_eq( array(), $levels( $re ), 'different short reference → no flag' );
$d6 = ctc_order( $pid, '09126666666' );
$rf = $upload( $d6, $make( 'f.jpg', '8,000', '2222222' ), '771203', '1234' );
ctc_eq( array( 'warn' ), $levels( $rf ), 'same short reference, other card → only a warning' );
$d7 = ctc_order( $pid, '09127777777' );
$rg = $upload( $d7, $make( 'g.jpg', '7,000', '3333333' ), '771203', '5022' );
ctc_ok( in_array( 'danger', $levels( $rg ), true ), 'same reference + same card digits → danger' );
$d8 = ctc_order( $pid, '09128888888' );
$rh = $upload( $d8, $make( 'h.jpg', '6,000', '4444444' ), '7712039944', '' );
ctc_ok( in_array( 'danger', $levels( $rh ), true ), 'same long (10-digit) reference → danger' );

// Same order: re-sending after a rejection is not a duplicate.
Orders::reject( wc_get_order( $d4->get_id() ), 'x' );
$ri = $upload( wc_get_order( $d4->get_id() ), $make( 'other2.jpg', '3,780,000', '7712039944', 90, 720, '1405/08/16 09:11' ), '', '' );
ctc_eq( array(), $levels( $ri ), 'same order re-send → no flag' );

// The same customer re-uses the payment of a cancelled order → warning only.
wc_get_order( $d1->get_id() )->update_status( 'cancelled' );
copy( $dir . '/a.jpg', $dir . '/a-again.jpg' );
$d9 = ctc_order( $pid, '09121111111' );
$rj = $upload(
	$d9,
	array(
		'path' => 'ctc-test/a-again.jpg',
		'name' => 'a.jpg',
		'type' => 'image/jpeg',
		'size' => 1,
	)
);
$from_d1 = array_values( array_filter( (array) ( $rj['flags'] ?? array() ), static fn( $f ) => (int) $f['order'] === $d1->get_id() ) );
ctc_eq( 'warn', $from_d1[0]['level'] ?? '', 'cancelled order of the same customer → warning' );
ctc_eq( 'reuse', $from_d1[0]['note'] ?? '', 'with the «re-use» note' );

// REST row carries readable flags.
$row = Controller::order_row( wc_get_order( $d2->get_id() ) );
$flag = $row['receipts'][0]['flags'][0] ?? array();
ctc_eq( 'danger', $flag['level'] ?? '', 'REST flag level' );
ctc_ok( str_contains( (string) ( $flag['text'] ?? '' ), 'همان فایل' ), 'REST flag text', $flag );
ctc_ok( '' !== (string) ( $flag['edit'] ?? '' ), 'REST flag links the other order' );
ctc_ok( str_contains( implode( ' ', wp_list_pluck( wc_get_order_notes( array( 'order_id' => $d2->get_id() ) ), 'content' ) ), '⚠️' ), 'flag noted on the order' );

// Block mode refuses a sure duplicate and removes the file.
ctc_module( 'fraud', array( 'action' => 'block' ) );
copy( $dir . '/a.jpg', $dir . '/a-block.jpg' );
$d10     = ctc_order( $pid, '09120000010' );
$blocked = array(
	'path' => 'ctc-test/a-block.jpg',
	'name' => 'a.jpg',
	'type' => 'image/jpeg',
	'size' => 1,
);
$checked = apply_filters( 'ctc_receipt_check', $blocked, $d10, '', '' );
ctc_ok( is_wp_error( $checked ) && 'ctc_duplicate' === $checked->get_error_code(), 'block mode → refused' );
Receipts::discard( $blocked );
ctc_ok( ! file_exists( $dir . '/a-block.jpg' ), 'refused file deleted' );
ctc_eq( Orders::AWAITING, wc_get_order( $d10->get_id() )->get_status(), 'order still awaiting its receipt' );

// Fingerprints survive the file clean-up.
wp_delete_file( $dir . '/a.jpg' );
copy( $dir . '/a-copy.jpg', $dir . '/a-late.jpg' );
$d11 = ctc_order( $pid, '09120000011' );
$late = apply_filters(
	'ctc_receipt_check',
	array(
		'path' => 'ctc-test/a-late.jpg',
		'name' => 'a.jpg',
		'type' => 'image/jpeg',
		'size' => 1,
	),
	$d11,
	'',
	''
);
ctc_ok( is_wp_error( $late ), 'duplicate caught after the original file was deleted' );
ctc_module( 'fraud', array( 'on' => false ) );
$off = apply_filters(
	'ctc_receipt_check',
	array(
		'path' => 'ctc-test/a-late.jpg',
		'name' => 'a.jpg',
		'type' => 'image/jpeg',
		'size' => 1,
	),
	$d11,
	'',
	''
);
ctc_ok( is_array( $off ) && empty( $off['flags'] ), 'module off → no check' );

// ---------------------------------------------------------------------
// Clean up.
// ---------------------------------------------------------------------
foreach ( glob( $dir . '/*' ) as $file ) {
	wp_delete_file( $file );
}
rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
foreach ( array_unique( $GLOBALS['ctc_orders'] ) as $oid ) {
	$o = wc_get_order( $oid );
	if ( $o ) {
		$o->delete( true );
	}
}
$wpdb->query( 'DELETE FROM ' . Fraud::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$product->delete( true );
$other->delete( true );
wp_delete_term( $cat, 'product_cat' );
WC()->cart->empty_cart();
update_option( Settings::OPTION, $saved_settings );
false === $saved_usage ? delete_option( Card_Limits::OPTION ) : update_option( Card_Limits::OPTION, $saved_usage );
Settings::flush();

$t = $GLOBALS['ctc_t'];
WP_CLI::log( sprintf( 'Modules: %d passed, %d failed.', $t['pass'], count( $t['fail'] ) ) );
foreach ( $t['fail'] as $f ) {
	WP_CLI::log( '  ✗ ' . $f );
}
if ( $t['fail'] ) {
	WP_CLI::halt( 1 );
}
