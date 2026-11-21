<?php
/**
 * WordPress Create Page Tool (`wordpress.create_page`).
 *
 * @package WPilotAI\Tools\WordPress
 */

declare(strict_types=1);

namespace WPilotAI\Tools\WordPress;

use WPilotAI\Core\WPilotException;
use WPilotAI\Security\PermissionEngine;
use WPilotAI\Tools\ToolInterface;

final class CreatePageTool implements ToolInterface {

	public function get_name(): string {
		return 'wordpress.create_page';
	}

	public function get_description(): string {
		return 'Create a new WordPress page (defaults to draft status unless publish is explicitly approved).';
	}

	public function get_input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'title', 'content' ),
			'properties'           => array(
				'title'   => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 500,
				),
				'content' => array(
					'type'      => 'string',
					'maxLength' => 100000,
				),
				'status'  => array(
					'type' => 'string',
					'enum' => array( 'draft', 'publish' ),
				),
				'slug'    => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
			),
		);
	}

	public function get_required_capability(): string {
		return 'publish_pages';
	}

	public function get_risk_level(): string {
		return PermissionEngine::RISK_MEDIUM;
	}

	public function validate( array $arguments ): bool {
		return isset( $arguments['title'], $arguments['content'] )
			&& is_string( $arguments['title'] )
			&& '' !== trim( $arguments['title'] )
			&& is_string( $arguments['content'] );
	}

	public function execute( array $arguments ): array {
		$status = isset( $arguments['status'] ) && 'publish' === $arguments['status'] ? 'publish' : 'draft';
		if ( ! current_user_can( 'edit_pages' ) || ( 'publish' === $status && ! current_user_can( 'publish_pages' ) ) ) {
			throw new WPilotException( 'wpilot_forbidden', 'Current user lacks permission to create a page with this status.', 403 );
		}

		$post_data = array(
			'post_type'    => 'page',
			'post_title'   => sanitize_text_field( (string) $arguments['title'] ),
			'post_content' => wp_kses_post( (string) ( $arguments['content'] ?? '' ) ),
			'post_status'  => $status,
		);
		if ( ! empty( $arguments['slug'] ) ) {
			$post_data['post_name'] = sanitize_title( (string) $arguments['slug'] );
		}

		$page_id = wp_insert_post( $post_data, true );
		if ( is_wp_error( $page_id ) ) {
			throw new WPilotException( 'wpilot_create_failed', 'Failed to create WordPress page.', 500 );
		}

		return array(
			'page_id' => (int) $page_id,
			'status'  => $status,
			'url'     => get_permalink( (int) $page_id ),
			'created' => true,
		);
	}

	public function verify( array $arguments, array $result ): bool {
		if ( empty( $result['page_id'] ) ) {
			return false;
		}
		$post = get_post( (int) $result['page_id'] );
		return $post && 'page' === $post->post_type;
	}
}
