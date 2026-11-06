<?php
/**
 * Customer-facing glue outside the payment page: WooCommerce e-mails (on-hold + new order for
 * «در انتظار رسید», card and «ارسال رسید» button), My Account («ارسال رسید» action, receipt box on the
 * order view) and cache-plugin exclusions.
 *
 * @package CardToCard
 */

namespace CardToCard;

use CardToCard\Support\Banks;

defined( 'ABSPATH' ) || exit;

/**
 * E-mails, My Account, caches.
 */
final class Frontend {

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_filter( 'woocommerce_email_actions', array( __CLASS__, 'email_actions' ) );
		add_action( 'woocommerce_email', array( __CLASS__, 'emails' ) );
		add_action( 'woocommerce_email_before_order_table', array( __CLASS__, 'email_box' ), 5, 3 );
		add_filter( 'woocommerce_my_account_my_orders_actions', array( __CLASS__, 'account_actions' ), 10, 2 );
		add_action( 'woocommerce_order_details_before_order_table', array( __CLASS__, 'view_order_box' ) );
		add_filter( 'rocket_cache_reject_uri', array( __CLASS__, 'rocket_reject' ) );
	}

	/**
	 * Let WooCommerce fire e-mail notifications for pending → ctc-receipt.
	 *
	 * @param string[] $actions Actions.
	 * @return string[]
	 */
	public static function email_actions( $actions ): array {
		$actions   = (array) $actions;
		$actions[] = 'woocommerce_order_status_pending_to_' . Orders::AWAITING;
		$actions[] = 'woocommerce_order_status_failed_to_' . Orders::AWAITING;
		return $actions;
	}

	/**
	 * Customer «در انتظار» and admin «سفارش جدید» e-mails on → ctc-receipt.
	 *
	 * @param \WC_Emails $mailer Mailer.
	 */
	public static function emails( $mailer ): void {
		$emails = $mailer instanceof \WC_Emails ? $mailer->get_emails() : array();
		foreach ( array( 'WC_Email_Customer_On_Hold_Order', 'WC_Email_New_Order' ) as $class ) {
			if ( isset( $emails[ $class ] ) ) {
				foreach ( array( 'pending', 'failed' ) as $from ) {
					add_action( 'woocommerce_order_status_' . $from . '_to_' . Orders::AWAITING . '_notification', array( $emails[ $class ], 'trigger' ), 10, 2 );
				}
			}
		}
	}

	/**
	 * Card and payment link in the customer's e-mails while the receipt is awaited.
	 *
	 * @param \WC_Order $order         Order.
	 * @param bool      $sent_to_admin Admin e-mail.
	 * @param bool      $plain_text    Plain text.
	 */
	public static function email_box( $order, $sent_to_admin, $plain_text = false ): void {
		if ( $sent_to_admin || ! $order instanceof \WC_Order || ! Orders::is_ours( $order ) ) {
			return;
		}
		$url   = Orders::receipt_url( $order );
		$cards = 'pay' === Orders::page_state( $order ) ? Orders::cards( $order ) : array();
		if ( ! $cards ) {
			return;
		}
		if ( $plain_text ) {
			foreach ( $cards as $card ) {
				echo esc_html( trim( $card['bank_name'] . ' ' . Banks::format_card( (string) $card['number'] ) . ' ' . $card['holder'] ) ) . "\n";
			}
			if ( '' !== $url ) {
				echo esc_html__( 'ارسال رسید:', 'card-to-card' ) . ' ' . esc_url_raw( $url ) . "\n\n";
			}
			return;
		}
		$accent = Settings::accent()['hex'];
		foreach ( $cards as $card ) {
			$p = (array) $card['palette'];
			if ( 'custom' === ( $card['skin'] ?? '' ) ) {
				$p['colors'] = array( $card['color1'], $card['color2'], $card['color1'] );
				$p['ink']    = \CardToCard\Support\Colors::ink( (string) $card['color1'], (string) $card['color2'] );
			}
			printf(
				'<div style="margin:0 0 14px;padding:18px 20px;border-radius:16px;background:%1$s;background-image:linear-gradient(135deg,%1$s,%2$s);color:%3$s;direction:rtl;text-align:right"><div style="font-size:13px;opacity:.9;margin-bottom:10px">%4$s</div><div dir="ltr" style="font-size:21px;font-weight:bold;letter-spacing:2px;text-align:left;font-family:monospace">%5$s</div><div style="font-size:13px;margin-top:10px;opacity:.9">%6$s</div></div>',
				esc_attr( (string) $p['colors'][0] ),
				esc_attr( (string) $p['colors'][1] ),
				esc_attr( (string) $p['ink'] ),
				esc_html( '' !== $card['bank_name'] ? $card['bank_name'] : __( 'کارت بانکی', 'card-to-card' ) ),
				esc_html( Banks::format_card( (string) $card['number'] ) ),
				esc_html( (string) $card['holder'] )
			);
		}
		if ( '' !== $url ) {
			printf(
				'<p style="margin:0 0 24px"><a href="%1$s" style="display:inline-block;padding:12px 22px;border-radius:12px;background:%2$s;color:%4$s;text-decoration:none;font-weight:bold">%3$s</a></p>',
				esc_url( $url ),
				esc_attr( $accent ),
				esc_html__( 'ارسال رسید واریز', 'card-to-card' ),
				esc_attr( \CardToCard\Support\Colors::ink( $accent ) )
			);
		}
	}

	/**
	 * «ارسال رسید» in My Account › Orders.
	 *
	 * @param array<string, array<string, string>> $actions Actions.
	 * @param \WC_Order                            $order   Order.
	 * @return array<string, array<string, string>>
	 */
	public static function account_actions( $actions, $order ): array {
		$actions = (array) $actions;
		if ( Settings::get( 'account_action' ) && $order instanceof \WC_Order && '' !== Orders::receipt_url( $order ) ) {
			$actions = array(
				'ctc-receipt' => array(
					'url'  => Orders::receipt_url( $order ),
					'name' => __( 'ارسال رسید', 'card-to-card' ),
				),
			) + $actions;
			unset( $actions['pay'] );
		}
		return $actions;
	}

	/**
	 * Card / receipt state above the order table on «view order».
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function view_order_box( $order ): void {
		if ( Settings::get( 'account_box' ) && $order instanceof \WC_Order && Orders::is_ours( $order ) && function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'view-order' ) ) {
			Payment_Page::render_box( $order );
		}
	}

	/**
	 * WP Rocket: never cache the payment pages.
	 *
	 * @param string[] $uris URIs.
	 * @return string[]
	 */
	public static function rocket_reject( $uris ): array {
		$uris   = (array) $uris;
		$uris[] = '/' . Settings::get( 'slug', 'card-pay' ) . '/(.*)';
		return $uris;
	}
}
