<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Export\Estimate;

use LightweightPlugins\Migration\Export\EnumCursor;
use LightweightPlugins\Migration\Export\Estimate\EstimateParts;
use LightweightPlugins\Migration\Export\Estimate\FileSizes;
use LightweightPlugins\Migration\Export\Estimate\FileTally;
use LightweightPlugins\Migration\Export\ExportOptions;
use LightweightPlugins\Migration\Export\ExportRoots;
use LightweightPlugins\Migration\Export\FileEnumerator;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\SiteFixture;
use LightweightPlugins\Migration\Tests\Support\SiteTree;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use LightweightPlugins\Migration\WordPress\Site;

final class FileSizesTest extends MonkeyTestCase {

	/** Files on top of SiteTree: a part each, and what a backup never carries. */
	private const EXTRA = [
		'wp-content/plugins/hello.php'           => "<?php // hello dolly\n",
		'wp-content/mu-plugins/sub/lib.php'      => "<?php // loaded by host.php\n",
		'wp-content/cache/page.html'             => "<html>cached</html>\n",
		'wp-content/blogs.dir/2/files/old.txt'   => "old upload\n",
		'wp-content/uploads/2026/10/second.jpg'  => "jpeg bytes\n",
		'wp-content/languages/hu_HU.mo'          => "mo\n",
		'wp-content/database.sql'                => "-- a dump left behind\n",
		'wp-content/plugins/sqlite-database-integration/load.php' => "<?php // sqlite\n",
	];

	private string $dir;

	private string $storage;

	private Site $site;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = TempDir::create();
		SiteTree::create( $this->dir );
		foreach ( self::EXTRA as $rel => $data ) {
			if ( ! is_dir( dirname( $this->dir . '/' . $rel ) ) ) {
				mkdir( dirname( $this->dir . '/' . $rel ), 0755, true );
			}
			file_put_contents( $this->dir . '/' . $rel, $data );
		}
		$this->storage = $this->dir . '/wp-content/lw-migration-0123456789ab';
		$this->site    = SiteFixture::make( $this->dir, [ 'plugins' => [ 'lw-migration/lw-migration.php', 'sample/sample.php', 'inactive/inactive.php', 'hello.php', 'sqlite-database-integration/load.php' ] ] );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	public function test_each_part_counts_the_files_of_a_seeded_tree(): void {
		$parts = $this->measure( new NoDeadline() );

		$this->assertSame( [ 'bytes' => $this->bytes( 'uploads/2026/10/photo.txt', 'uploads/2026/10/second.jpg', 'blogs.dir/2/files/old.txt' ), 'files' => 3 ], $parts['media'] );
		$this->assertSame(
			[
				'bytes'       => $this->bytes( 'plugins/sample/sample.php', 'plugins/inactive/inactive.php', 'plugins/hello.php' ),
				'count'       => 3,
				'activeBytes' => $this->bytes( 'plugins/sample/sample.php' ),
				'activeCount' => 1,
			],
			$parts['plugins'],
			'This plugin and the SQLite plugin never travel; inactive.php and hello.php are left out with the inactive ones.'
		);
		$this->assertSame(
			[
				'bytes'       => $this->bytes( 'themes/twentytwentyfive/style.css', 'themes/old-theme/style.css' ),
				'count'       => 2,
				'activeBytes' => $this->bytes( 'themes/twentytwentyfive/style.css' ),
				'activeCount' => 1,
			],
			$parts['themes']
		);
		$this->assertSame( [ 'bytes' => $this->bytes( 'mu-plugins/host.php', 'mu-plugins/sub/lib.php' ), 'count' => 1 ], $parts['muPlugins'], 'Only the PHP files directly in mu-plugins are loaded as must-use plugins.' );
		$this->assertSame( [ 'bytes' => $this->bytes( 'cache/page.html' ) ], $parts['cache'] );
		$this->assertSame( [ 'bytes' => $this->bytes( 'index.php', 'languages/hu_HU.mo' ) ], $parts['other'], 'Our storage and a leftover dump are never counted.' );
		$this->assertNull( $parts['database'] );
	}

