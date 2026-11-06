<?php
/**
 * Integration tests inside a real WordPress + WooCommerce (run with WP-CLI):
 *   bin/dev.sh wp eval-file wp-content/plugins/card-to-card/tests/integration/run.php
 *
 * Covers the whole order flow (statuses, stock, deadline jobs, strict guard, approve / reject / expire),
 * settings sanitising, REST permissions and payloads, Toman amounts, and the SMS layer with mocked
 * Melipayamak answers (pattern + line modes, credit, error codes).
 *
 * @package CardToCard
 */

use CardToCard\Admin\App;
use CardToCard\Gateway;
use CardToCard\Orders;
use CardToCard\Payment_Page;
use CardToCard\Receipts;
use CardToCard\Settings;
use CardToCard\Sms\Events;
use CardToCard\Sms\Melipayamak;
use CardToCard\Sms\Sender;
use CardToCard\Util;

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
 * Fresh order paid by card to card through the gateway.
 *
 * @param int $product_id Product.
 * @param int $qty        Quantity.
 * @return array{0:WC_Order, 1:array<string,string>}
 */
function ctc_new_order( int $product_id, int $qty = 1 ): array {
	$order = wc_create_order();
	$order->add_product( wc_get_product( $product_id ), $qty );
	$order->set_billing_first_name( 'سارا' );
	$order->set_billing_last_name( 'محمدی' );
	$order->set_billing_phone( '۰۹۱۲-۳۴۵-۶۷۸۹' );
	$order->set_billing_email( 'sara@example.test' );
	$order->set_payment_method( Gateway::ID );
	$order->set_payment_method_title( 'کارت به کارت' );
	$order->calculate_totals();
	$order->save();
	$gateways = WC()->payment_gateways()->payment_gateways();
	$result   = $gateways[ Gateway::ID ]->process_payment( $order->get_id() );
	return array( wc_get_order( $order->get_id() ), $result );
}

/**
 * Pending Action Scheduler jobs of an order.
 *
 * @param string $hook     Hook.
 * @param int    $order_id Order.
 * @return int
 */
function ctc_jobs( string $hook, int $order_id ): int {
	return count(
		as_get_scheduled_actions(
			array(
				'hook'   => $hook,
				'args'   => array( $order_id ),
				'status' => ActionScheduler_Store::STATUS_PENDING,
				'group'  => Orders::GROUP,
			),
			'ids'
		)
	);
}

$GLOBALS['ctc_mails'] = array();
add_filter(
	'pre_wp_mail',
	static function ( $short, $atts ) {
		$GLOBALS['ctc_mails'][] = array( 'to' => (string) ( is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : $atts['to'] ), 'subject' => (string) $atts['subject'], 'body' => (string) $atts['message'] );
		return true;
	},
	10,
	2
);

wp_set_current_user( 1 );
WC()->initialize_session();
WC()->initialize_cart();

// ---------------------------------------------------------------------
// Setup: product with stock, cards seeded, currency Toman.
// ---------------------------------------------------------------------
update_option( 'woocommerce_currency', 'IRT' );
update_option( 'woocommerce_price_num_decimals', '0' );
$product = new WC_Product_Simple();
$product->set_name( 'محصول تست' );
$product->set_regular_price( '480000' );
$product->set_manage_stock( true );
$product->set_stock_quantity( 10 );
$product->save();
$pid = $product->get_id();

$saved_settings = get_option( Settings::OPTION );
Settings::save(
	array(
		'pro'        => true,
		'redirect'   => true,
		'strict'     => true,
		'deadline'   => 24,
		'remind'     => 6,
		'card_mode'  => 'all',
		'sms_driver' => 'log',
		'sms_admins' => '09350000000',
		'sms_events' => array(),
	)
);
ctc_ok( count( Settings::cards() ) >= 1, 'cards are configured (run dev/seed.php first)' );

// ---------------------------------------------------------------------
// Currency: Toman everywhere.
// ---------------------------------------------------------------------
ctc_eq( 'تومان', Util::unit(), 'unit is Toman for IRT' );
ctc_eq( '۱٬۴۶۳٬۰۰۰', Util::amount( 1463000 ), 'amount has Persian digits and separators' );
ctc_eq( 'یک میلیون و چهارصد و شصت و سه هزار تومان', Util::amount_words( 1463000 ), 'amount in words with Toman' );
update_option( 'woocommerce_currency', 'IRR' );
ctc_eq( 'ریال', Util::unit(), 'unit is Rial for IRR' );
update_option( 'woocommerce_currency', 'IRT' );

// ---------------------------------------------------------------------
// Gateway registration and availability.
// ---------------------------------------------------------------------
$gateways = WC()->payment_gateways()->payment_gateways();
ctc_ok( isset( $gateways[ Gateway::ID ] ), 'gateway registered' );
ctc_eq( 'yes', $gateways[ Gateway::ID ]->enabled, 'gateway enabled' );
ctc_eq( Settings::get( 'button' ), $gateways[ Gateway::ID ]->order_button_text, 'button text from panel' );

// ---------------------------------------------------------------------
// Placing an order.
// ---------------------------------------------------------------------
[ $order, $result ] = ctc_new_order( $pid, 1 );
$id                 = $order->get_id();
ctc_eq( 'success', $result['result'], 'process_payment success' );
ctc_eq( Orders::url( $order ), $result['redirect'], 'redirects to the payment page' );
ctc_ok( (bool) preg_match( '#/card-pay/' . $id . '-[a-f0-9]{16}/$#', $result['redirect'] ), 'pretty payment URL', $result['redirect'] );
ctc_eq( Orders::AWAITING, $order->get_status(), 'status ctc-receipt' );
ctc_eq( 9, wc_get_product( $pid )->get_stock_quantity(), 'stock held (10 → 9)' );
ctc_ok( count( (array) $order->get_meta( '_ctc_cards' ) ) === count( Settings::cards() ), 'cards snapshotted on the order' );
ctc_ok( abs( Orders::deadline( $order ) - ( time() + DAY_IN_SECONDS ) ) < 120, 'deadline 24h' );
ctc_eq( 1, ctc_jobs( 'ctc_expire', $id ), 'expire job scheduled' );
ctc_eq( 1, ctc_jobs( 'ctc_remind', $id ), 'reminder job scheduled' );
ctc_ok( $order->needs_payment() || ! $order->is_paid(), 'order is not paid' );
ctc_ok( in_array( 'placed', (array) $order->get_meta( '_ctc_sms_sent' ), true ), 'placed SMS sent once' );
$to_customer = array_values( array_filter( $GLOBALS['ctc_mails'], static fn( $m ) => str_contains( $m['to'], 'sara@example.test' ) ) );
ctc_ok( count( $to_customer ) >= 1, 'customer e-mail sent on ctc-receipt', array_column( $GLOBALS['ctc_mails'], 'subject' ) );
ctc_ok( $to_customer && str_contains( $to_customer[0]['body'], Orders::url( $order ) ), 'customer e-mail has the payment link' );
ctc_ok( $to_customer && str_contains( $to_customer[0]['body'], 'linear-gradient' ), 'customer e-mail has the bank-coloured card' );
ctc_ok( count( $GLOBALS['ctc_mails'] ) > count( $to_customer ), 'admin new-order e-mail sent' );

