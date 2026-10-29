<?php
/**
 * The failure kind of a Google Drive answer.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\GoogleDrive;

use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\HttpFailures;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;

defined( 'ABSPATH' ) || exit;

/**
 * Drive answers {"error":{"errors":[{"reason":…}],"code":…}}
 * (developers.google.com/workspace/drive/api/guides/handle-errors): the
 * reason is only classified, never shown. storageQuotaExceeded is a full
 * storage; rateLimitExceeded and userRateLimitExceeded are a passing limit.
 * Preflight P-I2: an answer to a request sent without Authorization (the
 * upload session address) is never REAUTH — 401, 403, 404 and 410 there
 * mean the session is gone (one fresh start). The rest by status
 * (HttpFailures).
 */
final class DriveErrors {

	public const LABEL = 'Google Drive';

	/**
	 * @param int    $status     HTTP status (not 2xx).
	 * @param string $body       Answer body (only classified).
	 * @param string $retryAfter Retry-After header ('' = none).
	 * @param int    $now        Unix time.
	 * @param bool   $authorized Whether the request carried the Bearer token.
	 */
	public static function classify( int $status, string $body, string $retryAfter, int $now, bool $authorized = true ): CloudFailure {
		$errors = HttpFailures::json( $body )['error']['errors'] ?? null;
		$reason = is_array( $errors ) && is_array( $errors[0] ?? null ) ? ( $errors[0]['reason'] ?? '' ) : '';
		if ( 'storageQuotaExceeded' === $reason ) {
			/* translators: %s: provider name */
			return new PermanentFailure( sprintf( __( 'The %s storage is full.', 'lw-migration' ), self::LABEL ), PermanentFailure::FULL, $status );
		}
		if ( in_array( $reason, [ 'rateLimitExceeded', 'userRateLimitExceeded' ], true ) ) {
			/* translators: %s: provider name */
			return new TransientFailure( sprintf( __( '%s asked to slow down; trying again later.', 'lw-migration' ), self::LABEL ), TransientFailure::BUSY, $status, HttpFailures::retryAfter( $retryAfter, $now ) );
		}
		if ( ! $authorized && in_array( $status, [ 401, 403, 404, 410 ], true ) ) {
			/* translators: %s: provider name */
			return new PermanentFailure( sprintf( __( '%s no longer knows this upload; it starts over.', 'lw-migration' ), self::LABEL ), PermanentFailure::SESSION_GONE, $status );
		}

		return HttpFailures::generic( $status, self::LABEL, $retryAfter, $now );
	}
}
