/**
 * The "Last runs" strip of a schedule card, without React: one bar per run
 * of the item's `history` (oldest first), its look and its words. A status
 * this page does not know (a cancelled run, a new value) gets the neutral
 * look and the "Unknown" label, never an error.
 */

/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { statusLabel } from './timing';

const TONES = {
	done: 'ok',
	partial: 'partial',
	failed: 'failed',
	skipped: 'skipped',
};

/**
 * @param {string} status Run status.
 * @return {string} ok | partial | failed | skipped | unknown.
 */
export const barTone = ( status ) =>
	Object.prototype.hasOwnProperty.call( TONES, status )
		? TONES[ status ]
		: 'unknown';

/**
 * @param {string} trigger cron | manual.
 * @return {string} Who started the run.
 */
export const triggerLabel = ( trigger ) =>
	'cron' === trigger
		? __( 'Schedule', 'lw-migration' )
		: __( 'By hand', 'lw-migration' );

/**
 * The bars of the strip.
 *
 * @param {Object[]}               history Item `history`: {status, endedAt, trigger}.
 * @param {(ts: number) => string} write   Writes a moment (runWriter()).
 * @return {{key: string, tone: string, label: string}[]} Bars, oldest first.
 */
export const stripBars = ( history, write ) =>
	( history || [] ).map( ( run, index ) => ( {
		key: `${ run.endedAt }-${ index }`,
		tone: barTone( run.status ),
		label: sprintf(
			/* translators: 1: result, e.g. Ready; 2: when it ended; 3: who started it (Schedule, By hand). */
			__( '%1$s, %2$s (%3$s)', 'lw-migration' ),
			statusLabel( run.status ),
			write( run.endedAt ),
			triggerLabel( run.trigger )
		),
	} ) );

/**
 * What the strip says to a screen reader.
 *
 * @param {Object[]} history Item `history`.
 * @return {string} "The last 14 runs: 13 ready, 1 not."
 */
export function stripSummary( history ) {
	const runs = history || [];
	const ready = runs.filter( ( run ) => 'done' === run.status ).length;
	return sprintf(
		/* translators: 1: number of runs; 2: runs that ended ready; 3: the others. */
		_n(
			'The last %1$d run: %2$d ready, %3$d not.',
			'The last %1$d runs: %2$d ready, %3$d not.',
			runs.length,
			'lw-migration'
		),
		runs.length,
		ready,
		runs.length - ready
	);
}
