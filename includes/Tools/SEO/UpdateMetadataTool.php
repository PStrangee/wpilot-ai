<?php
/**
 * SEO Metadata Update Tool (`seo.update_metadata`) with Lossless Backup Snapshot & Verification.
 *
 * @package WPilotAI\Tools\SEO
 */

declare(strict_types=1);

namespace WPilotAI\Tools\SEO;

use WPilotAI\Backup\BackupManager;
use WPilotAI\Core\WPilotException;
use WPilotAI\SEO\NativeSeoAdapter;
use WPilotAI\SEO\RankMathAdapter;
use WPilotAI\SEO\SeoAdapterInterface;
use WPilotAI\SEO\YoastAdapter;
use WPilotAI\Security\PermissionEngine;
use WPilotAI\Tools\ToolInterface;

final class UpdateMetadataTool implements ToolInterface {

	private BackupManager $backups;

	public function __construct( BackupManager $backups ) {
		$this->backups = $backups;
	}

	public function get_name(): string {
		return 'seo.update_metadata';
	}

	public function get_description(): string {
		return 'Update SEO title, meta description, and focus keyword via the active SEO adapter (Yoast, Rank Math, or Native) with complete rollback backup.';
	}

	public function get_input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'page_id' ),
			'properties'           => array(
				'page_id'       => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'title'         => array(
					'type'      => 'string',
					'maxLength' => 300,
				),
				'description'   => array(
					'type'      => 'string',
					'maxLength' => 500,
				),
				'focus_keyword' => array(
					'type'      => 'string',
					'maxLength' => 200,
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
		if ( ! isset( $arguments['page_id'] ) || ! is_int( $arguments['page_id'] ) || $arguments['page_id'] <= 0 ) {
			return false;
		}
		return isset( $arguments['title'] ) || isset( $arguments['description'] ) || isset( $arguments['focus_keyword'] );
	}

	public function execute( array $arguments ): array {
		$page_id = (int) $arguments['page_id'];
		$post    = get_post( $page_id );
		if ( ! $post ) {
			throw new WPilotException( 'wpilot_page_not_found', 'Target page not found for SEO update.', 404 );
		}
		if ( ! current_user_can( 'edit_post', $page_id ) ) {
			throw new WPilotException( 'wpilot_forbidden', 'Current user cannot edit SEO metadata for this page.', 403 );
		}

		$adapter  = $this->resolve_adapter();
		$previous = $adapter->read_meta( $page_id );

		$raw_meta = array();
		foreach ( $adapter->get_meta_keys() as $meta_key ) {
			$exists                = metadata_exists( 'post', $page_id, $meta_key );
			$raw_meta[ $meta_key ] = array(
				'exists' => $exists,
				'value'  => $exists ? get_post_meta( $page_id, $meta_key, true ) : '',
			);
		}

		$backup_id = $this->backups->create_snapshot(
			'seo_meta',
			(string) $page_id,
			$this->get_name(),
			array(
				'adapter'  => $adapter->get_id(),
				'meta'     => $previous,
				'raw_meta' => $raw_meta,
			),
			$arguments
		);

		$adapter->write_meta(
			$page_id,
			array(
				'title'         => isset( $arguments['title'] ) ? (string) $arguments['title'] : $previous['title'],
				'description'   => isset( $arguments['description'] ) ? (string) $arguments['description'] : $previous['description'],
				'focus_keyword' => isset( $arguments['focus_keyword'] ) ? (string) $arguments['focus_keyword'] : $previous['focus_keyword'],
			)
		);

		return array(
			'page_id'   => $page_id,
			'adapter'   => $adapter->get_id(),
			'backup_id' => $backup_id,
			'updated'   => true,
		);
	}

	public function verify( array $arguments, array $result ): bool {
		$adapter = $this->resolve_adapter();
		$after   = $adapter->read_meta( (int) $arguments['page_id'] );
		if ( isset( $arguments['title'] ) && sanitize_text_field( (string) $arguments['title'] ) !== $after['title'] ) {
			return false;
		}
		if ( isset( $arguments['description'] ) && sanitize_textarea_field( (string) $arguments['description'] ) !== $after['description'] ) {
			return false;
		}
		if ( isset( $arguments['focus_keyword'] ) && sanitize_text_field( (string) $arguments['focus_keyword'] ) !== $after['focus_keyword'] ) {
			return false;
		}
		return true;
	}

	private function resolve_adapter(): SeoAdapterInterface {
		foreach ( array( new YoastAdapter(), new RankMathAdapter(), new NativeSeoAdapter() ) as $adapter ) {
			if ( $adapter->is_active() ) {
				return $adapter;
			}
		}
		return new NativeSeoAdapter();
	}
}
