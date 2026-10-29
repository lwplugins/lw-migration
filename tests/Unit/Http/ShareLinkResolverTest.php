<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Http;

use LightweightPlugins\Migration\Http\ShareLinkResolver;
use PHPUnit\Framework\TestCase;

final class ShareLinkResolverTest extends TestCase {

	public function test_dropbox_links_download_directly(): void {
		$this->assertSame( 'https://www.dropbox.com/scl/fi/abc/site.wpress?rlkey=xyz&dl=1', ShareLinkResolver::resolve( 'https://www.dropbox.com/scl/fi/abc/site.wpress?rlkey=xyz&dl=0' ) );
		$this->assertSame( 'https://dropbox.com/s/abc/site.wpress?dl=1', ShareLinkResolver::resolve( 'https://dropbox.com/s/abc/site.wpress' ) );
		$this->assertSame( 'https://DropBox.com/s/abc/site.wpress?dl=1', ShareLinkResolver::resolve( 'https://DropBox.com/s/abc/site.wpress' ) );
	}

	public function test_google_drive_links(): void {
		$this->assertSame( 'https://drive.google.com/uc?export=download&id=1AbC_-x9', ShareLinkResolver::resolve( 'https://drive.google.com/file/d/1AbC_-x9/view?usp=sharing' ) );
		$this->assertSame( 'https://drive.google.com/uc?export=download&id=XYZ', ShareLinkResolver::resolve( 'https://drive.google.com/open?id=XYZ' ) );
		$this->assertSame( 'https://drive.google.com/drive/folders/abc', ShareLinkResolver::resolve( 'https://drive.google.com/drive/folders/abc' ) );
		$html = '<form id="download-form" action="https://drive.usercontent.google.com/download" method="get">'
			. '<input type="hidden" name="id" value="XYZ"><input type="hidden" name="export" value="download">'
			. '<input type="hidden" name="confirm" value="t"><input type="hidden" name="uuid" value="u-1"></form>';
		$this->assertSame( 'https://drive.usercontent.google.com/download?id=XYZ&export=download&confirm=t&uuid=u-1', ShareLinkResolver::confirmUrl( $html ) );
		$this->assertNull( ShareLinkResolver::confirmUrl( str_replace( 'https://', 'http://', $html ) ) );
		$this->assertNull( ShareLinkResolver::confirmUrl( '<html>nothing</html>' ) );
	}

	public function test_onedrive_links_use_the_shares_api(): void {
		$url = 'https://1drv.ms/u/s!AbcDef?e=1';
		$id  = rtrim( strtr( base64_encode( $url ), '+/', '-_' ), '=' );
		$this->assertSame( 'https://api.onedrive.com/v1.0/shares/u!' . $id . '/root/content', ShareLinkResolver::resolve( $url ) );
	}

	public function test_other_links_are_unchanged(): void {
		$this->assertSame( 'https://files.example.com/a.wpress?x=1', ShareLinkResolver::resolve( 'https://files.example.com/a.wpress?x=1' ) );
		$this->assertSame( 'not a url', ShareLinkResolver::resolve( 'not a url' ) );
		$this->assertSame( '', ShareLinkResolver::resolve( '' ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function lookalikeHosts(): array {
		return array(
			'suffix'       => array( 'https://dropbox.com.evil.test/s/abc/a.wpress' ),
			'prefix'       => array( 'https://evildropbox.com/s/abc/a.wpress' ),
			'userinfo'     => array( 'https://www.dropbox.com@evil.test/s/abc/a.wpress' ),
			'in path'      => array( 'https://evil.test/www.dropbox.com/a.wpress' ),
			'in query'     => array( 'https://evil.test/a?u=https://dropbox.com/x' ),
			'drive suffix' => array( 'https://drive.google.com.evil.test/file/d/abc/view' ),
			'drive in path' => array( 'https://evil.test/drive.google.com/open?id=abc' ),
			'onedrive'     => array( 'https://1drv.ms.evil.test/u/s!A' ),
			'onedrive 2'   => array( 'https://evil1drv.ms/u/s!A' ),
		);
	}

	/**
	 * @dataProvider lookalikeHosts
	 */
	public function test_only_exact_provider_hosts_are_rewritten( string $url ): void {
		$this->assertSame( $url, ShareLinkResolver::resolve( $url ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function foreignSchemes(): array {
		return array(
			'ftp'        => array( 'ftp://www.dropbox.com/s/abc/a.wpress' ),
			'file'       => array( 'file://dropbox.com/etc/passwd' ),
			'javascript' => array( 'javascript://dropbox.com/%0Aalert(1)' ),
			'drive ftp'  => array( 'ftp://drive.google.com/open?id=abc' ),
			'onedrive'   => array( 'gopher://1drv.ms/u/s!A' ),
			'no scheme'  => array( '//dropbox.com/s/abc/a.wpress' ),
		);
	}

	/**
	 * @dataProvider foreignSchemes
	 */
	public function test_non_http_schemes_are_never_rewritten( string $url ): void {
		$this->assertSame( $url, ShareLinkResolver::resolve( $url ) );
	}

	public function test_the_result_of_a_rewrite_is_always_http_or_https(): void {
		foreach ( array( 'https://dropbox.com/s/a', 'http://dropbox.com/s/a', 'https://drive.google.com/open?id=a', 'https://1drv.ms/u/s!A', 'http://1drv.ms/u/s!A' ) as $url ) {
			$this->assertMatchesRegularExpression( '#^https?://#', ShareLinkResolver::resolve( $url ) );
		}
		$this->assertStringStartsWith( 'http://dropbox.com/', ShareLinkResolver::resolve( 'http://dropbox.com/s/a' ) );
	}

	public function test_invalid_drive_ids_are_not_rewritten(): void {
		$url = 'https://drive.google.com/open?id=a%26b';
		$this->assertSame( $url, ShareLinkResolver::resolve( $url ) );
		$this->assertNull( ShareLinkResolver::driveId( 'https://drive.google.com/open?id[]=x' ) );
	}

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function confirmHosts(): array {
		return array(
			'google.com'              => array( 'https://google.com/download', true ),
			'sub-host'                => array( 'https://drive.google.com/uc?id=a', true ),
			'user content'            => array( 'https://drive.usercontent.googleusercontent.com/download', true ),
			'upper case'              => array( 'https://Drive.Google.COM/download', true ),
			'suffix lookalike'        => array( 'https://google.com.evil.test/download', false ),
			'prefix lookalike'        => array( 'https://evilgoogle.com/download', false ),
			'bare user content'       => array( 'https://googleusercontent.com/download', false ),
			'user info'               => array( 'https://google.com@evil.test/download', false ),
			'fragment'                => array( 'https://evil.test#.google.com', false ),
			'port as password'        => array( 'https://drive.google.com:443@evil.test/download', false ),
			'backslash user info'     => array( 'https://evil.test\\@drive.google.com/download', false ),
			'trailing dot'            => array( 'https://drive.google.com./download', false ),
			'plain http'              => array( 'http://drive.google.com/download', false ),
			'no host'                 => array( 'https:///drive.google.com', false ),
			'not an address'          => array( 'drive.google.com', false ),
		);
	}

	/**
	 * @dataProvider confirmHosts
	 */
	public function test_confirmation_targets_are_trusted_only_on_google_hosts( string $url, bool $trusted ): void {
		$this->assertSame( $trusted, ShareLinkResolver::isGoogleHost( $url ) );
	}
}
