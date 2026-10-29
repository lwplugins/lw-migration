<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Database;

use LightweightPlugins\Migration\Database\SerializedReplacer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Length prefixes after replacement, read the way WordPress reads the value
 * back (maybe_unserialize() trims it first).
 */
final class SerializedReplacerTest extends TestCase {

	private const PAIRS = [ 'http://old.test' => 'https://new.example.com' ];

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function padding(): array {
		return [
			'trailing newline' => [ '', "\n" ],
			'leading space'    => [ ' ', '' ],
			'both sides'       => [ "\r\n\t", " \x0B\x00" ],
		];
	}

	#[DataProvider( 'padding' )]
	public function test_whitespace_padded_value_keeps_its_lengths( string $lead, string $trail ): void {
		$out = ( new SerializedReplacer( self::PAIRS ) )->replace( $lead . serialize( [ 'u' => 'http://old.test/ő' ] ) . $trail );

		$this->assertSame( [ 'u' => 'https://new.example.com/ő' ], unserialize( trim( $out ) ) );
		$this->assertStringStartsWith( $lead . 'a:1:', $out );
		$this->assertStringEndsWith( '}' . $trail, $out );
	}

	#[DataProvider( 'padding' )]
	public function test_whitespace_padded_nested_value_keeps_its_lengths( string $lead, string $trail ): void {
		$inner = $lead . serialize( [ 'u' => 'http://old.test' ] ) . $trail;
		$out   = unserialize( ( new SerializedReplacer( self::PAIRS ) )->replace( serialize( [ 'opts' => $inner ] ) ) );

		$this->assertSame( [ 'u' => 'https://new.example.com' ], unserialize( trim( $out['opts'] ) ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function scalarLookalikes(): array {
		return [
			'integer'   => [ 'i:http://old.test;' ],
			'double'    => [ 'd:http://old.test;' ],
			'boolean'   => [ 'b:http://old.test;' ],
			'reference' => [ 'R:http://old.test;' ],
			'object id' => [ 'r:http://old.test;' ],
		];
	}

	#[DataProvider( 'scalarLookalikes' )]
	public function test_text_that_only_looks_like_a_scalar_token_is_replaced( string $text ): void {
		$r = new SerializedReplacer( self::PAIRS );

		$this->assertSame( strtr( $text, self::PAIRS ), $r->replace( $text ) );
		$this->assertSame( [ 'x' => strtr( $text, self::PAIRS ) ], unserialize( $r->replace( serialize( [ 'x' => $text ] ) ) ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function scalarTokens(): array {
		$out = [];
		foreach ( [ 'i:-5;', 'i:+5;', 'd:0.1;', 'd:1.0E+25;', 'd:-INF;', 'd:INF;', 'd:NAN;', 'd:-0;', 'd:.5;', 'd:5.;', 'd:1e5;', 'd:+1.5e-3;', 'b:0;', 'b:1;' ] as $token ) {
			$out[ $token ] = [ $token ];
		}

		return $out;
	}

	#[DataProvider( 'scalarTokens' )]
	public function test_every_scalar_token_unserialize_accepts_still_parses( string $token ): void {
		$data = 'a:3:{i:0;' . $token . 'i:1;s:15:"http://old.test";i:2;R:3;}';
		$this->assertNotFalse( unserialize( $data ), 'The token is valid serialized data.' );

		$back = unserialize( ( new SerializedReplacer( self::PAIRS ) )->replace( $data ) );

		$this->assertSame( 'https://new.example.com', $back[1] );
		$this->assertSame( 'https://new.example.com', $back[2] );
	}

	/**
	 * A value nested $levels times; the URL sits at the deepest level.
	 */
	private static function nested( string $payload, int $levels ): string {
		for ( $i = 0; $i < $levels; $i++ ) {
			$payload = serialize( $payload );
		}

		return $payload;
	}

	public function test_a_deeply_nested_large_value_stays_bounded_and_valid(): void {
		$deepest = str_repeat( 'x', 1048576 ) . ' http://old.test';
		$data    = self::nested( $deepest, 400 );
		$r       = new SerializedReplacer( self::PAIRS );
		$before  = memory_get_usage();
		memory_reset_peak_usage();

		$out = $r->replace( $data );

		$this->assertLessThan( 64 * 1048576, memory_get_peak_usage() - $before, 'Far under a 256 MB memory limit.' );
		$this->assertTrue( $r->limited() );
		for ( $i = 0; $i < 400; $i++ ) {
			$out = unserialize( $out );
			$this->assertIsString( $out, 'Level ' . ( $i + 1 ) . ' is valid serialized data.' );
		}
		$this->assertSame( $deepest, $out, 'The part past the limit is kept exactly.' );
	}

	public function test_three_levels_with_an_address_at_every_level_are_all_replaced(): void {
		$three = serialize( [ 'u' => 'http://old.test/3' ] );
		$two   = serialize( [ 'u' => 'http://old.test/2', 'in' => $three ] );
		$one   = serialize( [ 'u' => 'http://old.test/1', 'in' => $two ] );
		$r     = new SerializedReplacer( self::PAIRS );

		$a = unserialize( $r->replace( $one ) );
		$b = unserialize( $a['in'] );
		$c = unserialize( $b['in'] );

		$this->assertFalse( $r->limited() );
		$this->assertSame( [ 'https://new.example.com/1', 'https://new.example.com/2', 'https://new.example.com/3' ], [ $a['u'], $b['u'], $c['u'] ] );
	}

	public function test_nested_strings_are_replaced_down_to_32_levels(): void {
		$chain = 'http://old.test';
		for ( $i = 0; $i < 40; $i++ ) {
			$chain = serialize( [ 'u' => 'http://old.test', 'in' => $chain ] );
		}
		$r = new SerializedReplacer( self::PAIRS );

		$level = unserialize( $r->replace( serialize( [ 'pad' => str_repeat( 'p', 200000 ), 'in' => $chain ] ) ) )['in'];

		$this->assertTrue( $r->limited() );
		for ( $depth = 1; $depth <= 40; $depth++ ) {
			$array = unserialize( $level );
			$this->assertSame( $depth <= 32 ? 'https://new.example.com' : 'http://old.test', $array['u'], 'Level ' . $depth );
			$level = $array['in'];
		}
	}
}
