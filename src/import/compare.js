/**
 * The check view's comparison without React: the archive next to this site,
 * row by row, each marked same / changed / unknown. A value the archive does
 * not tell (an archive of the reference .wpress tool has no versions or
 * prefix) is shown as unknown and never blocks.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { formatBytes } from '../data/format';
import { isLow } from './check';

const known = ( value ) =>
	null !== value && undefined !== value && '' !== value;

const shown = ( value ) =>
	known( value ) ? String( value ) : __( 'unknown', 'lw-migration' );

/**
 * @param {string|null|undefined} archive The archive's value ('' or null = unknown).
 * @param {string|null|undefined} site    This site's value.
 * @return {string} same|changed|unknown.
 */
export function compareState( archive, site ) {
	if ( ! known( archive ) || ! known( site ) ) {
		return 'unknown';
	}
	return String( archive ) === String( site ) ? 'same' : 'changed';
}

/**
 * The address row follows the server's `replacesAddress` (letter case and a
 * trailing slash aside), null = the archive does not name its address.
 *
 * @param {Object} summary Confirm summary.
 * @return {string} same|changed|unknown.
 */
function addressState( summary ) {
	if ( true === summary.replacesAddress ) {
		return 'changed';
	}
	return false === summary.replacesAddress ? 'same' : 'unknown';
}

/**
 * @param {Object} summary Confirm summary.
 * @return {Object} The free-space row's cells.
 */
function spaceCells( summary ) {
	return {
		archive: known( summary.expandedSize )
			? sprintf(
					/* translators: %s: size of the archive's files once unpacked. */
					__( 'about %s expanded', 'lw-migration' ),
					formatBytes( summary.expandedSize )
				)
			: __( 'expanded size unknown', 'lw-migration' ),
		site: known( summary.diskFree )
			? sprintf(
					/* translators: %s: free disk space on this site. */
					__( '%s free', 'lw-migration' ),
					formatBytes( summary.diskFree )
				)
			: __( 'free space unknown', 'lw-migration' ),
	};
}

/**
 * The rows of the comparison, in page order: address (for a site restore
 * into a network, each site's own row follows), WordPress, PHP, table
 * prefix (the restored tables always get this site's), free space and
 * encryption (these two compare nothing: state `info`).
 *
 * @param {Object}  summary Confirm summary.
 * @param {boolean} safety  The safety-backup switch (the space it needs).
 * @return {Object[]} `{ id, label, archive, site, state, warn }`.
 */
export function factRows( summary, safety = summary.safetyBackup ) {
	const prefix = summary.prefix || {};
	// A site restore into a network moves each site to its own address.
	const perSite =
		Boolean( summary.network ) && 'network' !== summary.network.kind;
	return [
		{
			id: 'address',
			label: __( 'Address', 'lw-migration' ),
			archive: shown( summary.from ),
			...( perSite
				? {
						site: __(
							'each site gets its own below',
							'lw-migration'
						),
						state: 'info',
					}
				: { site: summary.to, state: addressState( summary ) } ),
		},
		{
			id: 'wordpress',
			label: 'WordPress',
			archive: shown( summary.wordpress?.archive ),
			site: shown( summary.wordpress?.site ),
			state: compareState(
				summary.wordpress?.archive,
				summary.wordpress?.site
			),
		},
		{
			id: 'php',
			label: 'PHP',
			archive: shown( summary.php?.archive ),
			site: shown( summary.php?.site ),
			state: compareState( summary.php?.archive, summary.php?.site ),
		},
		{
			id: 'prefix',
			label: __( 'Table prefix', 'lw-migration' ),
			archive: shown( prefix.archive ),
			site: sprintf(
				/* translators: %s: this site's database table prefix, e.g. wp_. */
				__( '%s (stays)', 'lw-migration' ),
				prefix.site || ''
			),
			state: compareState( prefix.archive, prefix.site ),
		},
		{
			id: 'space',
			label: __( 'Free space', 'lw-migration' ),
			...spaceCells( summary ),
			state: 'info',
			warn: isLow( summary, safety ),
		},
		{
			id: 'encryption',
			label: __( 'Encryption', 'lw-migration' ),
			archive: summary.encrypted
				? __( 'encrypted, password accepted', 'lw-migration' )
				: __( 'none', 'lw-migration' ),
			site: '',
			state: 'info',
		},
	].map( ( fact ) => ( { warn: false, ...fact } ) );
}
