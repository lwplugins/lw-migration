<?php
/**
 * Cloud destinations against MinIO (spec 21.9 "Integráció (Docker): MinIO").
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\E2E;

use LightweightPlugins\Migration\Archive\Reader;
use LightweightPlugins\Migration\Backups\BackupName;

/**
 * Run by bin/test-cloud.sh. Site a uploads to folders of the bucket lwm-e2e;
 * site b restores and downloads from them. A 12 MiB file in site a's uploads
 * makes every backup at least three 5 MiB parts. Both sites reach MinIO by
 * the name lwm-minio.invalid, which only the cloud probe resolves, while the
 * probe makes fsockopen every request's default transport: a request that
 * arrives proves the plugin's forced cURL and its CURLOPT_RESOLVE pin.
 */
final class CloudE2ETest extends E2ETestCase {

	private const PROBE = 'lwm-e2e-cloud-probe.php';

	private const HOST = 'lwm-minio.invalid';

	protected function setUp(): void {
		parent::setUp();
		if ( false === getenv( 'LWM_E2E_MINIO' ) ) {
			$this->markTestSkipped( 'Run bin/test-cloud.sh to execute the cloud tests.' );
		}
		foreach ( [ 'a', 'b' ] as $site ) {
			$mu = WpSites::path( $site ) . '/wp-content/mu-plugins';
			if ( ! is_dir( $mu ) ) {
				mkdir( $mu, 0755, true );
			}
			copy( __DIR__ . '/cloud-probe.php', $mu . '/' . self::PROBE );
		}
		$uploads = WpSites::path( 'a' ) . '/wp-content/uploads/2026/10';
		if ( ! is_dir( $uploads ) ) {
			mkdir( $uploads, 0755, true );
		}
		if ( ! is_file( $uploads . '/big.bin' ) ) {
			file_put_contents( $uploads . '/big.bin', random_bytes( 12 * 1048576 ) ); // Random: no compression shrinks it below three parts.
		}
	}

	protected function tearDown(): void {
		if ( false !== getenv( 'LWM_E2E_MINIO' ) ) {
			foreach ( [ 'a', 'b' ] as $site ) {
				[ , $out ] = WpSites::wp( $site, 'lw-migration', 'destination', 'list', '--format=json' );
				foreach ( array_column( (array) json_decode( $out, true ), 'id' ) as $id ) {
					WpSites::wp( $site, 'lw-migration', 'destination', 'remove', (string) $id, '--yes' );
				}
			}
			@unlink( WpSites::path( 'a' ) . '/wp-content/uploads/2026/10/big.bin' ); // phpcs:ignore -- gone already is fine.
		}
		foreach ( [ 'a', 'b' ] as $site ) {
			@unlink( WpSites::path( $site ) . '/wp-content/mu-plugins/' . self::PROBE ); // phpcs:ignore -- gone already is fine.
		}
		putenv( 'http_proxy' );
		putenv( 'ALL_PROXY' );
		parent::tearDown();
	}

	private static function secret(): string {
		return (string) getenv( 'LWM_E2E_MINIO_SECRET' );
	}

	/** MinIO by the name only the probe resolves (http://lwm-minio.invalid:<port>). */
	private static function endpoint(): string {
		return 'http://' . self::HOST . ':' . (int) parse_url( (string) getenv( 'LWM_E2E_MINIO' ), PHP_URL_PORT );
	}

	/**
	 * Adds the MinIO destination on a site (the secret on STDIN) and answers its id.
	 */
	private static function addDestination( string $site, string $name = 'MinIO', string $prefix = 'site-a', ?string $secret = null ): string {
		[ $code, $out, $err ] = WpSites::wpInput(
			$site,
			( $secret ?? self::secret() ) . "\n",
			'lw-migration',
			'destination',
			'add',
			's3',
			'--name=' . $name,
			'--preset=custom',
			'--endpoint=' . self::endpoint(),
			'--bucket=lwm-e2e',
			'--prefix=' . $prefix,
			'--access-key=' . getenv( 'LWM_E2E_MINIO_USER' )
		);
		self::assertSame( 0, $code, $out . $err );
		self::assertSame( 1, preg_match( '/with ID ([a-f0-9]{8})/', $out, $m ), $out );
		self::assertStringNotContainsString( self::secret(), $out . $err );

		return $m[1];
	}

	/**
	 * @return array<int, array{name: string, size: string, date: string, ours: string}>
	 */
	private static function remote( string $site, string $id ): array {
		return (array) json_decode( WpSites::ok( $site, 'lw-migration', 'cloud', 'list', $id, '--format=json' ), true );
	}

