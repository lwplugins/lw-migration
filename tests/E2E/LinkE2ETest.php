<?php
/**
 * Connections on real WordPress sites: pairing, the signed check, the
 * refusals of the signed routes, removal on either side and a far clock.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\E2E;

use LightweightPlugins\Migration\Link\KeyExchange;
use LightweightPlugins\Migration\Link\PairingCode;

/**
 * Site B is served by PHP's built-in web server on 127.0.0.1:8093 (its
 * pairing codes name that address); site A is used through WP-CLI and
 * reaches B over core's HTTP API. A test-only must-use plugin on both sites
 * allows that local address and keeps mails in an option.
 */
final class LinkE2ETest extends E2ETestCase {

	private const PROBE = 'lwm-e2e-link-probe.php';

	private const NS = '\\LightweightPlugins\\Migration\\';

	private const ROWS = [ 'lw_migration_links', 'lw_migration_link_code', 'lw_migration_link_nonces' ];

	private const HELLO = '/link/hello';

	private ?RestClient $client = null;

	private bool $prepared = false;

	protected function tearDown(): void {
		if ( null !== $this->client ) {
			$this->client->stop();
			$this->client = null;
		}
		if ( $this->prepared ) {
			foreach ( [ 'a', 'b' ] as $site ) {
				$probe = WpSites::path( $site ) . '/wp-content/mu-plugins/' . self::PROBE;
				if ( is_file( $probe ) ) {
					unlink( $probe );
				}
				foreach ( array_merge( self::ROWS, [ 'lwm_e2e_mails', 'lwm_e2e_link_address' ] ) as $option ) {
					WpSites::wp( $site, 'option', 'delete', $option );
				}
			}
		}
		parent::tearDown();
	}

	/** Copies the probe into both sites and serves B. */
	private function prepare(): CheckedRest {
		$this->prepared = true;
		foreach ( [ 'a', 'b' ] as $site ) {
			$dir = WpSites::path( $site ) . '/wp-content/mu-plugins';
			if ( ! is_dir( $dir ) ) {
				mkdir( $dir, 0755, true );
			}
			copy( __DIR__ . '/link-probe.php', $dir . '/' . self::PROBE );
		}
		WpSites::ok( 'b', 'option', 'update', 'lwm_e2e_link_address', 'http://127.0.0.1:' . RestClient::PORT );
		$this->client = new RestClient( 'b' );
		$this->client->start();

		return new CheckedRest( $this->client, [ WpSites::storage( 'a' ), WpSites::storage( 'b' ) ] );
	}

	/**
	 * `link create-code` on B.
	 *
	 * @return array{0: string, 1: string, 2: string} The code, stdout and stderr.
	 */
	private static function createCode( string ...$flags ): array {
		[ $code, $out, $err ] = WpSites::wp( 'b', 'lw-migration', 'link', 'create-code', '--user=admin', ...$flags );
		if ( 0 !== $code || 1 !== preg_match( '/^(lwm1\.\S+)$/m', $out, $m ) ) {
			throw new \RuntimeException( "Unexpected create-code output:\n" . $out . $err );
		}

		return [ $m[1], $out, $err ];
	}

	/**
	 * `link add <code>` on A.
	 *
	 * @return array{0: string, 1: string} The new connection id and stderr.
	 */
	private static function add( string $code ): array {
		[ $exit, $out, $err ] = WpSites::wp( 'a', 'lw-migration', 'link', 'add', $code, '--user=admin' );
		if ( 0 !== $exit || 1 !== preg_match( '/Connection id: ([a-f0-9]{16})\.$/m', $out, $m ) ) {
			throw new \RuntimeException( "Unexpected add output:\n" . $out . $err );
		}

		return [ $m[1], $err ];
	}

	/**
	 * @return array<int, array<string, string>> `link list --format=json` of a site.
	 */
	private static function links( string $site ): array {
		return (array) json_decode( WpSites::ok( $site, 'lw-migration', 'link', 'list', '--format=json' ), true );
	}

	/**
	 * @return array<string, mixed> An option of a site as WordPress returns it ('' when missing).
	 */
	private static function option( string $site, string $name ) {
		return json_decode( WpSites::ok( $site, 'eval', 'echo wp_json_encode( get_option( ' . var_export( $name, true ) . ', "" ) );' ), true );
	}

	/** @return array<string, string> The raw connection rows of B. */
	private static function rowsOfB(): array {
		$rows = [];
		foreach ( self::ROWS as $name ) {
			$rows[ $name ] = (string) self::option( 'b', $name );
		}

		return $rows;
	}