	/**
	 * What the estimate says a choice adds up to is what an export with that
	 * choice lists, byte for byte.
	 *
	 * @param array<string, bool> $options Export options.
	 * @param array<int, string>  $minus   Estimate figures to subtract from the whole ("part.key" or "part.key-part.key2").
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'choices' )]
	public function test_the_estimate_matches_what_an_export_lists( array $options, array $minus ): void {
		$parts    = $this->measure( new NoDeadline() );
		$expected = array_sum( array_map( static fn( array $part ): int => $part['bytes'], array_filter( $parts ) ) );
		foreach ( $minus as $figure ) {
			$expected -= $this->figure( $parts, $figure );
		}

		$this->assertSame( $expected, $this->exported( ExportOptions::fromArray( $options ) ) );
	}

	/**
	 * @return array<string, array{0: array<string, bool>, 1: array<int, string>}>
	 */
	public static function choices(): array {
		return [
			'everything'             => [ [], [] ],
			'no media'               => [ [ 'noMedia' => true ], [ 'media.bytes' ] ],
			'no plugins'             => [ [ 'noPlugins' => true ], [ 'plugins.bytes' ] ],
			'active plugins only'    => [ [ 'noInactivePlugins' => true ], [ 'plugins.bytes-plugins.activeBytes' ] ],
			'no themes'              => [ [ 'noThemes' => true ], [ 'themes.bytes' ] ],
			'active theme only'      => [ [ 'noInactiveThemes' => true ], [ 'themes.bytes-themes.activeBytes' ] ],
			'no must-use plugins'    => [ [ 'noMustUsePlugins' => true ], [ 'muPlugins.bytes' ] ],
			'no cache'               => [ [ 'noCache' => true ], [ 'cache.bytes' ] ],
			'only what is in use'    => [
				[
					'noInactivePlugins' => true,
					'noInactiveThemes'  => true,
					'noCache'           => true,
				],
				[ 'plugins.bytes-plugins.activeBytes', 'themes.bytes-themes.activeBytes', 'cache.bytes' ],
			],
		];
	}

	public function test_a_slice_at_a_time_counts_every_file_once(): void {
		$whole = $this->measure( new NoDeadline() );
		$saved = $this->fresh();
		$runs  = 0;
		do {
			[ $done, $next ] = $this->slice( $saved );
			if ( 2 === $runs ) {
				// This request dies after its run: nothing of it is saved, the next one redoes it.
				$this->assertFalse( $done );
				++$runs;
				continue;
			}
			$saved = $next;
			++$runs;
		} while ( ! $done && $runs < 100 );

		$this->assertTrue( $done );
		$this->assertGreaterThan( 3, $runs, 'One folder per slice.' );
		$this->assertSame( $whole, EstimateParts::of( $this->state( $saved['tally'] ) ) );
		$this->assertSame( [], $this->workFiles(), 'A finished walk removes its work files.' );
	}

	public function test_a_slice_that_lost_its_lease_never_writes_into_the_saved_work(): void {
		$whole = $this->measure( new NoDeadline() );
		$saved = $this->slice( $this->slice( $this->fresh() )[1] )[1];
		$this->slice( $saved ); // Took too long: another request took the lease, nothing of it is saved.
		[ , $taker ] = $this->slice( $saved ); // The request that took over, saved.
		$owned       = $this->workFiles( $taker['work'] );
		$before      = array_map( 'md5_file', $owned );
		$this->slice( $saved ); // The slow one again, after the save: it still writes only its own files.

		$this->assertSame( $before, array_map( 'md5_file', $owned ), 'The saved slice\'s files are untouched.' );
		$saved = $taker;
		do {
			[ $done, $saved ] = $this->slice( $saved );
		} while ( ! $done );
		$this->assertSame( $whole, EstimateParts::of( $this->state( $saved['tally'] ) ), 'Every file counted once.' );
	}

