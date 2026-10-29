<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\OAuth;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\ConnectTicket;
use LightweightPlugins\Migration\Tests\Support\FakeTransients;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

/**
 * Issue #12 (ruling R12): the one-time id of a command-line connect link.
 */
final class ConnectTicketTest extends MonkeyTestCase {

	private int $now = 1_800_000_000;

	protected function setUp(): void {
		parent::setUp();
		FakeTransients::install();
		Functions\when( 'wp_salt' )->justReturn( 'unit-test-auth-salt' );
	}

	private function tickets(): ConnectTicket {
		return new ConnectTicket( fn(): int => $this->now );
	}

	public function test_a_ticket_is_64_hex_stored_only_as_an_hmac_for_ten_minutes(): void {
		$ticket = $this->tickets()->issue( 7, 'abcd1234' );

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $ticket );
		$name = (string) array_key_first( FakeTransients::$store );
		$this->assertStringStartsWith( 'lw_migration_connect_', $name );
		$this->assertStringNotContainsString( $ticket, $name . json_encode( FakeTransients::$store ) );
		$this->assertSame( 600, FakeTransients::$store[ $name ][1] );
	}

	public function test_a_ticket_works_once_for_its_user(): void {
		$ticket = $this->tickets()->issue( 7, 'abcd1234' );

		$this->assertSame( 'abcd1234', $this->tickets()->redeem( $ticket, 7 ) );
		$this->assertNull( $this->tickets()->redeem( $ticket, 7 ), 'Used up.' );
	}

	public function test_another_user_uses_the_ticket_up_without_getting_it(): void {
		$ticket = $this->tickets()->issue( 7, 'abcd1234' );

		$this->assertNull( $this->tickets()->redeem( $ticket, 8 ) );
		$this->assertNull( $this->tickets()->redeem( $ticket, 7 ), 'Gone after the refused use.' );
	}

	public function test_an_expired_ticket_is_refused(): void {
		$ticket     = $this->tickets()->issue( 7, 'abcd1234' );
		$this->now += ConnectTicket::TTL + 1;

		$this->assertNull( $this->tickets()->redeem( $ticket, 7 ) );
	}

	public function test_a_changed_record_does_not_verify(): void {
		$ticket = $this->tickets()->issue( 7, 'abcd1234' );
		$name   = array_key_first( FakeTransients::$store );
		FakeTransients::$store[ $name ][0]['user'] = 8;

		$this->assertNull( $this->tickets()->redeem( $ticket, 8 ) );
	}

	public function test_a_malformed_ticket_reads_nothing(): void {
		Functions\expect( 'get_transient' )->never();

		$this->assertNull( $this->tickets()->redeem( 'not-a-ticket', 7 ) );
		$this->assertNull( $this->tickets()->redeem( str_repeat( 'a', 64 ), 0 ) );
	}

	public function test_two_racing_uses_let_exactly_one_through(): void {
		$ticket = $this->tickets()->issue( 7, 'abcd1234' );
		$record = array_values( FakeTransients::$store )[0][0];
		Functions\when( 'get_transient' )->justReturn( $record );
		$deletes = 0;
		Functions\when( 'delete_transient' )->alias(
			static function () use ( &$deletes ): bool {
				++$deletes;
				return 1 === $deletes;
			}
		);

		$this->assertCount( 1, array_filter( [ $this->tickets()->redeem( $ticket, 7 ), $this->tickets()->redeem( $ticket, 7 ) ] ) );
	}

	public function test_issue_needs_a_user_a_profile_id_and_a_stored_record(): void {
		foreach ( [ [ 0, 'abcd1234', \RuntimeException::class ], [ 7, 'ABCD', \InvalidArgumentException::class ] ] as [ $user, $profile, $class ] ) {
			try {
				$this->tickets()->issue( $user, $profile );
				$this->fail( 'Refused.' );
			} catch ( \RuntimeException | \InvalidArgumentException $e ) {
				$this->assertInstanceOf( $class, $e );
			}
		}
		Functions\when( 'set_transient' )->justReturn( false );
		$this->expectException( \RuntimeException::class );
		$this->tickets()->issue( 7, 'abcd1234' );
	}

	public function test_the_ticket_parameters_are_marked_sensitive(): void {
		foreach ( [ [ 'redeem', 'ticket' ], [ 'transient', 'ticket' ], [ 'mac', 'ticket' ] ] as [ $method, $name ] ) {
			$this->assertCount( 1, ( new \ReflectionParameter( [ ConnectTicket::class, $method ], $name ) )->getAttributes( \SensitiveParameter::class ), $method );
		}
	}
}
