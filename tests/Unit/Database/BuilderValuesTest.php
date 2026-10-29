<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Database;

use LightweightPlugins\Migration\Database\BuilderValues;
use LightweightPlugins\Migration\Database\Replacer;
use PHPUnit\Framework\TestCase;

final class BuilderValuesTest extends TestCase {

	private const PAIRS = [ 'http://old.test' => 'https://new.test' ];

	public function test_visual_composer_raw_html(): void {
		$b64 = base64_encode( rawurlencode( '<a href="http://old.test/x">x</a>' ) );
		$sql = "INSERT INTO `p` VALUES (1,'[vc_raw_html]{$b64}[/vc_raw_html]');";
		$out = ( new Replacer( self::PAIRS + [ rawurlencode( 'http://old.test' ) => rawurlencode( 'https://new.test' ) ], [], new BuilderValues( true ) ) )->statement( $sql );
		preg_match( '/\[vc_raw_html\]([^\[]+)\[/', $out, $m );
		$this->assertSame( '<a href="https://new.test/x">x</a>', rawurldecode( base64_decode( $m[1] ) ) );
	}

	public function test_base64_literal_with_serialized_payload(): void {
		$payload = base64_encode( serialize( [ 'bg' => 'http://old.test/bg.jpg' ] ) );
		$out     = ( new Replacer( self::PAIRS, [], new BuilderValues( false, false, true ) ) )->statement( "INSERT INTO `m` VALUES (1,'{$payload}');" );
		preg_match( "/VALUES \\(1,'([^']+)'\\)/", $out, $m );
		$this->assertSame( [ 'bg' => 'https://new.test/bg.jpg' ], unserialize( base64_decode( $m[1] ) ) );
	}

	public function test_oxygen_code_blocks(): void {
		$b64 = base64_encode( 'url("http://old.test/a.png")' );
		$sql = 'INSERT INTO `pm` VALUES (1,\'x \\"code-css\\":\\"' . $b64 . '\\" y\');';
		$this->assertStringContainsString( '\\"code-css\\":\\"', $sql );
		$out = ( new Replacer( self::PAIRS, [], new BuilderValues( false, true ) ) )->statement( $sql );
		$this->assertStringContainsString( base64_encode( 'url("https://new.test/a.png")' ), $out );
	}

	public function test_invalid_base64_and_inactive_builders_are_untouched(): void {
		$sql = "INSERT INTO `m` VALUES (1,'abc');";
		$this->assertSame( $sql, ( new Replacer( self::PAIRS, [], new BuilderValues( false, false, true ) ) )->statement( $sql ) );
		$this->assertFalse( ( new BuilderValues() )->isActive() );
	}

	public function test_detect(): void {
		$none = static fn( string $x ): bool => false;
		$this->assertTrue( BuilderValues::detect( [ 'js_composer/js_composer.php' ], 'tt', 'tt', $none, $none )->isActive() );
		$this->assertTrue( BuilderValues::detect( [], 'betheme', 'child', $none, $none )->isActive() );
		$this->assertTrue( BuilderValues::detect( [], 'tt', 'tt', static fn( string $p ): bool => 'oxygen/functions.php' === $p, $none )->isActive() );
		$this->assertFalse( BuilderValues::detect( [ 'akismet/akismet.php' ], 'tt', 'tt', $none, $none )->isActive() );
	}

	public function test_huge_base64_run_in_raw_html_is_safe(): void {
		$text = str_repeat( 'a', 7500000 ) . 'http://old.test/x';
		$b64  = base64_encode( $text );
		$this->assertGreaterThan( 10000000, strlen( $b64 ) );
		$sql = "INSERT INTO `p` VALUES (1,'[vc_raw_html]{$b64}[/vc_raw_html]');";
		$out = ( new Replacer( self::PAIRS, [], new BuilderValues( true ) ) )->statement( $sql );
		if ( $out === $sql ) {
			$this->assertSame( $sql, $out ); // PCRE gave up: original kept.
			return;
		}
		preg_match( '/\[vc_raw_html\]([^\[]+)\[/', $out, $m );
		$this->assertStringEndsWith( 'https://new.test/x', base64_decode( $m[1] ) );
	}

	public function test_huge_base64_literal_is_replaced_without_regex(): void {
		$b64 = base64_encode( str_repeat( 'a', 7500000 ) . 'http://old.test/x' );
		$out = ( new Replacer( self::PAIRS, [], new BuilderValues( false, false, true ) ) )->statement( "INSERT INTO `m` VALUES (1,'{$b64}');" );
		$this->assertStringContainsString( base64_encode( str_repeat( 'a', 7500000 ) . 'https://new.test/x' ), $out );
	}
}
