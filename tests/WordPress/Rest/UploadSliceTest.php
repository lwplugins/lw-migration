<?php
/**
 * Where an upload slice comes from: a multipart file part or the raw body.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Rest;

use LightweightPlugins\Migration\Rest\RestException;
use LightweightPlugins\Migration\Rest\UploadSlice;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

/**
 * Hosting firewalls refuse a raw application/octet-stream body (406), so the
 * page sends each slice as a multipart file part; the raw body stays for the
 * connected sites and for hosts with file uploads switched off.
 */
final class UploadSliceTest extends MonkeyTestCase {

	private string $temp = '';

	protected function tearDown(): void {
		if ( '' !== $this->temp && is_file( $this->temp ) ) {
			unlink( $this->temp );
		}
		parent::tearDown();
	}

	public function test_reads_the_file_part_of_a_multipart_request(): void {
		$request = $this->multipart( [ 'chunk' => $this->part( 'slice-bytes' ) ] );

		$this->assertSame( 'slice-bytes', UploadSlice::from( $request, static fn(): bool => true ) );
	}

	public function test_reads_the_raw_body_without_a_file_part(): void {
		$request = $this->request( 'application/octet-stream', [], "raw\0bytes" );

		$this->assertSame( "raw\0bytes", UploadSlice::from( $request ) );
	}

	public function test_refuses_a_file_part_php_did_not_receive_as_an_upload(): void {
		$request = $this->multipart( [ 'chunk' => $this->part( 'x' ) ] );

		$this->assertRefused( 'lw_migration_upload_failed', 400, fn() => UploadSlice::from( $request, static fn(): bool => false ) );
	}

	public function test_a_part_over_upload_max_filesize_asks_for_smaller_slices(): void {
		\Brain\Monkey\Functions\when( 'wp_convert_hr_to_bytes' )->justReturn( 0 );
		$request = $this->multipart( [ 'chunk' => [ 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE ] ] );

		$this->assertRefused( 'lw_migration_chunk_too_large', 413, fn() => UploadSlice::from( $request, static fn(): bool => true ) );
	}

	public function test_a_multipart_request_without_its_file_says_file_uploads_are_off(): void {
		$request = $this->multipart( [] );

		$this->assertRefused( 'lw_migration_upload_off', 415, fn() => UploadSlice::from( $request ) );
	}

	/**
	 * @param array<string, mixed> $files File params.
	 */
	private function multipart( array $files ): \WP_REST_Request {
		return $this->request( 'multipart/form-data; boundary=----x', $files, '' );
	}

	/**
	 * @param array<string, mixed> $files File params.
	 */
	private function request( string $type, array $files, string $body ): \WP_REST_Request {
		$request = \Mockery::mock( \WP_REST_Request::class );
		$request->allows( 'get_header' )->with( 'content_type' )->andReturn( $type );
		$request->allows( 'get_file_params' )->andReturn( $files );
		$request->allows( 'get_body' )->andReturn( $body );

		return $request;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function part( string $bytes ): array {
		$this->temp = (string) tempnam( sys_get_temp_dir(), 'lwm-slice' );
		file_put_contents( $this->temp, $bytes );

		return [ 'tmp_name' => $this->temp, 'error' => UPLOAD_ERR_OK, 'size' => strlen( $bytes ) ];
	}

	private function assertRefused( string $code, int $status, \Closure $call ): void {
		try {
			$call();
			$this->fail( 'Expected a refusal.' );
		} catch ( RestException $e ) {
			$this->assertSame( $code, $e->errorCode );
			$this->assertSame( $status, $e->status );
		}
	}
}
