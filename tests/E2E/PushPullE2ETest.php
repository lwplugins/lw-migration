<?php
/**
 * Pushes and pulls between two real WordPress sites over core's HTTP API: the
 * restore, what it keeps, the secrets that never land on disk, rights,
 * removal, the dump, the acting admin across a push, the signed archive
 * route of a pull and a push back after a pull.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\E2E;

use LightweightPlugins\Migration\Link\PairingCode;
use LightweightPlugins\Migration\Tests\Support\TempDir;

/**
 * Site B is served by PHP's built-in web server on 127.0.0.1:8093 (its
 * pairing codes name that address); site A runs `wp lw-migration push` and
 * drives B's restore, or `wp lw-migration pull` and drives B's export and
 * downloads it, over signed requests. Small archives: no media, plugins
 * or themes. The test address option names B's address by home URL, so A's
 * copy, which the push brings to B, still names it.
 */
final class PushPullE2ETest extends E2ETestCase {

	private const PROBE = 'lwm-e2e-link-probe.php';

	private const NS = '\\LightweightPlugins\\Migration\\';

	private const SMALL = [ '--exclude-media', '--exclude-plugins', '--exclude-themes' ];

	private const OPTIONS = [ 'lw_migration_links', 'lw_migration_link_code', 'lw_migration_link_nonces', 'lwm_e2e_mails', 'lwm_e2e_link_address' ];

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
				foreach ( self::OPTIONS as $option ) {
					WpSites::wp( $site, 'option', 'delete', $option );
				}
				foreach ( [ 'boss', 'filler' ] as $user ) {
					WpSites::wp( $site, 'user', 'delete', $user, '--yes' );
				}
				foreach ( [ 'Push E2E', 'Pull E2E' ] as $marker ) {
					$posts = trim( WpSites::ok( $site, 'post', 'list', '--post_type=post', '--s=' . $marker, '--format=ids' ) );
					if ( '' !== $posts ) {
						WpSites::wp( $site, 'post', 'delete', ...array_merge( explode( ' ', $posts ), [ '--force' ] ) );
					}
				}
			}
		}
		parent::tearDown();
	}

	/** Copies the probe into both sites and serves B. */
	private function prepare(): void {
		$this->prepared = true;
		foreach ( [ 'a', 'b' ] as $site ) {
			$dir = WpSites::path( $site ) . '/wp-content/mu-plugins';
			if ( ! is_dir( $dir ) ) {
				mkdir( $dir, 0755, true );
			}
			copy( __DIR__ . '/link-probe.php', $dir . '/' . self::PROBE );
			// By home URL: B announces the test server, A its own address; A's copy reaches B with the push.
			WpSites::ok( $site, 'option', 'update', 'lwm_e2e_link_address', '{"http://site-b.test":"http://127.0.0.1:' . RestClient::PORT . '"}', '--format=json' );
		}
		$this->client = new RestClient( 'b' );
		$this->client->start();
	}

	/**
	 * A code made on B (by $user) typed on A.
	 *
	 * @return array{0: string, 1: string} The connection id and the code's nonce.
	 */
	private static function pair( string $allow, string $user = 'admin' ): array {
		[ $exit, $out, $err ] = WpSites::wp( 'b', 'lw-migration', 'link', 'create-code', '--user=' . $user, '--allow=' . $allow );
		if ( 0 !== $exit || 1 !== preg_match( '/^(lwm1\.\S+)$/m', $out, $code ) ) {
			throw new \RuntimeException( "Unexpected create-code output:\n" . $out . $err );
		}
		[ $exit, $out, $err ] = WpSites::wp( 'a', 'lw-migration', 'link', 'add', $code[1], '--user=admin' );
		if ( 0 !== $exit || 1 !== preg_match( '/Connection id: ([a-f0-9]{16})\.$/m', $out, $id ) ) {
			throw new \RuntimeException( "Unexpected add output:\n" . $out . $err );
		}

		return [ $id[1], PairingCode::decode( $code[1] )['nonce'] ];
	}

	/**
	 * `wp lw-migration push <id> --yes` on A, small.
	 *
	 * @return array{0: int, 1: string, 2: string} Exit code, stdout, stderr.
	 */
	private static function push( string $id ): array {
		return WpSites::wp( 'a', 'lw-migration', 'push', $id, '--yes', ...self::SMALL );
	}

	/**
	 * @return mixed What `wp eval` echoed as JSON on a site.
	 */
	private static function evaluate( string $site, string $php ) {
		return json_decode( WpSites::ok( $site, 'eval', $php ), true );
	}

	/** The raw lw_migration_links value of B, without the last-use times a signed request updates. */
	private static function linksOfB(): array {
		$rows = (array) json_decode( (string) self::evaluate( 'b', 'echo wp_json_encode( get_option( "lw_migration_links", "" ) );' ), true );

		return array_map( static fn( array $row ): array => array_diff_key( $row, [ 'lastUsedAt' => true ] ), $rows );
	}

	public function test_a_site_pushes_itself_to_a_connected_site(): void {
		$this->prepare();
		[ $id, $nonce ] = self::pair( 'push,pull' );
		$secret         = (array) self::evaluate( 'a', '$l = ( new ' . self::NS . 'Link\Links() )->find( ' . var_export( $id, true ) . ', time() ); echo wp_json_encode( [ bin2hex( $l["key"] ), base64_encode( $l["key"] ) ] );' );
		$this->assertCount( 2, $secret );

		$jobsOfA = $this->restored( $id );
		$this->secretsStayInTheirRows( array_merge( $secret, [ $nonce, 'X-LWM-Signature' ] ), $jobsOfA );
		$this->declinedPushLeavesNothing( $id );
		$this->rightsAndRemoval( $id );
		$this->dumpHasNoConnection();
		$this->ownerFollowsThePush();
	}

	/**
	 * Cases 1–3: the push restores A on B, keeping B's address, connections and admin.
	 *
	 * @return string A's job folder of the push.
	 */
	private function restored( string $id ): string {
		$marker  = 'Push E2E ' . bin2hex( random_bytes( 4 ) );
		$post    = trim( WpSites::ok( 'a', 'post', 'create', '--post_title=' . $marker, '--post_status=publish', '--porcelain' ) );
		$links   = self::linksOfB();
		$hash    = trim( WpSites::ok( 'b', 'user', 'get', 'admin', '--field=user_pass' ) );
		$before  = self::backups( 'b' );
		$listing = (array) json_decode( WpSites::ok( 'a', 'lw-migration', 'link', 'list', '--format=json' ), true );
		$this->assertSame( $id, $listing[0]['id'] ?? null );

		[ $exit, $out, $err ] = self::push( $id );

		$this->assertSame( 0, $exit, $out . $err );
		$this->assertStringContainsString( 'On ' . $listing[0]['site'] . ':', $out );
		$this->assertStringContainsString( 'Address: http://site-a.test -> http://site-b.test', $out, 'The other site\'s summary, relayed.' );
		$this->assertStringContainsString( 'A backup of the current database is taken first.', $out );
		$this->assertStringContainsString( 'now shows the content of this site.', $out );
		$this->assertStringContainsString( 'can read it on the way', $err, 'An http connection warns (U1).' );

		$this->assertSame( $marker, trim( WpSites::ok( 'b', 'post', 'get', $post, '--field=post_title' ) ) );
		$this->assertSame( 'http://site-b.test', trim( WpSites::ok( 'b', 'option', 'get', 'home' ) ) );
		$this->assertSame( 'http://site-b.test', trim( WpSites::ok( 'b', 'option', 'get', 'siteurl' ) ) );
		$this->assertSame( $links, self::linksOfB(), 'The import kept B\'s connections.' );
		$this->assertSame( $hash, trim( WpSites::ok( 'b', 'user', 'get', 'admin', '--field=user_pass' ) ), 'B\'s admin keeps its password.' );
		$new = array_values( array_diff( self::backups( 'b' ), $before ) );
		$this->assertCount( 1, $new );
		$this->assertStringStartsWith( 'pre-import-', $new[0] );
		$this->assertNull( WpSites::lock( 'a' ) );
		$this->assertNull( WpSites::lock( 'b' ) );

		$dir = $this->pushFolder();
		$this->assertFileDoesNotExist( $dir . '/archive.wpress' );
		$this->assertSame( [], self::uploadsOfB(), 'B keeps no upload of a finished push.' );

		return $dir;
	}

	/** @return array<int, string> Uploaded archives left in B's job folders. */
	private static function uploadsOfB(): array {
		return array_map( 'basename', (array) glob( WpSites::storage( 'b' ) . '/jobs/*/upload.wpress' ) );
	}

	/**
	 * Review m7: a push whose relayed question is declined (driven tick by
	 * tick on A, then cancelled) releases B's import, and B keeps no upload.
	 */
	private function declinedPushLeavesNothing( string $id ): void {
		$posts = WpSites::ok( 'b', 'post', 'list', '--post_type=post', '--format=ids' );
		$php   = '$s = ' . self::NS . 'Services::get(); $api = ' . self::NS . 'Rest\LinksApi::fromServices( $s );'
			. ' $job = $api->push( ' . var_export( $id, true ) . ', [ "options" => [ "noMedia" => true, "noPlugins" => true, "noThemes" => true ] ] );'
			. ' $r = $s->runner( "push", new ' . self::NS . 'Job\MemorySecretStore() );'
			. ' for ( $i = 0; $i < 300; $i++ ) { $s->lock()->refresh( $job->id, time() ); $j = $r->tick( $job->id, new ' . self::NS . 'Support\TimeDeadline( 10 ), time() );'
			. ' if ( in_array( $j->status, [ "needs_input", "done", "failed", "cancelled" ], true ) ) { break; } }'
			. ' $c = $r->cancel( $job->id, time() ); echo wp_json_encode( [ $j->status, $j->input["kind"] ?? null, $c->status ] );';

		$this->assertSame( [ 'needs_input', 'confirm', 'cancelled' ], self::evaluate( 'a', $php ) );

		$this->assertSame( [], self::uploadsOfB(), 'B keeps no upload of a declined push.' );
		$this->assertSame( $posts, WpSites::ok( 'b', 'post', 'list', '--post_type=post', '--format=ids' ) );
		$this->assertNull( WpSites::lock( 'a' ) );
		$this->assertNull( WpSites::lock( 'b' ) );
	}

	/** A's job folder of the newest push. */
	private function pushFolder(): string {
		$found = null;
		foreach ( (array) glob( WpSites::storage( 'a' ) . '/jobs/*/job.json' ) as $file ) {
			$job = json_decode( (string) file_get_contents( (string) $file ), true );
			if ( 'push' === ( $job['type'] ?? '' ) && ( null === $found || $job['createdAt'] >= $found[1] ) ) {
				$found = [ dirname( (string) $file ), $job['createdAt'], $job['status'] ];
			}
		}
		$this->assertNotNull( $found, 'A has a push job.' );
		$this->assertSame( 'done', $found[2] );

		return $found[0];
	}

	/**
	 * Case 4: no job file and no debug log of either site holds the link
	 * secret, the code's nonce or a signature header (never named in a
	 * failure message).
	 *
	 * @param array<int, string> $needles The secret in hex and base64, the nonce, the header name.
	 */
	private function secretsStayInTheirRows( array $needles, string $push ): void {
		$files = [ $push . '/job.json' ];
		foreach ( [ 'a', 'b' ] as $site ) {
			$tree = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( WpSites::storage( $site ) . '/jobs', \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $tree as $file ) {
				if ( $file->isFile() ) {
					$files[] = $file->getPathname();
				}
			}
			$log = WpSites::path( $site ) . '/wp-content/debug.log';
			if ( is_file( $log ) ) {
				$files[] = $log;
			}
		}
		$this->assertGreaterThan( 2, count( $files ) );
		$leaks = 0;
		foreach ( $files as $file ) {
			$text = (string) file_get_contents( $file );
			foreach ( $needles as $needle ) {
				$leaks += str_contains( $text, $needle ) ? 1 : 0;
			}
		}
		$this->assertSame( 0, $leaks, 'No secret material in the job folders or logs of either site.' );
	}

	/** Case 5: a pull-only link cannot push; a link removed on B is refused. */
	private function rightsAndRemoval( string $id ): void {
		[ $pullOnly ]      = self::pair( 'pull' );
		[ $exit, , $err ]  = self::push( $pullOnly );
		$this->assertNotSame( 0, $exit );
		$this->assertStringContainsString( 'does not allow sending this site there', $err );

		$php    = 'try { ' . self::NS . 'Link\LinkClients::fromWordPress()->forLink( ' . var_export( $pullOnly, true ) . ' )->json( "POST", "/push", [ "ref" => "aaaabbbbccccdddd", "name" => "x-20261009-120000-000.wpress", "size" => 100000, "safetyBackup" => false ] ); echo wp_json_encode( "accepted" ); }'
			. ' catch ( ' . self::NS . 'Link\LinkException $e ) { echo wp_json_encode( [ $e->status, $e->remoteCode ] ); }';
		$this->assertSame( [ 403, 'lw_migration_link_not_allowed' ], self::evaluate( 'a', $php ) );

		$posts = WpSites::ok( 'b', 'post', 'list', '--post_type=post', '--format=ids' );
		WpSites::ok( 'b', 'lw-migration', 'link', 'remove', $id, '--yes' );
		[ $exit, , $err ] = self::push( $id );
		$this->assertNotSame( 0, $exit );
		$this->assertStringContainsString( 'no longer accepts this connection', $err );
		$this->assertSame( $posts, WpSites::ok( 'b', 'post', 'list', '--post_type=post', '--format=ids' ) );
		$this->assertNull( WpSites::lock( 'a' ) );
		$this->assertNull( WpSites::lock( 'b' ) );
		WpSites::ok( 'a', 'lw-migration', 'link', 'remove', $id, '--yes' );
		WpSites::ok( 'a', 'lw-migration', 'link', 'remove', $pullOnly, '--yes' );
	}

	/** Case 6: a backup's database dump holds no connection row. */
	private function dumpHasNoConnection(): void {
		[ $name ] = self::export( 'a', ...self::SMALL );
		$dir      = TempDir::create();
		try {
			WpSites::ok( 'a', 'lw-migration', 'extract', $name, '--dir=' . $dir . '/out' );
			$dump = (string) file_get_contents( $dir . '/out/database.sql' );
			$this->assertStringContainsString( 'INSERT INTO', $dump );
			$this->assertStringNotContainsString( 'lw_migration_link', $dump );
		} finally {
			TempDir::remove( $dir );
			WpSites::ok( 'a', 'lw-migration', 'delete', $name, '--yes' );
		}
	}

	/**
	 * Case 8 (I3): B's code-maker `boss` has another id in A's archive; after
	 * the push B's link acts for boss under the id the import kept; a demoted
	 * boss stops the link.
	 */
	private function ownerFollowsThePush(): void {
		WpSites::ok( 'b', 'user', 'create', 'boss', 'boss@site-b.test', '--role=administrator' );
		WpSites::ok( 'a', 'user', 'create', 'filler', 'filler@site-a.test', '--role=subscriber' );
		WpSites::ok( 'a', 'user', 'create', 'boss', 'boss@site-a.test', '--role=administrator' );
		$this->assertNotSame( trim( WpSites::ok( 'a', 'user', 'get', 'boss', '--field=ID' ) ), trim( WpSites::ok( 'b', 'user', 'get', 'boss', '--field=ID' ) ) );
		[ $id ] = self::pair( 'push,pull', 'boss' );

		[ $exit, $out, $err ] = self::push( $id );
		$this->assertSame( 0, $exit, $out . $err );

		$owner = self::evaluate( 'b', 'echo wp_json_encode( ( new ' . self::NS . 'Link\Links() )->find( ' . var_export( $id, true ) . ', time() )["createdBy"] );' );
		$this->assertSame( [ 'id' => (int) trim( WpSites::ok( 'b', 'user', 'get', 'boss', '--field=ID' ) ), 'login' => 'boss' ], $owner );
		[ $exit, $out, $err ] = self::push( $id );
		$this->assertSame( 0, $exit, 'The link still acts for boss: ' . $out . $err );

		WpSites::ok( 'b', 'user', 'set-role', 'boss', 'subscriber' );
		[ $exit, , $err ] = self::push( $id );
		$this->assertNotSame( 0, $exit );
		$this->assertStringContainsString( 'can no longer use the plugin on this site', $err );
		$this->assertNull( WpSites::lock( 'a' ) );
		$this->assertNull( WpSites::lock( 'b' ) );
	}

	/**
	 * `wp lw-migration pull <id> --yes --user=admin` on A.
	 *
	 * @return array{0: int, 1: string, 2: string} Exit code, stdout, stderr.
	 */
	private static function pull( string $id ): array {
		return WpSites::wp( 'a', 'lw-migration', 'pull', $id, '--yes', '--user=admin' );
	}

	/** The raw lw_migration_links value of a site. */
	private static function rawLinks( string $site ): string {
		return (string) self::evaluate( $site, 'echo wp_json_encode( (string) $GLOBALS["wpdb"]->get_var( "SELECT option_value FROM {$GLOBALS["wpdb"]->options} WHERE option_name = \'lw_migration_links\'" ) );' );
	}

	public function test_a_site_pulls_a_connected_site(): void {
		$this->prepare();
		[ $id, $nonce ] = self::pair( 'push,pull' );
		$secret         = (array) self::evaluate( 'a', '$l = ( new ' . self::NS . 'Link\Links() )->find( ' . var_export( $id, true ) . ', time() ); echo wp_json_encode( [ bin2hex( $l["key"] ), base64_encode( $l["key"] ) ] );' );
		$this->assertCount( 2, $secret );

		[ $jobOfA, $jobOfB ] = $this->pulled( $id );
		$this->otherSiteReleasedItsExport( $jobOfB );
		$this->archiveRouteNeedsItsSignature( $id, $jobOfB );
		$this->secretsStayInTheirRows( array_merge( $secret, [ $nonce, 'X-LWM-Signature' ] ), $jobOfA );
		$this->pullNeedsTheRight();

		// Round trip: the link survived the import on both sides.
		$marker               = 'Push E2E ' . bin2hex( random_bytes( 4 ) );
		$post                 = trim( WpSites::ok( 'a', 'post', 'create', '--post_title=' . $marker, '--post_status=publish', '--porcelain' ) );
		[ $exit, $out, $err ] = self::push( $id );
		$this->assertSame( 0, $exit, $out . $err );
		$this->assertSame( $marker, trim( WpSites::ok( 'b', 'post', 'get', $post, '--field=post_title' ) ) );
		$this->assertNull( WpSites::lock( 'a' ) );
		$this->assertNull( WpSites::lock( 'b' ) );
	}

	/**
	 * Pull cases 1–3: A shows B's content and keeps its address, connections,
	 * admin and a safety backup.
	 *
	 * @return array{0: string, 1: string} A's job folder of the pull, B's export job id.
	 */
	private function pulled( string $id ): array {
		$marker  = 'Pull E2E ' . bin2hex( random_bytes( 4 ) );
		WpSites::ok( 'b', 'post', 'create', '--post_title=' . $marker, '--post_status=publish', '--porcelain' );
		$links   = self::rawLinks( 'a' );
		$hash    = trim( WpSites::ok( 'a', 'user', 'get', 'admin', '--field=user_pass' ) );
		$before  = self::backups( 'a' );
		$ofB     = self::backups( 'b' );
		$listing = (array) json_decode( WpSites::ok( 'a', 'lw-migration', 'link', 'list', '--format=json' ), true );
		$this->assertSame( $id, $listing[0]['id'] ?? null );

		[ $exit, $out, $err ] = self::pull( $id );

		$this->assertSame( 0, $exit, $out . $err );
		$this->assertStringContainsString( 'Importing ' . $listing[0]['site'] . ' over the connection', $out );
		$this->assertStringContainsString( 'Address: http://site-b.test -> http://site-a.test', $out, 'The summary of what is restored.' );
		$this->assertStringContainsString( 'can read it on the way', $err, 'An http connection warns (U1).' );
		$this->assertStringContainsString( 'Success:', $out );

		$this->assertSame( $marker, trim( WpSites::ok( 'a', 'post', 'list', '--post_type=post', '--s=' . $marker, '--field=post_title' ) ) );
		$this->assertSame( 'http://site-a.test', trim( WpSites::ok( 'a', 'option', 'get', 'home' ) ) );
		$this->assertSame( $links, self::rawLinks( 'a' ), 'The import kept A\'s connections byte for byte.' );
		$this->assertSame( $hash, trim( WpSites::ok( 'a', 'user', 'get', 'admin', '--field=user_pass' ) ), 'A\'s admin keeps its password.' );
		$new = array_values( array_diff( self::backups( 'a' ), $before ) );
		$this->assertCount( 1, $new );
		$this->assertStringStartsWith( 'pre-import-', $new[0] );
		$this->assertSame( $ofB, self::backups( 'b' ), 'B stores no backup for a pull.' );
		$this->assertNull( WpSites::lock( 'a' ) );
		$this->assertNull( WpSites::lock( 'b' ) );
		// The restore rewrote B's address to A's in every option, the test's address map too: set A's back.
		WpSites::ok( 'a', 'option', 'update', 'lwm_e2e_link_address', '{"http://site-b.test":"http://127.0.0.1:' . RestClient::PORT . '"}', '--format=json' );

		return $this->pullFolder();
	}

	/**
	 * A's job folder of the newest pull and the id of B's export it drove.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function pullFolder(): array {
		$found = null;
		foreach ( (array) glob( WpSites::storage( 'a' ) . '/jobs/*/job.json' ) as $file ) {
			$job = json_decode( (string) file_get_contents( (string) $file ), true );
			if ( 'link' === ( $job['options']['source'] ?? '' ) && ( null === $found || $job['createdAt'] >= $found[1]['createdAt'] ) ) {
				$found = [ dirname( (string) $file ), $job ];
			}
		}
		$this->assertNotNull( $found, 'A has a pull job.' );
		$this->assertSame( 'done', $found[1]['status'] );
		$this->assertTrue( $found[1]['state']['remote']['released'] ?? null, 'B released its archive.' );

		return [ $found[0], (string) $found[1]['state']['remote']['job'] ];
	}

	/** Pull case 4: B's export is done, names the link and keeps no archive. */
	private function otherSiteReleasedItsExport( string $job ): void {
		$this->assertFileExists( WpSites::storage( 'b' ) . '/jobs/' . $job . '/job.json' );
		$this->assertFileDoesNotExist( WpSites::storage( 'b' ) . '/jobs/' . $job . '/archive.wpress' );
		$view = (array) self::evaluate( 'b', '$s = ' . self::NS . 'Services::get(); echo wp_json_encode( ' . self::NS . 'Rest\JobsApi::fromServices( $s )->view( $s->jobs()->load( ' . var_export( $job, true ) . ' ) ) );' );
		$this->assertSame( [ 'export', 'done' ], [ $view['type'] ?? null, $view['status'] ?? null ] );
		$this->assertSame( 'http://site-a.test', $view['link']['address'] ?? null, 'B\'s job names the link it ran for.' );
	}

	/**
	 * Pull case 5: B's archive route answers only a request signed for it;
	 * a correctly signed one reaches the route (the archive is gone: 409).
	 */
	private function archiveRouteNeedsItsSignature( string $id, string $job ): void {
		$rest    = $this->client;
		$archive = '/link/jobs/' . $job . '/archive';
		$refused = [
			'no headers'  => $rest->call( 'GET', $archive, null ),
			'other route' => $rest->call( 'GET', $archive, null, [], null, self::signedOnA( $id, '/lw-migration/v1/link/jobs/' . $job ) ),
		];
		$bytes   = $refused['no headers']['body'];
		$this->assertSame( 'lw_migration_link_refused', json_decode( $bytes, true )['code'] ?? null );
		foreach ( $refused as $label => $answer ) {
			$this->assertSame( [ 401, $bytes ], [ $answer['status'], $answer['body'] ], $label );
		}
		$signed = $rest->call( 'GET', $archive, null, [], null, self::signedOnA( $id, '/lw-migration/v1' . $archive ) );
		$this->assertSame( [ 409, 'lw_migration_archive_not_ready' ], [ $signed['status'], $signed['json']['code'] ?? null ], $signed['body'] );
	}

	/**
	 * Signature headers made on A for a bodiless GET of $route.
	 *
	 * @return array<int, string>
	 */
	private static function signedOnA( string $id, string $route ): array {
		$php   = 'echo wp_json_encode( ' . self::NS . 'Link\Signer::headers( ' . var_export( $id, true ) . ', ( new ' . self::NS . 'Link\Links() )->find( ' . var_export( $id, true ) . ', time() )["key"], "GET", ' . var_export( $route, true ) . ', "", time() ) );';
		$lines = [];
		foreach ( (array) self::evaluate( 'a', $php ) as $name => $value ) {
			$lines[] = $name . ': ' . $value;
		}
		self::assertCount( 4, $lines );

		return $lines;
	}

	/** Pull case 6: a push-only link cannot pull. */
	private function pullNeedsTheRight(): void {
		[ $pushOnly ]     = self::pair( 'push' );
		[ $exit, , $err ] = self::pull( $pushOnly );
		$this->assertNotSame( 0, $exit );
		$this->assertStringContainsString( 'does not allow copying that site here', $err );
		$this->assertNull( WpSites::lock( 'a' ) );
		WpSites::ok( 'a', 'lw-migration', 'link', 'remove', $pushOnly, '--yes' );
	}

	/** Case 7: a network has no push and no pull. */
	public function test_a_network_cannot_push(): void {
		if ( ! WpSites::hasNetworks() ) {
			$this->markTestSkipped( 'Run LWM_E2E_NETWORK=1 bin/test-wp.sh for the network sites.' );
		}
		foreach ( [ 'push', 'pull' ] as $command ) {
			[ $exit, , $err ] = WpSites::wp( 'n', 'lw-migration', $command, '0123456789abcdef', '--yes' );

			$this->assertNotSame( 0, $exit, $command );
			$this->assertStringContainsString( 'Connections work between single sites only.', $err, $command );
		}
	}
}
