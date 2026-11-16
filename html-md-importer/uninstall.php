<?php
/**
 * Uninstall: removes temporary import sessions. Imported pages, posts and media are kept.
 *
 * @package HtmlMdImporter
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'hmi_cleanup_sessions' );

$hmi_uploads = wp_upload_dir( null, false );
$hmi_dir     = rtrim( (string) $hmi_uploads['basedir'], '/\\' ) . '/hmi-sessions';
if ( is_dir( $hmi_dir ) ) {
	$hmi_iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $hmi_dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $hmi_iterator as $hmi_info ) {
		$hmi_info->isDir() ? @rmdir( $hmi_info->getPathname() ) : @unlink( $hmi_info->getPathname() ); // phpcs:ignore
	}
	@rmdir( $hmi_dir ); // phpcs:ignore
}
