<?php
declare(strict_types=1);

namespace WPilotAI\Tests;

use PHPUnit\Framework\TestCase;
use WPTestState;
use WPilotAI\Backup\BackupManager;
use WPilotAI\Core\WPilotException;
use WPilotAI\Logging\AuditLogger;
use WPilotAI\Tools\Elementor\UpdateWidgetTool;
use WPilotAI\Tools\SEO\UpdateMetadataTool;
use WPilotAI\Tools\WordPress\DeletePageTool;
use WPilotAI\Tools\WordPress\UpdatePageTool;

final class BackupRollbackTest extends TestCase {

	private BackupManager $backups;

	protected function setUp(): void {
		WPTestState::reset();
		$this->backups = new BackupManager( new AuditLogger() );
	}

	public function test_post_update_and_rollback_preserves_and_verifies_every_field(): void {
		WPTestState::seed_post(
			12,
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_name'    => 'landing-v1',
				'post_title'   => 'Original Landing',
				'post_content' => '<p>Original copy</p>',
				'post_excerpt' => 'Original excerpt',
				'post_parent'  => 5,
				'menu_order'   => 7,
			)
		);

		$tool   = new UpdatePageTool( $this->backups );
		$result = $tool->execute(
			array(
				'page_id' => 12,
				'title'   => 'Updated Landing',
				'content' => '<p>Updated copy</p>',
			)
		);
		self::assertTrue( $tool->verify( array( 'page_id' => 12, 'title' => 'Updated Landing', 'content' => '<p>Updated copy</p>' ), $result ) );
		self::assertSame( 'Updated Landing', get_post( 12 )->post_title );

		// Simulate drift across status, slug, parent, and menu_order before rollback.
		WPTestState::$posts[12]->post_status = 'publish';
		WPTestState::$posts[12]->post_name   = 'landing-drifted';
		WPTestState::$posts[12]->post_parent = 99;
		WPTestState::$posts[12]->menu_order  = 42;

		$rolled = $this->backups->rollback( (int) $result['backup_id'] );
		self::assertTrue( $rolled['restored'] );
		self::assertTrue( $rolled['verified'] );

