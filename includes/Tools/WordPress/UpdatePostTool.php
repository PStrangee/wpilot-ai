<?php
/**
 * WordPress Update Post Tool (`wordpress.update_post`) with Complete Backup & Verification.
 *
 * @package WPilotAI\Tools\WordPress
 */

declare(strict_types=1);

namespace WPilotAI\Tools\WordPress;

use WPilotAI\Backup\BackupManager;
use WPilotAI\Core\WPilotException;
use WPilotAI\Security\PermissionEngine;
use WPilotAI\Tools\ToolInterface;

final class UpdatePostTool implements ToolInterface {

	private BackupManager $backups;

	public function __construct( BackupManager $backups ) {
		$this->backups = $backups;
	}

	public function get_name(): string {
		return 'wordpress.update_post';
	}

	public function get_description(): string {
		return 'Safely update a WordPress blog post title, content, or excerpt after saving a complete backup snapshot.';
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
		return 'edit_posts';
	}

	public function get_risk_level(): string {
		return PermissionEngine::RISK_MEDIUM;
	}

	public function validate( array $arguments ): bool {
		if ( ! isset( $arguments['post_id'] ) || ! is_int( $arguments['post_id'] ) || $arguments['post_id'] <= 0 ) {
			return false;
		}
		return isset( $arguments['title'] ) || isset( $arguments['content'] ) || isset( $arguments['excerpt'] );
	}

	public function execute( array $arguments ): array {
		$post_id = (int) $arguments['post_id'];
		$post    = get_post( $post_id );
		if ( ! $post || 'post' !== $post->post_type ) {
			throw new WPilotException( 'wpilot_post_not_found', 'Target blog post not found.', 404 );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			throw new WPilotException( 'wpilot_forbidden', 'Current user cannot edit this post.', 403 );
		}

		$previous = array(
			'post_type'    => $post->post_type,
			'post_status'  => $post->post_status,
			'post_name'    => $post->post_name,
			'post_title'   => $post->post_title,
			'post_content' => $post->post_content,
			'post_excerpt' => $post->post_excerpt,
			'post_parent'  => (int) $post->post_parent,
			'menu_order'   => (int) $post->menu_order,
		);

		$update = array( 'ID' => $post_id );
		if ( isset( $arguments['title'] ) ) {
			$update['post_title'] = sanitize_text_field( (string) $arguments['title'] );
		}
		if ( isset( $arguments['content'] ) ) {
			$update['post_content'] = wp_kses_post( (string) $arguments['content'] );
		}
		if ( isset( $arguments['excerpt'] ) ) {
			$update['post_excerpt'] = sanitize_textarea_field( (string) $arguments['excerpt'] );
		}

		$backup_id = $this->backups->create_snapshot( 'post', (string) $post_id, $this->get_name(), $previous, $update );
		$updated   = wp_update_post( $update, true );
		if ( is_wp_error( $updated ) ) {
			throw new WPilotException( 'wpilot_update_failed', 'Failed to update the target blog post.', 500 );
		}

		return array(
			'post_id'   => $post_id,
			'backup_id' => $backup_id,
			'updated'   => true,
		);
	}

	public function verify( array $arguments, array $result ): bool {
		$post = get_post( (int) $arguments['post_id'] );
		if ( ! $post ) {
			return false;
		}
		if ( isset( $arguments['title'] ) && sanitize_text_field( (string) $arguments['title'] ) !== $post->post_title ) {
			return false;
		}
		if ( isset( $arguments['content'] ) && wp_kses_post( (string) $arguments['content'] ) !== $post->post_content ) {
			return false;
		}
		if ( isset( $arguments['excerpt'] ) && sanitize_textarea_field( (string) $arguments['excerpt'] ) !== $post->post_excerpt ) {
			return false;
		}
		return true;
	}
}
