<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\OAuth;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthState;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\Pkce;
use LightweightPlugins\Migration\Tests\Support\FakeTransients;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class OAuthStateTest extends MonkeyTestCase {

	private int $now = 1_800_000_000;

	private string $session = 'session-of-user-7';

	protected function setUp(): void {
		parent::setUp();
		FakeTransients::install();
		Functions\when( 'wp_salt' )->justReturn( 'unit-test-auth-salt' );
		Functions\when( 'wp_get_session_token' )->alias( fn(): string => $this->session );
	}

	private function states(): OAuthState {
		return new OAuthState( fn(): int => $this->now );
	}

	private function issue(): string {
		return $this->states()->issue( 7, 'abcd1234', 'client-id.apps', 'https://site.test/wp-json/lw-migration/v1/oauth/callback', Pkce::verifier() );
	}

	public function test_issue_returns_64_hex_and_names_the_transient_by_an_hmac(): void {
		$state = $this->issue();

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $state );
		$this->assertCount( 1, FakeTransients::$store );
		$name = array_key_first( FakeTransients::$store );
		$this->assertStringStartsWith( 'lw_migration_oauth_', $name );
		$this->assertStringNotContainsString( $state, $name );
		$this->assertLessThanOrEqual( 172, strlen( $name ), 'Fits a transient name.' );
		$this->assertSame( OAuthState::TTL, FakeTransients::$store[ $name ][1] );
		$this->assertStringNotContainsString( 'client-id.apps', (string) json_encode( FakeTransients::$store ), 'Only an HMAC of the Client ID is stored.' );
	}

	public function test_a_state_is_used_up_by_its_first_consume(): void {
		$state = $this->issue();

		$first = $this->states()->consume( $state, 7 );

		$this->assertSame( 'abcd1234', $first['profile'] );
		$this->assertSame( 'https://site.test/wp-json/lw-migration/v1/oauth/callback', $first['redirectUri'] );
		$this->assertTrue( Pkce::isVerifier( $first['verifier'] ) );
		$this->assertSame( $this->states()->clientMark( 'client-id.apps' ), $first['client'] );
		$this->assertNull( $this->states()->consume( $state, 7 ), 'A replay finds nothing.' );
	}

	public function test_an_expired_state_is_refused(): void {
		$state     = $this->issue();
		$this->now += OAuthState::TTL + 1;

		$this->assertNull( $this->states()->consume( $state, 7 ) );
	}

	public function test_another_user_or_session_cannot_use_a_state(): void {
		$state = $this->issue();
		$this->assertNull( $this->states()->consume( $state, 8 ), 'Another user.' );

		$state         = $this->issue();
		$this->session = 'another-login-of-user-7';
		$this->assertNull( $this->states()->consume( $state, 7 ), 'Another login session of the same user.' );
	}

	public function test_a_changed_record_does_not_verify(): void {
		$state = $this->issue();
		$name  = array_key_first( FakeTransients::$store );
		FakeTransients::$store[ $name ][0]['profile'] = 'ffff0000';

		$this->assertNull( $this->states()->consume( $state, 7 ) );
	}

	public function test_two_racing_consumes_let_exactly_one_through(): void {
		$state  = $this->issue();
		$name   = array_key_first( FakeTransients::$store );
		$record = FakeTransients::$store[ $name ][0];
		// Both requests read the record before either deletes it; only one delete succeeds.
		Functions\when( 'get_transient' )->justReturn( $record );
		$deletes = 0;
		Functions\when( 'delete_transient' )->alias(
			static function () use ( &$deletes ): bool {
				++$deletes;
				return 1 === $deletes;
			}
		);

		$results = [ $this->states()->consume( $state, 7 ), $this->states()->consume( $state, 7 ) ];

		$this->assertCount( 1, array_filter( $results ) );
	}

	public function test_a_malformed_state_reads_nothing(): void {
		Functions\expect( 'get_transient' )->never();

		$this->assertNull( $this->states()->consume( 'not-a-state', 7 ) );
		$this->assertNull( $this->states()->consume( str_repeat( 'A', 64 ), 7 ) );
	}

	/**
	 * Issue #12: no state works in any login session any more (the command
	 * line prints a one-time link to the plugin's page instead), and
	 * issue() has no way to ask for one.
	 */
	public function test_no_state_works_outside_the_login_session_it_was_issued_in(): void {
		$state         = $this->issue();
		$this->session = 'browser-login-of-user-7';

		$this->assertNull( $this->states()->consume( $state, 7 ), 'Another login session of the same user.' );
		$this->assertSame( 5, ( new \ReflectionMethod( OAuthState::class, 'issue' ) )->getNumberOfParameters(), 'No any-session flag.' );
	}

	public function test_a_record_with_an_empty_session_never_verifies(): void {
		$state = $this->issue();
		$name  = array_key_first( FakeTransients::$store );
		FakeTransients::$store[ $name ][0]['session'] = '';

		$this->assertNull( $this->states()->consume( $state, 7 ) );
	}

	public function test_issue_without_a_user_or_a_login_session_is_refused(): void {
		$this->session = '';
		$this->expectException( \RuntimeException::class );

		$this->issue();
	}

	public function test_a_refused_consume_still_uses_the_state_up(): void {
		$state = $this->issue();
		$this->assertNull( $this->states()->consume( $state, 8 ), 'Another user.' );
		$this->assertSame( [], FakeTransients::$store, 'Deleted on the failed use.' );
		$this->assertNull( $this->states()->consume( $state, 7 ), 'The owner cannot use it afterwards either.' );

		$state = $this->issue();
		$name  = array_key_first( FakeTransients::$store );
		FakeTransients::$store[ $name ][0]['verifier'] = Pkce::verifier();
		$this->assertNull( $this->states()->consume( $state, 7 ), 'A changed record.' );
		$this->assertSame( [], FakeTransients::$store, 'A changed record is deleted too.' );
	}

	public function test_an_unknown_state_deletes_nothing(): void {
		$this->issue();
		Functions\expect( 'delete_transient' )->never();

		$this->assertNull( $this->states()->consume( str_repeat( 'a', 64 ), 7 ) );
	}

	public function test_a_cli_record_cannot_be_turned_into_a_session_bound_one_or_back(): void {
		$state = $this->issue();
		$name  = array_key_first( FakeTransients::$store );
		FakeTransients::$store[ $name ][0]['session'] = '';
		$this->session = 'another-login-of-user-7';

		$this->assertNull( $this->states()->consume( $state, 7 ), 'The MAC covers the session field.' );
	}

	public function test_the_verifier_and_the_state_parameters_are_marked_sensitive(): void {
		$parameters = [
			new \ReflectionParameter( [ OAuthState::class, 'issue' ], 'verifier' ),
			new \ReflectionParameter( [ OAuthState::class, 'consume' ], 'state' ),
			new \ReflectionParameter( [ OAuthState::class, 'transient' ], 'state' ),
			new \ReflectionParameter( [ OAuthState::class, 'mark' ], 'value' ),
			new \ReflectionParameter( [ OAuthState::class, 'mac' ], 'state' ),
			new \ReflectionParameter( [ OAuthState::class, 'mac' ], 'record' ),
		];

		foreach ( $parameters as $parameter ) {
			$this->assertCount( 1, $parameter->getAttributes( \SensitiveParameter::class ), $parameter->getDeclaringFunction()->getName() . ' $' . $parameter->getName() );
		}
	}

	public function test_issue_refuses_a_malformed_profile_id_or_verifier(): void {
		foreach ( [ [ 'ABCD1234', Pkce::verifier() ], [ "abcd1234\0", '' ], [ 'abcd1234', 'short' ], [ 'abcd1234', Pkce::verifier() . '+' ] ] as [ $profile, $verifier ] ) {
			try {
				$this->states()->issue( 7, $profile, 'c', 'https://site.test/cb', $verifier );
				$this->fail( 'Accepted a malformed profile id or verifier.' );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( 'A state needs a profile id and a PKCE verifier or none.', $e->getMessage(), 'Fixed words, never the value.' );
			}
		}
		$this->assertSame( [], FakeTransients::$store );
	}

	public function test_fields_moved_across_their_boundary_do_not_verify(): void {
		$verifier = Pkce::verifier();
		$state    = $this->states()->issue( 7, 'abcd1234', 'c', "https://site.test/cb\0" . $verifier, '' );
		$name     = array_key_first( FakeTransients::$store );
		FakeTransients::$store[ $name ][0]['redirect'] = 'https://site.test/cb';
		FakeTransients::$store[ $name ][0]['verifier'] = $verifier . "\0";

		$this->assertNull( $this->states()->consume( $state, 7 ), 'Each MAC field is length-prefixed.' );
	}

	public function test_a_record_that_cannot_be_stored_throws(): void {
		Functions\when( 'set_transient' )->justReturn( false );
		$this->expectException( \RuntimeException::class );

		$this->issue();
	}

	public function test_no_user_is_refused_on_issue_and_on_consume(): void {
		$state = $this->issue();

		$this->assertNull( $this->states()->consume( $state, 0 ) );
		$this->assertCount( 1, FakeTransients::$store, 'A refused user reads nothing.' );
		$this->expectException( \RuntimeException::class );
		$this->states()->issue( 0, 'abcd1234', 'c', 'https://site.test/cb', '' );
	}

	public function test_an_expired_record_is_deleted_on_consume(): void {
		$state     = $this->issue();
		$this->now += OAuthState::TTL + 1;

		$this->assertNull( $this->states()->consume( $state, 7 ) );
		$this->assertSame( [], FakeTransients::$store );
	}
}
