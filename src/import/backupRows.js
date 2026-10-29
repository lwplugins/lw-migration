/**
 * The restore picker's backup rows without React: how a backup was made
 * (read from its name, like Backups\BackupName) and its tags — uploaded to
 * a destination too, encrypted, made on another site, in the older .wpress
 * folder (`GET /backups` items).
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { typeText } from '../components/DestinationIcon';
import { OWN_FOLDER } from '../data/boot';

// BackupName::SCHEDULE_TIME_PATTERN and SITE_TIME_PATTERN.
const SCHEDULED = /-\d{8}-\d{6}-\d{3}-[a-f0-9]{8}\.wpress$/;
const MANUAL = /-\d{8}-\d{6}-\d{3}\.wpress$/;

/**
 * @param {Object} item Item of `GET /backups`.
 * @return {string} reset|safety|scheduled|manual, '' for a name another tool gave.
 */
export function backupKind( item ) {
	if ( true === item.beforeReset ) {
		return 'reset';
	}
	if ( true === item.safety ) {
		return 'safety';
	}
	if ( SCHEDULED.test( item.name ) ) {
		return 'scheduled';
	}
	return MANUAL.test( item.name ) ? 'manual' : '';
}

/**
 * @param {string} kind backupKind().
 * @return {string} Its word in the row's meta line.
 */
export const kindText = ( kind ) =>
	( {
		reset: __( 'before a reset', 'lw-migration' ),
		safety: __( 'before a restore', 'lw-migration' ),
		scheduled: __( 'scheduled', 'lw-migration' ),
		manual: __( 'manual', 'lw-migration' ),
	} )[ kind ] || '';

/**
 * The row's tags, in this order: each destination it was uploaded to (that
 * still exists), encrypted, another site, the older .wpress folder.
 *
 * @param {Object}   item         Item of `GET /backups`.
 * @param {Object[]} destinations Destination views.
 * @return {Object[]} `{ key, kind, text, destination? }`.
 */
export function backupTags( item, destinations ) {
	const tags = ( item.cloud || [] )
		.filter( ( copy ) => 'up' === copy.status )
		.map( ( copy ) => destinations.find( ( d ) => d.id === copy.id ) )
		.filter( Boolean )
		.map( ( destination ) => ( {
			key: `cloud-${ destination.id }`,
			kind: 'cloud',
			text: sprintf(
				/* translators: %s: destination type, e.g. Google Drive. */
				__( 'Also at %s', 'lw-migration' ),
				typeText( destination )
			),
			destination,
		} ) );
	if ( true === item.encrypted ) {
		tags.push( {
			key: 'lock',
			kind: 'lock',
			text: __( 'Encrypted', 'lw-migration' ),
		} );
	}
	if ( item.fromSite ) {
		tags.push( {
			key: 'site',
			kind: 'site',
			text: __( 'Another site', 'lw-migration' ),
		} );
	}
	if ( item.folder && OWN_FOLDER !== item.folder ) {
		tags.push( {
			key: 'folder',
			kind: 'folder',
			text: __( 'Older .wpress folder', 'lw-migration' ),
		} );
	}
	return tags;
}

/**
 * @param {string}   name  A backup's file name at a destination.
 * @param {Object[]} items Items of `GET /backups`.
 * @return {boolean} A backup of that name is in the backups list here.
 */
export const alsoHere = ( name, items ) =>
	items.some( ( item ) => item.name === name );
