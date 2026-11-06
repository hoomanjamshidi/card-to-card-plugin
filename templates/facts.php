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
use CardToCard\Util;

defined( 'ABSPATH' ) || exit;

$ctc_card   = $args['card'];
$ctc_amount = $args['amount'];
?>
<dl class="ctc-facts">
	<div class="ctc-fact is-amount">
		<dt><?php esc_html_e( 'مبلغ قابل واریز', 'card-to-card' ); ?></dt>
		<dd><b><?php echo esc_html( $ctc_amount['value'] ); ?></b> <small><?php echo esc_html( $ctc_amount['unit'] ); ?></small>
			<span class="ctc-words"><?php echo esc_html( $ctc_amount['words'] ); ?></span>
			<?php if ( ! empty( $ctc_amount['rial']['raw'] ) ) : ?>
				<?php // Many banking apps ask for rial: the same amount, ready to copy. ?>
				<button type="button" class="ctc-rial" data-copy="<?php echo esc_attr( $ctc_amount['rial']['raw'] ); ?>" aria-label="<?php esc_attr_e( 'کپی مبلغ به ریال', 'card-to-card' ); ?>">
					<?php /* translators: %s: amount in rial */ ?>
					<span><?php echo esc_html( sprintf( __( 'به ریال: %s', 'card-to-card' ), $ctc_amount['rial']['value'] ) ); ?></span>
					<?php echo Util::icon( 'copy' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- bundled SVG. ?><i data-copy-label><?php esc_html_e( 'کپی', 'card-to-card' ); ?></i>
				</button>
			<?php endif; ?>
		</dd>
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
