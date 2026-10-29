/**
 * External dependencies
 */
import { afterEach, describe, expect, it, vi } from 'vitest';

/**
 * Internal dependencies
 */
import { BUSY_DELAY, MAX_RETRIES, RETRY_DELAY } from '../data/errors';
import {
	ENDED,
	FAILED,
	IDLE_WAIT_MS,
	LOGGED_OUT,
	STOPPED,
	WAITING,
	cancelAfterLoop,
	cancelFailure,
	drivesOnAfterCancel,
	runTicks,
	sameView,
	sleep,
} from './drive';

const view = ( fields = {} ) => ( {
	id: 'abcdef0123456789',
	type: 'export',
	status: 'running',
	step: 'content',
	stepIndex: 3,
	progress: 10,
	message: 'Copying files',
	updatedAt: 1000,
	warningCount: 0,
	input: null,
	...fields,
} );

/**
 * Runs the loop with a scripted server: each entry is a view to return or
 * `{ throws: error }`.
 *
 * @param {Object} start  First view.
 * @param {Array}  script Tick answers in order.
 * @param {Object} extra  Overrides (read, stopped).
 */
async function run( start, script, extra = {} ) {
	const answers = [ ...script ];
	const tick = vi.fn( async () => {
		const next = answers.shift();
		if ( ! next ) {
			throw new Error( 'tick called more often than scripted' );
		}
		if ( next.throws ) {
			throw next.throws;
		}
		return next;
	} );
	const wait = vi.fn( async () => {} );
	const onView = vi.fn();
	const read = extra.read || vi.fn();
	const result = await runTicks( start, {
		tick,
		read,
		wait,
		onView,
		stopped: extra.stopped || ( () => false ),
	} );
	return { result, tick, wait, onView, read };
}

describe( 'sameView', () => {
	it( 'is true when nothing the screen shows changed', () => {
		expect( sameView( view(), view( { warnings: [ 'x' ] } ) ) ).toBe(
			true
		);
	} );

	it.each( [
		[ 'status', 'needs_input' ],
		[ 'step', 'database' ],
		[ 'stepIndex', 4 ],
		[ 'progress', 11 ],
		[ 'message', 'Copying more files' ],
		[ 'updatedAt', 1001 ],
		[ 'warningCount', 1 ],
	] )( 'sees a change of %s', ( key, value ) => {
		expect( sameView( view(), view( { [ key ]: value } ) ) ).toBe( false );
	} );
} );

