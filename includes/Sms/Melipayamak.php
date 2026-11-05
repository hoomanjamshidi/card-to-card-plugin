<?php
/**
 * Melipayamak (ملی‌پیامک) REST client, two sending modes:
 *  - pattern: «SendByBaseNumber» (BaseServiceNumber) — an approved pattern (bodyId) plus its
 *    variables in order, on the shared service line; https://www.melipayamak.com/api/sendbybasenumber/
 *  - line: «SendSMS» — a full text from your own (dedicated or shared) sender number;
 *    https://www.melipayamak.com/api/sendsimplesms2/
 * Plus GetCredit for the connection test.
 *
 * @package CardToCard
 */

namespace CardToCard\Sms;

defined( 'ABSPATH' ) || exit;

/**
 * Melipayamak API.
 */
final class Melipayamak {

	public const REST = 'https://rest.payamak-panel.com/api/SendSMS/';

	/**
	 * Username.
	 *
	 * @var string
	 */
	private string $user;

	/**
	 * Password or API key.
	 *
	 * @var string
	 */
	private string $pass;

	/**
	 * Client.
	 *
	 * @param string $user Username.
	 * @param string $pass Password / API key.
	 */
	public function __construct( string $user, string $pass ) {
		$this->user = trim( $user );
		$this->pass = $pass;
	}

