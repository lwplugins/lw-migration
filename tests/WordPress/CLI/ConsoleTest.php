<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\CLI;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\CLI\Console;
use LightweightPlugins\Migration\CLI\WpCliOutput;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class ConsoleTest extends MonkeyTestCase {

	#[RunInSeparateProcess]
	public function test_lines_warnings_and_successes_show_control_characters_as_escapes(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/wp-cli-class.php';

		Console::log( "Connected with \x1b]0;title\x07Shop (ü ő)" );
		Console::warning( "Upload to \x1b[2K\x1b[1AOne failed:\n\tdenied." );
		Console::success( "Deleted \u{9b}2Ka.wpress." );
		( new WpCliOutput() )->line( "Job \x1b]52;c;QUJD\x07done" );
		( new WpCliOutput() )->warning( "\rfake" );

		$this->assertSame(
			[
				'Connected with \x1b]0;title\x07Shop (ü ő)',
				"Warning: Upload to \\x1b[2K\\x1b[1AOne failed:\n\tdenied.",
				'Success: Deleted \u009b2Ka.wpress.',
				'Job \x1b]52;c;QUJD\x07done',
				'Warning: \x0dfake',
			],
			\WP_CLI::$lines
		);
	}

	#[RunInSeparateProcess]
	public function test_an_error_shows_control_characters_as_escapes(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/wp-cli-class.php';

		try {
			Console::error( "Cannot open \x1b]52;c;QUJD\x07a.wpress" );
			$this->fail( 'error() stops.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( [ 'Cannot open \x1b]52;c;QUJD\x07a.wpress' ], \WP_CLI::$errors );
		}
	}

	public function test_table_cells_show_control_characters_as_escapes(): void {
		$printed = null;
		Functions\when( 'WP_CLI\Utils\format_items' )->alias(
			static function ( string $format, array $items, array $fields ) use ( &$printed ): void {
				$printed = [ $format, $items, $fields ];
			}
		);

		Console::items(
			'table',
			[
				[ 'name' => "\x1b]52;c;QUJD\x07x.txt", 'size' => 12, 'ours' => true ],
				[ 'name' => 'fájl ő.txt', 'size' => null, 'ours' => false ],
			],
			[ 'name', 'size', 'ours' ]
		);

		$this->assertSame(
			[
				'table',
				[
					[ 'name' => '\x1b]52;c;QUJD\x07x.txt', 'size' => 12, 'ours' => true ],
					[ 'name' => 'fájl ő.txt', 'size' => null, 'ours' => false ],
				],
				[ 'name', 'size', 'ours' ],
			],
			$printed
		);
	}

	public function test_prompt_text_keeps_colour_tokens_literal(): void {
		$question = "Address of %k%0shop%n (\x1b]0;t\x07ü ő) 100%:";

		// php-cli-tools colorize() turns "%%" into "%" and the other tokens into colour codes.
		$this->assertSame( 'Address of %%k%%0shop%%n (\x1b]0;t\x07ü ő) 100%%:', Console::promptText( $question, true ) );
		// Without colours its render() prints the text as it is.
		$this->assertSame( 'Address of %k%0shop%n (\x1b]0;t\x07ü ő) 100%:', Console::promptText( $question, false ) );
	}

	/**
	 * Ways to reach the terminal other than Console, and where each one is allowed.
	 *
	 * @return array<string, array{0: string, 1: array<int, string>}> Label => [pattern on the code without comments and strings, files allowed].
	 */
	private static function printPaths(): array {
		return [
			'WP_CLI print method' => [ '/WP_CLI::(log|line|warning|error|success|error_multi_line|print_value|colorize|debug|confirm|make_progress_bar)\s*\(/i', [ 'Console.php' ] ],
			'format_items'        => [ '/format_items\s*\(/i', [ 'Console.php' ] ],
			'progress bar'        => [ '/make_progress_bar\s*\(/i', [] ],
			'cli prompt'          => [ '/\x5c?\bcli\x5c(prompt|choose|menu)\s*\(/i', [ 'Prompt.php' ] ],
			'cli line with text'  => [ '/\x5c?\bcli\x5c(line|out|out_padded|err|render)\s*\(\s*[^)\s]/i', [] ],
			'cli out'             => [ '/\x5c?\bcli\x5c(out|out_padded|err|render)\s*\(/i', [] ],
			'cli line'            => [ '/\x5c?\bcli\x5cline\s*\(\s*\)/i', [ 'Prompt.php' ] ],
			'echo or print'       => [ '/(?<!::|->|function )\b(echo|print)\b/i', [] ],
			'printf family'       => [ '/(?<![\w>:])v?printf\s*\(|\b(print_r|var_dump|var_export)\s*\(/i', [] ],
			'standard streams'    => [ '/\b(fwrite|fputs|fprintf|fputcsv)\s*\(\s*(STDOUT|STDERR)\b|php:\/\/(stdout|stderr|output)/i', [] ],
			'aliased WP_CLI'      => [ '/\buse\s+\x5c?WP_CLI\s+as\b|\buse\s+function\s+\x5c?(WP_CLI|cli)\x5c/i', [] ],
		];
	}

	/**
	 * The file's PHP with comments removed and string literals emptied
	 * (stream names in strings stay, so php://stdout is still found).
	 *
	 * @param string $code File contents.
	 */
	private static function codeOnly( string $code ): string {
		$out = '';
		foreach ( token_get_all( $code ) as $token ) {
			if ( ! is_array( $token ) ) {
				$out .= $token;
				continue;
			}
			if ( T_INLINE_HTML === $token[0] && '' !== trim( $token[1] ) ) {
				$out .= ' echo ';
			} elseif ( in_array( $token[0], [ T_COMMENT, T_DOC_COMMENT ], true ) ) {
				$out .= ' ';
			} elseif ( in_array( $token[0], [ T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE ], true ) ) {
				$out .= 1 === preg_match( '#php://(stdout|stderr|output)#i', $token[1] ) ? $token[1] : "''";
			} else {
				$out .= $token[1];
			}
		}

		return $out;
	}

	public function test_commands_print_only_through_the_console(): void {
		$root   = dirname( __DIR__, 3 ) . '/includes/CLI';
		$direct = [];
		$files  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$name = substr( $file->getPathname(), strlen( $root ) + 1 );
			$code = self::codeOnly( (string) file_get_contents( $file->getPathname() ) );
			foreach ( self::printPaths() as $label => [ $pattern, $allowed ] ) {
				if ( 1 === preg_match( $pattern, $code ) && ! in_array( $name, $allowed, true ) ) {
					$direct[] = $name . ': ' . $label;
				}
			}
		}
		sort( $direct );

		$this->assertGreaterThan( 30, iterator_count( $files ), 'The scan reads the CLI folder.' );
		$this->assertSame( [], $direct, 'Text reaches the terminal only through Console, which shows control characters as escapes.' );
	}

	public function test_the_guard_finds_each_way_around_the_console(): void {
		$samples = [
			'WP_CLI print method' => '<?php WP_CLI::print_value( $x );',
			'format_items'        => '<?php \\WP_CLI\\Utils\\format_items( "table", $rows, [] );',
			'progress bar'        => '<?php \\WP_CLI\\Utils\\make_progress_bar( "x", 3 );',
			'cli prompt'          => '<?php \\cli\\prompt( $q );',
			'cli line with text'  => '<?php \\cli\\line( $name );',
			'cli out'             => '<?php \\cli\\out_padded( $name );',
			'cli line'            => '<?php \\cli\\line();',
			'echo or print'       => '<?php echo $name;',
			'printf family'       => '<?php \\printf( "%s", $name );',
			'standard streams'    => '<?php fwrite( STDERR, $name );',
			'aliased WP_CLI'      => '<?php use WP_CLI as Cli;',
		];
		$quiet   = '<?php /* echo $x; WP_CLI::log( $x ); */ $a = sprintf( "%s", "echo print" ); CloudLines::print( $job ); WP_CLI::halt( 2 ); function confirm() {}';
		$found   = [];
		foreach ( $samples as $label => $sample ) {
			$found[ $label ] = 1 === preg_match( self::printPaths()[ $label ][0], self::codeOnly( $sample ) );
		}

		$this->assertSame( array_fill_keys( array_keys( $samples ), true ), $found );
		foreach ( self::printPaths() as $label => [ $pattern ] ) {
			$this->assertSame( 0, preg_match( $pattern, self::codeOnly( $quiet ) ), $label );
		}
	}
}
