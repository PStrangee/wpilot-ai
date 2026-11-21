<?php
/**
 * WordPress Delete Page Tool (`wordpress.delete_page`) — High Risk, moves to Trash with full backup snapshot.
 *
 * @package WPilotAI\Tools\WordPress
 */

declare(strict_types=1);

namespace WPilotAI\Tools\WordPress;

use WPilotAI\Backup\BackupManager;
use WPilotAI\Core\WPilotException;
use WPilotAI\Security\PermissionEngine;
use WPilotAI\Tools\ToolInterface;

final class DeletePageTool implements ToolInterface {

	private BackupManager $backups;

	public function __construct( BackupManager $backups ) {
		$this->backups = $backups;
	}

	public function get_name(): string {
		return 'wordpress.delete_page';
	}

	public function get_description(): string {
		return 'Move a WordPress page to Trash after capturing a full backup snapshot. Always requires explicit administrator approval.';
	}

	public function get_input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'page_id' ),
			'properties'           => array(
				'page_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
		);
	}

	public function get_required_capability(): string {
		return 'delete_pages';
	}

	public function get_risk_level(): string {
		return PermissionEngine::RISK_HIGH;
	}

	public function validate( array $arguments ): bool {
		return isset( $arguments['page_id'] ) && is_int( $arguments['page_id'] ) && $arguments['page_id'] > 0;
	}

	public function execute( array $arguments ): array {
		$page_id = (int) $arguments['page_id'];
		$page    = get_post( $page_id );
		if ( ! $page || 'page' !== $page->post_type ) {
			throw new WPilotException( 'wpilot_page_not_found', 'Target page not found.', 404 );
		}
		if ( ! current_user_can( 'delete_post', $page_id ) ) {
			throw new WPilotException( 'wpilot_forbidden', 'Current user cannot delete this page.', 403 );
		}

		$backup_id = $this->backups->create_snapshot(
			'post',
			(string) $page_id,
			$this->get_name(),
			array(
				'post_type'    => $page->post_type,
				'post_status'  => $page->post_status,
				'post_name'    => $page->post_name,
				'post_title'   => $page->post_title,
				'post_content' => $page->post_content,
				'post_excerpt' => $page->post_excerpt,
				'post_parent'  => (int) $page->post_parent,
				'menu_order'   => (int) $page->menu_order,
			),
			array( 'post_status' => 'trash' )
		);

		$trashed = wp_trash_post( $page_id );
		if ( ! $trashed ) {
			throw new WPilotException( 'wpilot_delete_failed', 'Failed to move page to Trash.', 500 );
		}

		return array(
			'page_id'   => $page_id,
			'backup_id' => $backup_id,
			'trashed'   => true,
		);
	}

	public function verify( array $arguments, array $result ): bool {
		$post = get_post( (int) $arguments['page_id'] );
		return $post && 'trash' === $post->post_status;
	}
}
