<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Rest;

use LightweightPlugins\Migration\Rest\DestinationInput;
use LightweightPlugins\Migration\Rest\RestException;
use LightweightPlugins\Migration\Storage\Destinations\DestinationRegistry;
use LightweightPlugins\Migration\Storage\Destinations\DestinationType;
use LightweightPlugins\Migration\Storage\Destinations\Ftp\FtpType;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Storage\Destinations\S3\S3Type;
use LightweightPlugins\Migration\Storage\Destinations\Sftp\SftpType;
use LightweightPlugins\Migration\Storage\Destinations\WebDav\WebDavType;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

/**
 * A stored secret belongs to the server it was entered for (issue #3): an
 * edit that moves the server forgets it and asks for it again, an edit that
 * does not move it keeps it.
 */
final class DestinationAuthorityTest extends MonkeyTestCase {

	/**
	 * @return array<string, array{0: DestinationType, 1: array<string, mixed>, 2: array<string, mixed>, 3: array<int, string>, 4: string}> type, stored settings, moved settings, stored secrets, secret field asked for.
	 */
	public static function moves(): array {
		$ftp  = [ 'host' => 'ftp.example.com', 'port' => 21, 'security' => 'ftps', 'user' => 'u' ];
		$sftp = [ 'host' => 'sftp.example.com', 'port' => 22, 'user' => 'u', 'auth' => 'password' ];
		$dav  = [ 'url' => 'https://cloud.example.com/remote.php/dav/files/u/', 'mode' => 'auto', 'user' => 'u' ];
		$s3   = [ 'preset' => 'custom', 'endpoint' => 'https://s3.example.com', 'bucket' => 'backup-bkt', 'accessKey' => 'minioadmin' ];

		return [
			'ftp host'        => [ new FtpType( null, static fn(): bool => true ), $ftp, [ 'host' => 'attacker.example' ] + $ftp, [ 'password' ], 'password' ],
			'ftp port'        => [ new FtpType( null, static fn(): bool => true ), $ftp, [ 'port' => 2121 ] + $ftp, [ 'password' ], 'password' ],
			'ftp security'    => [ new FtpType( null, static fn(): bool => true ), $ftp, [ 'security' => 'ftp' ] + $ftp, [ 'password' ], 'password' ],
			'sftp host'       => [ new SftpType(), $sftp, [ 'host' => 'attacker.example' ] + $sftp, [ 'password' ], 'password' ],
			'sftp port'       => [ new SftpType(), $sftp, [ 'port' => 2222 ] + $sftp, [ 'password' ], 'password' ],
			'webdav host'     => [ new WebDavType(), $dav, [ 'url' => 'https://attacker.example/remote.php/dav/files/u/' ] + $dav, [ 'password' ], 'password' ],
			'webdav scheme'   => [ new WebDavType(), $dav, [ 'url' => 'http://cloud.example.com/remote.php/dav/files/u/' ] + $dav, [ 'password' ], 'password' ],
			'webdav port'     => [ new WebDavType(), $dav, [ 'url' => 'https://cloud.example.com:8443/remote.php/dav/files/u/' ] + $dav, [ 'password' ], 'password' ],
			's3 endpoint'     => [ new S3Type(), $s3, [ 'endpoint' => 'https://s3.attacker.example' ] + $s3, [ 'secretKey' ], 'secretKey' ],
			's3 preset'       => [ new S3Type(), $s3, [ 'preset' => 'aws', 'region' => 'us-east-1' ] + $s3, [ 'secretKey' ], 'secretKey' ],
		];
	}

	/**
	 * @dataProvider moves
	 *
	 * @param DestinationType      $type    Type.
	 * @param array<string, mixed> $stored  Stored settings.
	 * @param array<string, mixed> $moved   Settings of the edit.
	 * @param array<int, string>   $vault   Secret fields in the vault.
	 * @param string               $field   The secret the edit must send again.
	 */
	public function test_a_moved_server_forgets_the_stored_secret_and_asks_for_it_again( DestinationType $type, array $stored, array $moved, array $vault, string $field ): void {
		$registry = new DestinationRegistry( [ $type ] );
		$current  = new Profile( 'abcd1234', $type->key(), 'D', $stored );

		try {
			DestinationInput::apply( $current, $registry, [ 'settings' => $moved ], $vault );
			$this->fail( 'The secret is required again.' );
		} catch ( RestException $e ) {
			$this->assertSame( $field, $e->data['field'] ?? '' );
		}
		$out = DestinationInput::apply( $current, $registry, [ 'settings' => $moved, 'secrets' => [ $field => 'entered-again' ] ], $vault );
		$this->assertSame( [ $field => 'entered-again' ], $out['secrets'] );
		$this->assertSame( [], $out['forget'], 'The new value replaces the old one in the vault.' );
	}

