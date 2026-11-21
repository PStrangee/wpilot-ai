<?php
/**
 * WordPress Get Page Tool (`wordpress.get_page`).
 *
 * @package WPilotAI\Tools\WordPress
 */

declare(strict_types=1);

namespace WPilotAI\Tools\WordPress;

use WPilotAI\Core\WPilotException;
use WPilotAI\Security\PermissionEngine;
use WPilotAI\Tools\ToolInterface;

final class GetPageTool implements ToolInterface {

	public function get_name(): string {
		return 'wordpress.get_page';
	}

	public function get_description(): string {
		return 'Fetch full details of a single WordPress page by ID including title, content, slug, and SEO metadata.';
	}

	public function get_input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'page_id' ),
			'properties'           => array(
				'page_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'WordPress post ID of the page.',
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
		return isset( $arguments['page_id'] ) && is_int( $arguments['page_id'] ) && $arguments['page_id'] > 0;
	}

	public function execute( array $arguments ): array {
		$page_id = (int) $arguments['page_id'];
		$page    = get_post( $page_id );
		if ( ! $page || 'page' !== $page->post_type ) {
			throw new WPilotException( 'wpilot_page_not_found', 'Requested WordPress page was not found.', 404 );
		}
		if ( ! current_user_can( 'edit_post', $page_id ) ) {
			throw new WPilotException( 'wpilot_forbidden', 'Current user cannot access this page.', 403 );
		}

		return array(
			'id'           => $page->ID,
			'title'        => $page->post_title,
			'slug'         => $page->post_name,
			'status'       => $page->post_status,
			'content'      => $page->post_content,
			'excerpt'      => $page->post_excerpt,
			'is_elementor' => 'builder' === get_post_meta( $page->ID, '_elementor_edit_mode', true ),
		);
	}

	public function verify( array $arguments, array $result ): bool {
		return isset( $result['id'] ) && (int) $result['id'] === (int) $arguments['page_id'];
	}
}
