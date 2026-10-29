<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Database;

use LightweightPlugins\Migration\Database\InsertColumns;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InsertColumnsTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: int, 2: array{table: string, column: string|null, position: int}|null}>
	 */
	public static function statements(): array {
		return [
			'column list'         => [ "INSERT INTO `lwt_a``b` (`id`,`c``d`, plain) VALUES (1,'x','y');", 1, [ 'table' => 'lwt_a`b', 'column' => 'plain', 'position' => 3 ] ],
			'no column list'      => [ "INSERT INTO `t` VALUES (1,NULL,0x41,'x');", 0, [ 'table' => 't', 'column' => null, 'position' => 4 ] ],
			'second row'          => [ "INSERT INTO `t` VALUES ('a','b'),('c','d');", 3, [ 'table' => 't', 'column' => null, 'position' => 2 ] ],
			'commas in literals'  => [ "INSERT INTO `t` VALUES ('a,(b)',\"q,'\",'c');", 1, [ 'table' => 't', 'column' => null, 'position' => 3 ] ],
			'charset introducer'  => [ "INSERT INTO `t` (`a`,`b`) VALUES (_utf8mb4'x', N'y');", 1, [ 'table' => 't', 'column' => 'b', 'position' => 2 ] ],
			'no such literal'     => [ "INSERT INTO `t` VALUES ('a');", 1, null ],
			'not an insert'       => [ 'DROP TABLE IF EXISTS `t`;', 0, null ],
			'unterminated quote'  => [ "INSERT INTO `t` VALUES ('a", 0, null ],
		];
	}

	/**
	 * @param string                                                         $sql      Statement.
	 * @param int                                                            $literal  Ordinal.
	 * @param array{table: string, column: string|null, position: int}|null $expected Column.
	 */
	#[DataProvider( 'statements' )]
	public function test_names_the_column_of_a_literal( string $sql, int $literal, ?array $expected ): void {
		$this->assertSame( $expected, InsertColumns::ofLiteral( $sql, $literal ) );
	}
}
