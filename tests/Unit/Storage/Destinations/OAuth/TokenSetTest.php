<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Storage\Destinations\OAuth;

use LightweightPlugins\Migration\Storage\Destinations\OAuth\TokenSet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A token answer is read strictly: a token is visible ASCII (it goes into
 * an Authorization header), the type is Bearer when given, expires_in a
 * whole number clamped to 5 min .. 1 day (review round 1 m2: longer than
 * twice TokenKeeper::MARGIN, so a short-lived token is still cached).
 */
final class TokenSetTest extends TestCase {

	private const NOW = 1_800_000_000;

	public function test_reads_every_field(): void {
		$set = TokenSet::fromAnswer(
			[
				'access_token'  => 'ya29.a',
				'token_type'    => 'Bearer',
				'expires_in'    => 3599,
				'refresh_token' => '1//r',
				'id_token'      => 'h.p.s',
				'account_id'    => 'dbid:AA',
			],
			self::NOW
		);

		$this->assertNotNull( $set );
		$this->assertSame( [ 'ya29.a', self::NOW + 3599, '1//r', 'h.p.s', 'dbid:AA' ], [ $set->accessToken, $set->expiresAt, $set->refreshToken, $set->idToken, $set->accountId ] );
	}

	/**
	 * @return array<string, array{0: mixed, 1: int}>
	 */
	public static function lifetimes(): array {
		return [
			'missing'       => [ null, 3600 ],
			'small'         => [ 5, 300 ],
			'negative'      => [ -10, 300 ],
			'under 5 min'   => [ 120, 300 ],
			'huge'          => [ 10_000_000, 86400 ],
			'digits string' => [ '3600', 3600 ],
		];
	}

	#[DataProvider( 'lifetimes' )]
	public function test_expires_in_is_bounded( $value, int $seconds ): void {
		$json = [ 'access_token' => 'a' ];
		if ( null !== $value ) {
			$json['expires_in'] = $value;
		}

		$this->assertSame( self::NOW + $seconds, TokenSet::fromAnswer( $json, self::NOW )?->expiresAt );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function refused(): array {
		return [
			'no access token'     => [ [ 'token_type' => 'Bearer' ] ],
			'empty access token'  => [ [ 'access_token' => '' ] ],
			'array access token'  => [ [ 'access_token' => [ 'a' ] ] ],
			'line break in token' => [ [ 'access_token' => "a\r\nX-Evil: 1" ] ],
			'space in token'      => [ [ 'access_token' => 'a b' ] ],
			'too long token'      => [ [ 'access_token' => str_repeat( 'a', TokenSet::MAX_TOKEN + 1 ) ] ],
			'mac token type'      => [ [ 'access_token' => 'a', 'token_type' => 'mac' ] ],
			'float lifetime'      => [ [ 'access_token' => 'a', 'expires_in' => 3600.5 ] ],
			'text lifetime'       => [ [ 'access_token' => 'a', 'expires_in' => 'soon' ] ],
			'bad refresh token'   => [ [ 'access_token' => 'a', 'refresh_token' => "r\n" ] ],
			'number refresh'      => [ [ 'access_token' => 'a', 'refresh_token' => 12 ] ],
			'bad id token'        => [ [ 'access_token' => 'a', 'id_token' => false ] ],
			'long account id'     => [ [ 'access_token' => 'a', 'account_id' => str_repeat( 'x', 256 ) ] ],
		];
	}

	/**
	 * @param array<string, mixed> $json Answer.
	 */
	#[DataProvider( 'refused' )]
	public function test_an_odd_answer_is_refused( array $json ): void {
		$this->assertNull( TokenSet::fromAnswer( $json, self::NOW ) );
	}

	public function test_null_optional_fields_count_as_absent(): void {
		$set = TokenSet::fromAnswer(
			[
				'access_token'  => 'a',
				'refresh_token' => null,
				'id_token'      => null,
				'account_id'    => null,
				'scope'         => null,
			],
			self::NOW
		);

		$this->assertNotNull( $set );
		$this->assertSame( [ '', '', '' ], [ $set->refreshToken, $set->idToken, $set->accountId ] );
	}

	public function test_bearer_is_matched_without_case(): void {
		$this->assertNotNull( TokenSet::fromAnswer( [ 'access_token' => 'a', 'token_type' => 'bearer' ], self::NOW ) );
	}
}
