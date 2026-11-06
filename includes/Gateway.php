<?php
/**
 * The «کارت به کارت» WooCommerce payment gateway.
 *
 * Title, description, button text and cards live in the plugin panel (Settings); WooCommerce's own
 * screen keeps only the on/off switch and links to the panel, so there is one place to edit.
 *
 * @package CardToCard
 */

namespace CardToCard;

defined( 'ABSPATH' ) || exit;

/**
 * Card-to-card gateway.
 */
class Gateway extends \WC_Payment_Gateway {

	public const ID = 'card_to_card';

	/**
	 * Set up the gateway.
	 */
	public function __construct() {
		$this->id                 = self::ID;
		$this->has_fields         = false;
		$this->method_title       = __( 'کارت به کارت حرفه‌ای', 'card-to-card' );
		$this->method_description = __( 'واریز کارت به کارت با صفحه پرداخت اختصاصی، کارت هم‌رنگ بانک، آپلود رسید، تأیید/رد رسید، مهلت و لغو خودکار و پیامک.', 'card-to-card' );
		$this->supports           = array( 'products' );
		$this->icon               = (string) apply_filters( 'ctc_gateway_icon', '' );

		$this->init_form_fields();
		$this->init_settings();

		/**
		 * Gateway title at checkout (the fee module adds «۲٪ تخفیف»).
		 *
		 * @param string $title Title from the panel.
		 */
		$this->title             = (string) apply_filters( 'ctc_gateway_title', (string) Settings::get( 'title' ) );
		$this->description       = (string) Settings::get( 'description' );
		$this->order_button_text = (string) Settings::get( 'button' );
		$this->enabled           = (string) $this->get_option( 'enabled', 'no' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
	}

	/**
	 * Only the switch here; everything else is in the plugin panel.
	 */
	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled' => array(
				'title'   => __( 'فعال‌سازی', 'card-to-card' ),
				'type'    => 'checkbox',
				'label'   => __( 'پرداخت کارت به کارت فعال باشد', 'card-to-card' ),
				'default' => 'no',
			),
		);
	}

	/**
	 * WooCommerce settings screen: the switch plus a link to the plugin panel.
	 */
	public function admin_options(): void {
		parent::admin_options();
		printf(
			'<p><a class="button button-primary" href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=card-to-card' ) ),
			esc_html__( 'باز کردن پنل تنظیمات کارت به کارت', 'card-to-card' )
		);
	}

	/**
	 * Available when switched on, at least one card is set and the total is in range.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		if ( ! parent::is_available() || ! Settings::cards() ) {
			return false;
		}
		$total = (float) $this->get_order_total();
		$min   = (int) Settings::get( 'min_total', 0 );
		$max   = (int) Settings::get( 'max_total', 0 );
		if ( $total > 0 && ( ( $min > 0 && $total < $min ) || ( $max > 0 && $total > $max ) ) ) {
			return false;
		}
		// Front end only: the cart / pay-for-order page decide; admin screens and REST lists always see it.
		if ( ! is_admin() || wp_doing_ajax() ) {
			if ( ! Orders::eligible_cards( null, $total ) ) {
				return false;
			}
			/**
			 * Whether card to card is offered for the current checkout (rules module).
			 *
			 * @param bool    $available Available.
			 * @param Gateway $gateway   Gateway.
			 */
			return (bool) apply_filters( 'ctc_is_available', true, $this );
		}
		return true;
	}

	/**
	 * Place the order: pick the card(s), wait for the receipt, go to the payment page.
	 *
	 * @param int $order_id Order id.
	 * @return array<string, string>
	 */
	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return array( 'result' => 'failure' );
		}
		/**
		 * Before the cards are given (the fee module makes sure its line is on the order).
		 *
		 * @param \WC_Order $order Order.
		 */
		do_action( 'ctc_process_payment', $order );
		Orders::assign_cards( $order );
		$status = Settings::get( 'pro' ) ? Orders::AWAITING : 'on-hold';
		$order->update_status( $status, __( 'در انتظار واریز کارت به کارت.', 'card-to-card' ) );
		if ( 'on-hold' === $status && Settings::get( 'hold_stock' ) ) {
			wc_maybe_reduce_stock_levels( $order->get_id() );
		}
		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}
		$redirect = Settings::get( 'pro' ) && Settings::get( 'redirect' ) ? Orders::url( $order ) : $this->get_return_url( $order );
		return array(
			'result'   => 'success',
			'redirect' => $redirect,
		);
	}

	/**
	 * Thank-you page: the card and the «ارسال رسید» button (when not redirected to the payment page).
	 *
	 * @param int $order_id Order id.
	 */
	public function thankyou_page( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order instanceof \WC_Order && Settings::get( 'thankyou_box' ) ) {
			Payment_Page::render_box( $order );
		}
	}
}
