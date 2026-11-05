<?php
/**
 * Facts beside a card: amount (with words), holder, IBAN, account — each with a copy button.
 * Override: copy to yourtheme/card-to-card/facts.php.
 *
 * @package CardToCard
 *
 * @var array<string, mixed> $args { card: card_view(), amount: array }
 */

use CardToCard\Payment_Page;

defined( 'ABSPATH' ) || exit;

$ctc_card   = $args['card'];
$ctc_amount = $args['amount'];
?>
<dl class="ctc-facts">
	<div class="ctc-fact is-amount">
		<dt><?php esc_html_e( 'مبلغ قابل واریز', 'card-to-card' ); ?></dt>
		<dd><b><?php echo esc_html( $ctc_amount['value'] ); ?></b> <small><?php echo esc_html( $ctc_amount['unit'] ); ?></small>
			<span class="ctc-words"><?php echo esc_html( $ctc_amount['words'] ); ?></span></dd>
		<?php echo Payment_Page::copy_button( (string) $ctc_amount['raw'], __( 'کپی مبلغ', 'card-to-card' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>
	<?php if ( '' !== $ctc_card['holder'] ) : ?>
		<div class="ctc-fact">
			<dt><?php esc_html_e( 'صاحب حساب', 'card-to-card' ); ?></dt>
			<dd><?php echo esc_html( $ctc_card['holder'] ); ?></dd>
		</div>
	<?php endif; ?>
	<?php if ( '' !== $ctc_card['sheba'] ) : ?>
		<div class="ctc-fact">
			<dt><?php esc_html_e( 'شماره شبا', 'card-to-card' ); ?></dt>
			<dd dir="ltr" class="ctc-mono"><?php echo esc_html( $ctc_card['sheba'] ); ?></dd>
			<?php echo Payment_Page::copy_button( (string) $ctc_card['sheba_r'], __( 'کپی شماره شبا', 'card-to-card' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
	<?php endif; ?>
	<?php if ( '' !== $ctc_card['account'] ) : ?>
		<div class="ctc-fact">
			<dt><?php esc_html_e( 'شماره حساب', 'card-to-card' ); ?></dt>
			<dd dir="ltr" class="ctc-mono"><?php echo esc_html( $ctc_card['account'] ); ?></dd>
			<?php echo Payment_Page::copy_button( (string) $ctc_card['account'], __( 'کپی شماره حساب', 'card-to-card' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
	<?php endif; ?>
</dl>
