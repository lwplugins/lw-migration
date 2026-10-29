/**
 * The week ruler's geometry, without React: seven local days from today's
 * midnight, where a moment falls on them, and two switched-on schedules
 * due in the same minute. The run times are the server's (`week` of each
 * item); nothing here computes a run.
 */

/**
 * Internal dependencies
 */
import { dayStart, zoneOf } from './zone';

/** Days on the ruler. */
export const DAYS = 7;

/** Lane colours (schedules.scss `$lanes`); the next schedule takes the next. */
export const LANES = 4;

/**
 * @param {number} index Position of the schedule in the list.
 * @return {number} Its lane colour, 0 … LANES - 1.
 */
export const laneOf = ( index ) => index % LANES;

/**
 * The start of today and of each following day, in the site's time zone:
 * DAYS + 1 moments (the last one closes the week). A DST day is 23 or 25
 * hours long; each still gets one column.
 *
 * @param {number} now      Unix seconds.
 * @param {string} timezone The site's time zone.
 * @return {number[]} Unix seconds.
 */
export function weekStarts( now, timezone ) {
	const zone = zoneOf( timezone );
	return Array.from( { length: DAYS + 1 }, ( _, day ) =>
		dayStart( now, zone, day )
	);
}

/**
 * Where a moment sits on the ruler: its day's column plus the share of that
 * day gone by.
 *
 * @param {number}   ts     Unix seconds.
 * @param {number[]} starts weekStarts().
 * @return {number|null} Percent from the left (0-100), null outside the week.
 */
export function position( ts, starts ) {
	for ( let day = 0; day < starts.length - 1; day++ ) {
		const from = starts[ day ];
		const to = starts[ day + 1 ];
		if ( ts >= from && ts < to ) {
			return ( ( day + ( ts - from ) / ( to - from ) ) / DAYS ) * 100;
		}
	}
	return null;
}

/**
 * The first minute two or more switched-on schedules are due together
 * (each schedule counted once). They never run at once: due runs wait in a
 * queue, so the later one waits for the earlier.
 *
 * @param {Object[]} items Schedule items (`enabled`, `title`, `week`).
 * @return {{at: number, names: string[]}|null} The moment and the schedules.
 */
export function firstClash( items ) {
	const byMinute = new Map();
	items
		.filter( ( item ) => item.enabled )
		.forEach( ( item ) => {
			new Set(
				( item.week || [] ).map( ( ts ) => Math.floor( ts / 60 ) )
			).forEach( ( minute ) => {
				if ( ! byMinute.has( minute ) ) {
					byMinute.set( minute, [] );
				}
				byMinute.get( minute ).push( item.title );
			} );
		} );
	let found = null;
	byMinute.forEach( ( names, minute ) => {
		if ( names.length > 1 && ( ! found || minute < found.minute ) ) {
			found = { minute, names };
		}
	} );
	return found ? { at: found.minute * 60, names: found.names } : null;
}

/**
 * Roving focus inside a lane: which mark a key moves to.
 *
 * @param {string} key   KeyboardEvent key.
 * @param {number} index The focused mark.
 * @param {number} count Marks in the lane.
 * @return {number|null} The mark to focus, null for a key the lane leaves alone.
 */
export function nextMark( key, index, count ) {
	switch ( key ) {
		case 'ArrowRight':
		case 'ArrowDown':
			return Math.min( count - 1, index + 1 );
		case 'ArrowLeft':
		case 'ArrowUp':
			return Math.max( 0, index - 1 );
		case 'Home':
			return 0;
		case 'End':
			return count - 1;
		default:
			return null;
	}
}
