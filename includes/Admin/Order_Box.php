<?php
/**
 * «رسید کارت به کارت» box on the order screen (HPOS and legacy): receipt preview, reference, payer
 * card digits, history, approve / reject with a reason. Status colours in the orders list and
 * warnings (blocked status change, invalid card / IBAN in the panel).
 *
 * @package CardToCard
 */

namespace CardToCard\Admin;

use CardToCard\Orders;
use CardToCard\Receipts;
use CardToCard\Settings;
use CardToCard\Support\Persian;
use CardToCard\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Order screen box.
 */
final class Order_Box {

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add' ), 30, 2 );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save' ), 60 );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_head', array( __CLASS__, 'css' ) );
	}

	/**
	 * Register the box (our orders only).
	 *
	 * @param string             $screen_id   Screen.
	 * @param \WP_Post|\WC_Order $data_object Order or post.
	 */
	public static function add( $screen_id, $data_object = null ): void {
		if ( ! function_exists( 'wc_get_page_screen_id' ) || wc_get_page_screen_id( 'shop-order' ) !== $screen_id ) {
			return;
		}
		$order = $data_object instanceof \WC_Order ? $data_object : ( $data_object instanceof \WP_Post ? wc_get_order( $data_object->ID ) : null );
		if ( ! $order instanceof \WC_Order || ! Orders::is_ours( $order ) ) {
			return;
		}
		add_meta_box( 'ctc-receipt', __( 'رسید کارت به کارت', 'card-to-card' ), array( __CLASS__, 'render' ), $screen_id, 'side', 'high' );
	}

	/**
	 * Box content. Buttons submit the order form itself (no nested forms).
	 *
	 * @param \WP_Post|\WC_Order $data_object Order or post.
	 */
	public static function render( $data_object ): void {
		$order = $data_object instanceof \WC_Order ? $data_object : wc_get_order( $data_object->ID );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$receipts = Orders::receipts( $order );
		$deadline = Orders::deadline( $order );
		$labels   = array(
			'pending'  => __( 'در انتظار بررسی', 'card-to-card' ),
			'approved' => __( 'تأیید شد', 'card-to-card' ),
			'rejected' => __( 'رد شد', 'card-to-card' ),
		);
		wp_nonce_field( 'ctc_box', 'ctc_box_nonce' );
		echo '<div class="ctc-box-admin">';
		echo '<p><a href="' . esc_url( Orders::url( $order ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'صفحه پرداخت مشتری', 'card-to-card' ) . ' ↗</a></p>';
		foreach ( Orders::cards( $order ) as $card ) {
			echo '<p class="ctc-adm-card" style="--c1:' . esc_attr( (string) $card['palette']['colors'][0] ) . ';--c2:' . esc_attr( (string) $card['palette']['colors'][1] ) . ';--ink:' . esc_attr( (string) $card['palette']['ink'] ) . '"><span>' . esc_html( (string) $card['bank_name'] ) . '</span><b dir="ltr">' . esc_html( (string) $card['number_fmt'] ) . '</b></p>';
		}
		if ( $order->has_status( Orders::AWAITING ) && $deadline ) {
			/* translators: %s: Jalali date and time */
			echo '<p>' . esc_html( sprintf( __( 'مهلت ارسال رسید: %s', 'card-to-card' ), Util::jdate( 'j F، H:i', $deadline ) ) ) . '</p>';
		}
		if ( ! $receipts ) {
			echo '<p class="ctc-muted">' . esc_html__( 'هنوز رسیدی ارسال نشده است.', 'card-to-card' ) . '</p>';
		}
		foreach ( array_reverse( $receipts, true ) as $i => $r ) {
			$url   = Receipts::file_url( $order->get_id(), (int) $i );
			$state = (string) ( $r['state'] ?? 'pending' );
			echo '<div class="ctc-r is-' . esc_attr( $state ) . '">';
			if ( ! empty( $r['deleted'] ) ) {
				/* translators: %s: Jalali date */
				echo '<p class="ctc-muted">' . esc_html( sprintf( __( 'فایل رسید طبق تنظیمات نگهداری در %s حذف شد.', 'card-to-card' ), Util::jdate( 'j F Y', (int) $r['deleted'] ) ) ) . '</p>';
			} elseif ( in_array( (string) $r['type'], array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
				echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener"><img src="' . esc_url( $url ) . '" alt="' . esc_attr__( 'رسید', 'card-to-card' ) . '" loading="lazy"></a>';
			} else {
				echo '<a class="button" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'دانلود فایل رسید', 'card-to-card' ) . '</a>';
			}
			echo '<p><b>' . esc_html( $labels[ $state ] ?? $state ) . '</b> — ' . esc_html( Util::jdate( 'j F، H:i', (int) $r['time'] ) ) . '</p>';
			if ( '' !== (string) ( $r['ref'] ?? '' ) ) {
				/* translators: %s: bank reference */
				echo '<p>' . esc_html( sprintf( __( 'شماره پیگیری: %s', 'card-to-card' ), $r['ref'] ) ) . '</p>';
			}
			if ( '' !== (string) ( $r['last4'] ?? '' ) ) {
				/* translators: %s: last four digits */
				echo '<p>' . esc_html( sprintf( __( 'چهار رقم آخر کارت واریزکننده: %s', 'card-to-card' ), Persian::digits( (string) $r['last4'] ) ) ) . '</p>';
			}
			foreach ( (array) ( $r['fields'] ?? array() ) as $label => $value ) {
				echo '<p>' . esc_html( $label . ': ' . $value ) . '</p>';
			}
			if ( '' !== (string) ( $r['reason'] ?? '' ) ) {
				/* translators: %s: reason */
				echo '<p>' . esc_html( sprintf( __( 'دلیل رد: %s', 'card-to-card' ), $r['reason'] ) ) . '</p>';
			}
			echo '</div>';
		}
		if ( $order->has_status( array( Orders::AWAITING, Orders::REVIEW, 'on-hold' ) ) ) {
			echo '<p><button type="submit" class="button button-primary ctc-approve" name="ctc_do" value="approve">' . esc_html__( 'تأیید واریز و شروع سفارش', 'card-to-card' ) . '</button></p>';
		}
		if ( $order->has_status( Orders::REVIEW ) ) {
			echo '<p><label for="ctc-reason">' . esc_html__( 'دلیل رد (برای مشتری پیامک می‌شود)', 'card-to-card' ) . '</label>';
			echo '<textarea id="ctc-reason" name="ctc_reason" rows="2" style="width:100%"></textarea></p>';
			echo '<p><button type="submit" class="button" name="ctc_do" value="reject">' . esc_html__( 'رد رسید', 'card-to-card' ) . '</button></p>';
		}
		echo '</div>';
	}

	/**
	 * Approve / reject after WooCommerce saved the order form.
	 *
	 * @param int $order_id Order id.
	 */
	public static function save( $order_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified right below.
		if ( empty( $_POST['ctc_do'] ) || ! isset( $_POST['ctc_box_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['ctc_box_nonce'] ) ), 'ctc_box' ) || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order || ! Orders::is_ours( $order ) ) {
			return;
		}
		$do     = sanitize_key( wp_unslash( $_POST['ctc_do'] ) );
		$reason = sanitize_textarea_field( wp_unslash( $_POST['ctc_reason'] ?? '' ) );
		$result = 'approve' === $do ? Orders::approve( $order ) : ( 'reject' === $do ? Orders::reject( $order, $reason ) : true );
		if ( is_wp_error( $result ) && class_exists( 'WC_Admin_Meta_Boxes' ) ) {
			\WC_Admin_Meta_Boxes::add_error( $result->get_error_message() );
		}
	}

	/**
	 * Notices: a blocked status change; an invalid card / IBAN; gateway on without cards.
	 */
	public static function notices(): void {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$key = 'ctc_guard_' . get_current_user_id();
		if ( get_transient( $key ) ) {
			delete_transient( $key );
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'سفارش کارت به کارت تا تأیید رسید «در حال انجام» یا «تکمیل» نمی‌شود. از جعبه «رسید کارت به کارت» در صفحه سفارش تأیید کنید.', 'card-to-card' ) . '</p></div>';
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$bad = array();
		foreach ( (array) Settings::get( 'cards', array() ) as $card ) {
			$resolved = Settings::resolve_card( $card );
			if ( ! $resolved['card_ok'] || ! $resolved['sheba_ok'] ) {
				$bad[] = Persian::digits( (string) $resolved['number_fmt'] );
			}
		}
		if ( $bad ) {
			/* translators: %s: card numbers */
			echo '<div class="notice notice-error"><p>' . esc_html( sprintf( __( 'کارت به کارت: شماره کارت یا شبای «%s» معتبر نیست؛ در پنل کارت به کارت بررسی کنید.', 'card-to-card' ), implode( '، ', $bad ) ) ) . '</p></div>';
		}
	}

	/**
	 * Status colours in the orders list and the box layout.
	 */
	public static function css(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! function_exists( 'wc_get_page_screen_id' ) || ! in_array( $screen->id, array( wc_get_page_screen_id( 'shop-order' ), 'edit-shop_order', 'shop_order' ), true ) ) {
			return;
		}
		echo '<style>'
			. '.order-status.status-ctc-receipt{background:#fdecc8;color:#8a5a00}'
			. '.order-status.status-ctc-review{background:#dbe7ff;color:#2446a6}'
			. '.ctc-r{margin:0 0 12px;padding:8px;border:1px solid #dcdcde;border-radius:8px}'
			. '.ctc-r.is-pending{border-color:#dba617;background:#fffbea}.ctc-r.is-approved{border-color:#68de7c}.ctc-r.is-rejected{opacity:.7}'
			. '.ctc-r img{display:block;max-width:100%;height:auto;border-radius:6px}.ctc-r p{margin:6px 0 0}'
			. '.ctc-adm-card{display:flex;flex-direction:column;gap:4px;padding:10px 12px;border-radius:10px;background:linear-gradient(135deg,var(--c1),var(--c2));color:var(--ink)}'
			. '.ctc-adm-card b{font-size:14px;letter-spacing:1px;text-align:left}.ctc-muted{color:#646970}'
			. '</style>';
	}
}