	/**
	 * @dataProvider moves
	 *
	 * @param DestinationType      $type   Type.
	 * @param array<string, mixed> $stored Stored settings.
	 * @param array<string, mixed> $moved  Settings of the edit.
	 * @param array<int, string>   $vault  Secret fields in the vault.
	 */
	public function test_an_edit_that_leaves_the_server_alone_keeps_the_stored_secret( DestinationType $type, array $stored, array $moved, array $vault ): void {
		$current = new Profile( 'abcd1234', $type->key(), 'D', $stored );
		$same    = DestinationInput::apply( $current, new DestinationRegistry( [ $type ] ), [ 'name' => 'Renamed', 'settings' => $stored + [ 'folder' => '' ] ], $vault );

		$this->assertSame( [ [], [] ], [ $same['secrets'], $same['forget'] ], 'The same server keeps the stored secret, and nothing is forgotten.' );
		$this->assertSame( 'Renamed', $same['profile']->name );
	}

	public function test_the_secrets_not_sent_are_dropped_from_the_vault(): void {
		$type    = new SftpType();
		$current = new Profile( 'abcd1234', 'sftp', 'D', [ 'host' => 'sftp.example.com', 'port' => 22, 'user' => 'u', 'auth' => 'key' ] );
		$out     = DestinationInput::apply(
			$current,
			new DestinationRegistry( [ $type ] ),
			[ 'settings' => [ 'host' => 'attacker.example', 'port' => 22, 'user' => 'u', 'auth' => 'key' ], 'secrets' => [ 'privateKey' => "-----BEGIN KEY-----\nabc\n-----END KEY-----" ] ],
			[ 'privateKey', 'passphrase', 'password' ]
		);

		$this->assertSame( [ 'passphrase', 'password' ], $out['forget'], 'The old passphrase and password go; the key just sent replaces the old one.' );
	}

	public function test_a_rename_or_a_folder_change_keeps_the_secret(): void {
		$type    = new WebDavType();
		$current = new Profile( 'abcd1234', 'webdav', 'D', [ 'url' => 'https://cloud.example.com/remote.php/dav/files/u/', 'mode' => 'auto', 'user' => 'u' ] );
		$out     = DestinationInput::apply(
			$current,
			new DestinationRegistry( [ $type ] ),
			[ 'settings' => [ 'url' => 'HTTPS://Cloud.Example.com:443/other/path/', 'mode' => 'auto', 'user' => 'u', 'folder' => 'Backups' ] ],
			[ 'password' ]
		);

		$this->assertSame( [ [], [] ], [ $out['secrets'], $out['forget'] ], 'Same scheme, host and port: the path is free to change.' );
	}

	/** Final review M9: like a new SFTP host key, a certificate fingerprint sent with the move is the new server's. */
	public function test_a_moved_ftps_server_keeps_only_a_new_certificate_fingerprint_sent_with_the_move(): void {
		$old      = implode( ':', array_fill( 0, 32, 'AA' ) );
		$new      = implode( ':', array_fill( 0, 32, 'BB' ) );
		$stored   = [ 'host' => 'ftp.example.com', 'port' => 21, 'security' => 'ftps', 'user' => 'u', 'certFingerprint' => $old ];
		$registry = new DestinationRegistry( [ new FtpType( null, static fn(): bool => true ) ] );
		$current  = new Profile( 'abcd1234', 'ftp', 'D', $stored );
		$secrets  = [ 'secrets' => [ 'password' => 'entered-again' ] ];

		$typed = DestinationInput::apply( $current, $registry, [ 'settings' => [ 'host' => 'ftp2.example.com', 'certFingerprint' => strtolower( $new ) ] + $stored ] + $secrets, [ 'password' ] );
		$same  = DestinationInput::apply( $current, $registry, [ 'settings' => [ 'host' => 'ftp2.example.com' ] + $stored ] + $secrets, [ 'password' ] );

		$this->assertSame( $new, $typed['profile']->settings['certFingerprint'] ?? null, 'The new server\'s fingerprint, typed with the move, stays.' );
		$this->assertArrayNotHasKey( 'certFingerprint', $same['profile']->settings, 'The old server\'s fingerprint goes.' );
	}

	public function test_a_new_destination_forgets_nothing(): void {
		$out = DestinationInput::apply( null, new DestinationRegistry( [ new S3Type() ] ), [ 'type' => 's3', 'name' => 'N', 'settings' => [ 'preset' => 'aws', 'region' => 'us-east-1', 'bucket' => 'backup-bkt', 'accessKey' => 'minioadmin' ], 'secrets' => [ 'secretKey' => 's' ] ] );

		$this->assertSame( [], $out['forget'] );
	}
}
