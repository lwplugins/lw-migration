/**
 * The job card's times: when the job started, how long it has run and when
 * it last made progress.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { formatDayTime, formatSiteTime, pageLocale } from '../data/format';

const TIME = { hour: '2-digit', minute: '2-digit' };
const DAY = { year: 'numeric', month: '2-digit', day: '2-digit' };

/**
 * Seconds a job has run, by the browser's clock. A server clock ahead of
 * this one never shows a negative time.
 *
 * @param {number} createdAt Unix seconds the job was created (0 = unknown).
 * @param {number} now       Unix seconds.
 * @return {number|null} Whole seconds, null when the start is unknown.
 */
export function elapsedSeconds( createdAt, now ) {
	const start = Number( createdAt ) || 0;
	if ( start <= 0 ) {
		return null;
	}
	return Math.max( 0, Math.floor( now - start ) );
}

/**
 * "14 s", "2 min 14 s", "1 h 3 min".
 *
 * @param {number} seconds Whole seconds.
 * @return {string} Duration.
 */
export function formatElapsed( seconds ) {
	const total = Math.max( 0, Math.floor( Number( seconds ) || 0 ) );
	const hours = Math.floor( total / 3600 );
	const minutes = Math.floor( ( total % 3600 ) / 60 );
	if ( hours > 0 ) {
		return sprintf(
			/* translators: 1: hours, 2: minutes of a running job's time. */
			__( '%1$d h %2$d min', 'lw-migration' ),
			hours,
			minutes
		);
	}
	if ( minutes > 0 ) {
		return sprintf(
			/* translators: 1: minutes, 2: seconds of a running job's time. */
			__( '%1$d min %2$d s', 'lw-migration' ),
			minutes,
			total % 60
		);
	}
	return sprintf(
		/* translators: %d: seconds of a running job's time. */
		__( '%d s', 'lw-migration' ),
		total
	);
}

/**
 * A moment of the job in the site's time zone: the time of day when it is
 * today there, the day and time otherwise.
 *
 * @param {number} ts     Unix seconds.
 * @param {number} now    Unix seconds.
 * @param {string} locale BCP 47 tag (default: the page language).
 * @return {string} Text.
 */
export function jobTime( ts, now, locale = pageLocale() ) {
	const sameDay =
		formatSiteTime( ts, DAY, 'en' ) === formatSiteTime( now, DAY, 'en' );
	return sameDay
		? formatSiteTime( ts, TIME, locale )
		: formatDayTime( ts, locale );
}
