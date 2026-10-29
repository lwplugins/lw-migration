<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\CLI;

use LightweightPlugins\Migration\CLI\Prompt;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The flows that do not depend on the terminal (the WP_CLI stand-in keeps state per process).
 */
#[RunTestsInSeparateProcesses]
final class PromptPasswordTest extends MonkeyTestCase {

	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/Support/wp-cli-class.php';
		$this->dir = TempDir::create();
		file_put_contents( $this->dir . '/pw', "from-file\n" );
		file_put_contents( $this->dir . '/empty', '' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	private function error( array $assoc ): string {
		try {
			Prompt::password( $assoc, false );
		} catch ( \RuntimeException $e ) {
			return (string) end( \WP_CLI::$errors );
		}
		$this->fail( 'Refused.' );
	}

	public function test_no_flag_means_no_password_and_a_file_or_value_is_read(): void {
		$this->assertNull( Prompt::password( [], false ) );
		$this->assertSame( 'from-file', Prompt::password( [ 'password-file' => $this->dir . '/pw' ], true ) );
		$this->assertSame( 'from-file', Prompt::password( [ 'password-file' => $this->dir . '/pw' ], false, true ) );
		$this->assertSame( 'v', Prompt::password( [ 'password' => 'v' ], false ) );
	}

	public function test_both_flags_an_empty_value_and_an_empty_file_are_refused(): void {
		$this->assertStringContainsString( 'not both', $this->error( [ 'password' => 'v', 'password-file' => $this->dir . '/pw' ] ) );
		$this->assertSame( 'The password cannot be empty.', $this->error( [ 'password' => '' ] ) );
		$this->assertStringContainsString( 'holds no password', $this->error( [ 'password-file' => $this->dir . '/empty' ] ) );
	}
}
