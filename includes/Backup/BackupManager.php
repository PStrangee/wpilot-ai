<?php
/**
 * Backup Snapshot & Verified Rollback Manager.
 *
 * Captures complete pre-mutation snapshots for WordPress posts/pages, Elementor
 * documents, SEO metadata, and attachment metadata, and verifies every field
 * after restoration.
 *
 * @package WPilotAI\Backup
 */

declare(strict_types=1);

namespace WPilotAI\Backup;

use WPilotAI\Core\WPilotException;
use WPilotAI\Logging\AuditLogger;
use WPilotAI\SEO\NativeSeoAdapter;
use WPilotAI\SEO\RankMathAdapter;
use WPilotAI\SEO\SeoAdapterInterface;
use WPilotAI\SEO\YoastAdapter;

final class BackupManager {

	private AuditLogger $audit;

	public function __construct( AuditLogger $audit ) {
		$this->audit = $audit;
	}

	/**
	 * Captures a pre-mutation snapshot for a WordPress post/page, Elementor document, or SEO/post metadata.
	 *
	 * @param array<string, mixed> $previous_state
	 * @param array<string, mixed> $proposed_state
	 */
	public function create_snapshot(
		string $resource_type,
		string $resource_id,
		string $tool_name,
		array $previous_state,
		array $proposed_state = array()
	): int {
		global $wpdb;
		$table = $wpdb->prefix . 'wpilot_backups';

		$previous_json = wp_json_encode( $previous_state );
		$proposed_json = wp_json_encode( $proposed_state );

		if ( ! is_string( $previous_json ) || ! is_string( $proposed_json ) ) {
			throw new WPilotException(
				'wpilot_backup_encode_failed',
				'Unable to serialize backup snapshot before mutation.',
				500
			);
		}

		$inserted = $wpdb->insert(
			$table,
			array(
				'user_id'        => get_current_user_id(),
				'resource_type'  => sanitize_key( $resource_type ),
				'resource_id'    => sanitize_text_field( $resource_id ),
				'tool_name'      => sanitize_text_field( $tool_name ),
				'previous_state' => $previous_json,
				'proposed_state' => $proposed_json,
			)
		);

		$backup_id = (int) $wpdb->insert_id;
		if ( false === $inserted || $backup_id <= 0 ) {
			throw new WPilotException(
				'wpilot_backup_write_failed',
				'Failed to persist backup snapshot before mutation. Aborting write operation.',
				500
			);
		}

		return $backup_id;
	}

	/**
	 * Restores a captured backup snapshot and verifies the persisted state after restoration.
	 *
	 * @return array{restored: bool, verified: bool, backup_id: int, resource_type: string, resource_id: string}
	 */
	public function rollback( int $backup_id ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new WPilotException(
				'wpilot_forbidden',
				'Administrator privileges are required to perform rollbacks.',
				403
			);
		}

