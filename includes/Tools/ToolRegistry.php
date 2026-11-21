<?php
/**
 * Central Tool Registry exporting OpenAI Responses API tool schemas.
 *
 * @package WPilotAI\Tools
 */

declare(strict_types=1);

namespace WPilotAI\Tools;

use WPilotAI\Security\PermissionEngine;

final class ToolRegistry {

	/** @var array<string, ToolInterface> */
	private array $tools = array();

	private PermissionEngine $permissions;

	public function __construct( PermissionEngine $permissions ) {
		$this->permissions = $permissions;
	}

	public function register( ToolInterface $tool ): void {
		$this->tools[ $tool->get_name() ] = $tool;
	}

	public function get( string $name ): ToolInterface {
		if ( ! isset( $this->tools[ $name ] ) ) {
			throw new \InvalidArgumentException( sprintf( 'Tool "%s" is not allowlisted or registered.', esc_html( $name ) ) );
		}
		return $this->tools[ $name ];
	}

	/**
	 * Exports tools formatted for OpenAI Responses API function calling,
	 * filtered to capabilities granted to the current WordPress user.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function export_openai_tools(): array {
		$exported = array();
		foreach ( $this->tools as $tool ) {
			if ( ! $this->permissions->can_execute_capability( $tool->get_required_capability() ) ) {
				continue;
			}
			$exported[] = array(
				'type'        => 'function',
				'name'        => $tool->get_name(),
				'description' => $tool->get_description(),
				'parameters'  => $tool->get_input_schema(),
			);
		}
		return $exported;
	}
}
