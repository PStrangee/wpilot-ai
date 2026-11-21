<?php
/**
 * WordPress Admin Menu & React App Enqueue Handler.
 *
 * @package WPilotAI\Core
 */

declare(strict_types=1);

namespace WPilotAI\Core;

use WPilotAI\Security\PermissionEngine;

final class AdminPage {

	private Settings $settings;
	private PermissionEngine $permissions;

	public function __construct( Settings $settings, ?PermissionEngine $permissions = null ) {
		$this->settings    = $settings;
		$this->permissions = $permissions ?? new PermissionEngine( $settings );
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function add_menu_page(): void {
		if ( ! $this->permissions->can_access_plugin() ) {
			return;
		}

		add_menu_page(
			__( 'WPilot AI', 'wpilot-ai' ),
			__( 'WPilot AI', 'wpilot-ai' ),
			'read',
			'wpilot-ai',
			array( $this, 'render_mount_point' ),
			'dashicons-superhero-alt',
			58
		);
	}

	public function render_mount_point(): void {
		if ( ! $this->permissions->can_access_plugin() ) {
			wp_die( esc_html__( 'You do not have permission to access WPilot AI.', 'wpilot-ai' ) );
		}
		echo '<div id="wpilot-ai-root" class="wrap"></div>';
	}

	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'toplevel_page_wpilot-ai' !== $hook_suffix || ! $this->permissions->can_access_plugin() ) {
			return;
		}

		$script_path = WPILOT_AI_DIR . 'assets/build/admin.js';
		$style_path  = WPILOT_AI_DIR . 'assets/build/admin.css';

		if ( file_exists( $style_path ) ) {
			wp_enqueue_style(
				'wpilot-ai-admin',
				WPILOT_AI_URL . 'assets/build/admin.css',
				array(),
				WPILOT_AI_VERSION
			);
		}

		if ( file_exists( $script_path ) ) {
			wp_enqueue_script(
				'wpilot-ai-admin',
				WPILOT_AI_URL . 'assets/build/admin.js',
				array( 'wp-element' ),
				WPILOT_AI_VERSION,
				true
			);

			wp_localize_script(
				'wpilot-ai-admin',
				'wpilotAiBoot',
				array(
					'restUrl'   => esc_url_raw( rest_url( 'wpilot/v1/' ) ),
					'nonce'     => wp_create_nonce( 'wp_rest' ),
					'locale'    => get_user_locale(),
					'isRtl'     => is_rtl(),
					'isAdmin'   => current_user_can( 'manage_options' ),
					'siteName'  => get_bloginfo( 'name' ),
					'wpVersion' => get_bloginfo( 'version' ),
					'settings'  => $this->settings->public_config(),
				)
			);
		}
	}
}
