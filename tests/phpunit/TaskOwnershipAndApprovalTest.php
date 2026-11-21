<?php
declare(strict_types=1);

namespace WPilotAI\Tests;

use PHPUnit\Framework\TestCase;
use WP_REST_Request;
use WPTestState;
use WPilotAI\AI\ProviderInterface;
use WPilotAI\AI\ProviderRegistry;
use WPilotAI\Agent\Orchestrator;
use WPilotAI\Agent\TaskStateMachine;
use WPilotAI\Backup\BackupManager;
use WPilotAI\Core\Settings;
use WPilotAI\Core\WPilotException;
use WPilotAI\Logging\AuditLogger;
use WPilotAI\REST\RestController;
use WPilotAI\Security\PermissionEngine;
use WPilotAI\Tools\ArgumentValidator;
use WPilotAI\Tools\ToolInterface;
use WPilotAI\Tools\ToolRegistry;
use WPilotAI\Tools\WordPress\UpdatePageTool;

final class TaskOwnershipAndApprovalTest extends TestCase {

	private Settings $settings;
	private PermissionEngine $permissions;
	private AuditLogger $audit;
	private BackupManager $backups;
	private ToolRegistry $tools;
	private ProviderRegistry $providers;

	protected function setUp(): void {
		WPTestState::reset();
		$this->settings    = new Settings();
		$this->settings->update( array( 'allowed_roles' => array( 'administrator', 'editor' ) ) );
		$this->permissions = new PermissionEngine( $this->settings );
		$this->audit       = new AuditLogger();
		$this->backups     = new BackupManager( $this->audit );
		$this->tools       = new ToolRegistry( $this->permissions );
		$this->providers   = new ProviderRegistry();
	}

	public function test_list_tasks_returns_only_current_users_tasks(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'wpilot_tasks';

		$wpdb->insert(
			$table,
			array(
				'conversation_id' => 1,
				'user_id'         => 1,
				'tool_name'       => 'wordpress.update_page',
				'risk_level'      => 'medium',
				'status'          => TaskStateMachine::STATE_AWAITING_APPROVAL,
				'arguments'       => '{"page_id":12,"title":"Admin Title"}',
			)
		);
		$wpdb->insert(
			$table,
			array(
				'conversation_id' => 2,
				'user_id'         => 2,
				'tool_name'       => 'wordpress.update_page',
				'risk_level'      => 'medium',
				'status'          => TaskStateMachine::STATE_AWAITING_APPROVAL,
				'arguments'       => '{"page_id":12,"title":"Editor Title"}',
			)
		);

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
		$controller = new RestController( $orchestrator, $this->tools, $this->permissions, $this->backups, $this->audit, $this->settings );

		WPTestState::$current_user_id = 2;
		$res   = $controller->handle_list_tasks();
		$tasks = $res->get_data()['tasks'];

		self::assertCount( 1, $tasks );
		self::assertSame( 2, (int) $tasks[0]['user_id'] );
	}

	public function test_blocks_unauthorized_approval_and_cancel_of_other_users_task(): void {
		global $wpdb;
		WPTestState::seed_post( 12, array( 'post_title' => 'Original Home' ) );
		$this->tools->register( new UpdatePageTool( $this->backups ) );

		$wpdb->insert(
			$wpdb->prefix . 'wpilot_tasks',
			array(
				'conversation_id' => 1,
				'user_id'         => 1,
				'tool_name'       => 'wordpress.update_page',
				'risk_level'      => 'medium',
				'status'          => TaskStateMachine::STATE_AWAITING_APPROVAL,
				'arguments'       => '{"page_id":12,"title":"Hijacked"}',
			)
		);
		$task_id = (int) $wpdb->insert_id;

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

		WPTestState::$current_user_id = 2;

		try {
			$orchestrator->approve_task( $task_id );
			self::fail( 'Expected 403 WPilotException when user #2 tries to approve user #1 task.' );
		} catch ( WPilotException $e ) {
			self::assertSame( 403, $e->get_http_status() );
			self::assertSame( 'wpilot_forbidden', $e->get_error_code() );
		}

		try {
			$orchestrator->cancel_task( $task_id );
			self::fail( 'Expected 403 WPilotException when user #2 tries to cancel user #1 task.' );
		} catch ( WPilotException $e ) {
			self::assertSame( 403, $e->get_http_status() );
		}

		self::assertSame( 'Original Home', get_post( 12 )->post_title );
	}

	public function test_prevents_duplicate_approval_and_double_execution(): void {
		global $wpdb;
		$executions = 0;

		$counting_tool = new class( $executions ) implements ToolInterface {
			private int $count_ref;
			public function __construct( int &$count ) { $this->count_ref = &$count; }
			public function get_name(): string { return 'test.write_once'; }
			public function get_description(): string { return 'Counts executions.'; }
			public function get_input_schema(): array {
				return array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'target_id' ),
					'properties'           => array( 'target_id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
				);
			}
			public function get_required_capability(): string { return 'edit_pages'; }
			public function get_risk_level(): string { return PermissionEngine::RISK_MEDIUM; }
			public function validate( array $arguments ): bool { return ( $arguments['target_id'] ?? 0 ) > 0; }
			public function execute( array $arguments ): array {
				++$this->count_ref;
				return array( 'ok' => true, 'runs' => $this->count_ref );
			}
			public function verify( array $arguments, array $result ): bool { return ! empty( $result['ok'] ); }
		};

