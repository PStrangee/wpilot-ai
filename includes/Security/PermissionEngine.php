<?php
/**
 * Permission, Rate-Limiting & Prompt-Injection Protection Engine.
 *
 * @package WPilotAI\Security
 */

declare(strict_types=1);

namespace WPilotAI\Security;

use WPilotAI\Core\Settings;

final class PermissionEngine {

	public const RISK_LOW    = 'low';
	public const RISK_MEDIUM = 'medium';
	public const RISK_HIGH   = 'high';

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function can_access_plugin( ?int $user_id = null ): bool {
		$user = null === $user_id ? wp_get_current_user() : get_userdata( $user_id );
		if ( ! $user || 0 === $user->ID ) {
			return false;
		}
		if ( user_can( $user, 'manage_options' ) ) {
			return true;
		}
		$allowed = (array) ( $this->settings->all()['allowed_roles'] ?? array( 'administrator' ) );
		return ! empty( array_intersect( (array) $user->roles, $allowed ) );
	}

	public function can_execute_capability( string $capability ): bool {
		return current_user_can( $capability );
	}

	public function requires_approval( string $risk_level ): bool {
		if ( self::RISK_HIGH === $risk_level ) {
			return true;
		}
		if ( self::RISK_MEDIUM === $risk_level ) {
			return (bool) ( $this->settings->all()['require_approval_med'] ?? true );
		}
		return false;
	}

	/**
	 * Per-user hourly rate limiter backed by WordPress transients.
	 */
	public function check_rate_limit( int $user_id ): bool {
		$limit = (int) ( $this->settings->all()['max_requests_per_hour'] ?? 60 );
		$key   = 'wpilot_rl_' . $user_id . '_' . gmdate( 'YmdH' );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return false;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return true;
	}

	/**
	 * Wraps untrusted WordPress site content in explicit data delimiters so
	 * prompt-injection directives embedded inside posts/plugins cannot override policy.
	 */
	public function isolate_untrusted_content( string $source, string $content ): string {
		$cleaned = preg_replace(
			'/<\/?\s*(?:untrusted_site_content|system|assistant|developer)\b[^>]*>/i',
			'[escaped_tag]',
			$content
		);
		$safe_body = is_string( $cleaned ) ? $cleaned : $content;
		return sprintf(
			"<untrusted_site_content source=\"%s\">\n%s\n</untrusted_site_content>",
			esc_attr( $source ),
			$safe_body
		);
	}
}
