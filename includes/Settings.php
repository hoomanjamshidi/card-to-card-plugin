<?php
/**
 * Plugin settings: one autoloaded option (`ctc_settings`), defaults, typed reads and sanitising.
 * The React panel reads/writes the whole array through REST (Rest\Settings_Controller); every value
 * is sanitised here against the defaults' types, so nothing unknown is ever stored.
 *
 * @package CardToCard
 */

namespace CardToCard;

use CardToCard\Support\Banks;
use CardToCard\Support\Persian;

defined( 'ABSPATH' ) || exit;

/**
 * Settings store.
 */
final class Settings {

	public const OPTION = 'ctc_settings';

	/**
	 * Cached merged settings.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $cache = null;

	/**
	 * Default values (also the schema: types come from here).
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			// Gateway.
			'title'             => __( 'کارت به کارت', 'card-to-card' ),
			'description'       => __( 'مبلغ را به کارت فروشگاه واریز کنید و تصویر رسید را بفرستید. اطلاعات کارت بعد از ثبت سفارش نمایش داده می‌شود.', 'card-to-card' ),
			'button'            => __( 'ثبت سفارش و دریافت شماره کارت', 'card-to-card' ),
			'min_total'         => 0,
			'max_total'         => 0,
			// Cards.
			'cards'             => array(),
			'card_mode'         => 'all', // all | random | rotate.
			// Flow.
			'pro'               => true,
			'redirect'          => true,
			'strict'            => true,
			'deadline'          => 24,
			'remind'            => 6,
			'max_mb'            => 5,
			'pdf'               => true,
			'ref'               => 'optional', // hidden | optional | required.
			'ref_label'         => __( 'شماره پیگیری واریز', 'card-to-card' ),
			'last4'             => 'optional', // hidden | optional | required.
			'last4_label'       => __( '۴ رقم آخر کارت شما', 'card-to-card' ),
			'file_label'        => __( 'تصویر رسید را انتخاب کنید یا اینجا رها کنید', 'card-to-card' ),
			'fields'            => array(), // Extra receipt form fields (see sanitize_fields()).
			'slug'              => 'card-pay',
			// Orders and My Account.
			'paid_status'       => 'auto', // auto (WooCommerce decides) | processing | completed.
			'expire_status'     => 'cancelled', // cancelled | failed.
			'hold_stock'        => true,
			'customer_cancel'   => true,
			'customer_note'     => true,
			'account_action'    => true,
			'account_box'       => true,
			'thankyou_box'      => true,
			// Receipt files: days to keep (0 = forever).
			'keep_approved'     => 0,
			'keep_rejected'     => 30,
			'keep_cancelled'    => 30,
			'delete_with_order' => true,
			// Texts; tokens: first_name, order, deadline, total, shop.
			'page_title'        => __( '{first_name} عزیز، سفارشتان ثبت شد', 'card-to-card' ),
			'text_pay'          => __( 'مبلغ سفارش را به کارت زیر واریز کنید و تصویر رسید را تا {deadline} همین‌جا بفرستید. بعد از تأیید رسید، سفارشتان آماده‌سازی می‌شود.', 'card-to-card' ),
			'steps'             => __( "مبلغ را دقیقاً به همین کارت واریز کنید.\nاز رسید بانک یا اپلیکیشن عکس بگیرید.\nرسید را در فرم پایین بفرستید؛ نتیجه بررسی پیامک می‌شود.", 'card-to-card' ),
			'text_review'       => __( 'رسید شما رسید. معمولاً ظرف چند ساعت کاری بررسی می‌شود و نتیجه برایتان پیامک می‌شود.', 'card-to-card' ),
			'text_reject'       => __( 'رسید قبلی تأیید نشد. لطفاً رسید درست را دوباره بفرستید یا با پشتیبانی در تماس باشید.', 'card-to-card' ),
			'text_paid'         => __( 'واریز شما تأیید شد و سفارشتان در حال آماده‌سازی است.', 'card-to-card' ),
			'text_expired'      => __( 'مهلت ارسال رسید تمام شد و سفارش لغو شد. اگر واریز کرده‌اید، با پشتیبانی تماس بگیرید.', 'card-to-card' ),
			'support'           => '',
			'support_url'       => '',
			// Look.
			'layout'            => 'theme', // theme | standalone.
			'scheme'            => 'light', // light | dark | auto.
			'accent'            => '#4f46e5',
			'font'              => 'vazirmatn', // vazirmatn | theme.
			'tilt'              => true,
			'logo'              => true,
			// SMS.
			'sms_driver'        => 'off', // off | log | melipayamak.
			'mp_mode'           => 'pattern', // pattern | line.
			'mp_user'           => '',
			'mp_pass'           => '',
			'mp_from'           => '',
			'sms_admins'        => '',
			'sms_log'           => true,
			'sms_events'        => array(),
			// Advanced.
			'cleanup'           => false,
		);
	}

	/**
	 * All settings, merged over defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		}
		return self::$cache;
	}

	/**
	 * One setting.
	 *
	 * @param string $key      Key.
	 * @param mixed  $fallback Value when the key is unknown.
	 * @return mixed
	 */
	public static function get( string $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Save (sanitised) settings and drop the cache.
	 *
	 * @param array<string, mixed> $input Raw values.
	 * @return array<string, mixed> Stored values.
	 */
	public static function save( array $input ): array {
		$clean = self::sanitize( array_merge( self::all(), $input ) );
		update_option( self::OPTION, $clean, true );
		self::$cache = null;
		/**
		 * Settings saved.
		 *
		 * @param array<string, mixed> $clean Stored values.
		 */
		do_action( 'ctc_settings_saved', $clean );
		return self::all();
	}

	/**
	 * Forget the cache (tests, option changed elsewhere).
	 */
	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * Sanitise a full settings array against the defaults.
	 *
	 * @param array<string, mixed> $input Values.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input ): array {
		$defaults  = self::defaults();
		$out       = array();
		$choices   = array(
			'card_mode'     => array( 'all', 'random', 'rotate' ),
			'ref'           => array( 'hidden', 'optional', 'required' ),
			'last4'         => array( 'hidden', 'optional', 'required' ),
			'layout'        => array( 'theme', 'standalone' ),
			'scheme'        => array( 'light', 'dark', 'auto' ),
			'font'          => array( 'vazirmatn', 'theme' ),
			'sms_driver'    => array( 'off', 'log', 'melipayamak' ),
			'mp_mode'       => array( 'pattern', 'line' ),
			'paid_status'   => array( 'auto', 'processing', 'completed' ),
			'expire_status' => array( 'cancelled', 'failed' ),
		);
		$ranges    = array(
			'deadline'       => array( 0, 720 ),
			'remind'         => array( 0, 240 ),
			'max_mb'         => array( 1, 20 ),
			'min_total'      => array( 0, PHP_INT_MAX ),
			'max_total'      => array( 0, PHP_INT_MAX ),
			'keep_approved'  => array( 0, 3650 ),
			'keep_rejected'  => array( 0, 3650 ),
			'keep_cancelled' => array( 0, 3650 ),
		);
		$multiline = array( 'description', 'text_pay', 'steps', 'text_review', 'text_reject', 'text_paid', 'text_expired', 'support' );

		foreach ( $defaults as $key => $default ) {
			$value = $input[ $key ] ?? $default;
			if ( 'cards' === $key ) {
				$out[ $key ] = self::sanitize_cards( is_array( $value ) ? $value : array() );
			} elseif ( 'fields' === $key ) {
				$out[ $key ] = self::sanitize_fields( is_array( $value ) ? $value : array() );
			} elseif ( 'sms_events' === $key ) {
				$out[ $key ] = self::sanitize_events( is_array( $value ) ? $value : array() );
			} elseif ( isset( $choices[ $key ] ) ) {
				$value       = (string) $value;
				$out[ $key ] = in_array( $value, $choices[ $key ], true ) ? $value : $default;
			} elseif ( is_bool( $default ) ) {
				$out[ $key ] = filter_var( $value, FILTER_VALIDATE_BOOLEAN );
			} elseif ( is_int( $default ) ) {
				$num         = (int) Persian::latin( (string) $value );
				$range       = $ranges[ $key ] ?? array( 0, PHP_INT_MAX );
				$out[ $key ] = max( $range[0], min( $range[1], $num ) );
			} elseif ( 'accent' === $key ) {
				$out[ $key ] = sanitize_hex_color( (string) $value ) ?? $default;
			} elseif ( 'slug' === $key ) {
				$slug        = sanitize_title( (string) $value );
				$out[ $key ] = '' !== $slug ? $slug : $default;
			} elseif ( 'support_url' === $key ) {
				$out[ $key ] = esc_url_raw( (string) $value );
			} elseif ( 'sms_admins' === $key ) {
				$numbers     = array_filter( array_map( array( Persian::class, 'mobile' ), (array) preg_split( '/[,،;\n]+/u', (string) $value ) ) );
				$out[ $key ] = implode( ', ', array_unique( $numbers ) );
			} elseif ( in_array( $key, array( 'mp_user', 'mp_pass', 'mp_from' ), true ) ) {
				$out[ $key ] = trim( Persian::latin( sanitize_text_field( (string) $value ) ) );
			} elseif ( in_array( $key, $multiline, true ) ) {
				$out[ $key ] = sanitize_textarea_field( (string) $value );
			} else {
				$out[ $key ] = sanitize_text_field( (string) $value );
			}
		}
		return $out;
	}

