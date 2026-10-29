/**
 * WordPress dependencies
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { api, errorMessage } from '../data/api';
import {
	LOGGED_OUT as LOGGED_OUT_KIND,
	REREAD,
	classify,
} from '../data/errors';
import {
	FAILED,
	LOGGED_OUT,
	cancelAfterLoop,
	cancelFailure,
	drivesOnAfterCancel,
	runTicks,
	sleep,
} from './drive';
import { isFinished } from './phases';

/**
 * The browser driver (decision 8) as a hook: runs the loop of drive.js while
 * `running` and the job neither waits nor ended. A lasting failure stops it
 * with the message and a "Try again"; a logout stops it with the log-in-again
 * state (ruling P7) — the job is never resumed or cancelled behind the
 * user's back. Each loop has its own AbortController: unmounting or
 * stopping aborts its request and ends its waits; a cancel pauses it and
 * lets its tick finish first.
 *
 * @param {Object}                 initial           Job view.
 * @param {Object}                 options
 * @param {boolean}                options.autoStart Drive at once (this tab started it, or it is past its point of no return).
 * @param {(view: Object) => void} options.onChange  Called with every new job view.
 * @return {Object} { job, running, error, loggedOut, update, answer, cancel, refresh, resume, stop }.
 */
export default function useJobDriver( initial, { autoStart, onChange } ) {
	const [ job, setJob ] = useState( initial );
	const [ running, setRunning ] = useState( autoStart );
	// Bumped to start a new loop while `running` stays true (after a pause).
	const [ generation, setGeneration ] = useState( 0 );
	const [ error, setError ] = useState( '' );
	const [ loggedOut, setLoggedOut ] = useState( false );
	const mounted = useRef( true );
	const latest = useRef( initial );
	const loop = useRef( null );
	const report = useRef( onChange );
	report.current = onChange;

	useEffect( () => {
		mounted.current = true;
		return () => {
			mounted.current = false;
		};
	}, [] );

	const update = useCallback( ( next ) => {
		if ( mounted.current && next?.id ) {
			latest.current = next;
			setJob( next );
			report.current?.( next );
		}
	}, [] );

	/**
	 * Stops driving because of a failure: a logout shows the log-in state,
	 * anything else its message.
	 *
	 * @param {Object} e Rejection.
	 */
	const stop = useCallback( ( e ) => {
		if ( ! mounted.current ) {
			return;
		}
		setRunning( false );
		if ( LOGGED_OUT_KIND === classify( e ) ) {
			setLoggedOut( true );
		} else {
			setError( errorMessage( e ) );
		}
	}, [] );

	const blocked = isFinished( job ) || 'needs_input' === job.status;

	useEffect( () => {
		if ( ! running || blocked ) {
			return undefined;
		}
		const controller = new AbortController();
		const pauser = new AbortController();
		const { signal } = controller;
		const isStopped = () =>
			signal.aborted || pauser.signal.aborted || ! mounted.current;
		const promise = runTicks( latest.current, {
			tick: ( id ) => api.tick( id, signal ),
			read: ( id ) => api.job( id, signal ),
			wait: ( ms ) => sleep( ms, [ signal, pauser.signal ] ),
			onView: update,
			stopped: isStopped,
		} ).then( ( result ) => {
			if ( isStopped() ) {
				return;
			}
			if ( LOGGED_OUT === result.outcome ) {
				setRunning( false );
				setLoggedOut( true );
			} else if ( FAILED === result.outcome ) {
				stop( result.error );
			}
		} );
		const current = { promise, pause: () => pauser.abort() };
		loop.current = current;
		promise.finally( () => {
			if ( loop.current === current ) {
				loop.current = null;
			}
		} );
		return () => controller.abort();
	}, [ running, blocked, generation, job.id, update, stop ] );

	/**
	 * @return {Promise<boolean>} Whether the job was read.
	 */
	const refresh = async () => {
		try {
			update( await api.job( job.id ) );
			return true;
		} catch ( e ) {
			stop( e );
			return false;
		}
	};

	const resume = () => {
		setError( '' );
		setRunning( true );
		setGeneration( ( value ) => value + 1 );
	};

	/**
	 * Sends an answer (password or confirmation). A refusal because the job
	 * moved on reads it again instead of failing; other failures are thrown
	 * for the prompt to show (a logout also shows the log-in state).
	 *
	 * @param {Object} input Answer body.
	 * @return {Promise<Object|undefined>} New view.
	 */
	const answer = async ( input ) => {
		setError( '' );
		let next;
		try {
			next = await api.answer( job.id, input );
		} catch ( e ) {
			const kind = classify( e );
			if ( REREAD === kind ) {
				if ( await refresh() ) {
					resume();
				}
				return undefined;
			}
			if ( LOGGED_OUT_KIND === kind ) {
				setLoggedOut( true );
			}
			throw e;
		}
		update( next );
		resume();
		return next;
	};

	/**
	 * Cancels without racing the loop: pause it, let its in-flight tick
	 * release the job, then send the cancel; drive on only when the cancel
	 * was deferred (or the job is somehow still unfinished).
	 *
	 * @return {Promise<Object>} `{ job, cancelDeferred }`.
	 */
	const cancel = async () => {
		const current = loop.current;
		const wasDriving = Boolean( current );
		let result;
		try {
			result = await cancelAfterLoop(
				() => current?.pause(),
				current?.promise || null,
				() => api.cancel( job.id )
			);
		} catch ( e ) {
			const after = cancelFailure( e, wasDriving );
			if ( after.stop && mounted.current ) {
				setRunning( false );
			}
			if ( after.loggedOut && mounted.current ) {
				setLoggedOut( true );
			}
			if ( after.resume ) {
				resume();
			}
			throw e;
		}
		update( result.job );
		if ( drivesOnAfterCancel( result ) ) {
			resume();
		}
		return result;
	};

	return {
		job,
		running,
		error,
		loggedOut,
		update,
		answer,
		cancel,
		refresh,
		resume,
		stop,
	};
}
