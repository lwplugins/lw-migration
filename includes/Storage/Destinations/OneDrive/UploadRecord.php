<?php
/**
 * What a OneDrive upload session keeps in the job's cursor.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\OneDrive;

use LightweightPlugins\Migration\Storage\Destinations\OAuth\ProviderHttp;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\UploadSession;

defined( 'ABSPATH' ) || exit;

/**
 * The session record (Task 10 review m5): `remote` is the uploadUrl, `extra`
 * is { held, itemId, xor } — what OneDrive holds (the offset, unless a
 * status answer said more), the file's id once complete, and the
 * quickXorHash fold of the confirmed bytes (base64, QuickXorHash::WIDTH
 * bytes). H6 / P-I3: a stored session of another type, with a foreign
 * address, a fragment size that is not a whole multiple up to MAX, or other
 * extras starts over before any request.
 */
final class UploadRecord {

	public const MULTIPLE = 327680;

	/** Largest fragment (E10; a request must stay under 60 MiB). */
	public const MAX = 31457280;

	private const EXTRA = [ 'held', 'itemId', 'xor' ];

	/**
	 * @param UploadSession $s Stored session.
	 * @throws PermanentFailure SESSION_GONE when it is not one of ours.
	 */
	public static function assertOwn( UploadSession $s ): void {
		$extra = $s->extra;
		ksort( $extra );
		$xor  = is_string( $extra['xor'] ?? null ) ? base64_decode( $extra['xor'], true ) : false;
		$id   = $extra['itemId'] ?? null;
		$held = $extra['held'] ?? null;
		$fits = self::EXTRA === array_keys( $extra ) && false !== $xor && QuickXorHash::WIDTH === strlen( $xor )
			&& is_string( $id ) && ( '' === $id || 1 === preg_match( OneDriveDestination::ID_PATTERN, $id ) )
			&& is_int( $held ) && $held >= $s->offset && $held <= min( $s->size, $s->offset + $s->chunk );
		if ( OneDriveDestination::TYPE !== $s->type || ! OneDriveDestination::isFileName( $s->name ) || $s->chunk < 1 || 0 !== $s->chunk % self::MULTIPLE || $s->chunk > self::MAX || ! self::isProviderAddress( $s->remote ) || ! $fits ) {
			/* translators: %s: provider name */
			throw new PermanentFailure( sprintf( __( 'The record of this upload to %s does not fit; the upload starts over.', 'lw-migration' ), GraphErrors::LABEL ), PermanentFailure::SESSION_GONE );
		}
	}

	/**
	 * @param int    $held   What OneDrive holds (= the offset unless a status answer said more).
	 * @param string $itemId The file's id once complete.
	 * @param string $fold   QuickXorHash fold of the confirmed bytes.
	 * @return array{held: int, itemId: string, xor: string}
	 */
	public static function extra( int $held, string $itemId, string $fold ): array {
		return [
			'held'   => $held,
			'itemId' => $itemId,
			'xor'    => base64_encode( $fold ),
		];
	}

	/**
	 * @param UploadSession $s A session of ours (assertOwn() checked the fold).
	 * @return string The hash fold of its confirmed bytes.
	 */
	public static function fold( UploadSession $s ): string {
		return (string) base64_decode( (string) $s->extra['xor'], true );
	}

	/**
	 * P-C2: an https address of a DNS name on port 443, without userinfo,
	 * fragment or whitespace — an uploadUrl or a download Location (the host
	 * set is not documented).
	 *
	 * @param string $url Address.
	 */
	public static function isProviderAddress( #[\SensitiveParameter] string $url ): bool {
		if ( ! str_starts_with( $url, 'https://' ) || strlen( $url ) > 4096 ) {
			return false;
		}
		try {
			ProviderHttp::host( $url, GraphErrors::LABEL );
		} catch ( PermanentFailure $e ) {
			return false;
		}

		return true;
	}
}
