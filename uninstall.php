<?php
/**
 * Uninstall routine for WPilot AI.
 *
 * Cleans up plugin options and custom tables when deleted from WordPress Admin.
 *
 * @package WPilotAI
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$wpilot_options = array(
	'wpilot_ai_settings',
	'wpilot_ai_openai_key_enc',
	'wpilot_ai_db_version',
);

foreach ( $wpilot_options as $option_name ) {
	delete_option( $option_name );
}

$wpilot_tables = array(
	$wpdb->prefix . 'wpilot_conversations',
	$wpdb->prefix . 'wpilot_messages',
	$wpdb->prefix . 'wpilot_tasks',
	$wpdb->prefix . 'wpilot_backups',
	$wpdb->prefix . 'wpilot_audit_logs',
);

foreach ( $wpilot_tables as $table_name ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table names are fixed internal plugin identifiers dropped on uninstall.
	$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" );
}

// Clean up per-user hourly rate-limit transients.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Removing plugin transients on uninstall.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_wpilot_rl_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_wpilot_rl_' ) . '%'
	)
);