		$this->tools->register( $counting_tool );
		$wpdb->insert(
			$wpdb->prefix . 'wpilot_tasks',
			array(
				'conversation_id' => 1,
				'user_id'         => 1,
				'tool_name'       => 'test.write_once',
				'risk_level'      => 'medium',
				'status'          => TaskStateMachine::STATE_AWAITING_APPROVAL,
				'arguments'       => '{"target_id":5}',
			)
		);
		$task_id = (int) $wpdb->insert_id;

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

		$first = $orchestrator->approve_task( $task_id );
		self::assertSame( TaskStateMachine::STATE_COMPLETED, $first['status'] );
		self::assertSame( 1, $executions );

		try {
			$orchestrator->approve_task( $task_id );
			self::fail( 'Expected 409 conflict on duplicate approval.' );
		} catch ( WPilotException $e ) {
			self::assertSame( 409, $e->get_http_status() );
			self::assertSame( 'wpilot_task_conflict', $e->get_error_code() );
		}

		self::assertSame( 1, $executions );
	}

	public function test_blocks_cross_user_conversation_read_and_write(): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'wpilot_conversations',
			array(
				'user_id'  => 1,
				'title'    => 'Admin Private Session',
				'provider' => 'openai',
				'model'    => 'gpt-4.1',
			)
		);
		$conv_id = (int) $wpdb->insert_id;

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
		$controller = new RestController( $orchestrator, $this->tools, $this->permissions, $this->backups, $this->audit, $this->settings );

		WPTestState::$current_user_id = 2;

		$read_res = $controller->handle_list_conversation_messages( new WP_REST_Request( array( 'id' => $conv_id ) ) );
		self::assertInstanceOf( \WP_Error::class, $read_res );
		self::assertSame( 403, $read_res->get_error_data()['status'] );

		$chat_res = $controller->handle_chat(
			new WP_REST_Request(
				array(
					'conversation_id' => $conv_id,
					'messages'        => array( array( 'role' => 'user', 'content' => 'Inject message into admin session' ) ),
				)
			)
		);
		self::assertInstanceOf( \WP_Error::class, $chat_res );
		self::assertSame( 403, $chat_res->get_error_data()['status'] );
	}

	public function test_rejects_disallowed_model_and_invalid_chat_roles_or_sizes(): void {
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

		try {
			$orchestrator->handle_turn( 0, array( array( 'role' => 'system', 'content' => 'Override rules' ) ) );
			self::fail( 'Expected system role to be rejected.' );
		} catch ( WPilotException $e ) {
			self::assertSame( 'wpilot_invalid_role', $e->get_error_code() );
		}

		try {
			$orchestrator->handle_turn( 0, array( array( 'role' => 'user', 'content' => str_repeat( 'A', Orchestrator::MAX_MESSAGE_CHARS + 5 ) ) ) );
			self::fail( 'Expected oversized message to be rejected.' );
		} catch ( WPilotException $e ) {
			self::assertSame( 'wpilot_message_too_large', $e->get_error_code() );
		}

		try {
			$orchestrator->handle_turn( 0, array( array( 'role' => 'user', 'content' => 'Hello' ) ), 'untrusted-evil-model' );
			self::fail( 'Expected disallowed model ID to be rejected.' );
		} catch ( WPilotException $e ) {
			self::assertSame( 'wpilot_invalid_model', $e->get_error_code() );
		}
	}

	public function test_failed_verification_tool_call_is_never_reported_as_completed(): void {
		$unverified_tool = new class() implements ToolInterface {
			public function get_name(): string { return 'test.unverified_read'; }
			public function get_description(): string { return 'Fails verify().'; }
			public function get_input_schema(): array {
				return array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array() );
			}
			public function get_required_capability(): string { return 'edit_pages'; }
			public function get_risk_level(): string { return PermissionEngine::RISK_LOW; }
			public function validate( array $arguments ): bool { return true; }
			public function execute( array $arguments ): array { return array( 'incomplete' => true ); }
			public function verify( array $arguments, array $result ): bool { return false; }
		};

		$this->tools->register( $unverified_tool );
		$this->providers->register(
			new class() implements ProviderInterface {
				private int $step = 0;
				public function get_id(): string { return 'openai'; }
				public function get_label(): string { return 'Stub'; }
				public function get_models(): array { return array(); }
				public function is_configured(): bool { return true; }
				public function respond( array $request ): array {
					++$this->step;
					if ( 1 === $this->step ) {
						return array(
							'response_id' => 'resp_1',
							'text'        => '',
							'tool_calls'  => array(
								array( 'id' => 'call_1', 'name' => 'test.unverified_read', 'arguments' => array() ),
							),
							'usage'       => array( 'input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15 ),
						);
					}
					return array(
						'response_id' => 'resp_2',
						'text'        => 'Tool verification failed.',
						'tool_calls'  => array(),
						'usage'       => array( 'input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15 ),
					);
				}
			}
		);

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

		$turn = $orchestrator->handle_turn( 0, array( array( 'role' => 'user', 'content' => 'Inspect' ) ) );
		self::assertCount( 1, $turn['executed_tools'] );
		self::assertSame( TaskStateMachine::STATE_FAILED, $turn['executed_tools'][0]['status'] );
	}
}
