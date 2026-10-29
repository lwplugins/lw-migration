<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Backups;

use LightweightPlugins\Migration\Backups\BackupOrigin;
use PHPUnit\Framework\TestCase;

final class BackupOriginTest extends TestCase {

	private const FIXTURES = __DIR__ . '/../../Fixtures/wpress/';

	public function test_a_backup_of_this_site_names_no_other_site(): void {
		$origin = BackupOrigin::read( self::FIXTURES . 'plain.wpress', 'https://Example.test/' );

		$this->assertSame( [ 'encrypted' => false, 'fromSite' => null ], $origin );
	}

	public function test_a_backup_of_another_site_names_its_address_without_the_scheme(): void {
		$this->assertSame( 'site-a.test', BackupOrigin::read( self::FIXTURES . 'full-site.wpress', 'https://example.test' )['fromSite'] );
		$this->assertSame( 'example.test', BackupOrigin::read( self::FIXTURES . 'plain.wpress', 'https://example.test/blog' )['fromSite'], 'Another folder of the same host is another site.' );
	}

	public function test_the_scheme_alone_does_not_make_another_site(): void {
		$this->assertNull( BackupOrigin::read( self::FIXTURES . 'full-site.wpress', 'https://SITE-A.test' )['fromSite'] );
	}

	public function test_an_encrypted_backup_says_so(): void {
		$this->assertTrue( BackupOrigin::read( self::FIXTURES . 'encrypted.wpress', 'https://example.test' )['encrypted'] );
	}

	public function test_an_unreadable_file_is_unknown(): void {
		$file = tempnam( sys_get_temp_dir(), 'lwm' );
		file_put_contents( $file, 'not an archive' );
		try {
			$this->assertSame( [ 'encrypted' => null, 'fromSite' => null ], BackupOrigin::read( $file, 'https://example.test' ) );
		} finally {
			unlink( $file );
		}
	}

	/**
	 * @dataProvider provide_places
	 */
	public function test_place_is_host_and_path_in_lower_case( string $url, string $place ): void {
		$this->assertSame( $place, BackupOrigin::place( $url ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function provide_places(): array {
		return [
			'scheme and slash' => [ 'https://Shop.Example.com/', 'shop.example.com' ],
			'folder'           => [ 'http://example.com/Blog/', 'example.com/blog' ],
			'port'             => [ 'http://127.0.0.1:8894', '127.0.0.1:8894' ],
			'no address'       => [ '', '' ],
		];
	}
}