// Payment link security.
ctc_eq( $id, Orders::from_key( Orders::key( $order ) )->get_id(), 'key resolves the order' );
ctc_eq( null, Orders::from_key( $id . '-0000000000000000' ), 'wrong key rejected' );
ctc_eq( null, Orders::from_key( 'abc' ), 'malformed key rejected' );
ctc_eq( null, Orders::from_key( ( $id + 999 ) . '-' . substr( Orders::key( $order ), -16 ) ), 'key of another order rejected' );

// Page args in Toman.
$args = Payment_Page::args( $order );
ctc_eq( 'pay', $args['state'], 'page state pay' );
ctc_eq( '۴۸۰٬۰۰۰', $args['amount']['value'], 'page amount' );
ctc_eq( '480000', $args['amount']['raw'], 'raw amount for copying' );
ctc_eq( 'تومان', $args['amount']['unit'], 'page unit' );
ctc_eq( 'چهارصد و هشتاد هزار تومان', $args['amount']['words'], 'page amount in words' );
ctc_ok( str_contains( $args['title'], 'سارا' ), 'title token {first_name}', $args['title'] );
ctc_ok( ! empty( $args['form']['nonce'] ), 'form present while awaiting' );
ctc_ok( '' !== $args['cards'][0]['logo'] && str_contains( $args['cards'][0]['style'], '--c1:' ), 'card has logo and bank colours' );

// Strict mode: manual status change is undone.
$order->update_status( 'processing' );
$order = wc_get_order( $id );
ctc_eq( Orders::AWAITING, $order->get_status(), 'strict guard keeps ctc-receipt' );
ctc_ok( ! $order->is_paid(), 'still unpaid after blocked change' );

// ---------------------------------------------------------------------
// Receipt → review → reject → receipt → approve.
// ---------------------------------------------------------------------
$fake = array(
	'path' => '2026/11/test.png',
	'name' => 'r.png',
	'type' => 'image/png',
	'size' => 10,
);
Receipts::attach( $order, $fake, '123456', '4321' );
$order = wc_get_order( $id );
ctc_eq( Orders::REVIEW, $order->get_status(), 'receipt → ctc-review' );
ctc_eq( 0, ctc_jobs( 'ctc_expire', $id ), 'jobs dropped in review' );
ctc_eq( 'review', Payment_Page::args( $order )['state'], 'page state review' );
ctc_ok( empty( Payment_Page::args( $order )['form'] ), 'no form in review' );

$r = Orders::reject( $order, 'مبلغ کم است' );
$order = wc_get_order( $id );
ctc_ok( true === $r, 'reject ok' );
ctc_eq( Orders::AWAITING, $order->get_status(), 'reject → ctc-receipt' );
ctc_eq( 'rejected', Orders::receipts( $order )[0]['state'], 'receipt marked rejected' );
ctc_eq( 'مبلغ کم است', Payment_Page::args( $order )['reason'], 'reason shown on the page' );
ctc_eq( 1, ctc_jobs( 'ctc_expire', $id ), 'new deadline job after reject' );
ctc_eq( 9, wc_get_product( $pid )->get_stock_quantity(), 'stock not reduced twice' );
ctc_ok( is_wp_error( Orders::reject( $order, 'x' ) ), 'cannot reject when no receipt is in review' );

Receipts::attach( wc_get_order( $id ), $fake, '987654', '' );
$r     = Orders::approve( wc_get_order( $id ) );
$order = wc_get_order( $id );
ctc_ok( true === $r, 'approve ok' );
ctc_eq( 'processing', $order->get_status(), 'approve → processing' );
ctc_ok( $order->is_paid(), 'order paid' );
ctc_eq( '987654', $order->get_transaction_id(), 'transaction id = bank reference' );
ctc_eq( 9, wc_get_product( $pid )->get_stock_quantity(), 'stock unchanged by payment_complete' );
ctc_eq( 'paid', Payment_Page::args( $order )['state'], 'page state paid' );
ctc_eq( array(), Payment_Page::args( $order )['cards'], 'no card once paid' );
ctc_ok( is_wp_error( Orders::approve( $order ) ), 'cannot approve twice' );
ctc_ok( in_array( 'approved', (array) $order->get_meta( '_ctc_sms_sent' ), true ), 'approved SMS sent' );

// ---------------------------------------------------------------------
// Expiry.
// ---------------------------------------------------------------------
[ $o2 ] = ctc_new_order( $pid, 2 );
ctc_eq( 7, wc_get_product( $pid )->get_stock_quantity(), 'second order holds 2' );
Orders::expire( $o2->get_id() );
ctc_eq( Orders::AWAITING, wc_get_order( $o2->get_id() )->get_status(), 'early expire job does nothing' );
$o2->update_meta_data( '_ctc_deadline', (string) ( time() - 10 ) );
$o2->save_meta_data();
Orders::expire( $o2->get_id() );
$o2 = wc_get_order( $o2->get_id() );
ctc_eq( 'cancelled', $o2->get_status(), 'expired → cancelled' );
ctc_eq( 9, wc_get_product( $pid )->get_stock_quantity(), 'stock returned on cancel' );
ctc_eq( 'cancelled', Payment_Page::args( $o2 )['state'], 'page state cancelled' );
ctc_ok( in_array( 'cancelled', (array) $o2->get_meta( '_ctc_sms_sent' ), true ), 'cancel SMS sent' );

// Non-pro mode → on-hold.
Settings::save( array( 'pro' => false ) );
[ $o3, $r3 ] = ctc_new_order( $pid );
ctc_eq( 'on-hold', $o3->get_status(), 'simple mode → on-hold' );
ctc_ok( ! str_contains( $r3['redirect'], '/card-pay/' ), 'simple mode → thank-you page' );
ctc_eq( 8, wc_get_product( $pid )->get_stock_quantity(), 'simple mode reduces stock' );
Settings::save( array( 'pro' => true ) );

