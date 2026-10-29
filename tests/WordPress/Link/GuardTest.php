<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Link;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Link\Guard;
use LightweightPlugins\Migration\Link\Links;
use LightweightPlugins\Migration\Link\Nonces;
use LightweightPlugins\Migration\Link\Signer;
use LightweightPlugins\Migration\Support\SecretBox;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class GuardTest extends MonkeyTestCase {

	private const NOW = 1790000000;

	private const ID = '0123456789abcdef';

	private const ROUTE = '/lw-migration/v1/link/jobs/aaaaaaaaaaaaaaaa/tick';

	private FakeWpdb $db;

	private Links $links;

	private string $key;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'is_multisite' )->justReturn( false );
		$this->db    = FakeWpdb::install();
		$this->links = new Links( null, new SecretBox( str_repeat( 'k', 32 ) ) );
		$this->key   = random_bytes( 32 );
		$this->links->add( [ 'id' => self::ID, 'direction' => Links::IN, 'address' => 'https://a.example', 'name' => 'A', 'allow' => [ 'push', 'pull' ], 'createdBy' => [ 'id' => 1, 'login' => 'admin' ], 'pairedFrom' => '' ], $this->key, self::NOW - 10, true );
	}

	/**
	 * @param array<string, string>     $headers Signature headers.
	 * @param array<string, mixed>|null $query   Query parameters (default: rest_route only).
	 * @return array<string, mixed>|string
	 */
	private function verify( array $headers, string $method = 'POST', string $route = self::ROUTE, string $body = '', ?array $query = null, int $now = self::NOW, bool $pending = false ) {
		return Guard::verify( $method, $route, $query ?? [ 'rest_route' => $route ], $headers, $body, $pending, $this->links, new Nonces(), $now );
	}

	/**
	 * @return array<string, string>
	 */
	private function signed( string $method = 'POST', string $route = self::ROUTE, string $body = '', int $at = self::NOW, string $id = self::ID, ?string $key = null ): array {
		return Signer::headers( $id, $key ?? $this->key, $method, $route, $body, $at );
	}

	public function test_a_signed_request_opens_its_link_once(): void {
		$headers = $this->signed( 'POST', self::ROUTE, '{"a":1}' );
		$link    = $this->verify( $headers, 'POST', self::ROUTE, '{"a":1}' );
		$this->assertIsArray( $link );
		$this->assertSame( self::ID, $link['id'] );
		$this->assertSame( $this->key, $link['key'] );
		$this->assertSame( '', $this->verify( $headers, 'POST', self::ROUTE, '{"a":1}' ), 'A replay is refused.' );
		$this->assertSame( '', $this->verify( $headers, 'POST', self::ROUTE, '{"a":1}', null, self::NOW + 100 ), 'Also later inside the window.' );
	}

	public function test_nothing_is_written_for_a_refused_request(): void {
		$before = $this->db->rows;
		$cases  = [
			'no headers'   => [ [] ],
			'wrong key'    => [ $this->signed( 'POST', self::ROUTE, '', self::NOW, self::ID, str_repeat( 'x', 32 ) ) ],
			'other route'  => [ $this->signed( 'POST', '/lw-migration/v1/link/push' ) ],
			'other body'   => [ $this->signed(), 'POST', self::ROUTE, '{"a":1}' ],
			'other method' => [ $this->signed(), 'GET' ],
			'too old'      => [ $this->signed( 'POST', self::ROUTE, '', self::NOW - 301 ) ],
			'too new'      => [ $this->signed( 'POST', self::ROUTE, '', self::NOW + 301 ) ],
			'extra query'  => [ $this->signed(), 'POST', self::ROUTE, '', [ 'rest_route' => self::ROUTE, 'offset' => '0' ] ],
			'not a link/*' => [ $this->signed( 'POST', '/lw-migration/v1/jobs' ), 'POST', '/lw-migration/v1/jobs' ],
			'unknown link' => [ $this->signed( 'POST', self::ROUTE, '', self::NOW, 'fedcba9876543210' ) ],
			'two link ids' => [ array_merge( $this->signed(), [ Signer::HEADER_LINK => self::ID . ',' . self::ID ] ) ],
		];
		foreach ( $cases as $label => $case ) {
			$this->assertSame( '', $this->verify( ...$case ), $label );
		}
		$this->assertSame( $before, $this->db->rows, 'A refusal writes nothing: no nonce, no last use.' );
	}

	public function test_only_a_confirmed_incoming_link_is_accepted(): void {
		$this->links->add( [ 'id' => 'bbbbbbbbbbbbbbbb', 'direction' => Links::OUT, 'address' => 'https://b.example', 'name' => 'B', 'allow' => [ 'pull' ], 'createdBy' => [ 'id' => 1, 'login' => 'admin' ], 'pairedFrom' => '' ], $this->key, self::NOW, true );
		$this->links->add( [ 'id' => 'cccccccccccccccc', 'direction' => Links::IN, 'address' => 'https://c.example', 'name' => 'C', 'allow' => [ 'pull' ], 'createdBy' => [ 'id' => 1, 'login' => 'admin' ], 'pairedFrom' => '' ], $this->key, self::NOW, false );
		$this->assertSame( '', $this->verify( $this->signed( 'POST', self::ROUTE, '', self::NOW, 'bbbbbbbbbbbbbbbb' ) ), 'An outgoing link never accepts.' );
		$this->assertSame( '', $this->verify( $this->signed( 'POST', self::ROUTE, '', self::NOW, 'cccccccccccccccc' ) ), 'Pending: only the hello.' );
		$this->assertIsArray( $this->verify( $this->signed( 'POST', self::ROUTE, '', self::NOW, 'cccccccccccccccc' ), 'POST', self::ROUTE, '', null, self::NOW, true ) );
	}

	public function test_a_link_whose_secret_no_longer_opens_is_refused(): void {
		$headers = $this->signed();
		$this->assertSame( '', Guard::verify( 'POST', self::ROUTE, [ 'rest_route' => self::ROUTE ], $headers, '', false, new Links( null, new SecretBox( str_repeat( 'z', 32 ) ) ), new Nonces(), self::NOW ) );
	}

	public function test_the_link_route_itself_is_guarded(): void {
		$root = '/lw-migration/v1/link';
		$this->assertSame( Guard::ROOT, $root );
		$this->assertIsArray( $this->verify( $this->signed( 'DELETE', $root ), 'DELETE', $root ), 'DELETE /link reaches the removal (I1).' );
		$near = '/lw-migration/v1/linkx';
		$this->assertSame( '', $this->verify( $this->signed( 'DELETE', $near ), 'DELETE', $near ) );
		$before = $this->db->rows;
		foreach ( [ "\n", "\r", "\0", "\x7F", "\t" ] as $control ) {
			$bent = self::ROUTE . $control;
			// Signer refuses to sign such a route, so the signature is made by hand over the bent text.
			$headers = $this->signed();
			$text    = implode( "\n", [ 'LWM-HMAC-SHA256', self::ID, 'POST', $bent, (string) self::NOW, $headers[ Signer::HEADER_NONCE ], hash( 'sha256', '' ) ] );
			$headers[ Signer::HEADER_SIGNATURE ] = hash_hmac( 'sha256', $text, $this->key );
			$this->assertSame( '', $this->verify( $headers, 'POST', $bent ), 'Control characters in a route are refused (m4).' );
		}
		$this->assertSame( $before, $this->db->rows );
	}

	public function test_a_network_refuses_everything(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		$before = $this->db->rows;
		$this->assertSame( '', $this->verify( $this->signed() ) );
		$this->assertSame( $before, $this->db->rows );
	}

	public function test_a_contended_replay_cache_is_busy_not_refused(): void {
		$this->db->failWrites = true;
		$this->assertSame( Nonces::BUSY, $this->verify( $this->signed() ) );
	}

	public function test_the_last_use_is_noted_after_a_verified_request(): void {
		$this->verify( $this->signed() );
		$this->assertSame( self::NOW, $this->links->find( self::ID, self::NOW )['lastUsedAt'] );
	}

	#[RunInSeparateProcess]
	public function test_the_refusal_is_one_untranslated_constant(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/wp-error-class.php';
		Functions\expect( '__' )->never();
		$a = Guard::refusal();
		$b = Guard::refusal();
		$this->assertSame( [ 'lw_migration_link_refused', 'This request is not accepted.', [ 'status' => 401 ] ], [ $a->get_error_code(), $a->get_error_message(), $a->get_error_data() ] );
		$this->assertEquals( $a, $b );
	}

	#[RunInSeparateProcess]
	public function test_busy_is_a_retryable_503(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/wp-error-class.php';
		$busy = Guard::busy();
		$this->assertSame( [ 'lw_migration_link_busy', [ 'status' => 503 ] ], [ $busy->get_error_code(), $busy->get_error_data() ] );
	}
}
