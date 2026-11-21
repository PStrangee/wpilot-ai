<?php
/**
 * Plugin Name:       WPilot AI
 * Plugin URI:        https://github.com/wpilot-ai/wpilot-ai
 * Description:       Your WordPress. Your AI. Your Control. An intelligent, modular WordPress AI management agent with structured tool calling, Elementor, WooCommerce, SEO, approval diffs, and rollback.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            WPilot AI Contributors
 * Author URI:        https://github.com/wpilot-ai
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wpilot-ai
 * Domain Path:       /languages
 *
 * @package WPilotAI
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPILOT_AI_VERSION', '1.0.0' );
define( 'WPILOT_AI_FILE', __FILE__ );
define( 'WPILOT_AI_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPILOT_AI_URL', plugin_dir_url( __FILE__ ) );
define( 'WPILOT_AI_MIN_PHP', '8.2' );

// Prefer Composer autoloader if present; fall back to built-in PSR-4 loader.
if ( file_exists( WPILOT_AI_DIR . 'vendor/autoload.php' ) ) {
	require_once WPILOT_AI_DIR . 'vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( string $class ): void {
			$prefix   = 'WPilotAI\\';
			$base_dir = WPILOT_AI_DIR . 'includes/';

			if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
				return;
			}

			$relative_class = substr( $class, strlen( $prefix ) );
			$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	);
}

register_activation_hook( __FILE__, array( \WPilotAI\Core\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \WPilotAI\Core\Deactivator::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		if ( version_compare( PHP_VERSION, WPILOT_AI_MIN_PHP, '<' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html(
							sprintf(
								/* translators: %s: Minimum PHP version required. */
								__( 'WPilot AI requires PHP %s or higher.', 'wpilot-ai' ),
								WPILOT_AI_MIN_PHP
							)
						)
					);
				}
			);
			return;
		}

		\WPilotAI\Core\Plugin::instance()->boot();
	}
);
