/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Pure helpers of the transfer-link dialog (ShareModal).
 */

/** Seconds a new link may live: an hour, a day, a week. */
export const EXPIRY_OPTIONS = [ 3600, 86400, 604800 ];

/** The default choice: a day (the server's default too). */
export const DEFAULT_EXPIRY = 86400;

/**
 * @return {Object} { seconds: label } for the segmented control.
 */
export const expiryOptions = () => ( {
	3600: __( '1 hour', 'lw-migration' ),
	86400: __( '24 hours', 'lw-migration' ),
	604800: __( '7 days', 'lw-migration' ),
} );

/**
 * The links of one backup that still work, the one that lives longest first.
 *
 * @param {Array|undefined} items  Items of GET /backups/shares.
 * @param {Object}          backup { folder, name }.
 * @param {number}          now    Unix seconds.
 * @return {Array} Items.
 */
export const liveLinksFor = ( items, backup, now ) =>
	( items || [] )
		.filter(
			( item ) =>
				item.folder === backup.folder &&
				item.name === backup.name &&
				! item.expired &&
				item.expiresAt > now
		)
		.sort( ( a, b ) => b.expiresAt - a.expiresAt );

/**
 * "never used" / "used 3 times, last today 14:02".
 *
 * @param {Object}                 item   A link.
 * @param {(ts: number) => string} format Writes a time.
 * @return {string} Text.
 */
export function usesLabel( item, format ) {
	if ( ! item.uses && ! item.lastUsedAt ) {
		return __( 'never used', 'lw-migration' );
	}
	return sprintf(
		/* translators: 1: number of downloads, 2: date and time of the last one. */
		_n(
			'used %1$d time, last %2$s',
			'used %1$d times, last %2$s',
			item.uses,
			'lw-migration'
		),
		item.uses,
		format( item.lastUsedAt )
	);
}
