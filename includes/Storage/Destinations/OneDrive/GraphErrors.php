<?php
/**
 * The failure kind of a Microsoft Graph answer.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\OneDrive;

use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\HttpFailures;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;

defined( 'ABSPATH' ) || exit;

/**
 * Graph answers {"error":{"code":…,"message":…}}
 * (learn.microsoft.com/en-us/onedrive/developer/rest-api/concepts/errors,
 * read 2026-10-14): the code is only classified, the message never read.
 * quotaLimitReached (and 507) is a full storage; activityLimitReached and
 * 509 a passing limit. Preflight P-I2: the upload address and the download
 * address are sent without Authorization, so a refusal there is never
 * REAUTH — at the upload address 401, 403, 404 and 410 mean the session is
 * gone (one fresh start); at a download address the same statuses mean it
 * expired (the item was just found; the next read asks for a new one). The
 * rest by status (HttpFailures).
 */
final class GraphErrors {

	public const LABEL = 'OneDrive';

	/** A request to Graph with the Bearer token. */
	public const API = 'api';

	/** A request to the upload session address. */
	public const UPLOAD = 'upload';

	/** A request to a download address. */
	public const DOWNLOAD = 'download';

	/**
	 * @param int    $status     HTTP status (not 2xx).
	 * @param string $body       Answer body (only classified).
	 * @param string $retryAfter Retry-After header ('' = none).
	 * @param int    $now        Unix time.
	 * @param string $where      API, UPLOAD or DOWNLOAD.
	 */
	public static function classify( int $status, string $body, string $retryAfter, int $now, string $where = self::API ): CloudFailure {
		$code = HttpFailures::json( $body )['error']['code'] ?? '';
		if ( 'quotaLimitReached' === $code ) {
			return HttpFailures::generic( 507, self::LABEL, $retryAfter, $now );
		}
		if ( 'activityLimitReached' === $code || 509 === $status ) {
			/* translators: %s: provider name */
			return new TransientFailure( sprintf( __( '%s asked to slow down; trying again later.', 'lw-migration' ), self::LABEL ), TransientFailure::BUSY, $status, HttpFailures::retryAfter( $retryAfter, $now ) );
		}
		if ( self::UPLOAD === $where && in_array( $status, [ 401, 403, 404, 410 ], true ) ) {
			/* translators: %s: provider name */
			return new PermanentFailure( sprintf( __( '%s no longer knows this upload; it starts over.', 'lw-migration' ), self::LABEL ), PermanentFailure::SESSION_GONE, $status );
		}
		if ( self::DOWNLOAD === $where && in_array( $status, [ 401, 403, 404, 410 ], true ) ) { // The /content 302 just named it: it expired (review m4).
			/* translators: %s: provider name */
			return new TransientFailure( sprintf( __( 'The download address %s gave has expired; trying again.', 'lw-migration' ), self::LABEL ), TransientFailure::SERVER, $status );
		}
		if ( 409 === $status ) {
			/* translators: %s: provider name */
			return new PermanentFailure( sprintf( __( '%s refused the request because of a conflicting file.', 'lw-migration' ), self::LABEL ), PermanentFailure::INVALID, 409 );
		}

		return HttpFailures::generic( $status, self::LABEL, $retryAfter, $now );
	}
}
