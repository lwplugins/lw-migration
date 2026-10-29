/**
 * The Restore section's source step without React: what is kept while the
 * check runs (App holds it, so Back from the check returns to the same
 * source with the same file, address or choice), which destination the
 * cloud source reads, and when the check may start.
 */

/**
 * Internal dependencies
 */
import { awaitsConnect } from '../backups/cloudStatus';
import { isReady } from '../views/importRequest';

/**
 * The source step's state: the tile, the chosen file, the typed address,
 * the wanted backup `{ folder, name }`, the cloud destination and file.
 */
export const EMPTY_DRAFT = {
	source: 'backup',
	file: null,
	url: '',
	wanted: null,
	cloudId: '',
	cloudName: '',
};

/**
 * The destination the cloud source reads: the chosen one, else the first
 * one with a working account; never one that waits for its account.
 *
 * @param {Object[]} items Destination views.
 * @param {string}   id    Chosen destination id ('' = none).
 * @return {Object|null} Destination.
 */
export function pickDestination( items, id ) {
	const usable = items.filter( ( item ) => ! awaitsConnect( item ) );
	return usable.find( ( item ) => item.id === id ) || usable[ 0 ] || null;
}

/**
 * @param {string}      source      backup|upload|url|cloud.
 * @param {Object}      draft       EMPTY_DRAFT shape.
 * @param {Object|null} destination pickDestination() (cloud only).
 * @param {Object|null} backup      The chosen list entry (backup only).
 * @return {boolean} The chosen source is filled in.
 */
export function readyToCheck(
	source,
	draft,
	destination = null,
	backup = null
) {
	if ( 'cloud' === source ) {
		return Boolean( destination && draft.cloudName );
	}
	return isReady( source, { file: draft.file, url: draft.url, backup } );
}
