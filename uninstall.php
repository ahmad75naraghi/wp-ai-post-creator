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
