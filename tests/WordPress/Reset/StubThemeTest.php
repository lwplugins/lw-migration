<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Reset;

use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Reset\StubTheme;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class StubThemeTest extends MonkeyTestCase {

	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = TempDir::create();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	public function test_the_blank_theme_is_written_once_and_reused(): void {
		$this->assertSame( 'lw-migration-blank', StubTheme::write( $this->dir ) );
		$this->assertSame( [ '.', '..', 'functions.php', 'index.php', 'style.css' ], scandir( $this->dir . '/lw-migration-blank' ) );
		$this->assertStringContainsString( 'Theme Name: Blank (LW Migration)', (string) file_get_contents( $this->dir . '/lw-migration-blank/style.css' ) );
		$this->assertStringContainsString( 'wp_head()', (string) file_get_contents( $this->dir . '/lw-migration-blank/index.php' ) );
		$this->assertSame( 'lw-migration-blank', StubTheme::write( $this->dir ), 'Its own folder is reused.' );
	}

	public function test_a_folder_of_another_theme_under_that_name_is_refused(): void {
		mkdir( $this->dir . '/lw-migration-blank' );
		file_put_contents( $this->dir . '/lw-migration-blank/style.css', '/* Theme Name: Something Else */' );
		$this->expectException( JobException::class );
		$this->expectExceptionMessage( 'A folder named lw-migration-blank already exists in the themes folder.' );
		StubTheme::write( $this->dir );
	}

	public function test_a_link_under_that_name_is_refused(): void {
		mkdir( $this->dir . '/elsewhere' );
		file_put_contents( $this->dir . '/elsewhere/style.css', "/*\nTheme Name: Blank (LW Migration)\n*/" );
		symlink( $this->dir . '/elsewhere', $this->dir . '/lw-migration-blank' );
		$this->expectException( JobException::class );
		StubTheme::write( $this->dir );
	}
}
