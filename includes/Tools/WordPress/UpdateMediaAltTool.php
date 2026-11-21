<?php
/**
 * WordPress Update Media Alt Text Tool (`wordpress.update_media_alt`).
 *
 * @package WPilotAI\Tools\WordPress
 */

declare(strict_types=1);

namespace WPilotAI\Tools\WordPress;

use WPilotAI\Backup\BackupManager;
use WPilotAI\Core\WPilotException;
use WPilotAI\Security\PermissionEngine;
use WPilotAI\Tools\ToolInterface;

final class UpdateMediaAltTool implements ToolInterface {

	private BackupManager $backups;

	public function __construct( BackupManager $backups ) {
		$this->backups = $backups;
	}

	public function get_name(): string {
		return 'wordpress.update_media_alt';
	}

	public function get_description(): string {
		return 'Update accessibility alt text for a media attachment with full rollback snapshot.';
	}

	public function get_input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'media_id', 'alt' ),
			'properties'           => array(
				'media_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'alt'      => array(
					'type'      => 'string',
					'maxLength' => 500,
				),
			),
		);
	}

	public function get_required_capability(): string {
		return 'upload_files';
	}

	public function get_risk_level(): string {
		return PermissionEngine::RISK_MEDIUM;
	}

	public function validate( array $arguments ): bool {
		return isset( $arguments['media_id'], $arguments['alt'] )
			&& is_int( $arguments['media_id'] )
			&& $arguments['media_id'] > 0
			&& is_string( $arguments['alt'] );
	}

	public function execute( array $arguments ): array {
		$media_id = (int) $arguments['media_id'];
		$post     = get_post( $media_id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			throw new WPilotException( 'wpilot_media_not_found', 'Media attachment not found.', 404 );
		}
		if ( ! current_user_can( 'edit_post', $media_id ) ) {
			throw new WPilotException( 'wpilot_forbidden', 'Current user cannot edit this media attachment.', 403 );
		}

		$existed      = metadata_exists( 'post', $media_id, '_wp_attachment_image_alt' );
		$previous_alt = $existed ? (string) get_post_meta( $media_id, '_wp_attachment_image_alt', true ) : '';
		$new_alt      = sanitize_text_field( (string) $arguments['alt'] );

		$backup_id = $this->backups->create_snapshot(
			'post_meta',
			(string) $media_id,
			$this->get_name(),
			array(
				'raw_meta' => array(
					'_wp_attachment_image_alt' => array(
						'exists' => $existed,
						'value'  => $previous_alt,
					),
				),
			),
			array( '_wp_attachment_image_alt' => $new_alt )
		);

		update_post_meta( $media_id, '_wp_attachment_image_alt', $new_alt );

		return array(
			'media_id'  => $media_id,
			'alt'       => $new_alt,
			'backup_id' => $backup_id,
			'updated'   => true,
		);
	}

	public function verify( array $arguments, array $result ): bool {
		return sanitize_text_field( (string) $arguments['alt'] ) === (string) get_post_meta( (int) $arguments['media_id'], '_wp_attachment_image_alt', true );
	}
}