	/**
	 * The four signature headers, computed on A (never the key).
	 *
	 * @param string|null $id     A's link id; null for an unknown link with a random key.
	 * @param string      $route  Signed REST route.
	 * @param string      $body   Signed body.
	 * @param int         $offset Seconds added to A's clock.
	 * @return array<int, string> Header lines.
	 */
	private static function signed( ?string $id, string $route = '/lw-migration/v1/link/hello', string $body = '', int $offset = 0, string $method = 'POST' ): array {
		$key = null === $id ? 'random_bytes( 32 )' : '( new ' . self::NS . 'Link\Links() )->find( ' . var_export( $id, true ) . ', time() )["key"]';
		$php = 'echo wp_json_encode( ' . self::NS . 'Link\Signer::headers( ' . var_export( $id ?? bin2hex( random_bytes( 8 ) ), true ) . ', ' . $key . ', '
			. var_export( $method, true ) . ', ' . var_export( $route, true ) . ', ' . var_export( $body, true ) . ', time() + ' . $offset . ' ) );';
		$lines = [];
		foreach ( (array) json_decode( WpSites::ok( 'a', 'eval', $php ), true ) as $name => $value ) {
			$lines[] = $name . ': ' . $value;
		}
		if ( 4 !== count( $lines ) ) {
			throw new \RuntimeException( 'Cannot sign on A.' );
		}

		return $lines;
	}

	/**
	 * LinksApi::check() on A: the answer, or the refusal's message.
	 *
	 * @return array<string, mixed>|string
	 */
	private static function check( string $id ) {
		$php = 'try { echo wp_json_encode( ' . self::NS . 'Rest\LinksApi::fromServices( ' . self::NS . 'Services::get() )->check( ' . var_export( $id, true ) . ' ) ); }'
			. ' catch ( ' . self::NS . 'Rest\RestException $e ) { echo wp_json_encode( $e->getMessage() ); }';

		return json_decode( WpSites::ok( 'a', 'eval', $php ), true );
	}

	public function test_two_sites_pair_check_refuse_and_remove(): void {
		$rest = $this->prepare();

		$id = $this->pairing();

		$peer = self::check( $id );
		$this->assertIsArray( $peer, (string) json_encode( $peer ) );
		$name = trim( WpSites::ok( 'b', 'option', 'get', 'blogname' ) ); // Other tests import A's archive into B.
		$this->assertSame( [ $name, 'http://127.0.0.1:' . RestClient::PORT, [ 'pull' ] ], [ $peer['peer']['name'], $peer['peer']['address'], $peer['peer']['allow'] ] );

		$this->refusals( $rest, $id );
		$id = $this->removal( $id );
		$this->clock( $id );

		$this->assertStringContainsString( 'Success: Removed.', WpSites::ok( 'b', 'lw-migration', 'link', 'remove', $id, '--yes' ) );
		$gone = self::check( $id );
		$this->assertIsString( $gone );
		$this->assertStringContainsString( 'no longer accepts this connection', $gone );
	}

	/** Case 1: B makes a code, A types it; B's admins get one mail. */
	private function pairing(): string {
		[ $code, $out, $err ] = self::createCode();
		$this->assertStringContainsString( 'The other site may: copy this site (pull).', $out, 'Pull only by default (U2).' );
		$this->assertStringContainsString( 'can read it on the way', $err, 'An http site warns (U1).' );
		$nonce = PairingCode::decode( $code )['nonce'];

		[ $id, $warning ] = self::add( $code );
		$this->assertStringContainsString( 'can read it on the way', $warning );

		$a = self::links( 'a' );
		$this->assertCount( 1, $a );
		$this->assertSame( [ $id, 'out', 'ready', 'pull' ], [ $a[0]['id'], $a[0]['direction'], $a[0]['state'], $a[0]['allow'] ] );
		$b = self::links( 'b' );
		$this->assertCount( 1, $b );
		$this->assertSame( [ $id, 'in', 'ready', 'yes' ], [ $b[0]['id'], $b[0]['direction'], $b[0]['state'], $b[0]['http'] ] );
		$this->assertStringContainsString( 'from 127.0.0.1', $b[0]['paired'] );

		$mails = (array) self::option( 'b', 'lwm_e2e_mails' );
		$this->assertCount( 1, $mails, 'One mail per admin.' );
		$this->assertSame( 'admin@site-b.test', $mails[0]['to'] );
		foreach ( [ 'http://site-a.test', '127.0.0.1', 'may copy this site', 'wp lw-migration link remove ' . $id ] as $text ) {
			$this->assertStringContainsString( $text, $mails[0]['message'] );
		}
		$this->assertSame( '', self::option( 'a', 'lwm_e2e_mails' ), 'A sends no mail.' );

		[ $exit, , $again ] = WpSites::wp( 'a', 'lw-migration', 'link', 'add', $code, '--user=admin' );
		$this->assertNotSame( 0, $exit );
		$this->assertStringContainsString( 'did not accept this code', $again );
		$this->assertCount( 1, self::links( 'a' ) );

		$texts = [ (string) json_encode( $mails ), (string) json_encode( $a ), (string) json_encode( $b ), (string) json_encode( self::rowsOfB() ), (string) json_encode( self::option( 'a', 'lw_migration_links' ) ) ];
		foreach ( $texts as $text ) {
			$this->assertStringNotContainsString( $nonce, $text );
		}

		return $id;
	}

