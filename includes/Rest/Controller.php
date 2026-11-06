<?php
/**
 * REST API of the panel (namespace ctc/v1). Every route needs a logged-in shop manager and the
 * wp_rest nonce (cookie auth):
 *  - GET/POST settings              manage_woocommerce
 *  - GET  receipts                   edit_shop_orders — orders awaiting / in review, with receipts
 *  - POST orders/<id>/approve|reject edit_shop_orders
 *  - GET  stats                      edit_shop_orders
 *  - POST sms/test, GET|DELETE sms/log  manage_woocommerce
 *
 * @package CardToCard
 */

namespace CardToCard\Rest;

use CardToCard\Admin\App;
use CardToCard\Gateway;
use CardToCard\Modules\Fraud;
use CardToCard\Orders;
use CardToCard\Receipts;
use CardToCard\Settings;
use CardToCard\Sms\Sender;
use CardToCard\Support\Persian;
use CardToCard\Util;

defined( 'ABSPATH' ) || exit;

/**
 * REST routes.
 */
final class Controller {

	public const NS = 'ctc/v1';

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Permission: settings.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Permission: orders.
	 *
	 * @return bool
	 */
	public static function can_orders(): bool {
		return current_user_can( 'edit_shop_orders' );
	}

	/**
	 * Routes.
	 */
	public static function routes(): void {
		register_rest_route(
			self::NS,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => static fn() => rest_ensure_response( App::settings_for_app() ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'save_settings' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/receipts',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'receipts' ),
				'permission_callback' => array( __CLASS__, 'can_orders' ),
				'args'                => array(
					'status' => array(
						'type'    => 'string',
						'enum'    => array( 'review', 'awaiting', 'done', 'all' ),
						'default' => 'review',
					),
					'page'   => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/orders/(?P<id>\d+)/(?P<action>approve|reject)',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'decide' ),
				'permission_callback' => array( __CLASS__, 'can_orders' ),
				'args'                => array(
					'reason' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/stats',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'stats' ),
				'permission_callback' => array( __CLASS__, 'can_orders' ),
			)
		);
		register_rest_route(
			self::NS,
			'/sms/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'sms_test' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'to'    => array(
						'type'    => 'string',
						'default' => '',
					),
					'event' => array(
						'type'    => 'string',
						'default' => 'placed',
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/sms/log',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => static fn() => rest_ensure_response( array_slice( Sender::get_log(), 0, 100 ) ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => static function () {
						delete_option( Sender::LOG );
						return rest_ensure_response( array( 'ok' => true ) );
					},
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
			)
		);
	}

	/**
	 * Save settings. The masked password keeps the stored one; «enabled» goes to WooCommerce.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function save_settings( \WP_REST_Request $request ) {
		$input = $request->get_json_params();
		if ( ! is_array( $input ) ) {
			return new \WP_Error( 'ctc_bad', __( 'داده نامعتبر است.', 'card-to-card' ), array( 'status' => 400 ) );
		}
		if ( array_key_exists( 'enabled', $input ) ) {
			App::set_gateway( filter_var( $input['enabled'], FILTER_VALIDATE_BOOLEAN ) );
			unset( $input['enabled'] );
		}
		if ( isset( $input['mp_pass'] ) && App::MASK === $input['mp_pass'] ) {
			unset( $input['mp_pass'] );
		}
		Settings::save( $input );
		return rest_ensure_response( App::settings_for_app() );
	}

	/**
	 * Receipts list.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function receipts( \WP_REST_Request $request ) {
		$map      = array(
			'review'   => array( 'wc-' . Orders::REVIEW ),
			'awaiting' => array( 'wc-' . Orders::AWAITING ),
			'done'     => array( 'wc-processing', 'wc-completed', 'wc-cancelled' ),
			'all'      => array_keys( wc_get_order_statuses() ),
		);
		$status   = (string) $request['status'];
		$per_page = 20;
		$result   = wc_get_orders(
			array(
				'payment_method' => Gateway::ID,
				'status'         => $map[ $status ] ?? $map['review'],
				'limit'          => $per_page,
				'page'           => (int) $request['page'],
				'paginate'       => true,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		$items    = array();
		foreach ( $result->orders as $order ) {
			if ( $order instanceof \WC_Order ) {
				$items[] = self::order_row( $order );
			}
		}
		return rest_ensure_response(
			array(
				'items' => $items,
				'total' => (int) $result->total,
				'pages' => (int) $result->max_num_pages,
			)
		);
	}

	/**
	 * One order for the panel.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string, mixed>
	 */
	public static function order_row( \WC_Order $order ): array {
		$receipts = array();
		foreach ( Orders::receipts( $order ) as $i => $r ) {
			$receipts[] = array(
				'index'   => $i,
				'url'     => Receipts::file_url( $order->get_id(), (int) $i ),
				'image'   => in_array( (string) $r['type'], array( 'image/jpeg', 'image/png', 'image/webp' ), true ),
				'type'    => (string) $r['type'],
				'name'    => (string) $r['name'],
				'time'    => Util::jdate( 'j F، H:i', (int) $r['time'] ),
				'ref'     => (string) ( $r['ref'] ?? '' ),
				'last4'   => (string) ( $r['last4'] ?? '' ),
				'state'   => (string) ( $r['state'] ?? 'pending' ),
				'reason'  => (string) ( $r['reason'] ?? '' ),
				'fields'  => (array) ( $r['fields'] ?? array() ),
				'deleted' => ! empty( $r['deleted'] ),
				'flags'   => Fraud::present( $r ),
			);
		}
		$created = $order->get_date_created();
		$cards   = array();
		foreach ( Orders::cards( $order ) as $card ) {
			$cards[] = array(
				'bank'   => (string) $card['bank_name'],
				'slug'   => (string) $card['bank_slug'],
				'number' => (string) $card['number_fmt'],
			);
		}
		$deadline = Orders::deadline( $order );
		return array(
			'id'       => $order->get_id(),
			'number'   => (string) $order->get_order_number(),
			'status'   => $order->get_status(),
			'label'    => wc_get_order_status_name( $order->get_status() ),
			'customer' => trim( $order->get_formatted_billing_full_name() ),
			'phone'    => Persian::mobile( $order->get_billing_phone() ),
			'total'    => Util::amount( $order->get_total() ),
			'unit'     => Util::unit(),
			'date'     => $created ? Util::jdate( 'j F، H:i', $created->getTimestamp() ) : '',
			'deadline' => $deadline && $order->has_status( Orders::AWAITING ) ? Util::jdate( 'j F، H:i', $deadline ) : '',
			'edit'     => $order->get_edit_order_url(),
			'pay'      => Orders::url( $order ),
			'receipts' => $receipts,
			'cards'    => $cards,
		);
	}

