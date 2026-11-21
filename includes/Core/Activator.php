<?php
/**
 * Plugin Activation & Database Schema Setup.
 *
 * @package WPilotAI\Core
 */

declare(strict_types=1);

namespace WPilotAI\Core;

final class Activator {

	public const DB_VERSION = '1.0.0';

	public static function activate(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix . 'wpilot_';

		$sql_conversations = "CREATE TABLE {$prefix}conversations (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			title VARCHAR(255) NOT NULL DEFAULT '',
			provider VARCHAR(64) NOT NULL DEFAULT 'openai',
			model VARCHAR(128) NOT NULL DEFAULT 'gpt-4.1',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_id (user_id)
		) {$charset_collate};";

		$sql_messages = "CREATE TABLE {$prefix}messages (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			conversation_id BIGINT UNSIGNED NOT NULL,
			role VARCHAR(32) NOT NULL,
			content LONGTEXT NOT NULL,
			tool_calls LONGTEXT NULL,
			token_usage LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY conversation_id (conversation_id)
		) {$charset_collate};";

		$sql_tasks = "CREATE TABLE {$prefix}tasks (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			conversation_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			tool_name VARCHAR(128) NOT NULL,
			risk_level VARCHAR(16) NOT NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'pending',
			arguments LONGTEXT NOT NULL,
			before_snapshot LONGTEXT NULL,
			proposed_diff LONGTEXT NULL,
			result LONGTEXT NULL,
			backup_id BIGINT UNSIGNED NULL,
			approved_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY status (status),
			KEY conversation_id (conversation_id)
		) {$charset_collate};";

		$sql_backups = "CREATE TABLE {$prefix}backups (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			resource_type VARCHAR(64) NOT NULL,
			resource_id VARCHAR(128) NOT NULL,
			tool_name VARCHAR(128) NOT NULL,
			previous_state LONGTEXT NOT NULL,
			proposed_state LONGTEXT NULL,
			restored_at DATETIME NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY resource_lookup (resource_type, resource_id)
		) {$charset_collate};";

		$sql_audit = "CREATE TABLE {$prefix}audit_logs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			action VARCHAR(128) NOT NULL,
			risk_level VARCHAR(16) NOT NULL DEFAULT 'low',
			status VARCHAR(32) NOT NULL,
			summary TEXT NOT NULL,
			metadata LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY action (action)
		) {$charset_collate};";

		dbDelta( $sql_conversations );
		dbDelta( $sql_messages );
		dbDelta( $sql_tasks );
		dbDelta( $sql_backups );
		dbDelta( $sql_audit );

		update_option( 'wpilot_ai_db_version', self::DB_VERSION, false );

		if ( false === get_option( Settings::OPTION_KEY ) ) {
			add_option( Settings::OPTION_KEY, Settings::defaults(), '', false );
		}
	}
}