	/**
	 * Call a REST method (one retry on a network error, never on an API answer).
	 *
	 * @param string               $method Method (BaseServiceNumber, SendSMS, GetCredit).
	 * @param array<string, mixed> $body   Fields (credentials are added).
	 * @return array{Value:string, RetStatus:int, StrRetStatus:string}|\WP_Error
	 */
	public function call( string $method, array $body ) {
		if ( '' === $this->user || '' === $this->pass ) {
			return new \WP_Error( 'ctc_sms_config', __( 'نام کاربری یا رمز/کلید API ملی‌پیامک وارد نشده است.', 'card-to-card' ) );
		}
		$args     = array(
			'timeout' => 12,
			'body'    => array_merge(
				array(
					'username' => $this->user,
					'password' => $this->pass,
				),
				$body
			),
		);
		$response = wp_remote_post( self::REST . $method, $args );
		if ( is_wp_error( $response ) ) {
			$response = wp_remote_post( self::REST . $method, $args );
		}
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'ctc_sms_http', __( 'اتصال به ملی‌پیامک برقرار نشد:', 'card-to-card' ) . ' ' . $response->get_error_message() );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			/* translators: %s: HTTP status code */
			return new \WP_Error( 'ctc_sms_http', sprintf( __( 'پاسخ نامعتبر از ملی‌پیامک (HTTP %s).', 'card-to-card' ), (string) wp_remote_retrieve_response_code( $response ) ) );
		}
		return array(
			'Value'        => trim( (string) ( $data['Value'] ?? '' ) ),
			'RetStatus'    => (int) ( $data['RetStatus'] ?? 0 ),
			'StrRetStatus' => (string) ( $data['StrRetStatus'] ?? '' ),
		);
	}

	/**
	 * Pattern send on the shared service line.
	 *
	 * @param string             $to      Mobile (09…).
	 * @param string             $body_id Pattern code.
	 * @param array<int, string> $args    Variables {0}, {1} … in order.
	 * @return string|\WP_Error Message id.
	 */
	public function pattern( string $to, string $body_id, array $args ) {
		if ( ! ctype_digit( $body_id ) ) {
			return new \WP_Error( 'ctc_sms_config', __( 'کد الگو (bodyId) برای این پیامک وارد نشده است.', 'card-to-card' ) );
		}
		$args = array_map( array( __CLASS__, 'clean_arg' ), $args );
		return self::result(
			$this->call(
				'BaseServiceNumber',
				array(
					'text'   => implode( ';', $args ),
					'to'     => $to,
					'bodyId' => $body_id,
				)
			)
		);
	}

	/**
	 * Plain text from a sender number (dedicated or shared line).
	 *
	 * @param string $to   Mobile (09…).
	 * @param string $from Sender number.
	 * @param string $text Full text.
	 * @return string|\WP_Error Message id.
	 */
	public function line( string $to, string $from, string $text ) {
		if ( '' === trim( $from ) ) {
			return new \WP_Error( 'ctc_sms_config', __( 'شماره خط فرستنده وارد نشده است.', 'card-to-card' ) );
		}
		if ( '' === trim( $text ) ) {
			return new \WP_Error( 'ctc_sms_config', __( 'متن این پیامک خالی است.', 'card-to-card' ) );
		}
		return self::result(
			$this->call(
				'SendSMS',
				array(
					'to'      => $to,
					'from'    => trim( $from ),
					'text'    => $text,
					'isFlash' => 'false',
				)
			)
		);
	}

	/**
	 * Remaining credit.
	 *
	 * @return float|\WP_Error
	 */
	public function credit() {
		$data = $this->call( 'GetCredit', array() );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( 1 !== $data['RetStatus'] || ! is_numeric( $data['Value'] ) ) {
			return new \WP_Error( 'ctc_sms_credit', self::error( $data['Value'], $data['StrRetStatus'] ) );
		}
		return (float) $data['Value'];
	}

	/**
	 * Send answer → message id or error. Accepted sends return the message id (recId), a long number;
	 * refusals return a short code.
	 *
	 * @param array{Value:string, RetStatus:int, StrRetStatus:string}|\WP_Error $data Answer.
	 * @return string|\WP_Error
	 */
	public static function result( $data ) {
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( ctype_digit( $data['Value'] ) && strlen( $data['Value'] ) > 4 ) {
			return $data['Value'];
		}
		return new \WP_Error( 'ctc_sms_failed', self::error( $data['Value'], $data['StrRetStatus'] ) );
	}

	/**
	 * One pattern variable: plain text without «;» (the REST separator) or line breaks.
	 *
	 * @param mixed $arg Value.
	 * @return string
	 */
	public static function clean_arg( $arg ): string {
		$arg = trim( wp_strip_all_tags( (string) $arg ) );
		return str_replace( array( ';', "\r", "\n" ), array( '،', ' ', ' ' ), $arg );
	}

	/**
	 * Persian text of a Melipayamak return code.
	 *
	 * @param string $code   Value returned instead of a message id.
	 * @param string $status StrRetStatus.
	 * @return string
	 */
	public static function error( string $code, string $status = '' ): string {
		$map  = array(
			'-10' => __( 'در متغیرها لینک وجود دارد؛ الگوهای خدماتی لینک نمی‌پذیرند مگر در خود متن الگو.', 'card-to-card' ),
			'-7'  => __( 'خطا در شماره فرستنده؛ با پشتیبانی ملی‌پیامک تماس بگیرید.', 'card-to-card' ),
			'-6'  => __( 'خطای داخلی ملی‌پیامک؛ با پشتیبانی تماس بگیرید.', 'card-to-card' ),
			'-5'  => __( 'تعداد یا ترتیب متغیرها با الگو نمی‌خواند.', 'card-to-card' ),
			'-4'  => __( 'کد الگو معتبر نیست یا هنوز تأیید نشده است.', 'card-to-card' ),
			'-3'  => __( 'خط ارسال در سامانه تعریف نشده است.', 'card-to-card' ),
			'-2'  => __( 'در هر درخواست فقط یک گیرنده مجاز است.', 'card-to-card' ),
			'-1'  => __( 'دسترسی وب‌سرویس غیرفعال است.', 'card-to-card' ),
			'0'   => __( 'نام کاربری یا رمز عبور نادرست است.', 'card-to-card' ),
			'2'   => __( 'اعتبار پنل کافی نیست.', 'card-to-card' ),
			'3'   => __( 'سقف ارسال روزانه پر شده است.', 'card-to-card' ),
			'4'   => __( 'سقف حجم ارسال پر شده است.', 'card-to-card' ),
			'5'   => __( 'شماره فرستنده معتبر نیست.', 'card-to-card' ),
			'6'   => __( 'سامانه در حال به‌روزرسانی است؛ کمی بعد امتحان کنید.', 'card-to-card' ),
			'7'   => __( 'متن شامل کلمه فیلترشده است.', 'card-to-card' ),
			'9'   => __( 'ارسال از خطوط عمومی از طریق وب‌سرویس امکان‌پذیر نیست.', 'card-to-card' ),
			'10'  => __( 'کاربر غیرفعال است.', 'card-to-card' ),
			'11'  => __( 'پیامک ارسال نشد.', 'card-to-card' ),
			'12'  => __( 'مدارک کاربر کامل نیست.', 'card-to-card' ),
			'14'  => __( 'متن شامل لینک است؛ برای ارسال لینک با پشتیبانی ملی‌پیامک هماهنگ کنید.', 'card-to-card' ),
			'15'  => __( 'برای ارسال به چند گیرنده، عبارت «لغو11» باید در متن باشد.', 'card-to-card' ),
			'16'  => __( 'گیرنده‌ای یافت نشد.', 'card-to-card' ),
			'17'  => __( 'متن پیامک خالی است.', 'card-to-card' ),
			'18'  => __( 'شماره گیرنده نامعتبر است.', 'card-to-card' ),
			'19'  => __( 'سقف ارسال ساعتی پر شده است.', 'card-to-card' ),
			'35'  => __( 'شماره گیرنده در لیست سیاه مخابرات است.', 'card-to-card' ),
			'108' => __( 'IP سرور به‌خاطر تلاش‌های ناموفق مسدود شده است.', 'card-to-card' ),
			'109' => __( 'باید IP مجاز سرور را در پنل ملی‌پیامک ثبت کنید.', 'card-to-card' ),
			'110' => __( 'به‌جای رمز عبور، کلید API (ApiKey) پنل را وارد کنید.', 'card-to-card' ),
		);
		$code = trim( $code );
		if ( isset( $map[ $code ] ) ) {
			/* translators: 1: Persian reason, 2: code */
			return sprintf( __( '%1$s (کد %2$s)', 'card-to-card' ), $map[ $code ], $code );
		}
		return trim( __( 'ارسال پیامک ناموفق بود:', 'card-to-card' ) . ' ' . trim( $status . ' ' . $code ) );
	}
}