// Card modes.
Settings::save( array( 'card_mode' => 'rotate' ) );
[ $a ] = ctc_new_order( $pid );
[ $b ] = ctc_new_order( $pid );
ctc_eq( 1, count( (array) $a->get_meta( '_ctc_cards' ) ), 'rotate gives one card' );
ctc_ok( count( Settings::cards() ) < 2 || $a->get_meta( '_ctc_cards' )[0]['id'] !== $b->get_meta( '_ctc_cards' )[0]['id'], 'rotate moves to the next card' );
Settings::save( array( 'card_mode' => 'all' ) );

// ---------------------------------------------------------------------
// Settings sanitising.
// ---------------------------------------------------------------------
$clean = Settings::sanitize(
	array(
		'deadline'   => '۹۹۹۹',
		'max_mb'     => '0',
		'ref'        => 'nope',
		'accent'     => 'red',
		'slug'       => 'Pay Now!',
		'sms_admins' => '۰۹۱۲۱۲۳۴۵۶۷, 123, +98 935 000 0000',
		'title'      => '<b>x</b>',
		'cards'      => array(
			array(
				'number' => '۶۱۰۴-۳۳۷۹-۱۲۳۴-۵۶۷۸-۹۹',
				'sheba'  => 'ir 12',
			),
			array( 'number' => '' ),
			'junk',
		),
		'sms_events' => array(
			'placed' => array(
				'on'   => 'true',
				'body' => '۱۲۳a',
				'vars' => '{name} <script>',
			),
		),
	)
);
ctc_eq( 720, $clean['deadline'], 'deadline clamped' );
ctc_eq( 1, $clean['max_mb'], 'max_mb min 1' );
ctc_eq( 'optional', $clean['ref'], 'invalid choice → default' );
ctc_eq( '#4f46e5', $clean['accent'], 'invalid colour → default' );
ctc_eq( 'pay-now', $clean['slug'], 'slug sanitised' );
ctc_eq( '09121234567, 09350000000', $clean['sms_admins'], 'admin numbers normalised' );
ctc_eq( 'x', $clean['title'], 'tags stripped' );
ctc_eq( 1, count( $clean['cards'] ), 'empty / junk cards dropped' );
ctc_eq( '6104337912345678', $clean['cards'][0]['number'], 'card digits normalised to 16' );
ctc_eq( '', $clean['cards'][0]['sheba'], 'bad IBAN dropped' );
ctc_eq( '123', $clean['sms_events']['placed']['body'], 'pattern code digits only' );
ctc_eq( '{name} script', $clean['sms_events']['placed']['vars'], 'vars sanitised' );

// ---------------------------------------------------------------------
// REST.
// ---------------------------------------------------------------------
Settings::save( array( 'mp_pass' => 'secret-key' ) );
$res = rest_do_request( new WP_REST_Request( 'GET', '/ctc/v1/settings' ) );
ctc_eq( 200, $res->get_status(), 'GET settings 200' );
ctc_eq( App::MASK, $res->get_data()['mp_pass'], 'password masked in REST' );
$req = new WP_REST_Request( 'POST', '/ctc/v1/settings' );
$req->set_header( 'content-type', 'application/json' );
$req->set_body( wp_json_encode( array( 'mp_pass' => App::MASK, 'deadline' => 12 ) ) );
$res = rest_do_request( $req );
ctc_eq( 200, $res->get_status(), 'POST settings 200' );
ctc_eq( 'secret-key', Settings::get( 'mp_pass' ), 'mask keeps the stored password' );
ctc_eq( 12, Settings::get( 'deadline' ), 'setting saved via REST' );
Settings::save( array( 'deadline' => 24, 'mp_pass' => '' ) );

$res = rest_do_request( new WP_REST_Request( 'GET', '/ctc/v1/stats' ) );
ctc_eq( 200, $res->get_status(), 'stats 200' );
ctc_ok( $res->get_data()['awaiting'] >= 1, 'stats count awaiting' );

$res = rest_do_request( new WP_REST_Request( 'GET', '/ctc/v1/receipts' ) );
ctc_eq( 200, $res->get_status(), 'receipts 200' );

[ $o4 ] = ctc_new_order( $pid );
Receipts::attach( $o4, $fake, '555', '' );
$req = new WP_REST_Request( 'POST', '/ctc/v1/orders/' . $o4->get_id() . '/reject' );
$req->set_param( 'reason', 'تست' );
$res = rest_do_request( $req );
ctc_eq( 200, $res->get_status(), 'REST reject 200' );
ctc_eq( Orders::AWAITING, $res->get_data()['status'], 'REST reject status' );
$res = rest_do_request( new WP_REST_Request( 'POST', '/ctc/v1/orders/' . $o4->get_id() . '/reject' ) );
ctc_eq( 409, $res->get_status(), 'REST reject wrong state 409' );
$res = rest_do_request( new WP_REST_Request( 'POST', '/ctc/v1/orders/' . $o4->get_id() . '/approve' ) );
ctc_eq( 'processing', $res->get_data()['status'], 'REST approve' );

wp_set_current_user( 0 );
foreach ( array( array( 'GET', '/ctc/v1/settings' ), array( 'GET', '/ctc/v1/receipts' ), array( 'POST', '/ctc/v1/orders/' . $id . '/approve' ), array( 'POST', '/ctc/v1/sms/test' ) ) as [ $m, $route ] ) {
	ctc_eq( 401, rest_do_request( new WP_REST_Request( $m, $route ) )->get_status(), "anonymous $m $route → 401" );
}
$customer = wp_insert_user( array( 'user_login' => 'cust' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'customer' ) );
wp_set_current_user( $customer );
ctc_eq( 403, rest_do_request( new WP_REST_Request( 'GET', '/ctc/v1/settings' ) )->get_status(), 'customer → 403' );
wp_set_current_user( 1 );
wp_delete_user( $customer );

// ---------------------------------------------------------------------
// SMS with mocked Melipayamak.
// ---------------------------------------------------------------------
$calls = array();
$reply = array();
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$calls, &$reply ) {
		if ( ! str_contains( $url, 'payamak-panel.com' ) ) {
			return $pre;
		}
		$method  = basename( $url );
		$calls[] = array( 'method' => $method, 'body' => $args['body'] );
		$value   = $reply[ $method ] ?? array( 'Value' => '0', 'RetStatus' => 0, 'StrRetStatus' => 'x' );
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $value ),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
		);
	},
	10,
	3
);

