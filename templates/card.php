<?php
/**
 * One bank card plate: bank colours and logo, chip, number (4-4-4-4, left to right), holder and a
 * copy button. Copy buttons copy bare digits (what banking apps accept).
 * Override: copy to yourtheme/card-to-card/card.php.
 *
 * @package CardToCard
 *
 * @var array<string, mixed> $args See Payment_Page::card_view() (+ 'tilt').
 */

use CardToCard\Payment_Page;
use CardToCard\Util;

defined( 'ABSPATH' ) || exit;

if ( empty( $args['digits'] ) && empty( $args['sheba'] ) ) {
	return;
}
$ctc_classes = array( 'ctc-plate', 'is-' . $args['skin'], '' !== $args['slug'] ? 'bank-' . $args['slug'] : 'bank-unknown' );
?>
<div class="<?php echo esc_attr( implode( ' ', $ctc_classes ) ); ?>" style="<?php echo esc_attr( $args['style'] ); ?>"<?php echo ! empty( $args['tilt'] ) ? ' data-ctc-tilt' : ''; ?>>
	<span class="ctc-plate-pattern" aria-hidden="true"></span>
	<span class="ctc-plate-glare" aria-hidden="true"></span>
	<div class="ctc-plate-top">
		<?php if ( '' !== $args['logo'] ) : ?>
			<span class="ctc-plate-logo" aria-hidden="true"><?php echo $args['logo']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- bundled SVG asset. ?></span>
		<?php endif; ?>
		<span class="ctc-plate-bank"><?php echo esc_html( '' !== $args['bank'] ? $args['bank'] : __( 'کارت بانکی', 'card-to-card' ) ); ?></span>
		<svg class="ctc-plate-nfc" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M8.5 8.5a5 5 0 0 1 0 7M12 6a8.5 8.5 0 0 1 0 12M15.5 3.5a12 12 0 0 1 0 17"/></svg>
	</div>
	<span class="ctc-chip" aria-hidden="true"><i></i></span>
	<?php if ( '' !== $args['digits'] ) : ?>
		<b class="ctc-plate-no" dir="ltr" aria-label="<?php esc_attr_e( 'شماره کارت', 'card-to-card' ); ?>"><?php echo esc_html( $args['number'] ); ?></b>
	<?php else : ?>
		<b class="ctc-plate-no is-sheba" dir="ltr"><?php echo esc_html( $args['sheba'] ); ?></b>
	<?php endif; ?>
	<div class="ctc-plate-bottom">
		<span class="ctc-plate-holder">
			<small><?php esc_html_e( 'به نام', 'card-to-card' ); ?></small>
			<span><?php echo esc_html( '' !== $args['holder'] ? $args['holder'] : '—' ); ?></span>
		</span>
		<?php
		$ctc_copy = '' !== $args['digits'] ? array( $args['digits'], __( 'کپی شماره کارت', 'card-to-card' ) ) : array( $args['sheba_r'], __( 'کپی شماره شبا', 'card-to-card' ) );
		echo Payment_Page::copy_button( (string) $ctc_copy[0], (string) $ctc_copy[1], 'is-glass' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the builder.
		?>
	</div>
</div>
