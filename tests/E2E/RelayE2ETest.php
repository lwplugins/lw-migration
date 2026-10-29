<?php
/**
 * Connecting through the LW sign-in service, against a fake relay and a fake Dropbox (spec auth-lwplugins-com §8).
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\E2E;

/**
 * Run by bin/test-cloud.sh (service relay). Site a adds a Dropbox
 * destination through the LW app, connects it (the CLI prints the relay's
 * authorize address; the fake relay answers like a consent; the callback
 * runs in a real login session), checks it (an upload, a ranged read and a
 * delete at the fake Dropbox), and refreshes once through the fake relay:
 * the first access token is accepted only for the account check. Every
 * request reaches the TLS front only through the plugin's own pin (the
 * names resolve only in the relay probe) and verifies TLS against the run's
 * own CA. The real relay's image replaces the fake relay part once the Go
 * relay plan is done (ruling R11 of plan M7d).
 */
final class RelayE2ETest extends E2ETestCase {

	private const PROBE = 'lwm-e2e-relay-probe.php';

	private const CONFIG = 'lwm-e2e-relay.json';

	/** Where the probe writes every mail instead of sending it. */
	private const MAILS = '/wp-content/lwm-e2e-relay-mails.jsonl';

	protected function setUp(): void {
		parent::setUp();
		if ( false === getenv( 'LWM_E2E_RELAY' ) ) {
			$this->markTestSkipped( 'Run bin/test-cloud.sh to execute the relay tests.' );
		}
		$content = WpSites::path( 'a' ) . '/wp-content';
		if ( ! is_dir( $content . '/mu-plugins' ) ) {
			mkdir( $content . '/mu-plugins', 0755, true );
		}
		copy( __DIR__ . '/relay-probe.php', $content . '/mu-plugins/' . self::PROBE );
		@unlink( WpSites::path( 'a' ) . self::MAILS ); // phpcs:ignore -- gone already is fine.
		file_put_contents(
			$content . '/' . self::CONFIG,
			(string) json_encode(
				[
					'base'      => (string) getenv( 'LWM_E2E_RELAY' ),
					'ca'        => (string) getenv( 'LWM_E2E_RELAY_CA' ),
					'httpHosts' => [ 'site-a.test' ],
				]
			)
		);
		self::relay( 'POST', '/_e2e/control', [ 'refresh' => '' ] );
	}

	protected function tearDown(): void {
		if ( false !== getenv( 'LWM_E2E_RELAY' ) ) {
			[ , $out ] = WpSites::wp( 'a', 'lw-migration', 'destination', 'list', '--format=json' );
			foreach ( array_column( (array) json_decode( $out, true ), 'id' ) as $id ) {
				WpSites::wp( 'a', 'lw-migration', 'destination', 'remove', (string) $id, '--yes' );
			}
			self::relay( 'POST', '/_e2e/control', [ 'refresh' => '' ] );
			$content = WpSites::path( 'a' ) . '/wp-content';
			@unlink( $content . '/mu-plugins/' . self::PROBE ); // phpcs:ignore -- gone already is fine.
			@unlink( $content . '/' . self::CONFIG ); // phpcs:ignore -- gone already is fine.
			@unlink( WpSites::path( 'a' ) . self::MAILS ); // phpcs:ignore -- gone already is fine.
		}
		parent::tearDown();
	}

	/**
	 * A request to the fake relay over TLS (the run's CA, the name pinned to 127.0.0.1), no redirect followed.
	 *
	 * @param array<string, mixed>|null $body JSON body.
	 * @return array{status: int, location: string, body: string}
	 */
	private static function relay( string $method, string $path, ?array $body = null ): array {
		$curl    = curl_init( 'https://lwm-relay.invalid' . $path );
		$options = [
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_RESOLVE        => [ 'lwm-relay.invalid:443:127.0.0.1' ],
			CURLOPT_CAINFO         => (string) getenv( 'LWM_E2E_RELAY_CA' ),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER         => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT        => 30,
		];
		if ( null !== $body ) {
			$options[ CURLOPT_POSTFIELDS ] = (string) json_encode( $body );
			$options[ CURLOPT_HTTPHEADER ] = [ 'Content-Type: application/json' ];
		}
		curl_setopt_array( $curl, $options );
		$raw = curl_exec( $curl );
		if ( ! is_string( $raw ) ) {
			throw new \RuntimeException( 'The fake relay did not answer: ' . curl_error( $curl ) );
		}
		$size = (int) curl_getinfo( $curl, CURLINFO_HEADER_SIZE );

		return [
			'status'   => (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE ),
			'location' => (string) curl_getinfo( $curl, CURLINFO_REDIRECT_URL ),
			'body'     => substr( $raw, $size ),
		];
	}

	private static function refreshes(): int {
		return (int) ( json_decode( self::relay( 'GET', '/_e2e/stats' )['body'], true )['refreshes'] ?? -1 );
	}