describe( 'runTicks', () => {
	it( 'ticks again after an empty answer (an uninstall routine ended that request)', async () => {
		const answers = [ null, view( { status: 'done', progress: 100 } ) ];
		const tick = vi.fn( async () => answers.shift() );
		const wait = vi.fn( async () => {} );
		const onView = vi.fn();
		const result = await runTicks( view(), {
			tick,
			read: vi.fn(),
			wait,
			onView,
			stopped: () => false,
		} );

		expect( result.outcome ).toBe( ENDED );
		expect( tick ).toHaveBeenCalledTimes( 2 );
		expect( onView ).toHaveBeenCalledTimes( 1 );
		expect( wait ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'ticks until the job is done and shows every view', async () => {
		const a = view( { progress: 50 } );
		const b = view( { status: 'done', progress: 100 } );
		const { result, tick, onView, wait } = await run( view(), [ a, b ] );

		expect( result.outcome ).toBe( ENDED );
		expect( tick ).toHaveBeenCalledTimes( 2 );
		expect( tick ).toHaveBeenCalledWith( 'abcdef0123456789' );
		expect( onView.mock.calls ).toEqual( [ [ a ], [ b ] ] );
		expect( wait ).not.toHaveBeenCalled();
	} );

	it( 'stops when the job asks for input', async () => {
		const asking = view( {
			type: 'import',
			status: 'needs_input',
			input: { kind: 'upload', received: 0, size: 10, chunkBytes: 4 },
		} );
		const { result, tick } = await run( view( { status: 'pending' } ), [
			asking,
		] );

		expect( result.outcome ).toBe( WAITING );
		expect( tick ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'does not tick a job that already waits or ended', async () => {
		const waiting = await run( view( { status: 'needs_input' } ), [] );
		const ended = await run( view( { status: 'failed' } ), [] );

		expect( waiting.result.outcome ).toBe( WAITING );
		expect( ended.result.outcome ).toBe( ENDED );
		expect( waiting.tick ).not.toHaveBeenCalled();
		expect( ended.tick ).not.toHaveBeenCalled();
	} );

	it( 'waits a little after an unchanged view (idle), never failing', async () => {
		const same = view();
		const done = view( { status: 'done' } );
		const { result, wait } = await run( view(), [ same, same, done ] );

		expect( result.outcome ).toBe( ENDED );
		expect( wait.mock.calls ).toEqual( [
			[ IDLE_WAIT_MS ],
			[ IDLE_WAIT_MS ],
		] );
	} );

	it( 'is not idle when only the progress moved within the same second', async () => {
		const { wait } = await run( view(), [
			view( { progress: 12 } ),
			view( { progress: 14 } ),
			view( { status: 'done' } ),
		] );

		expect( wait ).not.toHaveBeenCalled();
	} );

	it( 'applies a stale-looking view like any other', async () => {
		const stale = view( { status: 'pending', stepIndex: 0, progress: 0 } );
		const { onView } = await run( view( { status: 'pending' } ), [
			stale,
			view( { status: 'done' } ),
		] );

		expect( onView ).toHaveBeenCalledWith( stale );
	} );

	it( 'retries a busy job after the busy wait', async () => {
		const busy = { throws: { status: 409, code: 'lw_migration_job_busy' } };
		const { result, wait, tick } = await run( view(), [
			busy,
			busy,
			view( { status: 'done' } ),
		] );

		expect( result.outcome ).toBe( ENDED );
		expect( tick ).toHaveBeenCalledTimes( 3 );
		expect( wait.mock.calls ).toEqual( [ [ BUSY_DELAY ], [ BUSY_DELAY ] ] );
	} );

	it( 'retries a passing failure with a growing pause', async () => {
		const gateway = { throws: { status: 502, code: 'http_502' } };
		const { result, wait } = await run( view(), [
			gateway,
			gateway,
			view( { status: 'done' } ),
		] );

		expect( result.outcome ).toBe( ENDED );
		expect( wait.mock.calls ).toEqual( [
			[ RETRY_DELAY ],
			[ 2 * RETRY_DELAY ],
		] );
	} );

	it( 'counts failures again from zero after a success', async () => {
		const gateway = { throws: { status: 502, code: 'http_502' } };
		const script = [];
		for ( let i = 0; i < MAX_RETRIES - 1; i++ ) {
			script.push( gateway );
		}
		script.push( view( { progress: 20 } ) );
		for ( let i = 0; i < MAX_RETRIES - 1; i++ ) {
			script.push( gateway );
		}
		script.push( view( { status: 'done' } ) );

		const { result } = await run( view(), script );

		expect( result.outcome ).toBe( ENDED );
	} );

	it( 'stops with the error after the last failed attempt', async () => {
		const error = { status: 409, code: 'lw_migration_job_busy' };
		const script = Array.from( { length: MAX_RETRIES }, () => ( {
			throws: error,
		} ) );
		const { result, tick } = await run( view(), script );

		expect( result ).toEqual( { outcome: FAILED, error } );
		expect( tick ).toHaveBeenCalledTimes( MAX_RETRIES );
	} );

	it( 'reads the job again on a REREAD refusal and goes on from it', async () => {
		const reread = view( { progress: 40 } );
		const read = vi.fn( async () => reread );
		const { result, onView } = await run(
			view(),
			[
				{
					throws: {
						status: 409,
						code: 'lw_migration_unexpected_input',
					},
				},
				view( { status: 'done' } ),
			],
			{ read }
		);

		expect( result.outcome ).toBe( ENDED );
		expect( read ).toHaveBeenCalledWith( 'abcdef0123456789' );
		expect( onView ).toHaveBeenCalledWith( reread );
	} );

	it( 'stops at once when the re-read job waits for input', async () => {
		const asking = view( {
			status: 'needs_input',
			input: { kind: 'password', reason: 'encrypted' },
		} );
		const { result, tick } = await run(
			view(),
			[ { throws: { status: 409, code: 'lw_migration_not_uploading' } } ],
			{ read: vi.fn( async () => asking ) }
		);

		expect( result.outcome ).toBe( WAITING );
		expect( tick ).toHaveBeenCalledTimes( 1 );
	} );

	it.each( [
		[ '401', { status: 401, code: 'rest_not_logged_in' } ],
		[ 'invalid nonce', { status: 403, code: 'rest_cookie_invalid_nonce' } ],
	] )( 'stops as logged out on %s, without retrying', async ( _n, error ) => {
		const { result, tick, wait } = await run( view(), [
			{ throws: error },
		] );

		expect( result ).toEqual( { outcome: LOGGED_OUT, error } );
		expect( tick ).toHaveBeenCalledTimes( 1 );
		expect( wait ).not.toHaveBeenCalled();
	} );

	it.each( [
		[ 'no permission', { status: 403, code: 'rest_forbidden' } ],
		[
			'site busy (server text kept)',
			{
				status: 409,
				code: 'lw_migration_site_busy',
				message: 'Another job holds the site until 14:02.',
			},
		],
		[ 'storage', { status: 500, code: 'lw_migration_storage' } ],
		[ 'job gone', { status: 404, code: 'lw_migration_no_job' } ],
	] )( 'stops at once on %s', async ( _n, error ) => {
		const { result, tick, wait } = await run( view(), [
			{ throws: error },
		] );

		expect( result ).toEqual( { outcome: FAILED, error } );
		expect( tick ).toHaveBeenCalledTimes( 1 );
		expect( wait ).not.toHaveBeenCalled();
	} );

	it( 'stops quietly when the aborted tick rejects', async () => {
		let stop = false;
		const tick = vi.fn( async () => {
			stop = true;
			throw { name: 'AbortError', code: 20 };
		} );
		const wait = vi.fn();
		const result = await runTicks( view(), {
			tick,
			read: vi.fn(),
			wait,
			onView: vi.fn(),
			stopped: () => stop,
		} );

		expect( result.outcome ).toBe( STOPPED );
		expect( wait ).not.toHaveBeenCalled();
	} );

	it( 'drops a view that arrives after the loop was stopped', async () => {
		let stop = false;
		const tick = vi.fn( async () => {
			stop = true;
			return view( { progress: 90 } );
		} );
		const onView = vi.fn();
		const result = await runTicks( view(), {
			tick,
			read: vi.fn(),
			wait: vi.fn(),
			onView,
			stopped: () => stop,
		} );

		expect( result.outcome ).toBe( STOPPED );
		expect( onView ).not.toHaveBeenCalled();
	} );
} );

describe( 'sleep', () => {
	afterEach( () => {
		vi.useRealTimers();
	} );

	it( 'resolves after the time', async () => {
		vi.useFakeTimers();
		let done = false;
		sleep( 1500 ).then( () => ( done = true ) );
		await vi.advanceTimersByTimeAsync( 1499 );
		expect( done ).toBe( false );
		await vi.advanceTimersByTimeAsync( 1 );
		expect( done ).toBe( true );
	} );

	it( 'ends early and clears its timer when a signal aborts', async () => {
		vi.useFakeTimers();
		const controller = new AbortController();
		let done = false;
		sleep( 60000, [ null, controller.signal ] ).then(
			() => ( done = true )
		);
		expect( vi.getTimerCount() ).toBe( 1 );
		controller.abort();
		await Promise.resolve();
		expect( done ).toBe( true );
		expect( vi.getTimerCount() ).toBe( 0 );
	} );

	it( 'does not wait at all on an already aborted signal', async () => {
		vi.useFakeTimers();
		const controller = new AbortController();
		controller.abort();
		await sleep( 60000, [ controller.signal ] );
		expect( vi.getTimerCount() ).toBe( 0 );
	} );
} );

describe( 'cancel while the loop runs', () => {
	it( 'waits for the in-flight tick before cancelling, and starts no new tick', async () => {
		let finishTick;
		const tick = vi.fn(
			() =>
				new Promise( ( resolve ) => {
					finishTick = () => resolve( view( { progress: 30 } ) );
				} )
		);
		const pauser = new AbortController();
		const onView = vi.fn();
		const loop = runTicks( view(), {
			tick,
			read: vi.fn(),
			wait: ( ms ) => sleep( ms, [ pauser.signal ] ),
			onView,
			stopped: () => pauser.signal.aborted,
		} );
		const cancelled = { job: view( { status: 'cancelled' } ) };
		const remove = vi.fn( async () => cancelled );

		const result = cancelAfterLoop( () => pauser.abort(), loop, remove );
		await Promise.resolve();
		expect( remove ).not.toHaveBeenCalled();

		finishTick();
		await expect( result ).resolves.toBe( cancelled );
		await expect( loop ).resolves.toEqual( { outcome: STOPPED } );
		expect( tick ).toHaveBeenCalledTimes( 1 );
		expect( remove ).toHaveBeenCalledTimes( 1 );
		// The view of the tick that lost to the cancel is not shown.
		expect( onView ).not.toHaveBeenCalled();
	} );

	it( 'ends an idle wait at once instead of sitting it out', async () => {
		const pauser = new AbortController();
		const same = view();
		let ticks = 0;
		const loop = runTicks( view(), {
			tick: async () => {
				ticks++;
				return same;
			},
			read: vi.fn(),
			wait: ( ms ) => sleep( ms, [ pauser.signal ] ),
			onView: vi.fn(),
			stopped: () => pauser.signal.aborted,
		} );
		await new Promise( ( resolve ) => setTimeout( resolve, 10 ) );
		const started = Date.now();
		await cancelAfterLoop(
			() => pauser.abort(),
			loop,
			async () => ( {} )
		);

		expect( Date.now() - started ).toBeLessThan( IDLE_WAIT_MS );
		expect( ticks ).toBe( 1 );
	} );

	it( 'cancels at once when no loop runs', async () => {
		const remove = vi.fn( async () => 'answer' );
		await expect( cancelAfterLoop( () => {}, null, remove ) ).resolves.toBe(
			'answer'
		);
	} );

	it( 'drives on only for an unfinished or deferred cancel', () => {
		expect(
			drivesOnAfterCancel( { job: view( { status: 'cancelled' } ) } )
		).toBe( false );
		expect(
			drivesOnAfterCancel( {
				job: view( { status: 'running' } ),
				cancelDeferred: true,
			} )
		).toBe( true );
		expect(
			drivesOnAfterCancel( { job: view( { status: 'running' } ) } )
		).toBe( true );
	} );
} );

describe( 'cancelFailure', () => {
	it( 'stops driving and asks for a new login after a logout', () => {
		for ( const wasDriving of [ true, false ] ) {
			expect(
				cancelFailure(
					{ status: 403, code: 'rest_cookie_invalid_nonce' },
					wasDriving
				)
			).toEqual( { loggedOut: true, stop: true, resume: false } );
			expect(
				cancelFailure( { status: 401, code: 'x' }, wasDriving )
			).toEqual( { loggedOut: true, stop: true, resume: false } );
		}
	} );

	it( 'drives on after any other failure when the loop was running', () => {
		expect(
			cancelFailure( { status: 500, code: 'lw_migration_storage' }, true )
		).toEqual( { loggedOut: false, stop: false, resume: true } );
	} );

	it( 'changes nothing when no loop was running', () => {
		expect(
			cancelFailure(
				{ status: 500, code: 'lw_migration_storage' },
				false
			)
		).toEqual( { loggedOut: false, stop: false, resume: false } );
	} );
} );
