<?php
/**
 * Uninstall ODrive Connector
 *
 * Runs when the plugin is deleted from the WordPress admin.
 *
 * @package ODriveConnector
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Remove all plugin options.
$options = array(
	'odrive_api_token',
	'odrive_api_token_encrypted',
	'odrive_url',
	'odrive_site_id',
	'odrive_registration_status',
	'odrive_last_health_check',
	'odrive_health_status',
	'odrive_connected',
	'odrive_backup_schedule',
	'odrive_settings',
	'odrive_db_version',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// Drop activity log table.
$table_name = $wpdb->prefix . 'odrive_activity';
$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Clear all ODrive transients.
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"DELETE FROM `{$wpdb->options}` WHERE `option_name` LIKE '_transient_odrive_%' OR `option_name` LIKE '_transient_timeout_odrive_%'"
);

// Clear WP-Cron events registered by this plugin.
$cron_events = array(
	'odrive_health_check',
	'odrive_scheduled_backup',
	'odrive_cleanup_logs',
);

foreach ( $cron_events as $event ) {
	$timestamp = wp_next_scheduled( $event );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, $event );
	}
	wp_clear_scheduled_hook( $event );
}
