<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Database;

use LightweightPlugins\Migration\Database\ConnectionGuard;
use LightweightPlugins\Migration\Database\DatabaseException;
use LightweightPlugins\Migration\Database\DumpFormat;
use LightweightPlugins\Migration\Database\ImportCursor;
use LightweightPlugins\Migration\Database\Importer;
use LightweightPlugins\Migration\Database\ImportPlan;
use LightweightPlugins\Migration\Database\InsertCheck;
use LightweightPlugins\Migration\Database\PrefixRules;
use LightweightPlugins\Migration\Database\Replacer;
use LightweightPlugins\Migration\Database\SchemaFixer;
use LightweightPlugins\Migration\Database\SqlEscape;
use LightweightPlugins\Migration\Database\SqlQuotes;
use LightweightPlugins\Migration\Database\SqlValue;
use LightweightPlugins\Migration\Database\StatementReader;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\FakeConnection;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Security review: a value the dump writes comes back byte for byte after the
 * import read, mapped, URL-replaced, checked and ran it; names with a
 * backtick stay one name.
 */
final class DumpRoundTripTest extends TestCase {

	private const TABLE = 'a`b';

	private const COLUMNS = [ 'id', 'c`d', 'bin', 'url' ];

	private const TYPES = [ 'bigint(20) unsigned', 'longtext', 'longblob', 'varchar(200)' ];

	private string $dir;

	protected function setUp(): void {
		$this->dir = TempDir::create();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
	}

	/**
	 * @return array<string, array{0: string|null}>
	 */
	public static function values(): array {
		$all = '';
		for ( $i = 0; $i < 256; $i++ ) {
			$all .= chr( $i );
		}

		return [
			'quotes'                    => [ "it's \"quoted\" ''" ],
			'backslashes'               => [ 'C:\\path\\to\\' ],
			'lone backslash'            => [ '\\' ],
			'escaped quote text'        => [ "\\'" ],
			'like wildcards'            => [ '\\% and \\_' ],
			'escape-looking text'       => [ '\\n\\0\\Z\\t' ],
			'control bytes'             => [ "a\x00b\x1ac\rd\ne\tf\x08g\x0Bh\fi" ],
			'utf-8 multibyte'           => [ 'Árvíztűrő tükörfúrógép 日本語 😀' ],
			'gbk lead byte + 0x5c'      => [ "\xbf\x5c" ],
			'gbk lead byte + quote'     => [ "\xbf\x27 OR 1=1 -- " ],
			'sjis lead + 0x5c + quote'  => [ "\x81\x5c\x27); DROP TABLE wp_users; --" ],
			'ends in 0x5c'              => [ "x\xa3\x5c" ],
			'every byte'                => [ $all ],
			'null'                      => [ null ],
			'the string NULL'           => [ 'NULL' ],
			'empty'                     => [ '' ],
			'hex-looking text'          => [ '0x41' ],
			'statement end inside'      => [ "');\nDROP TABLE x;\n" ],
			'comment-looking text'      => [ '/* -- # */' ],
			'backticks'                 => [ '`lwtab12_x`' ],
			'marker bytes'              => [ "\x01" . '0' . "\x02" ],
			'two megabytes of escapes' => [ str_repeat( "\\'\x00\n\"\x1a\xbf\x5c", 262144 ) ],
		];
	}

	#[DataProvider( 'values' )]
	public function test_a_value_round_trips_through_the_import( ?string $value ): void {
		foreach ( [ 'http://old.test/a', 'no address' ] as $url ) {
			$row = [ '7', $value, $value, $url ];

			$sql = $this->importRow( $row );

			$this->assertTrue( ( new InsertCheck( 'lwtab12_' ) )->isSafe( $sql ) );
			$this->assertSame( [ '7', $value, $value, 'no address' === $url ? $url : 'https://new.test/a' ], self::decode( $sql ) );
		}
	}

	public function test_names_with_a_backtick_stay_one_name(): void {
		$db = new FakeConnection();
		$this->import( $db, $this->dump( [ '1', 'x', 'y', 'z' ] ) );

		$this->assertContains( 'DROP TABLE IF EXISTS `lwtab12_a``b`;', $db->log );
		$this->assertStringStartsWith( 'CREATE TABLE `lwtab12_a``b` (', $this->executed( $db, 'CREATE' ) );
		$this->assertStringStartsWith( 'INSERT INTO `lwtab12_a``b` (`id`,`c``d`,`bin`,`url`) VALUES (', $this->executed( $db, 'INSERT' ) );
	}

	public function test_a_row_larger_than_the_packet_is_skipped_with_a_warning(): void {
		$db     = new FakeConnection();
		$cursor = $this->import( $db, $this->dump( [ '1', str_repeat( 'x', 70000 ), null, 'z' ] ), 65536 );

		$this->assertSame( '', $this->executed( $db, 'INSERT' ) );
		$this->assertStringContainsString( 'larger than the server allows', implode( "\n", $cursor->warnings ) );
	}

	/**
	 * @return array<string, array{0: bool, 1: string}>
	 */
	public static function columnLists(): array {
		return [
			'column list'    => [ true, '`wp_a``b`.`c``d`' ],
			'no column list' => [ false, '`wp_a``b` column 2' ],
		];
	}