	/** Case 3: every refusal is the same 401 bytes and writes nothing but the one accepted hello. */
	private function refusals( CheckedRest $rest, string $id ): void {
		[ $code ] = self::createCode();
		$nonce    = PairingCode::decode( $code )['nonce'];
		$before   = self::rowsOfB();
		$fresh    = self::signed( $id );
		$public   = bin2hex( KeyExchange::keypair()['public'] );
		$shape    = [
			'protocol' => 1,
			'code'     => bin2hex( random_bytes( 32 ) ),
			'public'   => $public,
			'address'  => 'http://site-a.test',
			'name'     => 'A',
			'mac'      => bin2hex( random_bytes( 32 ) ),
		];
		$wrongMac = array_merge( $shape, [ 'code' => KeyExchange::lookup( $nonce ) ] );

		$hello   = '/lw-migration/v1/link/hello';
		$signed  = [
			'unknown link' => self::signed( null ),
			'other route'  => self::signed( $id, '/lw-migration/v1/link/pull' ),
			'changed body' => self::signed( $id, $hello, '{"a":1}' ),
			'old time'     => self::signed( $id, $hello, '', -301 ),
			'extra query'  => self::signed( $id ),
			'multipart'    => self::signed( $id ),
			'garbled'      => self::signed( $id, $hello, '{"a":' ),
		];
		$refused = [
			'no headers'   => $rest->call( 'POST', self::HELLO, null ),
			'unknown link' => $rest->call( 'POST', self::HELLO, null, [], null, $signed['unknown link'] ),
			'other route'  => $rest->call( 'POST', self::HELLO, null, [], null, $signed['other route'] ),
			'changed body' => $rest->call( 'POST', self::HELLO, null, [], [ 'a' => 2 ], $signed['changed body'] ),
			'old time'     => $rest->call( 'POST', self::HELLO, null, [], null, $signed['old time'] ),
			'extra query'  => $rest->call( 'POST', self::HELLO, null, [ 'x' => 1 ], null, $signed['extra query'] ),
			'multipart'    => $rest->call( 'POST', self::HELLO, null, [], "--x\r\nContent-Disposition: form-data; name=\"offset\"\r\n\r\n0\r\n--x--\r\n", array_merge( $signed['multipart'], [ 'Content-Type: multipart/form-data; boundary=x' ] ) ),
			'random code'  => $rest->call( 'POST', '/link/pair', null, [], $shape ),
			'wrong mac'    => $rest->call( 'POST', '/link/pair', null, [], $wrongMac ),
		];
		$first             = $rest->call( 'POST', self::HELLO, null, [], null, $fresh );
		$refused['replay'] = $rest->call( 'POST', self::HELLO, null, [], null, $fresh );
		$this->assertSame( 200, $first['status'], $first['body'] );
		$this->assertSame( 'no-store, private', $first['headers']['cache-control'] ?? null );

		$bytes = $refused['no headers']['body'];
		$this->assertSame( 'lw_migration_link_refused', json_decode( $bytes, true )['code'] ?? null );
		foreach ( $refused as $label => $answer ) {
			$this->assertSame( [ 401, $bytes ], [ $answer['status'], $answer['body'] ], $label );
			$this->assertArrayNotHasKey( 'set-cookie', $answer['headers'], $label );
			$this->assertSame( 'no-store, private', $answer['headers']['cache-control'] ?? null, $label );
		}

		// Core refuses garbled JSON before dispatching it; its Allow-header pass asks the guard, which writes
		// nothing there (checked with the rows below). No detail beyond core's own text.
		$garbled = $rest->call( 'POST', self::HELLO, null, [], '{"a":', array_merge( $signed['garbled'], [ 'Content-Type: application/json' ] ) );
		$this->assertSame( [ 400, 'rest_invalid_json' ], [ $garbled['status'], $garbled['json']['code'] ?? null ], $garbled['body'] );
		$this->assertArrayNotHasKey( 'set-cookie', $garbled['headers'] );
		$this->assertStringNotContainsString( $id, $garbled['body'] );

		$after = self::rowsOfB();
		$this->assertSame( $before['lw_migration_link_code'], $after['lw_migration_link_code'], 'The open code is untouched.' );
		$this->assertSame( self::withoutUse( $before['lw_migration_links'] ), self::withoutUse( $after['lw_migration_links'] ), 'Only the hello\'s last use may change.' );
		$added = array_diff_key( (array) json_decode( $after['lw_migration_link_nonces'], true ), (array) json_decode( $before['lw_migration_link_nonces'], true ) );
		$this->assertCount( 1, $added, 'Exactly the accepted hello was remembered; the garbled JSON core refused used no nonce.' );
		$this->assertStringStartsWith( $id . ':', (string) array_key_first( $added ) );
		WpSites::ok( 'b', 'option', 'delete', 'lw_migration_link_code' );
	}