	/**
	 * Runs PHP inside a site (plugin classes loaded) and answers its output.
	 */
	private static function inSite( string $php, string $site = 'a' ): string {
		return trim( WpSites::ok( $site, 'eval', $php ) );
	}

	/** Size and SHA-1 of a local backup of a site ('' when there is none). */
	private static function localHash( string $site, string $name ): string {
		return self::inSite( '$b = \LightweightPlugins\Migration\Services::get()->backups()->find( ' . var_export( $name, true ) . ' ); echo null === $b ? "" : filesize( $b->path ) . " " . sha1_file( $b->path );', $site );
	}

	/**
	 * Size and SHA-1 of a file at a destination, read back in ranges from inside site a ('' when it is not there).
	 */
	private static function remoteSizeAndHash( string $id, string $name ): string {
		$php = '$d = \LightweightPlugins\Migration\Storage\Destinations\Destinations::fromWordPress();'
			. ' $dest = $d->open( $d->find( ' . var_export( $id, true ) . ' ) );'
			. ' foreach ( $dest->list() as $f ) { if ( ' . var_export( $name, true ) . ' !== $f->name ) { continue; }'
			. ' $h = hash_init( "sha1" ); for ( $o = 0; $o < $f->size; $o += 8388608 ) { hash_update( $h, $dest->readRange( $f, $o, min( 8388608, $f->size - $o ) ) ); }'
			. ' echo $f->size, " ", hash_final( $h ); }';

		return self::inSite( $php );
	}

	/** @return array<string, string> A destination's row in `destination list` of site a. */
	private static function destinationRow( string $id ): array {
		$rows = array_column( (array) json_decode( WpSites::ok( 'a', 'lw-migration', 'destination', 'list', '--format=json' ), true ), null, 'id' );

		return (array) ( $rows[ $id ] ?? [] );
	}

	/**
	 * Unfinished multipart uploads in the bucket (ListMultipartUploads), counted from inside site a.
	 */
	private static function openUploads( string $id ): int {
		$php = '$d = \LightweightPlugins\Migration\Storage\Destinations\Destinations::fromWordPress();'
			. ' $p = $d->find( ' . var_export( $id, true ) . ' );'
			. ' $e = \LightweightPlugins\Migration\Storage\Destinations\S3\S3Endpoint::fromSettings( $p->settings );'
			. ' $s = $d->vault()->open( $p->id );'
			. ' $c = new \LightweightPlugins\Migration\Storage\Destinations\S3\S3Client( $e, new \LightweightPlugins\Migration\Storage\Destinations\S3\SigV4Signer( $p->settings["accessKey"], $s["secretKey"], $e->region ) );'
			. ' echo substr_count( $c->request( "GET", "", [ "uploads" => "" ] )["body"], "<Upload>" );';

		return (int) self::inSite( $php );
	}

	/**
	 * The name is unknown to the system resolver and the site's default
	 * transport is fsockopen (no http_api_curl): only the plugin's pinned,
	 * forced cURL request reaches MinIO by that name. Proxies from the
	 * environment (pointing at a closed port) are switched off for it; the
	 * WordPress proxy settings make a custom named endpoint refuse instead.
	 */
	public function test_a_named_endpoint_is_reached_only_through_the_pinned_curl_request(): void {
		$port  = (int) parse_url( (string) getenv( 'LWM_E2E_MINIO' ), PHP_URL_PORT );
		$plain = '$n = 0; add_action( "http_api_curl", static function () use ( &$n ) { ++$n; } );'
			. ' $r = wp_remote_get( %s, [ "timeout" => 5 ] );'
			. ' echo is_wp_error( $r ) ? "error" : wp_remote_retrieve_response_code( $r ), " curl=", $n;';
		$this->assertSame( 'error curl=0', self::inSite( sprintf( $plain, var_export( self::endpoint() . '/minio/health/live', true ) ) ), 'The system resolver does not know the name.' );
		$this->assertSame( '200 curl=0', self::inSite( sprintf( $plain, var_export( 'http://127.0.0.1:' . $port . '/minio/health/live', true ) ) ), 'Requests of the site do not use cURL by default.' );

		$id = self::addDestination( 'a', 'MinIO named' );
		putenv( 'http_proxy=http://127.0.0.1:9' );
		putenv( 'ALL_PROXY=http://127.0.0.1:9' );
		$direct = '$h = curl_init( ' . var_export( self::endpoint() . '/minio/health/live', true ) . ' ); curl_setopt_array( $h, [ CURLOPT_RESOLVE => [ ' . var_export( self::HOST . ':' . $port . ':127.0.0.1', true ) . ' ], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5 ] ); echo false === curl_exec( $h ) ? "refused" : "reached";';
		$this->assertSame( 'refused', self::inSite( $direct ), 'cURL in the site honours the environment proxy.' );
		$this->assertStringContainsString( 'works', WpSites::ok( 'a', 'lw-migration', 'destination', 'test', $id ) );
		putenv( 'http_proxy' );
		putenv( 'ALL_PROXY' );

		$proxy = (string) tempnam( sys_get_temp_dir(), 'lwm-proxy' );
		file_put_contents( $proxy, "<?php\ndefine( 'WP_PROXY_HOST', '127.0.0.1' );\ndefine( 'WP_PROXY_PORT', '9' );\n" );
		try {
			[ $code, $out, $err ] = WpSites::wp( 'a', '--require=' . $proxy, 'lw-migration', 'destination', 'test', $id );
		} finally {
			unlink( $proxy );
		}
		$this->assertSame( 1, $code, $out . $err );
		$this->assertStringContainsString( "The site's WordPress proxy setting prevents checking the address of", $err );
	}

