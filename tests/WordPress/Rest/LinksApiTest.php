<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Rest;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Export\ExportOptions;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Link\Codes;
use LightweightPlugins\Migration\Link\KeyExchange;
use LightweightPlugins\Migration\Link\LinkClients;
use LightweightPlugins\Migration\Link\LinkException;
use LightweightPlugins\Migration\Link\Links;
use LightweightPlugins\Migration\Link\Pairing;
use LightweightPlugins\Migration\Link\PairingCode;
use LightweightPlugins\Migration\Rest\LinksApi;
use LightweightPlugins\Migration\Rest\RestException;
use LightweightPlugins\Migration\Support\SecretBox;
use LightweightPlugins\Migration\Tests\Support\FakeLinkPeer;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\FakeWpError;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use LightweightPlugins\Migration\WordPress\SiteBusyException;

/**
 * Site A's API against a fake site B (FakeLinkPeer); both sides share one recording transport.
 */
final class LinksApiTest extends MonkeyTestCase {

	private const OWNER = [
		'id'    => 3,
		'login' => 'me',
	];

	private const NO_LINK = 'This connection does not exist (any more).';

	/** The site that is acting (site_url, get_bloginfo). */
	private string $site = 'https://a.example';

	private string $aAddress = 'https://a.example';

	private string $bAddress = 'https://b.example';

	private FakeWpdb $db;

	private Links $links;

	private Codes $codes;

	private FakeLinkPeer $peer;

	/** @var array<int, string> Link ids cancelFor() was called with. */
	private array $cancelled = [];

	/** @var array<int, array{0: array<string, mixed>, 1: ExportOptions, 2: bool}> What startPush() was called with. */
	private array $pushed = [];

	/** @var array<int, array{0: array<string, mixed>, 1: bool, 2: array<string, mixed>|null}> What startPull() was called with. */
	private array $pulled = [];

