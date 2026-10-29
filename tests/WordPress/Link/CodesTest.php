<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Link;

use LightweightPlugins\Migration\Link\Codes;
use LightweightPlugins\Migration\Link\KeyExchange;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Support\SecretBox;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class CodesTest extends MonkeyTestCase {

	private const NOW = 1790000000;

	private function codes( string $key = 'k' ): Codes {
		return new Codes( null, new SecretBox( str_repeat( $key, 32 ) ) );
	}

	/**
	 * @return array{id: int, login: string}
	 */
	private static function owner(): array {
		return [
			'id'    => 7,
			'login' => 'boss',
		];
	}

	public function test_only_a_lookup_and_the_sealed_nonce_are_stored(): void {
		$db   = FakeWpdb::install();
		$code = $this->codes()->issue( self::owner(), [ 'pull' ], self::NOW );

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}\z/', $code['nonce'] );
		$this->assertSame( self::NOW + 900, $code['expiresAt'] );
		$raw = (string) $db->value( Options::LINK_CODE );
		$this->assertStringContainsString( KeyExchange::lookup( $code['nonce'] ), $raw );
		$this->assertStringNotContainsString( $code['nonce'], $raw );
		$this->assertStringNotContainsString( hash( 'sha256', $code['nonce'] ), $raw );
		$this->assertSame( 'off', $db->rows[ Options::LINK_CODE ]['autoload'] );
	}

	public function test_a_code_redeems_once_and_only_while_it_lives(): void {
		$db     = FakeWpdb::install();
		$codes  = $this->codes();
		$code   = $codes->issue( self::owner(), [ 'pull' ], self::NOW );
		$lookup = KeyExchange::lookup( $code['nonce'] );

		$before = $db->rows;
		$first  = $codes->matches( $lookup, self::NOW + 1 );
		$this->assertSame( $first, $codes->matches( $lookup, self::NOW + 1 ) );
		$this->assertSame( $code['nonce'], $first['nonce'] );
		$this->assertSame( $before, $db->rows, 'matches() only reads.' );

		$this->assertSame(
			[
				'nonce'     => $code['nonce'],
				'createdBy' => self::owner(),
				'allow'     => [ 'pull' ],
			],
			$codes->redeem( $lookup, self::NOW + 1 )
		);
		$this->assertNull( $codes->redeem( $lookup, self::NOW + 1 ) );

		$code   = $codes->issue( self::owner(), [ 'pull' ], self::NOW );
		$lookup = KeyExchange::lookup( $code['nonce'] );
		$this->assertNull( $codes->redeem( $lookup, self::NOW + 900 ), 'Expired at the second it ends.' );
		$this->assertNotNull( $codes->matches( $lookup, self::NOW + 899 ) );

		$before = $db->rows;
		foreach ( [ str_repeat( 'a', 64 ), strtoupper( $lookup ), substr( $lookup, 1 ), '' ] as $wrong ) {
			$this->assertNull( $codes->redeem( $wrong, self::NOW + 1 ), $wrong );
		}
		$this->assertSame( $before, $db->rows );

		$this->assertNull( $this->codes( 'z' )->redeem( $lookup, self::NOW + 1 ), 'Sealed with another key.' );
		$this->assertSame( $before, $db->rows );
	}

	public function test_a_new_code_replaces_the_old_one(): void {
		FakeWpdb::install();
		$codes = $this->codes();
		$first = $codes->issue( self::owner(), [ 'pull' ], self::NOW );
		$codes->issue( self::owner(), [ 'push', 'pull' ], self::NOW + 5 );

		$this->assertNull( $codes->matches( KeyExchange::lookup( $first['nonce'] ), self::NOW + 6 ) );
	}

	public function test_a_concurrent_redeem_wins_once(): void {
		$db     = FakeWpdb::install();
		$codes  = $this->codes();
		$code   = $codes->issue( self::owner(), [ 'pull' ], self::NOW );
		$lookup = KeyExchange::lookup( $code['nonce'] );

		$db->beforeWrite = static function () use ( $db ): void {
			unset( $db->rows[ Options::LINK_CODE ] );
		};
		$this->assertNull( $codes->redeem( $lookup, self::NOW + 1 ) );
	}

	public function test_a_code_needs_an_owner_and_a_right(): void {
		FakeWpdb::install();
		$cases = [
			[ [ 'id' => 0, 'login' => 'boss' ], [ 'pull' ] ],
			[ [ 'id' => 7, 'login' => '' ], [ 'pull' ] ],
			[ self::owner(), [] ],
			[ self::owner(), [ 'delete' ] ],
		];
		foreach ( $cases as [ $owner, $allow ] ) {
			try {
				$this->codes()->issue( $owner, $allow, self::NOW );
				$this->fail( 'Refused: ' . json_encode( [ $owner, $allow ] ) );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	public function test_pending_clear_and_prune(): void {
		$db    = FakeWpdb::install();
		$codes = $this->codes();
		$this->assertNull( $codes->pending( self::NOW ) );

		$codes->issue( self::owner(), [ 'pull' ], self::NOW );
		$this->assertSame( self::NOW + 900, $codes->pending( self::NOW ) );
		$this->assertNull( $codes->pending( self::NOW + 900 ) );
		$codes->prune( self::NOW + 899 );
		$this->assertArrayHasKey( Options::LINK_CODE, $db->rows, 'A live code is kept.' );
		$codes->prune( self::NOW + 900 );
		$this->assertArrayNotHasKey( Options::LINK_CODE, $db->rows, 'An expired code is deleted.' );

		$codes->issue( self::owner(), [ 'pull' ], self::NOW );
		$codes->clear();
		$this->assertNull( $codes->pending( self::NOW ) );
		$codes->clear();
	}

	public function test_a_login_with_invalid_utf8_is_refused_and_the_old_code_stays(): void {
		$db    = FakeWpdb::install();
		$codes = $this->codes();
		$codes->issue( self::owner(), [ 'pull' ], self::NOW );
		$before = $db->value( Options::LINK_CODE );

		try {
			$codes->issue( [ 'id' => 7, 'login' => "bo\xC3" ], [ 'pull' ], self::NOW );
			$this->fail( 'Refused.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertNotSame( '', $e->getMessage() );
		}
		$this->assertSame( $before, $db->value( Options::LINK_CODE ) );
	}

	public function test_a_code_that_cannot_be_opened_is_not_pending(): void {
		FakeWpdb::install();
		$this->codes()->issue( self::owner(), [ 'pull' ], self::NOW );

		$this->assertSame( self::NOW + 900, $this->codes()->pending( self::NOW ) );
		$this->assertNull( $this->codes( 'z' )->pending( self::NOW ), 'The salt changed: the code is dead.' );
	}
}
