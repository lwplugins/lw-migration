<?php
/**
 * The bytes of one upload slice from a REST request.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * The page sends a slice as the file part `chunk` of a multipart/form-data
 * request: hosting firewalls (ModSecurity rule sets) refuse a raw
 * application/octet-stream body, often with a 406. The raw body is still
 * read when there is no file part (the connected sites send it, and the page
 * falls back to it on lw_migration_upload_off). A multipart request whose
 * file PHP did not keep means file uploads are switched off (file_uploads):
 * PHP leaves php://input empty for multipart, so the slice is lost.
 */
final class UploadSlice {

	public const FIELD = 'chunk';

	/**
	 * @param \WP_REST_Request $request  Upload request.
	 * @param \Closure|null    $uploaded fn( string $path ): bool (default: is_uploaded_file).
	 * @throws RestException When the slice did not arrive.
	 */
	public static function from( \WP_REST_Request $request, ?\Closure $uploaded = null ): string {
		$file = $request->get_file_params()[ self::FIELD ] ?? null;
		if ( is_array( $file ) ) {
			return self::read( $file, $uploaded ?? static fn( string $path ): bool => is_uploaded_file( $path ) );
		}
		if ( str_starts_with( strtolower( (string) $request->get_header( 'content_type' ) ), 'multipart/' ) ) {
			throw new RestException( 'lw_migration_upload_off', __( 'This server does not take file uploads.', 'lw-migration' ), 415 );
		}

		return (string) $request->get_body();
	}

	/**
	 * @param array<string, mixed> $file     One entry of $_FILES.
	 * @param \Closure             $uploaded fn( string $path ): bool.
	 * @throws RestException When PHP refused or lost the part.
	 */
	private static function read( array $file, \Closure $uploaded ): string {
		$error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
		if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
			throw new RestException( 'lw_migration_chunk_too_large', __( 'This part of the upload is too large for the server.', 'lw-migration' ), 413, [ 'chunkBytes' => WebLimits::chunkBytes() ] );
		}
		$path = (string) ( $file['tmp_name'] ?? '' );
		if ( UPLOAD_ERR_OK !== $error || '' === $path || ! $uploaded( $path ) ) {
			throw new RestException( 'lw_migration_upload_failed', __( 'This part of the upload did not arrive. Try again.', 'lw-migration' ), 400 );
		}
		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- PHP's own temporary file of this request.

		if ( false === $bytes ) {
			throw new RestException( 'lw_migration_upload_failed', __( 'This part of the upload did not arrive. Try again.', 'lw-migration' ), 400 );
		}

		return $bytes;
	}
}
