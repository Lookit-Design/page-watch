<?php
/**
 * Uninstall cleanup for Lookit Page Watch.
 *
 * This runs when the plugin is deleted, not when it is updated. Deleting is a
 * normal part of testing a new build, though, and losing the webhook URL,
 * token and watchlist every time makes that tedious, so the plugin offers to
 * leave its data alone. When that option is on, nothing here is destroyed and
 * a reinstall carries straight on where it left off.
 *
 * @package LookitPageWatch
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$lookit_page_watch_settings = get_option( 'lookit_page_watch_settings' );

// Scheduled events always go, whether or not the data stays.
wp_clear_scheduled_hook( 'lpw_capture_event' );
wp_clear_scheduled_hook( 'lpw_digest_event' );

$lookit_page_watch_preserve = is_array( $lookit_page_watch_settings )
	&& ! empty( $lookit_page_watch_settings['preserve_on_uninstall'] );

if ( $lookit_page_watch_preserve ) {
	return;
}

// Stored capture files that were not registered as attachments.
if ( is_array( $lookit_page_watch_settings ) && ! empty( $lookit_page_watch_settings['dir_key'] ) ) {
	$lookit_page_watch_uploads = wp_upload_dir();
	$lookit_page_watch_dir     = trailingslashit( $lookit_page_watch_uploads['basedir'] ) . 'lookit-page-watch/' . $lookit_page_watch_settings['dir_key'];

	if ( is_dir( $lookit_page_watch_dir ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();

		global $wp_filesystem;

		if ( $wp_filesystem ) {
			$wp_filesystem->delete( $lookit_page_watch_dir, true );
		}
	}
}

// Captures that were registered as Media Library attachments.
$lookit_page_watch_attachments = get_posts(
	array(
		'post_type'   => 'attachment',
		'post_status' => 'any',
		'numberposts' => -1,
		'fields'      => 'ids',
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-off uninstall cleanup.
		'meta_key'    => '_lpw_kind',
	)
);

foreach ( $lookit_page_watch_attachments as $lookit_page_watch_attachment_id ) {
	wp_delete_attachment( (int) $lookit_page_watch_attachment_id, true );
}

delete_option( 'lookit_page_watch_settings' );
delete_option( 'lookit_page_watch_db_version' );
delete_option( 'lookit_page_watch_last_digest' );

$lookit_page_watch_pages    = $wpdb->prefix . 'lookit_page_watch_pages';
$lookit_page_watch_captures = $wpdb->prefix . 'lookit_page_watch_captures';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- dropping our own tables on uninstall.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $lookit_page_watch_captures ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- dropping our own tables on uninstall.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $lookit_page_watch_pages ) );
