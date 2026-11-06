<?php
/**
 * The settings panel: a React app (admin-src/, built into assets/admin/) under
 * WooCommerce › کارت به کارت, with a bubble for receipts waiting for review.
 *
 * @package CardToCard
 */

namespace CardToCard\Admin;

use CardToCard\Gateway;
use CardToCard\Orders;
use CardToCard\Payment_Page;
use CardToCard\Settings;
use CardToCard\Sms\Events;
use CardToCard\Support\Banks;
use CardToCard\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Admin page.
 */
final class App {

	public const SLUG = 'card-to-card';

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( CTC_FILE ), array( __CLASS__, 'links' ) );
	}

	/**
	 * Receipts waiting for review.
	 *
	 * @return int
	 */
	public static function pending_count(): int {
		$count = get_transient( 'ctc_pending_count' );
		if ( false === $count ) {
			$count = count(
				wc_get_orders(
					array(
						'status' => array( 'wc-' . Orders::REVIEW ),
						'limit'  => 100,
						'return' => 'ids',
					)
				)
			);
			set_transient( 'ctc_pending_count', $count, 5 * MINUTE_IN_SECONDS );
		}
		return (int) $count;
	}

	/**
	 * Menu item under WooCommerce.
	 */
	public static function menu(): void {
		$count = self::pending_count();
		$label = __( 'کارت به کارت', 'card-to-card' ) . ( $count ? ' <span class="awaiting-mod">' . esc_html( (string) $count ) . '</span>' : '' );
		add_submenu_page( 'woocommerce', __( 'کارت به کارت', 'card-to-card' ), $label, 'manage_woocommerce', self::SLUG, array( __CLASS__, 'render' ) );
	}

	/**
	 * «تنظیمات» link on the plugins screen.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public static function links( $links ): array {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'تنظیمات', 'card-to-card' ) . '</a>' );
		return (array) $links;
	}

	/**
	 * Mount point (the app paints over a skeleton).
	 */
	public static function render(): void {
		echo '<div class="wrap ctc-wrap"><h1 class="screen-reader-text">' . esc_html__( 'کارت به کارت', 'card-to-card' ) . '</h1><div id="ctc-app" dir="rtl"><div class="ctc-boot">' . esc_html__( 'در حال بارگذاری…', 'card-to-card' ) . '</div></div></div>';
	}

	/**
	 * Built app + boot data.
	 *
	 * @param string $hook Screen hook.
	 */
	public static function assets( $hook ): void {
		if ( 'woocommerce_page_' . self::SLUG !== $hook ) {
			return;
		}
		$ver = CTC_VERSION . '-' . (string) @filemtime( CTC_DIR . 'assets/admin/app.js' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- missing build is handled below.
		wp_enqueue_style( 'ctc-admin', CTC_URL . 'assets/admin/app.css', array(), $ver );
		wp_add_inline_style( 'ctc-admin', '@font-face{font-family:"CTC Vazirmatn";src:url("' . esc_url_raw( CTC_URL . 'assets/fonts/Vazirmatn-Variable.woff2' ) . '") format("woff2");font-weight:100 900;font-display:swap;}' );
		wp_enqueue_style( 'ctc-card', CTC_URL . 'assets/css/payment.css', array(), CTC_VERSION . '-' . (string) @filemtime( CTC_DIR . 'assets/css/payment.css' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		wp_enqueue_script( 'ctc-admin', CTC_URL . 'assets/admin/app.js', array(), $ver, true );
		wp_localize_script( 'ctc-admin', 'ctcBoot', self::boot() );
	}

	/**
	 * Everything the app needs on load.
	 *
	 * @return array<string, mixed>
	 */
	public static function boot(): array {
		$banks = array();
		foreach ( Banks::all() as $slug => $bank ) {
			$banks[ $slug ] = array(
				'name'   => $bank['name'],
				'bins'   => $bank['bins'],
				'codes'  => $bank['codes'],
				'colors' => $bank['colors'],
				'ink'    => $bank['ink'],
				'accent' => $bank['accent'],
				'logo'   => Payment_Page::logo( $slug ),
			);
		}
		$events = array();
		foreach ( Events::all() as $key => $event ) {
			$events[ $key ] = array(
				'label' => $event['label'],
				'to'    => $event['to'],
				'desc'  => $event['desc'],
				'vars'  => $event['vars'],
				'text'  => $event['text'],
				'on'    => $event['on'],
			);
		}
		return array(
			'rest'        => esc_url_raw( rest_url( 'ctc/v1/' ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'version'     => CTC_VERSION,
			'settings'    => self::settings_for_app(),
			'defaults'    => Settings::defaults(),
			'banks'       => $banks,
			'events'      => $events,
			'modules'     => \CardToCard\Modules\Registry::meta(),
			'themeColors' => \CardToCard\Support\Colors::theme(),
			'themeName'   => wp_get_theme()->get( 'Name' ),
			'tokens'      => Events::token_labels(),
			'unit'        => Util::unit(),
			'home'        => home_url( '/' ),
			'site'        => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'wcUrl'       => admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . Gateway::ID ),
			'ordersUrl'   => admin_url( 'admin.php?page=wc-orders' ),
			'pretty'      => (bool) get_option( 'permalink_structure' ),
			'blocks'      => class_exists( '\Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils' ) && \Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::is_checkout_block_default(),
		);
	}

	/**
	 * Settings with the gateway switch and the password masked.
	 *
	 * @return array<string, mixed>
	 */
	public static function settings_for_app(): array {
		$all            = Settings::all();
		$all['enabled'] = self::gateway_enabled();
		$all['mp_pass'] = '' !== (string) $all['mp_pass'] ? self::MASK : '';
		return $all;
	}

	public const MASK = '••••••••';

	/**
	 * Whether the gateway is on in WooCommerce.
	 *
	 * @return bool
	 */
	public static function gateway_enabled(): bool {
		$opt = get_option( 'woocommerce_' . Gateway::ID . '_settings', array() );
		return is_array( $opt ) && 'yes' === ( $opt['enabled'] ?? 'no' );
	}

	/**
	 * Switch the gateway in WooCommerce.
	 *
	 * @param bool $on On.
	 */
	public static function set_gateway( bool $on ): void {
		$opt            = get_option( 'woocommerce_' . Gateway::ID . '_settings', array() );
		$opt            = is_array( $opt ) ? $opt : array();
		$opt['enabled'] = $on ? 'yes' : 'no';
		update_option( 'woocommerce_' . Gateway::ID . '_settings', $opt );
	}
}
