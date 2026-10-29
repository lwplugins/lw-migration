<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Link;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Link\PairingMail;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class PairingMailTest extends MonkeyTestCase {

	/**
	 * Mails handed to the mailer: [ to, subject, body ].
	 *
	 * @var array<int, array{0: string, 1: string, 2: string}>
	 */
	private array $mails = [];

	/** @var array<int, object> */
	private array $users = [];

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_users' )->alias(
			function ( array $args ): array {
				$this->assertSame(
					[
						'capability' => 'manage_options',
						'fields'     => [ 'user_email' ],
					],
					$args
				);

				return $this->users;
			}
		);
		Functions\when( 'is_email' )->alias( static fn( $email ) => false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false );
		Functions\when( 'get_bloginfo' )->alias( static fn( $key = '' ) => 'name' === $key ? 'Site B &amp; Co' : '' );
		Functions\when( 'home_url' )->justReturn( 'https://b.example' );
		Functions\when( 'wp_date' )->justReturn( '2026-10-10 12:34' );
		Functions\when( 'wp_specialchars_decode' )->alias( static fn( $text ) => htmlspecialchars_decode( (string) $text, ENT_QUOTES ) );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $text ) => trim( strip_tags( (string) $text ) ) );
	}

	/**
	 * @param array<int, string> $allow Rights.
	 * @return array<string, mixed>
	 */
	private static function link( array $allow = [ 'pull', 'push' ] ): array {
		return [
			'id'         => '0123456789abcdef',
			'direction'  => 'in',
			'address'    => 'https://a.example/wp',
			'name'       => 'Site <i>A</i>',
			'allow'      => $allow,
			'createdAt'  => 1790000000,
			'createdBy'  => [
				'id'    => 7,
				'login' => 'boss',
			],
			'confirmed'  => false,
			'lastUsedAt' => 0,
			'pairedFrom' => '203.0.113.5',
			'key'        => str_repeat( 'S', 32 ),
		];
	}

	private function mailer( bool $ok = true ): \Closure {
		return function ( string $to, string $subject, string $body ) use ( $ok ): bool {
			$this->mails[] = [ $to, $subject, $body ];

			return $ok;
		};
	}

	public function test_every_admin_is_told_what_was_connected_and_how_to_remove_it(): void {
		$this->users = [ (object) [ 'user_email' => 'one@b.example' ], (object) [ 'user_email' => 'two@b.example' ], (object) [ 'user_email' => 'one@b.example' ], (object) [ 'user_email' => 'not an address' ] ];

		$this->assertSame( 2, PairingMail::send( self::link(), $this->mailer() ) );

		$this->assertSame( [ 'one@b.example', 'two@b.example' ], array_column( $this->mails, 0 ) );
		[ , $subject, $body ] = $this->mails[0];
		$this->assertSame( '[Site B & Co] A site was connected to this site', $subject );
		foreach ( [ 'Site B & Co', 'https://b.example', 'Site A', 'https://a.example/wp', '203.0.113.5', '2026-10-10 12:34', 'It may copy this site (database, files, users).', 'It may replace this site\'s content with its own.', 'LW Plugins > Migration > Connections > Remove', 'wp lw-migration link remove 0123456789abcdef' ] as $piece ) {
			$this->assertStringContainsString( $piece, $body );
		}
		$this->assertStringNotContainsString( '<i>', $body );
		foreach ( [ str_repeat( 'S', 32 ), bin2hex( str_repeat( 'S', 32 ) ), base64_encode( str_repeat( 'S', 32 ) ), 'boss' ] as $secret ) {
			$this->assertStringNotContainsString( $secret, $body );
		}
	}

	public function test_only_the_rights_given_are_named(): void {
		$this->users = [ (object) [ 'user_email' => 'one@b.example' ] ];
		PairingMail::send( self::link( [ 'pull' ] ), $this->mailer() );
		$this->assertStringContainsString( 'It may copy this site', $this->mails[0][2] );
		$this->assertStringNotContainsString( 'replace this site', $this->mails[0][2] );
	}

	public function test_a_failing_mailer_is_counted_never_thrown(): void {
		$this->users = [ (object) [ 'user_email' => 'one@b.example' ], (object) [ 'user_email' => 'two@b.example' ] ];
		$this->assertSame( 0, PairingMail::send( self::link(), $this->mailer( false ) ) );
		$this->assertCount( 2, $this->mails );
		$thrown = static function (): bool {
			throw new \RuntimeException( 'Mailer down.' );
		};
		$this->assertSame( 0, PairingMail::send( self::link(), $thrown ) );
	}

	public function test_no_admin_address_sends_nothing(): void {
		$this->users = [];
		$this->assertSame( 0, PairingMail::send( self::link(), $this->mailer() ) );
		$this->assertSame( [], $this->mails );
	}

	public function test_a_failing_user_query_sends_nothing(): void {
		Functions\when( 'get_users' )->alias(
			static function (): array {
				throw new \RuntimeException( 'Database gone.' );
			}
		);
		$this->assertSame( 0, PairingMail::send( self::link(), $this->mailer() ) );
	}

	public function test_the_mails_go_out_after_the_answer(): void {
		$this->users = [ (object) [ 'user_email' => 'one@b.example' ] ];
		$queued      = [];
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $callback, int $priority = 10 ) use ( &$queued ): bool {
				$queued[] = [ $hook, $callback, $priority ];

				return true;
			}
		);

		PairingMail::later( self::link(), $this->mailer() );

		$this->assertSame( [], $this->mails, 'Nothing is sent while the other site waits for the answer.' );
		$this->assertCount( 1, $queued );
		[ $hook, $callback, $priority ] = $queued[0];
		$this->assertSame( [ 'shutdown', PHP_INT_MAX ], [ $hook, $priority ], 'Last on shutdown, after the answer was sent.' );
		$callback();
		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'one@b.example', $this->mails[0][0] );
	}
}
