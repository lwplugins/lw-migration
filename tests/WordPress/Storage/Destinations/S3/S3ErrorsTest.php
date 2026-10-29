<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\S3;

use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\S3\S3Errors;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class S3ErrorsTest extends MonkeyTestCase {

	private static function xml( string $code ): string {
		return '<Error><Code>' . $code . '</Code><Message>m</Message></Error>';
	}

	/**
	 * @return array<string, array{0: int, 1: string, 2: string, 3: string}>
	 */
	public static function cases(): array {
		return [
			'503 slow down'          => [ 503, self::xml( 'SlowDown' ), TransientFailure::class, TransientFailure::BUSY ],
			'429 no body'            => [ 429, '', TransientFailure::class, TransientFailure::BUSY ],
			'500 internal'           => [ 500, self::xml( 'InternalError' ), TransientFailure::class, TransientFailure::SERVER ],
			'408 timeout'            => [ 408, '', TransientFailure::class, TransientFailure::SERVER ],
			'520 edge error'         => [ 520, '', TransientFailure::class, TransientFailure::SERVER ],
			'522 edge timeout'       => [ 522, '', TransientFailure::class, TransientFailure::SERVER ],
			'501 not implemented'    => [ 501, '', PermanentFailure::class, PermanentFailure::INVALID ],
			'505 http version'       => [ 505, '', PermanentFailure::class, PermanentFailure::INVALID ],
			'400 request timeout'    => [ 400, self::xml( 'RequestTimeout' ), TransientFailure::class, TransientFailure::SERVER ],
			'403 bad key'            => [ 403, self::xml( 'InvalidAccessKeyId' ), PermanentFailure::class, PermanentFailure::AUTH ],
			'403 bad signature'      => [ 403, self::xml( 'SignatureDoesNotMatch' ), PermanentFailure::class, PermanentFailure::AUTH ],
			'400 wrong region'       => [ 400, self::xml( 'AuthorizationHeaderMalformed' ), PermanentFailure::class, PermanentFailure::AUTH ],
			'403 denied'             => [ 403, self::xml( 'AccessDenied' ), PermanentFailure::class, PermanentFailure::DENIED ],
			'403 skewed clock'       => [ 403, self::xml( 'RequestTimeTooSkewed' ), PermanentFailure::class, PermanentFailure::CLOCK ],
			'404 bucket'             => [ 404, self::xml( 'NoSuchBucket' ), PermanentFailure::class, PermanentFailure::MISSING ],
			'404 upload'             => [ 404, self::xml( 'NoSuchUpload' ), PermanentFailure::class, PermanentFailure::SESSION_GONE ],
			'404 key'                => [ 404, self::xml( 'NoSuchKey' ), PermanentFailure::class, PermanentFailure::MISSING ],
			'403 quota'              => [ 403, self::xml( 'QuotaExceeded' ), PermanentFailure::class, PermanentFailure::FULL ],
			'507'                    => [ 507, '', PermanentFailure::class, PermanentFailure::FULL ],
			'400 entity too large'   => [ 400, self::xml( 'EntityTooLarge' ), PermanentFailure::class, PermanentFailure::TOO_LARGE ],
			'301 permanent redirect' => [ 301, self::xml( 'PermanentRedirect' ), PermanentFailure::class, PermanentFailure::INVALID ],
			'400 other'              => [ 400, self::xml( 'InvalidArgument' ), PermanentFailure::class, PermanentFailure::INVALID ],
		];
	}

	/**
	 * @param class-string $class Expected class.
	 */
	#[DataProvider( 'cases' )]
	public function test_status_and_code_decide_the_kind( int $status, string $body, string $class, string $reason ): void {
		$failure = S3Errors::classify( $status, $body, '', 'https://b.s3.example.test', 1000 );

		$this->assertInstanceOf( $class, $failure );
		$this->assertSame( [ $reason, $status ], [ $failure->reason, $failure->status ] );
	}

	public function test_retry_after_in_seconds_or_as_a_date_is_read_and_capped(): void {
		$this->assertSame( 7, S3Errors::retryAfter( '7', 1000 ) );
		$this->assertSame( 30, S3Errors::retryAfter( gmdate( 'D, d M Y H:i:s', 1030 ) . ' GMT', 1000 ) );
		$this->assertSame( 300, S3Errors::retryAfter( '99999', 1000 ) );
		$this->assertNull( S3Errors::retryAfter( 'soon', 1000 ) );
		$this->assertNull( S3Errors::retryAfter( '', 1000 ) );
		$this->assertNull( S3Errors::retryAfter( 'tomorrow', 1000 ) );
		$this->assertNull( S3Errors::retryAfter( '+1 minute', 1000 ) );
		$this->assertNull( S3Errors::retryAfter( 'next monday', 1000 ) );
		$this->assertSame( 0, S3Errors::retryAfter( 'Thu, 01 Jan 1970 00:00:10 GMT', 1000 ) );
		$this->assertSame( 7, S3Errors::classify( 503, '', '7', 'h', 1000 )->retryAfter );
	}

	/**
	 * WP_Error code and message as WordPress 7.1.2 builds them: WP_Http::request()
	 * wraps a Requests exception as http_request_failed with its message (cURL:
	 * "cURL error N: ..."; fsockopen: the ssl.no_match text), and a blocked host
	 * as http_request_not_executed.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function refused(): array {
		return [
			'certificate not trusted' => [ 'http_request_failed', 'cURL error 60: SSL certificate problem: unable to get local issuer certificate (CA file /etc/ssl/cert.pem)' ],
			'bad CA bundle'           => [ 'http_request_failed', 'cURL error 77: error setting certificate file: /home/u/certs/ca.pem' ],
			'name mismatch'           => [ 'http_request_failed', 'cURL error 60: SSL: no alternative certificate subject name matches target host name /x' ],
			'fsockopen name mismatch' => [ 'http_request_failed', 'SSL certificate did not match the requested domain name' ],
			'blocked host'            => [ 'http_request_not_executed', 'User has blocked requests through HTTP to the URL: https://b.s3.example.test/k?X-Amz-Signature=abc.' ],
			'address refused'         => [ 'http_request_failed', 'A valid URL was not provided.' ],
		];
	}

	#[DataProvider( 'refused' )]
	public function test_a_certificate_failure_or_a_blocked_request_stops_the_destination_in_our_words( string $code, string $message ): void {
		$failure = S3Errors::transport( $code, $message, 'https://b.s3.example.test' );

		$this->assertInstanceOf( PermanentFailure::class, $failure );
		$this->assertSame( PermanentFailure::INVALID, $failure->reason );
		$this->assertStringContainsString( 'https://b.s3.example.test', $failure->getMessage() );
		foreach ( [ 'cURL', '/etc/', '/var/', '/home/', '/x', 'Signature', 'did not match', 'valid URL' ] as $transportText ) {
			$this->assertStringNotContainsString( $transportText, $failure->getMessage() );
		}
	}

	public function test_a_request_that_never_reached_the_network_stops_the_destination(): void {
		$failure = S3Errors::transport( 'my_plugin_said_no', 'Blocked by a filter at /srv/www/x.php', 'https://b.s3.example.test', false );

		$this->assertInstanceOf( PermanentFailure::class, $failure );
		$this->assertSame( PermanentFailure::INVALID, $failure->reason );
		$this->assertStringNotContainsString( '/srv/', $failure->getMessage() );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function passing(): array {
		return [
			'timeout'      => [ 'cURL error 28: Operation timed out after 60001 milliseconds' ],
			'refused'      => [ 'cURL error 7: Failed to connect to b.s3.example.test port 443' ],
			'reset'        => [ 'cURL error 56: Recv failure: Connection reset by peer' ],
			'handshake'    => [ 'cURL error 35: OpenSSL SSL_connect: SSL_ERROR_SYSCALL in connection to /var/tmp/x' ],
			'unknown kind' => [ 'something else' ],
		];
	}

	#[DataProvider( 'passing' )]
	public function test_other_transport_failures_stay_worth_another_try( string $message ): void {
		$failure = S3Errors::transport( 'http_request_failed', $message, 'https://b.s3.example.test' );

		$this->assertInstanceOf( TransientFailure::class, $failure );
		$this->assertSame( TransientFailure::NETWORK, $failure->reason );
	}

	public function test_the_server_text_never_reaches_the_message(): void {
		$failure = S3Errors::classify( 403, '<Error><Code>AccessDenied</Code><Message>secret-ish server text</Message></Error>', '', 'https://b.s3.example.test', 1000 );

		$this->assertStringNotContainsString( 'secret-ish', $failure->getMessage() );
		$this->assertStringContainsString( 'AccessDenied', $failure->getMessage() );
		$this->assertStringContainsString( 'https://b.s3.example.test', $failure->getMessage() );
	}
}
