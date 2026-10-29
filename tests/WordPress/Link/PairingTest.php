<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Link;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Link\Codes;
use LightweightPlugins\Migration\Link\Guard;
use LightweightPlugins\Migration\Link\KeyExchange;
use LightweightPlugins\Migration\Link\LinkException;
use LightweightPlugins\Migration\Link\Links;
use LightweightPlugins\Migration\Link\Nonces;
use LightweightPlugins\Migration\Link\Pairing;
use LightweightPlugins\Migration\Link\PairingCode;
use LightweightPlugins\Migration\Link\Signer;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Support\SecretBox;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class PairingTest extends MonkeyTestCase {

	private const NOW = 1790000000;

	private const A = 'https://a.example';

	private const B = 'https://b.example';

	private const OWNER_B = [
		'id'    => 7,
		'login' => 'boss',
	];

	private const OWNER_A = [
		'id'    => 3,
		'login' => 'me',
	];

	/** The site that is acting (site_url, get_bloginfo). */
	private string $site = self::A;

	/** B's address while B acts. */
	private string $bAddress = self::B;

	private int $clock = self::NOW;

	/** Whether B's link row cannot be written when the hello arrives. */
	private bool $failConfirm = false;

	/**
	 * Requests A sent: [ url, args ].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $requests = [];

	/**
	 * Links B's notify closure was called with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $notified = [];

	private FakeWpdb $dbA;

	private FakeWpdb $dbB;

	private Links $a;

	private Links $b;

	private Codes $codes;

	private Nonces $nonces;

	private Pairing $bSide;

	protected function setUp(): void {
		parent::setUp();
		FakeWpdb::stubCache();
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'site_url' )->alias( fn() => $this->site );
		Functions\when( 'get_bloginfo' )->alias( fn( $key = '' ) => 'version' === $key ? '7.1.2' : ( self::A === $this->site ? 'Site A' : 'Site B' ) );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $text ) => trim( strip_tags( (string) $text ) ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $r ) => $r['response']['code'] );
		Functions\when( 'wp_remote_retrieve_header' )->alias( static fn( $r, $h ) => $r['headers'][ strtolower( (string) $h ) ] ?? '' );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $r ) => $r['body'] );
		Functions\when( 'add_query_arg' )->alias( static fn( $key, $value, $base ) => $base . '?' . $key . '=' . $value );
		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) );
		Functions\when( 'wp_parse_url' )->alias( static fn( $url, $component = -1 ) => parse_url( $url, $component ) );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $text ) => strip_tags( (string) $text ) );
		$boxA         = new SecretBox( str_repeat( 'a', 32 ) );
		$boxB         = new SecretBox( str_repeat( 'b', 32 ) );
		$this->dbA    = new FakeWpdb();
		$this->dbB    = new FakeWpdb();
		$this->a      = new Links( $this->dbA, $boxA );
		$this->b      = new Links( $this->dbB, $boxB );
		$this->codes  = new Codes( $this->dbB, $boxB );
		$this->nonces = new Nonces( $this->dbB );
		$this->bSide  = new Pairing(
			$this->b,
			$this->codes,
			fn(): int => $this->clock,
			null,
			function ( array $link ): void {
				$this->notified[] = $link;
			}
		);
	}

	/**
	 * @param array<mixed> $json Answer.
	 * @return array<string, mixed>
	 */
	private static function reply( int $code, array $json ): array {
		return [
			'response' => [ 'code' => $code ],
			'headers'  => [],
			'body'     => (string) json_encode( $json ),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function refused(): array {
		return self::reply(
			401,
			[
				'code'    => 'lw_migration_link_refused',
				'message' => 'This request is not accepted.',
			]
		);
	}

	/**
	 * A's transport: answers like B's routes would.
	 *
	 * @param \Closure|null $pair  fn( array $body ): array, changes the pair request before B sees it.
	 * @param \Closure|null $reply fn( array $answer ): array, changes B's pair answer before A sees it.
	 * @param bool          $hello Whether B answers the hello.
	 */
	private function transport( ?\Closure $pair = null, ?\Closure $reply = null, bool $hello = true ): \Closure {
		return function ( string $url, array $args ) use ( $pair, $reply, $hello ): array {
			$this->requests[] = [ $url, $args ];
			parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
			$route      = (string) $query['rest_route'];
			$body       = (string) ( $args['body'] ?? '' );
			$this->site = $this->bAddress;
			try {
				if ( Pairing::ROUTE === $route ) {
					$json = json_decode( $body, true );
					if ( null !== $pair ) {
						$json = $pair( $json );
					}
					try {
						$answer = $this->bSide->opens( $json ) ? $this->bSide->accept( $json, '203.0.113.5' ) : null;
					} catch ( \RuntimeException $e ) { // As LinkPeerRoutes::pair maps it (m11, m12).
						return Links::CODE_USED === $e->getCode()
							? self::reply( 409, [ 'code' => 'lw_migration_link_code_used', 'message' => 'The code was used but the connection could not be saved. Make a new code.' ] )
							: self::reply( 409, [ 'code' => 'lw_migration_link_full', 'message' => 'This site already has 20 connections.' ] );
					}
					if ( null !== $answer && null !== $reply ) {
						$answer = $reply( $answer );
					}

					return null === $answer ? self::refused() : self::reply( 200, $answer );
				}
				if ( ! $hello ) {
					return self::refused();
				}
				if ( $this->failConfirm ) {
					$this->dbB->failOn = Options::LINKS;
				}
				$link = Guard::verify( (string) $args['method'], $route, $query, (array) $args['headers'], $body, true, $this->b, $this->nonces, $this->clock );
				try {
					return is_array( $link ) ? self::signed( self::reply( 200, $this->bSide->hello( $link ) ), $link, $args ) : self::refused();
				} catch ( \RuntimeException $e ) { // As LinkPeerRoutes::hello maps it: Guard::busy().
					return self::reply( 503, [ 'code' => 'lw_migration_link_busy', 'message' => 'This site is busy answering the other site; it tries again in a moment.' ] );
				}
			} finally {
				$this->site = self::A;
			}
		};
	}

	/**
	 * B's answer signed for the verified link, as its routes sign it.
	 *
	 * @param array<string, mixed> $answer Answer.
	 * @param array<string, mixed> $link   The link Guard verified.
	 * @param array<string, mixed> $args   Request arguments.
	 * @return array<string, mixed>
	 */
	private static function signed( array $answer, array $link, array $args ): array {
		$answer['headers']['x-lwm-response-signature'] = Signer::answer( (string) $link['id'], (string) $link['key'], (string) $args['headers'][ Signer::HEADER_NONCE ], 200, (string) $answer['body'] );

		return $answer;
	}

	private function aSide( ?\Closure $send = null ): Pairing {
		return new Pairing( $this->a, new Codes( $this->dbA, new SecretBox( str_repeat( 'a', 32 ) ) ), fn(): int => $this->clock, $send ?? $this->transport() );
	}

	/**
	 * B makes a code (as B).
	 *
	 * @param array<int, string> $allow Rights.
	 */
	private function code( array $allow = [ 'pull' ] ): string {
		$this->site = $this->bAddress;
		try {
			return $this->bSide->createCode( self::OWNER_B, $allow )['code'];
		} finally {
			$this->site = self::A;
		}
	}

	private function failure( \Closure $call ): LinkException {
		try {
			$call();
		} catch ( LinkException $e ) {
			return $e;
		}
		$this->fail( 'A LinkException was expected.' );
	}

	/**
	 * A valid pairing request body for $code, as A would send it.
	 *
	 * @return array<string, mixed>
	 */
	private static function body( string $code, ?string $public = null, string $address = self::A, string $name = 'Site A' ): array {
		$nonce  = PairingCode::decode( $code )['nonce'];
		$public = $public ?? KeyExchange::keypair()['public'];

		return [
			'protocol' => 1,
			'code'     => KeyExchange::lookup( $nonce ),
			'public'   => bin2hex( $public ),
			'address'  => $address,
			'name'     => $name,
			'mac'      => KeyExchange::pairMac( $nonce, $public, $address, $name ),
		];
	}

	public function test_two_sites_pair_and_hold_the_same_secret(): void {
		$code  = $this->code();
		$nonce = PairingCode::decode( $code )['nonce'];
		$this->assertSame( self::B, PairingCode::decode( $code )['address'] );

		$view = $this->aSide()->add( $code, self::OWNER_A );

		$this->assertSame( [ 'out', self::B, 'Site B', [ 'pull' ], 'ready', false ], [ $view['direction'], $view['address'], $view['name'], $view['allow'], $view['state'], $view['insecure'] ] );
		$this->assertSame( self::OWNER_A, $view['createdBy'] );
		$this->assertArrayNotHasKey( 'key', $view );
		$this->assertArrayNotHasKey( 'secret', $view );
		$mine   = $this->a->find( $view['id'], $this->clock );
		$theirs = $this->b->find( $view['id'], $this->clock );
		$this->assertSame( 32, strlen( (string) $mine['key'] ) );
		$this->assertSame( $mine['key'], $theirs['key'] );
		$this->assertSame( [ Links::IN, true, self::OWNER_B, '203.0.113.5', self::A, 'Site A', [ 'pull' ] ], [ $theirs['direction'], $theirs['confirmed'], $theirs['createdBy'], $theirs['pairedFrom'], $theirs['address'], $theirs['name'], $theirs['allow'] ] );
		$this->assertNull( $this->dbB->value( Options::LINK_CODE ), 'The code is used up.' );
		$this->assertCount( 1, $this->notified );
		$this->assertSame( $view['id'], $this->notified[0]['id'] );
		$this->assertArrayNotHasKey( 'key', $this->notified[0], 'The mail hook never gets the secret.' );

		$this->assertCount( 2, $this->requests, 'The pairing request and the hello.' );
		$pair = json_decode( (string) $this->requests[0][1]['body'], true );
		$this->assertSame( [ 'address', 'code', 'mac', 'name', 'protocol', 'public' ], self::sortedKeys( $pair ) );
		$this->assertSame( KeyExchange::lookup( $nonce ), $pair['code'] );
		$this->assertSame( self::A, $pair['address'] );
		$this->assertSame( 'Site A', $pair['name'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}\z/', $pair['public'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}\z/', $pair['mac'] );
		$secret    = (string) $mine['key'];
		$forbidden = [ $nonce, base64_encode( $nonce ), hash( 'sha256', $nonce ), $secret, bin2hex( $secret ), base64_encode( $secret ), rtrim( strtr( base64_encode( $secret ), '+/', '-_' ), '=' ) ];
		foreach ( $this->requests as [ $url, $args ] ) {
			$wire = $url . "\n" . (string) ( $args['body'] ?? '' ) . "\n" . implode( "\n", array_map( 'strval', (array) $args['headers'] ) );
			foreach ( $forbidden as $piece ) {
				$this->assertStringNotContainsString( $piece, $wire, 'No request carries the nonce or the secret (I6).' );
			}
		}
		$this->assertSame( 'POST', $this->requests[1][1]['method'] );
		$this->assertStringEndsWith( 'rest_route=/lw-migration/v1/link/hello', $this->requests[1][0] );
	}

	/**
	 * @param array<string, mixed> $data Map.
	 * @return array<int, string>
	 */
	private static function sortedKeys( array $data ): array {
		$keys = array_keys( $data );
		sort( $keys );

		return $keys;
	}

	public function test_a_changed_pairing_request_is_refused(): void {
		$changes = [
			'address' => static fn( array $b ): array => array_merge( $b, [ 'address' => 'https://evil.example' ] ),
			'name'    => static fn( array $b ): array => array_merge( $b, [ 'name' => 'Trusted site' ] ),
			'public'  => static fn( array $b ): array => array_merge( $b, [ 'public' => bin2hex( KeyExchange::keypair()['public'] ) ] ),
		];
		foreach ( $changes as $label => $change ) {
			$code = $this->code();
			$raw  = $this->dbB->value( Options::LINK_CODE );
			$e    = $this->failure( fn() => $this->aSide( $this->transport( $change ) )->add( $code, self::OWNER_A ) );
			$this->assertSame( LinkException::CODE, $e->reason, $label );
			$this->assertSame( $raw, $this->dbB->value( Options::LINK_CODE ), 'B\'s code is still open: ' . $label );
			$this->assertNull( $this->dbB->value( Options::LINKS ), $label );
			$this->assertNull( $this->dbA->value( Options::LINKS ), $label );
		}
	}

	public function test_a_second_hello_does_not_mail_again(): void {
		$view = $this->aSide()->add( $this->code(), self::OWNER_A );
		$this->assertCount( 1, $this->notified );
		$link   = $this->a->find( $view['id'], $this->clock );
		$client = new \LightweightPlugins\Migration\Link\Client(
			[
				'id'      => $view['id'],
				'address' => self::B,
				'key'     => (string) $link['key'],
			],
			$this->transport(),
			fn(): int => $this->clock
		);
		$answer = $client->json( 'POST', '/hello' )['json'];
		$this->assertSame( [ 1, 'Site B', self::B, [ 'pull' ], '7.1.2' ], [ $answer['protocol'], $answer['name'], $answer['address'], $answer['allow'], $answer['wordpress'] ] );
		$this->assertCount( 1, $this->notified, 'Only the first confirmation mails.' );
	}

	public function test_a_code_works_once_and_fifteen_minutes(): void {
		$code = $this->code();
		$this->aSide()->add( $code, self::OWNER_A );
		$before = $this->dbA->rows;
		$e      = $this->failure( fn() => $this->aSide()->add( $code, self::OWNER_A ) );
		$this->assertSame( LinkException::CODE, $e->reason );
		$this->assertStringContainsString( 'it was used, it expired, or a newer code replaced it', $e->getMessage() );
		$this->assertSame( $before, $this->dbA->rows );

		$late        = $this->code();
		$this->clock = self::NOW + 900;
		$before      = $this->dbA->rows;
		$this->assertSame( LinkException::CODE, $this->failure( fn() => $this->aSide()->add( $late, self::OWNER_A ) )->reason );
		$this->assertSame( $before, $this->dbA->rows );
	}

	public function test_only_the_lookup_of_the_code_is_stored(): void {
		$code  = $this->code();
		$nonce = PairingCode::decode( $code )['nonce'];
		$raw   = (string) $this->dbB->value( Options::LINK_CODE );
		$this->assertStringContainsString( KeyExchange::lookup( $nonce ), $raw );
		$this->assertStringNotContainsString( $nonce, $raw );
		$this->assertStringNotContainsString( hash( 'sha256', $nonce ), $raw );
	}

	public function test_a_forged_or_changed_answer_is_refused(): void {
		$changes = [
			'proof'  => static fn( array $a ): array => array_merge( $a, [ 'proof' => str_repeat( '0', 64 ) ] ),
			'public' => static fn( array $a ): array => array_merge( $a, [ 'public' => bin2hex( KeyExchange::keypair()['public'] ) ] ),
			'id'     => static fn( array $a ): array => array_merge( $a, [ 'id' => 'fedcba9876543210' ] ),
			'allow'  => static fn( array $a ): array => array_merge( $a, [ 'allow' => [ 'delete' ] ] ),
			'unsorted' => static fn( array $a ): array => array_merge( $a, [ 'allow' => [ 'push', 'pull' ] ] ),
			'order'  => static fn( array $a ): array => array_merge( $a, [ 'allow' => [ 'pull', 'pull' ] ] ),
			'drop'   => static function ( array $a ): array {
				unset( $a['name'] );
				return $a;
			},
			'extra'  => static fn( array $a ): array => array_merge( $a, [ 'key' => 'x' ] ),
			'proto'  => static fn( array $a ): array => array_merge( $a, [ 'protocol' => 2 ] ),
			'zero'   => static fn( array $a ): array => array_merge( $a, [ 'public' => str_repeat( '0', 64 ) ] ),
			'name'   => static fn( array $a ): array => array_merge( $a, [ 'name' => "Site\x01B" ] ),
			'long'   => static fn( array $a ): array => array_merge( $a, [ 'name' => str_repeat( 'n', 1025 ) ] ),
		];
		foreach ( $changes as $label => $change ) {
			$e = $this->failure( fn() => $this->aSide( $this->transport( null, $change ) )->add( $this->code(), self::OWNER_A ) );
			$this->assertSame( LinkException::PROTOCOL, $e->reason, $label );
			$this->assertNull( $this->dbA->value( Options::LINKS ), 'A stores nothing: ' . $label );
		}
		$this->assertSame( [], $this->notified );
	}

	public function test_a_failed_hello_leaves_no_link_on_this_side(): void {
		$e = $this->failure( fn() => $this->aSide( $this->transport( null, null, false ) )->add( $this->code(), self::OWNER_A ) );
		$this->assertContains( $e->reason, [ LinkException::REFUSED, LinkException::CLOCK ] );
		$this->assertSame( [], $this->a->all( $this->clock ) );
		$pending = $this->b->all( $this->clock );
		$this->assertCount( 1, $pending );
		$this->assertSame( 'pending', $pending[0]['state'] );
		$this->assertSame( [], $this->b->all( $this->clock + Links::PENDING_TTL ), 'It expires.' );
		$this->assertSame( [], $this->notified );
	}

	public function test_a_wrong_body_or_code_writes_nothing_on_the_code_site(): void {
		$code   = $this->code();
		$good   = self::body( $code );
		$nonce  = PairingCode::decode( $code )['nonce'];
		$public = KeyExchange::keypair()['public'];
		$bad    = [
			'missing key' => array_diff_key( $good, [ 'name' => true ] ),
			'extra key'   => $good + [ 'extra' => 1 ],
			'nonce key'   => $good + [ 'nonce' => $nonce ],
			'protocol 2'  => array_merge( $good, [ 'protocol' => 2 ] ),
			'protocol "1"' => array_merge( $good, [ 'protocol' => '1' ] ),
			'63-hex code' => array_merge( $good, [ 'code' => substr( $good['code'], 1 ) ] ),
			'random code' => array_merge( $good, [ 'code' => bin2hex( random_bytes( 32 ) ) ] ),
			'wrong mac'   => array_merge( $good, [ 'mac' => KeyExchange::pairMac( $nonce, $public, self::A, 'Site A' ) ] ),
			'63-hex key'  => array_merge( $good, [ 'public' => substr( $good['public'], 1 ) ] ),
			'zero key'    => self::body( $code, str_repeat( "\0", 32 ) ),
			'ftp address' => self::body( $code, null, 'ftp://a.example' ),
			'bent address' => self::body( $code, null, 'https://a.example@evil.example' ),
			'long name'   => self::body( $code, null, self::A, str_repeat( 'n', 3000 ) ),
			'control name' => self::body( $code, null, self::A, "Site\nA" ),
			'bad utf-8'   => self::body( $code, null, self::A, "x\xC3" ),
			'not a map'   => 'text',
			'list'        => array_values( $good ),
			'null'        => null,
		];
		$before = $this->dbB->rows;
		foreach ( $bad as $label => $body ) {
			$this->assertFalse( $this->bSide->opens( $body ), $label );
			$this->assertNull( $this->bSide->accept( $body, '203.0.113.5' ), $label );
		}
		$this->assertSame( $before, $this->dbB->rows );
		$this->assertTrue( $this->bSide->opens( $good ), 'The good body opens.' );
	}

	public function test_a_long_or_marked_up_name_is_clipped_before_it_is_stored(): void {
		$code   = $this->code();
		$answer = $this->bSide->accept( self::body( $code, null, self::A, '<b>' . str_repeat( 'é', 300 ) . '</b>' ), '203.0.113.5' );
		$this->assertIsArray( $answer );
		$name = $this->b->find( $answer['id'], $this->clock )['name'];
		$this->assertLessThanOrEqual( Links::MAX_TEXT, strlen( $name ) );
		$this->assertSame( 1, preg_match( '//u', $name ) );
		$this->assertStringNotContainsString( '<b>', $name );
	}

	public function test_a_site_name_with_control_characters_still_pairs(): void {
		Functions\when( 'get_bloginfo' )->alias( fn( $key = '' ) => 'version' === $key ? '7.1.2' : ( self::A === $this->site ? 'Site A' : "Site\x01 B" ) );
		$view = $this->aSide()->add( $this->code(), self::OWNER_A );
		$this->assertSame( 'Site B', $view['name'] );
	}

	public function test_a_code_needs_a_user_and_a_right(): void {
		$this->site = self::B;
		$cases      = [
			[ [ 'id' => 0, 'login' => 'boss' ], [ 'pull' ] ],
			[ [ 'id' => 7, 'login' => '' ], [ 'pull' ] ],
			[ self::OWNER_B, [] ],
			[ self::OWNER_B, [ 'delete' ] ],
		];
		foreach ( $cases as [ $owner, $allow ] ) {
			try {
				$this->bSide->createCode( $owner, $allow );
				$this->fail( 'Refused.' );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNull( $this->dbB->value( Options::LINK_CODE ) );
			}
		}
	}

	public function test_http_pairs_with_a_warning_and_own_codes_are_refused(): void {
		$this->bAddress = 'http://b.example';
		$this->site     = $this->bAddress;
		$created        = $this->bSide->createCode( self::OWNER_B, [ 'pull', 'push' ] );
		$this->site     = self::A;
		$this->assertTrue( $created['insecure'] );
		$this->assertSame( self::NOW + 900, $created['expiresAt'] );
		$view = $this->aSide()->add( $created['code'], self::OWNER_A );
		$this->assertTrue( $view['insecure'] );
		$this->assertSame( 'http://b.example', $view['address'] );
		$this->assertSame( [ 'pull', 'push' ], $view['allow'] );

		$this->requests = [];
		$own            = PairingCode::encode( self::A, bin2hex( random_bytes( 16 ) ) );
		$e              = $this->failure( fn() => $this->aSide()->add( $own, self::OWNER_A ) );
		$this->assertSame( LinkException::CODE, $e->reason );
		$this->assertStringContainsString( 'made on this site', $e->getMessage() );
		$this->assertSame( [], $this->requests, 'Nothing is sent.' );

		$e = $this->failure( fn() => $this->aSide()->add( 'lwm1.nope', self::OWNER_A ) );
		$this->assertSame( LinkException::CODE, $e->reason );
		$this->assertSame( [], $this->requests );
	}

	/**
	 * Issue #2: a code from someone else must not aim this site's requests at
	 * an IP address of its network; the filter lw_migration_allow_ip_link_hosts
	 * allows it (local and test setups).
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function ipAddresses(): array {
		return [
			'loopback with a port' => [ 'http://127.0.0.1:8093' ],
			'metadata'             => [ 'http://169.254.169.254' ],
			'public v4'            => [ 'https://93.184.216.34/wp' ],
			'v6'                   => [ 'https://[2606:4700::1111]' ],
		];
	}

	#[DataProvider( 'ipAddresses' )]
	public function test_a_code_that_names_an_ip_address_is_refused_unless_allowed( string $address ): void {
		$this->bAddress = $address;
		$code           = $this->code();
		$raw            = $this->dbB->value( Options::LINK_CODE );
		$this->requests = [];

		$e = $this->failure( fn() => $this->aSide()->add( $code, self::OWNER_A ) );

		$this->assertSame( LinkException::SCHEME, $e->reason );
		$this->assertStringContainsString( 'IP address', $e->getMessage() );
		$this->assertStringContainsString( 'lw_migration_allow_ip_link_hosts', $e->getMessage() );
		$this->assertSame( [], $this->requests, 'Nothing is sent.' );
		$this->assertSame( $raw, $this->dbB->value( Options::LINK_CODE ), 'The other site\'s code still works.' );

		Filters\expectApplied( 'lw_migration_allow_ip_link_hosts' )->once()->with( false )->andReturn( true );
		$view = $this->aSide()->add( $code, self::OWNER_A );
		$this->assertSame( $address, $view['address'] );
	}

	public function test_this_site_being_http_marks_the_new_link_insecure(): void {
		$code       = $this->code();
		$this->site = 'http://a.example';
		$view       = ( new Pairing( $this->a, new Codes( $this->dbA, new SecretBox( str_repeat( 'a', 32 ) ) ), fn(): int => $this->clock, $this->transportFrom( 'http://a.example' ) ) )->add( $code, self::OWNER_A );
		$this->assertTrue( $view['insecure'] );
		$this->assertSame( self::B, $view['address'] );
	}

	/**
	 * The transport, coming back to $site after B's turn.
	 */
	private function transportFrom( string $site ): \Closure {
		$inner = $this->transport();

		return function ( string $url, array $args ) use ( $inner, $site ): array {
			try {
				return $inner( $url, $args );
			} finally {
				$this->site = $site;
			}
		};
	}

	public function test_a_unicode_address_is_sent_as_ascii(): void {
		$this->bAddress = 'https://bé.example/wű/';
		$code           = $this->code();
		$this->assertSame( 'https://xn--b-bga.example/w%C5%B1', PairingCode::decode( $code )['address'] );
		$this->site = 'https://bé.example/wű';
		$this->assertSame( 'https://xn--b-bga.example/w%C5%B1', Pairing::address() );
	}

	public function test_an_address_that_cannot_be_put_into_a_code_is_a_message_not_a_crash(): void {
		foreach ( [ 'https://user:pw@b.example', 'https://b.example:99999', 'https://b_x.example' ] as $address ) {
			$this->site = $address;
			try {
				$this->bSide->createCode( self::OWNER_B, [ 'pull' ] );
				$this->fail( 'Refused: ' . $address );
			} catch ( LinkException $e ) {
				$this->assertSame( LinkException::SCHEME, $e->reason );
				$this->assertStringNotContainsString( 'pw', $e->getMessage() );
			}
			$this->assertNull( $this->dbB->value( Options::LINK_CODE ), 'No code is issued.' );
		}
		$this->site = 'ftp://b.example';
		$this->assertSame( LinkException::SCHEME, $this->failure( fn() => $this->bSide->createCode( self::OWNER_B, [ 'pull' ] ) )->reason );
	}

	public function test_a_full_list_refuses_before_the_code_is_used(): void {
		$this->fill( Links::MAX );
		$code = $this->code();
		$raw  = $this->dbB->value( Options::LINK_CODE );
		$e    = $this->failure( fn() => $this->aSide()->add( $code, self::OWNER_A ) );
		$this->assertSame( 409, $e->status );
		$this->assertSame( 'lw_migration_link_full', $e->remoteCode );
		$this->assertSame( $raw, $this->dbB->value( Options::LINK_CODE ), 'The code still works.' );
		$this->assertNull( $this->dbA->value( Options::LINKS ) );
	}

	public function test_a_failure_after_the_code_was_used_says_make_a_new_code(): void {
		$this->fill( Links::MAX - 1 );
		$code                   = $this->code();
		$this->dbB->beforeWrite = function (): void {
			$this->fill( 1, 'f' );
		};
		$e                      = $this->failure( fn() => $this->aSide()->add( $code, self::OWNER_A ) );
		$this->assertSame( LinkException::CODE, $e->reason );
		$this->assertStringContainsString( 'Make a new code', $e->getMessage() );
		$this->assertNull( $this->dbB->value( Options::LINK_CODE ), 'The code was used.' );
		$this->assertNull( $this->dbA->value( Options::LINKS ) );
	}

	public function test_this_side_full_says_remove_one_first(): void {
		for ( $i = 0; $i < Links::MAX; $i++ ) {
			$this->a->add( [ 'id' => sprintf( 'e%015x', $i ), 'direction' => Links::OUT, 'address' => 'https://x' . $i . '.example', 'name' => 'X', 'allow' => [ 'pull' ], 'createdBy' => self::OWNER_A, 'pairedFrom' => '' ], random_bytes( 32 ), self::NOW, true );
		}
		$code           = $this->code();
		$raw            = $this->dbB->value( Options::LINK_CODE );
		$this->requests = [];
		$e              = $this->failure( fn() => $this->aSide()->add( $code, self::OWNER_A ) );
		$this->assertSame( LinkException::CODE, $e->reason );
		$this->assertStringContainsString( 'Remove one first', $e->getMessage() );
		$this->assertSame( [], $this->requests, 'Checked before the code is sent (m5).' );
		$this->assertSame( $raw, $this->dbB->value( Options::LINK_CODE ), 'The other site\'s code still works.' );
	}

	public function test_this_site_with_an_unfit_address_sends_nothing(): void {
		$code           = $this->code();
		$raw            = $this->dbB->value( Options::LINK_CODE );
		$this->requests = [];
		$this->site     = 'https://a_b.example';
		$aSide          = $this->aSide( $this->transportFrom( 'https://a_b.example' ) );
		$this->assertSame( LinkException::SCHEME, $this->failure( fn() => $aSide->add( $code, self::OWNER_A ) )->reason );
		$this->assertSame( [], $this->requests );
		$this->assertSame( $raw, $this->dbB->value( Options::LINK_CODE ) );
	}

	public function test_a_hello_that_cannot_confirm_leaves_no_link_on_this_side(): void {
		$this->failConfirm = true;
		$e                 = $this->failure( fn() => $this->aSide()->add( $this->code(), self::OWNER_A ) );
		$this->assertSame( 503, $e->status );
		$this->assertTrue( $e->isPassing() );
		$this->assertSame( [], $this->a->all( $this->clock ), 'A removed its link (m4).' );
		$this->assertSame( 'pending', $this->b->all( $this->clock )[0]['state'] );
		$this->assertSame( [], $this->notified );
	}

	public function test_pairing_with_itself_under_another_address_says_so(): void {
		$this->bAddress = 'http://a.example';
		$code           = $this->code();
		$self           = new Pairing( $this->b, $this->codes, fn(): int => $this->clock, $this->transport() );
		$e              = $this->failure( fn() => $self->add( $code, self::OWNER_A ) );
		$this->assertSame( LinkException::CODE, $e->reason );
		$this->assertStringContainsString( 'made on this site', $e->getMessage() );
		$this->assertSame( [], $this->notified );
	}

	public function test_a_network_accepts_no_pairing(): void {
		$code = $this->code();
		Functions\when( 'is_multisite' )->justReturn( true );
		$before = $this->dbB->rows;
		$this->assertFalse( $this->bSide->opens( self::body( $code ) ) );
		$this->assertNull( $this->bSide->accept( self::body( $code ), '203.0.113.5' ) );
		$this->assertSame( $before, $this->dbB->rows );
	}

	private function fill( int $count, string $prefix = 'd' ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			$this->b->add( [ 'id' => $prefix . sprintf( '%015x', $i ), 'direction' => Links::IN, 'address' => 'https://x' . $i . '.example', 'name' => 'X', 'allow' => [ 'pull' ], 'createdBy' => self::OWNER_B, 'pairedFrom' => '' ], random_bytes( 32 ), self::NOW, true );
		}
	}
}
