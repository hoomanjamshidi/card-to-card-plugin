<?php
/**
 * Module «رسید تکراری و مشکوک»: every uploaded receipt gets a fingerprint — the file's SHA-256, a
 * visual fingerprint (Support\Image_Hash) and the normalised bank reference — compared with the
 * receipts of all other orders. Matches become flags on the receipt («⚠️ مشکوک» in the panel and on
 * the order screen); in «block» mode a sure duplicate is refused at upload.
 *
 * Fingerprints live in their own table and outlive the files, so a receipt cannot be reused after
 * the retention clean-up deleted the original.
 *
 * Levels:
 *  - danger: same file; same picture re-saved at the same size; same reference + same card digits;
 *    or the same long (≥ 10 digit) reference.
 *  - warn:   a very similar picture (e.g. a resized copy — or a receipt that differs in one digit, so a
 *    person compares); the same short reference only (6-digit references repeat across banks); or
 *    any match with a cancelled, never approved order of the same customer (likely the same payment
 *    sent again).
 *
 * @package CardToCard
 */

namespace CardToCard\Modules;

use CardToCard\Orders;
use CardToCard\Receipts;
use CardToCard\Support\Image_Hash;
use CardToCard\Support\Persian;

defined( 'ABSPATH' ) || exit;

/**
 * Duplicate / suspicious receipts.
 */
final class Fraud extends Module {

	public const DB_VERSION = '1';

	/**
	 * Pixel-by-pixel difference under which two thumbnails are the same picture (re-saved).
	 */
	private const SAME = 0.00005;

