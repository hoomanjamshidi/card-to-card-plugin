<?php
/**
 * The private payment page (/card-pay/<id>-<key>/): the shop's card, amount, deadline and receipt
 * upload; afterwards the review / paid / cancelled state. Works on any theme:
 *  - «theme» layout: classic themes get get_header()/get_footer(); block themes get their header and
 *    footer template parts;
 *  - «standalone» layout: a clean full page of its own.
 * Templates can be overridden from a theme folder `card-to-card/`.
 *
 * @package CardToCard
 */

namespace CardToCard;

use CardToCard\Support\Banks;
use CardToCard\Support\Persian;

defined( 'ABSPATH' ) || exit;

/**
 * Payment page.
 */
final class Payment_Page {

	public const QUERY_VAR = 'ctc_pay';

	/**
	 * Option holding the id of the real WordPress page the payment URL is routed to.
	 */
	public const PAGE_OPTION = 'ctc_page_id';

	/**
	 * Shortcode printed by that page (fallback when a theme or page builder ignores template_include).
	 */
	public const SHORTCODE = 'card_to_card_payment';

	/**
	 * Order of the current request.
	 *
	 * @var \WC_Order|null
	 */
	private static ?\WC_Order $order = null;

	/**
	 * Errors and old values of a failed receipt submission.
	 *
	 * @var array{errors:array<string,string>, old:array<string,string>}
	 */
	private static array $form = array(
		'errors' => array(),
		'old'    => array(),
	);

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'rewrite' ), 20 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'template_redirect' ), 5 );
		add_filter( 'template_include', array( __CLASS__, 'template_include' ), 99 );
		add_filter( 'document_title_parts', array( __CLASS__, 'title' ) );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'ctc_settings_saved', array( __CLASS__, 'flush_rules' ) );
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
		add_filter( 'redirect_canonical', array( __CLASS__, 'no_canonical' ) );
		add_action( 'wp_trash_post', array( __CLASS__, 'page_removed' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'page_removed' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'page_removed' ) );
		add_filter( 'display_post_states', array( __CLASS__, 'post_state' ), 10, 2 );
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'sitemap_exclude' ) );
		add_filter( 'wpseo_exclude_from_sitemap_by_post_ids', array( __CLASS__, 'seo_exclude' ) );
		add_filter( 'rank_math/sitemap/exclude_posts', array( __CLASS__, 'seo_exclude' ) );
	}

	/**
	 * Current order (null outside the payment page).
	 *
	 * @return \WC_Order|null
	 */
	public static function order(): ?\WC_Order {
		return self::$order;
	}

	/**
	 * Pretty URL /<slug>/<key>/, routed to a real page of the site.
	 *
	 * Rewriting to a bare query var makes WordPress treat the request as the blog home, so a theme
	 * or page builder that takes over template_include (Elementor, custom themes…) shows the posts
	 * list. Routing to a published page instead makes every theme render that page, whose content
	 * (the shortcode) prints the payment page; our own wrappers still win when the theme allows.
	 */
	public static function rewrite(): void {
		$slug    = (string) Settings::get( 'slug', 'card-pay' );
		$version = CTC_VERSION . '|' . $slug . '|2';
		$fresh   = get_option( 'ctc_rewrite' ) !== $version;
		$page    = self::page_id();
		// Every request: is the page still there and published? If not, (re)create it and rebuild.
		if ( ! $fresh && $page && ( 'publish' !== get_post_status( $page ) || 'page' !== get_post_type( $page ) ) ) {
			$fresh = true;
		}
		$page   = $fresh ? self::ensure_page() : $page;
		$target = $page ? 'index.php?page_id=' . $page . '&' . self::QUERY_VAR . '=$matches[1]' : 'index.php?' . self::QUERY_VAR . '=$matches[1]';
		add_rewrite_rule( '^' . preg_quote( $slug, '#' ) . '/(\d+-[a-f0-9]{16})/?$', $target, 'top' );
		if ( $fresh ) {
			flush_rewrite_rules( false );
			update_option( 'ctc_rewrite', $version, false );
		}
	}

	/**
	 * Id of the payment page (0 when missing).
	 *
	 * @return int
	 */
	public static function page_id(): int {
		return (int) get_option( self::PAGE_OPTION, 0 );
	}

	/**
	 * Make sure the payment page exists, is published and carries the current slug.
	 *
	 * @return int Page id (0 when it could not be created).
	 */
	public static function ensure_page(): int {
		$slug = (string) Settings::get( 'slug', 'card-pay' );
		$id   = self::page_id();
		$post = $id ? get_post( $id ) : null;
		if ( ! $post instanceof \WP_Post || 'page' !== $post->post_type || 'trash' === $post->post_status ) {
			// A page left from an earlier install (option removed) is reused.
			$found = get_posts(
				array(
					'post_type'      => 'page',
					'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
					's'              => '[' . self::SHORTCODE . ']',
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);
			$post  = $found ? get_post( (int) $found[0] ) : null;
		}
		if ( $post instanceof \WP_Post ) {
			$changes = array();
			if ( 'publish' !== $post->post_status ) {
				$changes['post_status'] = 'publish';
			}
			if ( $slug !== $post->post_name ) {
				$changes['post_name'] = $slug;
			}
			if ( $changes ) {
				wp_update_post( array( 'ID' => $post->ID ) + $changes );
			}
			update_option( self::PAGE_OPTION, $post->ID, true );
			return (int) $post->ID;
		}
		$id = wp_insert_post(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'post_title'     => __( 'پرداخت کارت به کارت', 'card-to-card' ),
				'post_name'      => $slug,
				'post_content'   => '<!-- wp:shortcode -->[' . self::SHORTCODE . ']<!-- /wp:shortcode -->',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			),
			true
		);
		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}
		update_option( self::PAGE_OPTION, (int) $id, true );
		return (int) $id;
	}

	/**
	 * Our page was trashed, restored or deleted: rebuild the route on the next request.
	 *
	 * @param int $post_id Post id.
	 */
	public static function page_removed( $post_id ): void {
		if ( self::page_id() === (int) $post_id ) {
			delete_option( 'ctc_rewrite' );
		}
	}

	/**
	 * Shortcode of the payment page.
	 *
	 * @return string
	 */
	public static function shortcode(): string {
		if ( ! self::$order ) {
			return '';
		}
		ob_start();
		echo '<div id="ctc-main" class="ctc-shell is-theme is-content">';
		self::body();
		echo '</div>';
		return (string) ob_get_clean();
	}

	/**
	 * No canonical redirect away from /<slug>/<key>/.
	 *
	 * @param string|false $url Redirect URL.
	 * @return string|false
	 */
	public static function no_canonical( $url ) {
		return '' !== (string) get_query_var( self::QUERY_VAR ) ? false : $url;
	}

	/**
	 * «صفحه پرداخت کارت به کارت» label in the pages list.
	 *
	 * @param array<string, string> $states States.
	 * @param \WP_Post              $post   Post.
	 * @return array<string, string>
	 */
	public static function post_state( $states, $post ): array {
		$states = (array) $states;
		if ( $post instanceof \WP_Post && self::page_id() === $post->ID ) {
			$states['ctc_page'] = __( 'صفحه پرداخت کارت به کارت (حذف نشود)', 'card-to-card' );
		}
		return $states;
	}

	/**
	 * Keep the page out of the core sitemap.
	 *
	 * @param array<string, mixed> $args Query args.
	 * @return array<string, mixed>
	 */
	public static function sitemap_exclude( $args ): array {
		$args = (array) $args;
		if ( self::page_id() ) {
			$args['post__not_in']   = (array) ( $args['post__not_in'] ?? array() );
			$args['post__not_in'][] = self::page_id();
		}
		return $args;
	}

	/**
	 * Keep the page out of Yoast / Rank Math sitemaps.
	 *
	 * @param int[] $ids Excluded ids.
	 * @return int[]
	 */
	public static function seo_exclude( $ids ): array {
		$ids = array_map( 'intval', (array) $ids );
		if ( self::page_id() ) {
			$ids[] = self::page_id();
		}
		return $ids;
	}

	/**
	 * The slug changed: flush on the next request.
	 */
	public static function flush_rules(): void {
		delete_option( 'ctc_rewrite' );
	}

	/**
	 * Query var.
	 *
	 * @param string[] $vars Vars.
	 * @return string[]
	 */
	public static function query_vars( $vars ): array {
		$vars   = (array) $vars;
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Resolve the page. Wrong key → 404 (rate limited against guessing); POST → receipt.
	 */
	public static function template_redirect(): void {
		$key = (string) get_query_var( self::QUERY_VAR );
		if ( '' === $key ) {
			// The bare page (/card-pay/) has nothing to show without a key.
			if ( self::page_id() && is_page( self::page_id() ) ) {
				global $wp_query;
				$wp_query->set_404();
				status_header( 404 );
				nocache_headers();
			}
			return;
		}
		nocache_headers();
		// The address itself is the key: never pass it to other sites (fonts, images, links).
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Robots-Tag: noindex, nofollow', true );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// Shared constant of cache plugins (WP Rocket, LiteSpeed, W3TC, WP Super Cache).
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
		}
		do_action( 'litespeed_control_set_nocache', 'card-to-card payment page' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed's hook.
		// Only wrong keys are counted, per address and order: a valid link opens any number of times,
		// also for the many customers that share one address (mobile carrier NAT, a CDN such as
		// Cloudflare / ArvanCloud in front of the shop). Guessing a 64-bit HMAC is hopeless anyway.
		$bucket = (string) absint( strtok( $key, '-' ) );
		$order  = Util::limited( 'key', 20, $bucket ) ? null : Orders::from_key( $key );
		if ( ! $order ) {
			Util::hit( 'key', HOUR_IN_SECONDS, $bucket );
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return;
		}
		self::$order = $order;
		$method      = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle_post().
		if ( 'POST' === $method && isset( $_POST['ctc_nonce'] ) ) {
			self::handle_post( $order );
		} elseif ( 'POST' === $method && ! $_POST && ! $_FILES && isset( $_SERVER['CONTENT_LENGTH'] ) && (int) $_SERVER['CONTENT_LENGTH'] > 0 ) {
			// Bigger than the server's post_max_size: PHP dropped the whole form. Say so instead of
			// showing the same empty form again.
			self::$form['errors']['file'] = self::too_big_message();
		}
		// phpcs:enable
	}

	/**
	 * Largest receipt the shop and the server both accept, in bytes.
	 *
	 * @return int
	 */
	public static function max_bytes(): int {
		$panel  = max( 1, (int) Settings::get( 'max_mb', 5 ) ) * MB_IN_BYTES;
		$server = (int) wp_max_upload_size();
		return $server > 0 ? min( $panel, $server ) : $panel;
	}

	/**
	 * «File too big» message with the real limit.
	 *
	 * @return string
	 */
	public static function too_big_message(): string {
		/* translators: %s: size in MB */
		return sprintf( __( 'حجم فایل حداکثر %s مگابایت باشد.', 'card-to-card' ), Persian::digits( (string) self::max_mb() ) );
	}

	/**
	 * The limit in whole megabytes for people (at least 1).
	 *
	 * @return int
	 */
	public static function max_mb(): int {
		return max( 1, (int) floor( self::max_bytes() / MB_IN_BYTES ) );
	}

	/**
	 * How many receipt files this order may send at once: 1, or the panel's maximum when «several
	 * receipts» is on and the order reaches its minimum total.
	 *
	 * @param \WC_Order $order Order.
	 * @return int
	 */
	public static function max_files( \WC_Order $order ): int {
		if ( ! Settings::get( 'multi' ) ) {
			return 1;
		}
		$min = (int) Settings::get( 'multi_min', 0 );
		if ( $min > 0 && (float) $order->get_total() < $min ) {
			return 1;
		}
		return max( 2, min( 10, (int) Settings::get( 'multi_max', 5 ) ) );
	}

	/**
	 * Handle the receipt form (POST, then redirect back on success).
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function handle_post( \WC_Order $order ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- the nonce is checked first.
		$nonce = sanitize_key( wp_unslash( $_POST['ctc_nonce'] ?? '' ) );
		$ref   = substr( Persian::latin( sanitize_text_field( wp_unslash( $_POST['ctc_ref'] ?? '' ) ) ), 0, 40 );
		$last4 = substr( Persian::only_digits( sanitize_text_field( wp_unslash( $_POST['ctc_last4'] ?? '' ) ) ), 0, 4 );
		$trap  = sanitize_text_field( wp_unslash( $_POST['ctc_hp'] ?? '' ) );
		// phpcs:enable
		$errors = array();
		$mode   = array(
			'ref'   => (string) Settings::get( 'ref', 'optional' ),
			'last4' => (string) Settings::get( 'last4', 'optional' ),
		);
		$ref    = 'hidden' === $mode['ref'] ? '' : $ref;
		$last4  = 'hidden' === $mode['last4'] ? '' : $last4;

		// The order's own form token; a WordPress nonce is still accepted from pages opened before it.
		$token_ok = '' !== $nonce && ( hash_equals( Orders::form_token( $order ), $nonce ) || wp_verify_nonce( $nonce, 'ctc_receipt_' . $order->get_id() ) );
		if ( ! $token_ok ) {
			$errors['form'] = __( 'صفحه را دوباره باز کنید و امتحان کنید.', 'card-to-card' );
		} elseif ( '' !== $trap ) {
			$errors['form'] = __( 'ارسال انجام نشد.', 'card-to-card' );
		} elseif ( ! $order->has_status( Orders::AWAITING ) ) {
			$errors['form'] = __( 'این سفارش الان منتظر رسید نیست.', 'card-to-card' );
		} elseif ( Util::limited( 'receipt', 10, (string) $order->get_id() ) ) {
			$errors['form'] = __( 'تعداد ارسال‌ها زیاد شد؛ کمی بعد دوباره امتحان کنید.', 'card-to-card' );
		}
		if ( ! $errors && 'required' === $mode['ref'] && '' === $ref ) {
			$errors['ref'] = __( 'شماره پیگیری واریز را بنویسید.', 'card-to-card' );
		}
		if ( ! $errors && ( ( 'required' === $mode['last4'] && '' === $last4 ) || ( '' !== $last4 && 4 !== strlen( $last4 ) ) ) ) {
			$errors['last4'] = __( 'چهار رقم آخر کارت را کامل بنویسید.', 'card-to-card' );
		}
		// Extra fields from the panel (label => value is stored, so later label edits keep history readable).
		$extra = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked above; sanitised per field below.
		$posted = isset( $_POST['ctc_f'] ) && is_array( $_POST['ctc_f'] ) ? wp_unslash( $_POST['ctc_f'] ) : array();
		foreach ( (array) Settings::get( 'fields', array() ) as $field ) {
			$raw   = $posted[ $field['id'] ] ?? '';
			$value = 'textarea' === $field['type'] ? sanitize_textarea_field( (string) $raw ) : sanitize_text_field( (string) $raw );
			$value = in_array( $field['type'], array( 'number', 'tel' ), true ) ? Persian::latin( $value ) : $value;
			$value = mb_substr( $value, 0, 500 );
			if ( 'select' === $field['type'] && '' !== $value && ! in_array( $value, self::options( $field ), true ) ) {
				$value = '';
			}
			if ( 'number' === $field['type'] && '' !== $value && ! is_numeric( str_replace( array( ',', '٬' ), '', $value ) ) ) {
				/* translators: %s: field label */
				$errors[ 'f_' . $field['id'] ] = sprintf( __( '«%s» باید عدد باشد.', 'card-to-card' ), $field['label'] );
			}
			if ( ! $errors && $field['required'] && '' === $value ) {
				/* translators: %s: field label */
				$errors[ 'f_' . $field['id'] ] = sprintf( __( '«%s» را وارد کنید.', 'card-to-card' ), $field['label'] );
			}
			$extra[ $field['id'] ] = $value;
		}
		$stored = null;
		if ( ! $errors ) {
			// Only real upload attempts count: a forgotten field must never lock the customer out.
			Util::hit( 'receipt', HOUR_IN_SECONDS, (string) $order->get_id() );
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each file is checked in Receipts::store().
			$stored = self::store_files( Receipts::uploads( $_FILES['ctc_file'] ?? null ), self::max_files( $order ) );
			if ( is_wp_error( $stored ) ) {
				$errors['file'] = $stored->get_error_message();
			} else {
				/**
				 * Check a stored receipt before it is attached (the fraud module adds fingerprints and
				 * flags, or blocks a duplicate). Return the (extended) receipt or a WP_Error.
				 *
				 * @param array<string, mixed> $stored Stored file (path, name, type, size).
				 * @param \WC_Order            $order  Order.
				 * @param string               $ref    Bank reference typed by the customer.
				 * @param string               $last4  Last four digits of the payer's card.
				 */
				/**
				 * Checked receipt or the refusal.
				 *
				 * @var array<string, mixed>|\WP_Error $checked
				 */
				$checked = apply_filters( 'ctc_receipt_check', $stored, $order, $ref, $last4 );
				if ( is_wp_error( $checked ) ) {
					Receipts::discard( $stored );
					$errors['file'] = $checked->get_error_message();
					$stored         = null;
				} elseif ( is_array( $checked ) ) {
					$stored = $checked;
				}
			}
		}
		if ( $errors || ! is_array( $stored ) ) {
			self::$form = array(
				'errors' => $errors,
				'old'    => array(
					'ref'   => $ref,
					'last4' => $last4,
				) + array_combine( array_map( static fn( $k ) => 'f_' . $k, array_keys( $extra ) ), array_values( $extra ) ),
			);
			return;
		}
		$labelled = array();
		foreach ( (array) Settings::get( 'fields', array() ) as $field ) {
			if ( '' !== ( $extra[ $field['id'] ] ?? '' ) ) {
				$labelled[ $field['label'] ] = $extra[ $field['id'] ];
			}
		}
		Receipts::attach( $order, $stored, $ref, $last4, $labelled );
		if ( ! defined( 'CTC_TESTING' ) ) {
			wp_safe_redirect( Orders::url( $order ) . '#ctc-state', 303 );
			exit;
		}
	}

	/**
	 * Store the picked files as one receipt: the first is the main file, the others go in «more».
	 * One bad file refuses the whole send and removes what was already stored.
	 *
	 * @param array<int, array<string, mixed>> $files Upload entries (Receipts::uploads()).
	 * @param int                              $max   Files allowed.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function store_files( array $files, int $max ) {
		if ( ! $files ) {
			return Receipts::store( array() ); // «Pick the receipt» error.
		}
		if ( count( $files ) > $max ) {
			return new \WP_Error(
				'file',
				1 === $max
					? __( 'فقط یک تصویر رسید بفرستید.', 'card-to-card' )
					/* translators: %s: number of files */
					: sprintf( __( 'حداکثر %s فایل رسید می‌توانید بفرستید.', 'card-to-card' ), Persian::digits( (string) $max ) )
			);
		}
		$done = array();
		foreach ( $files as $i => $file ) {
			$one = Receipts::store( $file );
			if ( is_wp_error( $one ) ) {
				foreach ( $done as $kept ) {
					Receipts::discard( $kept );
				}
				if ( count( $files ) > 1 ) {
					/* translators: 1: file number, 2: error */
					return new \WP_Error( 'file', sprintf( __( 'فایل %1$s: %2$s', 'card-to-card' ), Persian::digits( (string) ( $i + 1 ) ), $one->get_error_message() ) );
				}
				return $one;
			}
			$done[] = $one;
		}
		$main = array_shift( $done );
		if ( $done ) {
			$main['more'] = $done;
		}
		return $main;
	}

	/**
	 * Form state (tests and the template).
	 *
	 * @return array{errors:array<string,string>, old:array<string,string>}
	 */
	public static function form_state(): array {
		return self::$form;
	}

	/**
	 * Our template for the payment page.
	 *
	 * @param string $template Template.
	 * @return string
	 */
	public static function template_include( $template ): string {
		if ( ! self::$order ) {
			return (string) $template;
		}
		if ( 'standalone' === Settings::get( 'layout' ) ) {
			return self::locate( 'wrapper-standalone.php' );
		}
		return self::locate( wp_is_block_theme() ? 'wrapper-block.php' : 'wrapper-theme.php' );
	}

	/**
	 * Template path: theme `card-to-card/<file>` first, then the plugin's.
	 *
	 * @param string $file File.
	 * @return string
	 */
	public static function locate( string $file ): string {
		$theme = locate_template( 'card-to-card/' . $file );
		return '' !== $theme ? $theme : CTC_DIR . 'templates/' . $file;
	}

	/**
	 * Render a template with $args.
	 *
	 * @param string               $file Template file.
	 * @param array<string, mixed> $args Args.
	 */
	public static function part( string $file, array $args = array() ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $args is read by the template.
		$path = self::locate( $file );
		if ( is_readable( $path ) ) {
			include $path;
		}
	}

	/**
	 * Body of the payment page (used by every wrapper).
	 */
	public static function body(): void {
		if ( self::$order ) {
			self::part( 'payment-page.php', self::args( self::$order ) );
		}
	}

	/**
	 * Document title.
	 *
	 * @param array<string, string> $parts Parts.
	 * @return array<string, string>
	 */
	public static function title( $parts ): array {
		if ( self::$order ) {
			/* translators: %s: order number */
			$parts['title'] = sprintf( __( 'پرداخت کارت به کارت سفارش %s', 'card-to-card' ), Persian::digits( self::$order->get_order_number() ) );
		}
		return (array) $parts;
	}

	/**
	 * Private page: noindex, nofollow.
	 *
	 * @param array<string, mixed> $robots Directives.
	 * @return array<string, mixed>
	 */
	public static function robots( $robots ): array {
		if ( self::$order ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
			unset( $robots['max-image-preview'] );
		}
		return (array) $robots;
	}

	/**
	 * Styles and script of the payment page (and the thank-you / view-order boxes).
	 *
	 * @param bool $force Enqueue outside the payment page.
	 */
	public static function assets( bool $force = false ): void {
		$box = function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'view-order' ) );
		if ( ! self::$order && ! $force && ! $box ) {
			return;
		}
		$min = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		$css = file_exists( CTC_DIR . "assets/css/payment{$min}.css" ) ? $min : '';
		$js  = file_exists( CTC_DIR . "assets/js/payment{$min}.js" ) ? $min : '';
		// File time in the version: a changed stylesheet is never served stale from browser / CDN caches.
		$ver = static fn( string $file ): string => CTC_VERSION . '-' . (string) @filemtime( CTC_DIR . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing file falls back to the plain version.
		wp_enqueue_style( 'ctc-payment', CTC_URL . "assets/css/payment{$css}.css", array(), $ver( "assets/css/payment{$css}.css" ) );
		wp_add_inline_style( 'ctc-payment', self::inline_css() );
		wp_enqueue_script( 'ctc-payment', CTC_URL . "assets/js/payment{$js}.js", array(), $ver( "assets/js/payment{$js}.js" ), array( 'strategy' => 'defer' ) );
		wp_localize_script(
			'ctc-payment',
			'ctcPay',
			array(
				'copied'  => __( 'کپی شد', 'card-to-card' ),
				'copy'    => __( 'کپی', 'card-to-card' ),
				'pick'    => __( 'اول تصویر رسید را انتخاب کنید.', 'card-to-card' ),
				'remove'  => __( 'حذف', 'card-to-card' ),
				'addMore' => __( 'افزودن رسید دیگر', 'card-to-card' ),
				/* translators: %s: number of files */
				'nFiles'  => __( '%s فایل انتخاب شد', 'card-to-card' ),
				/* translators: %s: number of files */
				'tooMany' => __( 'حداکثر %s فایل می‌توانید بفرستید.', 'card-to-card' ),
				/* translators: %s: size in MB */
				'tooBig'  => __( 'حجم فایل بیشتر از %s مگابایت است.', 'card-to-card' ),
				'sending' => __( 'در حال ارسال…', 'card-to-card' ),
				'wait'    => __( 'رسید در حال ارسال است؛ تا پایان ارسال این صفحه را نبندید.', 'card-to-card' ),
				'prep'    => __( 'در حال آماده‌سازی تصویر…', 'card-to-card' ),
				'change'  => __( 'برای تغییر بزنید', 'card-to-card' ),
				/* translators: %s: size in KB */
				'kb'      => __( '%s کیلوبایت', 'card-to-card' ),
				/* translators: %s: size in MB */
				'mb'      => __( '%s مگابایت', 'card-to-card' ),
				/* translators: %s: time left, e.g. «۲ ساعت و ۱۰ دقیقه» */
				'left'    => __( '%s مانده', 'card-to-card' ),
				/* translators: 1: hours, 2: minutes */
				'hm'      => __( '%1$s ساعت و %2$s دقیقه', 'card-to-card' ),
				/* translators: %s: hours */
				'h'       => __( '%s ساعت', 'card-to-card' ),
				/* translators: %s: minutes */
				'm'       => __( '%s دقیقه', 'card-to-card' ),
				'tilt'    => (bool) Settings::get( 'tilt' ),
			)
		);
	}

	/**
	 * Accent colour and font as CSS variables.
	 *
	 * @return string
	 */
	public static function inline_css(): string {
		$accent = Settings::accent();
		$css    = '.ctc{--ctc-accent:' . $accent['css'] . ';--ctc-on-accent:' . Support\Colors::ink( $accent['hex'] ) . ';}';
		if ( 'vazirmatn' === Settings::get( 'font' ) ) {
			$url  = CTC_URL . 'assets/fonts/Vazirmatn-Variable.woff2';
			$css .= '@font-face{font-family:"CTC Vazirmatn";src:url("' . esc_url_raw( $url ) . '") format("woff2");font-weight:100 900;font-display:swap;}';
			$css .= '.ctc{--ctc-font:"CTC Vazirmatn",Tahoma,sans-serif;}';
		}
		return $css;
	}

	/**
	 * Inline bank logo (SVG file shipped with the plugin).
	 *
	 * @param string $slug Bank slug.
	 * @return string
	 */
	public static function logo( string $slug ): string {
		$file = CTC_DIR . 'assets/banks/' . sanitize_key( $slug ) . '.svg';
		if ( '' === $slug || ! is_readable( $file ) ) {
			return '';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bundled asset.
		return (string) file_get_contents( $file );
	}

	/**
	 * Data of one card plate.
	 *
	 * @param array<string, mixed> $card Resolved card.
	 * @return array<string, mixed>
	 */
	public static function card_view( array $card ): array {
		$palette = (array) $card['palette'];
		$skin    = (string) ( $card['skin'] ?? 'bank' );
		$colors  = (array) $palette['colors'];
		if ( 'custom' === $skin ) {
			$colors = array( (string) $card['color1'], (string) $card['color2'], (string) $card['color1'] );
		}
		return array(
			'id'      => (string) $card['id'],
			'slug'    => (string) $card['bank_slug'],
			'bank'    => (string) $card['bank_name'],
			'skin'    => $skin,
			'style'   => sprintf( '--c1:%s;--c2:%s;--c3:%s;--ink:%s;--foil:%s', $colors[0], $colors[1], $colors[2], 'custom' === $skin ? Support\Colors::ink( $colors[0], $colors[1] ) : $palette['ink'], 'custom' === $skin ? Support\Colors::ink( $colors[0], $colors[1] ) : $palette['accent'] ),
			'logo'    => Settings::get( 'logo' ) ? self::logo( (string) $card['bank_slug'] ) : '',
			'digits'  => (string) $card['number'],
			'number'  => (string) $card['number_fmt'],
			'holder'  => (string) $card['holder'],
			'sheba'   => (string) $card['sheba_fmt'],
			'sheba_r' => (string) $card['sheba'],
			'account' => (string) $card['account'],
		);
	}

	/**
	 * $args of the payment page.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string, mixed>
	 */
	public static function args( \WC_Order $order ): array {
		$state    = Orders::page_state( $order );
		$deadline = Orders::deadline( $order );
		$tokens   = Orders::text_tokens( $order );
		$texts    = array(
			'pay'       => 'text_pay',
			'review'    => 'text_review',
			'paid'      => 'text_paid',
			'cancelled' => 'text_expired',
		);
		$receipts = Orders::receipts( $order );
		$last     = $receipts ? end( $receipts ) : array();
		$history  = array();
		foreach ( array_reverse( $receipts ) as $r ) {
			$history[] = array(
				'date'   => Util::jdate( 'j F، H:i', (int) $r['time'] ),
				'state'  => (string) $r['state'],
				'reason' => (string) ( $r['reason'] ?? '' ),
				'ref'    => Persian::digits( (string) ( $r['ref'] ?? '' ) ),
				'files'  => count( Receipts::files( $r ) ),
			);
		}
		$rejected = 'pay' === $state && $last && 'rejected' === ( $last['state'] ?? '' );
		$items    = array();
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product ) {
				$items[] = array(
					'name' => $item->get_name(),
					'qty'  => Persian::digits( (string) $item->get_quantity() ),
				);
			}
		}
		$user = get_current_user_id();
		$text = (string) Settings::get( $texts[ $state ] );
		if ( 'cancelled' === $state && ! $order->get_meta( '_ctc_expired' ) ) {
			// Cancelled by the customer or the shop, not by the deadline: the «deadline passed» text would be wrong.
			$text = __( 'این سفارش لغو شده است. اگر مبلغی واریز کرده‌اید، با پشتیبانی تماس بگیرید.', 'card-to-card' );
		}
		$args = array(
			'state'    => $state,
			'code'     => (string) $order->get_order_number(),
			'title'    => Util::tokens( (string) Settings::get( 'page_title' ), $tokens ),
			'text'     => Util::tokens( $text, $tokens ),
			'steps'    => 'pay' === $state ? array_values( array_filter( array_map( 'trim', explode( "\n", (string) Settings::get( 'steps' ) ) ) ) ) : array(),
			'rejected' => $rejected ? Util::tokens( (string) Settings::get( 'text_reject' ), $tokens ) : '',
			'reason'   => $rejected ? (string) ( $last['reason'] ?? '' ) : '',
			'cards'    => 'pay' === $state ? array_map( array( __CLASS__, 'card_view' ), Orders::cards( $order ) ) : array(),
			'amount'   => self::amount( $order ),
			'deadline' => 'pay' === $state && $deadline ? array(
				'label' => Util::jdate( 'j F، ساعت H:i', $deadline ),
				'left'  => max( 0, $deadline - time() ),
			) : array(),
			'form'     => 'pay' === $state && $order->has_status( Orders::AWAITING ) ? array(
				'action'   => Orders::url( $order ),
				'nonce'    => Orders::form_token( $order ),
				'accept'   => Receipts::accept(),
				'max_mb'   => self::max_mb(),
				'max_size' => self::max_bytes(),
				'files'    => self::max_files( $order ),
				'pdf'      => (bool) Settings::get( 'pdf' ),
				'ref'      => (string) Settings::get( 'ref', 'optional' ),
				'last4'    => (string) Settings::get( 'last4', 'optional' ),
				'labels'   => array(
					'ref'   => (string) Settings::get( 'ref_label' ),
					'last4' => (string) Settings::get( 'last4_label' ),
					'file'  => (string) Settings::get( 'file_label' ),
				),
				'fields'   => array_map(
					static fn( array $f ): array => $f + array( 'choices' => self::options( $f ) ),
					(array) Settings::get( 'fields', array() )
				),
				'errors'   => self::$form['errors'],
				'old'      => self::$form['old'],
			) : array(),
			'history'  => $history,
			'items'    => $items,
			'view'     => $user && $user === (int) $order->get_customer_id() ? $order->get_view_order_url() : '',
			'shop'     => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' ),
			'support'  => Settings::get( 'support_on' ) ? Util::tokens( (string) Settings::get( 'support' ), $tokens ) : '',
			'chat'     => Settings::get( 'support_on' ) ? (string) Settings::get( 'support_url' ) : '',
			'scheme'   => (string) Settings::get( 'scheme', 'light' ),
			'tilt'     => (bool) Settings::get( 'tilt' ),
		);
		/**
		 * Payment page args (themes can add or change anything).
		 *
		 * @param array<string, mixed> $args  Args.
		 * @param \WC_Order            $order Order.
		 */
		return (array) apply_filters( 'ctc_page_args', $args, $order );
	}

	/**
	 * The compact box on the thank-you and view-order pages: card + «ارسال رسید» button, or the
	 * receipt state.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function render_box( \WC_Order $order ): void {
		if ( ! Orders::is_ours( $order ) ) {
			return;
		}
		$state = Orders::page_state( $order );
		$args  = array(
			'state'   => $state,
			'cards'   => 'pay' === $state ? array_map( array( __CLASS__, 'card_view' ), Orders::cards( $order ) ) : array(),
			'amount'  => self::amount( $order ),
			'url'     => Settings::get( 'pro' ) ? Orders::url( $order ) : '',
			'receipt' => Orders::receipt_url( $order ),
			'tilt'    => (bool) Settings::get( 'tilt' ),
			'scheme'  => (string) Settings::get( 'scheme', 'light' ),
			'history' => array_map(
				static fn( array $r ): array => array(
					'date'   => Util::jdate( 'j F، H:i', (int) $r['time'] ),
					'state'  => (string) $r['state'],
					'reason' => (string) ( $r['reason'] ?? '' ),
				),
				array_reverse( Orders::receipts( $order ) )
			),
		);
		self::assets( true );
		self::part( 'box.php', (array) apply_filters( 'ctc_box_args', $args, $order ) );
	}

	/**
	 * The amount block of the page and the box: digits, bare digits to copy, unit, words and, for
	 * shops that price in toman, the same amount in rial (`rial` is empty otherwise).
	 *
	 * Many banking apps ask for the amount in rial; a toman figure typed there pays a tenth of the
	 * order and the receipt gets rejected.
	 *
	 * @param \WC_Order $order Order.
	 * @return array{value: string, raw: string, unit: string, words: string, rial: array{value?: string, raw?: string}}
	 */
	public static function amount( \WC_Order $order ): array {
		$total = (float) $order->get_total();
		$rate  = array(
			'IRT'  => 10,
			'IRHT' => 10000,
		)[ get_woocommerce_currency() ] ?? 0;
		$rial  = (int) round( $total * $rate );
		return array(
			'value' => Util::amount( $total ),
			'raw'   => (string) (int) round( $total ),
			'unit'  => Util::unit(),
			'words' => Util::amount_words( $total ),
			'rial'  => $rial > 0 ? array(
				'value' => Persian::digits( number_format( $rial ) ),
				'raw'   => (string) $rial,
			) : array(),
		);
	}

	/**
	 * Copy button markup.
	 *
	 * @param string $value Value copied.
	 * @param string $label Accessible label.
	 * @param string $extra Extra class.
	 * @return string
	 */
	public static function copy_button( string $value, string $label, string $extra = '' ): string {
		return '<button type="button" class="ctc-copy ' . esc_attr( $extra ) . '" data-copy="' . esc_attr( $value ) . '" aria-label="' . esc_attr( $label ) . '">' . Util::icon( 'copy' ) . '<span data-copy-label>' . esc_html__( 'کپی', 'card-to-card' ) . '</span></button>';
	}

	/**
	 * Choices of a select field (one per line).
	 *
	 * @param array<string, mixed> $field Field.
	 * @return string[]
	 */
	public static function options( array $field ): array {
		return array_values( array_filter( array_map( 'trim', explode( "\n", (string) ( $field['options'] ?? '' ) ) ), static fn( string $o ): bool => '' !== $o ) );
	}

	/**
	 * Validation helper for templates: masked card.
	 *
	 * @param string $card Card.
	 * @return string
	 */
	public static function mask( string $card ): string {
		return Banks::mask_card( $card );
	}
}
