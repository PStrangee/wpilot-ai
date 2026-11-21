<?php
/**
 * Main Plugin Container & Module Bootstrap.
 *
 * @package WPilotAI\Core
 */

declare(strict_types=1);

namespace WPilotAI\Core;

use WPilotAI\AI\OpenAIProvider;
use WPilotAI\AI\ProviderRegistry;
use WPilotAI\Agent\Orchestrator;
use WPilotAI\Agent\TaskStateMachine;
use WPilotAI\Backup\BackupManager;
use WPilotAI\Logging\AuditLogger;
use WPilotAI\REST\RestController;
use WPilotAI\Security\PermissionEngine;
use WPilotAI\Tools\Code\InspectFileTool;
use WPilotAI\Tools\Elementor\InspectPageTool as ElementorInspectPageTool;
use WPilotAI\Tools\Elementor\UpdateWidgetTool as ElementorUpdateWidgetTool;
use WPilotAI\Tools\SEO\AnalyzePageTool as SeoAnalyzePageTool;
use WPilotAI\Tools\SEO\UpdateMetadataTool as SeoUpdateMetadataTool;
use WPilotAI\Tools\System\CreateBackupTool;
use WPilotAI\Tools\System\RollbackTool;
use WPilotAI\Tools\ArgumentValidator;
use WPilotAI\Tools\ToolRegistry;
use WPilotAI\Tools\WooCommerce\ListProductsTool;
use WPilotAI\Tools\WooCommerce\UpdateProductTool;
use WPilotAI\Tools\WordPress\CreatePageTool;
use WPilotAI\Tools\WordPress\DeletePageTool;
use WPilotAI\Tools\WordPress\GetMenusTool;
use WPilotAI\Tools\WordPress\GetPageTool;
use WPilotAI\Tools\WordPress\GetSiteInfoTool;
use WPilotAI\Tools\WordPress\ListMediaTool;
use WPilotAI\Tools\WordPress\ListPagesTool;
use WPilotAI\Tools\WordPress\ListPluginsTool;
use WPilotAI\Tools\WordPress\ListPostsTool;
use WPilotAI\Tools\WordPress\ListThemesTool;
use WPilotAI\Tools\WordPress\UpdateMediaAltTool;
use WPilotAI\Tools\WordPress\UpdatePageTool;
use WPilotAI\Tools\WordPress\UpdatePostTool;

final class Plugin {

	private static ?self $instance = null;
	private bool $booted           = false;

	private Settings $settings;
	private PermissionEngine $permissions;
	private AuditLogger $audit_logger;
	private BackupManager $backup_manager;
	private ToolRegistry $tool_registry;
	private ProviderRegistry $provider_registry;
	private Orchestrator $orchestrator;

	private function __construct() {
		$this->settings          = new Settings();
		$this->permissions       = new PermissionEngine( $this->settings );
		$this->audit_logger      = new AuditLogger();
		$this->backup_manager    = new BackupManager( $this->audit_logger );
		$this->tool_registry     = new ToolRegistry( $this->permissions );
		$this->provider_registry = new ProviderRegistry();
		$this->orchestrator      = new Orchestrator(
			$this->provider_registry,
			$this->tool_registry,
			$this->permissions,
			$this->backup_manager,
			$this->audit_logger,
			new TaskStateMachine(),
			$this->settings,
			new ArgumentValidator()
		);
	}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		load_plugin_textdomain( 'wpilot-ai', false, dirname( plugin_basename( WPILOT_AI_FILE ) ) . '/languages' );

		$this->register_providers();
		$this->register_tools();

		$admin_page = new AdminPage( $this->settings, $this->permissions );
		$admin_page->register();

		$rest_controller = new RestController(
			$this->orchestrator,
			$this->tool_registry,
			$this->permissions,
			$this->backup_manager,
			$this->audit_logger,
			$this->settings
		);

		add_action( 'rest_api_init', array( $rest_controller, 'register_routes' ) );
	}

	private function register_providers(): void {
		$this->provider_registry->register( new OpenAIProvider( $this->settings ) );
		do_action( 'wpilot_register_providers', $this->provider_registry, $this->settings );
	}

	private function register_tools(): void {
		// WordPress Core Tools
		$this->tool_registry->register( new GetSiteInfoTool() );
		$this->tool_registry->register( new ListPagesTool() );
		$this->tool_registry->register( new GetPageTool() );
		$this->tool_registry->register( new CreatePageTool() );
		$this->tool_registry->register( new UpdatePageTool( $this->backup_manager ) );
		$this->tool_registry->register( new DeletePageTool( $this->backup_manager ) );
		$this->tool_registry->register( new ListPostsTool() );
		$this->tool_registry->register( new UpdatePostTool( $this->backup_manager ) );
		$this->tool_registry->register( new ListPluginsTool() );
		$this->tool_registry->register( new ListThemesTool() );
		$this->tool_registry->register( new ListMediaTool() );
		$this->tool_registry->register( new UpdateMediaAltTool( $this->backup_manager ) );
		$this->tool_registry->register( new GetMenusTool() );

		// Elementor Tools
		$this->tool_registry->register( new ElementorInspectPageTool() );
		$this->tool_registry->register( new ElementorUpdateWidgetTool( $this->backup_manager ) );

		// WooCommerce Tools
		$this->tool_registry->register( new ListProductsTool() );
		$this->tool_registry->register( new UpdateProductTool( $this->backup_manager ) );

		// SEO Tools
		$this->tool_registry->register( new SeoAnalyzePageTool() );
		$this->tool_registry->register( new SeoUpdateMetadataTool( $this->backup_manager ) );

		// Code & System Tools
		$this->tool_registry->register( new InspectFileTool() );
		$this->tool_registry->register( new CreateBackupTool( $this->backup_manager ) );
		$this->tool_registry->register( new RollbackTool( $this->backup_manager ) );

		do_action( 'wpilot_register_tools', $this->tool_registry, $this->backup_manager );
	}
}