		if ( $backup_id <= 0 ) {
			throw new WPilotException( 'wpilot_invalid_backup_id', 'Invalid backup ID.', 400 );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'wpilot_backups';
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $backup_id ), ARRAY_A );

		if ( ! is_array( $row ) ) {
			throw new WPilotException( 'wpilot_backup_not_found', 'Backup snapshot not found.', 404 );
		}

		if ( ! empty( $row['restored_at'] ) ) {
			throw new WPilotException(
				'wpilot_backup_already_restored',
				sprintf( 'Backup snapshot #%d has already been restored.', absint( $backup_id ) ),
				409
			);
		}

		$previous = json_decode( (string) $row['previous_state'], true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $previous ) || empty( $previous ) ) {
			throw new WPilotException( 'wpilot_backup_corrupted', 'Corrupted backup snapshot payload.', 500 );
		}

		$type = (string) $row['resource_type'];
		$id   = (int) $row['resource_id'];
		if ( $id <= 0 ) {
			throw new WPilotException( 'wpilot_backup_corrupted', 'Backup snapshot is missing a valid resource ID.', 500 );
		}

		if ( 'post' === $type ) {
			$this->rollback_post( $id, $previous );
		} elseif ( 'elementor' === $type ) {
			$this->rollback_elementor( $id, $previous );
		} elseif ( 'seo_meta' === $type ) {
			$this->rollback_seo_meta( $id, $previous );
		} elseif ( 'post_meta' === $type ) {
			$this->rollback_raw_meta( $id, $previous );
		} else {
			throw new WPilotException( 'wpilot_rollback_unsupported', 'Unsupported resource type for automatic rollback.', 400 );
		}

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET restored_at = %s WHERE id = %d AND restored_at IS NULL',
				$table,
				current_time( 'mysql', true ),
				$backup_id
			)
		);

		if ( false === $updated ) {
			throw new WPilotException(
				'wpilot_backup_status_update_failed',
				'Resource was restored, but the backup record timestamp could not be updated.',
				500
			);
		}

		if ( 1 !== (int) $updated ) {
			throw new WPilotException(
				'wpilot_backup_already_restored',
				sprintf( 'Backup snapshot #%d was already restored by another request.', absint( $backup_id ) ),
				409
			);
		}

		$this->audit->log(
			get_current_user_id(),
			'system.rollback',
			'medium',
			'rolled_back',
			sprintf( 'Restored and verified backup #%d for %s #%d.', $backup_id, $type, $id )
		);

		return array(
			'restored'      => true,
			'verified'      => true,
			'backup_id'     => $backup_id,
			'resource_type' => $type,
			'resource_id'   => (string) $id,
		);
	}

	/**
	 * Restores complete WordPress post state (including trashed posts, post_status,
	 * post_name, post_parent, and menu_order) and verifies every restored field.
	 *
	 * @param array<string, mixed> $previous
	 */
	private function rollback_post( int $post_id, array $previous ): void {
		$current = get_post( $post_id );
		if ( ! $current ) {
			throw new WPilotException( 'wpilot_target_missing', 'Target WordPress post no longer exists.', 404 );
		}

		$has_post_fields = isset( $previous['post_title'] )
			|| isset( $previous['post_content'] )
			|| isset( $previous['post_status'] )
			|| isset( $previous['post_name'] )
			|| isset( $previous['post_excerpt'] )
			|| isset( $previous['post_parent'] )
			|| isset( $previous['menu_order'] );

		if ( ! $has_post_fields ) {
			throw new WPilotException( 'wpilot_backup_corrupted', 'Post backup snapshot contains no restorable post fields.', 500 );
		}

		$target_status = isset( $previous['post_status'] ) && is_string( $previous['post_status'] ) && '' !== $previous['post_status']
			? sanitize_key( $previous['post_status'] )
			: (string) $current->post_status;

		// If the post is currently trashed and previous state was not trashed, untrash cleanly first.
		if ( 'trash' === $current->post_status && 'trash' !== $target_status ) {
			$untrashed = wp_untrash_post( $post_id );
			if ( ! $untrashed ) {
				throw new WPilotException( 'wpilot_rollback_failed', 'Failed to restore page from Trash.', 500 );
			}
		}

		$update = array(
			'ID'           => $post_id,
			'post_title'   => (string) ( $previous['post_title'] ?? $current->post_title ),
			'post_content' => (string) ( $previous['post_content'] ?? $current->post_content ),
			'post_excerpt' => (string) ( $previous['post_excerpt'] ?? $current->post_excerpt ),
			'post_status'  => $target_status,
		);

		if ( array_key_exists( 'post_name', $previous ) && is_string( $previous['post_name'] ) ) {
			$update['post_name'] = $previous['post_name'];
		}
		if ( array_key_exists( 'post_parent', $previous ) ) {
			$update['post_parent'] = (int) $previous['post_parent'];
		}
		if ( array_key_exists( 'menu_order', $previous ) ) {
			$update['menu_order'] = (int) $previous['menu_order'];
		}

		$updated = wp_update_post( $update, true );
		if ( is_wp_error( $updated ) ) {
			throw new WPilotException( 'wpilot_rollback_failed', 'Failed to restore WordPress post state.', 500 );
		}

		// Re-read and verify every restored post field against persisted WordPress values.
		$after = get_post( $post_id );
		if (
			! $after
			|| $after->post_status !== $target_status
			|| $after->post_title !== $update['post_title']
			|| $after->post_content !== $update['post_content']
			|| $after->post_excerpt !== $update['post_excerpt']
			|| ( array_key_exists( 'post_name', $update ) && (string) $after->post_name !== (string) $update['post_name'] )
			|| ( array_key_exists( 'post_parent', $update ) && (int) $after->post_parent !== (int) $update['post_parent'] )
			|| ( array_key_exists( 'menu_order', $update ) && (int) $after->menu_order !== (int) $update['menu_order'] )
		) {
			throw new WPilotException(
				'wpilot_rollback_verification_failed',
				'Post rollback completed but failed post-restoration state verification.',
				500
			);
		}
	}

	/**
	 * Restores complete Elementor document metadata and clears Elementor CSS cache, then verifies.
	 *
	 * @param array<string, mixed> $previous
	 */
	private function rollback_elementor( int $post_id, array $previous ): void {
		if ( ! get_post( $post_id ) ) {
			throw new WPilotException( 'wpilot_target_missing', 'Target Elementor page no longer exists.', 404 );
		}

		if ( ! array_key_exists( '_elementor_data', $previous ) || ! is_string( $previous['_elementor_data'] ) ) {
			throw new WPilotException( 'wpilot_backup_corrupted', 'Elementor backup is missing valid _elementor_data.', 500 );
		}

		$data_existed  = ! array_key_exists( '_elementor_data_exists', $previous ) || (bool) $previous['_elementor_data_exists'];
		$expected_data = $previous['_elementor_data'];

		$this->restore_single_meta(
			$post_id,
			'_elementor_data',
			$data_existed,
			$expected_data,
			true
		);

		if ( isset( $previous['meta'] ) && is_array( $previous['meta'] ) ) {
			foreach ( $previous['meta'] as $meta_key => $entry ) {
				if ( ! is_string( $meta_key ) || '' === $meta_key ) {
					continue;
				}
				if ( is_array( $entry ) && array_key_exists( 'exists', $entry ) ) {
					$this->restore_single_meta( $post_id, $meta_key, (bool) $entry['exists'], $entry['value'] ?? '', false );
				} else {
					$this->restore_single_meta( $post_id, $meta_key, true, $entry, false );
				}
			}
		}

		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}

		// Verify persisted Elementor JSON and existence state match the snapshot exactly.
		if ( ! $data_existed ) {
			if ( metadata_exists( 'post', $post_id, '_elementor_data' ) ) {
				throw new WPilotException(
					'wpilot_rollback_verification_failed',
					'Elementor rollback failed to remove previously absent _elementor_data.',
					500
				);
			}
			return;
		}

		$after = (string) get_post_meta( $post_id, '_elementor_data', true );
		if ( ! metadata_exists( 'post', $post_id, '_elementor_data' ) || $after !== $expected_data ) {
			throw new WPilotException(
				'wpilot_rollback_verification_failed',
				'Elementor rollback failed post-restoration verification.',
				500
			);
		}
	}

	/**
	 * Restores complete SEO metadata (supporting both raw_meta snapshots and adapter-keyed snapshots) and verifies.
	 *
	 * @param array<string, mixed> $previous
	 */
	private function rollback_seo_meta( int $post_id, array $previous ): void {
		if ( ! get_post( $post_id ) ) {
			throw new WPilotException( 'wpilot_target_missing', 'Target post for SEO rollback no longer exists.', 404 );
		}

		if ( isset( $previous['raw_meta'] ) && is_array( $previous['raw_meta'] ) ) {
			$this->rollback_raw_meta( $post_id, array( 'raw_meta' => $previous['raw_meta'] ) );
			return;
		}

		if ( isset( $previous['adapter'], $previous['meta'] ) && is_string( $previous['adapter'] ) && is_array( $previous['meta'] ) ) {
			$adapter  = $this->resolve_seo_adapter_by_id( $previous['adapter'] );
			$expected = array(
				'title'         => (string) ( $previous['meta']['title'] ?? '' ),
				'description'   => (string) ( $previous['meta']['description'] ?? '' ),
				'focus_keyword' => (string) ( $previous['meta']['focus_keyword'] ?? '' ),
			);
			$adapter->write_meta( $post_id, $expected );
			$after = $adapter->read_meta( $post_id );

			if (
				sanitize_text_field( $expected['title'] ) !== $after['title']
				|| sanitize_textarea_field( $expected['description'] ) !== $after['description']
				|| sanitize_text_field( $expected['focus_keyword'] ) !== $after['focus_keyword']
			) {
				throw new WPilotException(
					'wpilot_rollback_verification_failed',
					'SEO metadata rollback failed post-restoration verification.',
					500
				);
			}
			return;
		}

		if ( isset( $previous['meta'] ) && is_array( $previous['meta'] ) ) {
			$raw_map = array();
			foreach ( $previous['meta'] as $k => $v ) {
				if ( is_string( $k ) && '' !== $k ) {
					$raw_map[ $k ] = array(
						'exists' => true,
						'value'  => $v,
					);
				}
			}
			$this->rollback_raw_meta( $post_id, array( 'raw_meta' => $raw_map ) );
			return;
		}

		throw new WPilotException( 'wpilot_backup_corrupted', 'SEO backup snapshot is missing metadata payload.', 500 );
	}

	/**
	 * Restores arbitrary post meta keys preserving existence/deletion semantics, then verifies each key.
	 *
	 * @param array<string, mixed> $previous
	 */
	private function rollback_raw_meta( int $post_id, array $previous ): void {
		if ( ! get_post( $post_id ) ) {
			throw new WPilotException( 'wpilot_target_missing', 'Target post for metadata rollback no longer exists.', 404 );
		}

		$raw_meta = $previous['raw_meta'] ?? null;
		if ( ! is_array( $raw_meta ) || empty( $raw_meta ) ) {
			throw new WPilotException( 'wpilot_backup_corrupted', 'Metadata backup snapshot is empty or invalid.', 500 );
		}

		$restored_count = 0;
		foreach ( $raw_meta as $meta_key => $entry ) {
			if ( ! is_string( $meta_key ) || '' === $meta_key || ! is_array( $entry ) || ! array_key_exists( 'exists', $entry ) ) {
				continue;
			}
			$exists = (bool) $entry['exists'];
			$value  = $entry['value'] ?? '';
			$this->restore_single_meta( $post_id, $meta_key, $exists, $value, false );
			++$restored_count;
		}

		if ( 0 === $restored_count ) {
			throw new WPilotException( 'wpilot_backup_corrupted', 'Metadata backup snapshot contains no valid entries.', 500 );
		}
	}

	/**
	 * Restores a single post meta key (or deletes it if it did not previously exist) and verifies persistence.
	 *
	 * @param mixed $value
	 */
	private function restore_single_meta( int $post_id, string $meta_key, bool $existed, mixed $value, bool $slash ): void {
		if ( ! $existed ) {
			delete_post_meta( $post_id, $meta_key );
			if ( metadata_exists( 'post', $post_id, $meta_key ) ) {
				throw new WPilotException(
					'wpilot_rollback_verification_failed',
					sprintf( 'Failed to remove post meta "%s" during rollback.', esc_html( $meta_key ) ),
					500
				);
			}
			return;
		}

		$to_store = $slash && is_string( $value ) ? wp_slash( $value ) : $value;
		update_post_meta( $post_id, $meta_key, $to_store );

		$after = get_post_meta( $post_id, $meta_key, true );
		if ( ! metadata_exists( 'post', $post_id, $meta_key ) || $after !== $value ) {
			throw new WPilotException(
				'wpilot_rollback_verification_failed',
				sprintf( 'Post meta "%s" failed post-restoration verification.', esc_html( $meta_key ) ),
				500
			);
		}
	}

	private function resolve_seo_adapter_by_id( string $adapter_id ): SeoAdapterInterface {
		if ( 'yoast' === $adapter_id ) {
			return new YoastAdapter();
		}
		if ( 'rank_math' === $adapter_id ) {
			return new RankMathAdapter();
		}
		return new NativeSeoAdapter();
	}
}
