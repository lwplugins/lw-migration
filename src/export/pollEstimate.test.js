/**
 * External dependencies
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Internal dependencies
 */
import { POLL_MS, pollEstimate } from './pollEstimate';

const counting = { complete: false };
const finished = { complete: true };

// Lets the promise chain of one request settle.
const settle = () => vi.advanceTimersByTimeAsync( 0 );

describe( 'pollEstimate', () => {
	beforeEach( () => {
		vi.useFakeTimers();
	} );
	afterEach( () => {
		vi.useRealTimers();
	} );

	it( 'asks again every POLL_MS until the estimate is complete', async () => {
		const answers = [ counting, counting, finished ];
		const fetcher = vi.fn( () => Promise.resolve( answers.shift() ) );
		const onData = vi.fn();
		pollEstimate( { fetcher, onData, onError: vi.fn() } );

		await settle();
		expect( fetcher ).toHaveBeenCalledTimes( 1 );
		await vi.advanceTimersByTimeAsync( POLL_MS - 1 );
		expect( fetcher ).toHaveBeenCalledTimes( 1 );
		await vi.advanceTimersByTimeAsync( 1 );
		expect( fetcher ).toHaveBeenCalledTimes( 2 );
		await vi.advanceTimersByTimeAsync( POLL_MS );
		expect( fetcher ).toHaveBeenCalledTimes( 3 );
		await vi.advanceTimersByTimeAsync( POLL_MS * 4 );
		expect( fetcher ).toHaveBeenCalledTimes( 3 );
		expect( onData.mock.calls.map( ( [ e ] ) => e ) ).toEqual( [
			counting,
			counting,
			finished,
		] );
	} );

	it( 'stops asking and reporting once stopped, and aborts the request', async () => {
		let signal;
		let answer;
		const fetcher = vi.fn( ( given ) => {
			signal = given;
			return new Promise( ( resolve ) => {
				answer = resolve;
			} );
		} );
		const onData = vi.fn();
		const stop = pollEstimate( { fetcher, onData, onError: vi.fn() } );
		await settle();
		stop();
		expect( signal.aborted ).toBe( true );
		answer( counting );
		await vi.advanceTimersByTimeAsync( POLL_MS * 3 );
		expect( onData ).not.toHaveBeenCalled();
		expect( fetcher ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'clears the next ask when stopped between two', async () => {
		const fetcher = vi.fn( () => Promise.resolve( counting ) );
		const stop = pollEstimate( {
			fetcher,
			onData: vi.fn(),
			onError: vi.fn(),
		} );
		await settle();
		stop();
		await vi.advanceTimersByTimeAsync( POLL_MS * 3 );
		expect( fetcher ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'reports a failure and asks no more', async () => {
		const failure = { code: 'rest_forbidden' };
		const fetcher = vi
			.fn()
			.mockResolvedValueOnce( counting )
			.mockRejectedValueOnce( failure );
		const onError = vi.fn();
		pollEstimate( { fetcher, onData: vi.fn(), onError } );
		await settle();
		await vi.advanceTimersByTimeAsync( POLL_MS );
		expect( onError ).toHaveBeenCalledWith( failure );
		await vi.advanceTimersByTimeAsync( POLL_MS * 3 );
		expect( fetcher ).toHaveBeenCalledTimes( 2 );
	} );
} );