	/**
	 * Shift-forgiving difference under which two thumbnails are «very similar», by sensitivity.
	 */
	private const SIMILAR = array(
		'low'    => 0.00004,
		'normal' => 0.00007,
		'high'   => 0.0001,
	);

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'fraud';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'تشخیص رسید تکراری و مشکوک', 'card-to-card' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'هر رسید با رسیدهای همه سفارش‌های دیگر مقایسه می‌شود: همان فایل، همان تصویر (حتی دوباره ذخیره یا کوچک‌شده) و شماره پیگیری تکراری. رسید مشکوک برچسب می‌خورد یا اصلاً پذیرفته نمی‌شود.', 'card-to-card' );
	}

	/**
	 * Icon.
	 *
	 * @return string
	 */
	public function icon(): string {
		return 'shield';
	}

	/**
	 * Defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return array(
			'file'        => true, // Same file (SHA-256).
			'image'       => true, // Same image as a different file.
			'ref'         => true, // Same bank reference.
			'action'      => 'flag', // flag | block (sure duplicates are refused at upload).
			'confirm'     => true, // Ask before approving a flagged receipt.
			'sensitivity' => 'normal', // low | normal | high.
		);
	}

	/**
	 * Choices.
	 *
	 * @return array<string, string[]>
	 */
	protected function choices(): array {
		return array(
			'action'      => array( 'flag', 'block' ),
			'sensitivity' => array( 'low', 'normal', 'high' ),
		);
	}

	/**
	 * Hooks.
	 */
	public function boot(): void {
		self::install();
		add_filter( 'ctc_receipt_check', array( $this, 'check' ), 10, 4 );
		add_action( 'ctc_receipt_uploaded', array( $this, 'record' ) );
	}

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ctc_fingerprints';
	}

	/**
	 * Create / upgrade the table (once per version).
	 */
	public static function install(): void {
		if ( self::DB_VERSION === get_option( 'ctc_fp_db' ) ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_id bigint(20) unsigned NOT NULL,
				idx smallint(5) unsigned NOT NULL DEFAULT 0,
				sha char(64) NOT NULL DEFAULT '',
				dhash char(64) NOT NULL DEFAULT '',
				ratio smallint(5) unsigned NOT NULL DEFAULT 0,
				thumb blob NULL,
				ref varchar(40) NOT NULL DEFAULT '',
				last4 char(4) NOT NULL DEFAULT '',
				created int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY order_id (order_id),
				KEY sha (sha),
				KEY ref (ref),
				KEY created (created)
			) {$charset};"
		);
		update_option( 'ctc_fp_db', self::DB_VERSION, false );
	}

	/**
	 * Bank reference as digits («۴۸۲-۹۱۶» → 482916); '' when too short to mean anything.
	 *
	 * @param string $ref Reference.
	 * @return string
	 */
	public static function normalize_ref( string $ref ): string {
		$digits = ltrim( Persian::only_digits( $ref ), '0' );
		return strlen( $digits ) >= 5 ? substr( $digits, 0, 40 ) : '';
	}

	/**
	 * Fingerprint of a stored receipt.
	 *
	 * @param array<string, mixed> $stored Stored file.
	 * @param string               $ref    Reference.
	 * @param string               $last4  Card digits.
	 * @return array{sha:string, dhash:string, ratio:int, thumb:string, ref:string, last4:string}
	 */
	public function fingerprint( array $stored, string $ref, string $last4 ): array {
		$path  = Receipts::path( $stored );
		$image = '' !== $path && $this->conf( 'image' ) && str_starts_with( (string) ( $stored['type'] ?? '' ), 'image/' ) ? Image_Hash::analyze( $path ) : null;
		return array(
			'sha'   => '' !== $path ? (string) hash_file( 'sha256', $path ) : '',
			'dhash' => $image['hash'] ?? '',
			'ratio' => $image['ratio'] ?? 0,
			'thumb' => $image['thumb'] ?? '',
			'ref'   => self::normalize_ref( $ref ),
			'last4' => 4 === strlen( $last4 ) ? $last4 : '',
		);
	}

	/**
	 * Compare a fingerprint with other orders' receipts.
	 *
	 * @param array<string, mixed> $fp    Fingerprint.
	 * @param \WC_Order            $order Order the receipt is for.
	 * @return array<int, array<string, mixed>> Flags, worst first, one per other order.
	 */
	public function matches( array $fp, \WC_Order $order ): array {
		global $wpdb;
		$table = self::table();
		$id    = $order->get_id();
		$hits  = array(); // order id => [ type => true ].

		if ( $this->conf( 'file' ) && '' !== $fp['sha'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, fresh data needed.
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT order_id FROM {$table} WHERE sha = %s AND order_id <> %d LIMIT 10", $fp['sha'], $id ) ) as $other ) {
				$hits[ (int) $other ]['file'] = true;
			}
		}
		if ( $this->conf( 'image' ) && '' !== $fp['dhash'] ) {
			$limit = self::SIMILAR[ (string) $this->conf( 'sensitivity', 'normal' ) ] ?? self::SIMILAR['normal'];
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows       = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, order_id, dhash, ratio FROM {$table} WHERE dhash <> '' AND order_id <> %d AND created > %d ORDER BY id DESC LIMIT 5000", $id, time() - 2 * YEAR_IN_SECONDS ), ARRAY_A );
			$candidates = array();
			foreach ( $rows as $row ) {
				if ( abs( (int) $row['ratio'] - (int) $fp['ratio'] ) > 20 ) {
					continue; // Different shape: not the same picture.
				}
				$dist = Image_Hash::distance( $fp['dhash'], (string) $row['dhash'] );
				if ( $dist <= 40 ) {
					$candidates[ (int) $row['id'] ] = $dist;
				}
			}
			asort( $candidates );
			foreach ( array_slice( $candidates, 0, 25, true ) as $row_id => $dist ) {
				unset( $dist );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$row   = $wpdb->get_row( $wpdb->prepare( "SELECT order_id, thumb FROM {$table} WHERE id = %d", $row_id ), ARRAY_A );
				$thumb = is_array( $row ) && $row['thumb'] ? gzuncompress( (string) $row['thumb'] ) : '';
				if ( ! is_string( $thumb ) || '' === $thumb ) {
					continue;
				}
				if ( Image_Hash::difference( $fp['thumb'], $thumb ) <= self::SAME ) {
					$hits[ (int) $row['order_id'] ]['image'] = true;
				} elseif ( Image_Hash::difference( $fp['thumb'], $thumb, 1 ) <= $limit ) {
					$hits[ (int) $row['order_id'] ]['similar'] = true;
				}
			}
		}
		if ( $this->conf( 'ref' ) && '' !== $fp['ref'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT order_id, last4 FROM {$table} WHERE ref = %s AND order_id <> %d LIMIT 10", $fp['ref'], $id ), ARRAY_A );
			foreach ( $rows as $row ) {
				$same_card                                  = '' !== $fp['last4'] && $fp['last4'] === (string) $row['last4'];
				$hits[ (int) $row['order_id'] ]['ref']      = true;
				$hits[ (int) $row['order_id'] ]['ref_card'] = ! empty( $hits[ (int) $row['order_id'] ]['ref_card'] ) || $same_card;
			}
		}

		$flags = array();
		foreach ( $hits as $other_id => $types ) {
			$flags[] = $this->classify( $other_id, $types, $fp, $order );
		}
		usort( $flags, static fn( $a, $b ) => ( 'danger' === $b['level'] ) <=> ( 'danger' === $a['level'] ) );
		return $flags;
	}

	/**
	 * Level and reason of a match with another order.
	 *
	 * @param int                  $other_id Other order.
	 * @param array<string, bool>  $types    What matched (file, image, ref, ref_card).
	 * @param array<string, mixed> $fp       Fingerprint.
	 * @param \WC_Order            $order    Order.
	 * @return array<string, mixed>
	 */
	private function classify( int $other_id, array $types, array $fp, \WC_Order $order ): array {
		$what = array();
		if ( ! empty( $types['image'] ) || ! empty( $types['file'] ) ) {
			unset( $types['similar'] );
		}
		foreach ( array( 'file', 'image', 'similar', 'ref' ) as $type ) {
			if ( ! empty( $types[ $type ] ) ) {
				$what[] = $type;
			}
		}
		$sure  = ! empty( $types['file'] ) || ! empty( $types['image'] ) || ! empty( $types['ref_card'] ) || ( ! empty( $types['ref'] ) && strlen( (string) $fp['ref'] ) >= 10 );
		$level = $sure ? 'danger' : 'warn';
		$note  = '';
		$other = wc_get_order( $other_id );
		if ( $other instanceof \WC_Order ) {
			$same_customer = ( '' !== $order->get_billing_phone() && Persian::mobile( $order->get_billing_phone() ) === Persian::mobile( $other->get_billing_phone() ) )
				|| ( '' !== $order->get_billing_email() && strtolower( $order->get_billing_email() ) === strtolower( $other->get_billing_email() ) );
			$never_paid    = ! $other->get_meta( '_ctc_approved' ) && ! $other->is_paid();
			if ( $same_customer && $never_paid && $other->has_status( array( 'cancelled', 'failed' ) ) ) {
				$level = 'warn';
				$note  = 'reuse';
			} elseif ( $same_customer ) {
				$note = 'same_customer';
			}
		} else {
			$note = 'deleted';
		}
		return array(
			'level'  => $level,
			'types'  => $what,
			'order'  => $other_id,
			'number' => $other instanceof \WC_Order ? (string) $other->get_order_number() : (string) $other_id,
			'note'   => $note,
		);
	}

	/**
	 * Before a receipt is attached: fingerprint, flags, and (block mode) refuse a sure duplicate.
	 *
	 * @param array<string, mixed>|\WP_Error $stored Stored receipt.
	 * @param \WC_Order                      $order  Order.
	 * @param string                         $ref    Reference.
	 * @param string                         $last4  Card digits.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function check( $stored, $order, $ref = '', $last4 = '' ) {
		if ( ! is_array( $stored ) || ! $order instanceof \WC_Order || ! $this->enabled() ) {
			return $stored;
		}
		$fp    = $this->fingerprint( $stored, (string) $ref, (string) $last4 );
		$flags = $this->matches( $fp, $order );
		if ( $flags && 'block' === $this->conf( 'action' ) && 'danger' === $flags[0]['level'] ) {
			/* translators: %s: other order number */
			$order->add_order_note( sprintf( __( 'رسید تکراری پذیرفته نشد: همین رسید قبلاً برای سفارش #%s ارسال شده بود.', 'card-to-card' ), $flags[0]['number'] ) );
			return new \WP_Error( 'ctc_duplicate', __( 'این رسید قبلاً برای سفارش دیگری ارسال شده است. اگر فکر می‌کنید اشتباهی پیش آمده، با پشتیبانی تماس بگیرید.', 'card-to-card' ) );
		}
		$stored['fp']    = array(
			'sha'   => $fp['sha'],
			'dhash' => $fp['dhash'],
			'ratio' => $fp['ratio'],
			'ref'   => $fp['ref'],
			'last4' => $fp['last4'],
		);
		$stored['flags'] = $flags;
		self::$pending   = $fp;
		return $stored;
	}

	/**
	 * Fingerprint of the receipt being attached (thumbnail kept out of order meta).
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $pending = null;

	/**
	 * After the receipt is attached: store its fingerprint and note the flags on the order.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function record( $order ): void {
		if ( ! $order instanceof \WC_Order || null === self::$pending ) {
			return;
		}
		$fp            = self::$pending;
		self::$pending = null;
		$receipts      = Orders::receipts( $order );
		$index         = count( $receipts ) - 1;
		self::insert( $order->get_id(), max( 0, $index ), $fp );
		$flags = $index >= 0 ? (array) ( $receipts[ $index ]['flags'] ?? array() ) : array();
		if ( $flags ) {
			$order->add_order_note( '⚠️ ' . implode( ' ', array_map( array( self::class, 'describe' ), $flags ) ) );
		}
	}

	/**
	 * Save a fingerprint row.
	 *
	 * @param int                  $order_id Order.
	 * @param int                  $index    Receipt index.
	 * @param array<string, mixed> $fp       Fingerprint.
	 */
	public static function insert( int $order_id, int $index, array $fp ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- own table.
		$wpdb->insert(
			self::table(),
			array(
				'order_id' => $order_id,
				'idx'      => $index,
				'sha'      => (string) $fp['sha'],
				'dhash'    => (string) $fp['dhash'],
				'ratio'    => (int) $fp['ratio'],
				'thumb'    => '' !== (string) $fp['thumb'] ? gzcompress( (string) $fp['thumb'], 6 ) : null,
				'ref'      => (string) $fp['ref'],
				'last4'    => (string) $fp['last4'],
				'created'  => time(),
			),
			array( '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%d' )
		);
	}

	/**
	 * Sentence for a flag.
	 *
	 * @param array<string, mixed> $flag Flag.
	 * @return string
	 */
	public static function describe( array $flag ): string {
		$names = array(
			'file'    => __( 'همان فایل', 'card-to-card' ),
			'image'   => __( 'همان تصویر', 'card-to-card' ),
			'similar' => __( 'تصویری بسیار مشابه', 'card-to-card' ),
			'ref'     => __( 'همان شماره پیگیری', 'card-to-card' ),
		);
		$what  = implode( '، ', array_map( static fn( $t ) => $names[ $t ] ?? $t, (array) ( $flag['types'] ?? array() ) ) );
		/* translators: 1: what matched, 2: other order number */
		$text  = sprintf( __( '%1$s قبلاً برای سفارش #%2$s ارسال شده است.', 'card-to-card' ), $what, Persian::digits( (string) ( $flag['number'] ?? '' ) ) );
		$notes = array(
			'reuse'         => __( 'آن سفارش متعلق به همین مشتری است و لغو شده؛ احتمالاً همان واریز را برای سفارش تازه فرستاده.', 'card-to-card' ),
			'same_customer' => __( 'هر دو سفارش متعلق به یک مشتری‌اند.', 'card-to-card' ),
			'deleted'       => __( 'آن سفارش حذف شده است.', 'card-to-card' ),
		);
		$note  = $notes[ (string) ( $flag['note'] ?? '' ) ] ?? '';
		return trim( $text . ' ' . $note );
	}

	/**
	 * Flags of a receipt for the panel: level, text and a link to the other order.
	 *
	 * @param array<string, mixed> $receipt Receipt.
	 * @return array<int, array{level:string, text:string, order:int, number:string, edit:string}>
	 */
	public static function present( array $receipt ): array {
		$out = array();
		foreach ( (array) ( $receipt['flags'] ?? array() ) as $flag ) {
			if ( ! is_array( $flag ) ) {
				continue;
			}
			$other = wc_get_order( (int) ( $flag['order'] ?? 0 ) );
			$out[] = array(
				'level'  => 'danger' === ( $flag['level'] ?? '' ) ? 'danger' : 'warn',
				'text'   => self::describe( $flag ),
				'order'  => (int) ( $flag['order'] ?? 0 ),
				'number' => (string) ( $flag['number'] ?? '' ),
				'edit'   => $other instanceof \WC_Order ? $other->get_edit_order_url() : '',
			);
		}
		return $out;
	}

	/**
	 * Whether a receipt carries a «danger» flag.
	 *
	 * @param array<string, mixed> $receipt Receipt.
	 * @return bool
	 */
	public static function is_danger( array $receipt ): bool {
		foreach ( (array) ( $receipt['flags'] ?? array() ) as $flag ) {
			if ( is_array( $flag ) && 'danger' === ( $flag['level'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Panel: whether image fingerprints can be made here, and how many receipts are indexed.
	 *
	 * @return array<string, mixed>
	 */
	public function data(): array {
		global $wpdb;
		$count = 0;
		if ( self::DB_VERSION === get_option( 'ctc_fp_db' ) ) {
			$table = self::table();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, no input.
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}
		return array(
			'gd'      => function_exists( 'imagecreatefromstring' ),
			'heic'    => class_exists( '\Imagick' ),
			'indexed' => $count,
		);
	}
}
