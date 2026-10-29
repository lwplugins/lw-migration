<?php
/**
 * Every new destination type against a real server (spec 21.9).
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\E2E;

use LightweightPlugins\Migration\Backups\BackupName;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Run by bin/test-cloud.sh. A 12 MiB random file in site a's uploads makes
 * every backup several FTP/SFTP parts (they start at 1 MiB) and at least
 * two Nextcloud chunks (10 MiB). Every server is reached by a name only the
 * cloud probe resolves, so a working destination proves that it connected
 * to the address the plugin checked. What arrived is compared with the
 * backup on the server's own disk.
 */
final class CloudProtocolsE2ETest extends E2ETestCase {

	private bool $prepared = false;

	protected function setUp(): void {
		parent::setUp();
		foreach ( [ 'sftp', 'ftp', 'nextcloud' ] as $kind ) {
			if ( CloudServers::available( $kind ) ) {
				CloudServers::prepare();
				$this->prepared = true;
				return;
			}
		}
		$this->markTestSkipped( 'Run bin/test-cloud.sh to start the SFTP, FTP or Nextcloud servers.' );
	}

	protected function tearDown(): void {
		if ( $this->prepared ) {
			CloudServers::tidy();
		}
		parent::tearDown();
	}

	/**
	 * Types that upload, list, restore and delete.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function kinds(): array {
		return [
			'SFTP with a key and passphrase' => [ 'sftp' ],
			'SFTP with a password'           => [ 'sftp-password' ],
			'FTPS'                           => [ 'ftps' ],
			'plain FTP'                      => [ 'ftp' ],
			'Nextcloud'                      => [ 'nextcloud' ],
		];
	}

	/**
	 * Types that upload in parts, with the FTP server that refuses REST (parts are appended).
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function uploadKinds(): array {
		return self::kinds() + [ 'plain FTP without REST (APPE)' => [ 'ftp-appe' ] ];
	}

	private static function need( string $kind ): void {
		if ( ! CloudServers::available( $kind ) ) {
			self::markTestSkipped( 'Run bin/test-cloud.sh to start the ' . $kind . ' server.' );
		}
	}

	/**
	 * @return array<string, array{name: string, size: string, ours: string}> The size is for people (e.g. "28 MB").
	 */
	private static function remote( string $site, string $id ): array {
		return array_column( (array) json_decode( WpSites::ok( $site, 'lw-migration', 'cloud', 'list', $id, '--format=json' ), true ), null, 'name' );
	}

	/**
	 * @return array<int, int> Offsets announced for a destination (cloud-probe.php's log).
	 */
	private static function parts( string $id ): array {
		$log = (string) @file_get_contents( WpSites::path( 'a' ) . '/wp-content/lwm-e2e-parts.log' ); // phpcs:ignore -- no log yet is an empty one.
		preg_match_all( '/^' . $id . ' (\d+)$/m', $log, $m );

		return array_map( 'intval', $m[1] );
	}

	#[DataProvider( 'kinds' )]
	public function test_a_backup_goes_up_in_parts_and_another_site_restores_and_deletes_it( string $kind ): void {
		self::need( $kind );
		$folder = 'm7b-' . $kind . '-' . bin2hex( random_bytes( 3 ) );
		$id     = CloudServers::add( 'a', $kind, 'Up ' . $kind, $folder );
		WpSites::ok( 'a', 'option', 'update', 'blogname', 'Cloud ' . $kind );

		[ $name, $path ] = self::export( 'a', '--to=' . $id );

		$this->assertGreaterThanOrEqual( 2, count( self::parts( $id ) ), 'More than one part.' );
		$this->assertSame( CloudServers::local( $path ), CloudServers::onDisk( $kind, $folder, $name ), 'The server holds the backup, byte for byte.' );
		$this->assertSame( [ $name ], CloudServers::filesOnDisk( $kind, $folder ), 'No part file or test file is left.' );
		$listed = self::remote( 'a', $id );
		$this->assertSame( 'yes', $listed[ $name ]['ours'] ?? '', 'Listed as this site\'s backup.' );

		$onB                  = CloudServers::add( 'b', $kind, 'Down ' . $kind, $folder );
		[ $code, $out, $err ] = WpSites::wp( 'b', 'lw-migration', 'cloud', 'restore', $onB, $name, '--yes', '--no-safety-backup' );
		$this->assertSame( 0, $code, $out . $err );
		$this->assertSame( 'Cloud ' . $kind, trim( WpSites::ok( 'b', 'option', 'get', 'blogname' ) ) );

		[ $code, $out, $err ] = WpSites::wp( 'b', 'lw-migration', 'cloud', 'delete', $onB, $name, '--yes' );
		$this->assertSame( 0, $code, $out . $err );
		$this->assertSame( '', CloudServers::onDisk( $kind, $folder, $name ), 'Deleted at the server.' );
		$this->assertArrayNotHasKey( $name, self::remote( 'a', $id ) );
	}

