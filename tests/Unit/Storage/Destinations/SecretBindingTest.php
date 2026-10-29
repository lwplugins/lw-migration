<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Storage\Destinations;

use LightweightPlugins\Migration\Storage\Destinations\AuthorityBound;
use LightweightPlugins\Migration\Storage\Destinations\DestinationType;
use LightweightPlugins\Migration\Storage\Destinations\Ftp\FtpType;
use LightweightPlugins\Migration\Storage\Destinations\S3\S3Type;
use LightweightPlugins\Migration\Storage\Destinations\SecretBinding;
use LightweightPlugins\Migration\Storage\Destinations\Sftp\SftpType;
use LightweightPlugins\Migration\Storage\Destinations\WebDav\WebDavType;
use LightweightPlugins\Migration\Tests\Support\FakeKeyedType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecretBindingTest extends TestCase {

	/**
	 * @return array<string, array{0: DestinationType, 1: array<string, mixed>, 2: array<string, mixed>, 3: bool}>
	 */
	public static function edits(): array {
		$ftp    = [ 'host' => 'ftp.example', 'port' => 21, 'security' => 'ftps', 'user' => 'u', 'folder' => 'a' ];
		$sftp   = [ 'host' => 'sftp.example', 'port' => 22, 'user' => 'u', 'folder' => 'a' ];
		$webdav = [ 'url' => 'https://dav.example/files/', 'user' => 'u', 'folder' => 'a' ];
		$s3     = [ 'preset' => 'custom', 'endpoint' => 'https://s3.example', 'bucket' => 'b', 'accessKey' => 'AKIA0000' ];

		return [
			'ftp: folder and user'     => [ new FtpType(), $ftp, [ 'user' => 'v', 'folder' => 'b' ] + $ftp, true ],
			'ftp: host spelling'       => [ new FtpType(), $ftp, [ 'host' => ' FTP.example ' ] + $ftp, true ],
			'ftp: another host'        => [ new FtpType(), $ftp, [ 'host' => 'ftp.other' ] + $ftp, false ],
			'ftp: another port'        => [ new FtpType(), $ftp, [ 'port' => 2121 ] + $ftp, false ],
			'ftp: another security'    => [ new FtpType(), $ftp, [ 'security' => 'ftp' ] + $ftp, false ],
			'sftp: folder and user'    => [ new SftpType(), $sftp, [ 'user' => 'v', 'folder' => 'b' ] + $sftp, true ],
			'sftp: another host'       => [ new SftpType(), $sftp, [ 'host' => 'sftp.other' ] + $sftp, false ],
			'sftp: another port'       => [ new SftpType(), $sftp, [ 'port' => 2222 ] + $sftp, false ],
			'webdav: path and case'    => [ new WebDavType(), $webdav, [ 'url' => 'https://DAV.example:443/other/' ] + $webdav, true ],
			'webdav: another host'     => [ new WebDavType(), $webdav, [ 'url' => 'https://dav.other/files/' ] + $webdav, false ],
			'webdav: another scheme'   => [ new WebDavType(), $webdav, [ 'url' => 'http://dav.example/files/' ] + $webdav, false ],
			'webdav: another port'     => [ new WebDavType(), $webdav, [ 'url' => 'https://dav.example:8443/files/' ] + $webdav, false ],
			's3: bucket and access'    => [ new S3Type(), $s3, [ 'bucket' => 'c', 'accessKey' => 'AKIA1111' ] + $s3, true ],
			's3: endpoint slash, case' => [ new S3Type(), $s3, [ 'endpoint' => 'https://S3.example/' ] + $s3, true ],
			's3: another endpoint'     => [ new S3Type(), $s3, [ 'endpoint' => 'https://s3.other' ] + $s3, false ],
			's3: another provider'     => [ new S3Type(), $s3, [ 'preset' => 'aws' ] + $s3, false ],
		];
	}

	/**
	 * @param DestinationType      $type   Type.
	 * @param array<string, mixed> $before Stored settings.
	 * @param array<string, mixed> $after  Edited settings.
	 * @param bool                 $same   Whether the edit keeps the server.
	 */
	#[DataProvider( 'edits' )]
	public function test_the_binding_changes_exactly_when_the_server_does( DestinationType $type, array $before, array $after, bool $same ): void {
		$this->assertSame( $same, SecretBinding::of( $type, $before ) === SecretBinding::of( $type, $after ) );
	}

	/**
	 * @param DestinationType      $type   Type.
	 * @param array<string, mixed> $before Stored settings.
	 * @param array<string, mixed> $after  Edited settings.
	 */
	#[DataProvider( 'edits' )]
	public function test_the_binding_agrees_with_the_types_own_server_check( DestinationType $type, array $before, array $after ): void {
		$this->assertInstanceOf( AuthorityBound::class, $type );
		$this->assertSame( $type->authority( $before ) === $type->authority( $after ), SecretBinding::of( $type, $before ) === SecretBinding::of( $type, $after ) );
	}

	public function test_a_binding_names_the_type_too(): void {
		$settings = [ 'host' => 'box.example', 'port' => 22 ];

		$this->assertNotSame( SecretBinding::of( new SftpType(), $settings ), SecretBinding::of( new FtpType(), $settings + [ 'security' => 'ftps' ] ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}\z/', (string) SecretBinding::of( new SftpType(), $settings ) );
	}

	public function test_a_type_without_a_server_of_its_own_has_no_binding(): void {
		$this->assertNull( SecretBinding::of( new FakeKeyedType(), [ 'host' => 'example.com' ] ) );
	}
}
