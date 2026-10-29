<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\WebDav;

use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Storage\Destinations\WebDav\WebDavErrors;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class WebDavErrorsTest extends MonkeyTestCase {

	/**
	 * @return array<string, array{0: int, 1: string, 2: string}>
	 */
	public static function statuses(): array {
		return [
			'timeout'            => [ 408, TransientFailure::class, TransientFailure::SERVER ],
			'locked'             => [ 423, TransientFailure::class, TransientFailure::BUSY ],
			'too early'          => [ 425, TransientFailure::class, TransientFailure::SERVER ],
			'too many'           => [ 429, TransientFailure::class, TransientFailure::BUSY ],
			'server'             => [ 500, TransientFailure::class, TransientFailure::SERVER ],
			'maintenance'        => [ 503, TransientFailure::class, TransientFailure::BUSY ],
			'cdn origin'         => [ 520, TransientFailure::class, TransientFailure::SERVER ],
			'not implemented'    => [ 501, PermanentFailure::class, PermanentFailure::INVALID ],
			'wrong password'     => [ 401, PermanentFailure::class, PermanentFailure::AUTH ],
			'forbidden'          => [ 403, PermanentFailure::class, PermanentFailure::DENIED ],
			'no such folder'     => [ 404, PermanentFailure::class, PermanentFailure::MISSING ],
			'parent missing'     => [ 409, PermanentFailure::class, PermanentFailure::MISSING ],
			'quota'              => [ 507, PermanentFailure::class, PermanentFailure::FULL ],
			'too large'          => [ 413, PermanentFailure::class, PermanentFailure::TOO_LARGE ],
			'redirect to https'  => [ 301, PermanentFailure::class, PermanentFailure::INVALID ],
			'method not allowed' => [ 405, PermanentFailure::class, PermanentFailure::INVALID ],
		];
	}

	#[DataProvider( 'statuses' )]
	public function test_each_status_has_its_kind( int $status, string $class, string $reason ): void {
		$failure = WebDavErrors::classify( $status, '', 'https://cloud.example', 1760000000 );

		$this->assertInstanceOf( $class, $failure );
		$this->assertSame( $reason, $failure->reason );
		$this->assertSame( $status, $failure->status );
		$this->assertStringContainsString( 'HTTP ' . $status, $failure->getMessage() );
	}

	public function test_retry_after_is_kept_on_a_passing_failure(): void {
		$this->assertSame( 7, WebDavErrors::classify( 503, '7', 'https://cloud.example', 1760000000 )->retryAfter );
	}

	/**
	 * Preflight P-I7: a certificate problem stops the destination, a broken
	 * handshake is passing, a request never sent stops it; the transport text
	 * (it may name a local file) is never shown.
	 */
	public function test_transport_failures_have_the_kinds_of_every_http_destination(): void {
		$certificate = WebDavErrors::transport( 'http_request_failed', 'cURL error 60: SSL certificate problem: self-signed certificate (CA file /etc/ssl/cert.pem)', 'https://cloud.example' );
		$handshake   = WebDavErrors::transport( 'http_request_failed', 'cURL error 35: OpenSSL SSL_connect: SSL_ERROR_SYSCALL', 'https://cloud.example' );
		$unsent      = WebDavErrors::transport( 'my_filter_said_no', 'Blocked by /srv/www/x.php', 'https://cloud.example', false );

		$this->assertInstanceOf( PermanentFailure::class, $certificate );
		$this->assertSame( PermanentFailure::INVALID, $certificate->reason );
		$this->assertStringNotContainsString( '/etc/ssl', $certificate->getMessage() );
		$this->assertInstanceOf( TransientFailure::class, $handshake );
		$this->assertSame( TransientFailure::NETWORK, $handshake->reason );
		$this->assertInstanceOf( PermanentFailure::class, $unsent );
		$this->assertSame( PermanentFailure::INVALID, $unsent->reason );
		$this->assertStringNotContainsString( '/srv/www', $unsent->getMessage() );
	}
}