	/**
	 * Adds a Dropbox destination through the LW app and connects it.
	 *
	 * @return string Destination id.
	 */
	private function connectDropbox(): string {
		$added = WpSites::ok( 'a', 'lw-migration', 'destination', 'add', 'dropbox', '--name=Relay Box' );
		$this->assertSame( 1, preg_match( '/with ID ([a-f0-9]{8})/', $added, $id ), $added );
		$this->assertStringNotContainsString( 'redirect address', $added, 'Nothing to register for the LW app.' );

		$printed = WpSites::ok( 'a', 'lw-migration', 'destination', 'connect', $id[1], '--user=admin' );
		$this->assertSame( 1, preg_match( '#^(https://lwm-relay\.invalid/v1/authorize\?\S+)$#m', $printed, $link ), $printed );
		parse_str( (string) parse_url( $link[1], PHP_URL_QUERY ), $query );
		$this->assertSame( [ 'v', 'provider', 'return', 'state', 'code_challenge', 'code_challenge_method' ], array_keys( $query ) );
		$this->assertSame( 'dropbox', $query['provider'] );

		$consent = self::relay( 'GET', (string) parse_url( $link[1], PHP_URL_PATH ) . '?' . (string) parse_url( $link[1], PHP_URL_QUERY ) );
		$this->assertSame( 302, $consent['status'], $consent['body'] );
		$this->assertStringStartsWith( (string) $query['return'], $consent['location'] );

		parse_str( (string) parse_url( $consent['location'], PHP_URL_QUERY ), $back );
		unset( $back['rest_route'] );
		$this->assertSame( '1', $back['lwm_relay'] ?? null );
		$client = new RestClient( 'a' );
		$client->start();
		try {
			$auth   = $client->login( 'admin' );
			$answer = $client->call( 'GET', '/oauth/callback', [ 'cookie' => $auth['cookie'], 'nonce' => '' ], $back );
		} finally {
			$client->stop();
		}
		$this->assertSame( 302, $answer['status'] );
		$this->assertStringContainsString( 'lwm-oauth=connected', $answer['headers']['location'] ?? '' );

		return $id[1];
	}

	public function test_connect_upload_and_one_refresh_through_the_relay(): void {
		$before = self::refreshes();
		$id     = $this->connectDropbox();

		[ $code, $out, $err ] = WpSites::wp( 'a', 'lw-migration', 'destination', 'test', $id );

		$this->assertSame( 0, $code, $out . $err );
		$this->assertStringContainsString( 'works: a test file was written, read back and deleted', $out );
		$this->assertSame( $before + 1, self::refreshes(), 'One refresh through the relay after the first token was refused.' );
		$list = (array) json_decode( WpSites::ok( 'a', 'lw-migration', 'destination', 'list', '--format=json' ), true );
		$this->assertSame( [ 'ok', 'Dropbox' ], [ $list[0]['status'] ?? '', $list[0]['where'] ?? '' ] );
		foreach ( [ 'rt-1', 'at-1', 'at-2', 'e2e-code' ] as $secret ) {
			$this->assertStringNotContainsString( $secret, $out . $err . json_encode( $list ) );
		}
	}

	/**
	 * Whether the vault still holds the refresh token the connect stored (never printed).
	 */
	private static function refreshTokenKept( string $id ): bool {
		$php = '$v = \\LightweightPlugins\\Migration\\Storage\\Destinations\\Destinations::fromWordPress()->vault()->open( ' . var_export( $id, true ) . ' );'
			. ' echo is_array( $v ) && "rt-1" === ( $v["refreshToken"] ?? "" ) ? "kept" : "changed";';

		return 'kept' === trim( WpSites::ok( 'a', 'eval', $php ) );
	}

	/**
	 * Review Focus 3: a passing failure with our text, the destination not
	 * `reauth` (so ProfileRepository::revoked(), the only way to the reconnect
	 * mail, never ran), no mail at all, and the stored refresh token untouched:
	 * the next run refreshes with it once the relay answers again.
	 */
	public function test_a_misconfigured_relay_is_a_passing_failure_with_our_text(): void {
		$id = $this->connectDropbox();
		self::relay( 'POST', '/_e2e/control', [ 'refresh' => 'relay_misconfigured' ] );
		$before = self::refreshes();

		[ $code, $out, $err ] = WpSites::wp( 'a', 'lw-migration', 'destination', 'test', $id );

		$this->assertSame( 1, $code, 'WP_CLI::error() with our text: ' . $out . $err );
		$this->assertStringContainsString( 'The LW sign-in service cannot refresh the connection right now; we are on it.', $out . $err );
		$this->assertStringNotContainsString( 'relay_misconfigured', $out . $err, 'Never the relay\'s own words.' );
		$list = (array) json_decode( WpSites::ok( 'a', 'lw-migration', 'destination', 'list', '--format=json' ), true );
		$this->assertNotSame( 'reauth', $list[0]['status'] ?? '', 'No reconnect is asked for.' );
		$this->assertFileDoesNotExist( WpSites::path( 'a' ) . self::MAILS, 'No reconnect mail (no mail at all).' );
		$this->assertTrue( self::refreshTokenKept( $id ), 'The stored refresh token is untouched.' );

		self::relay( 'POST', '/_e2e/control', [ 'refresh' => '' ] );
		[ $code, $out, $err ] = WpSites::wp( 'a', 'lw-migration', 'destination', 'test', $id );

		$this->assertSame( 0, $code, $out . $err );
		$this->assertSame( $before + 1, self::refreshes(), 'The next run refreshed with the kept token.' );
	}
}
