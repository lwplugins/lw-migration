<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Storage\Destinations\OAuth;

use LightweightPlugins\Migration\Storage\Destinations\OAuth\Provider;
use PHPUnit\Framework\TestCase;

final class ProviderTest extends TestCase {

	/**
	 * @return array<string, string>
	 */
	private static function query( string $url ): array {
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
		return $query;
	}

	public function test_google_asks_for_offline_access_consent_drive_file_and_pkce(): void {
		$google = Provider::google();
		$url    = $google->authorizeUrl( 'cid', 'https://site.test/cb', 'st', 'ch' );

		$this->assertStringStartsWith( 'https://accounts.google.com/o/oauth2/v2/auth?', $url );
		$this->assertSame(
			[
				'client_id'             => 'cid',
				'redirect_uri'          => 'https://site.test/cb',
				'response_type'         => 'code',
				'scope'                 => 'https://www.googleapis.com/auth/drive.file',
				'state'                 => 'st',
				'code_challenge'        => 'ch',
				'code_challenge_method' => 'S256',
				'access_type'           => 'offline',
				'prompt'                => 'consent',
			],
			self::query( $url )
		);
		$this->assertSame( 'https://oauth2.googleapis.com/token', $google->tokenEndpoint );
		$this->assertSame( Provider::GOOGLE, $google->key );
		$this->assertSame( 'Google Drive', $google->label );
		$this->assertFalse( $google->scopeOnToken );
		$this->assertFalse( $google->rule( 'ip' ) );
		$this->assertTrue( $google->rule( 'suffix' ) );
	}

	public function test_dropbox_asks_for_offline_tokens_and_its_four_scopes(): void {
		$dropbox = Provider::dropbox();
		$url     = $dropbox->authorizeUrl( 'key', 'https://site.test/cb', 'st', 'ch' );
		$query   = self::query( $url );

		$this->assertStringStartsWith( 'https://www.dropbox.com/oauth2/authorize?', $url );
		$this->assertSame( 'https://api.dropboxapi.com/oauth2/token', $dropbox->tokenEndpoint );
		$this->assertSame( 'offline', $query['token_access_type'] );
		$this->assertSame( 'files.metadata.read files.content.read files.content.write account_info.read', $query['scope'] );
		$this->assertSame( 'S256', $query['code_challenge_method'] );
		$this->assertSame( 'Dropbox', $dropbox->label );
	}

	public function test_microsoft_uses_the_tenant_the_access_choice_and_repeats_the_scope_on_token_calls(): void {
		$app    = Provider::microsoft( 'common', 'appfolder' );
		$folder = self::query( $app->authorizeUrl( 'cid', 'https://site.test/cb', 'st', 'ch' ) );
		$files  = Provider::microsoft( 'contoso.onmicrosoft.com', 'files' );

		$this->assertStringStartsWith( 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize?', $app->authorizeUrl( 'cid', 'https://site.test/cb', 'st', 'ch' ) );
		$this->assertSame( 'https://login.microsoftonline.com/common/oauth2/v2.0/token', $app->tokenEndpoint );
		$this->assertSame( 'offline_access openid profile https://graph.microsoft.com/Files.ReadWrite.AppFolder', $folder['scope'], 'The account label needs preferred_username (profile), never the email scope.' );
		$this->assertSame( 'query', $folder['response_mode'] );
		$this->assertTrue( $app->scopeOnToken );
		$this->assertStringContainsString( 'https://graph.microsoft.com/Files.ReadWrite', $files->scope() );
		$this->assertStringNotContainsString( 'AppFolder', $files->scope() );
		$this->assertSame( 'https://login.microsoftonline.com/contoso.onmicrosoft.com/oauth2/v2.0/token', $files->tokenEndpoint );
		$this->assertFalse( $app->rule( 'query' ), 'Personal accounts: no query in the redirect address.' );
		$this->assertFalse( Provider::microsoft( 'consumers' )->rule( 'query' ) );
		$this->assertTrue( $files->rule( 'query' ), 'A work-only tenant may have one.' );
		$this->assertSame( 256, $app->rule( 'length' ) );
		$this->assertSame( 'OneDrive', $app->label );
	}

	public function test_the_tenant_is_lower_cased(): void {
		$this->assertSame( 'https://login.microsoftonline.com/contoso.onmicrosoft.com/oauth2/v2.0/token', Provider::microsoft( 'Contoso.OnMicrosoft.com' )->tokenEndpoint );
		$this->assertFalse( Provider::microsoft( 'Consumers' )->rule( 'query' ), 'Compared after lower-casing.' );
	}

	/**
	 * @dataProvider provide_tenants_that_could_change_the_endpoint_path
	 */
	public function test_a_tenant_that_could_change_the_endpoint_path_is_refused( string $tenant ): void {
		$this->expectException( \InvalidArgumentException::class );

		Provider::microsoft( $tenant );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function provide_tenants_that_could_change_the_endpoint_path(): array {
		return [
			'dot'          => [ '.' ],
			'dot dot'      => [ '..' ],
			'traversal'    => [ '../evil?' ],
			'empty label'  => [ 'contoso..com' ],
			'leading dot'  => [ '.contoso.com' ],
			'trailing dot' => [ 'contoso.com.' ],
			'slash'        => [ 'a/b' ],
			'empty'        => [ '' ],
			'encoded'      => [ '%2e%2e' ],
			'leading dash' => [ '-contoso.com' ],
			'label dash'   => [ 'contoso-.com' ],
			'tld dash'     => [ 'contoso.-com' ],
			'long label'   => [ str_repeat( 'a', 64 ) . '.com' ],
		];
	}

	public function test_a_domain_tenant_with_inner_dashes_is_kept(): void {
		$this->assertStringContainsString( '/my-org.example-1.com/', Provider::microsoft( 'my-org.example-1.com' )->tokenEndpoint );
		$this->assertStringContainsString( '/' . str_repeat( 'a', 63 ) . '.com/', Provider::microsoft( str_repeat( 'a', 63 ) . '.com' )->tokenEndpoint );
	}

	public function test_without_pkce_no_challenge_is_sent(): void {
		$plain = new Provider( 'x', 'X', 'https://auth.example/a', 'https://auth.example/t', [ 'a', 'b' ], [], false, false );
		$query = self::query( $plain->authorizeUrl( 'cid', 'https://site.test/cb', 'st', '' ) );

		$this->assertArrayNotHasKey( 'code_challenge', $query );
		$this->assertArrayNotHasKey( 'code_challenge_method', $query );
		$this->assertSame( 'a b', $query['scope'] );
	}

	public function test_the_query_encodes_spaces_as_percent_20(): void {
		$url = Provider::dropbox()->authorizeUrl( 'key', 'https://site.test/?rest_route=/x', 'st', 'ch' );

		$this->assertStringContainsString( 'scope=files.metadata.read%20files.content.read', $url );
		$this->assertStringContainsString( 'redirect_uri=https%3A%2F%2Fsite.test%2F%3Frest_route%3D%2Fx', $url );
	}

	public function test_unknown_rules_are_false_and_defaults_are_lenient(): void {
		$dropbox = Provider::dropbox();

		$this->assertFalse( $dropbox->rule( 'nonsense' ) );
		$this->assertTrue( $dropbox->rule( 'ip' ) );
		$this->assertTrue( $dropbox->rule( 'query' ) );
		$this->assertTrue( $dropbox->rule( 'idn' ) );
		$this->assertFalse( $dropbox->rule( 'suffix' ) );
		$this->assertFalse( $dropbox->rule( 'chars' ) );
		$this->assertSame( 0, $dropbox->rule( 'length' ) );
	}
}