	public function test_a_listed_slice_keeps_only_its_visited_folders_file(): void {
		[ , $saved ] = $this->slice( $this->fresh() );

		$this->assertSame( [ FileSizes::PREFIX . $saved['work'] . '.list' . FileEnumerator::VISITED_SUFFIX ], array_map( 'basename', $this->workFiles() ), 'The list of a slice is read and removed at once.' );
	}

	/**
	 * @return array{cursor: array<string, mixed>, tally: array<string, mixed>, work: string}
	 */
	private function fresh(): array {
		return [
			'cursor' => ( new EnumCursor() )->toArray(),
			'tally'  => [],
			'work'   => '',
		];
	}

	/**
	 * One slice (one folder) from a saved state, under a new work id.
	 *
	 * @param array{cursor: array<string, mixed>, tally: array<string, mixed>, work: string} $saved Saved state.
	 * @return array{0: bool, 1: array{cursor: array<string, mixed>, tally: array<string, mixed>, work: string}} Done, and the state to save.
	 */
	private function slice( array $saved ): array {
		$cursor = EnumCursor::fromArray( $saved['cursor'] );
		$tally  = new FileTally( $saved['tally'] );
		$work   = bin2hex( random_bytes( 8 ) );
		$done   = ( new FileSizes( $this->storage . '/jobs/estimate', $this->storage ) )->run( $this->site, $cursor, $tally, new CountdownDeadline( 0 ), $saved['work'], $work );
		$this->assertSame( 0, $cursor->listBytes, 'The list holds one slice at most.' );

		return [
			$done,
			[
				'cursor' => $cursor->toArray(),
				'tally'  => $tally->toArray(),
				'work'   => $work,
			],
		];
	}

	/**
	 * @param string $work Work id ('' = every work file).
	 * @return array<int, string>
	 */
	private function workFiles( string $work = '' ): array {
		return glob( $this->storage . '/jobs/estimate/' . FileSizes::PREFIX . ( '' === $work ? '' : $work . '.' ) . '*' ) ?: [];
	}

	/**
	 * @param \LightweightPlugins\Migration\Support\Deadline $deadline Time budget.
	 * @return array<string, array<string, int>|null>
	 */
	private function measure( $deadline ): array {
		$cursor = new EnumCursor();
		$tally  = new FileTally();
		$this->assertTrue( ( new FileSizes( $this->storage . '/jobs/estimate', $this->storage ) )->run( $this->site, $cursor, $tally, $deadline, '', bin2hex( random_bytes( 8 ) ) ) );

		return EstimateParts::of( $this->state( $tally->toArray() ) );
	}

	/**
	 * @param array<string, mixed> $tally Tally.
	 * @return array<string, mixed> An EstimateScan state in the files phase.
	 */
	private function state( array $tally ): array {
		return [
			'phase'    => 'done',
			'site'     => $this->site->toArray(),
			'network'  => null,
			'database' => null,
			'tally'    => $tally,
		];
	}

	private function exported( ExportOptions $options ): int {
		$roots  = ExportRoots::build( $this->site, $options, $this->storage );
		$list   = $this->dir . '/export.list';
		$cursor = new EnumCursor();
		( new FileEnumerator( $roots, $list, ExportRoots::territories( $this->site ) ) )->run( $cursor, new NoDeadline(), static function (): void {} );

		return $cursor->bytes;
	}

	private function bytes( string ...$rels ): int {
		$files = array_merge( SiteTree::FILES, self::EXTRA );

		return array_sum( array_map( static fn( string $rel ): int => strlen( $files[ 'wp-content/' . $rel ] ), $rels ) );
	}

	/**
	 * @param array<string, array<string, int>|null> $parts  Estimate parts.
	 * @param string                                 $figure "part.key" or "part.key-part.key2".
	 */
	private function figure( array $parts, string $figure ): int {
		$value = 0;
		foreach ( explode( '-', $figure ) as $i => $term ) {
			[ $part, $key ] = explode( '.', $term );
			$value         += ( 0 === $i ? 1 : -1 ) * (int) $parts[ $part ][ $key ];
		}

		return $value;
	}
}