	/**
	 * Destination cards: digits only, IBAN normalised, bank detected when not chosen.
	 *
	 * @param array<int, mixed> $cards Raw cards.
	 * @return array<int, array<string, mixed>>
	 */
	public static function sanitize_cards( array $cards ): array {
		$out = array();
		foreach ( array_slice( $cards, 0, 20 ) as $card ) {
			if ( ! is_array( $card ) ) {
				continue;
			}
			$number = substr( Persian::only_digits( (string) ( $card['number'] ?? '' ) ), 0, 16 );
			$sheba  = Banks::sheba( (string) ( $card['sheba'] ?? '' ) );
			if ( '' === $number && '' === $sheba ) {
				continue;
			}
			$bank  = sanitize_key( (string) ( $card['bank'] ?? '' ) );
			$skin  = in_array( $card['skin'] ?? '', array( 'bank', 'custom', 'glass', 'mono' ), true ) ? $card['skin'] : 'bank';
			$id    = sanitize_key( (string) ( $card['id'] ?? '' ) );
			$out[] = array(
				'id'      => '' !== $id ? $id : 'c' . substr( md5( $number . $sheba . wp_rand() ), 0, 8 ),
				'number'  => $number,
				'holder'  => sanitize_text_field( (string) ( $card['holder'] ?? '' ) ),
				'sheba'   => $sheba,
				'account' => substr( preg_replace( '/[^\d\-.]/', '', Persian::latin( (string) ( $card['account'] ?? '' ) ) ), 0, 30 ),
				'bank'    => isset( Banks::all()[ $bank ] ) ? $bank : '',
				'skin'    => $skin,
				'color1'  => sanitize_hex_color( (string) ( $card['color1'] ?? '' ) ) ?? '#4f46e5',
				'color2'  => sanitize_hex_color( (string) ( $card['color2'] ?? '' ) ) ?? '#0ea5e9',
				'active'  => ! isset( $card['active'] ) || filter_var( $card['active'], FILTER_VALIDATE_BOOLEAN ),
			);
		}
		return $out;
	}

