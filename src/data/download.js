/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * What a refused HEAD check of a download link means (ruling P1): null =
 * ask for a new link and check again (a first 401/403); otherwise the
 * rejection to throw, shaped like request()'s, so classify() reads it (a
 * second 401 = logged out, a 404 = the backup is gone).
 *
 * @param {number} status  HTTP status of the HEAD request.
 * @param {number} attempt 1-based attempt.
 * @return {Object|null} Rejection, or null to try a new link.
 */
export function headRefusal( status, attempt ) {
	const refused = 401 === status || 403 === status;
	if ( refused && attempt < 2 ) {
		return null;
	}
	if ( refused ) {
		return {
			status,
			code: 'lw_migration_download_refused',
			message: __(
				'The download link was refused. Reload the page and try again.',
				'lw-migration'
			),
			data: {},
		};
	}
	if ( 404 === status ) {
		return {
			status,
			code: 'lw_migration_backup_missing',
			message: __(
				'This backup does not exist (any more).',
				'lw-migration'
			),
			data: {},
		};
	}
	return {
		status,
		code: `http_${ status }`,
		message: sprintf(
			/* translators: %d: HTTP status code, e.g. 502. */
			__( 'The server answered with error %d.', 'lw-migration' ),
			status
		),
		data: {},
	};
}
