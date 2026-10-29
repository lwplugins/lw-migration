<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\CLI;

use LightweightPlugins\Migration\CLI\ExtractTarget;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class ExtractTargetTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = TempDir::create();
		mkdir( $this->dir . '/site/wp-content', 0755, true );
		mkdir( $this->dir . '/outside' );
		symlink( $this->dir . '/site/wp-content', $this->dir . '/outside/sneaky' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
	}

	public function test_resolves_relative_dot_dot_and_symlinked_targets(): void {
		$d = $this->dir;
		$this->assertSame( $d . '/outside/new/x', ExtractTarget::resolve( 'new/x', $d . '/outside' ) );
		$this->assertSame( $d . '/site/new', ExtractTarget::resolve( 'missing/../../site/new', $d . '/outside' ) );
		$this->assertSame( $d . '/site/wp-content/a', ExtractTarget::resolve( $d . '/outside/sneaky/a', '/' ) );
		$this->assertSame( $d . '/site', ExtractTarget::resolve( $d . '/site/./', '/' ) );
	}

	public function test_inside_any_root_including_the_root_itself(): void {
		$d     = $this->dir;
		$roots = [ $d . '/site/', $d . '/site/wp-content' ];
		$this->assertTrue( ExtractTarget::isInside( $d . '/site', $roots ) );
		$this->assertTrue( ExtractTarget::isInside( $d . '/site/wp-content/backup', $roots ) );
		$this->assertTrue( ExtractTarget::isInside( ExtractTarget::resolve( 'sneaky/b', $d . '/outside' ), $roots ) );
		$this->assertFalse( ExtractTarget::isInside( $d . '/site2', $roots ) );
		$this->assertFalse( ExtractTarget::isInside( $d . '/outside/x', $roots ) );
	}
}
