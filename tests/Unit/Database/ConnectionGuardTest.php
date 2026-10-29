<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Database;

use LightweightPlugins\Migration\Database\ConnectionGuard;
use LightweightPlugins\Migration\Database\DatabaseException;
use LightweightPlugins\Migration\Tests\Support\FakeConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #14: the quote scanner relies on the session's charset and sql_mode.
 */
final class ConnectionGuardTest extends TestCase {

	public function test_a_safe_client_and_connection_charset_pass(): void {
		$this->expectNotToPerformAssertions();
		ConnectionGuard::assertSafeCharset( new FakeConnection() );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function unsafeSides(): array {
		return [
			'client only'     => [ 'SELECT @@character_set_client', 'character_set_client' ],
			'connection only' => [ 'SELECT @@character_set_connection', 'character_set_connection' ],
		];
	}

	#[DataProvider( 'unsafeSides' )]
	public function test_an_unsafe_charset_on_either_side_is_refused( string $query, string $variable ): void {
		$db                 = new FakeConnection();
		$db->rows[ $query ] = [ [ 'cs' => 'GBK' ] ];

		$this->expectException( DatabaseException::class );
		$this->expectExceptionMessage( 'The database connection uses the gbk character set (' . $variable . ')' );
		ConnectionGuard::assertSafeCharset( $db );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function safeModes(): array {
		return [
			'empty'          => [ '' ],
			'strict'         => [ 'STRICT_TRANS_TABLES,NO_ZERO_DATE' ],
			'similar prefix' => [ 'NO_BACKSLASH_ESCAPES_X,ANSI_QUOTES_X' ],
		];
	}

	#[DataProvider( 'safeModes' )]
	public function test_a_mode_with_backslash_escapes_passes( string $mode ): void {
		$db          = new FakeConnection();
		$db->sqlMode = $mode;

		$this->expectNotToPerformAssertions();
		ConnectionGuard::assertSqlMode( $db );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function unsafeModes(): array {
		return [
			'alone'           => [ 'NO_BACKSLASH_ESCAPES' ],
			'among others'    => [ 'STRICT_TRANS_TABLES,NO_BACKSLASH_ESCAPES,NO_ZERO_DATE' ],
			'lower case'      => [ 'no_backslash_escapes' ],
		];
	}

	#[DataProvider( 'unsafeModes' )]
	public function test_no_backslash_escapes_is_refused( string $mode ): void {
		$db          = new FakeConnection();
		$db->sqlMode = $mode;

		$this->expectException( DatabaseException::class );
		$this->expectExceptionMessage( 'NO_BACKSLASH_ESCAPES' );
		ConnectionGuard::assertSqlMode( $db );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function ansiQuoteModes(): array {
		return [
			'alone'      => [ 'ANSI_QUOTES' ],
			'ansi'       => [ 'REAL_AS_FLOAT,PIPES_AS_CONCAT,ANSI_QUOTES,IGNORE_SPACE,ONLY_FULL_GROUP_BY,ANSI' ],
			'ansi only'  => [ 'ANSI' ],
			'lower case' => [ 'strict_trans_tables,ansi_quotes' ],
		];
	}

	#[DataProvider( 'ansiQuoteModes' )]
	public function test_ansi_quotes_is_refused( string $mode ): void {
		$db          = new FakeConnection();
		$db->sqlMode = $mode;

		$this->expectException( DatabaseException::class );
		$this->expectExceptionMessage( 'ANSI_QUOTES' );
		ConnectionGuard::assertSqlMode( $db );
	}

	public function test_a_mode_the_server_does_not_tell_is_refused(): void {
		$db          = new FakeConnection();
		$db->sqlMode = null;

		$this->expectException( DatabaseException::class );
		ConnectionGuard::assertSqlMode( $db );
	}
}
