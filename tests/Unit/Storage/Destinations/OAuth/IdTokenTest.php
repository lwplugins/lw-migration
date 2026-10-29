<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Storage\Destinations\OAuth;

use LightweightPlugins\Migration\Storage\Destinations\OAuth\IdToken;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\Pkce;
use PHPUnit\Framework\TestCase;

/**
 * Plan E14: the id_token gives only the account label on the card.
 */
final class IdTokenTest extends TestCase {

	/**
	 * @param array<string, mixed> $claims Payload.
	 */
	private static function jwt( array $claims ): string {
		return Pkce::encode( '{"alg":"RS256"}' ) . '.' . Pkce::encode( (string) json_encode( $claims ) ) . '.sig';
	}

	public function test_the_label_prefers_preferred_username_then_email(): void {
		$this->assertSame( 'a@contoso.com', IdToken::label( self::jwt( [ 'preferred_username' => 'a@contoso.com', 'email' => 'b@x.com' ] ) ) );
		$this->assertSame( 'b@x.com', IdToken::label( self::jwt( [ 'email' => 'b@x.com' ] ) ) );
		$this->assertSame( 'b@x.com', IdToken::label( self::jwt( [ 'preferred_username' => '', 'email' => 'b@x.com' ] ) ) );
	}

	public function test_garbage_gives_no_claims(): void {
		$this->assertSame( [], IdToken::claims( 'nope' ) );
		$this->assertSame( [], IdToken::claims( 'a.' . Pkce::encode( '"text"' ) . '.c' ), 'A payload that is not an object.' );
		$this->assertSame( [], IdToken::claims( 'a.' . Pkce::encode( '[1,2]' ) . '.c' ), 'A list is not an object.' );
		$this->assertSame( [], IdToken::claims( 'a.b.c.d' ) );
		$this->assertSame( '', IdToken::label( 'a.b!.c' ) );
		$this->assertSame( '', IdToken::label( self::jwt( [ 'preferred_username' => str_repeat( 'x', 300 ) ] ) ), 'An absurd label is not shown.' );
		$this->assertSame( '', IdToken::label( self::jwt( [ 'preferred_username' => 7, 'email' => [ 'x' ] ] ) ) );
	}

	public function test_a_label_with_control_characters_is_not_shown(): void {
		$this->assertSame( '', IdToken::label( self::jwt( [ 'preferred_username' => "a@b.com\r\nX" ] ) ) );
		$this->assertSame( 'c@d.com', IdToken::label( self::jwt( [ 'preferred_username' => "a\x00b", 'email' => 'c@d.com' ] ) ) );
	}
}
