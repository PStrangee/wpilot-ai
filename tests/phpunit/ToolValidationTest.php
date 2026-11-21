<?php
declare(strict_types=1);

namespace WPilotAI\Tests;

use PHPUnit\Framework\TestCase;
use WPTestState;
use WPilotAI\AI\ProviderInterface;
use WPilotAI\AI\ProviderRegistry;
use WPilotAI\Agent\Orchestrator;
use WPilotAI\Agent\TaskStateMachine;
use WPilotAI\Backup\BackupManager;
use WPilotAI\Core\Settings;
use WPilotAI\Core\WPilotException;
use WPilotAI\Logging\AuditLogger;
use WPilotAI\Security\PermissionEngine;
use WPilotAI\Tools\ArgumentValidator;
use WPilotAI\Tools\Code\InspectFileTool;
use WPilotAI\Tools\ToolInterface;
use WPilotAI\Tools\ToolRegistry;
use WPilotAI\Tools\WordPress\UpdatePageTool;

final class ToolValidationTest extends TestCase {

	protected function setUp(): void {
		WPTestState::reset();
	}

	public function test_argument_validator_rejects_extra_properties_wrong_types_and_bounds(): void {
		$audit   = new AuditLogger();
		$backups = new BackupManager( $audit );
		$tool    = new UpdatePageTool( $backups );
		$val     = new ArgumentValidator();

		// Valid payload passes.
		$val->assert_valid( $tool, array( 'page_id' => 12, 'title' => 'Valid Title' ) );

		// Unexpected extra property rejected.
		try {
			$val->assert_valid( $tool, array( 'page_id' => 12, 'title' => 'Ok', 'post_status' => 'trash' ) );
			self::fail( 'Expected unexpected property to be rejected.' );
		} catch ( WPilotException $e ) {
			self::assertSame( 'wpilot_invalid_tool_arguments', $e->get_error_code() );
		}

		// Non-integer page_id rejected.
		try {
			$val->assert_valid( $tool, array( 'page_id' => '12', 'title' => 'Ok' ) );
			self::fail( 'Expected string page_id to be rejected.' );
		} catch ( WPilotException $e ) {
			self::assertSame( 'wpilot_invalid_tool_arguments', $e->get_error_code() );
		}

		// Missing mutable fields rejected by tool::validate().
		try {
			$val->assert_valid( $tool, array( 'page_id' => 12 ) );
			self::fail( 'Expected UpdatePageTool::validate() to reject update with no fields.' );
		} catch ( WPilotException $e ) {
			self::assertSame( 'wpilot_tool_validation_failed', $e->get_error_code() );
		}
	}

	public function test_orchestrator_invokes_validate_before_execution_and_on_approval(): void {
		global $wpdb;
		$validate_calls = 0;
		$execute_calls  = 0;

		$guarded_tool = new class( $validate_calls, $execute_calls ) implements ToolInterface {
			private int $val_ref;
			private int $exec_ref;
			public function __construct( int &$v, int &$e ) {
				$this->val_ref  = &$v;
				$this->exec_ref = &$e;
			}
			public function get_name(): string { return 'test.guarded_write'; }
			public function get_description(): string { return 'Checks validate().'; }
			public function get_input_schema(): array {
				return array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'code' ),
					'properties'           => array( 'code' => array( 'type' => 'string' ) ),
				);
			}
			public function get_required_capability(): string { return 'edit_pages'; }
			public function get_risk_level(): string { return PermissionEngine::RISK_MEDIUM; }
			public function validate( array $arguments ): bool {
				++$this->val_ref;
				return 'ALLOW' === ( $arguments['code'] ?? '' );
			}
			public function execute( array $arguments ): array {
				++$this->exec_ref;
				return array( 'ok' => true );
			}
			public function verify( array $arguments, array $result ): bool { return ! empty( $result['ok'] ); }
		};

		$settings    = new Settings();
		$permissions = new PermissionEngine( $settings );
		$audit       = new AuditLogger();
		$backups     = new BackupManager( $audit );
		$tools       = new ToolRegistry( $permissions );
		$tools->register( $guarded_tool );

		$providers = new ProviderRegistry();
		$providers->register(
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
							'response_id' => 'r1',
							'text'        => '',
							'tool_calls'  => array(
								array( 'id' => 'c1', 'name' => 'test.guarded_write', 'arguments' => array( 'code' => 'DENY' ) ),
							),
							'usage'       => array( 'input_tokens' => 5, 'output_tokens' => 5, 'total_tokens' => 10 ),
						);
					}
					return array(
						'response_id' => 'r2',
						'text'        => 'Validation rejected.',
						'tool_calls'  => array(),
						'usage'       => array( 'input_tokens' => 5, 'output_tokens' => 5, 'total_tokens' => 10 ),
					);
				}
			}
		);

		$orchestrator = new Orchestrator(
			$providers,
			$tools,
			$permissions,
			$backups,
			$audit,
			new TaskStateMachine(),
			$settings,
			new ArgumentValidator()
		);

		$turn = $orchestrator->handle_turn( 0, array( array( 'role' => 'user', 'content' => 'Run tool' ) ) );
		self::assertSame( 1, $validate_calls );
		self::assertSame( 0, $execute_calls );
		self::assertEmpty( $turn['pending_tasks'] );

		// Also verify tampered DB arguments are caught during approve_task().
		$wpdb->insert(
			$wpdb->prefix . 'wpilot_tasks',
			array(
				'conversation_id' => 1,
				'user_id'         => 1,
				'tool_name'       => 'test.guarded_write',
				'risk_level'      => 'medium',
				'status'          => TaskStateMachine::STATE_AWAITING_APPROVAL,
				'arguments'       => '{"code":"DENY"}',
			)
		);
		$tampered_id = (int) $wpdb->insert_id;

		try {
			$orchestrator->approve_task( $tampered_id );
			self::fail( 'Expected approve_task to reject tampered invalid arguments.' );
		} catch ( WPilotException $e ) {
			self::assertSame( 'wpilot_tool_validation_failed', $e->get_error_code() );
		}
		self::assertSame( 2, $validate_calls );
		self::assertSame( 0, $execute_calls );
	}

	public function test_inspect_file_tool_validation_blocks_path_traversal(): void {
		$tool = new InspectFileTool();
		$val  = new ArgumentValidator();

		$val->assert_valid( $tool, array( 'relative_path' => 'style.css' ) );

		$this->expectException( WPilotException::class );
		$val->assert_valid( $tool, array( 'relative_path' => '../../wp-config.php' ) );
	}
}
