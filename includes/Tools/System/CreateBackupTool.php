<?php
/**
 * Manual Snapshot Backup Tool (`system.create_backup`).
 *
 * @package WPilotAI\Tools\System
 */

declare(strict_types=1);

namespace WPilotAI\Tools\System;

use WPilotAI\Backup\BackupManager;
use WPilotAI\Core\WPilotException;
use WPilotAI\Security\PermissionEngine;
use WPilotAI\Tools\ToolInterface;

final class CreateBackupTool implements ToolInterface {

	private BackupManager $backups;

	public function __construct( BackupManager $backups ) {
		$this->backups = $backups;
	}

	public function get_name(): string {
		return 'system.create_backup';
	}

	public function get_description(): string {
		return 'Capture an explicit backup snapshot of a WordPress page or post prior to manual or multi-step edits.';
	}

	public function get_input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'post_id' ),
			'properties'           => array(
				'post_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
		);
	}

	public function get_required_capability(): string {
		return 'edit_pages';
	}

	public function get_risk_level(): string {
		return PermissionEngine::RISK_LOW;
	}

	public function validate( array $arguments ): bool {
		return isset( $arguments['post_id'] ) && is_int( $arguments['post_id'] ) && $arguments['post_id'] > 0;
	}

	public function execute( array $arguments ): array {
		$post_id = (int) $arguments['post_id'];
		$post    = get_post( $post_id );
		if ( ! $post ) {
			throw new WPilotException( 'wpilot_post_not_found', 'Post/Page not found for backup.', 404 );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			throw new WPilotException( 'wpilot_forbidden', 'Current user cannot back up this post.', 403 );
		}

		$backup_id = $this->backups->create_snapshot(
			'post',
			(string) $post_id,
			$this->get_name(),
			array(
				'post_type'    => $post->post_type,
				'post_status'  => $post->post_status,
				'post_name'    => $post->post_name,
				'post_title'   => $post->post_title,
				'post_content' => $post->post_content,
				'post_excerpt' => $post->post_excerpt,
				'post_parent'  => (int) $post->post_parent,
				'menu_order'   => (int) $post->menu_order,
			)
		);

		return array(
			'backup_id' => $backup_id,
			'post_id'   => $post_id,
			'captured'  => true,
		);
	}

	public function verify( array $arguments, array $result ): bool {
		return ! empty( $result['backup_id'] ) && (int) $result['backup_id'] > 0;
	}
}