	/**
	 * Extra receipt form fields: id, label, type, required, placeholder, options (select), ltr.
	 *
	 * @param array<int, mixed> $fields Raw fields.
	 * @return array<int, array{id:string, label:string, type:string, required:bool, placeholder:string, options:string, ltr:bool}>
	 */
	public static function sanitize_fields( array $fields ): array {
		$out   = array();
		$types = array( 'text', 'number', 'date', 'time', 'textarea', 'select', 'tel' );
		foreach ( array_slice( $fields, 0, 12 ) as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$label = sanitize_text_field( (string) ( $field['label'] ?? '' ) );
			if ( '' === $label ) {
				continue;
			}
			$id    = sanitize_key( (string) ( $field['id'] ?? '' ) );
			$out[] = array(
				'id'          => '' !== $id ? $id : 'f' . substr( md5( $label . wp_rand() ), 0, 6 ),
				'label'       => $label,
				'type'        => in_array( $field['type'] ?? '', $types, true ) ? (string) $field['type'] : 'text',
				'required'    => filter_var( $field['required'] ?? false, FILTER_VALIDATE_BOOLEAN ),
				'placeholder' => sanitize_text_field( (string) ( $field['placeholder'] ?? '' ) ),
				'options'     => sanitize_textarea_field( (string) ( $field['options'] ?? '' ) ),
				'ltr'         => filter_var( $field['ltr'] ?? false, FILTER_VALIDATE_BOOLEAN ),
			);
		}
		return $out;
	}

