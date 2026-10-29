<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Export;

use LightweightPlugins\Migration\Archive\ArchiveException;
use LightweightPlugins\Migration\Archive\Cipher;
use LightweightPlugins\Migration\Export\ExportException;
use LightweightPlugins\Migration\Export\ExportOptions;
use LightweightPlugins\Migration\Export\PackageBuilder;
use LightweightPlugins\Migration\Package\PackageConfig;
use LightweightPlugins\Migration\Tests\Support\SiteFixture;
use PHPUnit\Framework\TestCase;

final class PackageBuilderTest extends TestCase {

	public function test_builds_reference_compatible_package(): void {
		$site    = SiteFixture::make( '/srv/a', [ 'uploadPath' => 'files' ] );
		$options = ExportOptions::fromArray(
			[
				'noSpamComments' => true,
				'excludeTables'  => [ 'wp_log' ],
				'replace'        => [ [ 'old', 'new' ] ],
				'compression'    => 'bzip2',
				'encrypt'        => true,
			]
		);
		$package = PackageBuilder::build( $site, $options, new Cipher( 'pw' ), "# BEGIN WordPress\n", '', '8.0.36' );
		$again   = PackageConfig::fromJson( $package->toJson() );

		$this->assertSame( 'https://old.test', $again->get( 'SiteURL' ) );
		$this->assertSame( 'https://old.test', $again->get( 'InternalHomeURL' ) );
		$this->assertSame( [ 'old' ], $again->get( 'Replace.OldValues' ) );
		$this->assertSame( [ 'new' ], $again->get( 'Replace.NewValues' ) );
		$this->assertTrue( $again->get( 'NoSpamComments' ) );
		$this->assertNull( $again->get( 'NoMedia' ), 'Only set flags are written.' );
		$this->assertSame( LW_MIGRATION_VERSION, $again->get( 'Plugin.Version' ) );
		$this->assertSame( '/srv/a/', $again->get( 'WordPress.Absolute' ) );
		$this->assertSame( '/srv/a/wp-content', $again->get( 'WordPress.Content' ) );
		$this->assertSame( [ '/srv/a/wp-content/themes' ], $again->get( 'WordPress.Themes' ) );
		$this->assertSame( 'https://old.test/wp-content/uploads/', $again->get( 'WordPress.UploadsURL' ) );
		$this->assertSame( [ 'Version' => '8.0.36', 'Charset' => 'utf8mb4', 'Collate' => '', 'Prefix' => 'wp_', 'ExcludedTables' => [ 'wp_log' ] ], $again->get( 'Database' ) );
		$this->assertSame( PHP_INT_SIZE, $again->get( 'PHP.Integer' ) );
		$this->assertSame( [ 'sample/sample.php' ], $again->get( 'Plugins' ), 'Our own plugin is never listed.' );
		$this->assertSame( 'twentytwentyfive', $again->get( 'Template' ) );
		$this->assertSame( 'files', $again->get( 'Uploads' ) );
		$this->assertSame( "# BEGIN WordPress\n", base64_decode( (string) $again->toArray()['Server']['.htaccess'] ), 'Dot-path lookup cannot address a key with a dot.' );
		$this->assertSame( 'bzip2', $again->compression() );
		$this->assertTrue( $again->isEncrypted() );
		$this->assertTrue( ( new Cipher( 'pw' ) )->verifySignature( $again->encryptedSignature() ) );
	}

	public function test_plain_package_has_no_encryption_or_compression_keys(): void {
		$data = PackageBuilder::build( SiteFixture::make( '/srv/a' ), new ExportOptions(), null, '', '', '10.11.6-MariaDB' )->toArray();
		$this->assertArrayNotHasKey( 'Encrypted', $data );
		$this->assertArrayNotHasKey( 'Compression', $data );
		$this->assertSame( [ '.htaccess' => '', 'web.config' => '' ], $data['Server'] );
	}

	public function test_only_format_keys_and_unknown_charset_is_written_as_the_format_does(): void {
		$site = SiteFixture::make(
			'/srv/a',
			[
				'dbCharset' => 'undefined',
				'dbCollate' => 'undefined',
			]
		);
		$data = PackageBuilder::build( $site, new ExportOptions(), null, '', '', '8.0.36' )->toArray();

		$this->assertSame( 'undefined', $data['Database']['Charset'] );
		$this->assertSame( 'undefined', $data['Database']['Collate'] );
		$this->assertSame( [ 'Version', 'Absolute', 'Content', 'Plugins', 'Themes', 'Uploads', 'UploadsURL' ], array_keys( $data['WordPress'] ) );
		$this->assertSame( [ 'SiteURL', 'HomeURL', 'InternalSiteURL', 'InternalHomeURL', 'Plugin', 'WordPress', 'Database', 'PHP', 'Plugins', 'Template', 'Stylesheet', 'Uploads', 'UploadsURL', 'Server' ], array_keys( $data ) );
	}

	public function test_encrypt_option_without_cipher_is_refused(): void {
		$this->expectException( ExportException::class );
		PackageBuilder::build( SiteFixture::make( '/srv/a' ), ExportOptions::fromArray( [ 'encrypt' => true ] ), null, '', '', '8.0.36' );
	}

	public function test_cipher_without_encrypt_option_is_refused(): void {
		$this->expectException( ExportException::class );
		PackageBuilder::build( SiteFixture::make( '/srv/a' ), new ExportOptions(), new Cipher( 'pw' ), '', '', '8.0.36' );
	}

	public function test_invalid_utf8_in_a_url_fails_loudly(): void {
		$this->expectException( ArchiveException::class );
		PackageBuilder::build( SiteFixture::make( '/srv/a', [ 'siteUrl' => "https://bad\xff.test" ] ), new ExportOptions(), null, '', '', '8.0.36' );
	}
}
