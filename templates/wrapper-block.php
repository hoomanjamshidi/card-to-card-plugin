<?php
/**
 * Payment page inside a block theme: the theme's own header and footer template parts.
 * The parts are rendered before wp_head() so their block styles are printed in the head.
 * Override: copy to yourtheme/card-to-card/wrapper-block.php.
 *
 * @package CardToCard
 */

defined( 'ABSPATH' ) || exit;

$ctc_header = do_blocks( '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->' );
$ctc_footer = do_blocks( '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->' );
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'ctc-body' ); ?>>
<?php wp_body_open(); ?>
<div class="wp-site-blocks">
	<?php echo $ctc_header; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered blocks. ?>
	<main id="ctc-main" class="ctc-shell">
		<?php \CardToCard\Payment_Page::body(); ?>
	</main>
	<?php echo $ctc_footer; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered blocks. ?>
</div>
<?php wp_footer(); ?>
</body>
</html>
