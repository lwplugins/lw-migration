<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Database;

use LightweightPlugins\Migration\Database\QueryException;
use PHPUnit\Framework\TestCase;

final class QueryExceptionTest extends TestCase {

	public function test_the_message_names_the_code_never_the_server_text(): void {
		$server = "Duplicate entry 'secret\\' x' for key 'PRIMARY'";
		$e      = new QueryException( $server, 1062, "INSERT INTO t VALUES ('secret')" );

		$this->assertSame( 'Database error 1062 (duplicate entry).', $e->getMessage() );
		$this->assertSame( $server, $e->serverText(), 'Kept for decisions in code only.' );
		$this->assertSame( 1062, $e->errno() );
		$this->assertSame( 'Database error 1292 (server error).', ( new QueryException( "Truncated incorrect value: 'secret'", 1292, '' ) )->getMessage() );
	}
}
