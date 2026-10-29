<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Integration\Database;

use LightweightPlugins\Migration\Database\DumpCursor;
use LightweightPlugins\Migration\Database\Dumper;
use LightweightPlugins\Migration\Database\DumpOptions;
use LightweightPlugins\Migration\Database\ImportCursor;
use LightweightPlugins\Migration\Database\Importer;
use LightweightPlugins\Migration\Database\ImportPlan;
use LightweightPlugins\Migration\Database\PrefixRules;
use LightweightPlugins\Migration\Database\Replacer;
use LightweightPlugins\Migration\Database\SchemaFixer;
use LightweightPlugins\Migration\Database\SiteTarget;
use LightweightPlugins\Migration\Database\StatementReader;
use LightweightPlugins\Migration\Database\TableLister;
use LightweightPlugins\Migration\Database\TableSwap;
use LightweightPlugins\Migration\Database\UrlPairs;
use LightweightPlugins\Migration\Package\PackageConfig;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Integration\DatabaseTestCase;
use LightweightPlugins\Migration\Tests\Support\TempDir;

/**
 * Compatibility with dumps written by the reference exporter, in both directions.
 * tests/Fixtures/sql/reference-dump.sql is the reference exporter's dump of seed.sql.
 */
final class ReferenceCompatTest extends DatabaseTestCase {

	private const FIXTURES = __DIR__ . '/../../Fixtures/sql/';

	private string $dir;

	/** @var array<int, array<string, string|null>> */
	private array $seedBinary = [];

	protected function setUp(): void {
		parent::setUp();
		$this->dir = TempDir::create();
		$this->loadSeed();
		$this->seedBinary = $this->binaryRows();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
	}

	public function test_our_dump_matches_reference_dump(): void {
		$fixture = (array) json_decode( (string) file_get_contents( self::FIXTURES . 'reference-dump.json' ), true );
		if ( self::majorMinor( (string) $fixture['server'] ) !== self::majorMinor( self::db()->serverVersion() ) ) {
			$this->markTestSkipped( sprintf( 'The reference dump was made on %s; this server is %s.', $fixture['server'], self::db()->serverVersion() ) );
		}
		$skip = (int) $fixture['header_lines'];

		$ours      = self::withoutHeader( (string) file_get_contents( $this->ourDump() ), $skip );
		$reference = self::withoutHeader( (string) file_get_contents( self::FIXTURES . 'reference-dump.sql' ), $skip );
		$this->assertSame( $reference, $ours );
	}

	public function test_reference_dump_imports_and_swaps(): void {
		$this->dropAll();
		$this->importAndSwap( self::FIXTURES . 'reference-dump.sql' );
	}

	public function test_round_trip_our_dump(): void {
		$path = $this->ourDump();
		$this->dropAll();
		$this->importAndSwap( $path );
	}

