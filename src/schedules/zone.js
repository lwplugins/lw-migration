/**
 * Wall-clock arithmetic in the site's time zone (the `timezone` of the
 * schedules list), without React: reading a moment's local date and time,
 * and finding the moment of a local date and time. The server's own rules
 * (NextRun.php) stay the truth; this is enough for the week ruler's day
 * starts and the editor's preview.
 */

/**
 * Internal dependencies
 */
import { pageLocale } from '../data/format';

const OFFSET = /^([+-])(\d{2}):?(\d{2})$/;

/**
 * The zone for Intl: a named zone, or UTC plus a shift for an offset-only
 * site ("+02:00") or a name this browser does not know.
 *
 * @param {string} timezone Name or offset from the server.
 * @return {{timeZone: string, shift: number}} Zone and seconds to add.
 */
export function zoneOf( timezone ) {
	const offset = OFFSET.exec( timezone || '' );
	if ( offset ) {
		const sign = '-' === offset[ 1 ] ? -1 : 1;
		return {
			timeZone: 'UTC',
			shift:
				sign *
				( Number( offset[ 2 ] ) * 3600 + Number( offset[ 3 ] ) * 60 ),
		};
	}
	if ( timezone ) {
		try {
			new Intl.DateTimeFormat( 'en', { timeZone: timezone } );
			return { timeZone: timezone, shift: 0 };
		} catch {
			// Unknown to this browser: UTC below.
		}
	}
	return { timeZone: 'UTC', shift: 0 };
}

const readers = new Map();

/**
 * @param {string} timeZone Intl zone.
 * @return {Intl.DateTimeFormat} A numeric 24-hour reader (one per zone).
 */
function reader( timeZone ) {
	if ( ! readers.has( timeZone ) ) {
		readers.set(
			timeZone,
			new Intl.DateTimeFormat( 'en-US', {
				timeZone,
				hourCycle: 'h23',
				year: 'numeric',
				month: 'numeric',
				day: 'numeric',
				hour: 'numeric',
				minute: 'numeric',
				second: 'numeric',
			} )
		);
	}
	return readers.get( timeZone );
}

/**
 * The local date and time of a moment.
 *
 * @param {number} ts   Unix seconds.
 * @param {Object} zone zoneOf().
 * @return {{year: number, month: number, day: number, hour: number, minute: number, second: number}} Wall clock.
 */
export function wall( ts, zone ) {
	const parts = {};
	reader( zone.timeZone )
		.formatToParts( ( ts + zone.shift ) * 1000 )
		.forEach( ( { type, value } ) => {
			parts[ type ] = Number( value );
		} );
	return {
		year: parts.year,
		month: parts.month,
		day: parts.day,
		hour: parts.hour % 24,
		minute: parts.minute,
		second: parts.second,
	};
}

/**
 * @param {Object} w wall().
 * @return {number} ISO weekday of its date, 1 = Monday.
 */
export const isoWeekday = ( w ) =>
	( ( new Date( Date.UTC( w.year, w.month - 1, w.day ) ).getUTCDay() + 6 ) %
		7 ) +
	1;

/**
 * @param {number} ts   Unix seconds.
 * @param {Object} zone zoneOf().
 * @return {number} Seconds the local clock is ahead of UTC then.
 */
function offsetAt( ts, zone ) {
	const w = wall( ts, zone );
	return (
		Date.UTC( w.year, w.month - 1, w.day, w.hour, w.minute, w.second ) /
			1000 -
		ts
	);
}

/**
 * @param {Object} a wall().
 * @param {Object} b wall().
 * @return {boolean} The same local minute.
 */
const sameMinute = ( a, b ) =>
	a.year === b.year &&
	a.month === b.month &&
	a.day === b.day &&
	a.hour === b.hour &&
	a.minute === b.minute;

/**
 * The moment of a local date and time. Out-of-range parts roll over like
 * Date.UTC (day 32 is the next month's first). A time skipped by a DST
 * change lands within the hour after it; a time that happens twice is its
 * first occurrence (as on the server).
 *
 * @param {Object} zone   zoneOf().
 * @param {number} year   Year.
 * @param {number} month  1-12.
 * @param {number} day    Day of the month.
 * @param {number} hour   0-23.
 * @param {number} minute 0-59.
 * @return {number} Unix seconds.
 */
export function moment( zone, year, month, day, hour = 0, minute = 0 ) {
	const guess = Date.UTC( year, month - 1, day, hour, minute ) / 1000;
	const first = guess - offsetAt( guess, zone );
	const at = guess - offsetAt( first, zone );
	const shift = offsetAt( at - 7200, zone ) - offsetAt( at, zone );
	if (
		shift > 0 &&
		sameMinute( wall( at - shift, zone ), wall( at, zone ) )
	) {
		return at - shift;
	}
	return at;
}

/**
 * The start of the local day `days` after the day of `ts`.
 *
 * @param {number} ts   Unix seconds.
 * @param {Object} zone zoneOf().
 * @param {number} days Days to add (0 = that day).
 * @return {number} Unix seconds of its midnight.
 */
export function dayStart( ts, zone, days = 0 ) {
	const w = wall( ts, zone );
	return moment( zone, w.year, w.month, w.day + days );
}

/**
 * One Intl formatter for many moments in the zone.
 *
 * @param {Object} zone    zoneOf().
 * @param {Object} options Intl.DateTimeFormat options (no time zone).
 * @param {string} locale  BCP 47 tag (default: the page language).
 * @return {(ts: number) => string} Writes Unix seconds.
 */
export function zoneFormatter( zone, options, locale = pageLocale() ) {
	const intl = new Intl.DateTimeFormat( locale, {
		...options,
		timeZone: zone.timeZone,
	} );
	return ( ts ) => intl.format( ( ts + zone.shift ) * 1000 );
}