Settings::save(
	array(
		'sms_driver' => 'melipayamak',
		'mp_mode'    => 'pattern',
		'mp_user'    => 'user',
		'mp_pass'    => 'key',
		'mp_from'    => '50001234',
		'sms_events' => array(
			'placed' => array( 'on' => true, 'body' => '777', 'vars' => '{name} {order} {total}' ),
		),
	)
);
$reply['GetCredit'] = array( 'Value' => '1523.5', 'RetStatus' => 1, 'StrRetStatus' => 'Ok' );
$test               = Sender::test();
ctc_ok( $test['ok'] && 1523.5 === $test['credit'], 'connection test reads credit', $test );

$reply['GetCredit'] = array( 'Value' => '0', 'RetStatus' => 0, 'StrRetStatus' => 'InvalidUser' );
$test               = Sender::test();
ctc_ok( ! $test['ok'] && str_contains( $test['message'], 'نام کاربری یا رمز' ), 'connection test reports wrong credentials', $test );

$reply['GetCredit']         = array( 'Value' => '100', 'RetStatus' => 1, 'StrRetStatus' => 'Ok' );
$reply['BaseServiceNumber'] = array( 'Value' => '2185479656544521', 'RetStatus' => 1, 'StrRetStatus' => 'Ok' );
$calls                      = array();
$test                       = Sender::test( '09123456789', 'placed' );
$last                       = end( $calls );
ctc_ok( true === $test['sent'], 'pattern test message sent', $test );
ctc_eq( 'BaseServiceNumber', $last['method'], 'pattern mode uses BaseServiceNumber' );
ctc_eq( '777', $last['body']['bodyId'], 'pattern code sent' );
ctc_eq( '09123456789', $last['body']['to'], 'mobile normalised' );
ctc_eq( 3, count( explode( ';', $last['body']['text'] ) ), 'three pattern variables in order' );

[ $o5 ]   = ctc_new_order( $pid );
$last     = end( $calls );
$expected = implode( ';', array( 'سارا محمدی', (string) $o5->get_order_number(), '۴۸۰٬۰۰۰ تومان' ) );
ctc_eq( $expected, $last['body']['text'], 'order SMS variables: name;order;total (Toman)' );

$reply['BaseServiceNumber'] = array( 'Value' => '-4', 'RetStatus' => 0, 'StrRetStatus' => 'x' );
$r                          = Sender::send( '09123456789', '777', array( 'a' ), '', 'placed' );
ctc_ok( is_wp_error( $r ) && str_contains( $r->get_error_message(), 'کد الگو' ), 'error -4 explained in Persian', is_wp_error( $r ) ? $r->get_error_message() : $r );
ctc_ok( is_wp_error( Sender::send( '09123456789', '', array(), '', 'x' ) ), 'missing pattern code is an error' );
ctc_eq( 'a،b', Melipayamak::clean_arg( "a;b\n" ), 'separator stripped from variables' );

// Line mode (dedicated / shared number).
Settings::save(
	array(
		'mp_mode'    => 'line',
		'sms_events' => array(
			'placed' => array( 'on' => true, 'text' => '{name} سفارش {order} مبلغ {total}' ),
		),
	)
);
$reply['SendSMS'] = array( 'Value' => '5465464654654', 'RetStatus' => 1, 'StrRetStatus' => 'Ok' );
$calls            = array();
[ $o6 ]           = ctc_new_order( $pid );
$last             = end( $calls );
ctc_eq( 'SendSMS', $last['method'], 'line mode uses SendSMS' );
ctc_eq( '50001234', $last['body']['from'], 'sender number sent' );
ctc_eq( 'سارا محمدی سفارش ' . $o6->get_order_number() . ' مبلغ ۴۸۰٬۰۰۰ تومان', $last['body']['text'], 'line text with tokens' );
ctc_ok( in_array( 'placed', (array) wc_get_order( $o6->get_id() )->get_meta( '_ctc_sms_sent' ), true ), 'line SMS recorded as sent' );
$reply['SendSMS'] = array( 'Value' => '14', 'RetStatus' => 0, 'StrRetStatus' => 'x' );
$r                = Sender::send( '09123456789', '', array(), 'x https://a.b', 'x' );
ctc_ok( is_wp_error( $r ) && str_contains( $r->get_error_message(), 'لینک' ), 'line error 14 (link) explained' );
Settings::save( array( 'mp_from' => '' ) );
ctc_ok( is_wp_error( Sender::send( '09123456789', '', array(), 'x', 'x' ) ), 'missing sender number is an error' );

// Once per order; admin events go to admin numbers.
$calls = array();
Events::job( $o6->get_id(), 'placed', true );
ctc_eq( 0, count( $calls ), 'placed not sent twice' );
Settings::save( array( 'mp_from' => '50001234', 'sms_admins' => '09350000000, 09360000000' ) );
$reply['SendSMS'] = array( 'Value' => '5465464654654', 'RetStatus' => 1, 'StrRetStatus' => 'Ok' );
Events::job( $o6->get_id(), 'admin_receipt', false );
ctc_eq( array( '09350000000', '09360000000' ), array_column( array_column( $calls, 'body' ), 'to' ), 'admin SMS to every admin number' );

// Off → nothing sent.
Settings::save( array( 'sms_driver' => 'off' ) );
$calls = array();
Events::queue( $o6->get_id(), 'remind', false );
ctc_eq( 0, count( $calls ), 'driver off sends nothing' );
ctc_ok( count( Sender::get_log() ) > 0, 'send log written' );

// ---------------------------------------------------------------------
// Orders & My Account settings.
// ---------------------------------------------------------------------
Settings::save( array( 'sms_driver' => 'off', 'paid_status' => 'completed', 'customer_note' => true ) );
[ $p1 ] = ctc_new_order( $pid );
Receipts::attach( $p1, $fake, '1', '' );
Orders::approve( wc_get_order( $p1->get_id() ) );
$p1 = wc_get_order( $p1->get_id() );
ctc_eq( 'completed', $p1->get_status(), 'paid status «completed» from the panel' );
$notes = wc_get_order_notes( array( 'order_id' => $p1->get_id(), 'type' => 'customer' ) );
ctc_ok( count( $notes ) >= 1, 'approval note is a customer note' );
Settings::save( array( 'paid_status' => 'auto' ) );

