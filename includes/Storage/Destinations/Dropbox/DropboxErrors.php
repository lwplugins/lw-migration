<?php
/**
 * The failure kind of a Dropbox answer.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\Dropbox;

use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\HttpFailures;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;

defined( 'ABSPATH' ) || exit;

/**
 * 409 is "endpoint specific" (docs.dropboxapi.com/dropbox-api/docs/error-handling.md):
 * its error_summary names the union tags joined by "/" and padded with
 * dots; the tags are only classified, never shown. Space errors are FULL,
 * too_many_write_operations a passing BUSY, a part that arrived damaged
 * (content_hash_mismatch: not taken) a passing NETWORK, a forgotten, closed or
 * out-of-step upload session SESSION_GONE (one fresh start), a missing
 * path MISSING, a payload over the limit TOO_LARGE, no permission or the
 * account's upload limit DENIED; any other 409 and every other status by
 * status (HttpFailures). Every Dropbox request carries the Bearer token, so
 * a 401 after OAuthHttp's one renewal is REAUTH (P-I2 has no request
 * without it here).
 */
final class DropboxErrors {

	public const LABEL = 'Dropbox';

	private const DENIED = [ 'no_write_permission', 'access_restricted', 'upload_traffic_limit_reached', 'team_folder', 'operation_suppressed' ];

	/**
	 * @param int    $status     HTTP status (not 2xx).
	 * @param string $body       Answer body (only classified).
	 * @param string $retryAfter Retry-After header ('' = none).
	 * @param int    $now        Unix time.
	 * @param bool   $session    The request worked on an upload session.
	 */
	public static function classify( int $status, string $body, string $retryAfter, int $now, bool $session = false ): CloudFailure {
		$tags = 409 === $status ? self::tags( $body ) : [];
		$has  = static fn( string ...$names ): bool => [] !== array_intersect( $names, $tags );
		if ( $has( 'insufficient_space', 'team_folder_insufficient_space', 'member_folder_insufficient_space' ) ) {
			/* translators: %s: provider name */
			return new PermanentFailure( sprintf( __( 'The %s storage is full.', 'lw-migration' ), self::LABEL ), PermanentFailure::FULL, $status );
		}
		if ( $has( 'too_many_write_operations' ) ) {
			/* translators: %s: provider name */
			return new TransientFailure( sprintf( __( '%s asked to slow down; trying again later.', 'lw-migration' ), self::LABEL ), TransientFailure::BUSY, $status, HttpFailures::retryAfter( $retryAfter, $now ) );
		}
		if ( $has( 'content_hash_mismatch' ) ) { // The part arrived damaged and was not taken: send it again (Task 9 m3).
			/* translators: %s: provider name */
			return new TransientFailure( sprintf( __( '%s received a part of the backup damaged; it is sent again.', 'lw-migration' ), self::LABEL ), TransientFailure::NETWORK, $status );
		}
		if ( $session && $has( 'not_found', 'closed', 'incorrect_offset', 'not_closed' ) ) {
			/* translators: %s: provider name */
			return new PermanentFailure( sprintf( __( '%s no longer knows this upload; it starts over.', 'lw-migration' ), self::LABEL ), PermanentFailure::SESSION_GONE, $status );
		}
		if ( $has( 'not_found' ) ) {
			/* translators: %s: provider name */
			return new PermanentFailure( sprintf( __( '%s did not find the file or folder.', 'lw-migration' ), self::LABEL ), PermanentFailure::MISSING, $status );
		}
		if ( $has( 'too_large', 'payload_too_large' ) ) {
			/* translators: %s: provider name */
			return new PermanentFailure( sprintf( __( 'The backup is larger than %s accepts.', 'lw-migration' ), self::LABEL ), PermanentFailure::TOO_LARGE, $status );
		}
		if ( $has( ...self::DENIED ) ) {
			/* translators: %s: provider name */
			return new PermanentFailure( sprintf( __( '%s refused the write (no permission, or the account\'s upload limit is reached).', 'lw-migration' ), self::LABEL ), PermanentFailure::DENIED, $status );
		}

		return HttpFailures::generic( $status, self::LABEL, $retryAfter, $now );
	}

	/**
	 * The union tags of a 409's error_summary ("path/conflict/file/..").
	 *
	 * @param string $body Answer body.
	 * @return array<int, string>
	 */
	public static function tags( string $body ): array {
		$summary = HttpFailures::json( $body )['error_summary'] ?? '';

		return is_string( $summary ) ? array_values( array_filter( explode( '/', $summary ), static fn( string $tag ): bool => 1 === preg_match( '/^[a-z0-9_]{1,64}\z/', $tag ) ) ) : [];
	}
}