	/**
	 * SMS events: on/off, pattern code, variable order and full text (line mode).
	 *
	 * @param array<string, mixed> $events Raw events.
	 * @return array<string, array{on:bool, body:string, vars:string, text:string}>
	 */
	public static function sanitize_events( array $events ): array {
		$out = array();
		foreach ( $events as $key => $conf ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || ! is_array( $conf ) ) {
				continue;
			}
			$out[ $key ] = array(
				'on'   => filter_var( $conf['on'] ?? false, FILTER_VALIDATE_BOOLEAN ),
				'body' => Persian::only_digits( (string) ( $conf['body'] ?? '' ) ),
				'vars' => trim( (string) preg_replace( '/[^a-z0-9_{}\s]/', '', strtolower( (string) ( $conf['vars'] ?? '' ) ) ) ),
				'text' => sanitize_textarea_field( (string) ( $conf['text'] ?? '' ) ),
			);
		}
		return $out;
	}

	/**
	 * Active destination cards, each with its bank resolved.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function cards(): array {
		$cards = array();
		foreach ( (array) self::get( 'cards', array() ) as $card ) {
			if ( empty( $card['active'] ) ) {
				continue;
			}
			$cards[] = self::resolve_card( $card );
		}
		return $cards;
	}

	/**
	 * Fill a stored card with display data (bank, formatted numbers, validity).
	 *
	 * @param array<string, mixed> $card Stored card.
	 * @return array<string, mixed>
	 */
	public static function resolve_card( array $card ): array {
		$number = (string) ( $card['number'] ?? '' );
		$sheba  = (string) ( $card['sheba'] ?? '' );
		$slug   = '' !== (string) ( $card['bank'] ?? '' ) ? (string) $card['bank'] : Banks::detect( $number, $sheba );
		$bank   = Banks::get( $slug );
		return array_merge(
			$card,
			array(
				'bank_slug'  => $slug,
				'bank_name'  => '' !== $bank['name'] ? sprintf( /* translators: %s: bank name */ __( 'بانک %s', 'card-to-card' ), $bank['name'] ) : '',
				'palette'    => $bank,
				'number_fmt' => Banks::format_card( $number ),
				'sheba_fmt'  => Banks::format_sheba( $sheba ),
				'card_ok'    => '' === $number || Banks::card_valid( $number ),
				'sheba_ok'   => '' === $sheba || Banks::sheba_valid( $sheba ),
			)
		);
	}
}
