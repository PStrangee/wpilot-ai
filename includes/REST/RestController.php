<?php
/**
 * WordPress REST API Controller (`wpilot/v1`).
 *
 * Enforces permission callbacks, capability checks, conversation & task ownership,
 * model allowlist validation, and sanitized error responses.
 *
 * @package WPilotAI\REST
 */

declare(strict_types=1);

namespace WPilotAI\REST;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WPilotAI\Agent\Orchestrator;
use WPilotAI\Backup\BackupManager;
use WPilotAI\Core\Settings;
use WPilotAI\Core\WPilotException;
use WPilotAI\Logging\AuditLogger;
use WPilotAI\Security\PermissionEngine;
use WPilotAI\Tools\ToolRegistry;

final class RestController {

	private const NAMESPACE = 'wpilot/v1';

	private Orchestrator $orchestrator;
	private ToolRegistry $tools;
	private PermissionEngine $permissions;
	private BackupManager $backups;
	private AuditLogger $audit;
	private Settings $settings;

	public function __construct(
		Orchestrator $orchestrator,
		ToolRegistry $tools,
		PermissionEngine $permissions,
		BackupManager $backups,
		AuditLogger $audit,
		Settings $settings
	) {
		$this->orchestrator = $orchestrator;
		$this->tools        = $tools;
		$this->permissions  = $permissions;
		$this->backups      = $backups;
		$this->audit        = $audit;
		$this->settings     = $settings;
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/site-overview',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_site_overview' ),
				'permission_callback' => array( $this, 'check_access' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/conversations',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'handle_list_conversations' ),
					'permission_callback' => array( $this, 'check_access' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle_create_conversation' ),
					'permission_callback' => array( $this, 'check_access' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/conversations/(?P<id>\d+)/messages',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_list_conversation_messages' ),
				'permission_callback' => array( $this, 'check_access' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/chat',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_chat' ),
				'permission_callback' => array( $this, 'check_access' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/tasks',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_list_tasks' ),
				'permission_callback' => array( $this, 'check_access' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/tasks/(?P<id>\d+)/approve',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_approve_task' ),
				'permission_callback' => array( $this, 'check_access' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/tasks/(?P<id>\d+)/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_cancel_task' ),
				'permission_callback' => array( $this, 'check_access' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/backups',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_list_backups' ),
				'permission_callback' => array( $this, 'check_admin' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/backups/(?P<id>\d+)/rollback',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_rollback' ),
				'permission_callback' => array( $this, 'check_admin' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/audit-logs',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_audit_logs' ),
				'permission_callback' => array( $this, 'check_access' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'handle_get_settings' ),
					'permission_callback' => array( $this, 'check_admin' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle_update_settings' ),
					'permission_callback' => array( $this, 'check_admin' ),
				),
			)
		);
	}

	public function check_access(): bool|WP_Error {
		if ( ! $this->permissions->can_access_plugin() ) {
			return new WP_Error( 'wpilot_forbidden', __( 'Insufficient permissions.', 'wpilot-ai' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public function check_admin(): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'wpilot_admin_required', __( 'Administrator privileges required.', 'wpilot-ai' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public function handle_site_overview(): WP_REST_Response {
		$overview = array(
			'site'     => array(),
			'pages'    => array(),
			'plugins'  => array(),
			'media'    => array(),
			'products' => array(),
		);

		$queries = array(
			'site'     => array( 'wordpress.get_site_info', array(), null ),
			'pages'    => array( 'wordpress.list_pages', array( 'limit' => 25 ), 'pages' ),
			'plugins'  => array( 'wordpress.list_plugins', array(), 'plugins' ),
			'media'    => array( 'wordpress.list_media', array( 'limit' => 25 ), 'media' ),
			'products' => array( 'woocommerce.list_products', array( 'limit' => 25 ), 'products' ),
		);

		foreach ( $queries as $section => $spec ) {
			try {
				$tool = $this->tools->get( $spec[0] );
				if ( ! $this->permissions->can_execute_capability( $tool->get_required_capability() ) ) {
					continue;
				}
				$res                  = $tool->execute( $spec[1] );
				$overview[ $section ] = null === $spec[2] ? $res : ( $res[ $spec[2] ] ?? array() );
			} catch ( \Throwable $e ) {
				// Skip unavailable optional integrations (e.g., inactive WooCommerce) cleanly.
				$overview[ $section ] = array();
			}
		}

		return new WP_REST_Response( $overview, 200 );
	}

	public function handle_list_conversations(): WP_REST_Response {
		global $wpdb;
		$conv_table = $wpdb->prefix . 'wpilot_conversations';
		$rows       = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE user_id = %d ORDER BY updated_at DESC LIMIT 30',
				$conv_table,
				get_current_user_id()
			),
			ARRAY_A
		);
		return new WP_REST_Response( array( 'conversations' => is_array( $rows ) ? $rows : array() ), 200 );
	}

	public function handle_list_conversation_messages( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$conversation_id = (int) $request->get_param( 'id' );
		$user_id         = get_current_user_id();

		try {
			if ( $conversation_id <= 0 ) {
				throw new WPilotException( 'wpilot_invalid_conversation', 'Invalid conversation ID.', 400 );
			}
			$this->orchestrator->assert_conversation_ownership( $conversation_id, $user_id );

			global $wpdb;
			$msg_table = $wpdb->prefix . 'wpilot_messages';
			$rows      = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, conversation_id, role, content, tool_calls, token_usage, created_at FROM %i WHERE conversation_id = %d ORDER BY id ASC LIMIT 100',
					$msg_table,
					$conversation_id
				),
				ARRAY_A
			);

			return new WP_REST_Response(
				array(
					'conversation_id' => $conversation_id,
					'messages'        => is_array( $rows ) ? $rows : array(),
				),
				200
			);
		} catch ( \Throwable $e ) {
			return $this->to_wp_error( $e, 'wpilot_conversation_error' );
		}
	}

	public function handle_create_conversation( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$raw_title = (string) ( $request->get_param( 'title' ) ?: __( 'New Session', 'wpilot-ai' ) );
			$title     = substr( sanitize_text_field( $raw_title ), 0, 200 );
			$raw_model = $request->get_param( 'model' );
			$model     = $this->settings->assert_allowed_model(
				is_string( $raw_model ) && '' !== trim( $raw_model ) ? $raw_model : (string) $this->settings->all()['model']
			);

			global $wpdb;
			$conv_table = $wpdb->prefix . 'wpilot_conversations';
			$inserted   = $wpdb->insert(
				$conv_table,
				array(
					'user_id'  => get_current_user_id(),
					'title'    => $title,
					'provider' => (string) $this->settings->all()['provider'],
					'model'    => $model,
				)
			);

			$conv_id = (int) $wpdb->insert_id;
			if ( false === $inserted || $conv_id <= 0 ) {
				throw new WPilotException( 'wpilot_db_error', 'Unable to create conversation.', 500 );
			}

			return new WP_REST_Response(
				array(
					'id'    => $conv_id,
					'title' => $title,
					'model' => $model,
				),
				201
			);
		} catch ( \Throwable $e ) {
			return $this->to_wp_error( $e, 'wpilot_create_conversation_error' );
		}
	}

