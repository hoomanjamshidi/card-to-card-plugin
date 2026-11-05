<?php
/**
 * Helper for http.sh: places a card-to-card order and prints «id url».
 *
 * @package CardToCard
 */

$product = new WC_Product_Simple();
$product->set_name( 'محصول تست HTTP' );
$product->set_regular_price( '1463000' );
$product->save();
$order = wc_create_order();
$order->add_product( $product, 1 );
$order->set_billing_first_name( 'سارا' );
$order->set_billing_last_name( 'محمدی' );
$order->set_billing_phone( '09123456789' );
$order->set_payment_method( \CardToCard\Gateway::ID );
$order->calculate_totals();
$order->save();
$result = WC()->payment_gateways()->payment_gateways()[ \CardToCard\Gateway::ID ]->process_payment( $order->get_id() );
echo $order->get_id() . ' ' . $result['redirect'] . "\n";