	/**
	 * Approve / reject.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function decide( \WP_REST_Request $request ) {
		$order = wc_get_order( (int) $request['id'] );
		if ( ! $order instanceof \WC_Order || ! Orders::is_ours( $order ) ) {
			return new \WP_Error( 'ctc_404', __( 'سفارش پیدا نشد.', 'card-to-card' ), array( 'status' => 404 ) );
		}
		$result = 'approve' === $request['action'] ? Orders::approve( $order ) : Orders::reject( $order, sanitize_textarea_field( (string) $request['reason'] ) );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 409 ) );
			return $result;
		}
		delete_transient( 'ctc_pending_count' );
		$fresh = wc_get_order( $order->get_id() );
		return rest_ensure_response( $fresh instanceof \WC_Order ? self::order_row( $fresh ) : array() );
	}

	/**
	 * Dashboard numbers.
	 *
	 * @return \WP_REST_Response
	 */
	public static function stats() {
		$count = static fn( array $status ): int => (int) wc_get_orders(
			array(
				'payment_method' => Gateway::ID,
				'status'         => $status,
				'limit'          => 1,
				'paginate'       => true,
				'return'         => 'ids',
			)
		)->total;
		$paid  = wc_get_orders(
			array(
				'payment_method' => Gateway::ID,
				'status'         => array( 'wc-processing', 'wc-completed' ),
				'date_paid'      => '>' . ( time() - 30 * DAY_IN_SECONDS ),
				'limit'          => 500,
			)
		);
		$sum   = 0.0;
		$days  = array_fill( 0, 14, 0 );
		foreach ( $paid as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			$sum += (float) $order->get_total();
			$at   = $order->get_date_paid();
			$ago  = $at ? (int) floor( ( time() - $at->getTimestamp() ) / DAY_IN_SECONDS ) : 99;
			if ( $ago < 14 ) {
				$days[ 13 - $ago ] += (float) $order->get_total();
			}
		}
		return rest_ensure_response(
			array(
				'review'   => $count( array( 'wc-' . Orders::REVIEW ) ),
				'awaiting' => $count( array( 'wc-' . Orders::AWAITING ) ),
				'paid30'   => count( $paid ),
				'sum30'    => $sum,
				'days'     => $days,
				'unit'     => Util::unit(),
				'enabled'  => App::gateway_enabled(),
				'cards'    => count( Settings::cards() ),
			)
		);
	}

	/**
	 * SMS connection test (and optional test message).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function sms_test( \WP_REST_Request $request ) {
		return rest_ensure_response( Sender::test( (string) $request['to'], sanitize_key( (string) $request['event'] ) ) );
	}
}