$stock = wc_get_product( $pid )->get_stock_quantity();
Settings::save( array( 'expire_status' => 'failed' ) );
[ $p2 ] = ctc_new_order( $pid );
ctc_eq( $stock - 1, wc_get_product( $pid )->get_stock_quantity(), 'stock held before expiry' );
$p2->update_meta_data( '_ctc_deadline', (string) ( time() - 10 ) );
$p2->save_meta_data();
Orders::expire( $p2->get_id() );
ctc_eq( 'failed', wc_get_order( $p2->get_id() )->get_status(), 'expiry status «failed» from the panel' );
ctc_eq( $stock, wc_get_product( $pid )->get_stock_quantity(), 'failed expiry returns stock' );
Settings::save( array( 'expire_status' => 'cancelled' ) );

ctc_ok( in_array( Orders::AWAITING, wc_get_order_statuses() ? apply_filters( 'woocommerce_valid_order_statuses_for_cancel', array( 'pending' ), $p2 ) : array(), true ), 'customer can cancel while awaiting' );
Settings::save( array( 'customer_cancel' => false ) );
ctc_ok( ! in_array( Orders::AWAITING, apply_filters( 'woocommerce_valid_order_statuses_for_cancel', array( 'pending' ), $p2 ), true ), 'customer cancel can be switched off' );
Settings::save( array( 'customer_cancel' => true ) );

[ $p3 ]  = ctc_new_order( $pid );
$actions = apply_filters( 'woocommerce_my_account_my_orders_actions', array( 'pay' => array( 'url' => '#', 'name' => 'pay' ) ), $p3 );
ctc_ok( isset( $actions['ctc-receipt'] ) && ! isset( $actions['pay'] ), 'My Account: «ارسال رسید» replaces «پرداخت»' );
Settings::save( array( 'account_action' => false ) );
$actions = apply_filters( 'woocommerce_my_account_my_orders_actions', array(), $p3 );
ctc_ok( ! isset( $actions['ctc-receipt'] ), 'My Account action can be switched off' );
Settings::save( array( 'account_action' => true ) );
ob_start();
Payment_Page::render_box( $p3 );
$box = (string) ob_get_clean();
ctc_ok( str_contains( $box, 'ctc-plate' ) && str_contains( $box, Orders::url( $p3 ) ), 'My Account / thank-you box has the card and the receipt button' );
ctc_ok( str_contains( $box, 'تومان' ), 'box amount in Toman' );

// ---------------------------------------------------------------------
// Custom receipt fields.
// ---------------------------------------------------------------------
$clean = Settings::sanitize_fields(
	array(
		array( 'label' => 'ساعت واریز', 'type' => 'time', 'required' => true ),
		array( 'label' => 'بانک مبدأ', 'type' => 'select', 'options' => "ملت\nملی" ),
		array( 'label' => '', 'type' => 'text' ),
		array( 'label' => 'x', 'type' => 'evil' ),
	)
);
ctc_eq( 3, count( $clean ), 'fields without a label dropped' );
ctc_eq( 'text', $clean[2]['type'], 'unknown field type → text' );
Settings::save( array( 'fields' => $clean, 'ref_label' => 'کد رهگیری' ) );
$args = Payment_Page::args( $p3 );
ctc_eq( 3, count( $args['form']['fields'] ), 'fields reach the form' );
ctc_eq( array( 'ملت', 'ملی' ), $args['form']['fields'][1]['choices'], 'select choices parsed' );
ctc_eq( 'کد رهگیری', $args['form']['labels']['ref'], 'built-in label editable' );
ob_start();
Payment_Page::part( 'payment-page.php', $args );
$html = (string) ob_get_clean();
ctc_ok( str_contains( $html, 'name="ctc_f[' . $clean[0]['id'] . ']"' ) && str_contains( $html, 'type="time"' ), 'custom fields rendered' );
ctc_ok( str_contains( $html, 'کد رهگیری' ), 'custom label rendered' );
ctc_ok( ! str_contains( $html, '>1<' ) && str_contains( $html, '<span>۱</span>' ), 'step numbers in Persian digits' );
ctc_ok( ! str_contains( $html, '·' ), 'no middle dot (renders like ۰ in Persian fonts)' );
Settings::save( array( 'fields' => array(), 'ref_label' => Settings::defaults()['ref_label'] ) );

// ---------------------------------------------------------------------
// Receipt file retention.
// ---------------------------------------------------------------------
$dir = Receipts::dir();
wp_mkdir_p( $dir . '/2026/11' );
$make = static function ( string $name ) use ( $dir ): array {
	file_put_contents( $dir . '/2026/11/' . $name, 'x' ); // phpcs:ignore
	return array( 'path' => '2026/11/' . $name, 'name' => $name, 'type' => 'image/png', 'size' => 1 );
};
[ $k1 ] = ctc_new_order( $pid );
Receipts::attach( $k1, $make( 'ret-rejected.png' ), '', '' );
Orders::reject( wc_get_order( $k1->get_id() ), 'x' );
Receipts::attach( wc_get_order( $k1->get_id() ), $make( 'ret-approved.png' ), '', '' );
Orders::approve( wc_get_order( $k1->get_id() ) );
Settings::save( array( 'keep_approved' => 0, 'keep_rejected' => 30, 'keep_cancelled' => 0 ) );
ctc_eq( 0, Receipts::cleanup( time() + 29 * DAY_IN_SECONDS ), 'nothing deleted before 30 days' );
ctc_ok( Receipts::cleanup( time() + 31 * DAY_IN_SECONDS ) >= 1, 'rejected receipts deleted after 30 days' );
ctc_ok( ! file_exists( $dir . '/2026/11/ret-rejected.png' ) && file_exists( $dir . '/2026/11/ret-approved.png' ), 'only the rejected file removed; approved kept forever (0)' );
$r = Orders::receipts( wc_get_order( $k1->get_id() ) );
ctc_ok( ! empty( $r[0]['deleted'] ) && 'rejected' === $r[0]['state'], 'record kept with «deleted» flag' );
Settings::save( array( 'keep_approved' => 7 ) );
ctc_ok( Receipts::cleanup( time() + 8 * DAY_IN_SECONDS ) >= 1 && ! file_exists( $dir . '/2026/11/ret-approved.png' ), 'approved receipt deleted after 7 days' );
ctc_eq( 0, Receipts::cleanup( time() + 99 * DAY_IN_SECONDS ), 'deleted files are not counted twice' );

