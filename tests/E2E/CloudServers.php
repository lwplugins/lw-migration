<?php
/**
 * The test servers bin/test-cloud.sh starts, as destinations on a site.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\E2E;

use PHPUnit\Framework\Assert;

/**
 * Kinds: sftp (key + passphrase), sftp-password, sftp2 (another host key,
 * password), ftps (forced TLS), ftp (plain), ftp-appe (plain, REST refused:
 * parts are appended), nextcloud (WebDAV, chunked), webdav (the same
 * Nextcloud forced to one PUT). Every server is reached by a name under
 * .invalid that only the cloud probe resolves. Secrets go in on STDIN or as
 * a key file, never as flags.
 */
final class CloudServers {

	public const PROBE = 'lwm-e2e-cloud-probe.php';

	private const ENV = [
		'sftp'          => 'LWM_E2E_SFTP',
		'sftp-password' => 'LWM_E2E_SFTP',
		'sftp2'         => 'LWM_E2E_SFTP2',
		'ftps'          => 'LWM_E2E_FTPS',
		'ftp'           => 'LWM_E2E_FTP',
		'ftp-appe'      => 'LWM_E2E_FTP_APPE',
		'nextcloud'     => 'LWM_E2E_NEXTCLOUD',
		'webdav'        => 'LWM_E2E_NEXTCLOUD',
	];

	/** Container and the folder the destination's folder is relative to, per kind. */
	private const DISK = [
		'sftp'          => [ 'lwm-e2e-sftp', '/config' ],
		'sftp-password' => [ 'lwm-e2e-sftp', '/config' ],
		'sftp2'         => [ 'lwm-e2e-sftp2', '/config' ],
		'ftps'          => [ 'lwm-e2e-ftps', '/ftp/lwm' ],
		'ftp'           => [ 'lwm-e2e-ftp', '/ftp/lwm' ],
		'ftp-appe'      => [ 'lwm-e2e-ftp-appe', '/ftp/lwm' ],
		'nextcloud'     => [ 'lwm-e2e-nextcloud', '/var/www/html/data/lwm/files' ],
		'webdav'        => [ 'lwm-e2e-nextcloud', '/var/www/html/data/lwm/files' ],
	];

	public static function available( string $kind ): bool {
		return false !== getenv( self::ENV[ $kind ] );
	}

