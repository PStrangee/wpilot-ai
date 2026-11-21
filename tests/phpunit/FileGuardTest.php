<?php
declare(strict_types=1);

namespace WPilotAI\Tests;

use PHPUnit\Framework\TestCase;
use WPilotAI\Security\FileGuard;

final class FileGuardTest extends TestCase {

	public function test_blocks_traversal_and_disallowed_extensions(): void {
		$tmp   = sys_get_temp_dir() . '/wpilot_guard_' . uniqid();
		mkdir( $tmp, 0755, true );
		$safe  = $tmp . '/style.css';
		$bad   = $tmp . '/secret.env';
		file_put_contents( $safe, 'body { color: #111; }' );
		file_put_contents( $bad, 'SECRET=123' );

		$guard = new FileGuard( array( $tmp ) );
		self::assertTrue( $guard->is_path_allowed( $safe ) );
		self::assertFalse( $guard->is_path_allowed( $bad ) );
		self::assertFalse( $guard->is_path_allowed( $tmp . '/../etc/passwd' ) );

		unlink( $safe );
		unlink( $bad );
		rmdir( $tmp );
	}
}
