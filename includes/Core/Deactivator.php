<?php
/**
 * Plugin Deactivation Cleanup.
 *
 * @package WPilotAI\Core
 */

declare(strict_types=1);

namespace WPilotAI\Core;

final class Deactivator {

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'wpilot_ai_prune_old_logs' );
		flush_rewrite_rules();
	}
}
