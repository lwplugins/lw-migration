<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Archive;

use LightweightPlugins\Migration\Archive\PathGuard;
use LightweightPlugins\Migration\Archive\UnsafePathException;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class PathGuardTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = TempDir::create();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function unsafe(): array {
		return [
			'parent'        => [ '../x.php' ],
			'nested parent' => [ 'uploads/../../x.php' ],
			'absolute'      => [ '/etc/passwd' ],
			'drive'         => [ 'C:/x.php' ],
			'nul'           => [ "a\0b" ],
			'backslash'     => [ 'a\\..\\b' ],
			'empty segment' => [ 'a//b' ],
			'dot segment'   => [ 'a/./b' ],
			'empty'         => [ '' ],
		];
	}

	/**
	 * @dataProvider unsafe
	 */
	public function test_unsafe_names_are_rejected( string $name ): void {
		$this->assertFalse( PathGuard::isSafeEntryName( $name ) );
		$this->expectException( UnsafePathException::class );
		PathGuard::resolve( $this->dir, $name );
	}

	public function test_safe_name_resolves_inside_base(): void {
		$this->assertSame( $this->dir . '/uploads/2026/ékezetes fájl.txt', PathGuard::resolve( $this->dir, 'uploads/2026/ékezetes fájl.txt' ) );
	}

	public function test_symlinked_directory_pointing_outside_is_rejected(): void {
		$outside = TempDir::create();
		symlink( $outside, $this->dir . '/link' );
		try {
			$this->expectException( UnsafePathException::class );
			PathGuard::resolve( $this->dir, 'link/evil.php' );
		} finally {
			TempDir::remove( $outside );
		}
	}

	public function test_symlink_target_file_is_rejected(): void {
		symlink( '/etc/hosts', $this->dir . '/hosts' );
		$this->expectException( UnsafePathException::class );
		PathGuard::resolve( $this->dir, 'hosts' );
	}

	public function test_missing_base_is_rejected(): void {
		$this->expectException( UnsafePathException::class );
		PathGuard::resolve( $this->dir . '/nope', 'a.txt' );
	}

	public function test_dangling_symlink_component_is_rejected(): void {
		$outside = TempDir::create();
		try {
			symlink( $outside . '/missing', $this->dir . '/d' );
			$this->expectException( UnsafePathException::class );
			PathGuard::resolve( $this->dir, 'd/x.php' );
		} finally {
			TempDir::remove( $outside );
		}
	}

	public function test_prefix_sibling_directory_is_rejected(): void {
		mkdir( $this->dir . '/a' );
		mkdir( $this->dir . '/ab' );
		symlink( $this->dir . '/ab', $this->dir . '/a/l' );
		$this->expectException( UnsafePathException::class );
		PathGuard::resolve( $this->dir . '/a', 'l/x.php' );
	}

	public function test_symlink_inside_base_is_allowed(): void {
		mkdir( $this->dir . '/real' );
		symlink( $this->dir . '/real', $this->dir . '/ln' );
		$this->assertSame( $this->dir . '/ln/x.php', PathGuard::resolve( $this->dir, 'ln/x.php' ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function messagesWithControls(): array {
		return [
			'traversal'  => [ "../\x1b]52;c;QUJD\x07ignored.txt", 'Unsafe path in archive: ../\x1b]52;c;QUJD\x07ignored.txt' ],
			'nul'        => [ "a\0\x1b[2Kb", 'Unsafe path in archive: a\x00\x1b[2Kb' ],
			'symlink'    => [ "hosts\x1b[1A", 'Refusing to overwrite a symlink: hosts\x1b[1A' ],
			'c1 escapes' => [ "../\u{9b}2K", 'Unsafe path in archive: ../\u009b2K' ],
		];
	}

	/**
	 * @dataProvider messagesWithControls
	 */
	public function test_the_message_shows_control_characters_of_the_name_as_escapes( string $name, string $message ): void {
		symlink( '/etc/hosts', $this->dir . "/hosts\x1b[1A" );
		try {
			PathGuard::resolve( $this->dir, $name );
			$this->fail( 'The name is refused.' );
		} catch ( UnsafePathException $e ) {
			$this->assertSame( $message, $e->getMessage() );
		}
	}

	public function test_deep_new_path_resolves(): void {
		$this->assertSame( $this->dir . '/n1/n2/n3/n4/x.php', PathGuard::resolve( $this->dir, 'n1/n2/n3/n4/x.php' ) );
	}
}
