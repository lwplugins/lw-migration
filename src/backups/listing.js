/**
 * The Backups list without React: the items of `GET /backups` as rows, the
 * filter chips with their counts, the search, and the day groups (in the
 * site's time zone).
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { OWN_FOLDER } from '../data/boot';
import { formatSiteTime, siteTimeFormatter } from '../data/format';

/** BackupsApi item `kind`, in the order of the filter chips. */
export const KINDS = [
	'scheduled',
	'manual',
	'safetyRestore',
	'safetyReset',
	'other',
];

/** Days back that still get a group of their own; older ones share one. */
export const RECENT_DAYS = 7;

/**
 * The kind of an item that has none (an older server): from its folder and
 * its safety flags.
 *
 * @param {Object} item Item of `GET /backups`.
 * @return {string} One of KINDS.
 */
function kindOf( item ) {
	if ( KINDS.includes( item.kind ) ) {
		return item.kind;
	}
	if ( item.folder && OWN_FOLDER !== item.folder ) {
		return 'other';
	}
	if ( true === item.beforeReset ) {
		return 'safetyReset';
	}
	return true === item.safety ? 'safetyRestore' : 'manual';
}

/**
 * Rows from `GET /backups` items, the newest first.
 *
 * @param {Array|undefined} items Items.
 * @return {Object[]} Rows (label always text, links a number, cloud a list).
 */
export const toItems = ( items ) =>
	( Array.isArray( items ) ? items : [] )
		.map( ( item ) => ( {
			...item,
			label: 'string' === typeof item.label ? item.label : '',
			kind: kindOf( item ),
			links: Math.max( 0, Number( item.links ) || 0 ),
			cloud: Array.isArray( item.cloud ) ? item.cloud : [],
		} ) )
		.sort(
			( a, b ) => ( Number( b.mtime ) || 0 ) - ( Number( a.mtime ) || 0 )
		);

/**
 * @param {string} kind One of KINDS.
 * @return {string} Its tag in a row and its filter chip.
 */
export const kindText = ( kind ) =>
	( {
		scheduled: __( 'Scheduled', 'lw-migration' ),
		manual: __( 'Manual', 'lw-migration' ),
		safetyRestore: __( 'Before a restore', 'lw-migration' ),
		safetyReset: __( 'Before a reset', 'lw-migration' ),
		other: __( "The other .wpress tool's folder", 'lw-migration' ),
	} )[ kind ] || '';

/**
 * The filter chips: All, then each kind that has backups (a chip that would
 * show nothing is left out).
 *
 * @param {Object[]} items Rows.
 * @return {Object[]} `{ id, count }`; id `all` or a kind.
 */
export function chips( items ) {
	const counts = Object.fromEntries( KINDS.map( ( kind ) => [ kind, 0 ] ) );
	items.forEach( ( item ) => counts[ item.kind ]++ );
	return [
		{ id: 'all', count: items.length },
		...KINDS.filter( ( kind ) => counts[ kind ] > 0 ).map( ( kind ) => ( {
			id: kind,
			count: counts[ kind ],
		} ) ),
	];
}

/**
 * The rows a chip and a search show: the search matches the file name and
 * the label, case-insensitively.
 *
 * @param {Object[]} items  Rows.
 * @param {string}   filter `all` or a kind.
 * @param {string}   query  Typed search.
 * @return {Object[]} Rows.
 */
export function visibleItems( items, filter, query ) {
	const needle = String( query || '' )
		.trim()
		.toLocaleLowerCase();
	return items.filter(
		( item ) =>
			( 'all' === filter || item.kind === filter ) &&
			( ! needle ||
				`${ item.name } ${ item.label }`
					.toLocaleLowerCase()
					.includes( needle ) )
	);
}

/**
 * Counts site days: one formatter for every call (a long list asks for
 * hundreds of days).
 *
 * @return {(ts: number) => number} Unix seconds → days since 1970-01-01 (site calendar).
 */
function siteDays() {
	const format = siteTimeFormatter(
		{ year: 'numeric', month: 'numeric', day: 'numeric' },
		'en-US'
	);
	return ( ts ) => {
		const [ month, day, year ] = format( ts ).split( '/' ).map( Number );
		return Math.round( Date.UTC( year, month - 1, day ) / 86400000 );
	};
}

/**
 * How many site days before `now` a backup was made (0 = today; a time
 * ahead of the clock counts as today).
 *
 * @param {number} ts  Unix seconds.
 * @param {number} now Unix seconds.
 * @return {number} Days.
 */
export const daysAgo = ( ts, now ) => {
	const day = siteDays();
	return Math.max( 0, day( now ) - day( ts ) );
};

/**
 * @param {number} days daysAgo().
 * @param {number} ts   Unix seconds of one backup of the group.
 * @return {string} The group's title: Today, Yesterday, the day, or Older.
 */
function dayTitle( days, ts ) {
	if ( 0 === days ) {
		return __( 'Today', 'lw-migration' );
	}
	if ( 1 === days ) {
		return __( 'Yesterday', 'lw-migration' );
	}
	return days < RECENT_DAYS
		? formatSiteTime( ts, { month: 'short', day: 'numeric' } )
		: __( 'Older', 'lw-migration' );
}

/**
 * Rows grouped by the site day they were made, the order kept: today,
 * yesterday, each day of the last week, then one group for the rest.
 *
 * @param {Object[]} items Rows, the newest first.
 * @param {number}   now   Unix seconds.
 * @return {Object[]} `{ key, title, recent, items }`.
 */
export function dayGroups( items, now ) {
	const day = siteDays();
	const today = day( now );
	const groups = [];
	items.forEach( ( item ) => {
		const days = Math.min(
			Math.max( 0, today - day( Number( item.mtime ) || 0 ) ),
			RECENT_DAYS
		);
		const last = groups[ groups.length - 1 ];
		if ( last && last.days === days ) {
			last.items.push( item );
			return;
		}
		groups.push( {
			key: `day-${ days }`,
			days,
			title: dayTitle( days, Number( item.mtime ) || 0 ),
			recent: days < RECENT_DAYS,
			items: [ item ],
		} );
	} );
	return groups;
}

/**
 * The row's time: the clock time in a recent group, the day in Older.
 *
 * @param {number}  ts     Unix seconds.
 * @param {boolean} recent The row is in a day group of its own.
 * @return {string} Text.
 */
export const whenText = ( ts, recent ) =>
	recent
		? formatSiteTime( ts, { hour: '2-digit', minute: '2-digit' } )
		: formatSiteTime( ts, {
				year: 'numeric',
				month: 'short',
				day: 'numeric',
			} );