	#[DataProvider( 'uploadKinds' )]
	public function test_a_killed_upload_resumes_in_the_next_tick( string $kind ): void {
		self::need( $kind );
		$folder          = 'm7b-kill-' . $kind . '-' . bin2hex( random_bytes( 3 ) );
		$id              = CloudServers::add( 'a', $kind, 'Kill ' . $kind, $folder );
		[ $name, $path ] = self::export( 'a' );
		file_put_contents( WpSites::path( 'a' ) . '/wp-content/lwm-e2e-kill-after-cloud-part', $id );

		[ $last, $died ] = CloudServers::uploadOverRest( $name, $id );

		$this->assertSame( 1, $died, 'Exactly one tick died after its first part.' );
		$this->assertSame( 'done', $last['status'], (string) json_encode( $last ) );
		$this->assertSame( 'ok', $last['result']['cloud'][0]['status'], (string) json_encode( $last ) );
		$this->assertSame( CloudServers::local( $path ), CloudServers::onDisk( $kind, $folder, $name ), 'The resumed file is the backup, byte for byte.' );
		$this->assertSame( [ $name ], CloudServers::filesOnDisk( $kind, $folder ), 'No part file is left.' );
		if ( 'ftp-appe' === $kind ) {
			[ , $log ] = CloudServers::docker( 'logs', 'lwm-e2e-ftp-appe' );
			$this->assertStringContainsString( '"APPE ' . $name . '.part"', $log, 'The later parts were appended.' );
			$this->assertStringContainsString( '"REST ', $log, 'A part at an offset was tried first, and refused.' );
		}
	}

	#[DataProvider( 'uploadKinds' )]
	public function test_retention_prunes_only_this_sites_old_backups( string $kind ): void {
		self::need( $kind );
		$id      = CloudServers::add( 'a', $kind, 'Keep ' . $kind, 'm7b-keep-' . $kind . '-' . bin2hex( random_bytes( 3 ) ) );
		$foreign = [ 'other-site-test-20200101-000000-001.wpress', 'by-hand.wpress', BackupName::safety( 1577836800 ) ];
		$old     = [ BackupName::forSite( 'http://site-a.test', 1577836800, 1 ), BackupName::forSite( 'http://site-a.test', 1577923200, 2 ) ];
		WpSites::ok(
			'a',
			'eval',
			'$d = \LightweightPlugins\Migration\Storage\Destinations\Destinations::fromWordPress(); $p = $d->find( ' . var_export( $id, true ) . ' );'
			. ' $p->retention = [ "count" => 1, "bytes" => 0, "days" => 0 ]; $d->profiles()->save( $p ); $dest = $d->open( $p );'
			. ' foreach ( ' . var_export( array_merge( $foreign, $old ), true ) . ' as $n ) { $s = $dest->startUpload( $n, 3 ); $dest->finishUpload( $dest->uploadChunk( $s, "abc" ) ); }'
		);
		$this->assertCount( 5, self::remote( 'a', $id ) );

		$name = self::export( 'a', '--to=' . $id )[0];

		$left = array_keys( self::remote( 'a', $id ) );
		sort( $left );
		$expected = array_merge( $foreign, [ $name ] );
		sort( $expected );
		$this->assertSame( $expected, $left, 'Foreign, hand-copied and safety backups stay; this site keeps only the newest.' );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function davKinds(): array {
		return [
			'Nextcloud (MOVE .file)'    => [ 'nextcloud' ],
			'one-request WebDAV (MOVE)' => [ 'webdav' ],
		];
	}

	/**
	 * Final review I2: an imported backup keeps its owner's name, so another
	 * file may hold it — the MOVE (Overwrite: F) must refuse, not replace it.
	 */
	#[DataProvider( 'davKinds' )]
	public function test_a_webdav_upload_never_replaces_another_file_under_its_name( string $kind ): void {
		self::need( $kind );
		$folder   = 'm7b-taken-' . $kind . '-' . bin2hex( random_bytes( 3 ) );
		$id       = CloudServers::add( 'a', $kind, 'Taken ' . $kind, $folder );
		[ $name ] = self::export( 'a' );
		$other    = 'another file under this name';
		WpSites::ok(
			'a',
			'eval',
			'$d = \\LightweightPlugins\\Migration\\Storage\\Destinations\\Destinations::fromWordPress(); $dest = $d->open( $d->find( ' . var_export( $id, true ) . ' ) );'
			. ' $s = $dest->startUpload( ' . var_export( $name, true ) . ', ' . strlen( $other ) . ' ); $dest->finishUpload( $dest->uploadChunk( $s, ' . var_export( $other, true ) . ' ) );'
		);
		$before = CloudServers::onDisk( $kind, $folder, $name );
		$this->assertSame( strlen( $other ) . ' ' . sha1( $other ), $before, 'The other file is in place.' );

		[ $last ] = CloudServers::uploadOverRest( $name, $id );

		$this->assertSame( 'failed', $last['result']['cloud'][0]['status'] ?? '', (string) json_encode( $last ) );
		$this->assertStringContainsString( 'it was not replaced', (string) $last['result']['cloud'][0]['message'] );
		$this->assertSame( $before, CloudServers::onDisk( $kind, $folder, $name ), 'The other file is intact.' );
	}
}
