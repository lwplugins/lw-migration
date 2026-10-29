<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\OAuth;

use LightweightPlugins\Migration\Storage\Destinations\OAuth\HttpFailures;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Preflight P-I1: the provider-neutral status map; the transport and the
 * passing test come from HttpErrors, so OAuth classifies like S3 and WebDAV.
 */
final class HttpFailuresTest extends MonkeyTestCase {

	/**
	 * @return array<string, array{0: int, 1: string, 2: string}>
	 */
	public static function statuses(): array {
		return [
			'401' => [ 401, PermanentFailure::class, PermanentFailure::REAUTH ],
			'403' => [ 403, PermanentFailure::class, PermanentFailure::DENIED ],
			'404' => [ 404, PermanentFailure::class, PermanentFailure::MISSING ],
			'507' => [ 507, PermanentFailure::class, PermanentFailure::FULL ],
			'501' => [ 501, PermanentFailure::class, PermanentFailure::INVALID ],
			'505' => [ 505, PermanentFailure::class, PermanentFailure::INVALID ],
			'400' => [ 400, PermanentFailure::class, PermanentFailure::INVALID ],
			'408' => [ 408, TransientFailure::class, TransientFailure::SERVER ],
			'429' => [ 429, TransientFailure::class, TransientFailure::BUSY ],
			'500' => [ 500, TransientFailure::class, TransientFailure::SERVER ],
			'520' => [ 520, TransientFailure::class, TransientFailure::SERVER ],
		];
	}

	#[DataProvider( 'statuses' )]
	public function test_each_status( int $status, string $class, string $reason ): void {
		$failure = HttpFailures::generic( $status, 'Dropbox', '', 1_800_000_000 );

		$this->assertInstanceOf( $class, $failure );
		$this->assertSame( $reason, $failure->reason );
		$this->assertSame( $status, $failure->status );
		$this->assertStringContainsString( 'Dropbox', $failure->getMessage() );
	}

	public function test_retry_after_is_honoured_and_capped(): void {
		$this->assertSame( 300, HttpFailures::generic( 429, 'Dropbox', '9000', 0 )->retryAfter );
		$this->assertSame( 12, HttpFailures::generic( 503, 'Dropbox', '12', 0 )->retryAfter );
		$this->assertNull( HttpFailures::generic( 503, 'Dropbox', 'soon', 0 )->retryAfter );
	}

	public function test_transport_failures_follow_http_errors(): void {
		$this->assertSame( PermanentFailure::INVALID, HttpFailures::transport( 'http_request_failed', 'cURL error 60: SSL certificate problem', 'OneDrive' )->reason );
		$this->assertSame( TransientFailure::NETWORK, HttpFailures::transport( 'http_request_failed', 'cURL error 35: handshake', 'OneDrive' )->reason );
		$this->assertStringNotContainsString( 'handshake', HttpFailures::transport( 'http_request_failed', 'cURL error 35: handshake', 'OneDrive' )->getMessage() );
	}

	public function test_json_reads_only_an_object(): void {
		$this->assertSame( [ 'a' => 1 ], HttpFailures::json( ' {"a":1}' ) );
		$this->assertSame( [], HttpFailures::json( '[1,2]' ) );
		$this->assertSame( [], HttpFailures::json( 'nope' ) );
		$this->assertSame( [], HttpFailures::json( str_repeat( '{"a":', 40 ) . '1' . str_repeat( '}', 40 ) ), 'Too deep.' );
	}
}
