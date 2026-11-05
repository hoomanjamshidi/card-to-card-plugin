<?php
/**
 * Uninstall: settings, logs and counters are removed only when «حذف داده‌ها هنگام حذف افزونه» is on.
 * Receipt files and order meta stay (they are part of the shop's financial records) unless the same
 * switch is on, then the receipts folder is removed too.
 *
 * @package CardToCard
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$ctc_settings = get_option( 'ctc_settings', array() );
if ( ! is_array( $ctc_settings ) || empty( $ctc_settings['cleanup'] ) ) {
	return;
}
foreach ( array( 'ctc_settings', 'ctc_sms_log', 'ctc_rotate', 'ctc_rewrite', 'woocommerce_card_to_card_settings' ) as $ctc_option ) {
	delete_option( $ctc_option );
}
delete_transient( 'ctc_pending_count' );

$ctc_dir = wp_upload_dir( null, false )['basedir'] . '/ctc-receipts';
if ( is_dir( $ctc_dir ) ) {
	$ctc_files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $ctc_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $ctc_files as $ctc_file ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removing our own folder.
		$ctc_file->isDir() ? rmdir( $ctc_file->getPathname() ) : wp_delete_file( $ctc_file->getPathname() );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	rmdir( $ctc_dir );
}