	/**
	 * @return array<int, array<string, mixed>> Link rows without lastUsedAt.
	 */
	private static function withoutUse( string $raw ): array {
		return array_map( static fn( array $row ): array => array_diff_key( $row, [ 'lastUsedAt' => true ] ), (array) json_decode( $raw, true ) );
	}

	/** Case 4: removing on A tells B; a new code pairs again. */
	private function removal( string $id ): string {
		$out = WpSites::ok( 'a', 'lw-migration', 'link', 'remove', $id, '--yes' );
		$this->assertStringContainsString( 'The other site was told.', $out );
		$this->assertSame( [], self::links( 'a' ) );
		$this->assertSame( [], self::links( 'b' ) );

		[ $code ] = self::createCode( '--allow=pull,push' );
		[ $new ]  = self::add( $code );
		$this->assertNotSame( $id, $new );
		$this->assertSame( 'pull,push', self::links( 'b' )[0]['allow'] );

		return $new;
	}

	/** Case 5: A's clock ten minutes ahead: B refuses, A names the clock. */
	private function clock( string $id ): void {
		$php = '$l = ( new ' . self::NS . 'Link\Links() )->find( ' . var_export( $id, true ) . ', time() );'
			. ' $c = new ' . self::NS . 'Link\Client( [ "id" => $l["id"], "address" => $l["address"], "key" => $l["key"] ], null, static fn(): int => time() + 600 );'
			. ' try { $c->json( "POST", "/hello" ); echo wp_json_encode( "accepted" ); }'
			. ' catch ( ' . self::NS . 'Link\LinkException $e ) { echo wp_json_encode( [ $e->reason, $e->getMessage() ] ); }';
		$result = json_decode( WpSites::ok( 'a', 'eval', $php ), true );

		$this->assertIsArray( $result, (string) json_encode( $result ) );
		$this->assertSame( 'clock', $result[0] );
		$this->assertStringContainsString( '10 minutes', $result[1] );
	}

	public function test_the_link_command_has_four_subcommands(): void {
		$help = WpSites::ok( 'a', 'help', 'lw-migration', 'link' );

		foreach ( [ 'create-code', 'add', 'list', 'remove' ] as $subcommand ) {
			$this->assertMatchesRegularExpression( '/^\s+' . $subcommand . '\s/m', $help, $subcommand );
		}
	}

	/** Case 6: a network has no connection route and no link command. */
	public function test_a_network_has_no_connections(): void {
		if ( ! WpSites::hasNetworks() ) {
			$this->markTestSkipped( 'Run LWM_E2E_NETWORK=1 bin/test-wp.sh for the network sites.' );
		}
		[ $exit, , $err ] = WpSites::wp( 'n', 'lw-migration', 'link', 'list' );
		$this->assertNotSame( 0, $exit );
		$this->assertStringContainsString( 'Connections work between single sites only.', $err );

		$routes = (array) json_decode( WpSites::ok( 'n', 'eval', 'echo wp_json_encode( array_keys( rest_get_server()->get_routes() ) );' ), true );
		$this->assertContains( '/lw-migration/v1/jobs', $routes, 'The other routes are there.' );
		$this->assertSame( [], array_values( array_filter( $routes, static fn( string $route ): bool => str_starts_with( $route, '/lw-migration/v1/link' ) ) ) );
	}
}
