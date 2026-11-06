<?php
/**
 * Payment page body: state header, rejected-receipt alert, the card(s) with amount and deadline,
 * the receipt form, and the order summary / history column.
 * Override: copy to yourtheme/card-to-card/payment-page.php.
 *
 * @package CardToCard
 *
 * @var array<string, mixed> $args See Payment_Page::args().
 */

use CardToCard\Payment_Page;
use CardToCard\Support\Persian;
use CardToCard\Util;

defined( 'ABSPATH' ) || exit;

if ( empty( $args['code'] ) ) {
	return;
}
$ctc_state = (string) $args['state'];
$ctc_kick  = array(
	'pay'       => __( 'در انتظار واریز', 'card-to-card' ),
	'review'    => __( 'رسید در حال بررسی', 'card-to-card' ),
	'paid'      => __( 'پرداخت تأیید شد', 'card-to-card' ),
	'cancelled' => __( 'سفارش لغو شد', 'card-to-card' ),
);
$ctc_icon  = array(
	'pay'       => 'card',
	'review'    => 'receipt',
	'paid'      => 'tick',
	'cancelled' => 'x',
);
$ctc_hist  = array(
	'pending'  => __( 'در انتظار بررسی', 'card-to-card' ),
	'approved' => __( 'تأیید شد', 'card-to-card' ),
	'rejected' => __( 'رد شد', 'card-to-card' ),
);
$ctc_form  = $args['form'];
$ctc_err   = ! empty( $ctc_form ) ? $ctc_form['errors'] : array();
$ctc_cards = (array) $args['cards'];
$ctc_solo  = ! $ctc_cards && ! $ctc_form;
?>
<div class="ctc ctc-page is-<?php echo esc_attr( $ctc_state ); ?>" data-scheme="<?php echo esc_attr( $args['scheme'] ); ?>" dir="rtl">
	<ol class="ctc-steps-bar" aria-label="<?php esc_attr_e( 'مراحل پرداخت', 'card-to-card' ); ?>">
		<li class="is-done"><span>۱</span><?php esc_html_e( 'ثبت سفارش', 'card-to-card' ); ?></li>
		<li class="<?php echo 'pay' === $ctc_state ? 'is-now' : 'is-done'; ?>"><span>۲</span><?php esc_html_e( 'واریز و ارسال رسید', 'card-to-card' ); ?></li>
		<li class="<?php echo 'review' === $ctc_state ? 'is-now' : ( 'paid' === $ctc_state ? 'is-done' : '' ); ?>"><span>۳</span><?php esc_html_e( 'بررسی رسید', 'card-to-card' ); ?></li>
		<li class="<?php echo 'paid' === $ctc_state ? 'is-done' : ''; ?>"><span>۴</span><?php esc_html_e( 'تأیید و ارسال', 'card-to-card' ); ?></li>
	</ol>

	<section class="ctc-hero" id="ctc-state" aria-labelledby="ctc-title">
		<span class="ctc-hero-ic" aria-hidden="true"><?php echo Util::icon( $ctc_icon[ $ctc_state ] ?? 'card' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
		<div class="ctc-hero-tx">
			<p class="ctc-kicker"><?php echo esc_html( $ctc_kick[ $ctc_state ] ?? '' ); ?></p>
			<h1 id="ctc-title"><?php echo esc_html( $args['title'] ); ?></h1>
			<?php if ( '' !== $args['text'] ) : ?>
				<p class="ctc-lead"><?php echo esc_html( $args['text'] ); ?></p>
			<?php endif; ?>
		</div>
		<div class="ctc-code">
			<span><?php esc_html_e( 'شماره سفارش', 'card-to-card' ); ?></span>
			<b dir="ltr"><?php echo esc_html( Persian::digits( $args['code'] ) ); ?></b>
		</div>
		<?php if ( $ctc_form ) : ?>
			<?php // Back from the bank app or the SMS link: straight to the form, past the card and the steps. ?>
			<a class="ctc-btn ctc-hero-cta" href="#ctc-form" data-ctc-jump><?php echo Util::icon( 'upload' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php esc_html_e( 'واریز کرده‌ام؛ ارسال رسید', 'card-to-card' ); ?></span></a>
		<?php endif; ?>
	</section>

	<?php if ( '' !== $args['rejected'] ) : ?>
		<div class="ctc-alert" role="alert">
			<?php echo Util::icon( 'alert' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<p><b><?php echo esc_html( $args['rejected'] ); ?></b>
				<?php if ( '' !== $args['reason'] ) : ?>
					<?php /* translators: %s: rejection reason */ ?>
					<span><?php echo esc_html( sprintf( __( 'دلیل: %s', 'card-to-card' ), $args['reason'] ) ); ?></span>
				<?php endif; ?>
			</p>
		</div>
	<?php endif; ?>

	<div class="ctc-grid<?php echo $ctc_solo ? ' is-solo' : ''; ?>">
		<?php if ( ! $ctc_solo ) : ?>
		<div class="ctc-main">
			<?php if ( $ctc_cards ) : ?>
				<section class="ctc-panel ctc-pay" aria-labelledby="ctc-card-t" data-ctc-cards>
					<header class="ctc-panel-h">
						<h2 id="ctc-card-t"><?php echo Util::icon( 'card' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo esc_html( count( $ctc_cards ) > 1 ? __( 'به یکی از این کارت‌ها واریز کنید', 'card-to-card' ) : __( 'واریز به این کارت', 'card-to-card' ) ); ?></h2>
						<?php if ( count( $ctc_cards ) > 1 ) : ?>
							<div class="ctc-switch" role="tablist" aria-label="<?php esc_attr_e( 'کارت‌ها', 'card-to-card' ); ?>">
								<?php foreach ( $ctc_cards as $ctc_i => $ctc_card ) : ?>
									<button type="button" role="tab" id="ctc-tab-<?php echo esc_attr( $ctc_card['id'] ); ?>" aria-controls="ctc-card-<?php echo esc_attr( $ctc_card['id'] ); ?>" aria-selected="<?php echo 0 === $ctc_i ? 'true' : 'false'; ?>" style="<?php echo esc_attr( $ctc_card['style'] ); ?>">
										<i aria-hidden="true"></i><?php echo esc_html( '' !== $ctc_card['bank'] ? $ctc_card['bank'] : Payment_Page::mask( $ctc_card['digits'] ) ); ?>
									</button>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</header>
					<?php foreach ( $ctc_cards as $ctc_i => $ctc_card ) : ?>
						<div class="ctc-cardrow" id="ctc-card-<?php echo esc_attr( $ctc_card['id'] ); ?>"<?php echo count( $ctc_cards ) > 1 ? ' role="tabpanel" aria-labelledby="ctc-tab-' . esc_attr( $ctc_card['id'] ) . '"' : ''; ?><?php echo $ctc_i > 0 ? ' data-ctc-later' : ''; ?>>
							<?php Payment_Page::part( 'card.php', $ctc_card + array( 'tilt' => $args['tilt'] ) ); ?>
							<?php
							Payment_Page::part(
								'facts.php',
								array(
									'card'   => $ctc_card,
									'amount' => $args['amount'],
								)
							);
							?>
						</div>
					<?php endforeach; ?>
					<?php if ( ! empty( $args['deadline'] ) ) : ?>
						<p class="ctc-deadline" data-ctc-deadline="<?php echo esc_attr( (string) $args['deadline']['left'] ); ?>">
							<?php echo Util::icon( 'clock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php /* translators: %s: Jalali date and time */ ?>
							<span><?php echo esc_html( sprintf( __( 'مهلت ارسال رسید: %s', 'card-to-card' ), $args['deadline']['label'] ) ); ?></span>
							<b data-ctc-left aria-live="off"></b>
						</p>
					<?php endif; ?>
					<?php if ( $args['steps'] ) : ?>
						<ol class="ctc-howto">
							<?php foreach ( $args['steps'] as $ctc_step ) : ?>
								<li><?php echo esc_html( $ctc_step ); ?></li>
							<?php endforeach; ?>
						</ol>
					<?php endif; ?>
				</section>
			<?php endif; ?>

			<?php if ( $ctc_form ) : ?>
				<form class="ctc-panel ctc-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( $ctc_form['action'] ); ?>#ctc-form" id="ctc-form" data-ctc-form data-max="<?php echo esc_attr( (string) $ctc_form['max_size'] ); ?>" novalidate>
					<header class="ctc-panel-h"><h2><?php echo Util::icon( 'upload' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'ارسال رسید واریز', 'card-to-card' ); ?></h2></header>
					<input type="hidden" name="ctc_nonce" value="<?php echo esc_attr( $ctc_form['nonce'] ); ?>">
					<p class="ctc-hp" aria-hidden="true"><label>Website<input type="text" name="ctc_hp" tabindex="-1" autocomplete="off"></label></p>
					<?php if ( ! empty( $ctc_err['form'] ) ) : ?>
						<p class="ctc-err is-form" role="alert"><?php echo esc_html( $ctc_err['form'] ); ?></p>
					<?php endif; ?>

					<label class="ctc-drop<?php echo ! empty( $ctc_err['file'] ) ? ' is-bad' : ''; ?>">
						<input type="file" name="ctc_file" accept="<?php echo esc_attr( $ctc_form['accept'] ); ?>" required aria-describedby="ctc-file-h<?php echo ! empty( $ctc_err['file'] ) ? ' ctc-file-e' : ''; ?>"<?php echo ! empty( $ctc_err['file'] ) ? ' aria-invalid="true"' : ''; ?>>
						<span class="ctc-drop-ic" aria-hidden="true"><?php echo Util::icon( 'image' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<span class="ctc-drop-tx">
							<b data-ctc-name><?php echo esc_html( $ctc_form['labels']['file'] ); ?></b>
							<span id="ctc-file-h">
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: formats, 2: size in MB */
										__( '%1$s — حداکثر %2$s مگابایت', 'card-to-card' ),
										$ctc_form['pdf'] ? __( 'عکس یا PDF', 'card-to-card' ) : __( 'عکس', 'card-to-card' ),
										Persian::digits( (string) $ctc_form['max_mb'] )
									)
								);
								?>
							</span>
						</span>
						<img class="ctc-preview" alt="" hidden data-ctc-preview>
					</label>
					<?php if ( ! empty( $ctc_err['file'] ) ) : ?>
						<p class="ctc-err" id="ctc-file-e" role="alert"><?php echo esc_html( $ctc_err['file'] ); ?></p>
					<?php endif; ?>

					<?php if ( 'hidden' !== $ctc_form['ref'] || 'hidden' !== $ctc_form['last4'] || $ctc_form['fields'] ) : ?>
					<div class="ctc-fields">
						<?php if ( 'hidden' !== $ctc_form['ref'] ) : ?>
							<label class="ctc-f">
								<span><?php echo esc_html( $ctc_form['labels']['ref'] ); ?><?php echo 'required' === $ctc_form['ref'] ? ' <i class="ctc-req" aria-hidden="true">*</i>' : ' <small>' . esc_html__( '(اختیاری)', 'card-to-card' ) . '</small>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
								<input type="text" name="ctc_ref" dir="ltr" inputmode="numeric" maxlength="40" autocomplete="off" value="<?php echo esc_attr( $ctc_form['old']['ref'] ?? '' ); ?>"<?php echo 'required' === $ctc_form['ref'] ? ' required' : ''; ?><?php echo ! empty( $ctc_err['ref'] ) ? ' aria-invalid="true" aria-describedby="ctc-ref-e"' : ''; ?>>
								<?php if ( ! empty( $ctc_err['ref'] ) ) : ?>
									<em class="ctc-err" id="ctc-ref-e"><?php echo esc_html( $ctc_err['ref'] ); ?></em>
								<?php endif; ?>
							</label>
						<?php endif; ?>
						<?php if ( 'hidden' !== $ctc_form['last4'] ) : ?>
							<label class="ctc-f">
								<span><?php echo esc_html( $ctc_form['labels']['last4'] ); ?><?php echo 'required' === $ctc_form['last4'] ? ' <i class="ctc-req" aria-hidden="true">*</i>' : ' <small>' . esc_html__( '(اختیاری)', 'card-to-card' ) . '</small>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
								<input type="text" name="ctc_last4" dir="ltr" inputmode="numeric" maxlength="4" autocomplete="off" value="<?php echo esc_attr( $ctc_form['old']['last4'] ?? '' ); ?>"<?php echo 'required' === $ctc_form['last4'] ? ' required' : ''; ?><?php echo ! empty( $ctc_err['last4'] ) ? ' aria-invalid="true" aria-describedby="ctc-l4-e"' : ''; ?>>
								<?php if ( ! empty( $ctc_err['last4'] ) ) : ?>
									<em class="ctc-err" id="ctc-l4-e"><?php echo esc_html( $ctc_err['last4'] ); ?></em>
								<?php endif; ?>
							</label>
						<?php endif; ?>
						<?php foreach ( $ctc_form['fields'] as $ctc_field ) : ?>
							<?php
							$ctc_key   = 'f_' . $ctc_field['id'];
							$ctc_val   = (string) ( $ctc_form['old'][ $ctc_key ] ?? '' );
							$ctc_attrs = ' name="ctc_f[' . esc_attr( $ctc_field['id'] ) . ']"' . ( $ctc_field['required'] ? ' required' : '' ) . ( $ctc_field['ltr'] ? ' dir="ltr"' : '' ) . ( ! empty( $ctc_err[ $ctc_key ] ) ? ' aria-invalid="true"' : '' );
							?>
							<label class="ctc-f<?php echo 'textarea' === $ctc_field['type'] ? ' is-wide' : ''; ?>">
								<span><?php echo esc_html( $ctc_field['label'] ); ?><?php echo $ctc_field['required'] ? ' <i class="ctc-req" aria-hidden="true">*</i>' : ' <small>' . esc_html__( '(اختیاری)', 'card-to-card' ) . '</small>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
								<?php if ( 'textarea' === $ctc_field['type'] ) : ?>
									<textarea rows="3" placeholder="<?php echo esc_attr( $ctc_field['placeholder'] ); ?>"<?php echo $ctc_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>><?php echo esc_textarea( $ctc_val ); ?></textarea>
								<?php elseif ( 'select' === $ctc_field['type'] ) : ?>
									<select<?php echo $ctc_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>>
										<option value=""><?php echo esc_html( '' !== $ctc_field['placeholder'] ? $ctc_field['placeholder'] : __( 'انتخاب کنید', 'card-to-card' ) ); ?></option>
										<?php foreach ( $ctc_field['choices'] as $ctc_choice ) : ?>
											<option value="<?php echo esc_attr( $ctc_choice ); ?>"<?php selected( $ctc_val, $ctc_choice ); ?>><?php echo esc_html( $ctc_choice ); ?></option>
										<?php endforeach; ?>
									</select>
								<?php else : ?>
									<input type="<?php echo esc_attr( 'number' === $ctc_field['type'] ? 'text' : $ctc_field['type'] ); ?>"<?php echo in_array( $ctc_field['type'], array( 'number', 'tel' ), true ) ? ' inputmode="numeric"' : ''; ?> autocomplete="off" placeholder="<?php echo esc_attr( $ctc_field['placeholder'] ); ?>" value="<?php echo esc_attr( $ctc_val ); ?>"<?php echo $ctc_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>>
								<?php endif; ?>
								<?php if ( ! empty( $ctc_err[ $ctc_key ] ) ) : ?>
									<em class="ctc-err"><?php echo esc_html( $ctc_err[ $ctc_key ] ); ?></em>
								<?php endif; ?>
							</label>
						<?php endforeach; ?>
					</div>
					<?php endif; ?>
					<button class="ctc-btn ctc-submit" type="submit"><?php echo Util::icon( 'receipt' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php esc_html_e( 'ارسال رسید', 'card-to-card' ); ?></span></button>
				</form>
			<?php endif; ?>
		</div>
		<?php endif; ?>

		<aside class="ctc-side" aria-label="<?php esc_attr_e( 'خلاصه سفارش', 'card-to-card' ); ?>">
			<section class="ctc-panel ctc-sum">
				<h2><?php esc_html_e( 'خلاصه سفارش', 'card-to-card' ); ?></h2>
				<ul>
					<?php foreach ( $args['items'] as $ctc_item ) : ?>
						<li><span><?php echo esc_html( $ctc_item['name'] ); ?></span><b>× <?php echo esc_html( $ctc_item['qty'] ); ?></b></li>
					<?php endforeach; ?>
				</ul>
				<p class="ctc-total"><span><?php esc_html_e( 'مبلغ قابل پرداخت', 'card-to-card' ); ?></span><b><?php echo esc_html( $args['amount']['value'] ); ?> <small><?php echo esc_html( $args['amount']['unit'] ); ?></small></b></p>
			</section>

			<?php if ( $args['history'] ) : ?>
				<section class="ctc-panel ctc-hist">
					<h2><?php esc_html_e( 'رسیدهای ارسالی', 'card-to-card' ); ?></h2>
					<ol>
						<?php foreach ( $args['history'] as $ctc_h ) : ?>
							<li class="is-<?php echo esc_attr( $ctc_h['state'] ); ?>">
								<b><?php echo esc_html( $ctc_hist[ $ctc_h['state'] ] ?? $ctc_h['state'] ); ?></b>
								<span><?php echo esc_html( $ctc_h['date'] ); ?></span>
								<?php if ( '' !== $ctc_h['reason'] ) : ?>
									<em><?php echo esc_html( $ctc_h['reason'] ); ?></em>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ol>
				</section>
			<?php endif; ?>

			<div class="ctc-acts">
				<?php if ( '' !== $args['view'] ) : ?>
					<a class="ctc-btn is-ghost" href="<?php echo esc_url( $args['view'] ); ?>"><?php echo Util::icon( 'eye' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'جزئیات سفارش', 'card-to-card' ); ?></a>
				<?php endif; ?>
				<?php if ( '' !== $args['chat'] ) : ?>
					<a class="ctc-btn is-ghost" href="<?php echo esc_url( $args['chat'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo Util::icon( 'chat' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'گفت‌وگو با پشتیبانی', 'card-to-card' ); ?></a>
				<?php endif; ?>
				<?php if ( in_array( $ctc_state, array( 'paid', 'cancelled' ), true ) && '' !== $args['shop'] ) : ?>
					<a class="ctc-btn is-ghost" href="<?php echo esc_url( $args['shop'] ); ?>"><?php esc_html_e( 'بازگشت به فروشگاه', 'card-to-card' ); ?></a>
				<?php endif; ?>
			</div>
			<?php if ( '' !== $args['support'] ) : ?>
				<p class="ctc-support"><?php echo esc_html( $args['support'] ); ?></p>
			<?php endif; ?>
		</aside>
	</div>
</div>
