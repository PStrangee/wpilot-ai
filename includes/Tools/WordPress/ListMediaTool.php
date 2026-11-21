<?php
/**
 * WordPress List Media Tool (`wordpress.list_media`).
 *
 * @package WPilotAI\Tools\WordPress
 */

declare(strict_types=1);

namespace WPilotAI\Tools\WordPress;

use WPilotAI\Core\WPilotException;
use WPilotAI\Security\PermissionEngine;
use WPilotAI\Tools\ToolInterface;

final class ListMediaTool implements ToolInterface {

	public function get_name(): string {
		return 'wordpress.list_media';
	}

	public function get_description(): string {
		return 'Inspect media library items and flag images missing accessibility alt attributes.';
	}

	public function get_input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'missing_alt_only' => array( 'type' => 'boolean' ),
				'limit'            => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 50,
				),
			),
		);
	}

	public function get_required_capability(): string {
		return 'upload_files';
	}

	public function get_risk_level(): string {
		return PermissionEngine::RISK_LOW;
	}

	public function validate( array $arguments ): bool {
		if ( isset( $arguments['limit'] ) && ( ! is_int( $arguments['limit'] ) || $arguments['limit'] < 1 || $arguments['limit'] > 50 ) ) {
			return false;
		}
		if ( isset( $arguments['missing_alt_only'] ) && ! is_bool( $arguments['missing_alt_only'] ) ) {
			return false;
		}
		return true;
	}

	public function execute( array $arguments ): array {
		if ( ! current_user_can( 'upload_files' ) ) {
			throw new WPilotException( 'wpilot_forbidden', 'Current user lacks permission to inspect media library items.', 403 );
		}

		$limit       = isset( $arguments['limit'] ) ? max( 1, min( 50, (int) $arguments['limit'] ) ) : 25;
		$missing_alt = ! empty( $arguments['missing_alt_only'] );

		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'post_status'    => 'inherit',
				'posts_per_page' => $limit,
			)
		);

		$items = array();
		foreach ( $attachments as $media ) {
			if ( ! current_user_can( 'edit_post', $media->ID ) ) {
				continue;
			}
			$alt = (string) get_post_meta( $media->ID, '_wp_attachment_image_alt', true );
			if ( $missing_alt && '' !== trim( $alt ) ) {
				continue;
			}
			$items[] = array(
				'id'       => $media->ID,
				'filename' => basename( (string) get_attached_file( $media->ID ) ),
				'alt'      => $alt,
				'mime'     => $media->post_mime_type,
			);
		}

		return array(
			'count' => count( $items ),
			'media' => $items,
		);
	}

	public function verify( array $arguments, array $result ): bool {
		return isset( $result['media'] );
	}
}
