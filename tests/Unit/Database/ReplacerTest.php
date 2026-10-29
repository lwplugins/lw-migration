<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Database;

use LightweightPlugins\Migration\Database\Replacer;
use LightweightPlugins\Migration\Database\SerializedReplacer;
use LightweightPlugins\Migration\Database\SqlEscape;
use PHPUnit\Framework\TestCase;

final class ReplacerTest extends TestCase {

	private const PAIRS = [ 'http://old.test' => 'https://new.example.com' ];

	public function test_serialized_lengths_are_recomputed(): void {
		$r    = new SerializedReplacer( self::PAIRS );
		$data = serialize( [ 'url' => 'http://old.test/a', 'n' => 5, 'deep' => [ 'x' => 'http://old.test' ] ] );
		$out  = $r->replace( $data );
		$this->assertSame( [ 'url' => 'https://new.example.com/a', 'n' => 5, 'deep' => [ 'x' => 'https://new.example.com' ] ], unserialize( $out ) );
	}

	public function test_serialized_inside_serialized_and_multibyte(): void {
		$inner = serialize( [ 'link' => 'http://old.test/ü' ] );
		$data  = serialize( [ 'opts' => $inner, 'title' => 'Árvíz http://old.test' ] );
		$out   = unserialize( ( new SerializedReplacer( self::PAIRS ) )->replace( $data ) );
		$this->assertSame( [ 'link' => 'https://new.example.com/ü' ], unserialize( $out['opts'] ) );
		$this->assertSame( 'Árvíz https://new.example.com', $out['title'] );
	}

	public function test_objects_and_scalars(): void {
		$obj        = new \stdClass();
		$obj->home  = 'http://old.test';
		$obj->count = 2;
		$out        = ( new SerializedReplacer( self::PAIRS ) )->replace( serialize( [ $obj, true, null, 1.5 ] ) );
		$back       = unserialize( $out );
		$this->assertSame( 'https://new.example.com', $back[0]->home );
		$this->assertSame( [ true, null, 1.5 ], array_slice( $back, 1 ) );
	}

	public function test_plain_and_broken_serialized_fall_back_to_strtr(): void {
		$r = new SerializedReplacer( self::PAIRS );
		$this->assertSame( 'see https://new.example.com', $r->replace( 'see http://old.test' ) );
		$this->assertSame( 's:99:"https://new.example.com";', $r->replace( 's:99:"http://old.test";' ) );
		$this->assertSame( 'x', $r->replace( 'x' ) );
	}

	public function test_statement_rewrites_only_literals_and_keeps_escaping(): void {
		$r   = new Replacer( self::PAIRS );
		$val = serialize( [ 'u' => "http://old.test/it's" ] );
		$out = $r->statement( "INSERT INTO `t` VALUES (1,'" . \LightweightPlugins\Migration\Database\SqlEscape::escape( $val ) . "',0x41,'short');" );

		preg_match( "/VALUES \\(1,'((?:[^'\\\\]|\\\\.)*)',0x41,'short'\\);/", $out, $m );
		$this->assertNotEmpty( $m );
		$this->assertSame( [ 'u' => "https://new.example.com/it's" ], unserialize( \LightweightPlugins\Migration\Database\SqlEscape::unescape( $m[1] ) ) );
	}

	public function test_statement_without_old_value_is_untouched_and_raw_pairs_apply(): void {
		$r = new Replacer( self::PAIRS, [ "'old.test','/'" => "'new.example.com','/'" ] );
		$this->assertSame( "INSERT INTO `b` VALUES (1,'new.example.com','/');", $r->statement( "INSERT INTO `b` VALUES (1,'old.test','/');" ) );
		$this->assertSame( "INSERT INTO `x` VALUES ('nothing');", ( new Replacer( self::PAIRS ) )->statement( "INSERT INTO `x` VALUES ('nothing');" ) );
	}