	#[DataProvider( 'columnLists' )]
	public function test_a_value_nested_past_the_limit_is_kept_and_named_in_one_warning( bool $columns, string $label ): void {
		$value = str_repeat( 'x', 1048576 ) . ' http://old.test';
		for ( $i = 0; $i < 400; $i++ ) {
			$value = serialize( $value );
		}
		$db     = new FakeConnection();
		$before = memory_get_usage();
		memory_reset_peak_usage();

		$cursor = $this->import( $db, $this->dump( [ '1', $value, null, 'http://old.test/a' ], $columns ) );

		$this->assertLessThan( 64 * 1048576, memory_get_peak_usage() - $before, 'Far under a 256 MB memory limit.' );
		$this->assertSame( [ '1', $value, null, 'https://new.test/a' ], self::decode( $this->executed( $db, 'INSERT' ) ) );
		$nested = array_values( array_filter( $cursor->warnings, static fn( string $w ): bool => str_contains( $w, 'nested' ) ) );
		$this->assertCount( 1, $nested );
		$this->assertStringContainsString( $label, $nested[0] );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function unsafeCharsets(): array {
		$out = [];
		foreach ( [ 'big5', 'cp932', 'gbk', 'gb18030', 'sjis' ] as $charset ) {
			$out[ $charset ] = [ $charset ];
		}

		return $out;
	}

	#[DataProvider( 'unsafeCharsets' )]
	public function test_a_charset_whose_characters_can_end_in_a_backslash_stops_the_import_first( string $charset ): void {
		$db          = new FakeConnection();
		$db->charset = $charset;

		try {
			$this->import( $db, $this->dump( [ '1', "\xbf\x27", null, 'z' ] ) );
			$this->fail( 'Expected a DatabaseException.' );
		} catch ( DatabaseException $e ) {
			$this->assertStringContainsString( $charset . ' character set', $e->getMessage() );
		}
		$this->assertSame( [], $db->log );
	}

	#[DataProvider( 'unsafeCharsets' )]
	public function test_the_dump_refuses_the_same_charsets( string $charset ): void {
		$db          = new FakeConnection();
		$db->charset = strtoupper( $charset );

		$this->expectException( DatabaseException::class );
		ConnectionGuard::assertSafeCharset( $db );
	}

	/**
	 * @param array<int, string|null> $row Values in COLUMNS order.
	 */
	private function importRow( array $row ): string {
		$db = new FakeConnection();
		$this->import( $db, $this->dump( $row ) );

		return $this->executed( $db, 'INSERT' );
	}

	/**
	 * @param array<int, string|null> $row     Values in COLUMNS order.
	 * @param bool                    $columns Whether the INSERT names its columns.
	 */
	private function dump( array $row, bool $columns = true ): string {
		$name   = ( new PrefixRules( 'wp_' ) )->exportTableName( 'wp_' . self::TABLE );
		$create = 'CREATE TABLE ' . DumpFormat::quoteIdentifier( $name ) . " (\n  `id` bigint(20) unsigned NOT NULL,\n  `c``d` longtext,\n  `bin` longblob,\n  `url` varchar(200)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
		$values = array_map( static fn( ?string $v, string $t ): string => SqlValue::encode( $v, $t ), $row, self::TYPES );

		return DumpFormat::header( 'h', 'd', 'mysqli' ) . DumpFormat::table( $name, $create )
			. DumpFormat::START . DumpFormat::insert( $name, $values, $columns ? self::COLUMNS : [] ) . DumpFormat::COMMIT;
	}

	private function import( FakeConnection $db, string $sql, int $packet = 16777216 ): ImportCursor {
		$path = $this->dir . '/db.sql';
		file_put_contents( $path, $sql );
		$plan   = new ImportPlan( 'wp_', 'lwtab12_', PrefixRules::importPairs( 'wp_' ), new Replacer( [ 'http://old.test' => 'https://new.test' ] ), new SchemaFixer( '8.0.36' ), $this->dir . '/views.sql', $packet );
		$cursor = new ImportCursor();
		$reader = new StatementReader( $path );
		try {
			( new Importer( $db, $reader, $plan ) )->run( $cursor, new NoDeadline() );
		} finally {
			$reader->close();
		}

		return $cursor;
	}

	private function executed( FakeConnection $db, string $verb ): string {
		foreach ( $db->log as $sql ) {
			if ( str_starts_with( $sql, $verb . ' ' ) ) {
				return $sql;
			}
		}

		return '';
	}

	/**
	 * Reads the values of a one-row INSERT back with the import's own quote
	 * scanner and literal unescaping.
	 *
	 * @param string $sql INSERT statement.
	 * @return array<int, string|null>
	 */
	private static function decode( string $sql ): array {
		$i   = (int) strpos( $sql, ' VALUES (' ) + 9;
		$out = [];
		while ( true ) {
			if ( "'" === $sql[ $i ] ) {
				$end   = (int) SqlQuotes::closingQuote( $sql, $i );
				$out[] = SqlEscape::unescape( substr( $sql, $i + 1, $end - $i - 1 ) );
				$i     = $end + 1;
			} else {
				preg_match( '/\G[^,)]+/', $sql, $m, 0, $i );
				$out[] = 'NULL' === $m[0] ? null : ( str_starts_with( $m[0], '0x' ) ? (string) hex2bin( substr( $m[0], 2 ) ) : $m[0] );
				$i    += strlen( $m[0] );
			}
			if ( ')' === $sql[ $i ] ) {
				return $out;
			}
			++$i;
		}
	}
}
