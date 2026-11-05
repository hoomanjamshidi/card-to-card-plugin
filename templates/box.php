<?php
/**
 * Compact box on the thank-you and view-order pages: the card(s) and «ارسال رسید» while the receipt
 * is awaited, otherwise the receipt state with a link to the payment page.
 * Override: copy to yourtheme/card-to-card/box.php.
 *
 * @package CardToCard
 *
 * @var array<string, mixed> $args See Payment_Page::render_box().
 */

use CardToCard\Payment_Page;
use CardToCard\Util;

defined( 'ABSPATH' ) || exit;

$ctc_state = (string) $args['state'];
$ctc_label = array(
	'pay'       => __( 'منتظر واریز و ارسال رسید شما هستیم.', 'card-to-card' ),
	'review'    => __( 'رسید شما رسید و در حال بررسی است.', 'card-to-card' ),
	'paid'      => __( 'واریز شما تأیید شد.', 'card-to-card' ),
	'cancelled' => __( 'این سفارش لغو شده است.', 'card-to-card' ),
);
?>
<section class="ctc ctc-box is-<?php echo esc_attr( $ctc_state ); ?>" data-scheme="<?php echo esc_attr( $args['scheme'] ); ?>" dir="rtl">
	<header class="ctc-box-h">
		<h2><?php echo Util::icon( 'card' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'پرداخت کارت به کارت', 'card-to-card' ); ?></h2>
		<p><?php echo esc_html( $ctc_label[ $ctc_state ] ?? '' ); ?></p>
	</header>
	<?php foreach ( (array) $args['cards'] as $ctc_card ) : ?>
		<div class="ctc-cardrow">
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
	<?php if ( ! empty( $args['history'] ) ) : ?>
		<?php
		$ctc_hist = array(
			'pending'  => __( 'در انتظار بررسی', 'card-to-card' ),
			'approved' => __( 'تأیید شد', 'card-to-card' ),
			'rejected' => __( 'رد شد', 'card-to-card' ),
		);
		?>
		<ol class="ctc-hist-list">
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
	<?php endif; ?>
	<?php if ( '' !== $args['receipt'] ) : ?>
		<a class="ctc-btn ctc-box-cta" href="<?php echo esc_url( $args['receipt'] ); ?>"><?php echo Util::icon( 'upload' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'ارسال رسید واریز', 'card-to-card' ); ?></a>
	<?php elseif ( '' !== $args['url'] && 'pay' !== $ctc_state ) : ?>
		<a class="ctc-btn is-ghost ctc-box-cta" href="<?php echo esc_url( $args['url'] ); ?>"><?php echo Util::icon( 'eye' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'وضعیت پرداخت', 'card-to-card' ); ?></a>
	<?php endif; ?>
</section>
