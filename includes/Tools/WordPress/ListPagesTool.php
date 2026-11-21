<?php
/**
 * WordPress List Pages Tool (`wordpress.list_pages`).
 *
 * @package WPilotAI\Tools\WordPress
 */

declare(strict_types=1);

namespace WPilotAI\Tools\WordPress;

use WPilotAI\Core\WPilotException;
use WPilotAI\Security\PermissionEngine;
use WPilotAI\Tools\ToolInterface;

final class ListPagesTool implements ToolInterface {

	public function get_name(): string {
		return 'wordpress.list_pages';
	}

	public function get_description(): string {
		return 'List WordPress pages with IDs, titles, slugs, statuses, Elementor status, and last modified timestamps.';
	}

	public function get_input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'status' => array(
					'type'        => 'string',
					'enum'        => array( 'publish', 'draft', 'pending', 'private', 'any' ),
					'description' => 'Optional status filter: publish, draft, pending, private, or any.',
				),
				'limit'  => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 50,
					'description' => 'Maximum pages to return (1-50).',
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
		if ( isset( $arguments['limit'] ) && ( ! is_int( $arguments['limit'] ) || $arguments['limit'] < 1 || $arguments['limit'] > 50 ) ) {
			return false;
		}
		if ( isset( $arguments['status'] ) && ! in_array( $arguments['status'], array( 'publish', 'draft', 'pending', 'private', 'any' ), true ) ) {
			return false;
		}
		return true;
	}

	public function execute( array $arguments ): array {
		if ( ! current_user_can( 'edit_pages' ) ) {
			throw new WPilotException( 'wpilot_forbidden', 'Current user lacks permission to list pages.', 403 );
		}

		$limit  = isset( $arguments['limit'] ) ? max( 1, min( 50, (int) $arguments['limit'] ) ) : 25;
		$status = isset( $arguments['status'] ) ? sanitize_key( (string) $arguments['status'] ) : 'any';

		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => $status,
				'posts_per_page' => $limit,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
			)
		);

		$items = array();
		foreach ( $pages as $page ) {
			if ( ! current_user_can( 'edit_post', $page->ID ) ) {
				continue;
			}
			$items[] = array(
				'id'           => $page->ID,
				'title'        => $page->post_title,
				'slug'         => $page->post_name,
				'status'       => $page->post_status,
				'is_elementor' => 'builder' === get_post_meta( $page->ID, '_elementor_edit_mode', true ),
				'modified_gmt' => $page->post_modified_gmt,
				'url'          => get_permalink( $page->ID ),
			);
		}

		return array(
			'count' => count( $items ),
			'pages' => $items,
		);
	}

	public function verify( array $arguments, array $result ): bool {
		return isset( $result['pages'] ) && is_array( $result['pages'] );
	}
}
