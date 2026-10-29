<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\OAuth;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\AccountCheck;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\Provider;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Tests\Support\CurlHooks;
use LightweightPlugins\Migration\Tests\Support\FakeWpError;
use LightweightPlugins\Migration\Tests\Support\RecordedHttp;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Issue #12: the account of an access token, asked straight at the provider.
 */
final class AccountCheckTest extends MonkeyTestCase {

	private CurlHooks $curl;

	private RecordedHttp $http;

	protected function setUp(): void {
		parent::setUp();
		RecordedHttp::install();
		Functions\when( 'wp_json_encode' )->alias( static fn( $data, int $flags = 0 ) => json_encode( $data, $flags ) );
		$this->curl = ( new CurlHooks() )->install();
		$this->http = new RecordedHttp();
		AddressGuard::useResolver( static fn( string $host ): array => [ '203.0.113.10' ] );
	}

	protected function tearDown(): void {
		AddressGuard::useResolver( null );
		parent::tearDown();
	}

	private function identify( Provider $provider, string $body, int $status = 200 ): string {
		$this->http->push( RecordedHttp::answer( $status, $body ) );

		return ( new AccountCheck( $this->curl->wrap( $this->http->send() ), static fn(): int => 1_800_000_000 ) )->identify( $provider, 'tok-1' );
	}

	public function test_dropbox_is_asked_for_the_current_account(): void {
		$this->assertSame( 'dropbox:dbid:AA', $this->identify( Provider::dropbox(), '{"account_id":"dbid:AA","email":"a@example.com"}' ) );

		[ $url, $args ] = $this->http->requests[0];
		$this->assertSame( [ AccountCheck::DROPBOX, 'POST', 'null', 'application/json', 'Bearer tok-1' ], [ $url, $args['method'], $args['body'], $args['headers']['Content-Type'], $args['headers']['Authorization'] ] );
	}

	public function test_google_is_asked_for_the_permission_id_of_the_user(): void {
		$this->assertSame( 'google:0123', $this->identify( Provider::google(), '{"user":{"permissionId":"0123"}}' ) );

		[ $url, $args ] = $this->http->requests[0];
		$this->assertSame( [ 'https://www.googleapis.com/drive/v3/about?fields=user(permissionId)', 'GET', 'Bearer tok-1' ], [ $url, $args['method'], $args['headers']['Authorization'] ] );
	}

	public function test_microsoft_is_asked_for_the_drive_of_the_app_folder(): void {
		$this->assertSame( 'microsoft:b!drive', $this->identify( Provider::microsoft(), '{"id":"01ITEM","parentReference":{"driveId":"b!drive","driveType":"business"}}' ) );
		$this->assertSame( 'microsoft:item:01ITEM', $this->identify( Provider::microsoft(), '{"id":"01ITEM"}' ), 'Without a drive id: the folder\'s own id.' );

		$this->assertSame( AccountCheck::MICROSOFT, $this->http->requests[0][0] );
	}

	/**
	 * @return array<string, array{0: int, 1: string, 2: class-string<CloudFailure>}>
	 */
	public static function unconfirmed(): array {
		return [
			'refused token'       => [ 401, '{}', PermanentFailure::class ],
			'forbidden'           => [ 403, '{}', PermanentFailure::class ],
			'server busy'         => [ 503, '{}', TransientFailure::class ],
			'no account id'       => [ 200, '{"email":"a@example.com"}', PermanentFailure::class ],
			'malformed id'        => [ 200, '{"account_id":"a b"}', PermanentFailure::class ],
			'id of the wrong type' => [ 200, '{"account_id":7}', PermanentFailure::class ],
			'not json'            => [ 200, 'nope', PermanentFailure::class ],
		];
	}

	/**
	 * @param int                        $status HTTP status.
	 * @param string                     $body   Answer.
	 * @param class-string<CloudFailure> $class  Failure expected.
	 */
	#[DataProvider( 'unconfirmed' )]
	public function test_an_answer_without_a_usable_account_is_a_failure_with_our_text( int $status, string $body, string $class ): void {
		try {
			$this->identify( Provider::dropbox(), $body, $status );
			$this->fail( 'Not confirmed.' );
		} catch ( CloudFailure $e ) {
			$this->assertInstanceOf( $class, $e );
			$this->assertSame( 'Dropbox did not confirm which account this sign-in belongs to.', $e->getMessage() );
		}
	}

	public function test_429_is_busy_with_its_retry_after(): void {
		$this->http->push( RecordedHttp::answer( 429, '{}', [ 'retry-after' => '60' ] ) );

		try {
			( new AccountCheck( $this->curl->wrap( $this->http->send() ), static fn(): int => 1_800_000_000 ) )->identify( Provider::dropbox(), 'tok-1' );
			$this->fail( 'Not confirmed.' );
		} catch ( TransientFailure $e ) {
			$this->assertSame( [ TransientFailure::BUSY, 429, 60 ], [ $e->reason, $e->status, $e->retryAfter ] );
		}
	}

	public function test_a_timeout_is_a_temporary_network_failure(): void {
		$this->http->push( new FakeWpError( 'cURL error 28: Operation timed out after 30001 milliseconds' ) );

		try {
			( new AccountCheck( $this->curl->wrap( $this->http->send() ), static fn(): int => 1_800_000_000 ) )->identify( Provider::google(), 'tok-1' );
			$this->fail( 'Not confirmed.' );
		} catch ( TransientFailure $e ) {
			$this->assertSame( TransientFailure::NETWORK, $e->reason );
		}
	}

	public function test_the_access_token_is_marked_sensitive(): void {
		$this->assertCount( 1, ( new \ReflectionParameter( [ AccountCheck::class, 'identify' ], 'accessToken' ) )->getAttributes( \SensitiveParameter::class ) );
	}
}