	/**
	 * Copies the cloud probe into both sites and makes the 12 MiB random file
	 * that turns every backup of site a into several parts.
	 */
	public static function prepare(): void {
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
			file_put_contents( $uploads . '/big.bin', random_bytes( 12 * 1048576 ) ); // Random: no compression shrinks it.
		}
		@unlink( WpSites::path( 'a' ) . '/wp-content/lwm-e2e-parts.log' ); // phpcs:ignore -- gone already is fine.
	}

	/**
	 * Removes the destinations of both sites (a site holds at most 20), the probe and its flag files.
	 */
	public static function tidy(): void {
		foreach ( [ 'a', 'b' ] as $site ) {
			[ , $out ] = WpSites::wp( $site, 'lw-migration', 'destination', 'list', '--format=json' );
			foreach ( array_column( (array) json_decode( $out, true ), 'id' ) as $id ) {
				WpSites::wp( $site, 'lw-migration', 'destination', 'remove', (string) $id, '--yes' );
			}
			$content = WpSites::path( $site ) . '/wp-content';
			foreach ( [ 'mu-plugins/' . self::PROBE, 'lwm-e2e-single-put-bytes', 'lwm-e2e-kill-after-cloud-part', 'lwm-e2e-kill-after-move', 'lwm-e2e-parts.log' ] as $file ) {
				@unlink( $content . '/' . $file ); // phpcs:ignore -- gone already is fine.
			}
		}
		@unlink( WpSites::path( 'a' ) . '/wp-content/uploads/2026/10/big.bin' ); // phpcs:ignore -- gone already is fine.
	}

	/**
	 * @return array<int, string> Texts no answer, log or archive may contain.
	 */
	public static function secrets(): array {
		$secrets = [ getenv( 'LWM_E2E_SFTP_SECRET' ), getenv( 'LWM_E2E_FTP_SECRET' ), getenv( 'LWM_E2E_NEXTCLOUD_SECRET' ) ];
		$key     = (string) getenv( 'LWM_E2E_SFTP_KEY' );
		if ( '' !== $key && is_file( $key ) ) {
			$lines     = file( $key, FILE_IGNORE_NEW_LINES );
			$secrets[] = is_array( $lines ) ? (string) ( $lines[1] ?? '' ) : ''; // A line of the key's body.
		}

		return array_values( array_filter( array_map( 'strval', $secrets ) ) );
	}

	/**
	 * Adds a destination of $kind on $site; an SFTP host key or the FTPS server's self-signed certificate is confirmed through `destination test`.
	 *
	 * @return string Profile id.
	 */
	public static function add( string $site, string $kind, string $name, string $folder ): string {
		$id = self::addUnchecked( $site, $kind, $name, $folder );
		if ( str_starts_with( $kind, 'sftp' ) || 'ftps' === $kind ) {
			self::trust( $site, $id );
		}

		return $id;
	}

	/**
	 * Adds a destination of $kind on $site without confirming anything.
	 *
	 * @param string             $site     Site.
	 * @param string             $kind     Kind.
	 * @param string             $name     Destination name.
	 * @param string             $folder   Folder at the server.
	 * @param array<int, string> $override Flags that replace the kind's own (same name), e.g. --security=ftps.
	 * @return string Profile id.
	 */
	public static function addUnchecked( string $site, string $kind, string $name, string $folder, array $override = [] ): string {
		[ $type, $flags, $stdin ] = self::flags( $kind, $folder );
		foreach ( $override as $flag ) {
			$key   = strstr( $flag, '=', true );
			$flags = array_values( array_filter( $flags, static fn( string $f ): bool => strstr( $f, '=', true ) !== $key ) );
			$flags[] = $flag;
		}
		[ $code, $out, $err ] = WpSites::wpInput( $site, $stdin, 'lw-migration', 'destination', 'add', $type, '--name=' . $name, ...$flags );
		Assert::assertSame( 0, $code, $out . $err );
		Assert::assertSame( 1, preg_match( '/with ID ([a-f0-9]{8})/', $out, $m ), $out );
		foreach ( self::secrets() as $secret ) {
			Assert::assertStringNotContainsString( $secret, $out . $err );
		}

		return $m[1];
	}

	/**
	 * Runs `destination test` (it stops at the unknown host key or certificate and names it), then trusts it.
	 *
	 * @return string The fingerprint.
	 */
	public static function trust( string $site, string $id ): string {
		[ $code, $out, $err ] = WpSites::wp( $site, 'lw-migration', 'destination', 'test', $id );
		Assert::assertNotSame( 0, $code, 'Not trusted yet: ' . $out );
		Assert::assertSame( 1, preg_match( '#--(trust-host-key)=(SHA256:[A-Za-z0-9+/]{43})|--(trust-certificate)=((?:[0-9A-F]{2}:){31}[0-9A-F]{2})#', $out . $err, $m ), $out . $err );
		$flag  = '' !== $m[1] ? $m[1] : $m[3];
		$print = '' !== $m[1] ? $m[2] : $m[4];
		WpSites::ok( $site, 'lw-migration', 'destination', 'test', $id, '--' . $flag . '=' . $print );

		return $print;
	}

	/**
	 * Ticks a job through REST until it ends; a tick the probe killed counts as a death.
	 *
	 * @param RestClient           $client     Started client.
	 * @param array<string, mixed> $auth       Login.
	 * @param string               $job        Job id.
	 * @param \Closure|null        $afterDeath fn( string $jobId ): void, run once after the first death.
	 * @return array{0: array<string, mixed>, 1: int} The last tick's job view, deaths.
	 */
	public static function tickToEnd( RestClient $client, array $auth, string $job, ?\Closure $afterDeath = null ): array {
		$died = 0;
		$last = [];
		for ( $i = 0; $i < 300; $i++ ) {
			$tick = $client->call( 'POST', '/jobs/' . $job . '/tick', $auth );
			if ( 200 !== $tick['status'] || ! is_array( $tick['json'] ) ) {
				++$died;
				if ( 1 === $died && null !== $afterDeath ) {
					$afterDeath( $job );
				}
				continue;
			}
			$last = $tick['json'];
			if ( in_array( $last['status'], [ 'done', 'failed' ], true ) ) {
				break;
			}
		}

		return [ $last, $died ];
	}

	/**
	 * Starts an upload of a backup of site a to one destination over REST and ticks it to its end.
	 *
	 * @param string        $name       Backup name.
	 * @param string        $id         Destination id.
	 * @param \Closure|null $afterDeath See tickToEnd().
	 * @return array{0: array<string, mixed>, 1: int} The last tick's job view, deaths.
	 */
	public static function uploadOverRest( string $name, string $id, ?\Closure $afterDeath = null ): array {
		$client = new RestClient( 'a' );
		$client->start();
		try {
			$auth  = $client->login( 'admin' );
			$start = $client->call( 'POST', '/backups/upload', $auth, [], [ 'folder' => 'own', 'name' => $name, 'destinations' => [ $id ] ] );
			Assert::assertSame( 201, $start['status'], $start['body'] );

			return self::tickToEnd( $client, $auth, (string) $start['json']['id'], $afterDeath );
		} finally {
			$client->stop();
		}
	}

	/**
	 * Size and SHA-1 of a file as the server's own disk holds it ('' when it is not there).
	 */
	public static function onDisk( string $kind, string $folder, string $file ): string {
		[ $container, $root ] = self::DISK[ $kind ];
		$path                 = $root . '/' . $folder . '/' . $file;
		[ $code, $out ]       = self::docker( 'exec', $container, 'sh', '-c', 'test -f "$1" && echo "$(wc -c < "$1" | tr -d " ") $(sha1sum "$1" | cut -d " " -f 1)"', 'sh', $path );

		return 0 === $code ? trim( $out ) : '';
	}

	/**
	 * Size and SHA-1 of a local file.
	 */
	public static function local( string $path ): string {
		return filesize( $path ) . ' ' . sha1_file( $path );
	}

	/**
	 * The names in a folder on the server's disk.
	 *
	 * @return array<int, string>
	 */
	public static function filesOnDisk( string $kind, string $folder ): array {
		[ $container, $root ] = self::DISK[ $kind ];
		[ , $out ]            = self::docker( 'exec', $container, 'ls', '-1A', $root . '/' . $folder );

		return array_values( array_filter( explode( "\n", trim( $out ) ) ) );
	}

	/**
	 * Runs a docker command (never for more than two minutes).
	 *
	 * @return array{0: int, 1: string, 2: string} Exit code, output, errors.
	 */
	public static function docker( string ...$args ): array {
		$proc = proc_open( array_merge( [ 'timeout', '120', 'docker' ], $args ), [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
		if ( ! is_resource( $proc ) ) {
			return [ 1, '', 'docker did not start' ];
		}
		$out = (string) stream_get_contents( $pipes[1] );
		$err = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		return [ proc_close( $proc ), $out, $err ];
	}

	/**
	 * Host and port of a kind's server (the .invalid name).
	 *
	 * @return array{0: string, 1: string}
	 */
	public static function address( string $kind ): array {
		$value = (string) getenv( self::ENV[ $kind ] );
		if ( 'nextcloud' === $kind || 'webdav' === $kind ) {
			return [ (string) parse_url( $value, PHP_URL_HOST ), (string) parse_url( $value, PHP_URL_PORT ) ];
		}
		$pair = array_pad( explode( ':', $value ), 2, '' );

		return [ $pair[0], $pair[1] ];
	}

	/**
	 * @return array{0: string, 1: array<int, string>, 2: string} Type, flags, STDIN.
	 */
	private static function flags( string $kind, string $folder ): array {
		[ $host, $port ] = self::address( $kind );
		switch ( $kind ) {
			case 'sftp':
				return [ 'sftp', [ '--host=' . $host, '--port=' . $port, '--username=' . getenv( 'LWM_E2E_SFTP_USER' ), '--auth=key', '--private-key-file=' . getenv( 'LWM_E2E_SFTP_KEY' ), '--folder=' . $folder ], getenv( 'LWM_E2E_SFTP_SECRET' ) . "\n" ];
			case 'sftp-password':
			case 'sftp2':
				return [ 'sftp', [ '--host=' . $host, '--port=' . $port, '--username=' . getenv( 'LWM_E2E_SFTP_USER' ), '--auth=password', '--folder=' . $folder ], getenv( 'LWM_E2E_SFTP_SECRET' ) . "\n" ];
			case 'ftps':
			case 'ftp':
			case 'ftp-appe':
				return [ 'ftp', [ '--host=' . $host, '--port=' . $port, '--security=' . ( 'ftps' === $kind ? 'ftps' : 'ftp' ), '--username=' . getenv( 'LWM_E2E_FTP_USER' ), '--folder=' . $folder ], getenv( 'LWM_E2E_FTP_SECRET' ) . "\n" ];
		}

		return [ 'webdav', [ '--address=' . getenv( 'LWM_E2E_NEXTCLOUD' ), '--folder=' . $folder, '--mode=' . ( 'nextcloud' === $kind ? 'auto' : 'webdav' ), '--username=' . getenv( 'LWM_E2E_NEXTCLOUD_USER' ) ], getenv( 'LWM_E2E_NEXTCLOUD_SECRET' ) . "\n" ];
	}
}
