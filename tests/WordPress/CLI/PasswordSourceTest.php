<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\CLI;

use LightweightPlugins\Migration\CLI\PasswordSource;
use LightweightPlugins\Migration\CLI\SecretFiles;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class PasswordSourceTest extends MonkeyTestCase {

	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = TempDir::create();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	public function test_a_value_on_the_command_line_still_works(): void {
		$this->assertSame( 'pw', PasswordSource::read( [ 'password' => 'pw' ], false ) );
	}

	public function test_a_bare_flag_asks_on_a_terminal(): void {
		$this->assertNull( PasswordSource::read( [ 'password' => true ], true ) );
	}

	public function test_a_bare_flag_without_a_terminal_reads_one_stdin_line_and_cuts_one_line_ending(): void {
		$this->assertSame( 'pw ', PasswordSource::read( [ 'password' => true ], false, static fn(): string => "pw \n" ) );
		$this->assertSame( 'pw', PasswordSource::read( [ 'password' => true ], false, static fn(): string => "pw\r\n" ) );
		$this->assertSame( "pw\n", PasswordSource::read( [ 'password' => true ], false, static fn(): string => "pw\n\n" ) );
		$this->assertSame( '', PasswordSource::read( [ 'password' => true ], false, static fn(): string => "\n" ) );
	}

	public function test_end_of_stdin_stops_with_the_safe_forms_named(): void {
		try {
			PasswordSource::read( [ 'password' => true ], false, static fn(): ?string => null );
			$this->fail( 'No input.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( '--password-file=<path>', $e->getMessage() );
		}
	}

	public function test_a_file_gives_the_password_without_one_trailing_newline(): void {
		file_put_contents( $this->dir . '/pw', "  s3cret \n" );

		$this->assertSame( '  s3cret ', PasswordSource::read( [ 'password-file' => $this->dir . '/pw' ], false ) );
		$this->assertSame( '  s3cret ', PasswordSource::read( [ 'password-file' => $this->dir . '/pw' ], true ), 'The terminal does not matter.' );
	}

	public function test_both_flags_are_refused_without_the_value_or_the_path(): void {
		file_put_contents( $this->dir . '/pw', 'x' );

		foreach ( [ 'value-secret', true ] as $password ) {
			try {
				PasswordSource::read( [ 'password' => $password, 'password-file' => $this->dir . '/pw' ], false );
				$this->fail( 'Refused.' );
			} catch ( \RuntimeException $e ) {
				$this->assertStringContainsString( 'not both', $e->getMessage() );
				$this->assertStringNotContainsString( 'value-secret', $e->getMessage() );
				$this->assertStringNotContainsString( $this->dir, $e->getMessage() );
			}
		}
	}

	public function test_an_unreadable_huge_empty_or_pathless_file_stops_without_naming_the_path(): void {
		file_put_contents( $this->dir . '/big', str_repeat( 'k', SecretFiles::MAX_BYTES + 1 ) );
		file_put_contents( $this->dir . '/empty', "\n" );

		foreach ( [ $this->dir . '/nope', $this->dir . '/big', $this->dir, $this->dir . '/empty', true, '' ] as $path ) {
			try {
				PasswordSource::read( [ 'password-file' => $path ], false );
				$this->fail( 'Refused.' );
			} catch ( \RuntimeException $e ) {
				$this->assertStringContainsString( '--password-file', $e->getMessage() );
				$this->assertStringNotContainsString( $this->dir, $e->getMessage() );
			}
		}
	}

	public function test_a_stdin_line_longer_than_16_kb_is_refused_not_cut(): void {
		foreach ( [ SecretFiles::MAX_BYTES + 1, SecretFiles::MAX_BYTES + 2, SecretFiles::MAX_BYTES + 100 ] as $length ) {
			$stream = fopen( 'php://memory', 'w+b' );
			fwrite( $stream, str_repeat( 'k', $length ) . "\n" );
			rewind( $stream );
			$message = '';
			try {
				$read = PasswordSource::read( [ 'password' => true ], false, PasswordSource::lineOf( $stream ) );
				$message = 'read ' . strlen( (string) $read ) . ' bytes';
			} catch ( \RuntimeException $e ) {
				$message = $e->getMessage();
			} finally {
				fclose( $stream );
			}
			$this->assertStringContainsString( '16 KB', $message, 'A ' . $length . '-byte password is refused.' );
		}
	}

	public function test_a_stdin_line_of_exactly_16_kb_is_read_whole(): void {
		foreach ( [ "\n", "\r\n", '' ] as $ending ) {
			$stream = fopen( 'php://memory', 'w+b' );
			fwrite( $stream, str_repeat( 'k', SecretFiles::MAX_BYTES ) . $ending );
			rewind( $stream );
			$this->assertSame( str_repeat( 'k', SecretFiles::MAX_BYTES ), PasswordSource::read( [ 'password' => true ], false, PasswordSource::lineOf( $stream ) ) );
			fclose( $stream );
		}
	}
}
