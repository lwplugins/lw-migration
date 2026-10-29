<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Export;

use LightweightPlugins\Migration\Export\ExportOptions;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ExportOptionsTest extends MonkeyTestCase {

	public function test_cli_flags_lists_and_replace_pairs(): void {
		$o = ExportOptions::fromCli(
			[ 'http://a.test', 'https://b.test', 'foo', 'bar' ],
			[
				'exclude-media'            => true,
				'exclude-inactive-plugins' => true,
				'exclude-files'            => 'uploads/big, /cache/x/ ,',
				'exclude-tables'           => 'wp_log',
				'include-tables'           => 'other_t',
				'replace'                  => true,
				'compression'              => 'gzip',
				'password'                 => 'pw',
			]
		);
		$this->assertTrue( $o->noMedia );
		$this->assertTrue( $o->noInactivePlugins );
		$this->assertFalse( $o->noThemes );
		$this->assertSame( [ 'uploads/big', 'cache/x' ], $o->excludeFiles );
		$this->assertSame( [ 'wp_log' ], $o->excludeTables );
		$this->assertSame( [ 'other_t' ], $o->includeTables );
		$this->assertSame( [ [ 'http://a.test', 'https://b.test' ], [ 'foo', 'bar' ] ], $o->replace );
		$this->assertSame( 'gzip', $o->compression );
		$this->assertTrue( $o->encrypt );
		$this->assertSame( $o->toArray(), ExportOptions::fromArray( json_decode( (string) json_encode( $o->toArray() ), true ) )->toArray() );
	}

	/**
	 * @param array<int, string>   $args  Positional arguments.
	 * @param array<string, mixed> $assoc Flags.
	 */
	#[DataProvider( 'invalid' )]
	public function test_invalid_input_is_refused( array $args, array $assoc ): void {
		$this->expectException( \InvalidArgumentException::class );
		ExportOptions::fromCli( $args, $assoc );
	}

	/** @return array<string, array{0: array<int, string>, 1: array<string, mixed>}> */
	public static function invalid(): array {
		return [
			'odd replace'        => [ [ 'a' ], [ 'replace' => true ] ],
			'stray arguments'    => [ [ 'a', 'b' ], [] ],
			'unknown codec'      => [ [], [ 'compression' => 'zip' ] ],
			'traversing exclude' => [ [], [ 'exclude-files' => '../wp-config.php' ] ],
			'root exclude'       => [ [], [ 'exclude-files' => '/' ] ],
			'dot exclude'        => [ [], [ 'exclude-files' => './' ] ],
			'inner dot exclude'  => [ [], [ 'exclude-files' => 'a/./b' ] ],
			'backslash exclude'  => [ [], [ 'exclude-files' => '\\' ] ],
			'empty new value'    => [ [ 'old', '' ], [ 'replace' => true ] ],
			'empty old value'    => [ [ '', 'new' ], [ 'replace' => true ] ],
		];
	}

	public function test_excluded_paths_collapse_repeated_slashes(): void {
		$o = ExportOptions::fromArray( [ 'excludeFiles' => [ 'uploads//big///x', '//cache//', ' a\\\\b ' ] ] );

		$this->assertSame( [ 'uploads/big/x', 'cache', 'a/b' ], $o->excludeFiles );
	}

	public function test_database_only(): void {
		$o = ExportOptions::databaseOnly();
		$this->assertTrue( $o->noFiles && $o->noMedia && $o->noThemes && $o->noPlugins && $o->noMustUsePlugins );
		$this->assertSame( 'pre-import', $o->kind );
		$this->assertFalse( $o->noDatabase );
	}

	public function test_wp_cli_false_strings_are_false(): void {
		$o = ExportOptions::fromCli(
			[],
			[
				'exclude-media'   => 'false',
				'exclude-themes'  => '0',
				'exclude-plugins' => 'no',
				'exclude-cache'   => 'true',
				'replace'         => 'false',
			]
		);
		$this->assertFalse( $o->noMedia );
		$this->assertFalse( $o->noThemes );
		$this->assertFalse( $o->noPlugins );
		$this->assertTrue( $o->noCache );
		$this->assertSame( [], $o->replace );
	}

	public function test_no_files_forces_the_file_flags(): void {
		$o = ExportOptions::fromArray(
			[
				'noFiles'          => true,
				'noMedia'          => false,
				'noThemes'         => false,
				'noPlugins'        => false,
				'noMustUsePlugins' => false,
			]
		);
		$this->assertTrue( $o->noMedia && $o->noThemes && $o->noPlugins && $o->noMustUsePlugins );
		$this->assertSame( 'backup', $o->kind );
	}
	public function test_sites_are_whole_positive_unique_and_sorted(): void {
		$o = ExportOptions::fromArray( [ 'sites' => [ 3, '2', 3, 0, -1, 'x', '1.5' ] ] );

		$this->assertSame( [ 2, 3 ], $o->sites );
		$this->assertSame( [ 2, 3 ], $o->toArray()['sites'] );
		$this->assertSame( [], ( new ExportOptions() )->toArray()['sites'], 'A single-site job keeps the same shape (R19).' );
		$this->assertSame( [ 2, 3 ], ExportOptions::fromArray( json_decode( (string) json_encode( $o->toArray() ), true ) )->sites );
		$this->assertSame( [], ExportOptions::fromArray( [ 'sites' => '2,3' ] )->sites, 'Saved options hold a list; text is not split (the CLI and REST parse their own input).' );
		$this->assertSame( [ 7 ], ExportOptions::fromArray( [ 'sites' => [ ' 7 ', '2,3', 2.0 ] ] )->sites );
	}

	public function test_the_cli_reads_sites(): void {
		$this->assertSame( [ 2, 3 ], ExportOptions::fromCli( [], [ 'sites' => '3,2' ] )->sites );
		$this->assertSame( [ 2, 3 ], ExportOptions::fromCli( [], [ 'sites' => ' 3 , 2,3' ] )->sites );
		$this->assertSame( [], ExportOptions::fromCli( [], [] )->sites );
		foreach ( [ true, '2,x', '', '2,,3', '-2', '0', '2.5' ] as $value ) {
			try {
				ExportOptions::fromCli( [], [ 'sites' => $value ] );
				$this->fail( 'Refused: ' . var_export( $value, true ) );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( 'Give the site IDs: --sites=<id>[,<id>...]', $e->getMessage() );
			}
		}
	}

	public function test_sites_are_not_a_package_flag(): void {
		$this->assertArrayNotHasKey( 'Sites', ExportOptions::FLAGS );
		$this->assertNotContains( 'sites', ExportOptions::FLAGS );
		$this->assertNotContains( 'sites', ExportOptions::CLI_FLAGS );
	}
}
