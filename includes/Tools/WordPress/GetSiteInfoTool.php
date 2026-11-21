<?php
/**
 * WordPress Site Info Read Tool (`wordpress.get_site_info`).
 *
 * @package WPilotAI\Tools\WordPress
 */

declare(strict_types=1);

namespace WPilotAI\Tools\WordPress;

use WPilotAI\Core\WPilotException;
use WPilotAI\Security\PermissionEngine;
use WPilotAI\Tools\ToolInterface;

final class GetSiteInfoTool implements ToolInterface {

	public function get_name(): string {
		return 'wordpress.get_site_info';
	}

	public function get_description(): string {
		return 'Inspect WordPress core version, site name, URL, locale, active theme, and active integrations (Elementor, WooCommerce, Yoast/Rank Math).';
	}

	public function get_input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => (object) array(),
		);
	}

	public function get_required_capability(): string {
		return 'manage_options';
	}

	public function get_risk_level(): string {
		return PermissionEngine::RISK_LOW;
	}

	public function validate( array $arguments ): bool {
		return empty( $arguments );
	}

	public function execute( array $arguments ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new WPilotException( 'wpilot_forbidden', 'Administrator privileges are required to inspect site info.', 403 );
		}

		$theme = wp_get_theme();
		return array(
			'site_name'         => get_bloginfo( 'name' ),
			'tagline'           => get_bloginfo( 'description' ),
			'site_url'          => home_url(),
			'wordpress_version' => get_bloginfo( 'version' ),
			'php_version'       => PHP_VERSION,
			'locale'            => get_locale(),
			'is_rtl'            => is_rtl(),
			'active_theme'      => array(
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
			),
			'integrations'      => array(
				'elementor'   => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : false,
				'woocommerce' => defined( 'WC_VERSION' ) ? WC_VERSION : false,
				'yoast_seo'   => defined( 'WPSEO_VERSION' ) ? WPSEO_VERSION : false,
				'rank_math'   => defined( 'RANK_MATH_VERSION' ) ? RANK_MATH_VERSION : false,
			),
		);
	}

	public function verify( array $arguments, array $result ): bool {
		return isset( $result['wordpress_version'] );
	}
}
