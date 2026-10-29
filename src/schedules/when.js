/**
 * How the Schedules section writes a run's time, without React: "today
 * 03:00", "tomorrow 03:00", a weekday and date further away, the wait until
 * it ("in 11 hours 40 minutes"), and the day names of the week ruler. All in
 * the site's time zone.
 */

/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { pageLocale } from '../data/format';
import { dayStart, zoneFormatter, zoneOf } from './zone';

const TIME = { hour: '2-digit', minute: '2-digit' };
const FAR = {
	weekday: 'long',
	month: 'short',
	day: 'numeric',
	hour: '2-digit',
	minute: '2-digit',
};

/**
 * Writes run times relative to one "now" (one set of formatters for a
 * whole list).
 *
 * @param {number} now      Unix seconds.
 * @param {string} timezone The site's time zone (schedules list `timezone`).
 * @param {string} locale   BCP 47 tag (default: the page language).
 * @return {(ts: number) => string} "today 03:00" | "tomorrow 03:00" | "Sunday, Oct 18, 03:00".
 */
export function runWriter( now, timezone, locale = pageLocale() ) {
	const zone = zoneOf( timezone );
	const today = dayStart( now, zone );
	const tomorrow = dayStart( now, zone, 1 );
	const after = dayStart( now, zone, 2 );
	const time = zoneFormatter( zone, TIME, locale );
	const far = zoneFormatter( zone, FAR, locale );
	return ( ts ) => {
		if ( ts >= today && ts < tomorrow ) {
			/* translators: %s: time of day, e.g. 14:02. */
			return sprintf( __( 'today %s', 'lw-migration' ), time( ts ) );
		}
		if ( ts >= tomorrow && ts < after ) {
			/* translators: %s: time of day, e.g. 03:00. */
			return sprintf( __( 'tomorrow %s', 'lw-migration' ), time( ts ) );
		}
		return far( ts );
	};
}

/**
 * The wait until a run: minutes under an hour, hours and minutes under a
 * day, then days.
 *
 * @param {number} ts  Unix seconds of the run.
 * @param {number} now Unix seconds.
 * @return {string} "in 11 hours 40 minutes".
 */
export function relativeIn( ts, now ) {
	const minutes = Math.round( ( ts - now ) / 60 );
	if ( minutes < 1 ) {
		return __( 'due now', 'lw-migration' );
	}
	if ( minutes < 60 ) {
		return sprintf(
			/* translators: %d: number of minutes. */
			_n( 'in %d minute', 'in %d minutes', minutes, 'lw-migration' ),
			minutes
		);
	}
	const hours = Math.floor( minutes / 60 );
	const rest = minutes % 60;
	if ( hours >= 24 ) {
		const days = Math.round( minutes / 1440 );
		return sprintf(
			/* translators: %d: number of days. */
			_n( 'in %d day', 'in %d days', days, 'lw-migration' ),
			days
		);
	}
	if ( 0 === rest ) {
		return sprintf(
			/* translators: %d: number of hours. */
			_n( 'in %d hour', 'in %d hours', hours, 'lw-migration' ),
			hours
		);
	}
	if ( 1 === rest ) {
		return sprintf(
			/* translators: %d: number of hours. */
			_n(
				'in %d hour 1 minute',
				'in %d hours 1 minute',
				hours,
				'lw-migration'
			),
			hours
		);
	}
	return sprintf(
		/* translators: 1: number of hours, 2: number of minutes (2-59). */
		_n(
			'in %1$d hour %2$d minutes',
			'in %1$d hours %2$d minutes',
			hours,
			'lw-migration'
		),
		hours,
		rest
	);
}

/**
 * The column heads of the week ruler: "Today 16", then the short weekday
 * and the day of the month.
 *
 * @param {number[]} starts   Day starts (weekStarts()), the first is today.
 * @param {string}   timezone The site's time zone.
 * @param {string}   locale   BCP 47 tag (default: the page language).
 * @return {string[]} One label per day (the last start only closes the week).
 */
export function dayLabels( starts, timezone, locale = pageLocale() ) {
	const zone = zoneOf( timezone );
	const write = zoneFormatter(
		zone,
		{ weekday: 'short', day: 'numeric' },
		locale
	);
	const date = zoneFormatter( zone, { day: 'numeric' }, locale );
	return starts.slice( 0, -1 ).map( ( ts, index ) =>
		0 === index
			? sprintf(
					/* translators: %s: day of the month, e.g. 16. */
					__( 'Today %s', 'lw-migration' ),
					date( ts )
				)
			: write( ts )
	);
}