	public function handle_chat( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$conversation_id = (int) $request->get_param( 'conversation_id' );
		$messages        = $request->get_param( 'messages' );
		$model           = $request->get_param( 'model' );

		if ( ! is_array( $messages ) || empty( $messages ) ) {
			return new WP_Error( 'wpilot_invalid_input', __( 'Messages array is required.', 'wpilot-ai' ), array( 'status' => 400 ) );
		}

		if ( null !== $model && ! is_string( $model ) ) {
			return new WP_Error( 'wpilot_invalid_model', __( 'Model parameter must be a string.', 'wpilot-ai' ), array( 'status' => 400 ) );
		}

		try {
			$result = $this->orchestrator->handle_turn( $conversation_id, $messages, is_string( $model ) ? $model : null );
			return new WP_REST_Response( $result, 200 );
		} catch ( \Throwable $e ) {
			return $this->to_wp_error( $e, 'wpilot_agent_error' );
		}
	}

	public function handle_list_tasks(): WP_REST_Response {
		global $wpdb;
		$table   = $wpdb->prefix . 'wpilot_tasks';
		$user_id = get_current_user_id();

		// Strict task ownership: every user only sees their own tasks.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE user_id = %d ORDER BY id DESC LIMIT 50',
				$table,
				$user_id
			),
			ARRAY_A
		);

		return new WP_REST_Response( array( 'tasks' => is_array( $rows ) ? $rows : array() ), 200 );
	}

	public function handle_approve_task( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$result = $this->orchestrator->approve_task( (int) $request->get_param( 'id' ) );
			return new WP_REST_Response( $result, 200 );
		} catch ( \Throwable $e ) {
			return $this->to_wp_error( $e, 'wpilot_approve_error' );
		}
	}

	public function handle_cancel_task( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$result = $this->orchestrator->cancel_task( (int) $request->get_param( 'id' ) );
			return new WP_REST_Response( $result, 200 );
		} catch ( \Throwable $e ) {
			return $this->to_wp_error( $e, 'wpilot_cancel_error' );
		}
	}

	public function handle_list_backups(): WP_REST_Response {
		global $wpdb;
		$table = $wpdb->prefix . 'wpilot_backups';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, user_id, resource_type, resource_id, tool_name, restored_at, created_at FROM %i ORDER BY id DESC LIMIT 50',
				$table
			),
			ARRAY_A
		);
		return new WP_REST_Response( array( 'backups' => is_array( $rows ) ? $rows : array() ), 200 );
	}

	public function handle_rollback( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$result = $this->backups->rollback( (int) $request->get_param( 'id' ) );
			return new WP_REST_Response( $result, 200 );
		} catch ( \Throwable $e ) {
			return $this->to_wp_error( $e, 'wpilot_rollback_error' );
		}
	}

	public function handle_audit_logs(): WP_REST_Response {
		$only_user_id = current_user_can( 'manage_options' ) ? null : get_current_user_id();
		return new WP_REST_Response( array( 'logs' => $this->audit->recent( 50, $only_user_id ) ), 200 );
	}

	public function handle_get_settings(): WP_REST_Response {
		return new WP_REST_Response( $this->settings->public_config(), 200 );
	}

	public function handle_update_settings( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$params = $request->get_json_params();
			return new WP_REST_Response( $this->settings->update( $params ), 200 );
		} catch ( \Throwable $e ) {
			return $this->to_wp_error( $e, 'wpilot_settings_error' );
		}
	}

	/**
	 * Converts exceptions into safe WP_Error objects without leaking internal diagnostics.
	 */
	private function to_wp_error( \Throwable $e, string $fallback_code ): WP_Error {
		if ( $e instanceof WPilotException ) {
			return new WP_Error(
				$e->get_error_code(),
				$e->getMessage(),
				array( 'status' => $e->get_http_status() )
			);
		}

		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG && function_exists( 'error_log' ) ) {
			$safe_msg = AuditLogger::redact_secrets( $e->getMessage() );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[WPilot AI] %s: %s', get_class( $e ), $safe_msg ) );
		}

		return new WP_Error(
			$fallback_code,
			__( 'An internal error occurred while processing your request.', 'wpilot-ai' ),
			array( 'status' => 500 )
		);
	}
}
