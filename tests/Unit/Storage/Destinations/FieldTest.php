<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Storage\Destinations;

use LightweightPlugins\Migration\Storage\Destinations\Field;
use PHPUnit\Framework\TestCase;

final class FieldTest extends TestCase {

	public function test_a_multi_line_secret_is_a_secret_with_its_own_kind(): void {
		$key = new Field( 'privateKey', Field::SECRET_TEXT, 'Private key', true );

		$this->assertTrue( $key->isSecret() );
		$this->assertTrue( ( new Field( 'password', Field::SECRET, 'Password' ) )->isSecret() );
		$this->assertFalse( ( new Field( 'host', Field::TEXT, 'Host' ) )->isSecret() );
		$this->assertSame( 'secret_text', $key->toArray()['kind'] );
	}

	public function test_a_field_is_shown_only_while_every_condition_holds(): void {
		$key = new Field( 'privateKey', Field::SECRET_TEXT, 'Private key', true, '', [], null, [ 'auth' => [ 'key' ] ] );

		$this->assertTrue( $key->visible( [ 'auth' => 'key' ] ) );
		$this->assertFalse( $key->visible( [ 'auth' => 'password' ] ) );
		$this->assertFalse( $key->visible( [] ), 'A missing setting holds no listed value.' );
		$this->assertTrue( ( new Field( 'host', Field::TEXT, 'Host' ) )->visible( [] ), 'No condition: always shown.' );
		$this->assertFalse( ( new Field( 'n', Field::TEXT, 'N', false, '', [], null, [ 'port' => [ 21 ] ] ) )->visible( [ 'port' => '21' ] ), 'Strict, like the browser.' );
	}
}