[ $k2 ] = ctc_new_order( $pid );
Receipts::attach( $k2, $make( 'ret-cancel.png' ), '', '' );
$k2 = wc_get_order( $k2->get_id() );
$k2->update_status( 'cancelled' );
Settings::save( array( 'keep_approved' => 0, 'keep_rejected' => 0, 'keep_cancelled' => 10 ) );
ctc_ok( Receipts::cleanup( time() + 11 * DAY_IN_SECONDS ) >= 1 && ! file_exists( $dir . '/2026/11/ret-cancel.png' ), 'cancelled order receipts deleted after 10 days' );

[ $k3 ] = ctc_new_order( $pid );
Receipts::attach( $k3, $make( 'ret-delete.png' ), '', '' );
Settings::save( array( 'delete_with_order' => false ) );
wc_get_order( $k3->get_id() )->delete( true );
ctc_ok( file_exists( $dir . '/2026/11/ret-delete.png' ), 'file kept when «delete with order» is off' );
[ $k4 ] = ctc_new_order( $pid );
Receipts::attach( $k4, $make( 'ret-delete2.png' ), '', '' );
Settings::save( array( 'delete_with_order' => true ) );
wc_get_order( $k4->get_id() )->delete( true );
ctc_ok( ! file_exists( $dir . '/2026/11/ret-delete2.png' ), 'file deleted with the order' );
wp_delete_file( $dir . '/2026/11/ret-delete.png' );
ctc_ok( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'ctc_cleanup', array(), Orders::GROUP ), 'daily cleanup job scheduled' );

// Stock not held: reduced only on approval.
Settings::save( array( 'hold_stock' => false ) );
$before = wc_get_product( $pid )->get_stock_quantity();
[ $h1 ] = ctc_new_order( $pid );
ctc_eq( $before, wc_get_product( $pid )->get_stock_quantity(), 'no stock reserved when «رزرو موجودی» is off' );
Receipts::attach( $h1, $fake, '', '' );
Orders::approve( wc_get_order( $h1->get_id() ) );
ctc_eq( $before - 1, wc_get_product( $pid )->get_stock_quantity(), 'stock reduced on approval' );
Settings::save( array( 'hold_stock' => true ) );

// ---------------------------------------------------------------------
// Colours: readable ink, theme accent, card look follows the panel.
// ---------------------------------------------------------------------
ctc_eq( '#111827', \CardToCard\Support\Colors::ink( '#ffee58' ), 'dark text on a light colour' );
ctc_eq( '#ffffff', \CardToCard\Support\Colors::ink( '#4f46e5' ), 'white text on a dark colour' );
ctc_eq( '#111827', \CardToCard\Support\Colors::ink( '#1e3a8a', '#fde68a' ), 'the lightest gradient stop decides' );
ctc_eq( '#aabbcc', \CardToCard\Support\Colors::hex( '#ABC' ), 'short hex expanded' );
ctc_eq( '#ff8000', \CardToCard\Support\Colors::hex( 'rgb(255, 128, 0)' ), 'rgb() read' );
add_filter(
	'ctc_theme_colors',
	static fn( $colors ) => array_merge(
		(array) $colors,
		array(
			array(
				'slug'  => 'wp:test-accent',
				'name'  => 'Test',
				'color' => '#ffee58',
				'css'   => 'var(--wp--preset--color--test-accent, #ffee58)',
			),
		)
	)
);
Settings::save(
	array(
		'accent'        => '#123456',
		'accent_source' => 'theme',
		'accent_theme'  => 'wp:test-accent',
	)
);
ctc_eq( '#ffee58', Settings::accent()['hex'], 'theme accent resolved' );
ctc_ok( str_contains( Payment_Page::inline_css(), '--ctc-accent:var(--wp--preset--color--test-accent, #ffee58)' ), 'page follows the theme variable', Payment_Page::inline_css() );
ctc_ok( str_contains( Payment_Page::inline_css(), '--ctc-on-accent:#111827' ), 'dark button text on a light theme accent' );
Settings::save( array( 'accent_theme' => 'wp:gone' ) );
ctc_eq( '#123456', Settings::accent()['hex'], 'colour missing from the theme → panel colour' );
Settings::save( array( 'accent_source' => 'custom' ) );
ctc_ok( str_contains( Payment_Page::inline_css(), '--ctc-accent:#123456;--ctc-on-accent:#ffffff' ), 'custom accent with white text' );

$look_cards = (array) Settings::get( 'cards' );
[ $lk ]     = ctc_new_order( $pid );
$given      = (string) $lk->get_meta( '_ctc_cards' )[0]['id'];
$changed    = $look_cards;
foreach ( $changed as $i => $c ) {
	if ( $c['id'] === $given ) {
		$changed[ $i ]['skin']   = 'custom';
		$changed[ $i ]['color1'] = '#ff0000';
		$changed[ $i ]['color2'] = '#00aa00';
	}
}
Settings::save( array( 'cards' => $changed ) );
$view = Payment_Page::card_view( Orders::cards( wc_get_order( $lk->get_id() ) )[0] );
ctc_eq( 'custom', $view['skin'], 'custom skin reaches an existing order' );
ctc_ok( str_contains( $view['style'], '--c1:#ff0000' ), 'custom colours reach an existing order', $view['style'] );
foreach ( $changed as $i => $c ) {
	if ( $c['id'] === $given ) {
		$changed[ $i ]['number'] = '6037991111111111';
		$changed[ $i ]['color1'] = '#0000ff';
	}
}
Settings::save( array( 'cards' => $changed ) );
$kept = Orders::cards( wc_get_order( $lk->get_id() ) )[0];
ctc_ok( '6037991111111111' !== $kept['number'], 'number stays as given to the customer' );
ctc_ok( '#0000ff' !== ( $kept['color1'] ?? '' ), 'a replaced card number does not borrow the new look' );
Settings::save( array( 'cards' => $look_cards ) );
$lk->delete( true );

// ---------------------------------------------------------------------
// Coming back later: payment link, form token, rate limits, upload size, My Account, SMS link.
// ---------------------------------------------------------------------
/**
 * Open the payment page for a key as a visitor (GET) and return the order it resolved to.
 *
 * @param string $key Link key.
 * @return WC_Order|null
 */
