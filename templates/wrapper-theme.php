<?php
/**
 * Payment page inside a classic theme (header.php / footer.php).
 * Override: copy to yourtheme/card-to-card/wrapper-theme.php.
 *
 * @package CardToCard
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="ctc-main" class="ctc-shell is-theme">
	<?php \CardToCard\Payment_Page::body(); ?>
</main>
<?php
get_footer();
