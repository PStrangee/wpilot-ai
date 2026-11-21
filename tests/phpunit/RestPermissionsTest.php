<?php
declare(strict_types=1);

namespace WPilotAI\Tests;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WPTestState;
use WPilotAI\AI\ProviderInterface;
use WPilotAI\AI\ProviderRegistry;
use WPilotAI\Agent\Orchestrator;
use WPilotAI\Agent\TaskStateMachine;
use WPilotAI\Backup\BackupManager;
use WPilotAI\Core\Settings;
use WPilotAI\Logging\AuditLogger;
use WPilotAI\REST\RestController;
use WPilotAI\Security\PermissionEngine;
use WPilotAI\Tools\ArgumentValidator;
use WPilotAI\Tools\ToolRegistry;
use WPilotAI\Tools\WooCommerce\ListProductsTool;
use WPilotAI\Tools\WordPress\GetSiteInfoTool;
use WPilotAI\Tools\WordPress\ListPagesTool;

final class RestPermissionsTest extends TestCase {

	private Settings $settings;
	private PermissionEngine $permissions;
	private AuditLogger $audit;
	private BackupManager $backups;
	private ToolRegistry $tools;
	private ProviderRegistry $providers;
	private RestController $controller;

	protected function setUp(): void {
		WPTestState::reset();
		$this->settings = new Settings();
		$this->settings->update( array( 'allowed_roles' => array( 'administrator', 'editor' ) ) );
		$this->permissions = new PermissionEngine( $this->settings );
		$this->audit       = new AuditLogger();
		$this->backups     = new BackupManager( $this->audit );
		$this->tools       = new ToolRegistry( $this->permissions );
		$this->providers   = new ProviderRegistry();

		$this->tools->register( new GetSiteInfoTool() );
		$this->tools->register( new ListPagesTool() );
		$this->tools->register( new ListProductsTool() );

		$orchestrator = new Orchestrator(
			$this->providers,
			$this->tools,
			$this->permissions,
			$this->backups,
			$this->audit,
			new TaskStateMachine(),
			$this->settings,
			new ArgumentValidator()
		);
		$this->controller = new RestController(
			$orchestrator,
			$this->tools,
			$this->permissions,
			$this->backups,
			$this->audit,
			$this->settings
		);
	}

	public function test_unauthenticated_and_subscriber_are_blocked(): void {
		WPTestState::$current_user_id = 0;
		$anon_access = $this->controller->check_access();
		self::assertInstanceOf( WP_Error::class, $anon_access );
		self::assertSame( 403, $anon_access->get_error_data()['status'] );

		WPTestState::$current_user_id = 3; // Subscriber
		$sub_access = $this->controller->check_access();
		self::assertInstanceOf( WP_Error::class, $sub_access );
		self::assertSame( 403, $sub_access->get_error_data()['status'] );
	}

	public function test_allowed_editor_can_access_workspace_but_not_admin_endpoints(): void {
		WPTestState::$current_user_id = 2; // Editor
		self::assertTrue( $this->controller->check_access() );

		$admin_check = $this->controller->check_admin();
		self::assertInstanceOf( WP_Error::class, $admin_check );
		self::assertSame( 403, $admin_check->get_error_data()['status'] );

		// Non-admin rollback call must fail even if invoked directly on BackupManager.
		$rollback_res = $this->controller->handle_rollback( new WP_REST_Request( array( 'id' => 1 ) ) );
		self::assertInstanceOf( WP_Error::class, $rollback_res );
		self::assertSame( 403, $rollback_res->get_error_data()['status'] );
	}

	public function test_audit_logs_are_user_scoped_for_non_admins(): void {
		$this->audit->log( 1, 'wordpress.update_page', 'medium', 'completed', 'Admin audit record' );
		$this->audit->log( 2, 'wordpress.get_page', 'low', 'completed', 'Editor audit record' );

		WPTestState::$current_user_id = 2;
		$editor_logs = $this->controller->handle_audit_logs()->get_data()['logs'];
		self::assertCount( 1, $editor_logs );
		self::assertSame( 2, (int) $editor_logs[0]['user_id'] );

		WPTestState::$current_user_id = 1;
		$admin_logs = $this->controller->handle_audit_logs()->get_data()['logs'];
		self::assertCount( 2, $admin_logs );
	}

	public function test_unexpected_internal_errors_do_not_leak_sensitive_details(): void {
		$this->providers->register(
			new class() implements ProviderInterface {
				public function get_id(): string { return 'openai'; }
				public function get_label(): string { return 'Broken'; }
				public function get_models(): array { return array(); }
				public function is_configured(): bool { return true; }
				public function respond( array $request ): array {
					throw new \RuntimeException( 'SQLSTATE[42S02]: /var/www/secret/wp-config.php leaked' );
				}
			}
		);

		WPTestState::$current_user_id = 1;
		$res = $this->controller->handle_chat(
			new WP_REST_Request(
				array(
					'conversation_id' => 0,
					'messages'        => array( array( 'role' => 'user', 'content' => 'Trigger failure' ) ),
				)
			)
		);

		self::assertInstanceOf( WP_Error::class, $res );
		self::assertSame( 500, $res->get_error_data()['status'] );
		self::assertStringNotContainsString( 'SQLSTATE', $res->get_error_message() );
		self::assertStringNotContainsString( 'wp-config.php', $res->get_error_message() );
	}
}
