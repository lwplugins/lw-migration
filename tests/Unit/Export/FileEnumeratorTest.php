<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Export;

use LightweightPlugins\Migration\Export\EnumCursor;
use LightweightPlugins\Migration\Export\ExportException;
use LightweightPlugins\Migration\Export\ExportFilter;
use LightweightPlugins\Migration\Export\FileEnumerator;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class FileEnumeratorTest extends TestCase {

	/** Entries of the fixture tree with default roots. */
	private const ALL = [ 'index.php', 'a dir/b.txt', 'a dir/c/d.txt', 'link/o.txt', 'uploads/2026/10/ékezet.txt' ];

	private string $dir;

	protected function setUp(): void {
		$this->dir = TempDir::create();
		$files     = [
			'wp-content/index.php'                  => '<?php',
			'wp-content/a dir/b.txt'                => 'b',
			'wp-content/a dir/c/d.txt'              => 'dd',
			'wp-content/skip/x.txt'                 => 'x',
			'wp-content/uploads/2026/10/ékezet.txt' => 'é',
			'outside/o.txt'                         => 'o',
		];
		foreach ( $files as $rel => $data ) {
			$this->write( $rel, $data );
		}
		symlink( $this->dir . '/outside', $this->dir . '/wp-content/link' );
	}

	protected function tearDown(): void {
		foreach ( [ '/wp-content/locked', '/wp-content/a dir/c' ] as $rel ) {
			if ( is_dir( $this->dir . $rel ) ) {
				chmod( $this->dir . $rel, 0755 );
			}
		}
		TempDir::remove( $this->dir );
	}

	private function write( string $rel, string $data ): void {
		if ( ! is_dir( dirname( $this->dir . '/' . $rel ) ) ) {
			mkdir( dirname( $this->dir . '/' . $rel ), 0755, true );
		}
		file_put_contents( $this->dir . '/' . $rel, $data );
	}

	/** @return array<int, array{dir: string, entry: string, filter: ExportFilter}> */
	private function roots(): array {
		$content = $this->dir . '/wp-content';
		return [
			[ 'dir' => $content, 'entry' => '', 'filter' => new ExportFilter( [ $content . '/skip', $content . '/uploads' ] ) ],
			[ 'dir' => $content . '/uploads', 'entry' => 'uploads', 'filter' => new ExportFilter( [] ) ],
			[ 'dir' => $this->dir . '/missing', 'entry' => 'themes', 'filter' => new ExportFilter( [] ) ],
		];
	}

	/** @return array<int, string> */
	private function entries(): array {
		$out = [];
		foreach ( (array) file( $this->dir . '/list' ) as $line ) {
			$out[] = FileEnumerator::parseLine( (string) $line )['entry'];
		}
		return $out;
	}

	/**
	 * @param array<int, array{dir: string, entry: string, filter: ExportFilter}> $roots Roots.
	 * @return array<int, string> Warnings as "code:entry".
	 */
	private function enumerate( array $roots, ?EnumCursor $cursor = null ): array {
		$warnings = [];
		$done     = ( new FileEnumerator( $roots, $this->dir . '/list', [] ) )->run(
			$cursor ?? new EnumCursor(),
			new NoDeadline(),
			static function ( string $code, string $entry ) use ( &$warnings ): void {
				$warnings[] = "$code:$entry";
			}
		);
		$this->assertTrue( $done );

		return $warnings;
	}

	/**
	 * Runs to the end, stopping after a random number of folders in every tick
	 * and leaving crash garbage behind each time.
	 *
	 * @param array<int, array{dir: string, entry: string, filter: ExportFilter}> $roots Roots.
	 * @return int Ticks used.
	 */
	private function enumerateInTicks( array $roots, int $maxChecks ): int {
		$cursor = new EnumCursor();
		$runs   = 0;
		do {
			$cursor = EnumCursor::fromArray( json_decode( (string) json_encode( $cursor->toArray() ), true ) );
			foreach ( glob( $this->dir . '/list*' ) as $file ) {
				file_put_contents( $file, 'GARBAGE-FROM-A-CRASH', FILE_APPEND );
			}
			$done = ( new FileEnumerator( $roots, $this->dir . '/list', [] ) )->run( $cursor, new CountdownDeadline( mt_rand( 0, $maxChecks ) ), static function (): void {} );
			++$runs;
		} while ( ! $done && $runs < 500 );
		$this->assertTrue( $done );

		return $runs;
	}

	public function test_lists_files_in_order_following_symlinks(): void {
		$warnings = [];
		$cursor   = new EnumCursor();
		$done     = ( new FileEnumerator( $this->roots(), $this->dir . '/list', [] ) )->run(
			$cursor,
			new NoDeadline(),
			static function ( string $code, string $entry ) use ( &$warnings ): void {
				$warnings[] = "$code:$entry";
			}
		);

		$this->assertTrue( $done );
		$this->assertSame( self::ALL, $this->entries() );
		$this->assertSame( [], $warnings );
		$this->assertSame( 5, $cursor->files );
		$this->assertSame( 5 + 1 + 2 + 1 + 2, $cursor->bytes );
		$line = FileEnumerator::parseLine( (string) file( $this->dir . '/list' )[1] );
		$this->assertSame( $this->dir . '/wp-content/a dir/b.txt', $line['path'] );
		$this->assertSame( 1, $line['size'] );
		$this->assertSame( $this->dir . '/wp-content/link/o.txt', FileEnumerator::parseLine( (string) file( $this->dir . '/list' )[3] )['path'] );
	}

	public function test_resumes_folder_by_folder_with_the_same_result(): void {
		$cursor = new EnumCursor();
		$runs   = 0;
		do {
			$cursor = EnumCursor::fromArray( json_decode( (string) json_encode( $cursor->toArray() ), true ) );
			file_put_contents( $this->dir . '/list', 'GARBAGE-FROM-A-CRASH', FILE_APPEND );
			$done = ( new FileEnumerator( $this->roots(), $this->dir . '/list', [] ) )->run( $cursor, new CountdownDeadline( 0 ), static function (): void {} );
			++$runs;
		} while ( ! $done && $runs < 50 );

		$this->assertTrue( $done );
		$this->assertGreaterThan( 3, $runs );
		$this->assertSame( self::ALL, $this->entries() );
	}

	public function test_random_stops_in_a_deep_tree_with_loops_give_the_same_list(): void {
		$content = $this->dir . '/wp-content';
		foreach ( [ 'x', 'y', 'z' ] as $a ) {
			foreach ( [ '1', '2', '3' ] as $b ) {
				foreach ( [ 'p', 'q' ] as $c ) {
					$this->write( "wp-content/deep/$a/$b/$c/f.txt", "$a$b$c" );
				}
				symlink( '..', "$content/deep/$a/$b/up" );
			}
		}
		symlink( '../..', $content . '/deep/x/loop' );
		$this->enumerate( $this->roots() );
		$expected = $this->entries();
		$this->assertCount( 5 + 18, $expected );

		mt_srand( 1234 );
		$ticks = 0;
		for ( $round = 0; $round < 5; ++$round ) {
			unlink( $this->dir . '/list' );
			array_map( 'unlink', (array) glob( $this->dir . '/list.*' ) );
			$ticks += $this->enumerateInTicks( $this->roots(), 3 );
			$this->assertSame( $expected, $this->entries(), "round $round" );
		}
		$this->assertGreaterThan( 25, $ticks );
	}

	public function test_cursor_keeps_any_bytes_as_valid_json(): void {
		$cursor = new EnumCursor( 1, true, [ "caf\xe9", 'ok' ], 10, 2, 3, 0, 7 );
		$json   = json_encode( $cursor->toArray() );
		$this->assertNotFalse( $json );
		$copy = EnumCursor::fromArray( json_decode( (string) $json, true ) );
		$this->assertSame( [ "caf\xe9", 'ok' ], $copy->stack );
		$this->assertSame( 7, $copy->visitedBytes );
	}

	public function test_parse_line_rejects_garbage(): void {
		$this->assertNull( FileEnumerator::parseLine( "not a line\n" ) );
	}

	public function test_file_names_with_any_bytes_survive_the_list(): void {
		$name = "two words\nand a line break.txt";
		$this->write( 'wp-content/' . $name, 'r' );

		$this->enumerate( $this->roots() );

		$this->assertContains( $name, $this->entries() );
	}

	public function test_extraction_temp_files_are_never_listed(): void {
		$this->write( 'wp-content/a dir/.lwm-0123456789abcdef.part', 'half' );
		$this->write( 'wp-content/a dir/.lwm-not-ours.part', 'n' );
		$this->write( "wp-content/a dir/.lwm-0123456789abcdef.part\n", 'nl' );

		$warnings = $this->enumerate( $this->roots() );

		$this->assertSame( [ 'index.php', "a dir/.lwm-0123456789abcdef.part\n", 'a dir/.lwm-not-ours.part', 'a dir/b.txt', 'a dir/c/d.txt', 'link/o.txt', 'uploads/2026/10/ékezet.txt' ], $this->entries() );
		$this->assertSame( [], $warnings );
	}

	public function test_a_dangling_symlink_is_warned_with_its_relative_name(): void {
		symlink( $this->dir . '/nowhere', $this->dir . '/wp-content/a dir/broken' );
		$cursor = new EnumCursor();

		$warnings = $this->enumerate( $this->roots(), $cursor );

		$this->assertSame( self::ALL, $this->entries() );
		$this->assertSame( [ 'symlink:a dir/broken' ], $warnings );
		$this->assertSame( 1, $cursor->skipped );
	}

	public function test_a_file_symlink_is_listed_under_its_own_name(): void {
		symlink( $this->dir . '/outside/o.txt', $this->dir . '/wp-content/a dir/o-link.txt' );

		$this->enumerate( $this->roots() );

		$this->assertSame( [ 'index.php', 'a dir/b.txt', 'a dir/o-link.txt', 'a dir/c/d.txt', 'link/o.txt', 'uploads/2026/10/ékezet.txt' ], $this->entries() );
	}

	public function test_a_symlinked_root_is_followed_with_its_own_paths(): void {
		mkdir( $this->dir . '/shared/2026', 0755, true );
		file_put_contents( $this->dir . '/shared/2026/s.jpg', 'sss' );
		$content = $this->dir . '/wp-content';
		rename( $content . '/uploads', $this->dir . '/old-uploads' );
		symlink( $this->dir . '/shared', $content . '/uploads' );

		$warnings = $this->enumerate( $this->roots() );

		$this->assertSame( [ 'index.php', 'a dir/b.txt', 'a dir/c/d.txt', 'link/o.txt', 'uploads/2026/s.jpg' ], $this->entries() );
		$this->assertSame( $content . '/uploads/2026/s.jpg', FileEnumerator::parseLine( (string) file( $this->dir . '/list' )[4] )['path'] );
		$this->assertSame( [], $warnings );
	}

	public function test_a_root_reached_twice_through_a_symlink_is_walked_once(): void {
		$content = $this->dir . '/wp-content';
		symlink( $content . '/uploads', $this->dir . '/uploads-alias' );
		symlink( $content, $this->dir . '/content-alias' );
		$roots   = $this->roots();
		$roots[] = [ 'dir' => $this->dir . '/uploads-alias', 'entry' => 'uploads', 'filter' => new ExportFilter( [] ) ];
		$roots[] = [ 'dir' => $this->dir . '/content-alias', 'entry' => 'themes', 'filter' => new ExportFilter( [] ) ];

		$this->enumerate( $roots );

		$this->assertSame( self::ALL, $this->entries() );
	}

	public function test_a_theme_root_inside_the_plugins_folder_is_listed_once_under_themes(): void {
		$content = $this->dir . '/wp-content';
		$this->write( 'wp-content/plugins/p/p.php', 'p' );
		$this->write( 'wp-content/plugins/p/themes/t/style.css', 't' );
		$roots   = $this->roots();
		$roots[0]['filter'] = new ExportFilter( [ $content . '/skip', $content . '/uploads', $content . '/plugins', $content . '/plugins/p/themes' ] );
		$roots[] = [ 'dir' => $content . '/plugins', 'entry' => 'plugins', 'filter' => new ExportFilter( [] ) ];
		$roots[] = [ 'dir' => $content . '/plugins/p/themes', 'entry' => 'themes', 'filter' => new ExportFilter( [] ) ];

		$this->enumerate( $roots );

		$this->assertSame( [ ...self::ALL, 'plugins/p/p.php', 'themes/t/style.css' ], $this->entries() );
	}

	public function test_a_folder_reached_through_a_symlink_is_still_excluded(): void {
		$content = $this->dir . '/wp-content';
		$this->write( 'wp-content/real-skip/s.txt', 's' );
		symlink( $content . '/real-skip', $content . '/via' );
		$roots              = $this->roots();
		$roots[0]['filter'] = new ExportFilter( [ $content . '/skip', $content . '/uploads', $content . '/real-skip' ] );

		$this->enumerate( $roots );

		$this->assertSame( self::ALL, $this->entries() );
	}

	public function test_a_root_walked_through_a_symlink_keeps_real_path_exclusions(): void {
		$content = $this->dir . '/wp-content';
		symlink( $content, $this->dir . '/content-alias' );
		$roots = [
			[ 'dir' => $this->dir . '/content-alias', 'entry' => '', 'filter' => new ExportFilter( [ $content . '/skip', $content . '/uploads', $content . '/a dir/c', $this->dir . '/outside' ] ) ],
		];

		$this->enumerate( $roots );

		$this->assertSame( [ 'index.php', 'a dir/b.txt' ], $this->entries() );
	}

	public function test_a_folder_gone_between_ticks_is_skipped_silently(): void {
		$cursor = new EnumCursor( 0, true, [ 'gone', 'a dir' ] );

		$warnings = $this->enumerate( $this->roots(), $cursor );

		$this->assertSame( [ 'a dir/b.txt', 'a dir/c/d.txt', 'uploads/2026/10/ékezet.txt' ], $this->entries() );
		$this->assertSame( [], $warnings );
		$this->assertSame( 0, $cursor->skipped );
	}

	public function test_a_list_shorter_than_the_cursor_is_refused(): void {
		file_put_contents( $this->dir . '/list', 'short' );

		$this->expectException( ExportException::class );
		$this->expectExceptionMessage( 'shorter' );
		( new FileEnumerator( $this->roots(), $this->dir . '/list', [] ) )->run( new EnumCursor( 0, false, [], 100 ), new NoDeadline(), static function (): void {} );
	}

	public function test_unreadable_folders_are_warned_with_relative_names_only(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'root reads every folder' );
		}
		mkdir( $this->dir . '/wp-content/locked', 0755 );
		file_put_contents( $this->dir . '/wp-content/locked/secret.txt', 's' );
		chmod( $this->dir . '/wp-content/locked', 0000 );
		chmod( $this->dir . '/wp-content/a dir/c', 0600 );
		$cursor = new EnumCursor();

		$warnings = $this->enumerate( $this->roots(), $cursor );

		$this->assertSame( [ 'index.php', 'a dir/b.txt', 'link/o.txt', 'uploads/2026/10/ékezet.txt' ], $this->entries() );
		$this->assertSame( [ 'unreadable_dir:a dir/c', 'unreadable_dir:locked' ], $warnings );
		$this->assertSame( 2, $cursor->skipped );
		foreach ( $warnings as $warning ) {
			$this->assertStringNotContainsString( $this->dir, $warning );
		}
	}
}