	protected function setUp(): void {
		parent::setUp();
		FakeWpdb::stubCache();
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'site_url' )->alias( fn() => $this->site );
		Functions\when( 'get_bloginfo' )->alias( fn( $key = '' ) => 'version' === $key ? '7.1.2' : ( $this->site === $this->aAddress ? 'Site A' : 'Site B' ) );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $text ) => trim( strip_tags( (string) $text ) ) );
		Functions\when( 'is_wp_error' )->alias( static fn( $r ) => $r instanceof FakeWpError );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $r ) => $r['response']['code'] );
		Functions\when( 'wp_remote_retrieve_header' )->alias( static fn( $r, $h ) => $r['headers'][ strtolower( (string) $h ) ] ?? '' );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $r ) => $r['body'] );
		Functions\when( 'add_query_arg' )->alias( static fn( $key, $value, $base ) => $base . '?' . $key . '=' . $value );
		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) );
		Functions\when( 'wp_parse_url' )->alias( static fn( $url, $component = -1 ) => parse_url( $url, $component ) );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $text ) => strip_tags( (string) $text ) );
		$box         = new SecretBox( str_repeat( 'a', 32 ) );
		$this->db    = new FakeWpdb();
		$this->links = new Links( $this->db, $box );
		$this->codes = new Codes( $this->db, $box );
		$this->peer  = new FakeLinkPeer(
			function ( \Closure $work ) {
				$this->site = $this->bAddress;
				try {
					return $work();
				} finally {
					$this->site = $this->aAddress;
				}
			}
		);
	}

	/**
	 * The one builder of the API under test (Tasks 6 and 7 add their closures here, m17).
	 *
	 * @param array<string, mixed> $overrides Constructor arguments by name.
	 */
	private function api( array $overrides = [] ): LinksApi {
		$now  = static fn(): int => time();
		$send = $this->peer->send();
		$args = array_merge(
			[
				'links'     => $this->links,
				'codes'     => $this->codes,
				'pairing'   => new Pairing( $this->links, $this->codes, $now, $send ),
				'clients'   => new LinkClients( $this->links, $now, $send ),
				'cancelFor' => function ( string $id ): int {
					$this->cancelled[] = $id;
					return 1;
				},
				'startPush' => function ( array $link, ExportOptions $options, bool $safety ): Job {
					$this->pushed[] = [ $link, $options, $safety ];
					return new Job( 'cccccccccccccccc', 'push', [ 'link_upload' ] );
				},
				'startPull' => function ( array $link, bool $safety, ?array $admin ): Job {
					$this->pulled[] = [ $link, $safety, $admin ];
					return new Job( 'dddddddddddddddd', 'import', [ 'link_export', 'link_download' ], 0, [], [ 'source' => 'link', 'location' => $link['id'] ] );
				},
			],
			$overrides
		);

		return new LinksApi( ...array_values( $args ) );
	}

	/**
	 * @param \Closure $call     The call that must fail.
	 * @param int      $status   HTTP status.
	 * @param string   $code     Error code.
	 * @param string   $message  Message ('' = not checked).
	 * @param array<string, mixed> $data Expected data ([] = not checked).
	 */
	private function assertRefused( \Closure $call, int $status, string $code, string $message = '', array $data = [], string $label = '' ): void {
		try {
			$call();
		} catch ( RestException $e ) {
			$this->assertSame( [ $status, $code ], [ $e->status, $e->errorCode ], $label );
			if ( '' !== $message ) {
				$this->assertSame( $message, $e->getMessage(), $label );
			}
			foreach ( $data as $key => $value ) {
				$this->assertSame( $value, $e->data[ $key ] ?? null, $label . ' ' . $key );
			}
			return;
		}
		$this->fail( 'A RestException was expected: ' . $label );
	}

	/** Pairs A with B and returns the link id. */
	private function paired( array $allow = [ 'pull' ] ): string {
		return (string) $this->api()->add( [ 'code' => $this->peer->code( $allow ) ], self::OWNER )['id'];
	}

	public function test_the_list_never_carries_a_secret_or_a_code(): void {
		$id   = $this->paired();
		$code = $this->api()->createCode( [], self::OWNER );

		$all = $this->api()->all();

		$this->assertSame( [ 'items', 'code', 'available' ], array_keys( $all ) );
		$this->assertSame( $this->links->all( time() ), $all['items'] );
		$this->assertSame( $id, $all['items'][0]['id'] );
		$this->assertSame( [ 'expiresAt' => $code['expiresAt'] ], $all['code'] );
		$this->assertTrue( $all['available'] );
		$nonce = PairingCode::decode( $code['code'] )['nonce'];
		$json  = (string) json_encode( $all );
		foreach ( [ $code['code'], $nonce, KeyExchange::lookup( $nonce ), bin2hex( (string) $this->links->find( $id, time() )['key'] ) ] as $secret ) {
			$this->assertStringNotContainsString( $secret, $json );
		}
		$this->assertStringNotContainsString( '"secret"', $json );

		$this->assertSame( [ 'dropped' => true ], $this->api()->dropCode() );
		$this->assertNull( $this->api()->all()['code'] );
		$this->assertSame( [ 'dropped' => false ], $this->api()->dropCode() );
	}

	public function test_a_code_needs_a_valid_right_list(): void {
		$default = $this->api()->createCode( [], self::OWNER );
		$this->assertSame( [ 'code', 'expiresAt', 'insecure' ], array_keys( $default ) );
		$this->assertFalse( $default['insecure'] );
		$open = $this->codes->matches( KeyExchange::lookup( PairingCode::decode( $default['code'] )['nonce'] ), time() );
		$this->assertSame( [ [ 'pull' ], self::OWNER ], [ $open['allow'], $open['createdBy'] ], 'Pull only unless push is ticked (U2); the owner is the current admin.' );

		$both = $this->api()->createCode( [ 'allow' => [ 'pull', 'push' ] ], self::OWNER );
		$open = $this->codes->matches( KeyExchange::lookup( PairingCode::decode( $both['code'] )['nonce'] ), time() );
		$this->assertSame( [ 'pull', 'push' ], $open['allow'] );

		$refused = [
			'empty'       => [ 'allow' => [] ],
			'unknown'     => [ 'allow' => [ 'x' ] ],
			'one unknown' => [ 'allow' => [ 'pull', 'x' ] ],
			'twice'       => [ 'allow' => [ 'pull', 'pull' ] ],
			'string'      => [ 'allow' => 'pull' ],
			'keyed'       => [ 'allow' => [ 'a' => 'pull' ] ],
			'extra key'   => [ 'allow' => [ 'pull' ], 'x' => 1 ],
		];
		foreach ( $refused as $label => $body ) {
			$this->assertRefused( fn() => $this->api()->createCode( $body, self::OWNER ), 400, 'lw_migration_invalid', '', [ 'field' => 'allow' ], $label );
		}

		$this->site = $this->aAddress = 'http://a.example';
		$this->assertTrue( $this->api()->createCode( [], self::OWNER )['insecure'], 'An http address is marked (U1).' );

		$this->site = $this->aAddress = 'http://user@a.example';
		$this->assertRefused( fn() => $this->api()->createCode( [], self::OWNER ), 400, 'lw_migration_invalid', '', [ 'field' => 'code' ], 'unfit address' );
	}

	public function test_adding_validates_the_code_field(): void {
		$bad = [
			'none'      => [],
			'number'    => [ 'code' => 5 ],
			'too long'  => [ 'code' => str_repeat( 'a', 4000 ) ],
			'extra key' => [ 'code' => 'lwm1x', 'x' => 1 ],
		];
		foreach ( $bad as $label => $body ) {
			$this->assertRefused( fn() => $this->api()->add( $body, self::OWNER ), 400, 'lw_migration_invalid', '', [ 'field' => 'code' ], $label );
		}
		$this->assertSame( [], $this->peer->requests, 'Nothing is sent for a malformed body.' );

		$this->assertRefused( fn() => $this->api()->add( [ 'code' => 'garbage' ], self::OWNER ), 400, 'lw_migration_invalid', LinkException::notACode()->getMessage(), [ 'field' => 'code' ], 'not a code' );

		$code        = $this->peer->code();
		$this->site  = $this->aAddress = 'http://user@a.example';
		$this->assertRefused( fn() => $this->api()->add( [ 'code' => $code ], self::OWNER ), 400, 'lw_migration_invalid', '', [ 'field' => 'code' ], 'unfit own address' );
		$this->site = $this->aAddress = 'https://a.example';

		$this->peer->down = true;
		$this->assertRefused( fn() => $this->api()->add( [ 'code' => $code ], self::OWNER ), 502, 'lw_migration_link_failed', '', [ 'reason' => LinkException::UNREACHABLE ], 'unreachable' );
		$this->peer->down = false;

		$this->assertRefused( fn() => $this->api()->add( [ 'code' => $code ], [ 'id' => 0, 'login' => '' ] ), 400, 'lw_migration_invalid', '', [], 'no admin' );

		$this->bAddress = 'http://b.example';
		$view           = $this->api()->add( [ 'code' => $this->peer->code() ], self::OWNER );
		$this->assertTrue( $view['insecure'] );
		$this->assertSame( [ 'out', 'http://b.example', 'ready' ], [ $view['direction'], $view['address'], $view['state'] ] );
	}

	public function test_every_link_failure_has_one_answer(): void {
		$this->assertSame( [ 400, 'lw_migration_invalid', 'code' ], self::shape( LinksApi::refusal( new LinkException( LinkException::CODE, 'Bad code.' ) ) ) );
		$this->assertSame( [ 400, 'lw_migration_invalid', 'code' ], self::shape( LinksApi::refusal( new LinkException( LinkException::SCHEME, 'Bad address.' ) ) ) );
		$this->assertSame( [ 404, 'lw_migration_no_link', null ], self::shape( LinksApi::refusal( new LinkException( LinkException::GONE, 'Gone.' ) ) ) );
		foreach ( [ LinkException::REFUSED, LinkException::CLOCK, LinkException::UNREACHABLE, LinkException::NOT_AVAILABLE, LinkException::REMOTE, LinkException::PROTOCOL ] as $reason ) {
			$e = LinksApi::refusal( new LinkException( $reason, 'Text of ' . $reason ) );
			$this->assertSame( [ 502, 'lw_migration_link_failed', 'Text of ' . $reason, $reason ], [ $e->status, $e->errorCode, $e->getMessage(), $e->data['reason'] ], $reason );
		}
	}

	/**
	 * @return array{0: int, 1: string, 2: mixed}
	 */
	private static function shape( RestException $e ): array {
		return [ $e->status, $e->errorCode, $e->data['field'] ?? null ];
	}

	public function test_removing_an_outgoing_link_tells_the_other_site_best_effort(): void {
		$id                   = $this->paired();
		$this->peer->requests = [];

		$this->assertSame( [ 'removed' => true, 'notified' => true ], $this->api()->remove( $id ) );

		$this->assertCount( 1, $this->peer->requests );
		[ $url, $args ] = $this->peer->requests[0];
		$this->assertStringEndsWith( '?rest_route=/lw-migration/v1/link', $url );
		$this->assertSame( [ 'DELETE', 10 ], [ $args['method'], $args['timeout'] ] );
		$this->assertNull( $this->links->find( $id, time() ) );
		$this->assertNull( $this->peer->links->find( $id, time() ), 'B removed its side.' );
		$this->assertSame( [], $this->cancelled, 'An outgoing link has no jobs here to cancel.' );

		$other            = $this->paired();
		$this->peer->down = true;
		$this->assertSame( [ 'removed' => true, 'notified' => false ], $this->api()->remove( $other ) );
		$this->assertNull( $this->links->find( $other, time() ) );
	}

	public function test_removing_an_incoming_link_cancels_its_jobs(): void {
		$this->links->add( self::incoming( 'aaaaaaaaaaaaaaaa' ), random_bytes( 32 ), time(), true );

		$this->assertSame( [ 'removed' => true, 'notified' => false ], $this->api()->remove( 'aaaaaaaaaaaaaaaa' ) );

		$this->assertSame( [ 'aaaaaaaaaaaaaaaa' ], $this->cancelled );
		$this->assertNull( $this->links->find( 'aaaaaaaaaaaaaaaa', time() ) );
		$this->assertSame( [], $this->peer->requests );
	}

	public function test_forget_cancels_the_links_jobs_and_removes_it(): void {
		$this->links->add( self::incoming( 'aaaaaaaaaaaaaaaa' ), random_bytes( 32 ), time(), true );

		$this->assertSame( [ 'removed' => true ], $this->api()->forget( 'aaaaaaaaaaaaaaaa' ) );
		$this->assertSame( [ 'aaaaaaaaaaaaaaaa' ], $this->cancelled );
		$this->assertNull( $this->links->find( 'aaaaaaaaaaaaaaaa', time() ) );

		$this->assertSame( [ 'removed' => false ], $this->api()->forget( 'aaaaaaaaaaaaaaaa' ) );
	}

	public function test_unknown_ids_are_404(): void {
		foreach ( [ 'ffffffffffffffff', '../x', 'FFFFFFFFFFFFFFFF', '' ] as $id ) {
			$this->assertRefused( fn() => $this->api()->remove( $id ), 404, 'lw_migration_no_link', self::NO_LINK, [], 'remove ' . $id );
			$this->assertRefused( fn() => $this->api()->check( $id ), 404, 'lw_migration_no_link', self::NO_LINK, [], 'check ' . $id );
		}
		$this->assertSame( [], $this->cancelled );
		$this->assertSame( [], $this->peer->requests );
	}

	public function test_check_returns_the_other_sites_hello(): void {
		$id = $this->paired( [ 'pull', 'push' ] );

		$this->assertSame(
			[
				'peer' => [
					'name'      => 'Site B',
					'address'   => 'https://b.example',
					'allow'     => [ 'pull', 'push' ],
					'wordpress' => '7.1.2',
				],
			],
			$this->api()->check( $id )
		);

		$this->links->add( self::incoming( 'aaaaaaaaaaaaaaaa' ), random_bytes( 32 ), time(), true );
		$this->assertRefused( fn() => $this->api()->check( 'aaaaaaaaaaaaaaaa' ), 400, 'lw_migration_invalid', 'Only the site that typed the code can check the connection.' );

		$this->peer->links->remove( $id );
		$this->assertRefused( fn() => $this->api()->check( $id ), 502, 'lw_migration_link_failed', '', [ 'reason' => LinkException::REFUSED ], 'removed on B' );
	}

	public function test_one_builder_for_every_task(): void {
		$calls = 0;
		$api   = $this->api(
			[
				'cancelFor' => static function () use ( &$calls ): int {
					++$calls;
					return 0;
				},
			]
		);
		$this->links->add( self::incoming( 'aaaaaaaaaaaaaaaa' ), random_bytes( 32 ), time(), true );

		$api->forget( 'aaaaaaaaaaaaaaaa' );

		$this->assertSame( 1, $calls );
		$this->assertSame( [], $this->cancelled );
	}

	public function test_push_starts_a_push_over_an_outgoing_link_that_allows_it(): void {
		$id = $this->paired( [ 'pull', 'push' ] );

		$job = $this->api()->push( $id, [ 'options' => [ 'noMedia' => true ], 'safetyBackup' => false ] );

		$this->assertSame( 'cccccccccccccccc', $job->id );
		$this->assertCount( 1, $this->pushed );
		[ $link, $options, $safety ] = $this->pushed[0];
		$this->assertSame( [ $id, 'Site B', 'https://b.example' ], [ $link['id'], $link['name'], $link['address'] ] );
		$this->assertArrayNotHasKey( 'key', $link );
		$this->assertTrue( $options->noMedia );
		$this->assertFalse( $safety );

		$this->api()->push( $id, [] );
		$this->assertTrue( $this->pushed[1][2], 'The other site backs up its database by default.' );
		$this->assertSame( ( new ExportOptions() )->toArray(), $this->pushed[1][1]->toArray() );
	}

	public function test_push_refuses_a_bad_body_a_link_without_the_right_and_an_unknown_link(): void {
		$id   = $this->paired( [ 'pull', 'push' ] );
		$pull = $this->paired( [ 'pull' ] );
		$this->links->add( self::incoming( 'aaaaaaaaaaaaaaaa' ), random_bytes( 32 ), time(), true );
		$plain = 'A push is never encrypted and never chooses sites: the other site restores the archive without a password, and a connection joins two single sites.';

		$this->assertRefused( fn() => $this->api()->push( $id, [ 'type' => 'export' ] ), 400, 'lw_migration_invalid', '', [ 'field' => 'options' ], 'unknown key' );
		$this->assertRefused( fn() => $this->api()->push( $id, [ 'options' => 'all' ] ), 400, 'lw_migration_invalid', '', [ 'field' => 'options' ], 'options not an object' );
		$this->assertRefused( fn() => $this->api()->push( $id, [ 'options' => [ 'encrypt' => true ] ] ), 400, 'lw_migration_invalid', $plain, [ 'field' => 'options' ], 'encrypt' );
		$this->assertRefused( fn() => $this->api()->push( $id, [ 'options' => [ 'sites' => [ 2 ] ] ] ), 400, 'lw_migration_invalid', $plain, [ 'field' => 'options' ], 'sites' );
		$this->api()->push( $id, [ 'options' => [ 'encrypt' => false, 'sites' => [] ] ] );
		$this->assertCount( 1, $this->pushed, 'Saying no to both is fine.' );
		$this->pushed = [];
		$this->assertRefused( fn() => $this->api()->push( $id, [ 'safetyBackup' => 'yes' ] ), 400, 'lw_migration_invalid', '', [ 'field' => 'safetyBackup' ], 'safety backup' );
		$this->assertRefused( fn() => $this->api()->push( $pull, [] ), 400, 'lw_migration_invalid', 'This connection does not allow sending this site there.', [ 'field' => 'link' ], 'pull only' );
		foreach ( [ 'aaaaaaaaaaaaaaaa', 'ffffffffffffffff', '../x' ] as $other ) {
			$this->assertRefused( fn() => $this->api()->push( $other, [] ), 404, 'lw_migration_no_link', '', [], 'not an outgoing link: ' . $other );
		}
		$this->assertSame( [], $this->pushed );
	}

	public function test_push_on_a_busy_site_is_the_site_busy_answer(): void {
		$id  = $this->paired( [ 'push' ] );
		$api = $this->api(
			[
				'startPush' => static function (): Job {
					throw new SiteBusyException( 123 );
				},
			]
		);
		Functions\when( 'wp_date' )->justReturn( '12:00' );

		$this->expectException( SiteBusyException::class );
		$api->push( $id, [] );
	}

	public function test_pull_starts_an_import_over_an_outgoing_link_that_allows_it(): void {
		$id    = $this->paired( [ 'pull' ] );
		$admin = [
			'id'    => 1,
			'login' => 'admin',
		];

		$job = $this->api()->pull( $id, [ 'safetyBackup' => false ], $admin );

		$this->assertSame( 'dddddddddddddddd', $job->id );
		$this->assertCount( 1, $this->pulled );
		[ $link, $safety, $by ] = $this->pulled[0];
		$this->assertSame( [ $id, 'Site B', 'https://b.example' ], [ $link['id'], $link['name'], $link['address'] ] );
		$this->assertArrayNotHasKey( 'key', $link, 'The secret never leaves its row.' );
		$this->assertFalse( $safety );
		$this->assertSame( $admin, $by );

		$this->api()->pull( $id, [], null );
		$this->assertTrue( $this->pulled[1][1], 'This site backs up its database first by default.' );
		$this->assertNull( $this->pulled[1][2] );
	}

	public function test_pull_refuses_a_bad_body_a_link_without_the_right_and_an_unknown_link(): void {
		$id   = $this->paired( [ 'pull' ] );
		$push = $this->paired( [ 'push' ] );
		$this->links->add( self::incoming( 'aaaaaaaaaaaaaaaa' ), random_bytes( 32 ), time(), true );

		$this->assertRefused( fn() => $this->api()->pull( $id, [ 'options' => [] ], null ), 400, 'lw_migration_invalid', '', [ 'field' => 'safetyBackup' ], 'unknown key' );
		$this->assertRefused( fn() => $this->api()->pull( $id, [ 'safetyBackup' => 'yes' ], null ), 400, 'lw_migration_invalid', '', [ 'field' => 'safetyBackup' ], 'not a bool' );
		$this->assertRefused( fn() => $this->api()->pull( $push, [], null ), 400, 'lw_migration_invalid', 'This connection does not allow copying that site here.', [ 'field' => 'link' ], 'push only' );
		foreach ( [ 'aaaaaaaaaaaaaaaa', 'ffffffffffffffff', '../x' ] as $other ) {
			$this->assertRefused( fn() => $this->api()->pull( $other, [], null ), 404, 'lw_migration_no_link', '', [], 'not an outgoing link: ' . $other );
		}
		$this->assertSame( [], $this->pulled );
	}

	public function test_pull_on_a_busy_site_is_the_site_busy_answer(): void {
		$id  = $this->paired( [ 'pull' ] );
		$api = $this->api(
			[
				'startPull' => static function (): Job {
					throw new SiteBusyException( 123 );
				},
			]
		);
		Functions\when( 'wp_date' )->justReturn( '12:00' );

		$this->expectException( SiteBusyException::class );
		$api->pull( $id, [], null );
	}

	/**
	 * @return array{id: string, direction: string, address: string, name: string, allow: array<int, string>, createdBy: array{id: int, login: string}, pairedFrom: string}
	 */
	private static function incoming( string $id ): array {
		return [
			'id'         => $id,
			'direction'  => Links::IN,
			'address'    => 'https://c.example',
			'name'       => 'Site C',
			'allow'      => [ 'pull' ],
			'createdBy'  => [ 'id' => 1, 'login' => 'admin' ],
			'pairedFrom' => '203.0.113.7',
		];
	}
}
