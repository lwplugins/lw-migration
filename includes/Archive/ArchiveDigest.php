<?php
/**
 * Resumable SHA-256 of an archive file.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Archive;

use LightweightPlugins\Migration\Support\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * Issue #1: the archive of a pull streams past the answer signatures, so the
 * signed job view carries its SHA-256 and the pulling site compares the
 * downloaded file with it before any import step reads it. Both sides hash
 * in steps that respect the tick's deadline (a large archive takes longer
 * than one request may run): advance() reads from the saved offset with the
 * saved hash state (a serialized HashContext, base64 so it fits job.json)
 * and stops after a block once the deadline is reached. Progress that does
 * not fit (damaged, another algorithm, past the end) starts again from the
 * first byte.
 */
final class ArchiveDigest {

	public const PATTERN = '/^[a-f0-9]{64}\z/';

	private const ALGORITHM = 'sha256';

	private const BLOCK = 8388608;

	/**
	 * @param string               $path     The file.
	 * @param array<string, mixed> $progress {offset, context} of the previous call; [] to start.
	 * @param Deadline             $deadline The tick's deadline (at least one block is read).
	 * @param int                  $block    Bytes per read.
	 * @return array{offset: int, context: string, digest: string|null} The digest once the whole file is hashed.
	 * @throws \RuntimeException When the file cannot be read.
	 */
	public static function advance( string $path, array $progress, Deadline $deadline, int $block = self::BLOCK ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- a local archive; a warning would carry its path.
		$handle = is_file( $path ) ? @fopen( $path, 'rb' ) : false;
		if ( false === $handle ) {
			throw new \RuntimeException( 'The archive cannot be read for its checksum.' );
		}
		try {
			[ $offset, $context ] = self::resume( $progress, (int) filesize( $path ) );
			if ( 0 !== fseek( $handle, $offset ) ) {
				throw new \RuntimeException( 'Cannot seek in the archive.' );
			}
			do {
				$data = fread( $handle, max( 1, $block ) );
				if ( false === $data ) {
					throw new \RuntimeException( 'The archive cannot be read for its checksum.' );
				}
				if ( '' === $data ) {
					return [
						'offset'  => $offset,
						'context' => '',
						'digest'  => hash_final( $context ),
					];
				}
				hash_update( $context, $data );
				$offset += strlen( $data );
			} while ( ! $deadline->reached() );
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- opened above.
		}

		return [
			'offset'  => $offset,
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- a HashContext, read back only as one (resume()).
			'context' => base64_encode( serialize( $context ) ),
			'digest'  => null,
		];
	}

	/**
	 * Whether a computed digest is the one the signed view announced.
	 *
	 * @param mixed $expected The announced digest.
	 * @param mixed $actual   The computed digest.
	 */
	public static function same( $expected, $actual ): bool {
		return is_string( $expected ) && is_string( $actual ) && 1 === preg_match( self::PATTERN, $expected ) && hash_equals( $expected, $actual );
	}

	/**
	 * @param array<string, mixed> $progress Saved progress.
	 * @param int                  $size     File size.
	 * @return array{0: int, 1: \HashContext} Where to go on, with which state.
	 */
	private static function resume( array $progress, int $size ): array {
		$offset = $progress['offset'] ?? null;
		$packed = $progress['context'] ?? null;
		if ( is_int( $offset ) && $offset >= 0 && $offset <= $size && is_string( $packed ) ) {
			$raw = base64_decode( $packed, true );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- only a HashContext is accepted; anything else starts again.
			$context = false === $raw ? false : @unserialize( $raw, [ 'allowed_classes' => [ \HashContext::class ] ] );
			if ( $context instanceof \HashContext && self::ALGORITHM === ( $context->__serialize()[0] ?? null ) ) {
				return [ $offset, $context ];
			}
		}

		return [ 0, hash_init( self::ALGORITHM ) ];
	}
}