	public function test_a_backup_goes_up_in_parts_and_is_listed_with_its_size(): void {
		$id              = self::addDestination( 'a' );
		[ $name, $path ] = self::export( 'a', '--to=' . $id );

		$files = array_column( self::remote( 'a', $id ), null, 'name' );
		$this->assertArrayHasKey( $name, $files );
		$this->assertSame( 'yes', $files[ $name ]['ours'] );
		$this->assertGreaterThan( 2 * 5 * 1048576, filesize( $path ), 'At least three parts.' );
		$this->assertSame( filesize( $path ) . ' ' . sha1_file( $path ), self::remoteSizeAndHash( $id, $name ), 'The listed size is the backup\'s, and so are the bytes.' );
		$this->assertSame( 0, self::openUploads( $id ) );
		$this->assertSame( 'ok', self::destinationRow( $id )['status'] ?? null );
	}

	/**
	 * Review Focus 2.
	 */
	public function test_a_killed_upload_resumes_in_the_next_tick(): void {
		$id   = self::addDestination( 'a', 'MinIO kill' );
		$a    = WpSites::path( 'a' );
		$name = self::export( 'a' )[0];
		file_put_contents( $a . '/wp-content/lwm-e2e-kill-after-part', '2' );
		$client = new RestClient( 'a' );
		$client->start();
		try {
			$auth  = $client->login( 'admin' );
			$start = $client->call( 'POST', '/backups/upload', $auth, [], [ 'folder' => 'own', 'name' => $name, 'destinations' => [ $id ] ] );
			$this->assertSame( 201, $start['status'], $start['body'] );
			$job  = (string) $start['json']['id'];
			$died = 0;
			$tick = [];
			for ( $i = 0; $i < 100; $i++ ) {
				$tick = $client->call( 'POST', '/jobs/' . $job . '/tick', $auth );
				if ( 200 !== $tick['status'] || ! is_array( $tick['json'] ) ) {
					++$died; // The probe ended PHP inside this tick.
					continue;
				}
				if ( in_array( $tick['json']['status'], [ 'done', 'failed' ], true ) ) {
					break;
				}
			}
		} finally {
			$client->stop();
			@unlink( $a . '/wp-content/lwm-e2e-kill-after-part' ); // phpcs:ignore -- gone already is the expected case.
		}

		$this->assertSame( 1, $died, 'Exactly one tick died after part 2.' );
		$this->assertSame( 'done', $tick['json']['status'], (string) json_encode( $tick['json'] ) );
		$this->assertSame( 'ok', $tick['json']['result']['cloud'][0]['status'] );
		$files = array_column( self::remote( 'a', $id ), null, 'name' );
		$this->assertArrayHasKey( $name, $files );
		$this->assertSame( self::localHash( 'a', $name ), self::remoteSizeAndHash( $id, $name ), 'The resumed object is the backup, byte for byte.' );
		$this->assertSame( 0, self::openUploads( $id ), 'The resumed upload completed its one multipart upload; none was started twice.' );
	}

