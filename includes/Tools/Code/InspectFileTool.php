<?php
/**
 * Controlled Code Inspection Tool (`code.inspect_file`).
 *
 * Strictly confined to the active theme directory via FileGuard.
 *
 * @package WPilotAI\Tools\Code
 */

declare(strict_types=1);

namespace WPilotAI\Tools\Code;

use WPilotAI\Core\WPilotException;
use WPilotAI\Security\FileGuard;
use WPilotAI\Security\PermissionEngine;
use WPilotAI\Tools\ToolInterface;

final class InspectFileTool implements ToolInterface {

	public function get_name(): string {
		return 'code.inspect_file';
	}

	public function get_description(): string {
		return 'Read an allowlisted file (PHP, CSS, JS, JSON, XML) inside the active WordPress theme directory.';
	}

	public function get_input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'relative_path' ),
			'properties'           => array(
				'relative_path' => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 300,
					'description' => 'Path relative to the active theme directory (e.g. style.css or functions.php).',
				),
			),
		);
	}

	public function get_required_capability(): string {
		return 'edit_themes';
	}

	public function get_risk_level(): string {
		return PermissionEngine::RISK_LOW;
	}

	public function validate( array $arguments ): bool {
		if ( ! isset( $arguments['relative_path'] ) || ! is_string( $arguments['relative_path'] ) ) {
			return false;
		}
		$path = trim( $arguments['relative_path'] );
		if ( '' === $path || str_contains( $path, '..' ) || str_contains( $path, "\0" ) ) {
			return false;
		}
		return true;
	}

	public function execute( array $arguments ): array {
		if ( ! current_user_can( 'edit_themes' ) ) {
			throw new WPilotException( 'wpilot_forbidden', 'Theme editing capability is required.', 403 );
		}

		$theme_dir = get_stylesheet_directory();
		$guard     = new FileGuard( array( $theme_dir ) );
		$target    = $theme_dir . DIRECTORY_SEPARATOR . ltrim( (string) $arguments['relative_path'], '/\\' );

		try {
			$content = $guard->read_safe( $target );
		} catch ( \RuntimeException $e ) {
			throw new WPilotException( 'wpilot_file_access_denied', 'Requested file path is outside the allowlist or unreadable.', 403 );
		}

		return array(
			'relative_path' => sanitize_text_field( (string) $arguments['relative_path'] ),
			'bytes'         => strlen( $content ),
			'content'       => $content,
		);
	}

	public function verify( array $arguments, array $result ): bool {
		return isset( $result['content'] ) && is_string( $result['content'] );
	}
}
