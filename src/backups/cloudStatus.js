/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

const TONES = { up: 'ok', failed: 'critical', pending: 'info' };

/**
 * Where a backup was uploaded (BackupsApi item `cloud`), with the
 * destinations' names; a destination deleted since keeps its id as name.
 *
 * @param {Object}   item         Backup row.
 * @param {Object[]} destinations Destination views.
 * @return {Object[]} `{ id, name, destination, state, tone, label }`;
 *                    destination null when it was deleted since.
 */
export function cloudBadges( item, destinations ) {
	return ( item.cloud || [] ).map( ( copy ) => {
		const destination =
			destinations.find( ( d ) => d.id === copy.id ) || null;
		return {
			id: copy.id,
			name: destination?.name || copy.id,
			destination,
			state: copy.status,
			tone: TONES[ copy.status ] || 'idle',
			label:
				{
					up: __( 'uploaded', 'lw-migration' ),
					failed: __( 'upload failed', 'lw-migration' ),
					pending: __( 'uploading', 'lw-migration' ),
				}[ copy.status ] || copy.status,
		};
	} );
}

/**
 * The state word of one destination of a job or a run (CloudOutcome rows:
 * ok|failed|skipped, or pending when the job ended before it was reached).
 *
 * @param {string} status Row status.
 * @return {string} Label.
 */
export const cloudStateLabel = ( status ) =>
	( {
		ok: __( 'uploaded', 'lw-migration' ),
		failed: __( 'failed', 'lw-migration' ),
		skipped: __( 'skipped', 'lw-migration' ),
		pending: __( 'not uploaded', 'lw-migration' ),
	} )[ status ] || status;

/**
 * A run log's line for a destination that did not end `ok`: its reason, or
 * its state word when it has none.
 *
 * @param {Object} row Log entry `cloud` row `{ name, status, error }`.
 * @return {string} Text.
 */
export const cloudLogText = ( row ) =>
	`${ row.name }: ${ row.error || cloudStateLabel( row.status ) }`;

/**
 * A job's uploads ended partly (CloudOutcome::partial(), spec 21.1): some
 * destination of its `result.cloud` did not end `ok`.
 *
 * @param {Object[]|undefined} rows Job view `result.cloud`.
 * @return {boolean} Not every upload succeeded.
 */
export const notAllUploaded = ( rows ) =>
	( rows || [] ).some( ( row ) => 'ok' !== row.status );

/**
 * A destination's line under its name where one is chosen (DestinationView
 * `where`); one that sends backups unencrypted says so (spec 21.4, plan
 * D37: plain http WebDAV/S3, plain FTP).
 *
 * @param {Object} destination Destination view.
 * @return {string} Text.
 */
export const whereText = ( destination ) =>
	destination.insecure
		? `${ destination.where } · ${ __( 'unencrypted', 'lw-migration' ) }`
		: destination.where;

/**
 * Whether a Google Drive, Dropbox or OneDrive destination still waits for
 * its account (DestinationView `connection.connected`): an upload there would
 * only fail, so the pickers offer it disabled.
 *
 * @param {Object} destination Destination view.
 * @return {boolean} True until an account is connected.
 */
export const awaitsConnect = ( destination ) =>
	Boolean( destination.connection ) && ! destination.connection.connected;

/**
 * The line under a destination in the upload pickers: whereText(), and for
 * one without an account, where to connect it.
 *
 * @param {Object} destination Destination view.
 * @return {string} Text.
 */
export const choiceText = ( destination ) =>
	awaitsConnect( destination )
		? `${ whereText( destination ) } · ${ __(
				'connect it first in Destinations',
				'lw-migration'
			) }`
		: whereText( destination );
