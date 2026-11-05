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
	 * Pretty URL /<slug>/<key>/.
	 */
	public static function rewrite(): void {
		$slug = preg_quote( (string) Settings::get( 'slug', 'card-pay' ), '#' );
		add_rewrite_rule( '^' . $slug . '/(\d+-[a-f0-9]{16})/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
		$version = CTC_VERSION . '|' . Settings::get( 'slug', 'card-pay' );
		if ( get_option( 'ctc_rewrite' ) !== $version ) {
			flush_rewrite_rules( false );
			update_option( 'ctc_rewrite', $version, false );
		}
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
		$order = Util::rate_limit( 'key', 60, HOUR_IN_SECONDS ) ? Orders::from_key( $key ) : null;
		if ( ! $order ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return;
		}
		self::$order = $order;
		$method      = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in handle_post().
		if ( 'POST' === $method && isset( $_POST['ctc_nonce'] ) ) {
			self::handle_post( $order );
		}
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

		if ( ! wp_verify_nonce( $nonce, 'ctc_receipt_' . $order->get_id() ) ) {
			$errors['form'] = __( 'صفحه را دوباره باز کنید و امتحان کنید.', 'card-to-card' );
		} elseif ( '' !== $trap ) {
			$errors['form'] = __( 'ارسال انجام نشد.', 'card-to-card' );
		} elseif ( ! $order->has_status( Orders::AWAITING ) ) {
			$errors['form'] = __( 'این سفارش الان منتظر رسید نیست.', 'card-to-card' );
		} elseif ( ! Util::rate_limit( 'receipt', 5, HOUR_IN_SECONDS, (string) $order->get_id() ) ) {
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
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked in Receipts::store().
			$stored = Receipts::store( isset( $_FILES['ctc_file'] ) && is_array( $_FILES['ctc_file'] ) ? $_FILES['ctc_file'] : array() );
			if ( is_wp_error( $stored ) ) {
				$errors['file'] = $stored->get_error_message();
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
		wp_enqueue_style( 'ctc-payment', CTC_URL . "assets/css/payment{$css}.css", array(), CTC_VERSION );
		wp_add_inline_style( 'ctc-payment', self::inline_css() );
		wp_enqueue_script( 'ctc-payment', CTC_URL . "assets/js/payment{$js}.js", array(), CTC_VERSION, array( 'strategy' => 'defer' ) );
		wp_localize_script(
			'ctc-payment',
			'ctcPay',
			array(
				'copied'  => __( 'کپی شد', 'card-to-card' ),
				'copy'    => __( 'کپی', 'card-to-card' ),
				'pick'    => __( 'اول تصویر رسید را انتخاب کنید.', 'card-to-card' ),
				/* translators: %s: size in MB */
				'tooBig'  => __( 'حجم فایل بیشتر از %s مگابایت است.', 'card-to-card' ),
				'sending' => __( 'در حال ارسال…', 'card-to-card' ),
				/* translators: %s: hours:minutes */
				'left'    => __( '%s مانده', 'card-to-card' ),
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
		$accent = (string) Settings::get( 'accent', '#4f46e5' );
		$css    = '.ctc{--ctc-accent:' . $accent . ';}';
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
			'style'   => sprintf( '--c1:%s;--c2:%s;--c3:%s;--ink:%s;--foil:%s', $colors[0], $colors[1], $colors[2], 'custom' === $skin ? '#ffffff' : $palette['ink'], 'custom' === $skin ? '#ffffff' : $palette['accent'] ),
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
		$args = array(
			'state'    => $state,
			'code'     => (string) $order->get_order_number(),
			'title'    => Util::tokens( (string) Settings::get( 'page_title' ), $tokens ),
			'text'     => Util::tokens( (string) Settings::get( $texts[ $state ] ), $tokens ),
			'steps'    => 'pay' === $state ? array_values( array_filter( array_map( 'trim', explode( "\n", (string) Settings::get( 'steps' ) ) ) ) ) : array(),
			'rejected' => $rejected ? Util::tokens( (string) Settings::get( 'text_reject' ), $tokens ) : '',
			'reason'   => $rejected ? (string) ( $last['reason'] ?? '' ) : '',
			'cards'    => 'pay' === $state ? array_map( array( __CLASS__, 'card_view' ), Orders::cards( $order ) ) : array(),
			'amount'   => array(
				'value' => Util::amount( $order->get_total() ),
				'raw'   => (string) (int) round( (float) $order->get_total() ),
				'unit'  => Util::unit(),
				'words' => Util::amount_words( $order->get_total() ),
			),
			'deadline' => 'pay' === $state && $deadline ? array(
				'label' => Util::jdate( 'j F، ساعت H:i', $deadline ),
				'left'  => max( 0, $deadline - time() ),
			) : array(),
			'form'     => 'pay' === $state && $order->has_status( Orders::AWAITING ) ? array(
				'action'   => Orders::url( $order ),
				'nonce'    => wp_create_nonce( 'ctc_receipt_' . $order->get_id() ),
				'accept'   => Receipts::accept(),
				'max_mb'   => (int) Settings::get( 'max_mb', 5 ),
				'max_size' => max( 1, (int) Settings::get( 'max_mb', 5 ) ) * MB_IN_BYTES,
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
			'support'  => Util::tokens( (string) Settings::get( 'support' ), $tokens ),
			'chat'     => (string) Settings::get( 'support_url' ),
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
			'amount'  => array(
				'value' => Util::amount( $order->get_total() ),
				'raw'   => (string) (int) round( (float) $order->get_total() ),
				'unit'  => Util::unit(),
				'words' => Util::amount_words( $order->get_total() ),
			),
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