	public function test_empty_pairs_and_min_length(): void {
		$this->assertTrue( ( new Replacer( [] ) )->isEmpty() );
		$r = new Replacer( [ 'abcdef' => 'X', 'abcd' => 'Y' ] );
		$this->assertSame( 'abc', $r->value( 'abc' ) );
		$this->assertSame( 'Y', $r->plain( 'abcd' ) );
	}

	private function denseLiteral( int $bytes ): string {
		$esc = SqlEscape::escape( serialize( [ 'html' => "<a href=\"http://old.test/x\">it's</a>\n\r", 'k' => 'v' ] ) );

		return str_repeat( $esc, intdiv( $bytes, strlen( $esc ) ) );
	}

	public function test_escape_dense_10mb_literal_is_replaced(): void {
		$lit = $this->denseLiteral( 10485760 );
		$out = ( new Replacer( self::PAIRS ) )->statement( "INSERT INTO `t` VALUES (1,'" . $lit . "');" );
		$this->assertStringNotContainsString( 'http://old.test', $out );
		$this->assertStringContainsString( 'https://new.example.com/x', $out );
		$this->assertStringEndsWith( "');", $out );
	}

	/** @group slow */
	public function test_64mb_literal_completes(): void {
		$old = ini_set( 'memory_limit', '1G' );
		try {
			$lit   = $this->denseLiteral( 67108864 );
			$out   = ( new Replacer( self::PAIRS ) )->statement( "INSERT INTO `t` VALUES (1,'" . $lit . "');" );
			$clean = ! str_contains( $out, 'http://old.test' );
		} finally {
			unset( $lit, $out );
			ini_set( 'memory_limit', false === $old ? '128M' : $old );
		}
		$this->assertTrue( $clean );
	}

	public function test_doubled_quote_and_unterminated_literal(): void {
		$r = new Replacer( self::PAIRS );
		$this->assertSame( "INSERT INTO t VALUES ('it\\'s https://new.example.com', 'b');", $r->statement( "INSERT INTO t VALUES ('it''s http://old.test', 'b');" ) );
		$this->expectException( \LightweightPlugins\Migration\Database\DatabaseException::class );
		$r->statement( "INSERT INTO t VALUES ('http://old.test" );
	}

	public function test_enum_and_serializable_tokens_are_copied_verbatim(): void {
		$r    = new SerializedReplacer( self::PAIRS );
		$enum = 'E:11:"Suit:Hearts";';
		$out  = $r->replace( 'a:2:{i:0;' . $enum . 'i:1;s:15:"http://old.test";}' );
		$this->assertSame( 'a:2:{i:0;' . $enum . 'i:1;s:23:"https://new.example.com";}', $out );

		$ser = 'C:3:"Foo":5:{http:}';
		$out = $r->replace( 'a:2:{i:0;' . $ser . 'i:1;s:15:"http://old.test";}' );
		$this->assertSame( 'a:2:{i:0;' . $ser . 'i:1;s:23:"https://new.example.com";}', $out );
	}

	public function test_references_are_copied_and_depth_is_capped(): void {
		$r = new SerializedReplacer( self::PAIRS );
		$d = 'a:3:{i:0;s:15:"http://old.test";i:1;R:2;i:2;r:2;}';
		$this->assertSame( 'a:3:{i:0;s:23:"https://new.example.com";i:1;R:2;i:2;r:2;}', $r->replace( $d ) );

		$deep = str_repeat( 'a:1:{i:0;', 4100 ) . 's:15:"http://old.test";' . str_repeat( '}', 4100 );
		$this->assertSame( str_replace( 's:15:"http://old.test"', 's:15:"https://new.example.com"', $deep ), $r->replace( $deep ) );
	}

	/**
	 * @dataProvider provide_huge_lengths
	 */
	public function test_huge_declared_lengths_fall_back_without_exception( string $data ): void {
		$this->assertSame( $data, ( new SerializedReplacer( self::PAIRS ) )->replace( $data ) );
		$this->assertSame( strtr( $data . 'http://old.test', self::PAIRS ), ( new SerializedReplacer( self::PAIRS ) )->replace( $data . 'http://old.test' ) );
	}

