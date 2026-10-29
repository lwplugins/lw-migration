<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Storage\Destinations\S3;

use LightweightPlugins\Migration\Storage\Destinations\S3\SigV4Signer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Vectors: AWS Labs' Signature V4 test suite,
 * https://github.com/awslabs/aws-c-auth/tree/3281f8692e6fd10562c4585a4dded5c16b322698/tests/aws-signing-test-suite/v4
 * (files context.json, request.txt, header-canonical-request.txt, header-signature.txt of each case).
 */
final class SigV4SignerTest extends TestCase {

	private const SECRET = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';

	private static function signer(): SigV4Signer {
		return new SigV4Signer( 'AKIDEXAMPLE', self::SECRET, 'us-east-1', 'service' );
	}

	private static function time(): int {
		return (int) gmmktime( 12, 36, 0, 8, 30, 2015 );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: array<string, string>, 3: string}>
	 */
	public static function suite(): array {
		return [
			'get-vanilla'                     => [ 'GET', '/', [], '5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31' ],
			'get-vanilla-query-order-encoded' => [ 'GET', '/', [ 'Param-3' => 'Value3', 'Param' => 'Value2', "\u{1234}" => 'Value1' ], '371d3713e185cc334048618a97f809c9ffe339c62934c032af5a0e595648fcac' ],
			'get-unreserved'                  => [ 'GET', '/-._~0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', [], '07ef7494c76fa4850883e2b006601f940f8a34d404d0cfa977f52a65bbf5f24f' ],
			'post-vanilla'                    => [ 'POST', '/', [], '5da7c1a2acd57cee7505fc6676e4e544621c30862966e37dddb68e92efbe5d6b' ],
			'get-vanilla-utf8-query'          => [ 'GET', '/', [ "\u{1234}" => 'bar' ], '2cdec8eed098649ff3a119c94853b13c643bcf08f8b0a1d91e12c9027818dd04' ],
			'get-vanilla-query-order-key-case' => [ 'GET', '/', [ 'Param2' => 'value2', 'Param1' => 'value1' ], 'b97d918cfa904a5beff61c982a1b6f458b799221646efd99d3219ec94cdf2500' ],
		];
	}

	/**
	 * @param array<string, string> $query Decoded query.
	 */
	#[DataProvider( 'suite' )]
	public function test_reproduces_the_aws_test_suite( string $method, string $path, array $query, string $signature ): void {
		$headers = self::signer()->sign( $method, $path, $query, [ 'Host' => 'example.amazonaws.com' ], SigV4Signer::EMPTY_SHA256, self::time() );

		$this->assertSame( '20150830T123600Z', $headers['x-amz-date'] );
		$this->assertSame(
			'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, SignedHeaders=host;x-amz-date, Signature=' . $signature,
			$headers['Authorization']
		);
	}

	public function test_the_canonical_request_of_the_encoded_query_case_matches_the_suite_file(): void {
		$canonical = SigV4Signer::headers( [ 'Host' => 'example.amazonaws.com', 'x-amz-date' => '20150830T123600Z' ] );
		$expected  = "GET\n/\n%E1%88%B4=Value1&Param=Value2&Param-3=Value3\nhost:example.amazonaws.com\nx-amz-date:20150830T123600Z\n\nhost;x-amz-date\n" . SigV4Signer::EMPTY_SHA256;

		$this->assertSame( $expected, self::signer()->canonicalRequest( 'GET', '/', [ 'Param-3' => 'Value3', 'Param' => 'Value2', "\u{1234}" => 'Value1' ], $canonical, SigV4Signer::EMPTY_SHA256 ) );
	}

	public function test_s3_keys_keep_their_slashes_and_encode_everything_else_once(): void {
		$this->assertSame( '/bucket/backups/a%20b%2Bc~%25.wpress', SigV4Signer::path( '/bucket/backups/a b+c~%.wpress' ) );
		$this->assertSame( 'partNumber=2&uploadId=a%2Fb%3D', SigV4Signer::query( [ 'uploadId' => 'a/b=', 'partNumber' => '2' ] ) );
		$this->assertSame( 'uploads=', SigV4Signer::query( [ 'uploads' => '' ] ) );
	}

	/**
	 * SigV4 sorts the encoded names by byte value; PHP's <=> would compare
	 * numeric strings as numbers ('9' < '10', '1e1' == '10').
	 */
	public function test_query_names_sort_by_bytes_not_as_numbers(): void {
		$this->assertSame( '10=b&9=a', SigV4Signer::query( [ '9' => 'a', '10' => 'b' ] ) );
		$this->assertSame( '10=y&1e1=x', SigV4Signer::query( [ '1e1' => 'x', '10' => 'y' ] ) );
	}

	public function test_header_values_are_trimmed_and_inner_spaces_collapsed(): void {
		$this->assertSame( [ 'host' => 'h', 'x-amz-meta-a' => 'a b c' ], SigV4Signer::headers( [ 'X-Amz-Meta-A' => "  a   b\tc ", 'Host' => 'h' ] ) );
	}

	public function test_the_secret_never_appears_in_the_signed_headers(): void {
		$headers = self::signer()->sign( 'PUT', '/k', [], [ 'Host' => 'h' ], SigV4Signer::EMPTY_SHA256, self::time() );

		$this->assertStringNotContainsString( self::SECRET, implode( "\n", $headers ) );
	}
}