	/**
	 * Review Focus 4, and the upload command's exit status.
	 */
	public function test_a_failed_destination_keeps_the_local_copy_and_reports_partial(): void {
		$good = self::addDestination( 'a', 'MinIO good' );
		$bad  = self::addDestination( 'a', 'MinIO bad', 'site-a', 'not-the-secret' );

		[ $code, $out, $err ] = WpSites::wp( 'a', 'lw-migration', 'export', '--to=' . $good . ',' . $bad, '--no-keep-local' );

		$this->assertSame( 0, $code, $out . $err );
		$this->assertStringContainsString( 'Uploaded to MinIO good.', $out );
		$this->assertStringContainsString( 'Upload to MinIO bad failed', $out . $err );
		$this->assertStringContainsString( 'The backup is ready, but not every upload succeeded.', $err );
		$this->assertSame( 1, preg_match( '/Backup file: (\S+\.wpress)/', $out, $m ) );
		$this->assertContains( $m[1], self::backups( 'a' ), 'The local copy stays.' );

		[ $code, $out, $err ] = WpSites::wp( 'a', 'lw-migration', 'upload', $m[1], '--to=' . $good . ',' . $bad );

		$this->assertSame( 1, $code, $out . $err );
		$this->assertStringContainsString( 'Uploaded to MinIO good.', $out );
		$this->assertStringContainsString( 'Not every upload succeeded.', $err );
		$this->assertStringNotContainsString( self::secret(), $out . $err );
	}

	public function test_keep_local_off_deletes_the_local_copy_only_after_every_upload_succeeded(): void {
		$id = self::addDestination( 'a', 'MinIO no local', 'no-local' );

		[ $code, $out, $err ] = WpSites::wp( 'a', 'lw-migration', 'export', '--to=' . $id, '--no-keep-local' );

		$this->assertSame( 0, $code, $out . $err );
		$this->assertStringContainsString( 'The local copy was deleted: every upload succeeded.', $out );
		$this->assertSame( 1, preg_match( '/Backup file: (\S+\.wpress)/', $out, $m ) );
		$this->assertNotContains( $m[1], self::backups( 'a' ) );
		$this->assertContains( $m[1], array_column( self::remote( 'a', $id ), 'name' ) );
	}

	/**
	 * Review Focus 5.
	 */
	public function test_retention_prunes_only_this_sites_old_backups(): void {
		$id      = self::addDestination( 'a', 'MinIO keep', 'retention' );
		$foreign = [ 'other-site-test-20200101-000000-001.wpress', 'by-hand.wpress', BackupName::safety( 1577836800 ) ];
		$old     = [ BackupName::forSite( 'http://site-a.test', 1577836800, 1 ), BackupName::forSite( 'http://site-a.test', 1577923200, 2 ) ];
		$plant   = '$d = \LightweightPlugins\Migration\Storage\Destinations\Destinations::fromWordPress(); $p = $d->find( ' . var_export( $id, true ) . ' );'
			. ' $p->retention = [ "count" => 1, "bytes" => 0, "days" => 0 ]; $d->profiles()->save( $p );'
			. ' $dest = $d->open( $p );'
			. ' foreach ( ' . var_export( array_merge( $foreign, $old ), true ) . ' as $n ) { $s = $dest->startUpload( $n, 3 ); $dest->finishUpload( $dest->uploadChunk( $s, "abc" ) ); }';
		self::inSite( $plant );
		$this->assertCount( 5, self::remote( 'a', $id ) );

		$name = self::export( 'a', '--to=' . $id )[0];

		$left = array_column( self::remote( 'a', $id ), 'name' );
		sort( $left );
		$expected = array_merge( $foreign, [ $name ] );
		sort( $expected );
		$this->assertSame( $expected, $left, 'Foreign, hand-copied and safety backups stay; this site keeps only the newest.' );
	}

	public function test_another_site_restores_from_the_cloud_and_keeps_its_own_destinations(): void {
		$id = self::addDestination( 'a', 'MinIO restore', 'restore' );
		WpSites::ok( 'a', 'option', 'update', 'blogname', 'Cloud Source' );
		$name = self::export( 'a', '--to=' . $id )[0];
		$onB  = self::addDestination( 'b', 'MinIO on b', 'restore' );

		[ $code, $out, $err ] = WpSites::wp( 'b', 'lw-migration', 'cloud', 'restore', $onB, $name, '--yes', '--no-safety-backup' );

		$this->assertSame( 0, $code, $out . $err );
		$this->assertSame( 'Cloud Source', trim( WpSites::ok( 'b', 'option', 'get', 'blogname' ) ) );
		$this->assertContains( $name, self::backups( 'b' ), 'The download stays in the backups list.' );
		$this->assertSame( self::localHash( 'a', $name ), self::localHash( 'b', $name ) );
		$list = WpSites::ok( 'b', 'lw-migration', 'destination', 'list', '--format=json' );
		$this->assertStringContainsString( 'MinIO on b', $list, 'Spec 21.3: the target keeps its own profiles.' );
		$this->assertStringNotContainsString( 'MinIO restore', $list, 'The source\'s profiles never arrive.' );
	}

