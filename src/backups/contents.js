/**
 * The detail panel's Contents without React: the parts of one backup from
 * `GET /backups/contents`, the largest first, each with its share of the
 * archive for the bar; when to ask again.
 */

/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { formatNumber } from '../data/format';

/** Parts of the answer, in the order they are shown when sizes tie. */
export const PART_IDS = [
	'media',
	'plugins',
	'database',
	'themes',
	'muPlugins',
];

/** Milliseconds before an unfinished read (`partial: true`) is asked again. */
export const POLL_MS = 2500;

/**
 * @param {Object|null} answer Body of `GET /backups/contents`.
 * @return {boolean} The walk has not reached the end yet: ask again.
 */
export const readsOn = ( answer ) => true === answer?.partial;

/**
 * @param {string} id Part key.
 * @return {string} Its name.
 */
export const partTitle = ( id ) =>
	( {
		media: __( 'Media Library', 'lw-migration' ),
		plugins: __( 'Plugins', 'lw-migration' ),
		database: __( 'Database', 'lw-migration' ),
		themes: __( 'Themes', 'lw-migration' ),
		muPlugins: __( 'Must-use plugins', 'lw-migration' ),
	} )[ id ] || id;

/**
 * @param {string}      id    Part key.
 * @param {number|null} count The answer's count.
 * @return {string} How many there are ('' for the database or unknown).
 */
export function countText( id, count ) {
	if ( ! Number.isFinite( count ) || 'database' === id ) {
		return '';
	}
	const n = formatNumber( count );
	if ( 'media' === id ) {
		return sprintf(
			/* translators: %s: number of media files. */
			_n( '%s file', '%s files', count, 'lw-migration' ),
			n
		);
	}
	if ( 'themes' === id ) {
		return sprintf(
			/* translators: %s: number of themes. */
			_n( '%s theme', '%s themes', count, 'lw-migration' ),
			n
		);
	}
	return sprintf(
		/* translators: %s: number of plugins. */
		_n( '%s plugin', '%s plugins', count, 'lw-migration' ),
		n
	);
}

const sizeOf = ( value ) =>
	Number.isFinite( value ) ? Math.max( 0, value ) : null;

/**
 * The shown rows: the parts the backup holds (largest first, `share` of
 * the known sizes 0–1 for the bar), the unknown ones after them (`bytes`
 * null), and the ids of the parts it leaves out.
 *
 * @param {Object} answer Body of `GET /backups/contents` (finished).
 * @return {{rows: Object[], absent: string[]}} `rows`: `{ id, bytes, count, share }`.
 */
export function contentRows( answer ) {
	const parts = PART_IDS.map( ( id ) => ( {
		id,
		included: answer?.parts?.[ id ]?.included ?? null,
		bytes: sizeOf( answer?.parts?.[ id ]?.bytes ),
		count: sizeOf( answer?.parts?.[ id ]?.count ),
	} ) );
	const known = parts.filter(
		( part ) => true === part.included && null !== part.bytes
	);
	const total = known.reduce( ( sum, part ) => sum + part.bytes, 0 );
	const unknown = parts.filter(
		( part ) =>
			null === part.included ||
			( true === part.included && null === part.bytes )
	);
	return {
		rows: [
			...[ ...known ]
				.sort( ( a, b ) => b.bytes - a.bytes )
				.map( ( part ) => ( {
					id: part.id,
					bytes: part.bytes,
					count: part.count,
					share: total > 0 ? part.bytes / total : 0,
				} ) ),
			...unknown.map( ( part ) => ( {
				id: part.id,
				bytes: null,
				count: null,
				share: 0,
			} ) ),
		],
		absent: parts
			.filter( ( part ) => false === part.included )
			.map( ( part ) => part.id ),
	};
}
