/**
 * The Backups section without React: the archive entries paging and what a
 * refused action means for the screen. Only flags and codes are read, never
 * a translated text.
 */

/**
 * Internal dependencies
 */
import { LOGGED_OUT, classify, invalidField } from '../data/errors';
import { refusalText } from '../data/refusal';

/** Nothing read yet. */
export const NO_ENTRIES = { entries: [], next: null, encrypted: false };

/**
 * The list after one more page of `GET /backups/entries`: entries appended,
 * `next` (the header offset to ask for with `after`) and `encrypted` taken
 * from the newest page.
 *
 * @param {Object} list Current list.
 * @param {Object} page Page { entries, next, encrypted }.
 * @return {Object} New list.
 */
export const appendEntries = ( list, page ) => ( {
	entries: [
		...list.entries,
		...( Array.isArray( page?.entries ) ? page.entries : [] ),
	],
	next: Number.isInteger( page?.next ) ? page.next : null,
	encrypted: true === page?.encrypted,
} );

/**
 * @param {Object} list List.
 * @return {boolean} Whether the archive has entries after the shown ones.
 */
export const hasMoreEntries = ( list ) => Number.isInteger( list.next );

/**
 * What a refused backup action (download, label, contents, delete) means:
 * `loggedOut` → offer the login link; `text` → our hint or the server's
 * exact refusal at the control (`lw_migration_invalid`), '' = show the
 * general message; `gone` → the backup is not there any more, read the list
 * again.
 *
 * @param {Object} error Rejection of request().
 * @param {string} hint  Our text for the refused control ('' = none).
 * @return {{loggedOut: boolean, text: string, gone: boolean}} Problem.
 */
export function backupProblem( error, hint = '' ) {
	return {
		loggedOut: LOGGED_OUT === classify( error ),
		text: invalidField( error ) ? refusalText( error, hint ) : '',
		gone: 'lw_migration_backup_missing' === error?.code,
	};
}
