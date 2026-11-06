<?php
/**
 * Plugin Name:       کارت به کارت حرفه‌ای ووکامرس
 * Plugin URI:        https://github.com/hoomanjamshidi/card-to-card-plugin
 * Description:       درگاه کارت به کارت برای ووکامرس با کارت هم‌رنگ بانک، صفحه پرداخت اختصاصی، آپلود و بررسی رسید، مهلت و لغو خودکار، پیامک ملی‌پیامک (پترن و خط) و پنل تنظیمات مدرن. روی هر قالبی کار می‌کند.
 * Version:           1.2.1
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * WC tested up to:   11.2
 * Author:            Hooman Jamshidi
 * Author URI:        https://github.com/hoomanjamshidi
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       card-to-card
 * Domain Path:       /languages
 *
 * @package CardToCard
 */

defined( 'ABSPATH' ) || exit;

define( 'CTC_VERSION', '1.2.1' );
define( 'CTC_FILE', __FILE__ );
define( 'CTC_DIR', plugin_dir_path( __FILE__ ) );
define( 'CTC_URL', plugin_dir_url( __FILE__ ) );

/**
 * PSR-4 style autoloader: CardToCard\Sms\Sender → includes/Sms/Sender.php.
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		if ( 0 !== strpos( $class_name, 'CardToCard\\' ) ) {
			return;
		}
		$file = CTC_DIR . 'includes/' . str_replace( '\\', '/', substr( $class_name, 11 ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

add_action( 'plugins_loaded', array( \CardToCard\Plugin::class, 'boot' ), 20 );
register_activation_hook( __FILE__, array( \CardToCard\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \CardToCard\Plugin::class, 'deactivate' ) );

// HPOS (custom order tables) and block checkout compatibility.
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);
