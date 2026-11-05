<?php
/**
 * Payment page as a clean page of its own (no theme header/footer): shop name / logo on top.
 * Override: copy to yourtheme/card-to-card/wrapper-standalone.php.
 *
 * @package CardToCard
 */

defined( 'ABSPATH' ) || exit;

$ctc_logo = get_custom_logo();
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'ctc-body ctc-standalone' ); ?>>
<?php wp_body_open(); ?>
<header class="ctc-topbar">
	<a class="ctc-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>">
		<?php if ( $ctc_logo ) : ?>
			<?php echo wp_kses_post( $ctc_logo ); ?>
		<?php else : ?>
			<span><?php bloginfo( 'name' ); ?></span>
		<?php endif; ?>
	</a>
	<span class="ctc-secure"><?php echo \CardToCard\Util::icon( 'shield' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'صفحه امن پرداخت', 'card-to-card' ); ?></span>
</header>
<main id="ctc-main" class="ctc-shell">
	<?php \CardToCard\Payment_Page::body(); ?>
</main>
<?php wp_footer(); ?>
</body>
</html>
