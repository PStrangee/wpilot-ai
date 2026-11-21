<?php
/**
 * Elementor Document Inspection Tool (`elementor.inspect_page`).
 *
 * Preserves unknown widgets and verifies Elementor version compatibility.
 *
 * @package WPilotAI\Tools\Elementor
 */

declare(strict_types=1);

namespace WPilotAI\Tools\Elementor;

use WPilotAI\Core\WPilotException;
use WPilotAI\Security\PermissionEngine;
use WPilotAI\Tools\ToolInterface;

final class InspectPageTool implements ToolInterface {

	public function get_name(): string {
		return 'elementor.inspect_page';
	}

	public function get_description(): string {
		return 'Inspect an Elementor page container/widget tree without modifying unsupported elements.';
	}

	public function get_input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'page_id' ),
			'properties'           => array(
				'page_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
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
		if ( ! current_user_can( 'edit_post', $page_id ) ) {
			throw new WPilotException( 'wpilot_forbidden', 'Current user cannot inspect this page.', 403 );
		}

		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			return array(
				'available' => false,
				'message'   => 'Elementor plugin is not active on this WordPress site.',
			);
		}

		$raw  = (string) get_post_meta( $page_id, '_elementor_data', true );
		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			return array(
				'available' => true,
				'page_id'   => $page_id,
				'elements'  => array(),
			);
		}

		return array(
			'available'         => true,
			'elementor_version' => ELEMENTOR_VERSION,
			'page_id'           => $page_id,
			'elements'          => $this->summarize_tree( $data ),
		);
	}

	public function verify( array $arguments, array $result ): bool {
		return isset( $result['available'] );
	}

	/**
	 * @param array<int, mixed> $nodes
	 * @return array<int, array<string, mixed>>
	 */
	private function summarize_tree( array $nodes ): array {
		$out = array();
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$out[] = array(
				'id'         => (string) ( $node['id'] ?? '' ),
				'elType'     => (string) ( $node['elType'] ?? 'unknown' ),
				'widgetType' => (string) ( $node['widgetType'] ?? '' ),
				'settings'   => isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array(),
				'elements'   => isset( $node['elements'] ) && is_array( $node['elements'] ) ? $this->summarize_tree( $node['elements'] ) : array(),
			);
		}
		return $out;
	}
}
