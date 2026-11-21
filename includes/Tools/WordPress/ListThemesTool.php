<?php
/**
 * WordPress List Themes Tool (`wordpress.list_themes`).
 *
 * @package WPilotAI\Tools\WordPress
 */

declare(strict_types=1);

namespace WPilotAI\Tools\WordPress;

use WPilotAI\Core\WPilotException;
use WPilotAI\Security\PermissionEngine;
use WPilotAI\Tools\ToolInterface;

final class ListThemesTool implements ToolInterface {

	public function get_name(): string {
		return 'wordpress.list_themes';
	}

	public function get_description(): string {
		return 'Inspect installed WordPress themes and highlight the active stylesheet.';
	}

	public function get_input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => (object) array(),
		);
	}

	public function get_required_capability(): string {
		return 'switch_themes';
	}

	public function get_risk_level(): string {
		return PermissionEngine::RISK_LOW;
	}

	public function validate( array $arguments ): bool {
		return empty( $arguments );
	}

	public function execute( array $arguments ): array {
		if ( ! current_user_can( 'switch_themes' ) ) {
			throw new WPilotException( 'wpilot_forbidden', 'Current user lacks permission to inspect themes.', 403 );
		}

		$active = get_stylesheet();
		$themes = array();

		foreach ( wp_get_themes() as $slug => $theme ) {
			$themes[] = array(
				'slug'    => $slug,
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
				'active'  => $slug === $active,
			);
		}

		return array(
			'count'  => count( $themes ),
			'themes' => $themes,
		);
	}

	public function verify( array $arguments, array $result ): bool {
		return isset( $result['themes'] );
	}
}