	private function importAndSwap( string $path ): void {
		$tmp     = TableSwap::newTempPrefix();
		$package = PackageConfig::fromArray(
			[
				'SiteURL'   => 'http://old.test',
				'HomeURL'   => 'http://old.test',
				'WordPress' => [
					'UploadsURL' => 'http://old.test/wp-content/uploads/',
					'Absolute'   => '/var/old/',
					'Content'    => '/var/old/wp-content',
				],
			]
		);
		$pairs   = UrlPairs::build( $package, new SiteTarget( 'https://new.test', 'https://new.test', 'https://new.test/wp-content/uploads/', 'wp-content/uploads', '/var/new/', '/var/new/wp-content' ) );
		$plan    = new ImportPlan( 'wp_', $tmp, PrefixRules::importPairs( 'wp_' ), new Replacer( $pairs->pairs(), $pairs->raw() ), new SchemaFixer( self::db()->serverVersion() ), $this->dir . '/views.sql', 16777216 );
		$cursor  = new ImportCursor();
		$done    = false;
		for ( $i = 0; $i < 100 && ! $done; $i++ ) {
			$done = ( new Importer( self::db(), new StatementReader( $path ), $plan ) )->run( $cursor, new NoDeadline() );
		}
		$this->assertTrue( $done );
		$swap     = new TableSwap( self::db(), $tmp, 'wp_', $plan->extraPrefixes );
		$warnings = array_merge( $cursor->warnings, $swap->swap()->warnings, $swap->applyDeferred( $this->dir . '/views.sql' ), $swap->cleanup() );
		$this->assertSame( [], $warnings );
		$this->assertSame( 0, $cursor->warningCount );

		$this->assertSame( 'https://new.test', $this->option( 'siteurl' ) );
		$this->assertSame( 'https://new.test', $this->option( 'home' ) );
		$nested = unserialize( (string) $this->option( 'lw_nested' ) );
		$this->assertIsArray( $nested );
		$this->assertSame( [ 'url' => 'https://new.test/wp-content/uploads/a.jpg' ], unserialize( (string) $nested['image'] ) );
		$this->assertNull( $this->option( '_transient_foo' ) );
		$this->assertNotNull( $this->option( 'wp_user_roles' ) );
		$this->assertSame( 'a:0:{}', $this->option( 'active_plugins' ) );
		$this->assertSame( "Árvíztűrő \"tükör\" fúró's \\ blog\n\r\x1a", $this->option( 'blogname' ) );
		$this->assertSame( [ 'wp_capabilities', 'wp_user_level' ], array_column( self::db()->query( 'SELECT meta_key FROM wp_usermeta ORDER BY umeta_id' ), 'meta_key' ) );
		$this->assertSame( '2500', self::db()->query( 'SELECT COUNT(*) AS n FROM wp_big' )[0]['n'] );
		$this->assertSame( '3', self::db()->query( 'SELECT COUNT(*) AS n FROM wp_nopk' )[0]['n'] );
		$this->assertSame( '0', self::db()->query( 'SELECT COUNT(*) AS n FROM wp_empty' )[0]['n'] );
		$this->assertSame( $this->seedBinary, $this->binaryRows() );
		$this->assertSame( [ 'Anna', 'Béla' ], array_column( self::db()->query( 'SELECT name FROM wbk_bookings ORDER BY id' ), 'name' ) );
		$content = (string) self::db()->query( 'SELECT post_content FROM wp_posts WHERE ID = 1' )[0]['post_content'];
		$this->assertStringContainsString( 'href="https://new.test/hello-world/"', $content );
		$this->assertStringContainsString( "data-src='new.test/x'", $content );
		$this->assertStringNotContainsString( 'old.test', $content );
		$this->assertSame( [ [ 'Table_type' => 'VIEW' ] ], self::db()->query( "SELECT TABLE_TYPE AS Table_type FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wp_v'" ) );
		$this->assertCount( 6, self::db()->query( 'SELECT option_name FROM wp_v' ) );
	}

	private function ourDump(): string {
		$path   = $this->dir . '/ours.sql';
		$listed = ( new TableLister( self::db() ) )->list( [ 'wp_', 'wbk_', 'civicrm_' ] );
		$dumper = new Dumper( self::db(), new DumpOptions( 'wp_' ), $listed['tables'], $listed['views'], $path );
		$cursor = new DumpCursor();
		$done   = false;
		for ( $i = 0; $i < 100 && ! $done; $i++ ) {
			$done = $dumper->run( $cursor, new NoDeadline() );
		}
		$this->assertTrue( $done );

		return $path;
	}

	private function loadSeed(): void {
		$reader = new StatementReader( self::FIXTURES . 'seed.sql' );
		$offset = 0;
		try {
			for ( $s = $reader->next( $offset, 1048576 ); null !== $s; $s = $reader->next( $offset, 1048576 ) ) {
				$offset = $s->end;
				self::db()->execute( $s->sql );
			}
		} finally {
			$reader->close();
		}
	}

	private function dropAll(): void {
		$this->exec( 'DROP VIEW wp_v' );
		foreach ( ( new TableLister( self::db() ) )->list( [] )['tables'] as $table ) {
			$this->exec( 'DROP TABLE `' . $table . '`' );
		}
	}

	private function option( string $name ): ?string {
		$rows = self::db()->query( "SELECT option_value FROM wp_options WHERE option_name = '" . self::db()->escape( $name ) . "'" );

		return [] === $rows ? null : $rows[0]['option_value'];
	}

	/**
	 * @return array<int, array<string, string|null>>
	 */
	private function binaryRows(): array {
		return self::db()->query( 'SELECT id, HEX(blob_value) AS b, HEX(vb_value) AS v, blob_value IS NULL AS bn FROM wp_bin ORDER BY id' );
	}

	private static function majorMinor( string $version ): string {
		return implode( '.', array_slice( explode( '.', SchemaFixer::parseVersion( $version ) ), 0, 2 ) ) . ( false !== stripos( $version, 'maria' ) ? '-mariadb' : '' );
	}

	private static function withoutHeader( string $sql, int $lines ): string {
		return implode( "\n", array_slice( explode( "\n", $sql ), $lines ) );
	}
}
