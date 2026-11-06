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
						'maximum' => 1000,
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
	 * Receipts list, 10 orders a page, newest first.
	 *
	 * Kept light for big shops on shared hosting: the review / awaiting tabs ask for our own statuses
	 * only (no join on the payment method), there is no total count query (one extra row tells
	 * whether there is a next page), and the pictures travel inline in this answer.
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
		$status   = isset( $map[ (string) $request['status'] ] ) ? (string) $request['status'] : 'review';
		$per_page = 10;
		$page     = max( 1, (int) $request['page'] );
		$query    = array(
			'status'  => $map[ $status ],
			'limit'   => $per_page + 1,
			'offset'  => ( $page - 1 ) * $per_page,
			'orderby' => 'date',
			'order'   => 'DESC',
			'type'    => 'shop_order',
		);
		if ( ! in_array( $status, array( 'review', 'awaiting' ), true ) ) {
			$query['payment_method'] = Gateway::ID; // Our statuses are ours alone; the others are shared.
		}
		$start   = microtime( true );
		$orders  = array_values( array_filter( (array) wc_get_orders( $query ), static fn( $o ): bool => $o instanceof \WC_Order && Orders::is_ours( $o ) ) );
		$queried = microtime( true );
		$more    = count( $orders ) > $per_page;
		$items   = array();
		$missing = array();
		foreach ( array_slice( $orders, 0, $per_page ) as $order ) {
			$row     = self::order_row( $order, true );
			$items[] = $row;
			if ( ! empty( $row['_missing'] ) ) {
				$missing[] = $order->get_id();
			}
		}
		Receipts::queue_previews( $missing );
		$done = microtime( true );
		// Where the time went, in seconds: WordPress + every plugin loading before us, the order
		// query, and building the rows. The panel shows it when the list is slow.
		$boot     = isset( $GLOBALS['timestart'] ) ? $start - (float) $GLOBALS['timestart'] : 0.0;
		$timing   = array(
			'boot'  => round( $boot, 3 ),
			'query' => round( $queried - $start, 3 ),
			'rows'  => round( $done - $queried, 3 ),
		);
		$response = rest_ensure_response(
			array(
				'items'  => array_map( static fn( array $r ): array => array_diff_key( $r, array( '_missing' => 1 ) ), $items ),
				'page'   => $page,
				'more'   => $more,
				'timing' => $timing,
			)
		);
		$response->header( 'Server-Timing', sprintf( 'boot;dur=%d, query;dur=%d, rows;dur=%d', $timing['boot'] * 1000, $timing['query'] * 1000, $timing['rows'] * 1000 ) );
		return $response;
	}

	/**
	 * One order for the panel.
	 *
	 * @param \WC_Order $order  Order.
	 * @param bool      $inline Put the latest receipt's pictures inline (receipts list).
	 * @return array<string, mixed>
	 */
	public static function order_row( \WC_Order $order, bool $inline = false ): array {
		$all      = Orders::receipts( $order );
		$latest   = count( $all ) - 1;
		$receipts = array();
		$missing  = false;
		foreach ( $all as $i => $r ) {
			$files = array();
			foreach ( Receipts::files( $r ) as $f => $file ) {
				$image   = Receipts::is_image( $file['type'] ) && empty( $r['deleted'] );
				$preview = '';
				if ( $inline && $image && $i === $latest ) {
					// Never resize here: a missing copy (older receipt) is made in the background
					// and the panel shows the thumbnail URL meanwhile.
					$preview = Receipts::preview( $file, false );
					$missing = $missing || '' === $preview;
				}
				$files[] = array(
					'url'     => Receipts::file_url( $order->get_id(), (int) $i, (int) $f ),
					'thumb'   => $image ? Receipts::file_url( $order->get_id(), (int) $i, (int) $f, true ) : '',
					'preview' => $preview,
					'image'   => $image,
					'type'    => $file['type'],
					'name'    => $file['name'],
				);
			}
			$receipts[] = array(
				'index'   => $i,
				'url'     => $files[0]['url'] ?? '',
				'thumb'   => $files[0]['thumb'] ?? '',
				'preview' => $files[0]['preview'] ?? '',
				'files'   => $files,
				'image'   => Receipts::is_image( (string) $r['type'] ),
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
		$row      = array(
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
		if ( $missing ) {
			$row['_missing'] = true;
		}
		return $row;
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
		delete_transient( 'ctc_stats' );
		$fresh = wc_get_order( $order->get_id() );
		$row   = $fresh instanceof \WC_Order ? self::order_row( $fresh, true ) : array();
		unset( $row['_missing'] );
		return rest_ensure_response( $row );
	}

	/**
	 * Dashboard numbers.
	 *
	 * @return \WP_REST_Response
	 */
	public static function stats() {
		$cached = get_transient( 'ctc_stats' );
		if ( is_array( $cached ) ) {
			$cached['enabled'] = App::gateway_enabled();
			$cached['cards']   = count( Settings::cards() );
			return rest_ensure_response( $cached );
		}
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
				'orderby'        => 'none',
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
		$stats = array(
			'review'   => $count( array( 'wc-' . Orders::REVIEW ) ),
			'awaiting' => $count( array( 'wc-' . Orders::AWAITING ) ),
			'paid30'   => count( $paid ),
			'sum30'    => $sum,
			'days'     => $days,
			'unit'     => Util::unit(),
			'enabled'  => App::gateway_enabled(),
			'cards'    => count( Settings::cards() ),
		);
		// Loading a month of orders is the slow part on big shops: keep the numbers for a few
		// minutes (any status change of our orders drops them, see Plugin::flush_count()).
		set_transient( 'ctc_stats', $stats, 5 * MINUTE_IN_SECONDS );
		return rest_ensure_response( $stats );
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
