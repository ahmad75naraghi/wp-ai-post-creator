<?php
/**
 * Uninstall cleanup for AI Post Creator.
 *
 * Data is only removed when the site owner explicitly enabled
 * "Delete all plugin data on uninstall" in the settings.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$aipc_settings = get_option( 'aipc_settings', array() );

if ( empty( $aipc_settings['delete_on_uninstall'] ) ) {
	return; // Keep data unless the user opted in.
}

delete_option( 'aipc_settings' );
delete_option( 'aipc_jobs' );
delete_option( 'aipc_connections' );
delete_option( 'aipc_steps' );
delete_option( 'aipc_stats' );
delete_option( 'aipc_schedule' );
delete_option( 'aipc_bale' );
delete_option( 'aipc_git' );
delete_option( 'aipc_topic_queue' );
delete_option( 'aipc_schema_version' );
wp_clear_scheduled_hook( 'aipc_cron_tick' );
wp_clear_scheduled_hook( 'aipc_publish_post' );
wp_clear_scheduled_hook( 'aipc_run_job' );
wp_clear_scheduled_hook( 'aipc_bale_poll' );

// The 1.6 jobs table.
$GLOBALS['wpdb']->query( 'DROP TABLE IF EXISTS ' . $GLOBALS['wpdb']->prefix . 'aipc_jobs' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Remove plugin meta from all posts.
global $wpdb;
$aipc_meta_keys = array(
	'_aipc_generated',
	'_aipc_job',
	'_aipc_faq_schema',
	'_aipc_meta_title',
	'_aipc_meta_description',
);

foreach ( $aipc_meta_keys as $aipc_key ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", $aipc_key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

wp_clear_scheduled_hook( 'aipc_daily_cleanup' );

// Updater leftovers: cached versions, backups, temp folders.
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_aipc\\_git\\_v\\_%' OR option_name LIKE '\\_transient\\_timeout\\_aipc\\_git\\_v\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

$aipc_rrmdir = function ( $dir ) use ( &$aipc_rrmdir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( scandir( $dir ) as $f ) {
		if ( '.' !== $f && '..' !== $f ) {
			$path = $dir . '/' . $f;
			is_dir( $path ) ? $aipc_rrmdir( $path ) : @unlink( $path );
		}
	}
	@rmdir( $dir );
};

if ( is_dir( WP_CONTENT_DIR . '/aipc-backups' ) ) {
	$aipc_rrmdir( WP_CONTENT_DIR . '/aipc-backups' );
}
foreach ( glob( WP_CONTENT_DIR . '/aipc-git-tmp-*' ) as $aipc_tmp_dir ) {
	$aipc_rrmdir( $aipc_tmp_dir );
}
