/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { formatBytes } from '../data/format';
import { typeText } from '../components/DestinationIcon';

/**
 * The size the summary dock leads with: what is known to go in, "at least"
 * while a part that goes in is not counted or still growing, "before
 * compression" when the file is compressed (the estimate measures nothing
 * compressed), "whole network" when only chosen sites go in but the estimate
 * is the whole network's; '' without a figure.
 *
 * @param {Object}  comp         composition() result.
 * @param {string}  compression  'none', 'gzip' or 'bzip2'.
 * @param {boolean} wholeNetwork The size is the whole network's, not the chosen sites'.
 * @return {string} Text.
 */
export function sizeLead( comp, compression, wholeNetwork = false ) {
	if ( ! comp?.known ) {
		return '';
	}
	let size = formatBytes( comp.total );
	if ( comp.missing ) {
		/* translators: %s: size counted so far, e.g. 1.2 GB. */
		size = sprintf( __( 'at least %s', 'lw-migration' ), size );
	}
	if ( 'none' !== compression ) {
		/* translators: %s: size of what goes into the backup, e.g. 1.7 GB. */
		size = sprintf( __( '%s before compression', 'lw-migration' ), size );
	}
	return wholeNetwork
		? /* translators: %s: size of the whole network's backup, e.g. 1.7 GB. */
			sprintf( __( '%s (whole network)', 'lw-migration' ), size )
		: size;
}

/**
 * Where the backup goes and how the file is made, for the summary dock:
 * "backups folder + Google Drive, SFTP · gzip · encrypted".
 *
 * @param {Object}   props
 * @param {Object[]} props.chosen      The ticked destination views.
 * @param {boolean}  props.keepLocal   The local copy stays after the uploads.
 * @param {string}   props.compression 'none', 'gzip' or 'bzip2'.
 * @param {boolean}  props.encrypt     Password protected.
 * @return {string} Text.
 */
export function routeText( { chosen, keepLocal, compression, encrypt } ) {
	let where = __( 'the backups folder only', 'lw-migration' );
	if ( chosen.length ) {
		const folder = keepLocal
			? __( 'backups folder', 'lw-migration' )
			: __(
					'backups folder (deleted after the uploads)',
					'lw-migration'
				);
		const names = chosen.map( ( d ) => d.name || typeText( d ) );
		where = `${ folder } + ${ names.join( ', ' ) }`;
	}
	return [
		where,
		'none' === compression ? '' : compression,
		encrypt ? __( 'encrypted', 'lw-migration' ) : '',
	]
		.filter( Boolean )
		.join( ' \u00B7 ' );
}
