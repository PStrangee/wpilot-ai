<?php
/**
 * Targeted Elementor Widget Update Tool (`elementor.update_widget`).
 *
 * Patches only the specified widget ID while preserving every other container,
 * responsive rule, and third-party widget intact. Captures complete Elementor
 * metadata for verified rollback and verifies that the patched widget settings
 * were persisted accurately.
 *
 * @package WPilotAI\Tools\Elementor
 */

declare(strict_types=1);

namespace WPilotAI\Tools\Elementor;

use WPilotAI\Backup\BackupManager;
use WPilotAI\Core\WPilotException;
use WPilotAI\Security\PermissionEngine;
use WPilotAI\Tools\ToolInterface;

final class UpdateWidgetTool implements ToolInterface {

	private BackupManager $backups;

	public function __construct( BackupManager $backups ) {
		$this->backups = $backups;
	}

	public function get_name(): string {
		return 'elementor.update_widget';
	}

	public function get_description(): string {
		return 'Surgically patch settings on a single Elementor widget by ID while preserving the rest of the document tree.';
	}

	public function get_input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'page_id', 'widget_id', 'settings_patch' ),
			'properties'           => array(
				'page_id'        => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'widget_id'      => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 100,
				),
				'settings_patch' => array(
					'type' => 'object',
				),
			),
		);
	}

	public function get_required_capability(): string {
		return 'edit_pages';
	}

	public function get_risk_level(): string {
		return PermissionEngine::RISK_MEDIUM;
	}

	public function validate( array $arguments ): bool {
		return isset( $arguments['page_id'], $arguments['widget_id'], $arguments['settings_patch'] )
			&& is_int( $arguments['page_id'] )
			&& $arguments['page_id'] > 0
			&& is_string( $arguments['widget_id'] )
			&& '' !== trim( $arguments['widget_id'] )
			&& is_array( $arguments['settings_patch'] )
			&& ! empty( $arguments['settings_patch'] )
			&& ! array_is_list( $arguments['settings_patch'] );
	}

	public function execute( array $arguments ): array {
		$page_id = (int) $arguments['page_id'];
		$page    = get_post( $page_id );
		if ( ! $page ) {
			throw new WPilotException( 'wpilot_page_not_found', 'Target Elementor page does not exist.', 404 );
		}
		if ( ! current_user_can( 'edit_post', $page_id ) ) {
			throw new WPilotException( 'wpilot_forbidden', 'Current user cannot edit this Elementor page.', 403 );
		}

		$widget_id = sanitize_text_field( (string) $arguments['widget_id'] );
		$patch     = $this->sanitize_patch( (array) $arguments['settings_patch'] );

		$data_existed = metadata_exists( 'post', $page_id, '_elementor_data' );
		$raw          = (string) get_post_meta( $page_id, '_elementor_data', true );
		$tree         = json_decode( $raw, true );
		if ( ! is_array( $tree ) ) {
			throw new WPilotException( 'wpilot_elementor_invalid', 'Invalid or empty Elementor document JSON.', 400 );
		}

		$found = false;
		$tree  = $this->patch_node( $tree, $widget_id, $patch, $found );
		if ( ! $found ) {
			throw new WPilotException(
				'wpilot_widget_not_found',
				sprintf( 'Elementor widget "%s" not found on page #%d.', esc_html( $widget_id ), absint( $page_id ) ),
				404
			);
		}

		$encoded = wp_json_encode( $tree );
		if ( ! is_string( $encoded ) ) {
			throw new WPilotException( 'wpilot_elementor_encode_failed', 'Unable to encode updated Elementor document.', 500 );
		}

		$extra_meta = array();
		foreach ( array( '_elementor_edit_mode', '_elementor_template_type', '_elementor_version' ) as $meta_key ) {
			$exists                  = metadata_exists( 'post', $page_id, $meta_key );
			$extra_meta[ $meta_key ] = array(
				'exists' => $exists,
				'value'  => $exists ? get_post_meta( $page_id, $meta_key, true ) : '',
			);
		}

		$backup_id = $this->backups->create_snapshot(
			'elementor',
			(string) $page_id,
			$this->get_name(),
			array(
				'_elementor_data_exists' => $data_existed,
				'_elementor_data'        => $raw,
				'meta'                   => $extra_meta,
			),
			array(
				'widget_id' => $widget_id,
				'patch'     => $patch,
			)
		);

		update_post_meta( $page_id, '_elementor_data', wp_slash( $encoded ) );

		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}

		return array(
			'page_id'   => $page_id,
			'widget_id' => $widget_id,
			'backup_id' => $backup_id,
			'updated'   => true,
		);
	}

	public function verify( array $arguments, array $result ): bool {
		$page_id   = (int) $arguments['page_id'];
		$widget_id = sanitize_text_field( (string) $arguments['widget_id'] );
		$patch     = $this->sanitize_patch( (array) $arguments['settings_patch'] );

		$raw  = (string) get_post_meta( $page_id, '_elementor_data', true );
		$tree = json_decode( $raw, true );
		if ( ! is_array( $tree ) ) {
			return false;
		}

		$node = $this->find_node( $tree, $widget_id );
		if ( ! is_array( $node ) || ! isset( $node['settings'] ) || ! is_array( $node['settings'] ) ) {
			return false;
		}

		foreach ( $patch as $key => $expected_value ) {
			if ( ! array_key_exists( $key, $node['settings'] ) || $node['settings'][ $key ] !== $expected_value ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $patch
	 * @return array<string, mixed>
	 */
	private function sanitize_patch( array $patch ): array {
		$clean = array();
		foreach ( $patch as $key => $value ) {
			if ( ! is_string( $key ) || '' === $key ) {
				continue;
			}
			if ( is_string( $value ) ) {
				$clean[ $key ] = wp_kses_post( $value );
			} elseif ( is_array( $value ) ) {
				$clean[ $key ] = $this->sanitize_patch( $value );
			} elseif ( is_int( $value ) || is_float( $value ) || is_bool( $value ) ) {
				$clean[ $key ] = $value;
			}
		}
		return $clean;
	}

	/**
	 * @param array<int, mixed>    $nodes
	 * @param array<string, mixed> $patch
	 * @return array<int, mixed>
	 */
	private function patch_node( array $nodes, string $widget_id, array $patch, bool &$found ): array {
		foreach ( $nodes as $idx => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( (string) ( $node['id'] ?? '' ) === $widget_id ) {
				$existing                  = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array();
				$nodes[ $idx ]['settings'] = array_merge( $existing, $patch );
				$found                     = true;
				return $nodes;
			}
			if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
				$nodes[ $idx ]['elements'] = $this->patch_node( $node['elements'], $widget_id, $patch, $found );
				if ( $found ) {
					return $nodes;
				}
			}
		}
		return $nodes;
	}

	/**
	 * @param array<int, mixed> $nodes
	 * @return array<string, mixed>|null
	 */
	private function find_node( array $nodes, string $widget_id ): ?array {
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( (string) ( $node['id'] ?? '' ) === $widget_id ) {
				return $node;
			}
			if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
				$found = $this->find_node( $node['elements'], $widget_id );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}
}
