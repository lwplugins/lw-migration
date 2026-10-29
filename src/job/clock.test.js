/**
 * External dependencies
 */
import { afterEach, describe, expect, it, vi } from 'vitest';

/**
 * Internal dependencies
 */
import { runtime } from '../backups/hookRuntime';
import { elapsedSeconds, formatElapsed, jobTime } from './clock';
import JobClock from './JobClock';

vi.mock( '@wordpress/element', async () => ( {
	...( await import( '../backups/hookRuntime' ) ).runtime.element,
} ) );

vi.mock( '@wordpress/date', () => ( {
	getSettings: () => ( { timezone: { string: 'UTC', offset: 0 } } ),
} ) );

const at = ( day, hour, minute, second = 0 ) =>
	Date.UTC( 2026, 9, day, hour, minute, second ) / 1000;
const STARTED = at( 16, 15, 20 );

describe( 'elapsedSeconds', () => {
	it( 'counts whole seconds from the start', () => {
		expect( elapsedSeconds( STARTED, STARTED + 134.9 ) ).toBe( 134 );
		expect( elapsedSeconds( String( STARTED ), STARTED + 5 ) ).toBe( 5 );
	} );

	it( 'never goes below zero when the server clock is ahead', () => {
		expect( elapsedSeconds( STARTED, STARTED - 40 ) ).toBe( 0 );
	} );

	it( 'knows nothing without a start', () => {
		expect( elapsedSeconds( 0, STARTED ) ).toBeNull();
		expect( elapsedSeconds( undefined, STARTED ) ).toBeNull();
	} );
} );

describe( 'formatElapsed', () => {
	it( 'writes seconds, minutes and hours', () => {
		expect( formatElapsed( 0 ) ).toBe( '0 s' );
		expect( formatElapsed( 14 ) ).toBe( '14 s' );
		expect( formatElapsed( 134 ) ).toBe( '2 min 14 s' );
		expect( formatElapsed( 3600 ) ).toBe( '1 h 0 min' );
		expect( formatElapsed( 3794 ) ).toBe( '1 h 3 min' );
		expect( formatElapsed( -5 ) ).toBe( '0 s' );
	} );
} );

describe( 'jobTime', () => {
	it( 'writes only the time on the same site day, the day before it otherwise', () => {
		expect( jobTime( STARTED, at( 16, 23, 59 ), 'en-GB' ) ).toBe( '15:20' );
		expect( jobTime( STARTED, at( 17, 0, 5 ), 'en-GB' ) ).toBe(
			'16 Oct, 15:20'
		);
	} );
} );

describe( 'JobClock', () => {
	afterEach( () => {
		vi.useRealTimers();
	} );

	/**
	 * @param {Object} tree The clock's element.
	 * @return {string} Its elapsed time.
	 */
	const elapsedOf = ( tree ) => tree.props.children[ 2 ].props.children;

	it( 'ticks once a second by itself', () => {
		vi.useFakeTimers();
		vi.setSystemTime( ( STARTED + 134 ) * 1000 );
		const clock = runtime.mount( ( props ) => JobClock( props ), {
			createdAt: STARTED,
		} );
		// The time of day in the page language (Node's default here).
		expect( clock.result().props.children[ 0 ] ).toMatch(
			/^Started 0?3:20|^Started 15:20/
		);
		expect( elapsedOf( clock.result() ) ).toBe( '2 min 14 s' );
		vi.advanceTimersByTime( 1000 );
		expect( elapsedOf( clock.result() ) ).toBe( '2 min 15 s' );
		clock.unmount();
	} );

	it( 'shows zero, not a negative time, when the server clock is ahead', () => {
		vi.useFakeTimers();
		vi.setSystemTime( ( STARTED - 30 ) * 1000 );
		const clock = runtime.mount( ( props ) => JobClock( props ), {
			createdAt: STARTED,
		} );
		expect( elapsedOf( clock.result() ) ).toBe( '0 s' );
		clock.unmount();
	} );

	it( 'stands still at the last progress while the job does not move', () => {
		vi.useFakeTimers();
		vi.setSystemTime( ( STARTED + 3600 ) * 1000 );
		const clock = runtime.mount( ( props ) => JobClock( props ), {
			createdAt: STARTED,
			stoppedAt: STARTED + 134,
		} );
		expect( elapsedOf( clock.result() ) ).toBe( '2 min 14 s' );
		vi.advanceTimersByTime( 5000 );
		expect( elapsedOf( clock.result() ) ).toBe( '2 min 14 s' );
		clock.unmount();
	} );

	it( 'shows nothing without a start', () => {
		const clock = runtime.mount( ( props ) => JobClock( props ), {
			createdAt: 0,
		} );
		expect( clock.result() ).toBeNull();
		clock.unmount();
	} );
} );