function ctc_visit( string $key ): ?WC_Order {
	$prop = new ReflectionProperty( Payment_Page::class, 'order' );
	$prop->setAccessible( true );
	$prop->setValue( null, null );
	set_query_var( Payment_Page::QUERY_VAR, $key );
	@Payment_Page::template_redirect(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- header() in CLI.
	set_query_var( Payment_Page::QUERY_VAR, '' );
	$GLOBALS['wp_query']->is_404 = false;
	return Payment_Page::order();
}

/**
 * Reset the payment page's form state between simulated posts.
 */
function ctc_form_reset(): void {
	$prop = new ReflectionProperty( Payment_Page::class, 'form' );
	$prop->setAccessible( true );
	$prop->setValue(
		null,
		array(
			'errors' => array(),
			'old'    => array(),
		)
	);
}

/**
 * Forget a rate-limit bucket of the current test address.
 *
 * @param string $action Action.
 * @param string $extra  Extra key part.
 */
function ctc_rl_clear( string $action, string $extra ): void {
	delete_transient( 'ctc_rl_' . md5( $action . '|' . wp_hash( (string) $_SERVER['REMOTE_ADDR'] ) . '|' . $extra ) );
}

$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
[ $cb ]                 = ctc_new_order( $pid );
[ $cb2 ]                = ctc_new_order( $pid );
$cb_key                 = Orders::key( $cb );
$cb_bad                 = $cb->get_id() . '-0000000000000000';

// A valid link opens any number of times, also when many customers share one address.
$opened = 0;
for ( $i = 0; $i < 80; $i++ ) {
	$opened += ctc_visit( 0 === $i % 2 ? $cb_key : Orders::key( $cb2 ) ) ? 1 : 0;
}
ctc_eq( 80, $opened, 'valid links never hit the rate limit (shared IP / CDN)' );
for ( $i = 0; $i < 20; $i++ ) {
	ctc_visit( $cb_bad );
}
ctc_eq( null, ctc_visit( $cb_key ), 'after 20 wrong keys for an order, that order is locked for the guessing address' );
ctc_ok( null !== ctc_visit( Orders::key( $cb2 ) ), 'other orders from the same address still open' );
$_SERVER['REMOTE_ADDR'] = '203.0.113.8';
ctc_ok( null !== ctc_visit( $cb_key ), 'the customer on another address is not affected' );
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
ctc_rl_clear( 'key', (string) $cb->get_id() );
ctc_ok( null !== ctc_visit( $cb_key ), 'lock lifts with its window' );

// Form token: bound to the order, not to the visitor or the clock.
$cb_args = Payment_Page::args( $cb );
ctc_eq( Orders::form_token( $cb ), $cb_args['form']['nonce'], 'form carries the order token' );
ctc_ok( Orders::form_token( $cb ) !== Orders::form_token( $cb2 ), 'token differs per order' );
wp_set_current_user( 0 );
ctc_eq( Orders::form_token( $cb ), Payment_Page::args( $cb )['form']['nonce'], 'same token logged out' );
wp_set_current_user( 1 );
$post = static function ( array $fields ) use ( $cb ): array {
	ctc_form_reset();
	$_POST = $fields;
	Payment_Page::handle_post( wc_get_order( $cb->get_id() ) );
	$_POST = array();
	return Payment_Page::form_state()['errors'];
};
$errs = $post( array( 'ctc_nonce' => Orders::form_token( $cb ) ) );
ctc_ok( ! isset( $errs['form'] ) && isset( $errs['file'] ), 'order token accepted (reaches the file check)', $errs );
$errs = $post( array( 'ctc_nonce' => wp_create_nonce( 'ctc_receipt_' . $cb->get_id() ) ) );
ctc_ok( ! isset( $errs['form'] ), 'WordPress nonce of an older open page still accepted', $errs );
$errs = $post( array( 'ctc_nonce' => Orders::form_token( $cb2 ) ) );
ctc_ok( isset( $errs['form'] ), 'token of another order refused' );
$errs = $post( array( 'ctc_nonce' => '' ) );
ctc_ok( isset( $errs['form'] ), 'empty token refused' );
ctc_rl_clear( 'receipt', (string) $cb->get_id() );

// Mistakes in the form never lock the customer out; real upload attempts are limited.
Settings::save( array( 'ref' => 'required' ) );
for ( $i = 0; $i < 15; $i++ ) {
	$errs = $post( array( 'ctc_nonce' => Orders::form_token( $cb ) ) );
}
ctc_ok( isset( $errs['ref'] ) && ! isset( $errs['form'] ), '15 posts with a missing field: still only the field error', $errs );
Settings::save( array( 'ref' => 'optional' ) );
for ( $i = 0; $i < 10; $i++ ) {
	$errs = $post( array( 'ctc_nonce' => Orders::form_token( $cb ) ) );
}
ctc_ok( isset( $errs['file'] ) && ! isset( $errs['form'] ), '10 upload attempts allowed', $errs );
$errs = $post( array( 'ctc_nonce' => Orders::form_token( $cb ) ) );
ctc_ok( isset( $errs['form'] ), '11th upload attempt in an hour refused', $errs );
ctc_rl_clear( 'receipt', (string) $cb->get_id() );

// Upload size: the smaller of the panel and the server limit, and a clear message.
$small = static fn(): int => 2 * MB_IN_BYTES;
add_filter( 'upload_size_limit', $small );
ctc_eq( 2 * MB_IN_BYTES, Payment_Page::max_bytes(), 'server limit wins when smaller than the panel' );
ctc_eq( 2, Payment_Page::args( $cb )['form']['max_mb'], 'form shows the real limit' );
ctc_eq( 2 * MB_IN_BYTES, Payment_Page::args( $cb )['form']['max_size'], 'browser check uses the real limit' );
remove_filter( 'upload_size_limit', $small );
$large = static fn(): int => 64 * MB_IN_BYTES;
add_filter( 'upload_size_limit', $large );
ctc_eq( 5 * MB_IN_BYTES, Payment_Page::max_bytes(), 'panel limit when the server allows more' );
remove_filter( 'upload_size_limit', $large );
$too_big = Receipts::store(
	array(
		'name'     => 'r.jpg',
		'type'     => 'image/jpeg',
		'tmp_name' => '',
		'error'    => UPLOAD_ERR_INI_SIZE,
		'size'     => 0,
	)
);
ctc_ok( is_wp_error( $too_big ) && str_contains( $too_big->get_error_message(), 'مگابایت' ), 'server-refused size says «too big», not «try again»' );
ctc_form_reset();
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['CONTENT_LENGTH'] = (string) ( 50 * MB_IN_BYTES );
ctc_visit( $cb_key );
unset( $_SERVER['REQUEST_METHOD'], $_SERVER['CONTENT_LENGTH'] );
ctc_ok( str_contains( (string) ( Payment_Page::form_state()['errors']['file'] ?? '' ), 'مگابایت' ), 'a post over post_max_size shows the size error' );
ctc_form_reset();

// Quick jump to the form at the top of the page.
ob_start();
Payment_Page::part( 'payment-page.php', Payment_Page::args( $cb ) );
$html = (string) ob_get_clean();
ctc_ok( str_contains( $html, 'href="#ctc-form" data-ctc-jump' ) && str_contains( $html, 'id="ctc-form"' ), 'hero has the «ارسال رسید» jump to the form' );

// My Account and SMS point to the same no-login link; it opens logged out.
$acts = apply_filters( 'woocommerce_my_account_my_orders_actions', array(), $cb );
ctc_eq( Orders::url( $cb ), $acts['ctc-receipt']['url'] ?? '', 'My Account «ارسال رسید» = payment link' );
ctc_eq( Orders::url( $cb ), \CardToCard\Sms\Events::tokens( $cb )['link'], 'SMS {link} = payment link' );
ctc_ok( str_ends_with( Orders::url( $cb ), '/' . Orders::key( $cb ) . '/' ), 'SMS {key} completes the pattern URL', Orders::url( $cb ) );
foreach ( array( 'placed', 'rejected', 'remind' ) as $ev ) {
	ctc_ok( str_contains( \CardToCard\Sms\Events::all()[ $ev ]['text'], '{link}' ), "default «{$ev}» text carries the link" );
}
wp_set_current_user( 0 );
ctc_ok( null !== ctc_visit( $cb_key ), 'payment link opens without login' );
wp_set_current_user( 1 );
Receipts::attach( wc_get_order( $cb->get_id() ), $fake, '', '' );
$acts = apply_filters( 'woocommerce_my_account_my_orders_actions', array(), wc_get_order( $cb->get_id() ) );
ctc_ok( ! isset( $acts['ctc-receipt'] ), 'no «ارسال رسید» while the receipt is reviewed' );
Orders::reject( wc_get_order( $cb->get_id() ), 'تار است' );
$acts = apply_filters( 'woocommerce_my_account_my_orders_actions', array(), wc_get_order( $cb->get_id() ) );
ctc_ok( isset( $acts['ctc-receipt'] ), '«ارسال رسید» is back after a rejection' );
ctc_eq( Orders::form_token( $cb ), Payment_Page::args( wc_get_order( $cb->get_id() ) )['form']['nonce'], 'same link and token after a rejection' );

// Cancelled by hand vs. by the deadline: the right text.
wc_get_order( $cb2->get_id() )->update_status( 'cancelled' );
$cancel_text = Payment_Page::args( wc_get_order( $cb2->get_id() ) )['text'];
ctc_ok( '' !== $cancel_text && ! str_contains( $cancel_text, 'مهلت' ), 'cancelled by hand: no «deadline passed» text', $cancel_text );
$cb3 = wc_get_order( ctc_new_order( $pid )[0]->get_id() );
$cb3->update_meta_data( '_ctc_deadline', (string) ( time() - 10 ) );
$cb3->save_meta_data();
Orders::expire( $cb3->get_id() );
ctc_eq( Util::tokens( (string) Settings::get( 'text_expired' ), Orders::text_tokens( $cb3 ) ), Payment_Page::args( wc_get_order( $cb3->get_id() ) )['text'], 'expired: the deadline text' );

// An order put into «در انتظار رسید» by hand gets its card too.
$manual = wc_create_order();
$manual->add_product( wc_get_product( $pid ), 1 );
$manual->set_payment_method( Gateway::ID );
$manual->calculate_totals();
$manual->save();
$manual->update_status( Orders::AWAITING );
ctc_ok( is_array( wc_get_order( $manual->get_id() )->get_meta( '_ctc_cards' ) ) && wc_get_order( $manual->get_id() )->get_meta( '_ctc_cards' ), 'admin-made order gets its card on «در انتظار رسید»' );

// Amount in rial beside a toman price: many banking apps ask for rial.
$currency = get_option( 'woocommerce_currency' );
update_option( 'woocommerce_currency', 'IRT' );
$amt = Payment_Page::amount( $cb );
ctc_eq( (string) ( (int) round( (float) $cb->get_total() ) * 10 ), $amt['rial']['raw'] ?? '', 'toman shop: amount in rial = ×10' );
ob_start();
Payment_Page::part( 'payment-page.php', Payment_Page::args( wc_get_order( $cb->get_id() ) ) );
$html = (string) ob_get_clean();
ctc_ok( str_contains( $html, 'class="ctc-rial" data-copy="' . ( $amt['rial']['raw'] ?? 'x' ) . '"' ), 'payment page offers the rial amount to copy' );
update_option( 'woocommerce_currency', 'IRHT' );
ctc_eq( (string) ( (int) round( (float) $cb->get_total() * 10000 ) ), Payment_Page::amount( $cb )['rial']['raw'] ?? '', 'thousand-toman shop: ×10000' );
update_option( 'woocommerce_currency', 'IRR' );
ctc_eq( array(), Payment_Page::amount( $cb )['rial'], 'rial shop: no second figure' );
update_option( 'woocommerce_currency', $currency );

// Browser texts for «۲ ساعت و ۱۰ دقیقه مانده», image shrinking and the single submit.
Payment_Page::assets( true );
$data = (string) wp_scripts()->get_data( 'ctc-payment', 'data' );
foreach ( array( 'hm', 'h', 'm', 'wait', 'prep', 'change', 'kb', 'mb' ) as $k ) {
	ctc_ok( str_contains( $data, '"' . $k . '":' ), "ctcPay.{$k} is sent to the browser" );
}

foreach ( array( $cb, $cb2, $cb3, $manual ) as $o ) {
	$o->delete( true );
}
unset( $_SERVER['REMOTE_ADDR'] );

// ---------------------------------------------------------------------
// Clean up.
// ---------------------------------------------------------------------
update_option( Settings::OPTION, $saved_settings );
Settings::flush();
foreach ( array( $order, $o2, $o3, $a, $b, $o4, $o5, $o6, $p1, $p2, $p3, $k1, $k2, $h1 ) as $o ) {
	$o->delete( true );
}
$product->delete( true );

$t = $GLOBALS['ctc_t'];
WP_CLI::log( sprintf( 'Integration: %d passed, %d failed.', $t['pass'], count( $t['fail'] ) ) );
foreach ( $t['fail'] as $f ) {
	WP_CLI::log( '  ✗ ' . $f );
}
if ( $t['fail'] ) {
	WP_CLI::halt( 1 );
}
