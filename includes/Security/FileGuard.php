<?php
/**
 * Controlled Filesystem Guard preventing directory traversal and arbitrary execution.
 *
 * @package WPilotAI\Security
 */

declare(strict_types=1);

namespace WPilotAI\Security;

final class FileGuard {

	/** @var array<int, string> */
	private const ALLOWED_EXTENSIONS = array( 'php', 'css', 'js', 'json', 'xml' );

	/** @var array<int, string> */
	private const BLOCKED_BASENAMES = array(
		'wp-config.php',
		'wp-config-sample.php',
		'.env',
		'php.ini',
		'.htaccess',
	);

	/** @var array<int, string> */
	private array $allowed_roots;

	/**
	 * @param array<int, string> $allowed_roots Absolute directory paths.
	 */
	public function __construct( array $allowed_roots ) {
		$normalized = array();
		foreach ( $allowed_roots as $root ) {
			$real = realpath( $root );
			if ( false !== $real && is_dir( $real ) ) {
				$normalized[] = rtrim( $real, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
			}
		}
		$this->allowed_roots = $normalized;
	}

	public function is_path_allowed( string $target_path ): bool {
		return null !== $this->resolve_allowed_path( $target_path );
	}

	public function read_safe( string $target_path, int $max_bytes = 131072 ): string {
		$real = $this->resolve_allowed_path( $target_path );
		if ( null === $real || ! is_readable( $real ) ) {
			throw new \RuntimeException( 'Access denied: file is outside the WPilot AI allowlist.' );
		}
		$size = filesize( $real );
		if ( false === $size || $size > $max_bytes ) {
			throw new \RuntimeException( 'File exceeds maximum safe inspection size.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading validated local theme file strictly within allowed root.
		$content = file_get_contents( $real );
		if ( false === $content ) {
			throw new \RuntimeException( 'Unable to read target file.' );
		}
		return $content;
	}

	private function resolve_allowed_path( string $target_path ): ?string {
		if ( str_contains( $target_path, "\0" ) ) {
			return null;
		}
		$real = realpath( $target_path );
		if ( false === $real || ! is_file( $real ) ) {
			return null;
		}

		$basename = strtolower( basename( $real ) );
		if ( in_array( $basename, self::BLOCKED_BASENAMES, true ) || str_starts_with( $basename, '.env.' ) ) {
			return null;
		}

		$ext = strtolower( pathinfo( $real, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::ALLOWED_EXTENSIONS, true ) ) {
			return null;
		}

		foreach ( $this->allowed_roots as $root ) {
			if ( str_starts_with( $real, $root ) ) {
				return $real;
			}
		}

		return null;
	}
}
