/**
 * One-line texts of a schedule, without React.
 */

/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { formatBytes, formatClock, pageLocale } from '../data/format';

/**
 * A map's own entry for a value from the server (never an inherited one
 * like `toString`), or the fallback.
 *
 * @param {Object<string, string>} map      Known values.
 * @param {string}                 key      Value.
 * @param {string}                 fallback For anything else.
 * @return {string} Entry.
 */
const known = ( map, key, fallback ) =>
	Object.prototype.hasOwnProperty.call( map, key ) ? map[ key ] : fallback;

/**
 * @param {number} hour   0-23.
 * @param {number} minute 0-59.
 * @return {string} The time of day in the page language ("22:00", "10:00 PM").
 */
export const clock = ( hour, minute ) => formatClock( hour, minute );

/**
 * @param {number} hour 0-23.
 * @return {string} The hour alone, as the page language writes it ("22", "10 PM").
 */
export const hourLabel = ( hour ) =>
	new Intl.DateTimeFormat( pageLocale(), {
		hour: '2-digit',
		timeZone: 'UTC',
	} ).format( Date.UTC( 2000, 0, 1, hour ) );

/**
 * @param {number} day ISO weekday, 1 = Monday.
 * @return {string} Its name ('' outside 1-7).
 */
export const weekdayName = ( day ) =>
	[
		__( 'Monday', 'lw-migration' ),
		__( 'Tuesday', 'lw-migration' ),
		__( 'Wednesday', 'lw-migration' ),
		__( 'Thursday', 'lw-migration' ),
		__( 'Friday', 'lw-migration' ),
		__( 'Saturday', 'lw-migration' ),
		__( 'Sunday', 'lw-migration' ),
	][ day - 1 ] || '';

/**
 * @param {Object} s Schedule (item or form): frequency, every, minute, hour, weekday, monthday.
 * @return {string} When it runs.
 */
export function describeTiming( s ) {
	const at =
		'hourly' === s.frequency || 'hours' === s.frequency
			? ''
			: clock( s.hour, s.minute );
	switch ( s.frequency ) {
		case 'hourly':
			return sprintf(
				/* translators: %d: minute of the hour, 0-59. */
				__( 'Every hour, at minute %d', 'lw-migration' ),
				s.minute
			);
		case 'hours':
			return sprintf(
				/* translators: 1: number of hours, 2: minute of the hour. */
				_n(
					'Every %1$d hour, at minute %2$d',
					'Every %1$d hours, at minute %2$d',
					s.every,
					'lw-migration'
				),
				s.every,
				s.minute
			);
		case 'days':
			return sprintf(
				/* translators: 1: number of days, 2: time of day, e.g. 03:00. */
				_n(
					'Every %1$d day at %2$s',
					'Every %1$d days at %2$s',
					s.every,
					'lw-migration'
				),
				s.every,
				at
			);
		case 'weekly':
			return sprintf(
				/* translators: 1: day of the week, 2: time of day, e.g. 03:00. */
				__( 'Weekly on %1$s at %2$s', 'lw-migration' ),
				weekdayName( s.weekday ),
				at
			);
		case 'monthly':
			if ( s.monthday > 28 ) {
				return sprintf(
					/* translators: 1: day of the month, 2: time of day, e.g. 03:00. */
					__(
						'Monthly on day %1$d at %2$s (on the last day in shorter months)',
						'lw-migration'
					),
					s.monthday,
					at
				);
			}
			return sprintf(
				/* translators: 1: day of the month, 2: time of day, e.g. 03:00. */
				__( 'Monthly on day %1$d at %2$s', 'lw-migration' ),
				s.monthday,
				at
			);
		default:
			/* translators: %s: time of day, e.g. 03:00. */
			return sprintf( __( 'Every day at %s', 'lw-migration' ), at );
	}
}

/**
 * What a schedule keeps, one phrase per limit (shown as a list).
 *
 * @param {Object} r Retention {count, bytes, days}.
 * @return {string[]} Phrases.
 */
export function retentionLimits( r ) {
	const limits = [];
	if ( r.count > 0 ) {
		limits.push(
			sprintf(
				/* translators: %d: number of backups. */
				_n(
					'At most %d backup',
					'At most %d backups',
					r.count,
					'lw-migration'
				),
				r.count
			)
		);
	}
	if ( r.days > 0 ) {
		limits.push(
			sprintf(
				/* translators: %d: age in days. */
				_n(
					'At most %d day old',
					'At most %d days old',
					r.days,
					'lw-migration'
				),
				r.days
			)
		);
	}
	if ( r.bytes > 0 ) {
		limits.push(
			sprintf(
				/* translators: %s: size, e.g. 2 GB. */
				__( 'At most %s in total', 'lw-migration' ),
				formatBytes( r.bytes )
			)
		);
	}
	return limits.length
		? limits
		: [ __( 'Every backup of this schedule', 'lw-migration' ) ];
}

/**
 * @param {Object} notify {when, email}.
 * @return {string} Who is mailed when.
 */
export function describeNotify( notify ) {
	if ( 'off' === notify.when ) {
		return __( 'No e-mail', 'lw-migration' );
	}
	const to = notify.email || __( 'the default address', 'lw-migration' );
	switch ( notify.when ) {
		case 'success':
			return sprintf(
				/* translators: %s: e-mail address(es). */
				__( 'When a backup is ready, to %s', 'lw-migration' ),
				to
			);
		case 'always':
			return sprintf(
				/* translators: %s: e-mail address(es). */
				__( 'After every run, to %s', 'lw-migration' ),
				to
			);
		default:
			return sprintf(
				/* translators: %s: e-mail address(es). */
				__( 'When a run fails, to %s', 'lw-migration' ),
				to
			);
	}
}

/**
 * @param {string} status done|partial|failed|cancelled|skipped.
 * @return {string} Label.
 */
export const statusLabel = ( status ) =>
	known(
		{
			done: __( 'Ready', 'lw-migration' ),
			partial: __( 'Partly done', 'lw-migration' ),
			failed: __( 'Failed', 'lw-migration' ),
			cancelled: __( 'Cancelled', 'lw-migration' ),
			skipped: __( 'Skipped', 'lw-migration' ),
		},
		status,
		__( 'Unknown', 'lw-migration' )
	);

/**
 * @param {string} status Run status.
 * @return {string} StatusBadge status.
 */
export const statusTone = ( status ) =>
	known(
		{
			done: 'ok',
			partial: 'warning',
			failed: 'critical',
			skipped: 'warning',
		},
		status,
		'idle'
	);

/**
 * The mail result of a log entry. `pending` = the mail was due but no send
 * result was written (it is on its way, or the run stopped while sending);
 * '' and values this page does not know show as a dash.
 *
 * @param {string} mail '' | pending | sent | failed | throttled.
 * @return {string} Label.
 */
export const mailLabel = ( mail ) =>
	known(
		{
			pending: __( 'No result recorded', 'lw-migration' ),
			sent: __( 'Sent', 'lw-migration' ),
			failed: __( 'Could not be sent', 'lw-migration' ),
			throttled: __(
				'Not sent (one failure mail a day)',
				'lw-migration'
			),
		},
		mail,
		'—'
	);
