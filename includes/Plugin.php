<?php
/**
 * Bootstrap: loads after WooCommerce, registers the gateway (classic + blocks) and every module.
 *
 * @package CardToCard
 */

namespace CardToCard;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap.
 */
final class Plugin {

	/**
	 * Start (plugins_loaded).
	 */
	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'textdomain' ), 0 );
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'needs_woocommerce' ) );
			return;
		}
		add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'gateway' ) );
		add_action( 'woocommerce_blocks_payment_method_type_registration', array( __CLASS__, 'blocks' ) );
		add_action( 'woocommerce_before_delete_order', array( __CLASS__, 'delete_order' ), 10, 2 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'flush_count' ) );

		Orders::init();
		Receipts::init();
		Payment_Page::init();
		Frontend::init();
		Sms\Events::init();
		Rest\Controller::init();
		add_action( 'init', array( Modules\Registry::class, 'boot' ), 1 );
		add_action( 'ctc_settings_saved', array( Modules\Registry::class, 'boot' ) ); // A module switched on now works in this request.
		if ( is_admin() ) {
			Admin\App::init();
			Admin\Order_Box::init();
		}
	}

	/**
	 * Translations (init, as WordPress 6.7+ requires).
	 */
	public static function textdomain(): void {
		load_plugin_textdomain( 'card-to-card', false, dirname( plugin_basename( CTC_FILE ) ) . '/languages' );
	}

	/**
	 * Register the gateway.
	 *
	 * @param string[] $gateways Gateway classes.
	 * @return string[]
	 */
	public static function gateway( $gateways ): array {
		$gateways   = (array) $gateways;
		$gateways[] = Gateway::class;
		return $gateways;
	}

	/**
	 * Register the block checkout integration.
	 *
	 * @param \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry Registry.
	 */
	public static function blocks( $registry ): void {
		if ( class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			$registry->register( new Blocks\Payment_Method() );
		}
	}

	/**
	 * Permanently deleted order → its receipt files go too.
	 *
	 * @param int            $order_id Order id.
	 * @param \WC_Order|null $order    Order.
	 */
	public static function delete_order( $order_id, $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( (int) $order_id );
		if ( Settings::get( 'delete_with_order' ) && $order instanceof \WC_Order && Orders::is_ours( $order ) ) {
			Receipts::delete_files( $order );
		}
	}

	/**
	 * The menu bubble count changes with statuses.
	 */
	public static function flush_count(): void {
		delete_transient( 'ctc_pending_count' );
	}

	/**
	 * WooCommerce missing.
	 */
	public static function needs_woocommerce(): void {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'افزونه «کارت به کارت حرفه‌ای» به ووکامرس نیاز دارد. ابتدا ووکامرس را نصب و فعال کنید.', 'card-to-card' ) . '</p></div>';
		}
	}

	/**
	 * Activation: rewrite rules flushed on the next request, receipts folder ready.
	 */
	public static function activate(): void {
		delete_option( 'ctc_rewrite' );
		if ( function_exists( 'wp_upload_dir' ) ) {
			Receipts::dir();
		}
	}

	/**
	 * Deactivation: drop our rewrite rule and the scheduled jobs.
	 */
	public static function deactivate(): void {
		delete_option( 'ctc_rewrite' );
		flush_rewrite_rules( false );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), Orders::GROUP );
		}
	}
}
