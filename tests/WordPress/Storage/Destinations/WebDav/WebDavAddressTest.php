<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\WebDav;

use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Destinations\FieldException;
use LightweightPlugins\Migration\Storage\Destinations\WebDav\WebDavAddress;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class WebDavAddressTest extends MonkeyTestCase {

	protected function setUp(): void {
		parent::setUp();
		AddressGuard::useResolver( static fn( string $host ): array => 'metadata.google.internal' === $host ? [ '169.254.169.254' ] : [ '93.184.216.34' ] );
	}

	protected function tearDown(): void {
		AddressGuard::useResolver( null );
		parent::tearDown();
	}

	public function test_a_nextcloud_files_address_gives_the_server_and_the_user(): void {
		$a = WebDavAddress::fromSettings( 'https://Cloud.Example/remote.php/dav/files/alice/', 'Backups/site' );

		$this->assertTrue( $a->isNextcloud() );
		$this->assertSame( 'https://cloud.example/remote.php/dav/files/alice/Backups/site/', $a->folderUrl() );
		$this->assertSame( 'https://cloud.example/remote.php/dav/files/alice/Backups/site/a%20b.wpress', $a->file( 'a b.wpress' ) );
		$this->assertSame( [ 'https://cloud.example/remote.php/dav/files/alice/Backups/', 'https://cloud.example/remote.php/dav/files/alice/Backups/site/' ], $a->folderChain() );
		$this->assertSame( 'https://cloud.example/remote.php/dav/uploads/alice/lw-migration-0123456789abcdef', $a->uploadsUrl( 'lw-migration-0123456789abcdef' ) );
		$this->assertSame( 'https://cloud.example/status.php', $a->statusUrl() );
		$this->assertSame( 'https://cloud.example', $a->display() );
		$this->assertFalse( $a->insecure() );
	}

	public function test_a_nextcloud_in_a_sub_folder_with_an_e_mail_user(): void {
		$a = WebDavAddress::fromSettings( 'https://example.com/nextcloud/remote.php/dav/files/bob%40mail.example', '' );

		$this->assertSame( 'https://example.com/nextcloud', $a->server );
		$this->assertSame( 'bob@mail.example', $a->user );
		$this->assertSame( 'https://example.com/nextcloud/remote.php/dav/uploads/bob%40mail.example/x', $a->uploadsUrl( 'x' ) );
		$this->assertSame( [], $a->folderChain() );
	}

	public function test_any_other_webdav_address_is_plain_and_http_is_flagged(): void {
		$a = WebDavAddress::fromSettings( 'http://dav.example:8080/webdav', 'site' );

		$this->assertFalse( $a->isNextcloud() );
		$this->assertTrue( $a->insecure() );
		$this->assertSame( 'http://dav.example:8080/webdav/site/', $a->folderUrl() );
		$this->assertSame( 'http://dav.example:8080', $a->display() );
	}

	public function test_refuses_what_is_not_a_plain_http_address(): void {
		foreach ( [ '', 'cloud.example/dav', 'ftp://cloud.example/', 'https://user:pw@cloud.example/', 'https://cloud.example/dav?x=1', 'https://cloud.example/dav#a', 'https://cloud.example/dav?' ] as $bad ) {
			try {
				WebDavAddress::fromSettings( $bad, '' );
				$this->fail( $bad );
			} catch ( FieldException $e ) {
				$this->assertSame( 'url', $e->field );
			}
		}
	}

	/**
	 * Preflight P-I13 (H5): the save-time rules of every address an admin types.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function hardened(): array {
		return [
			'CR/LF in the path'           => [ "https://cloud.example/da\r\nv/" ],
			'encoded CR/LF in the path'   => [ 'https://cloud.example/dav%0d%0aX-Injected:%20y/' ],
			'a tab'                       => [ "https://cloud.example/da\tv/" ],
			'a space'                     => [ 'https://cloud.example/my dav/' ],
			'decimal address'             => [ 'http://2130706433/' ],
			'hex address'                 => [ 'http://0x7f.0.0.1/' ],
			'metadata address'            => [ 'http://169.254.169.254/' ],
			'name of the metadata server' => [ 'http://metadata.google.internal/dav/' ],
			'port 0'                      => [ 'https://cloud.example:0/dav/' ],
			'port over 65535'             => [ 'https://cloud.example:70000/dav/' ],
			'over 2048 characters'        => [ 'https://cloud.example/' . str_repeat( 'a', 2030 ) ],
			'parent segment'              => [ 'https://cloud.example/dav/../admin/' ],
			'encoded parent segment'      => [ 'https://cloud.example/dav/%2e%2E/admin/' ],
			'dot segment'                 => [ 'https://cloud.example/./dav/' ],
			'encoded slash in a segment'  => [ 'https://cloud.example/dav%2F..%2Fadmin/' ],
			'encoded backslash'           => [ 'https://cloud.example/dav%5C..%5Cadmin/' ],
			'backslash'                   => [ 'https://cloud.example\\@evil.example/' ],
			'underscore host'             => [ 'https://a_b.example/' ],
			'zone id'                     => [ 'http://[fe80::1%25en0]/' ],
		];
	}

	/**
	 * @param string $url Address.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'hardened' )]
	public function test_refuses_an_address_the_save_rules_refuse_naming_url_and_never_the_value( string $url ): void {
		try {
			WebDavAddress::fromSettings( $url, '' );
			$this->fail( 'Accepted.' );
		} catch ( FieldException $e ) {
			$this->assertSame( 'url', $e->field );
			$this->assertStringNotContainsString( 'cloud.example', $e->getMessage() );
		}
	}

	public function test_path_segments_are_encoded_once_and_the_default_port_is_dropped(): void {
		$a = WebDavAddress::fromSettings( 'https://cloud.example:443//My%20Files/%C3%A4/b%2Dc/', 'x' );
		$b = WebDavAddress::fromSettings( 'https://cloud.example/My%20Files/ä/', '' );

		$this->assertSame( 'https://cloud.example/My%20Files/%C3%A4/b-c/x/', $a->folderUrl() );
		$this->assertSame( 'https://cloud.example/My%20Files/%C3%A4/', $b->base );
		$this->assertSame( 'https://cloud.example', $a->display() );
	}

	public function test_an_ipv6_address_is_lower_case_in_brackets(): void {
		$a = WebDavAddress::fromSettings( 'http://[FD00::5]:8080/dav', '' );

		$this->assertSame( 'http://[fd00::5]:8080', $a->display() );
		$this->assertSame( 'http://[fd00::5]:8080/dav/', $a->base );
	}

	public function test_the_folder_is_checked_again(): void {
		try {
			WebDavAddress::fromSettings( 'https://cloud.example/dav/', 'a/../b' );
			$this->fail( 'Accepted.' );
		} catch ( FieldException $e ) {
			$this->assertSame( 'folder', $e->field );
		}
	}
}
