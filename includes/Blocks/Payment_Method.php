<?php
/**
 * Card to card in the block checkout (WooCommerce Cart & Checkout blocks).
 *
 * @package CardToCard
 */

namespace CardToCard\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use CardToCard\Gateway;
use CardToCard\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Block checkout integration.
 */
final class Payment_Method extends AbstractPaymentMethodType {

	/**
	 * Payment method name (the gateway id).
	 *
	 * @var string
	 */
	protected $name = Gateway::ID;

	/**
	 * Load settings.
	 */
	public function initialize(): void {
		$this->settings = (array) get_option( 'woocommerce_' . Gateway::ID . '_settings', array() );
	}

	/**
	 * Active when the gateway is available.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		return isset( $gateways[ Gateway::ID ] ) && 'yes' === $gateways[ Gateway::ID ]->enabled && Settings::cards();
	}

	/**
	 * Script handles.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles(): array {
		wp_register_script(
			'ctc-blocks',
			CTC_URL . 'assets/js/blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			CTC_VERSION,
			true
		);
		return array( 'ctc-blocks' );
	}

	/**
	 * Data passed to the script.
	 *
	 * @return array<string, mixed>
	 */
	public function get_payment_method_data(): array {
		return array(
			'title'       => (string) Settings::get( 'title' ),
			'description' => (string) Settings::get( 'description' ),
			'button'      => (string) Settings::get( 'button' ),
			'supports'    => array( 'products' ),
		);
	}
}
