<?php
/**
 * System Rollback Tool (`system.rollback`).
 *
 * @package WPilotAI\Tools\System
 */

declare(strict_types=1);

namespace WPilotAI\Tools\System;

use WPilotAI\Backup\BackupManager;
use WPilotAI\Security\PermissionEngine;
use WPilotAI\Tools\ToolInterface;

final class RollbackTool implements ToolInterface {

	private BackupManager $backups;

	public function __construct( BackupManager $backups ) {
		$this->backups = $backups;
	}

	public function get_name(): string {
		return 'system.rollback';
	}

	public function get_description(): string {
		return 'Restore a previously captured WPilot AI backup snapshot by ID.';
	}

	public function get_input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'backup_id' ),
			'properties'           => array(
				'backup_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
		);
	}

	public function get_required_capability(): string {
		return 'manage_options';
	}

	public function get_risk_level(): string {
		return PermissionEngine::RISK_HIGH;
	}

	public function validate( array $arguments ): bool {
		return isset( $arguments['backup_id'] ) && is_int( $arguments['backup_id'] ) && $arguments['backup_id'] > 0;
	}

	public function execute( array $arguments ): array {
		return $this->backups->rollback( (int) $arguments['backup_id'] );
	}

	public function verify( array $arguments, array $result ): bool {
		return ! empty( $result['restored'] ) && ! empty( $result['verified'] );
	}
}
