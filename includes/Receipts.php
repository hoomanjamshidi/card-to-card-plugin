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
						self::discard( $receipt );
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
		$stored = array(
			'path' => ltrim( str_replace( wp_normalize_path( $base ), '', wp_normalize_path( (string) $saved['file'] ) ), '/' ),
			'name' => sanitize_file_name( (string) $file['name'] ),
			'type' => (string) $check['type'],
			'size' => (int) $file['size'],
		);
		// The panel and the order screen show the small copies, so a list of receipts opens fast.
		self::thumb( $stored );
		self::thumb( $stored, 'mini' );
		return $stored;
	}

	/**
	 * The uploaded files of the receipt form as a list of $_FILES-like entries: one input
	 * («ctc_file») or several («ctc_file[]»).
	 *
	 * @param mixed $raw $_FILES['ctc_file'].
	 * @return array<int, array<string, mixed>>
	 */
	public static function uploads( $raw ): array {
		if ( ! is_array( $raw ) || ! isset( $raw['error'] ) ) {
			return array();
		}
		if ( ! is_array( $raw['error'] ) ) {
			return UPLOAD_ERR_NO_FILE === (int) $raw['error'] ? array() : array( $raw );
		}
		$out = array();
		foreach ( array_keys( $raw['error'] ) as $i ) {
			if ( UPLOAD_ERR_NO_FILE === (int) $raw['error'][ $i ] ) {
				continue;
			}
			$one = array();
			foreach ( array( 'name', 'type', 'tmp_name', 'error', 'size' ) as $key ) {
				$one[ $key ] = is_array( $raw[ $key ] ?? null ) ? ( $raw[ $key ][ $i ] ?? '' ) : '';
			}
			$out[] = $one;
		}
		return $out;
	}

	/**
	 * Every file of a receipt: the main one (path, name, type, size on the receipt itself, as in
	 * 1.0) and the extra ones of a multi-file receipt («more»).
	 *
	 * @param array<string, mixed> $receipt Receipt.
	 * @return array<int, array{path:string, name:string, type:string, size:int}>
	 */
	public static function files( array $receipt ): array {
		$out = array();
		foreach ( array_merge( array( $receipt ), array_values( (array) ( $receipt['more'] ?? array() ) ) ) as $file ) {
			if ( ! is_array( $file ) || '' === (string) ( $file['path'] ?? '' ) ) {
				continue;
			}
			$out[] = array(
				'path' => (string) $file['path'],
				'name' => (string) ( $file['name'] ?? '' ),
				'type' => (string) ( $file['type'] ?? '' ),
				'size' => (int) ( $file['size'] ?? 0 ),
			);
		}
		return $out;
	}

	/**
	 * Whether a stored type shows as a picture in the browser.
	 *
	 * @param string $type MIME type.
	 * @return bool
	 */
	public static function is_image( string $type ): bool {
		return in_array( $type, array( 'image/jpeg', 'image/png', 'image/webp' ), true );
	}

	/**
	 * Sizes of the small copies: «thumb» (order screen, long side 640 px) and «mini» (inlined in the
	 * panel's receipts list, 360 px).
	 */
	private const SIZES = array(
		'thumb' => array( 640, 72 ),
		'mini'  => array( 360, 64 ),
	);

	/**
	 * Path of a small copy next to the file: «<name>-thumb.jpg» / «<name>-mini.jpg».
	 *
	 * @param string $path Absolute path of the receipt.
	 * @param string $size thumb | mini.
	 * @return string
	 */
	private static function copy_path( string $path, string $size ): string {
		return (string) preg_replace( '/\.[a-z0-9]+$/i', '', $path ) . '-' . $size . '.jpg';
	}

	/**
	 * Small JPEG copy of an image receipt. Made at upload and, for older receipts, on the first view
	 * (unless $make is false). Returns '' when it cannot be made (not an image, no image editor), so
	 * the caller falls back to the original.
	 *
	 * @param array<string, mixed> $file One stored file (path, type).
	 * @param string               $size thumb | mini.
	 * @param bool                 $make Create it when missing.
	 * @return string Absolute path or ''.
	 */
	public static function thumb( array $file, string $size = 'thumb', bool $make = true ): string {
		$path = self::path( $file );
		if ( '' === $path || ! self::is_image( (string) ( $file['type'] ?? '' ) ) || ! isset( self::SIZES[ $size ] ) ) {
			return '';
		}
		$copy = self::copy_path( $path, $size );
		if ( is_file( $copy ) ) {
			return $copy;
		}
		if ( ! $make ) {
			return '';
		}
		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			return '';
		}
		[ $side, $quality ] = self::SIZES[ $size ];
		$dims               = $editor->get_size();
		if ( ! empty( $dims['width'] ) && ! empty( $dims['height'] ) && max( $dims['width'], $dims['height'] ) > $side ) {
			$editor->resize( $side, $side, false );
		}
		$editor->set_quality( $quality );
		$saved = $editor->save( $copy, 'image/jpeg' );
		return ! is_wp_error( $saved ) && is_file( $copy ) ? $copy : '';
	}

	/**
	 * The «mini» copy as a data: URI, so the panel's receipts list shows every picture from its one
	 * REST answer instead of one admin request (a full WordPress load) per picture, which queued
	 * behind each other on shared hosting. '' when there is none yet and $make is false.
	 *
	 * @param array<string, mixed> $file One stored file.
	 * @param bool                 $make Create the copy when missing.
	 * @return string
	 */
	public static function preview( array $file, bool $make = true ): string {
		$mini = self::thumb( $file, 'mini', $make );
		if ( '' === $mini || filesize( $mini ) > 120 * KB_IN_BYTES ) {
			return '';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- small local file.
		$data = file_get_contents( $mini );
		return false === $data ? '' : 'data:image/jpeg;base64,' . base64_encode( $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- inline image.
	}

	/**
	 * Delete a stored file that will not be attached (refused by a check).
	 *
	 * @param array<string, mixed> $stored Result of store().
	 */
	public static function discard( array $stored ): void {
		foreach ( self::files( $stored ) as $file ) {
			$path = self::path( $file );
			if ( '' === $path ) {
				continue;
			}
			foreach ( array_keys( self::SIZES ) as $size ) {
				$copy = self::copy_path( $path, $size );
				if ( is_file( $copy ) ) {
					wp_delete_file( $copy );
				}
			}
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
	 * Private URL of a receipt file (shop managers only). A raw URL: escape it at output
	 * (wp_nonce_url() would return «&amp;», which breaks the link in JSON for the panel).
	 *
	 * @param int  $order_id Order id.
	 * @param int  $index    Receipt index.
	 * @param int  $file     File of the receipt (0 = main file).
	 * @param bool $thumb    The small preview instead of the original.
	 * @return string
	 */
	public static function file_url( int $order_id, int $index, int $file = 0, bool $thumb = false ): string {
		$args = array(
			'action' => 'ctc_file',
			'order'  => $order_id,
			'i'      => $index,
		);
		if ( $file > 0 ) {
			$args['f'] = $file;
		}
		if ( $thumb ) {
			$args['s'] = 'thumb';
		}
		$args['_wpnonce'] = wp_create_nonce( 'ctc_file_' . $order_id );
		return add_query_arg( $args, admin_url( 'admin-post.php' ) );
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
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified right below.
		$order_id = absint( $_GET['order'] ?? 0 );
		$index    = absint( $_GET['i'] ?? 0 );
		$nth      = absint( $_GET['f'] ?? 0 );
		$small    = 'thumb' === sanitize_key( wp_unslash( $_GET['s'] ?? '' ) );
		// phpcs:enable
		check_admin_referer( 'ctc_file_' . $order_id );
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'card-to-card' ), '', array( 'response' => 403 ) );
		}
		$order    = wc_get_order( $order_id );
		$receipts = $order instanceof \WC_Order ? Orders::receipts( $order ) : array();
		$receipt  = $receipts[ $index ] ?? null;
		$file     = is_array( $receipt ) && empty( $receipt['deleted'] ) ? ( self::files( $receipt )[ $nth ] ?? null ) : null;
		$path     = is_array( $file ) ? self::path( $file ) : '';
		if ( '' === $path ) {
			wp_die( esc_html__( 'فایل رسید پیدا نشد.', 'card-to-card' ), '', array( 'response' => 404 ) );
		}
		$type  = (string) $file['type'];
		$image = self::is_image( $type );
		$name  = (string) $file['name'];
		if ( $small && $image ) {
			$thumb = self::thumb( $file );
			if ( '' !== $thumb ) {
				$path = $thumb;
				$type = 'image/jpeg';
				$name = (string) preg_replace( '/\.[a-z0-9]+$/i', '', $name ) . '-thumb.jpg';
			}
		}
		// Anything printed before us (a BOM, a notice, a plugin's whitespace) would corrupt the
		// picture, and output compression would make Content-Length wrong: send the bytes alone.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		// Images open in the tab; anything else (PDF, HEIC) downloads, so a customer's file never runs
		// as a document of the admin's origin. The sandbox blocks scripts either way.
		header( 'Content-Type: ' . ( $image || 'application/pdf' === $type || 'image/heic' === $type ? $type : 'application/octet-stream' ) );
		header( 'Content-Disposition: ' . ( $image ? 'inline' : 'attachment' ) . '; filename="' . rawurlencode( $name ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox" );
		// A stored file never changes and the URL carries the manager's nonce: let only this browser
		// keep it for a while, so the receipts list does not download every picture again.
		header( 'Cache-Control: private, max-age=86400' );
		header( 'Referrer-Policy: no-referrer' );
		if ( ! ini_get( 'zlib.output_compression' ) ) {
			header( 'Content-Length: ' . (string) filesize( $path ) );
		}
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
			if ( empty( $receipt['deleted'] ) ) {
				self::discard( $receipt );
			}
		}
	}
}
