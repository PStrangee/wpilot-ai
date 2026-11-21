<?php
/**
 * WordPress Get Menus Tool (`wordpress.get_menus`).
 *
 * @package WPilotAI\Tools\WordPress
 */

declare(strict_types=1);

namespace WPilotAI\Tools\WordPress;

use WPilotAI\Core\WPilotException;
use WPilotAI\Security\PermissionEngine;
use WPilotAI\Tools\ToolInterface;

final class GetMenusTool implements ToolInterface {

	public function get_name(): string {
		return 'wordpress.get_menus';
	}

	public function get_description(): string {
		return 'Inspect registered WordPress navigation menus and their menu items.';
	}

	public function get_input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => (object) array(),
		);
	}

	public function get_required_capability(): string {
		return 'edit_theme_options';
	}

	public function get_risk_level(): string {
		return PermissionEngine::RISK_LOW;
	}

	public function validate( array $arguments ): bool {
		return empty( $arguments );
	}

	public function execute( array $arguments ): array {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			throw new WPilotException( 'wpilot_forbidden', 'Current user lacks permission to inspect navigation menus.', 403 );
		}

		$menus = wp_get_nav_menus();
		$out   = array();

		foreach ( $menus as $menu ) {
			$items = wp_get_nav_menu_items( $menu->term_id );
			$links = array();
			if ( is_array( $items ) ) {
				foreach ( $items as $item ) {
					$links[] = array(
						'id'    => (int) $item->ID,
						'title' => (string) $item->title,
						'url'   => (string) $item->url,
					);
				}
			}
			$out[] = array(
				'id'    => (int) $menu->term_id,
				'name'  => (string) $menu->name,
				'slug'  => (string) $menu->slug,
				'items' => $links,
			);
		}

		return array(
			'count' => count( $out ),
			'menus' => $out,
		);
	}

	public function verify( array $arguments, array $result ): bool {
		return isset( $result['menus'] );
	}
}
