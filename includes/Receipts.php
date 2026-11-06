<?php
/**
 * Receipt files. They are private financial documents: stored outside the media library in
 * uploads/ctc-receipts (random names, web access denied) and streamed only to shop managers.
 *
 * @package CardToCard
 */

namespace CardToCard;

defined( 'ABSPATH' ) || exit;

/**
 * Store, validate and stream receipt files.
 */
final class Receipts {

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'admin_post_ctc_file', array( __CLASS__, 'stream' ) );
		add_action( 'ctc_cleanup', array( __CLASS__, 'run_cleanup' ) );
		add_action( 'action_scheduler_init', array( __CLASS__, 'schedule_cleanup' ) );
	}

	/**
	 * Daily retention job (Action Scheduler).
	 */
	public static function schedule_cleanup(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) && ! as_has_scheduled_action( 'ctc_cleanup', array(), Orders::GROUP ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, 'ctc_cleanup', array(), Orders::GROUP );
		}
	}

	/**
	 * Retention rule for one receipt: should its file go now?
	 *
	 * Days come from the panel (0 = keep forever):
	 *  - approved receipts: keep_approved days after approval;
	 *  - rejected receipts: keep_rejected days after rejection;
	 *  - every receipt of a cancelled / failed order: keep_cancelled days after the order changed.
	 *
	 * @param array<string, mixed> $receipt Receipt.
	 * @param \WC_Order            $order   Order.
	 * @param int                  $now     Current time.
	 * @return bool
	 */
	public static function expired( array $receipt, \WC_Order $order, int $now ): bool {
		if ( ! empty( $receipt['deleted'] ) ) {
			return false;
		}
		$days  = static fn( string $key ): int => (int) Settings::get( $key, 0 );
		$state = (string) ( $receipt['state'] ?? 'pending' );
		$at    = (int) ( $receipt['at'] ?? $receipt['time'] ?? 0 );
		if ( 'approved' === $state && $days( 'keep_approved' ) > 0 && $at && $now - $at >= $days( 'keep_approved' ) * DAY_IN_SECONDS ) {
			return true;
		}
		if ( 'rejected' === $state && $days( 'keep_rejected' ) > 0 && $at && $now - $at >= $days( 'keep_rejected' ) * DAY_IN_SECONDS ) {
			return true;
		}
		if ( $order->has_status( array( 'cancelled', 'failed', 'refunded' ) ) && $days( 'keep_cancelled' ) > 0 ) {
			$changed = $order->get_date_modified();
			return $changed && $now - $changed->getTimestamp() >= $days( 'keep_cancelled' ) * DAY_IN_SECONDS;
		}
		return false;
	}

	/**
	 * Daily job callback.
	 */
	public static function run_cleanup(): void {
		self::cleanup();
	}

	/**
	 * Delete receipt files whose retention ended. The receipt entry (date, reference, state) stays
	 * on the order for the record; only the file goes, flagged with «deleted».
	 *
	 * @param int|null $now Current time (tests).
	 * @return int Files deleted.
	 */
	public static function cleanup( $now = null ): int {
		$now = is_numeric( $now ) ? (int) $now : time();
		if ( ! Settings::get( 'keep_approved' ) && ! Settings::get( 'keep_rejected' ) && ! Settings::get( 'keep_cancelled' ) ) {
			return 0;
		}
		$count = 0;
		$page  = 1;
		do {
			$orders = (array) wc_get_orders(
				array(
					'payment_method' => Gateway::ID,
					'status'         => array_keys( wc_get_order_statuses() ),
					'limit'          => 100,
					'page'           => $page,
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);
			foreach ( $orders as $order ) {
				if ( ! $order instanceof \WC_Order ) {
					continue;
				}
				$receipts = Orders::receipts( $order );
				$changed  = false;
				foreach ( $receipts as $i => $receipt ) {
					if ( self::expired( $receipt, $order, $now ) ) {
						$path = self::path( $receipt );
						if ( '' !== $path ) {
							wp_delete_file( $path );
						}
						$receipts[ $i ]['deleted'] = $now;
						$changed                   = true;
						++$count;
					}
				}
				if ( $changed ) {
					$order->update_meta_data( '_ctc_receipts', $receipts );
					$order->save_meta_data();
				}
			}
			++$page;
			$more = 100 === count( $orders );
		} while ( $more );
		return $count;
	}

	/**
	 * Accepted types: images always, PDF when allowed.
	 *
	 * @return array<string, string>
	 */
	public static function mimes(): array {
		$mimes = array(
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'webp'         => 'image/webp',
			'heic'         => 'image/heic',
		);
		if ( Settings::get( 'pdf' ) ) {
			$mimes['pdf'] = 'application/pdf';
		}
		return $mimes;
	}

	/**
	 * «accept» attribute of the file input.
	 *
	 * @return string
	 */
	public static function accept(): string {
		$out = array();
		foreach ( array_keys( self::mimes() ) as $exts ) {
			foreach ( explode( '|', $exts ) as $ext ) {
				$out[] = '.' . $ext;
			}
		}
		return implode( ',', $out );
	}

	/**
	 * Folder of the receipts with web access denied (Apache 2.2/2.4, LiteSpeed). Nginx does not read
	 * .htaccess, but never lists the folder and the names are random 24-char strings.
	 *
	 * @return string Absolute path, no trailing slash.
	 */
	public static function dir(): string {
		$dir = wp_upload_dir( null, false )['basedir'] . '/ctc-receipts';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$rules = "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n";
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- tiny local file.
		if ( ! file_exists( $dir . '/.htaccess' ) || file_get_contents( $dir . '/.htaccess' ) !== $rules ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- protection files.
			file_put_contents( $dir . '/.htaccess', $rules );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		return $dir;
	}

	/**
	 * Check and store one uploaded file.
	 *
	 * @param array<string, mixed> $file One $_FILES entry.
	 * @return array{path:string, name:string, type:string, size:int}|\WP_Error
	 */
	public static function store( array $file ) {
		$error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
		if ( UPLOAD_ERR_NO_FILE === $error ) {
			return new \WP_Error( 'file', __( 'تصویر رسید را انتخاب کنید.', 'card-to-card' ) );
		}
		// Over the server's upload_max_filesize / the form's MAX_FILE_SIZE: a size problem, not a broken upload.
		if ( in_array( $error, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) || ( UPLOAD_ERR_OK === $error && (int) $file['size'] > Payment_Page::max_bytes() ) ) {
			return new \WP_Error( 'file', Payment_Page::too_big_message() );
		}
		if ( UPLOAD_ERR_OK !== $error || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			return new \WP_Error( 'file', __( 'فایل کامل بارگذاری نشد؛ دوباره امتحان کنید.', 'card-to-card' ) );
		}
		$check = wp_check_filetype_and_ext( (string) $file['tmp_name'], (string) $file['name'], self::mimes() );
		if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
			return new \WP_Error( 'file', Settings::get( 'pdf' ) ? __( 'فقط عکس (JPG، PNG، WEBP، HEIC) یا PDF.', 'card-to-card' ) : __( 'فقط عکس (JPG، PNG، WEBP یا HEIC).', 'card-to-card' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$base   = self::dir();
		$to_dir = static function ( array $dirs ) use ( $base ): array {
			$dirs['path'] = $base . $dirs['subdir'];
			$dirs['url']  = ''; // Never linked publicly.
			return $dirs;
		};
		add_filter( 'upload_dir', $to_dir );
		$saved = wp_handle_upload(
			$file,
			array(
				'test_form'                => false,
				'mimes'                    => self::mimes(),
				'unique_filename_callback' => static fn( $dir, $name, $ext ): string => strtolower( wp_generate_password( 24, false ) ) . strtolower( (string) $ext ),
			)
		);
		remove_filter( 'upload_dir', $to_dir );
		if ( empty( $saved['file'] ) || ! empty( $saved['error'] ) ) {
			return new \WP_Error( 'file', __( 'ذخیره فایل انجام نشد؛ دوباره امتحان کنید.', 'card-to-card' ) );
		}
		return array(
			'path' => ltrim( str_replace( wp_normalize_path( $base ), '', wp_normalize_path( (string) $saved['file'] ) ), '/' ),
			'name' => sanitize_file_name( (string) $file['name'] ),
			'type' => (string) $check['type'],
			'size' => (int) $file['size'],
		);
	}

	/**
	 * Delete a stored file that will not be attached (refused by a check).
	 *
	 * @param array<string, mixed> $stored Result of store().
	 */
	public static function discard( array $stored ): void {
		$path = self::path( $stored );
		if ( '' !== $path && is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Add a stored receipt to the order and move it to review.
	 *
	 * @param \WC_Order             $order  Order.
	 * @param array<string, mixed>  $stored Result of store().
	 * @param string                $ref    Bank reference.
	 * @param string                $last4  Last four digits of the payer's card.
	 * @param array<string, string> $extra  Extra form fields, label => value.
	 */
	public static function attach( \WC_Order $order, array $stored, string $ref, string $last4, array $extra = array() ): void {
		$receipts   = Orders::receipts( $order );
		$receipts[] = $stored + array(
			'time'   => time(),
			'ref'    => $ref,
			'last4'  => $last4,
			'fields' => $extra,
			'state'  => 'pending',
			'reason' => '',
		);
		$order->update_meta_data( '_ctc_receipts', $receipts );
		$order->update_status( Orders::REVIEW, __( 'رسید کارت به کارت از طرف مشتری ارسال شد.', 'card-to-card' ) );
		Sms\Events::queue( $order->get_id(), 'received', false );
		Sms\Events::queue( $order->get_id(), 'admin_receipt', false );
		/**
		 * A receipt was uploaded.
		 *
		 * @param \WC_Order $order Order.
		 */
		do_action( 'ctc_receipt_uploaded', $order );
	}

	/**
	 * Private URL of a receipt file (shop managers only).
	 *
	 * @param int $order_id Order id.
	 * @param int $index    Receipt index.
	 * @return string
	 */
	public static function file_url( int $order_id, int $index ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'ctc_file',
					'order'  => $order_id,
					'i'      => $index,
				),
				admin_url( 'admin-post.php' )
			),
			'ctc_file_' . $order_id
		);
	}

	/**
	 * Absolute path of a receipt, only when it is really inside the receipts folder.
	 *
	 * @param array<string, mixed> $receipt Receipt.
	 * @return string '' when missing or outside.
	 */
	public static function path( array $receipt ): string {
		$base = realpath( self::dir() );
		$path = realpath( self::dir() . '/' . (string) ( $receipt['path'] ?? '' ) );
		// realpath + prefix check: a stored path can never point outside the receipts folder.
		if ( ! $path || ! $base || ! str_starts_with( $path, $base . DIRECTORY_SEPARATOR ) || ! is_readable( $path ) ) {
			return '';
		}
		return $path;
	}

	/**
	 * Stream a receipt to a shop manager.
	 */
	public static function stream(): void {
		$order_id = absint( $_GET['order'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below.
		$index    = absint( $_GET['i'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( 'ctc_file_' . $order_id );
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'card-to-card' ), '', array( 'response' => 403 ) );
		}
		$order    = wc_get_order( $order_id );
		$receipts = $order instanceof \WC_Order ? Orders::receipts( $order ) : array();
		$receipt  = $receipts[ $index ] ?? null;
		$path     = is_array( $receipt ) && empty( $receipt['deleted'] ) ? self::path( $receipt ) : '';
		if ( '' === $path ) {
			wp_die( esc_html__( 'فایل رسید پیدا نشد.', 'card-to-card' ), '', array( 'response' => 404 ) );
		}
		// Images open in the tab; anything else (PDF, HEIC) downloads, so a customer's file never runs
		// as a document of the admin's origin. The sandbox blocks scripts either way.
		$type  = (string) $receipt['type'];
		$image = in_array( $type, array( 'image/jpeg', 'image/png', 'image/webp' ), true );
		nocache_headers();
		header( 'Content-Type: ' . ( $image || 'application/pdf' === $type || 'image/heic' === $type ? $type : 'application/octet-stream' ) );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'Content-Disposition: ' . ( $image ? 'inline' : 'attachment' ) . '; filename="' . rawurlencode( (string) $receipt['name'] ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox" );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a private file.
		exit;
	}

	/**
	 * Delete the files of an order (order deleted permanently).
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function delete_files( \WC_Order $order ): void {
		foreach ( Orders::receipts( $order ) as $receipt ) {
			if ( ! empty( $receipt['deleted'] ) ) {
				continue;
			}
			$path = self::path( $receipt );
			if ( '' !== $path ) {
				wp_delete_file( $path );
			}
		}
	}
}