	/**
	 * Download into the backups list (REST, ranged reads) and delete at the destination (CLI).
	 */
	public function test_a_backup_is_downloaded_back_and_deleted_at_the_destination(): void {
		$id   = self::addDestination( 'a', 'MinIO fetch', 'fetch' );
		$name = self::export( 'a', '--to=' . $id )[0];
		$onB  = self::addDestination( 'b', 'MinIO fetch on b', 'fetch' );
		$this->assertNotContains( $name, self::backups( 'b' ) );

		$client = new RestClient( 'b' );
		$rest   = new CheckedRest( $client, [ self::secret() ] );
		$client->start();
		try {
			$auth  = $client->login( 'admin' );
			$start = $rest->call( 'POST', '/destinations/' . $onB . '/files/fetch', $auth, [], [ 'name' => $name ] );
			$this->assertSame( 201, $start['status'], $start['body'] );
			$job = $rest->drive( $auth, (string) $start['json']['id'] );
		} finally {
			$client->stop();
		}

		$this->assertSame( 'done', $job['status'], (string) json_encode( $job ) );
		$this->assertContains( $name, self::backups( 'b' ) );
		$this->assertSame( self::localHash( 'a', $name ), self::localHash( 'b', $name ), 'The downloaded file is the uploaded one, byte for byte.' );

		[ $code, $out, $err ] = WpSites::wp( 'b', 'lw-migration', 'cloud', 'delete', $onB, $name, '--yes' );

		$this->assertSame( 0, $code, $out . $err );
		$this->assertNotContains( $name, array_column( self::remote( 'a', $id ), 'name' ) );
		$this->assertContains( $name, self::backups( 'b' ), 'Deleting at the destination leaves the local copy.' );
	}

	public function test_the_help_of_destination_add_names_no_secret_option(): void {
		$help = explode( 'GLOBAL PARAMETERS', WpSites::ok( 'a', 'help', 'lw-migration', 'destination', 'add' ) )[0]; // WP-CLI's own global flags follow.

		$this->assertStringContainsString( '--name=<name>', $help );
		$this->assertStringContainsString( 'Do not use the global --prompt here', $help );
		$this->assertStringNotContainsString( '--secret-key', $help );
		$this->assertStringNotContainsString( '[--prompt', $help, 'Only the warning names --prompt; it is no option of the command.' );

		[ $code, $out, $err ] = WpSites::wp( 'a', 'lw-migration', 'destination', 'add', 's3', '--name=Flag', '--preset=custom', '--endpoint=' . self::endpoint(), '--bucket=lwm-e2e', '--access-key=' . getenv( 'LWM_E2E_MINIO_USER' ), '--secret-key=' . self::secret() );

		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'Secrets are never options', $err );
		$this->assertStringNotContainsString( 'Flag', WpSites::ok( 'a', 'lw-migration', 'destination', 'list', '--format=csv' ) );
		$this->assertStringNotContainsString( self::secret(), $out . $err );
	}

	/**
	 * Review Focus 3.
	 */
	public function test_secrets_stay_out_of_rest_answers_jobs_and_the_archive(): void {
		$id     = self::addDestination( 'a', 'MinIO secret' );
		$client = new RestClient( 'a' );
		$rest   = new CheckedRest( $client, [ self::secret() ] );
		$client->start();
		try {
			$auth = $client->login( 'admin' );
			$this->assertSame( 200, $rest->call( 'GET', '/destinations', $auth )['status'] );
			$this->assertSame( 200, $rest->call( 'POST', '/destinations/' . $id . '/test', $auth )['status'] );
			$this->assertSame( 200, $rest->call( 'GET', '/destinations/' . $id . '/files', $auth )['status'] );
		} finally {
			$client->stop();
		}
		[ , $path ] = self::export( 'a', '--to=' . $id );

		foreach ( glob( WpSites::storage( 'a' ) . '/jobs/*/{job.json,job.log}', GLOB_BRACE ) ?: [] as $file ) {
			$this->assertStringNotContainsString( self::secret(), (string) file_get_contents( $file ), $file );
		}
		$reader = new Reader( $path );
		$entry  = $reader->find( 'database.sql' );
		$this->assertNotNull( $entry );
		$sql = $reader->readContents( $entry, 64 * 1048576 );
		$reader->close();
		$this->assertStringNotContainsString( 'lw_migration_destination', $sql );
		$this->assertStringNotContainsString( self::secret(), $sql );
	}
}
