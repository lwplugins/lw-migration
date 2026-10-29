<?php
/**
 * TmpTables refuses any prefix that could address a live table and writes only lwt… tables.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Import;

use LightweightPlugins\Migration\Database\DatabaseException;
use LightweightPlugins\Migration\Import\TmpTables;
use LightweightPlugins\Migration\Tests\Support\FakeConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TmpTablesGuardTest extends TestCase {

	private const WRITE = '/^(?:INSERT\s+INTO|DELETE\s+FROM|UPDATE|REPLACE\s+INTO)\s+`((?:[^`]|``)+)`/i';

	private function admin( FakeConnection $db, bool $inArchive ): void {
		$db->rows['SELECT user_login, user_pass FROM `wp_users`'] = [ [ 'user_login' => 'admin', 'user_pass' => '$P$live' ] ];
		$db->rows['SELECT ID FROM `lwtab12_users`']               = $inArchive ? [ [ 'ID' => '5' ] ] : [];
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function unsafePrefixes(): array {
		return [
			'live prefix as temp'      => [ 'wp_', 'wp_' ],
			'upper case'               => [ 'lwtAB12_', 'wp_' ],
			'no underscore'            => [ 'lwtab12', 'wp_' ],
			'five characters'          => [ 'lwtab123_', 'wp_' ],
			'backtick'                 => [ 'lwt`a1_', 'wp_' ],
			'replaced-table prefix'    => [ 'lwoab12_', 'wp_' ],
			'empty temp'               => [ '', 'wp_' ],
			'base equals temp'         => [ 'lwtab12_', 'lwtab12_' ],
			'base starts with temp'    => [ 'lwtab12_', 'lwtab12_wp_' ],
			'base is a temp prefix'    => [ 'lwtab12_', 'lwtcd34_' ],
			'base with a backtick'     => [ 'lwtab12_', 'wp`_' ],
			'base with a space'        => [ 'lwtab12_', 'wp _' ],
			'empty base'               => [ 'lwtab12_', '' ],
			'temp with a newline'      => [ "lwtab12_\n", 'wp_' ],
			'base with a newline'      => [ 'lwtab12_', "wp_\n" ],
		];
	}

	#[DataProvider( 'unsafePrefixes' )]
	public function test_unsafe_prefixes_are_refused( string $tmp, string $base ): void {
		$this->expectException( DatabaseException::class );
		new TmpTables( new FakeConnection(), $tmp, $base );
	}

	public function test_a_table_suffix_must_be_a_plain_identifier(): void {
		$this->expectException( DatabaseException::class );
		( new TmpTables( new FakeConnection(), 'lwtab12_', 'wp_' ) )->has( 'options` WHERE 1' );
	}

	public function test_a_table_suffix_with_a_trailing_newline_is_refused(): void {
		$this->expectException( DatabaseException::class );
		( new TmpTables( new FakeConnection(), 'lwtab12_', 'wp_' ) )->has( "options\n" );
	}

	public function test_every_write_targets_a_temporary_table_inside_a_transaction(): void {
		$db = new FakeConnection();
		$this->admin( $db, true );
		$t = new TmpTables( $db, 'lwtab12_', 'wp_site_' );
		$t->copyOptions( [ 'siteurl', "o'brien" ] );
		$t->copyOptionPrefixes( [ 'lw_migration_' ] );
		$t->setOption( 'active_plugins', 'a:0:{}' );
		$db->rows['SELECT user_login, user_pass FROM `wp_site_users`'] = [ [ 'user_login' => 'admin', 'user_pass' => '$P$live' ] ];
		$this->assertSame( 5, $t->keepAdmin( 1 ) );

		$writes = 0;
		$open   = false;
		foreach ( $db->log as $sql ) {
			if ( 'START TRANSACTION' === $sql ) {
				$this->assertFalse( $open, 'Transactions are not nested.' );
				$open = true;
				continue;
			}
			if ( 'COMMIT' === $sql ) {
				$this->assertTrue( $open );
				$open = false;
				continue;
			}
			$this->assertSame( 1, preg_match( self::WRITE, $sql, $m ), "Unexpected statement: {$sql}" );
			$this->assertStringStartsWith( 'lwtab12_', $m[1], $sql );
			$this->assertTrue( $open, "Write outside a transaction: {$sql}" );
			++$writes;
		}
		$this->assertFalse( $open );
		$this->assertSame( 10, $writes );
	}

	public function test_adding_the_admin_writes_only_temporary_tables(): void {
		$db = new FakeConnection();
		$this->admin( $db, false );
		try {
			( new TmpTables( $db, 'lwtab12_', 'wp_' ) )->keepAdmin( 1 );
			$this->fail( 'The fake never returns the inserted row.' );
		} catch ( DatabaseException $e ) {
			$this->assertSame( 'ROLLBACK', end( $db->log ) );
		}
		$inserts = preg_grep( '/^INSERT INTO `lwtab12_users`/', $db->log );
		$this->assertCount( 1, $inserts );
		foreach ( $db->log as $sql ) {
			if ( 1 === preg_match( self::WRITE, $sql, $m ) ) {
				$this->assertStringStartsWith( 'lwtab12_', $m[1] );
			}
		}
	}

	public function test_values_and_like_patterns_are_escaped(): void {
		$db = new FakeConnection();
		$t  = new TmpTables( $db, 'lwtab12_', 'wp_' );
		$t->setOption( "a'b", "x'); DROP TABLE wp_users; --" );
		$t->copyOptionPrefixes( [ "lw_%'" ] );

		$sql = implode( "\n", $db->log );
		$this->assertStringContainsString( "'a\\'b'", $sql );
		$this->assertStringContainsString( "'x\\'); DROP TABLE wp_users; --'", $sql );
		$this->assertStringContainsString( "LIKE 'lw\\\\_\\\\%\\'%'", $sql );
	}

	public function test_a_row_left_out_of_the_carry_is_dropped_and_never_copied(): void {
		$db = new FakeConnection();
		( new TmpTables( $db, 'lwtab12_', 'wp_' ) )->copyOptionPrefixes( [ 'lw_migration_' ], [ 'lw_migration_export_estimate' ] );

		$delete = (string) current( preg_grep( '/^DELETE FROM `lwtab12_options`/', $db->log ) );
		$insert = (string) current( preg_grep( '/^INSERT INTO `lwtab12_options`/', $db->log ) );
		$this->assertStringNotContainsString( 'NOT IN', $delete, 'A copy of it from the archive goes too.' );
		$this->assertStringContainsString( "AND option_name NOT IN ('lw_migration_export_estimate')", $insert );
	}

	public function test_a_failed_write_is_rolled_back_and_rethrown(): void {
		$db = ( new FakeConnection() )->failOn( 'INSERT', 2006 );
		try {
			( new TmpTables( $db, 'lwtab12_', 'wp_' ) )->setOption( 'x', 'y' );
			$this->fail( 'Expected the simulated error.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 2006, $e->getCode() );
		}
		$this->assertSame( [ 'START', 'DELETE', 'INSERT', 'ROLLBACK' ], array_map( static fn( string $s ): string => explode( ' ', $s )[0], $db->log ) );
	}

	public function test_empty_lists_send_no_statement(): void {
		$db = new FakeConnection();
		$t  = new TmpTables( $db, 'lwtab12_', 'wp_' );
		$t->copyOptions( [] );
		$t->copyOptionPrefixes( [] );

		$this->assertSame( [], $db->log );
	}
}
