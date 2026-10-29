<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Storage\Destinations\SecretVault;
use LightweightPlugins\Migration\Support\SecretBox;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class SecretVaultTest extends MonkeyTestCase {

	private FakeWpdb $db;

	protected function setUp(): void {
		parent::setUp();
		$this->db = FakeWpdb::install();
		Functions\when( 'wp_salt' )->justReturn( 'salt-one' );
	}

	/**
	 * Review Focus 3.
	 */
	public function test_stored_values_are_sealed_not_plain(): void {
		( new SecretVault() )->put( 'abcd1234', [ 'secretKey' => 'TOP-SECRET-KEY' ] );

		$stored = (string) $this->db->value( Options::DESTINATION_SECRETS );
		$this->assertStringNotContainsString( 'TOP-SECRET-KEY', $stored );
		$this->assertStringContainsString( 'lwm1:', $stored );
		$this->assertSame( 'off', $this->db->rows[ Options::DESTINATION_SECRETS ]['autoload'] );
		$this->assertSame( [ 'secretKey' => 'TOP-SECRET-KEY' ], ( new SecretVault() )->open( 'abcd1234' ) );
	}

	public function test_an_empty_value_keeps_the_stored_one_and_a_new_one_replaces_it(): void {
		$vault = new SecretVault();
		$vault->put( 'abcd1234', [ 'secretKey' => 'one', 'passphrase' => 'p' ] );
		$vault->put( 'abcd1234', [ 'secretKey' => '', 'passphrase' => 'q' ] );

		$this->assertSame( [ 'secretKey' => 'one', 'passphrase' => 'q' ], $vault->open( 'abcd1234' ) );
		$this->assertSame( [ 'secretKey', 'passphrase' ], $vault->fields( 'abcd1234' ) );
	}

	public function test_after_a_salt_change_nothing_opens(): void {
		( new SecretVault() )->put( 'abcd1234', [ 'secretKey' => 'one' ] );
		Functions\when( 'wp_salt' )->justReturn( 'salt-two' );

		$this->assertNull( ( new SecretVault() )->open( 'abcd1234' ), 'A value that does not open is reauth, never an empty secret.' );
		$this->assertSame( [ 'secretKey' ], ( new SecretVault() )->fields( 'abcd1234' ) );
	}

	public function test_another_profile_or_another_key_context_cannot_open_it(): void {
		$vault = new SecretVault();
		$vault->put( 'abcd1234', [ 'secretKey' => 'one' ] );
		$values             = json_decode( (string) $this->db->value( Options::DESTINATION_SECRETS ), true );
		$values['ffff0000'] = $values['abcd1234'];
		$this->db->rows[ Options::DESTINATION_SECRETS ]['value'] = (string) json_encode( $values, JSON_UNESCAPED_SLASHES );

		$this->assertNull( $vault->open( 'ffff0000' ), 'A sealed value is bound to its profile and field.' );
		$schedules = new SecretBox( hash_hmac( 'sha256', 'lw-migration/schedule-password/v1', 'salt-one', true ) );
		$this->assertNull( $schedules->open( (string) $values['abcd1234']['secretKey'] ) );
	}

	public function test_a_value_stored_for_one_server_opens_only_for_that_server(): void {
		$vault = new SecretVault();
		$vault->put( 'abcd1234', [ 'password' => 'one' ], 'server-a' );

		$this->assertSame( [ 'password' => 'one' ], $vault->open( 'abcd1234', 'server-a' ) );
		$this->assertNull( $vault->open( 'abcd1234', 'server-b' ), 'A value stored for another server never opens.' );
	}

	public function test_one_value_stored_for_another_server_keeps_the_whole_profile_closed(): void {
		$vault = new SecretVault();
		$vault->put( 'abcd1234', [ 'privateKey' => 'k' ], 'server-a' );
		$vault->put( 'abcd1234', [ 'passphrase' => 'p' ], 'server-b' );

		$this->assertNull( $vault->open( 'abcd1234', 'server-a' ) );
		$this->assertNull( $vault->open( 'abcd1234', 'server-b' ) );
	}

	/**
	 * Values sealed before the binding existed ("<id>\0<field>\0<value>")
	 * name no server: they keep opening, whatever server is asked for.
	 */
	public function test_a_value_sealed_without_a_server_still_opens(): void {
		$box = new SecretBox( hash_hmac( 'sha256', 'lw-migration/destination-secrets/v1', 'salt-one', true ) );
		$this->db->rows[ Options::DESTINATION_SECRETS ] = [
			'value'    => (string) json_encode( [ 'abcd1234' => [ 'password' => $box->seal( "abcd1234\0password\0legacy" ) ] ], JSON_UNESCAPED_SLASHES ),
			'autoload' => 'off',
		];

		$this->assertSame( [ 'password' => 'legacy' ], ( new SecretVault() )->open( 'abcd1234', 'server-a' ) );
		$this->assertSame( [ 'password' => 'legacy' ], ( new SecretVault() )->open( 'abcd1234' ) );
	}

	public function test_without_sodium_nothing_is_stored(): void {
		$this->expectException( \RuntimeException::class );
		( new SecretVault( null, false ) )->put( 'abcd1234', [ 'secretKey' => 'one' ] );
	}

	public function test_forget_removes_the_profiles_row_only(): void {
		$vault = new SecretVault();
		$vault->put( 'abcd1234', [ 'secretKey' => 'one' ] );
		$vault->put( 'eeee1111', [ 'secretKey' => 'two' ] );
		$vault->forget( 'abcd1234' );

		$this->assertSame( [], $vault->fields( 'abcd1234' ) );
		$this->assertSame( [ 'secretKey' => 'two' ], $vault->open( 'eeee1111' ) );
	}

	/**
	 * Review I1: the row is read fresh and written by compare-and-swap.
	 */
	public function test_a_write_made_between_the_read_and_the_write_is_kept(): void {
		$vault = new SecretVault();
		$vault->put( 'abcd1234', [ 'secretKey' => 'one' ] );
		$this->db->beforeWrite = static function (): void {
			( new SecretVault() )->put( 'eeee1111', [ 'secretKey' => 'two' ] );
		};

		$vault->put( 'abcd1234', [ 'passphrase' => 'p' ] );

		$this->assertSame( [ 'secretKey' => 'one', 'passphrase' => 'p' ], $vault->open( 'abcd1234' ) );
		$this->assertSame( [ 'secretKey' => 'two' ], $vault->open( 'eeee1111' ) );
	}

	public function test_a_put_that_cannot_be_written_throws(): void {
		$this->db->failWrites = true;

		$this->expectException( \RuntimeException::class );
		( new SecretVault() )->put( 'abcd1234', [ 'secretKey' => 'one' ] );
	}

	public function test_drop_removes_only_the_named_fields_of_one_profile(): void {
		$vault = new SecretVault( new SecretBox( str_repeat( 'k', 32 ) ), true );
		$vault->put( 'abcd1234', [ 'clientSecret' => 's', 'refreshToken' => 'r', 'accessToken' => '9:a' ] );
		$vault->put( 'ffff0000', [ 'refreshToken' => 'other' ] );

		$this->assertTrue( $vault->drop( 'abcd1234', [ 'refreshToken', 'accessToken' ] ) );

		$this->assertSame( [ 'clientSecret' => 's' ], $vault->open( 'abcd1234' ) );
		$this->assertSame( [ 'refreshToken' => 'other' ], $vault->open( 'ffff0000' ) );
	}

	/**
	 * Preflight P-C1 (the M7a I1 shape): drop() is a compare-and-swap on the row as it is now.
	 */
	public function test_drop_keeps_a_write_made_between_its_read_and_its_write(): void {
		$vault = new SecretVault();
		$vault->put( 'abcd1234', [ 'clientSecret' => 's', 'refreshToken' => 'r' ] );
		$this->db->beforeWrite = static function (): void {
			( new SecretVault() )->put( 'eeee1111', [ 'secretKey' => 'two' ] );
		};

		$this->assertTrue( $vault->drop( 'abcd1234', [ 'refreshToken' ] ) );

		$this->assertSame( [ 'clientSecret' => 's' ], $vault->open( 'abcd1234' ) );
		$this->assertSame( [ 'secretKey' => 'two' ], $vault->open( 'eeee1111' ) );
	}

	public function test_drop_of_nothing_writes_nothing_and_dropping_every_field_removes_the_profile(): void {
		$vault = new SecretVault();
		$vault->put( 'abcd1234', [ 'refreshToken' => 'r' ] );
		$before = $this->db->value( Options::DESTINATION_SECRETS );

		$this->assertTrue( $vault->drop( 'abcd1234', [ 'accessToken' ] ) );
		$this->assertTrue( $vault->drop( 'ffff0000', [ 'refreshToken' ] ) );
		$this->assertTrue( $vault->drop( 'abcd1234', [] ) );
		$this->assertSame( $before, $this->db->value( Options::DESTINATION_SECRETS ) );

		$this->assertTrue( $vault->drop( 'abcd1234', [ 'refreshToken' ] ) );
		$this->assertArrayNotHasKey( 'abcd1234', json_decode( (string) $this->db->value( Options::DESTINATION_SECRETS ), true ) );
	}

	public function test_a_drop_that_cannot_be_written_answers_false(): void {
		$vault = new SecretVault();
		$vault->put( 'abcd1234', [ 'refreshToken' => 'r' ] );
		$this->db->failWrites = true;

		$this->assertFalse( $vault->drop( 'abcd1234', [ 'refreshToken' ] ) );
	}

	/**
	 * Preflight P-C1: a token write never brings back the secrets of a deleted profile.
	 */
	public function test_put_existing_writes_only_to_a_profile_that_still_has_secrets(): void {
		$vault = new SecretVault();
		$vault->put( 'abcd1234', [ 'clientSecret' => 's' ] );

		$this->assertTrue( $vault->putExisting( 'abcd1234', [ 'refreshToken' => 'r', 'accessToken' => '' ] ) );
		$this->assertSame( [ 'clientSecret' => 's', 'refreshToken' => 'r' ], $vault->open( 'abcd1234' ) );

		$this->assertFalse( $vault->putExisting( 'ffff0000', [ 'refreshToken' => 'r' ] ) );
		$this->assertSame( [], $vault->fields( 'ffff0000' ) );
	}

	public function test_put_existing_loses_to_a_forget_made_between_its_read_and_its_write(): void {
		$vault = new SecretVault();
		$vault->put( 'abcd1234', [ 'clientSecret' => 's' ] );
		$this->db->beforeWrite = static function (): void {
			( new SecretVault() )->forget( 'abcd1234' );
		};

		$this->assertFalse( $vault->putExisting( 'abcd1234', [ 'refreshToken' => 'r' ] ) );

		$this->assertSame( [], $vault->fields( 'abcd1234' ) );
		$this->assertStringNotContainsString( 'abcd1234', (string) $this->db->value( Options::DESTINATION_SECRETS ) );
	}

	public function test_put_existing_values_are_sealed_and_a_failed_write_throws(): void {
		$vault = new SecretVault();
		$vault->put( 'abcd1234', [ 'clientSecret' => 's' ] );
		$vault->putExisting( 'abcd1234', [ 'refreshToken' => 'PLAIN-REFRESH' ] );
		$this->assertStringNotContainsString( 'PLAIN-REFRESH', (string) $this->db->value( Options::DESTINATION_SECRETS ) );

		$this->db->failWrites = true;
		$this->expectException( \RuntimeException::class );
		$vault->putExisting( 'abcd1234', [ 'refreshToken' => 'r2' ] );
	}

	public function test_the_token_values_are_marked_sensitive(): void {
		$parameter = ( new \ReflectionMethod( SecretVault::class, 'putExisting' ) )->getParameters()[1];
		$this->assertCount( 1, $parameter->getAttributes( \SensitiveParameter::class ) );
	}

	/**
	 * Review round 1 C1: a token write lands only while the stored grant (connection generation) is the writer's.
	 */
	public function test_put_if_matches_writes_only_while_the_stored_field_is_the_expected_one(): void {
		$vault = new SecretVault();
		$vault->put( 'abcd1234', [ 'clientSecret' => 's', 'grant' => 'g1' ] );

		$this->assertTrue( $vault->putIfMatches( 'abcd1234', 'grant', 'g1', [ 'refreshToken' => 'r' ] ) );
		$this->assertFalse( $vault->putIfMatches( 'abcd1234', 'grant', 'g2', [ 'refreshToken' => 'stale' ] ) );
		$this->assertFalse( $vault->putIfMatches( 'abcd1234', 'grant', '', [ 'refreshToken' => 'stale' ] ) );
		$this->assertFalse( $vault->putIfMatches( 'ffff0000', 'grant', '', [ 'refreshToken' => 'stale' ] ), 'No row, no write.' );
		$this->assertSame( [ 'clientSecret' => 's', 'grant' => 'g1', 'refreshToken' => 'r' ], $vault->open( 'abcd1234' ) );
		$this->assertSame( [], $vault->fields( 'ffff0000' ) );

		$vault->put( 'eeee1111', [ 'clientSecret' => 's' ] );
		$this->assertFalse( $vault->putIfMatches( 'eeee1111', 'grant', '', [ 'refreshToken' => 'r' ] ), 'Review round 2: an absent field matches nothing, not even an empty expectation.' );
		$this->assertFalse( $vault->putIfMatches( 'eeee1111', 'grant', 'g1', [ 'refreshToken' => 'r' ] ) );
		$this->assertSame( [ 'clientSecret' => 's' ], $vault->open( 'eeee1111' ) );
	}

	public function test_put_if_matches_loses_to_a_change_made_between_its_read_and_its_write(): void {
		$vault = new SecretVault();
		$vault->put( 'abcd1234', [ 'grant' => 'g1', 'refreshToken' => 'rA' ] );
		$this->db->beforeWrite = static function (): void {
			( new SecretVault() )->put( 'abcd1234', [ 'grant' => 'g2', 'refreshToken' => 'rB' ] );
		};

		$this->assertFalse( $vault->putIfMatches( 'abcd1234', 'grant', 'g1', [ 'refreshToken' => 'rA2' ] ) );
		$this->assertSame( [ 'grant' => 'g2', 'refreshToken' => 'rB' ], $vault->open( 'abcd1234' ) );
	}

	public function test_put_if_matches_never_matches_a_value_that_does_not_open(): void {
		$vault = new SecretVault();
		$vault->put( 'abcd1234', [ 'grant' => 'g1' ] );
		Functions\when( 'wp_salt' )->justReturn( 'salt-two' );

		$this->assertFalse( ( new SecretVault() )->putIfMatches( 'abcd1234', 'grant', 'g1', [ 'refreshToken' => 'r' ] ) );
		$this->assertFalse( ( new SecretVault() )->putIfMatches( 'abcd1234', 'grant', '', [ 'refreshToken' => 'r' ] ) );
	}

	/**
	 * Review m3: a field name is a plain identifier, so "<id>\0<field>\0" cannot be ambiguous.
	 */
	#[DataProvider( 'badFields' )]
	public function test_a_field_name_outside_letters_digits_and_underscore_is_refused( string $field ): void {
		try {
			( new SecretVault() )->put( 'abcd1234', [ $field => 'one' ] );
			$this->fail( 'A malformed field name is refused.' );
		} catch ( \LogicException $e ) {
			$this->assertNull( $this->db->value( Options::DESTINATION_SECRETS ) );
		}
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function badFields(): array {
		return [
			'nul byte' => [ "secret\0Key" ],
			'hyphen'   => [ 'secret-key' ],
			'space'    => [ 'secret key' ],
			'empty'    => [ '' ],
		];
	}
}
