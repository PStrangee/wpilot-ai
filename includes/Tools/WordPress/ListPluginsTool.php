<?php
/**
 * WordPress List Plugins Tool (`wordpress.list_plugins`).
 *
 * @package WPilotAI\Tools\WordPress
 */

declare(strict_types=1);

namespace WPilotAI\Tools\WordPress;

use WPilotAI\Core\WPilotException;
use WPilotAI\Security\PermissionEngine;
use WPilotAI\Tools\ToolInterface;

final class ListPluginsTool implements ToolInterface {

	public function get_name(): string {
		return 'wordpress.list_plugins';
	}

	public function get_description(): string {
		return 'Inspect installed WordPress plugins, versions, and active/inactive state.';
	}

	public function get_input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => (object) array(),
		);
	}

	public function get_required_capability(): string {
		return 'activate_plugins';
	}

	public function get_risk_level(): string {
		return PermissionEngine::RISK_LOW;
	}

	public function validate( array $arguments ): bool {
		return empty( $arguments );
	}

	public function execute( array $arguments ): array {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			throw new WPilotException( 'wpilot_forbidden', 'Current user lacks permission to inspect plugins.', 403 );
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins = get_plugins();
		$active      = (array) get_option( 'active_plugins', array() );
		$items       = array();

		foreach ( $all_plugins as $slug => $meta ) {
			$items[] = array(
				'slug'    => $slug,
				'name'    => (string) ( $meta['Name'] ?? $slug ),
				'version' => (string) ( $meta['Version'] ?? '' ),
				'active'  => in_array( $slug, $active, true ),
			);
		}

		return array(
			'count'   => count( $items ),
			'plugins' => $items,
		);
	}

	public function verify( array $arguments, array $result ): bool {
		return isset( $result['plugins'] );
	}
}
