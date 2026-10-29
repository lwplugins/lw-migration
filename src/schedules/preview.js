/**
 * The editor's "next runs" preview, without React: computed from the form
 * in the site's time zone, close to the server's rules (NextRun.php) but
 * not bound to them. Every few hours and every few days count from now, as
 * for a new schedule; a saved schedule may count from an earlier moment,
 * so its card shows the server's own times.
 */

/**
 * Internal dependencies
 */
import { isoWeekday, moment, wall, zoneOf } from './zone';

const HOUR = 3600;

/**
 * The first moment after `now` at the minute of the hour, then every
 * `every` hours (real hours, like the server).
 *
 * @param {Object} form  Form state.
 * @param {number} every Hours between runs.
 * @param {number} now   Unix seconds.
 * @param {Object} zone  zoneOf().
 * @param {number} count How many.
 * @return {number[]} Unix seconds.
 */
function byHours( form, every, now, zone, count ) {
	const w = wall( now, zone );
	let first = now - ( w.minute * 60 + w.second ) + form.minute * 60;
	if ( first < now ) {
		first += HOUR;
	}
	if ( first === now ) {
		first += every * HOUR;
	}
	return Array.from(
		{ length: count },
		( _, i ) => first + i * every * HOUR
	);
}

/**
 * The form's time on candidate days `step` days apart from `day` (local
 * date, rolled over like Date.UTC), the first `count` after `now`.
 *
 * @param {Object} form  Form state.
 * @param {Object} start {year, month, day} of the first candidate.
 * @param {number} step  Days between candidates.
 * @param {number} now   Unix seconds.
 * @param {Object} zone  zoneOf().
 * @param {number} count How many.
 * @return {number[]} Unix seconds.
 */
function byDays( form, start, step, now, zone, count ) {
	const runs = [];
	for ( let day = 0; runs.length < count && day < 400; day += step ) {
		const at = moment(
			zone,
			start.year,
			start.month,
			start.day + day,
			form.hour,
			form.minute
		);
		if ( at > now ) {
			runs.push( at );
		}
	}
	return runs;
}

/**
 * The monthly day, clamped to each month's last day.
 *
 * @param {Object} form  Form state.
 * @param {Object} today wall() of now.
 * @param {number} now   Unix seconds.
 * @param {Object} zone  zoneOf().
 * @param {number} count How many.
 * @return {number[]} Unix seconds.
 */
function byMonths( form, today, now, zone, count ) {
	const runs = [];
	for ( let month = 0; runs.length < count && month < 24; month++ ) {
		const first = new Date(
			Date.UTC( today.year, today.month - 1 + month )
		);
		const last = new Date(
			Date.UTC( first.getUTCFullYear(), first.getUTCMonth() + 1, 0 )
		).getUTCDate();
		const at = moment(
			zone,
			first.getUTCFullYear(),
			first.getUTCMonth() + 1,
			Math.min( form.monthday, last ),
			form.hour,
			form.minute
		);
		if ( at > now ) {
			runs.push( at );
		}
	}
	return runs;
}

const every = ( form ) => Math.max( 1, form.every || 1 );

/**
 * One rule per frequency: ( form, now, zone, count ) => runs.
 */
const RULES = {
	hourly: ( form, now, zone, count ) => byHours( form, 1, now, zone, count ),
	hours: ( form, now, zone, count ) =>
		byHours( form, every( form ), now, zone, count ),
	daily: ( form, now, zone, count ) =>
		byDays( form, wall( now, zone ), 1, now, zone, count ),
	days: ( form, now, zone, count ) =>
		byDays( form, wall( now, zone ), every( form ), now, zone, count ),
	weekly: ( form, now, zone, count ) => {
		const today = wall( now, zone );
		const ahead = ( form.weekday - isoWeekday( today ) + 7 ) % 7;
		return byDays(
			form,
			{ ...today, day: today.day + ahead },
			7,
			now,
			zone,
			count
		);
	},
	monthly: ( form, now, zone, count ) =>
		byMonths( form, wall( now, zone ), now, zone, count ),
};

/**
 * The next runs of the form, a preview.
 *
 * @param {Object} form     Form state (frequency, every, minute, hour, weekday, monthday).
 * @param {number} now      Unix seconds.
 * @param {string} timezone The site's time zone.
 * @param {number} count    How many (default 3).
 * @return {number[]} Unix seconds, ascending.
 */
export function previewRuns( form, now, timezone, count = 3 ) {
	const rule = Object.prototype.hasOwnProperty.call( RULES, form.frequency )
		? RULES[ form.frequency ]
		: RULES.daily;
	return rule( form, now, zoneOf( timezone ), count );
}
