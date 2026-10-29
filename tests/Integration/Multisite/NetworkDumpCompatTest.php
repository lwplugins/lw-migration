<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Integration\Multisite;

use LightweightPlugins\Migration\Archive\Format;
use LightweightPlugins\Migration\Archive\Reader;
use LightweightPlugins\Migration\Database\SchemaFixer;
use LightweightPlugins\Migration\Database\SessionMode;
use LightweightPlugins\Migration\Database\StatementReader;
use LightweightPlugins\Migration\Multisite\ExportScope;
use LightweightPlugins\Migration\Tests\Integration\DatabaseTestCase;
use LightweightPlugins\Migration\Tests\Support\TempDir;

/**
 * Our network and sites dumps name, order and prefix their tables and keys as the
 * reference-made archives of the same network do (Task 1 fixtures). The test server
 * gets the reference network itself: its whole-network dump with the plain
 * placeholder turned back into wp_.
 */
final class NetworkDumpCompatTest extends DatabaseTestCase {

	private const DIR = __DIR__ . '/../../Fixtures/wpress/';

	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = TempDir::create();
		SessionMode::relaxed( self::db(), fn() => $this->loadNetwork( $this->referenceDump( 'network.wpress' ) ) ); // Zero dates, as the importer allows them.
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
	}

	/** @return array<string, array{0: string, 1: array<int, int>|null}> */
	public static function archives(): array {
		return [
			'network'   => [ 'network.wpress', null ],
			'sites-2'   => [ 'sites-2.wpress', [ 2 ] ],
			'sites-1-3' => [ 'sites-1-3.wpress', [ 1, 3 ] ],
		];
	}

	/**
	 * @dataProvider archives
	 * @param string               $file   Reference archive.
	 * @param array<int, int>|null $chosen Its sites; null = the whole network.
	 */
	public function test_tables_and_prefixed_keys_match_the_reference( string $file, ?array $chosen ): void {
		$facts = (array) json_decode( (string) file_get_contents( self::DIR . 'network-fixtures.json' ), true );
		$ours  = NetworkTables::dump( self::db(), new ExportScope( 'wp_', [ 1, 2, 3 ], $chosen ), $this->dir . '/ours.sql' );

		$this->assertSame( $facts['tables'][ $file ], NetworkTables::createdTables( $ours ), 'Same CREATE TABLE names in the same order.' );
		$reference = self::prefixedKeys( $this->referenceDump( $file ) );
		$this->assertNotSame( [], $reference );
		$referenceSql = $this->referenceDump( $file );
		$this->assertSame( self::users( $referenceSql ), self::users( $ours ), 'The same users.' );
		$this->assertSame( self::userMeta( $referenceSql ), self::userMeta( $ours ), 'The same usermeta rows.' );
		$this->assertSame( $reference, self::prefixedKeys( $ours ), 'The same prefixed option names and meta keys as the reference, with the same placeholder variant in the same table.' );
	}

	/**
	 * @param string $sql A dump.
	 * @return array<int, string> "table|value" of every option_name / meta_key carrying the placeholder.
	 */
	private static function prefixedKeys( string $sql ): array {
		preg_match_all( "/^INSERT INTO `(SERVMASK_PREFIX_[A-Za-z0-9_]*options)` VALUES \\([0-9]+,'(SERVMASK_PREFIX_[^']*)'/m", $sql, $options, PREG_SET_ORDER );
		preg_match_all( "/^INSERT INTO `(SERVMASK_PREFIX_[A-Za-z0-9_]*usermeta)` VALUES \\([0-9]+,[0-9]+,'(SERVMASK_PREFIX_[^']*)'/m", $sql, $meta, PREG_SET_ORDER );
		$keys = array_unique( array_map( static fn( array $m ): string => $m[1] . '|' . $m[2], array_merge( $options, $meta ) ) );
		sort( $keys );

		return $keys;
	}

	/**
	 * @param string $sql A dump.
	 * @return array<int, string> Logins of the users table.
	 */
	private static function users( string $sql ): array {
		preg_match_all( "/^INSERT INTO `SERVMASK_PREFIX_[A-Za-z0-9_]*users` VALUES \\([0-9]+,'([^']*)'/m", $sql, $m );
		$logins = $m[1];
		sort( $logins );

		return $logins;
	}

	/**
	 * @param string $sql A dump.
	 * @return array<int, string> "user_id|meta_key" of the usermeta table.
	 */
	private static function userMeta( string $sql ): array {
		preg_match_all( "/^INSERT INTO `SERVMASK_PREFIX_[A-Za-z0-9_]*usermeta` VALUES \\([0-9]+,([0-9]+),'([^']*)'/m", $sql, $m, PREG_SET_ORDER );
		$rows = array_map( static fn( array $r ): string => $r[1] . '|' . $r[2], $m );
		sort( $rows );

		return $rows;
	}

	private function referenceDump( string $file ): string {
		$reader = new Reader( self::DIR . $file );
		try {
			foreach ( $reader->entries() as $entry ) {
				if ( Format::DATABASE === $entry->name ) {
					return $reader->readContents( $entry, 64 * 1048576 );
				}
			}
		} finally {
			$reader->close();
		}
		throw new \RuntimeException( 'No database.sql in ' . $file );
	}

	private function loadNetwork( string $sql ): void {
		$path = $this->dir . '/network.sql';
		file_put_contents( $path, str_replace( 'SERVMASK_PREFIX_', 'wp_', $sql ) );
		$fixer  = new SchemaFixer( self::db()->serverVersion() );
		$reader = new StatementReader( $path );
		$offset = 0;
		try {
			for ( $s = $reader->next( $offset, 1048576 ); null !== $s; $s = $reader->next( $offset, 1048576 ) ) {
				$offset = $s->end;
				$query  = trim( $s->sql );
				self::db()->execute( str_starts_with( $query, 'CREATE TABLE' ) ? $fixer->fixCreate( $query ) : $query );
			}
		} finally {
			$reader->close();
		}
	}
}
