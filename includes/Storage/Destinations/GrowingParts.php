<?php
/**
 * Part sizes of FTP and SFTP uploads.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations;

defined( 'ABSPATH' ) || exit;

/**
 * Plan D29. A part is held in memory, so it starts at 1 MiB, grows towards
 * ChunkPolicy::TARGET_SECONDS a part, never above 32 MiB or a quarter of the
 * memory PHP has left, always whole 64 KiB. UploadSession::with() keeps the
 * part size, so after() builds the next session itself.
 */
final class GrowingParts {

	public const START = 1048576;

	public const MAX = 33554432;

	public const MULTIPLE = 65536;

	/**
	 * @param int|null $free Bytes of memory left (null = no limit known).
	 */
	public static function policy( ?int $free ): ChunkPolicy {
		$max = self::MAX;
		if ( null !== $free ) {
			$max = max( self::MULTIPLE, min( $max, intdiv( max( 0, $free ), 4 ) ) );
		}

		return ChunkPolicy::growing( self::START, $max, self::MULTIPLE );
	}

	/**
	 * @param UploadSession $s       The session before the part.
	 * @param int           $sent    Bytes of the part.
	 * @param float         $seconds How long it took.
	 * @param ChunkPolicy   $policy  The policy.
	 */
	public static function after( UploadSession $s, int $sent, float $seconds, ChunkPolicy $policy ): UploadSession {
		return new UploadSession( $s->type, $s->name, $s->size, $s->offset + $sent, $policy->next( $s->chunk, $seconds ), $s->remote, $s->extra );
	}
}
