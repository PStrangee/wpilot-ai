<?php
/**
 * WordPress Update Page Tool (`wordpress.update_page`) with Complete Backup & Verification.
 *
 * @package WPilotAI\Tools\WordPress
 */

declare(strict_types=1);

namespace WPilotAI\Tools\WordPress;

use WPilotAI\Backup\BackupManager;
use WPilotAI\Core\WPilotException;
use WPilotAI\Security\PermissionEngine;
use WPilotAI\Tools\ToolInterface;

final class UpdatePageTool implements ToolInterface {

	private BackupManager $backups;

	public function __construct( BackupManager $backups ) {
		$this->backups = $backups;
	}

	public function get_name(): string {
		return 'wordpress.update_page';
	}

	public function get_description(): string {
		return 'Safely update a WordPress page title, content, or excerpt after capturing a complete rollback snapshot.';
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
				'title'   => array(
					'type'      => 'string',
					'maxLength' => 500,
				),
				'content' => array(
					'type'      => 'string',
					'maxLength' => 100000,
				),
				'excerpt' => array(
					'type'      => 'string',
					'maxLength' => 2000,
				),
			),
		);
	}

	public function get_required_capability(): string {
		return 'edit_pages';
	}

	public function get_risk_level(): string {
		return PermissionEngine::RISK_MEDIUM;
	}

	public function validate( array $arguments ): bool {
		if ( ! isset( $arguments['page_id'] ) || ! is_int( $arguments['page_id'] ) || $arguments['page_id'] <= 0 ) {
			return false;
		}
		return isset( $arguments['title'] ) || isset( $arguments['content'] ) || isset( $arguments['excerpt'] );
	}

	public function execute( array $arguments ): array {
		$page_id = (int) $arguments['page_id'];
		$page    = get_post( $page_id );

		if ( ! $page || 'page' !== $page->post_type ) {
			throw new WPilotException( 'wpilot_page_not_found', 'Target page does not exist.', 404 );
		}

		if ( ! current_user_can( 'edit_post', $page_id ) ) {
			throw new WPilotException( 'wpilot_forbidden', 'Current user cannot edit this page.', 403 );
		}

		$previous = array(
			'post_type'    => $page->post_type,
			'post_status'  => $page->post_status,
			'post_name'    => $page->post_name,
			'post_title'   => $page->post_title,
			'post_content' => $page->post_content,
			'post_excerpt' => $page->post_excerpt,
			'post_parent'  => (int) $page->post_parent,
			'menu_order'   => (int) $page->menu_order,
		);

		$update = array( 'ID' => $page_id );
		if ( isset( $arguments['title'] ) ) {
			$update['post_title'] = sanitize_text_field( (string) $arguments['title'] );
		}
		if ( isset( $arguments['content'] ) ) {
			$update['post_content'] = wp_kses_post( (string) $arguments['content'] );
		}
		if ( isset( $arguments['excerpt'] ) ) {
			$update['post_excerpt'] = sanitize_textarea_field( (string) $arguments['excerpt'] );
		}

		$backup_id = $this->backups->create_snapshot( 'post', (string) $page_id, $this->get_name(), $previous, $update );
		$updated   = wp_update_post( $update, true );

		if ( is_wp_error( $updated ) ) {
			throw new WPilotException( 'wpilot_update_failed', 'Failed to update the target WordPress page.', 500 );
		}

		return array(
			'page_id'   => $page_id,
			'backup_id' => $backup_id,
			'updated'   => true,
		);
	}

	public function verify( array $arguments, array $result ): bool {
		$page = get_post( (int) $arguments['page_id'] );
		if ( ! $page ) {
			return false;
		}
		if ( isset( $arguments['title'] ) && sanitize_text_field( (string) $arguments['title'] ) !== $page->post_title ) {
			return false;
		}
		if ( isset( $arguments['content'] ) && wp_kses_post( (string) $arguments['content'] ) !== $page->post_content ) {
			return false;
		}
		if ( isset( $arguments['excerpt'] ) && sanitize_textarea_field( (string) $arguments['excerpt'] ) !== $page->post_excerpt ) {
			return false;
		}
		return true;
	}
}