	public static function provide_huge_lengths(): array {
		return [
			'enum'         => [ 'E:9223372036854775800:"x";' ],
			'class count'  => [ 'C:3:"Foo":9223372036854775800:{x}' ],
			'class length' => [ 'C:9223372036854775800:"Foo":1:{x}' ],
			'array count'  => [ 'a:9223372036854775800:{i:0;i:1;}' ],
			'object'       => [ 'O:9223372036854775800:"Foo":0:{}' ],
		];
	}

	public function test_double_quoted_strings_and_identifiers_are_kept_byte_identical(): void {
		$r = new Replacer( self::PAIRS );
		$this->assertSame(
			"INSERT INTO `lwtab12_x` VALUES (\"it's\", 'https://new.example.com/a', \"don't\");",
			$r->statement( "INSERT INTO `lwtab12_x` VALUES (\"it's\", 'http://old.test/a', \"don't\");" )
		);
		$this->assertSame(
			"INSERT INTO `it's` VALUES (\"http://old.test \\\" x\", 'https://new.example.com');",
			$r->statement( "INSERT INTO `it's` VALUES (\"http://old.test \\\" x\", 'http://old.test');" )
		);
	}

	public function test_literal_scanner_only_maps_single_quoted_bodies(): void {
		$seen = [];
		$out  = \LightweightPlugins\Migration\Database\SqlLiterals::map(
			"(\"a'b\", `c'd`, 'e''f', \"g\"\"h\", 'i\\'j')",
			static function ( string $body ) use ( &$seen ): string {
				$seen[] = $body;
				return strtoupper( $body );
			}
		);
		$this->assertSame( [ "e''f", "i\\'j" ], $seen );
		$this->assertSame( "(\"a'b\", `c'd`, 'E''F', \"g\"\"h\", 'I\\'J')", $out );
	}

	public function test_unterminated_double_quote_is_an_error(): void {
		$this->expectException( \LightweightPlugins\Migration\Database\DatabaseException::class );
		( new Replacer( self::PAIRS ) )->statement( "INSERT INTO t VALUES (\"x, 'http://old.test');" );
	}

	public function test_many_limited_values_in_one_statement_are_named_once_per_column_in_linear_time(): void {
		$value = 'http://old.test';
		for ( $i = 0; $i < 34; $i++ ) {
			$value = serialize( $value );
		}
		$tuple = "(1,'" . SqlEscape::escape( $value ) . "','" . SqlEscape::escape( $value ) . "')";
		$sql   = 'INSERT INTO `lwt_t` (`id`,`a`,`b`) VALUES ' . implode( ',', array_fill( 0, 8000, $tuple ) ) . ';';
		$r     = new Replacer( self::PAIRS );
		$start = microtime( true );

		$r->statement( $sql );

		$this->assertLessThan( 5.0, microtime( true ) - $start, 'One pass over the statement, not one per limited value.' );
		$this->assertSame(
			[
				[ 'table' => 'lwt_t', 'column' => 'a', 'position' => 2 ],
				[ 'table' => 'lwt_t', 'column' => 'b', 'position' => 3 ],
			],
			$r->limitedColumns()
		);
	}

	public function test_past_the_label_cap_only_the_table_is_named(): void {
		$value = 'http://old.test';
		for ( $i = 0; $i < 34; $i++ ) {
			$value = serialize( $value );
		}
		$sql = 'INSERT INTO `lwt_t` VALUES (' . implode( ',', array_fill( 0, 40, "'" . SqlEscape::escape( $value ) . "'" ) ) . ');';
		$r   = new Replacer( self::PAIRS );

		$r->statement( $sql );
		$columns = $r->limitedColumns();

		$this->assertCount( 33, $columns );
		$this->assertSame( [ 'table' => 'lwt_t', 'column' => null, 'position' => 32 ], $columns[31] );
		$this->assertSame( [ 'table' => 'lwt_t', 'column' => null, 'position' => 0 ], $columns[32], 'The rest is named by its table only.' );
	}
}
