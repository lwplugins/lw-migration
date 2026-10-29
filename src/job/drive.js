/**
 * The browser driver's loop (decision 8), without React: ticks back to back
 * until the job ends, asks for input, or a failure decides otherwise. Every
 * decision comes from data/errors.js classify().
 */

/**
 * Internal dependencies
 */
import {
	BUSY,
	BUSY_DELAY,
	LOGGED_OUT as LOGGED_OUT_KIND,
	MAX_RETRIES,
	REREAD,
	RETRY,
	classify,
	retryDelay,
} from '../data/errors';

// The abortable pause lives with the retry rules; the loop's callers import it from here.
export { sleep } from '../data/errors';
import { isFinished } from './phases';

/** The job finished (done, failed or cancelled). */
export const ENDED = 'ended';
/** The job waits for an answer or an upload. */
export const WAITING = 'waiting';
/** The caller stopped the loop (screen gone, job changed under it). */
export const STOPPED = 'stopped';
/** The session ended: the user has to log in again. */
export const LOGGED_OUT = 'loggedOut';
/** A lasting failure, or the last passing one: `error` says which. */
export const FAILED = 'failed';

/** Pause after a tick that changed nothing (another request holds the job). */
export const IDLE_WAIT_MS = BUSY_DELAY;

const VIEW_KEYS = [
	'status',
	'step',
	'stepIndex',
	'progress',
	'message',
	'updatedAt',
	'warningCount',
];
const RECOVERABLE = [ BUSY, RETRY, REREAD ];

/**
 * Whether two job views show the same thing (ruling P8). `updatedAt` has
 * whole seconds, so it alone cannot tell two quick ticks apart.
 *
 * @param {Object} a Job view.
 * @param {Object} b Job view.
 * @return {boolean} Nothing visible changed.
 */
export const sameView = ( a, b ) =>
	VIEW_KEYS.every( ( key ) => a?.[ key ] === b?.[ key ] );

/**
 * Cancels without racing the loop (a tick holds the job lock for seconds,
 * longer than a busy retry waits): pause the loop, let its in-flight tick
 * finish, then send the cancel.
 *
 * @param {() => void}            pause   Makes the loop's stopped() true and ends its waits.
 * @param {Promise|null}          running The loop's promise (null when none runs).
 * @param {() => Promise<Object>} remove  The cancel request.
 * @return {Promise<Object>} The cancel answer `{ job, cancelDeferred }`.
 */
export async function cancelAfterLoop( pause, running, remove ) {
	pause();
	if ( running ) {
		await running.catch( () => null );
	}
	return remove();
}

/**
 * What a failed cancel leaves behind: a logout stops driving (no loop may
 * start again behind the log-in state) and asks for a new login; any other
 * failure lets a loop that was driving go on, so the job is never left
 * paused in silence.
 *
 * @param {Object}  error      The cancel's rejection.
 * @param {boolean} wasDriving A loop ran when the cancel was asked for.
 * @return {Object} `{ loggedOut, stop, resume }`.
 */
export function cancelFailure( error, wasDriving ) {
	if ( LOGGED_OUT_KIND === classify( error ) ) {
		return { loggedOut: true, stop: true, resume: false };
	}
	return { loggedOut: false, stop: false, resume: Boolean( wasDriving ) };
}

/**
 * Whether the loop goes on after a cancel answer: only when the job is not
 * finished (a cancel past the point of no return is only recorded).
 *
 * @param {Object} result `{ job, cancelDeferred }`.
 * @return {boolean} Drive on.
 */
export const drivesOnAfterCancel = ( result ) =>
	Boolean( result?.cancelDeferred ) || ! isFinished( result?.job );

/**
 * @param {Object}                          job        Job view to start from.
 * @param {Object}                          io
 * @param {(id: string) => Promise<Object>} io.tick    One tick; no retry of its own.
 * @param {(id: string) => Promise<Object>} io.read    Reads the job.
 * @param {(ms: number) => Promise<void>}   io.wait    Pause.
 * @param {(view: Object) => void}          io.onView  Called with every new view.
 * @param {() => boolean}                   io.stopped Whether the caller stopped.
 * @return {Promise<Object>} `{ outcome, error? }`.
 */
export async function runTicks( job, { tick, read, wait, onView, stopped } ) {
	let last = job;
	let failures = 0;

	while ( ! stopped() ) {
		if ( isFinished( last ) ) {
			return { outcome: ENDED };
		}
		if ( 'needs_input' === last.status ) {
			return { outcome: WAITING };
		}
		let next;
		let error = null;
		try {
			next = await tick( last.id );
		} catch ( e ) {
			error = e;
		}
		if ( stopped() ) {
			break;
		}
		if ( ! error && null === next ) {
			// An empty answer: a plugin's uninstall routine ended that request
			// (exit) after its work was recorded. The next tick goes on.
			failures = 0;
			await wait( IDLE_WAIT_MS );
			continue;
		}
		if ( error ) {
			const kind = classify( error );
			if ( LOGGED_OUT_KIND === kind ) {
				return { outcome: LOGGED_OUT, error };
			}
			failures++;
			if ( ! RECOVERABLE.includes( kind ) || failures >= MAX_RETRIES ) {
				return { outcome: FAILED, error };
			}
			if ( REREAD !== kind ) {
				await wait( retryDelay( kind, failures ) );
				continue;
			}
			// The job moved on without this tab (answered, uploaded): take its state.
			try {
				next = await read( last.id );
			} catch ( e ) {
				if ( LOGGED_OUT_KIND === classify( e ) ) {
					return { outcome: LOGGED_OUT, error: e };
				}
				await wait( retryDelay( RETRY, failures ) );
				continue;
			}
			if ( stopped() ) {
				break;
			}
		} else {
			failures = 0;
		}
		const idle = sameView( last, next );
		last = next;
		onView( next );
		if ( idle && ! isFinished( next ) && 'needs_input' !== next.status ) {
			await wait( IDLE_WAIT_MS );
		}
	}
	return { outcome: STOPPED };
}
