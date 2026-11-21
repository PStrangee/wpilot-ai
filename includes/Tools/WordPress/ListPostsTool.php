<?php
/**
 * WordPress List Posts Tool (`wordpress.list_posts`).
 *
 * @package WPilotAI\Tools\WordPress
 */

declare(strict_types=1);

namespace WPilotAI\Tools\WordPress;

use WPilotAI\Core\WPilotException;
use WPilotAI\Security\PermissionEngine;
use WPilotAI\Tools\ToolInterface;

final class ListPostsTool implements ToolInterface {

	public function get_name(): string {
		return 'wordpress.list_posts';
	}

	public function get_description(): string {
		return 'List recent WordPress blog posts with status, categories, and publication date.';
	}

	public function get_input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'limit' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 50,
				),
			),
		);
	}

	public function get_required_capability(): string {
		return 'edit_posts';
	}

	public function get_risk_level(): string {
		return PermissionEngine::RISK_LOW;
	}

	public function validate( array $arguments ): bool {
		return ! isset( $arguments['limit'] ) || ( is_int( $arguments['limit'] ) && $arguments['limit'] >= 1 && $arguments['limit'] <= 50 );
	}

	public function execute( array $arguments ): array {
		if ( ! current_user_can( 'edit_posts' ) ) {
			throw new WPilotException( 'wpilot_forbidden', 'Current user lacks permission to list posts.', 403 );
		}

		$limit = isset( $arguments['limit'] ) ? max( 1, min( 50, (int) $arguments['limit'] ) ) : 20;
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'any',
				'posts_per_page' => $limit,
			)
		);

		$items = array();
		foreach ( $posts as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			$items[] = array(
				'id'     => $post->ID,
				'title'  => $post->post_title,
				'status' => $post->post_status,
				'date'   => $post->post_date_gmt,
			);
		}

		return array(
			'count' => count( $items ),
			'posts' => $items,
		);
	}

	public function verify( array $arguments, array $result ): bool {
		return isset( $result['posts'] );
	}
}