		$after = get_post( 12 );
		self::assertSame( 'draft', $after->post_status );
		self::assertSame( 'Original Landing', $after->post_title );
		self::assertSame( '<p>Original copy</p>', $after->post_content );
		self::assertSame( 'Original excerpt', $after->post_excerpt );
		self::assertSame( 'landing-v1', $after->post_name );
		self::assertSame( 5, (int) $after->post_parent );
		self::assertSame( 7, (int) $after->menu_order );
	}

	public function test_delete_page_and_rollback_restores_trashed_page_and_verifies(): void {
		WPTestState::seed_post(
			24,
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_name'    => 'about-us',
				'post_title'   => 'About Us',
				'post_content' => '<p>Company story</p>',
				'post_parent'  => 2,
				'menu_order'   => 3,
			)
		);

		$delete_tool = new DeletePageTool( $this->backups );
		$res         = $delete_tool->execute( array( 'page_id' => 24 ) );
		self::assertTrue( $delete_tool->verify( array( 'page_id' => 24 ), $res ) );
		self::assertSame( 'trash', get_post( 24 )->post_status );

		$rolled = $this->backups->rollback( (int) $res['backup_id'] );
		self::assertTrue( $rolled['restored'] );
		self::assertTrue( $rolled['verified'] );

		$restored = get_post( 24 );
		self::assertSame( 'publish', $restored->post_status );
		self::assertSame( 'about-us', $restored->post_name );
		self::assertSame( 'About Us', $restored->post_title );
		self::assertSame( 2, (int) $restored->post_parent );
		self::assertSame( 3, (int) $restored->menu_order );
	}

	public function test_repeated_rollback_attempt_is_rejected_with_conflict(): void {
		WPTestState::seed_post(
			28,
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_name'    => 'pricing',
				'post_title'   => 'Pricing v1',
				'post_content' => '<p>Initial pricing</p>',
			)
		);

		$tool = new UpdatePageTool( $this->backups );
		$res  = $tool->execute( array( 'page_id' => 28, 'title' => 'Pricing v2' ) );
		$bid  = (int) $res['backup_id'];

		$first = $this->backups->rollback( $bid );
		self::assertTrue( $first['restored'] );

		try {
			$this->backups->rollback( $bid );
			self::fail( 'Expected 409 conflict on repeated rollback attempt.' );
		} catch ( WPilotException $e ) {
			self::assertSame( 'wpilot_backup_already_restored', $e->get_error_code() );
			self::assertSame( 409, $e->get_http_status() );
		}
	}

	public function test_rollback_rejects_missing_target_post(): void {
		$backup_id = $this->backups->create_snapshot(
			'post',
			'9999',
			'wordpress.update_page',
			array(
				'post_title'   => 'Deleted Forever',
				'post_content' => '<p>Gone</p>',
				'post_status'  => 'publish',
				'post_name'    => 'deleted-forever',
				'post_parent'  => 0,
				'menu_order'   => 0,
			)
		);

		try {
			$this->backups->rollback( $backup_id );
			self::fail( 'Expected 404 when target post no longer exists.' );
		} catch ( WPilotException $e ) {
			self::assertSame( 'wpilot_target_missing', $e->get_error_code() );
			self::assertSame( 404, $e->get_http_status() );
		}
	}

	public function test_rollback_rejects_corrupted_snapshots(): void {
		global $wpdb;
		WPTestState::seed_post( 61, array( 'post_type' => 'page', 'post_title' => 'Valid Page' ) );
		$table = $wpdb->prefix . 'wpilot_backups';

		// 1. Malformed JSON in previous_state.
		$wpdb->insert(
			$table,
			array(
				'user_id'        => 1,
				'resource_type'  => 'post',
				'resource_id'    => '61',
				'tool_name'      => 'wordpress.update_page',
				'previous_state' => '{corrupted-json',
				'proposed_state' => '{}',
			)
		);
		$corrupt_json_id = (int) $wpdb->insert_id;

		try {
			$this->backups->rollback( $corrupt_json_id );
			self::fail( 'Expected corrupted snapshot rejection for malformed JSON.' );
		} catch ( WPilotException $e ) {
			self::assertSame( 'wpilot_backup_corrupted', $e->get_error_code() );
		}

		// 2. Empty object snapshot with no restorable post fields.
		$wpdb->insert(
			$table,
			array(
				'user_id'        => 1,
				'resource_type'  => 'post',
				'resource_id'    => '61',
				'tool_name'      => 'wordpress.update_page',
				'previous_state' => '{"unrelated":"value"}',
				'proposed_state' => '{}',
			)
		);
		$empty_fields_id = (int) $wpdb->insert_id;

		try {
			$this->backups->rollback( $empty_fields_id );
			self::fail( 'Expected corrupted snapshot rejection when no restorable post fields exist.' );
		} catch ( WPilotException $e ) {
			self::assertSame( 'wpilot_backup_corrupted', $e->get_error_code() );
		}

		// 3. Corrupted Elementor snapshot missing _elementor_data.
		$elementor_bad_id = $this->backups->create_snapshot(
			'elementor',
			'61',
			'elementor.update_widget',
			array( 'meta' => array() )
		);
		try {
			$this->backups->rollback( $elementor_bad_id );
			self::fail( 'Expected corrupted snapshot rejection for missing _elementor_data.' );
		} catch ( WPilotException $e ) {
			self::assertSame( 'wpilot_backup_corrupted', $e->get_error_code() );
		}

		// 4. Corrupted SEO snapshot with empty raw_meta.
		$seo_bad_id = $this->backups->create_snapshot(
			'seo_meta',
			'61',
			'seo.update_metadata',
			array( 'raw_meta' => array() )
		);
		try {
			$this->backups->rollback( $seo_bad_id );
			self::fail( 'Expected corrupted snapshot rejection for empty SEO raw_meta.' );
		} catch ( WPilotException $e ) {
			self::assertSame( 'wpilot_backup_corrupted', $e->get_error_code() );
		}
	}

	public function test_rollback_detects_post_restoration_verification_mismatch_on_slug_parent_and_order(): void {
		WPTestState::seed_post(
			62,
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_name'    => 'original-slug',
				'post_title'   => 'Original Title',
				'post_content' => '<p>Body</p>',
				'post_excerpt' => '',
				'post_parent'  => 4,
				'menu_order'   => 9,
			)
		);

		$backup_id = $this->backups->create_snapshot(
			'post',
			'62',
			'wordpress.update_page',
			array(
				'post_status'  => 'publish',
				'post_name'    => 'original-slug',
				'post_title'   => 'Original Title',
				'post_content' => '<p>Body</p>',
				'post_excerpt' => '',
				'post_parent'  => 4,
				'menu_order'   => 9,
			)
		);

		// Simulate wp_update_post silently failing to restore menu_order/post_name.
		WPTestState::$override_next_wp_update_fields = array(
			'post_name'  => 'wrong-slug-after-write',
			'menu_order' => 999,
		);

		try {
			$this->backups->rollback( $backup_id );
			self::fail( 'Expected verification failure when post_name or menu_order did not match snapshot.' );
		} catch ( WPilotException $e ) {
			self::assertSame( 'wpilot_rollback_verification_failed', $e->get_error_code() );
		}
	}

	public function test_elementor_update_and_rollback_restores_complete_document_and_meta(): void {
		WPTestState::seed_post( 30, array( 'post_type' => 'page' ) );
		$original_tree = array(
			array(
				'id'       => 'sec-1',
				'elType'   => 'container',
				'elements' => array(
					array(
						'id'         => 'hero-h1',
						'elType'     => 'widget',
						'widgetType' => 'heading',
						'settings'   => array( 'title' => 'Old Hero', 'align' => 'left' ),
					),
				),
			),
		);
		$original_json = (string) wp_json_encode( $original_tree );
		update_post_meta( 30, '_elementor_data', wp_slash( $original_json ) );
		update_post_meta( 30, '_elementor_edit_mode', 'builder' );
		update_post_meta( 30, '_elementor_version', '3.25.4' );
		// Intentionally omit _elementor_template_type to test absence restoration.

		$tool = new UpdateWidgetTool( $this->backups );
		$res  = $tool->execute(
			array(
				'page_id'        => 30,
				'widget_id'      => 'hero-h1',
				'settings_patch' => array( 'title' => 'New Hero' ),
			)
		);

		self::assertStringContainsString( 'New Hero', (string) get_post_meta( 30, '_elementor_data', true ) );
		// Simulate extra Elementor meta drift added after snapshot.
		update_post_meta( 30, '_elementor_template_type', 'wp-page' );
		update_post_meta( 30, '_elementor_version', '3.26.0' );

		$rolled = $this->backups->rollback( (int) $res['backup_id'] );
		self::assertTrue( $rolled['restored'] );
		self::assertTrue( $rolled['verified'] );
		self::assertSame( $original_json, (string) get_post_meta( 30, '_elementor_data', true ) );
		self::assertSame( 'builder', (string) get_post_meta( 30, '_elementor_edit_mode', true ) );
		self::assertSame( '3.25.4', (string) get_post_meta( 30, '_elementor_version', true ) );
		self::assertFalse( metadata_exists( 'post', 30, '_elementor_template_type' ) );
	}

	public function test_seo_update_and_rollback_restores_existing_and_removes_previously_absent_meta(): void {
		WPTestState::seed_post( 40, array( 'post_type' => 'page' ) );
		update_post_meta( 40, 'rank_math_title', 'Original Rank Math Title' );
		// Note: rank_math_description and rank_math_focus_keyword intentionally do NOT exist yet.

		$tool = new UpdateMetadataTool( $this->backups );
		$res  = $tool->execute(
			array(
				'page_id'       => 40,
				'title'         => 'Improved SEO Title for Nordic Studio',
				'description'   => 'New Rank Math meta description.',
				'focus_keyword' => 'nordic lighting',
			)
		);

		self::assertSame( 'Improved SEO Title for Nordic Studio', get_post_meta( 40, 'rank_math_title', true ) );
		self::assertTrue( metadata_exists( 'post', 40, 'rank_math_description' ) );
		self::assertTrue( metadata_exists( 'post', 40, 'rank_math_focus_keyword' ) );

		$rolled = $this->backups->rollback( (int) $res['backup_id'] );
		self::assertTrue( $rolled['restored'] );
		self::assertTrue( $rolled['verified'] );

		self::assertSame( 'Original Rank Math Title', get_post_meta( 40, 'rank_math_title', true ) );
		self::assertFalse( metadata_exists( 'post', 40, 'rank_math_description' ) );
		self::assertFalse( metadata_exists( 'post', 40, 'rank_math_focus_keyword' ) );
	}

	public function test_handles_failed_database_untrash_and_post_operations_safely(): void {
		global $wpdb;
		WPTestState::seed_post( 50, array( 'post_type' => 'page', 'post_title' => 'Safe Page' ) );

		// 1. Failed backup DB insert must abort mutation before writing to WordPress.
		$wpdb->fail_next_insert = true;
		$tool = new UpdatePageTool( $this->backups );
		try {
			$tool->execute( array( 'page_id' => 50, 'title' => 'Should Not Save' ) );
			self::fail( 'Expected exception when backup insert fails.' );
		} catch ( WPilotException $e ) {
			self::assertSame( 'wpilot_backup_write_failed', $e->get_error_code() );
		}
		self::assertSame( 'Safe Page', get_post( 50 )->post_title );

		// 2. Failed wp_update_post during rollback must fail cleanly without marking restored_at.
		$backup_id = $this->backups->create_snapshot(
			'post',
			'50',
			'wordpress.update_page',
			array(
				'post_title'   => 'Safe Page',
				'post_status'  => 'publish',
				'post_content' => '<p>Hello world</p>',
				'post_excerpt' => '',
				'post_name'    => 'page-50',
				'post_parent'  => 0,
				'menu_order'   => 0,
			)
		);
		WPTestState::$fail_next_wp_update_post = true;

		try {
			$this->backups->rollback( $backup_id );
			self::fail( 'Expected rollback failure when wp_update_post returns WP_Error.' );
		} catch ( WPilotException $e ) {
			self::assertSame( 'wpilot_rollback_failed', $e->get_error_code() );
		}

		// Because the failed rollback did NOT set restored_at, retrying after recovery succeeds.
		$retried = $this->backups->rollback( $backup_id );
		self::assertTrue( $retried['restored'] );

		// 3. Failed wp_untrash_post on trashed page must abort safely.
		WPTestState::seed_post( 51, array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Trashed Page' ) );
		$del = ( new DeletePageTool( $this->backups ) )->execute( array( 'page_id' => 51 ) );
		WPTestState::$fail_next_wp_untrash_post = true;
		try {
			$this->backups->rollback( (int) $del['backup_id'] );
			self::fail( 'Expected rollback failure when wp_untrash_post returns false.' );
		} catch ( WPilotException $e ) {
			self::assertSame( 'wpilot_rollback_failed', $e->get_error_code() );
		}
	}
}
