/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import { firstClash, laneOf, position, weekStarts } from './week';

const TZ = 'Europe/Budapest';
const utc = ( month, day, hour, minute = 0 ) =>
	Date.UTC( 2026, month - 1, day, hour, minute ) / 1000;
// Friday 16 October 2026, 15:20 in Budapest.
const NOW = utc( 10, 16, 13, 20 );

describe( 'weekStarts', () => {
	it( 'starts at today’s local midnight and closes the seventh day', () => {
		const starts = weekStarts( NOW, TZ );
		expect( starts ).toHaveLength( 8 );
		expect( starts[ 0 ] ).toBe( utc( 10, 15, 22 ) );
		expect( starts[ 7 ] ).toBe( utc( 10, 22, 22 ) );
	} );

	it( 'gives the DST day its 25 hours', () => {
		const starts = weekStarts( utc( 10, 22, 10 ), TZ );
		// Sunday 25 October: midnight is still UTC+2, the next one UTC+1.
		expect( starts[ 3 ] ).toBe( utc( 10, 24, 22 ) );
		expect( starts[ 4 ] - starts[ 3 ] ).toBe( 25 * 3600 );
	} );
} );

describe( 'position', () => {
	const starts = weekStarts( NOW, TZ );

	it( 'places a moment in its day column', () => {
		expect( position( starts[ 0 ], starts ) ).toBe( 0 );
		expect( position( utc( 10, 16, 10 ), starts ) ).toBeCloseTo(
			( 0.5 / 7 ) * 100
		);
		expect( position( starts[ 3 ], starts ) ).toBeCloseTo(
			( 3 / 7 ) * 100
		);
	} );

	it( 'answers null outside the week', () => {
		expect( position( starts[ 0 ] - 1, starts ) ).toBeNull();
		expect( position( starts[ 7 ], starts ) ).toBeNull();
	} );
} );

describe( 'firstClash', () => {
	const item = ( title, week, enabled = true ) => ( {
		title,
		week,
		enabled,
	} );

	it( 'finds the first minute two switched-on schedules share', () => {
		const at = utc( 10, 17, 1 );
		expect(
			firstClash( [
				item( 'Nightly', [ at, utc( 10, 18, 1 ) ] ),
				item( 'Hourly', [ at + 30, utc( 10, 18, 1, 0 ) ] ),
				item( 'Weekly', [ utc( 10, 18, 1 ) ] ),
			] )
		).toEqual( { at, names: [ 'Nightly', 'Hourly' ] } );
	} );

	it( 'ignores a switched-off schedule and a schedule alone', () => {
		const at = utc( 10, 17, 1 );
		expect(
			firstClash( [
				item( 'Nightly', [ at, at + 20 ] ),
				item( 'Off', [ at ], false ),
				item( 'Broken', undefined ),
			] )
		).toBeNull();
	} );

	it( 'cycles the lane colours', () => {
		expect( [ 0, 1, 3, 4, 9 ].map( laneOf ) ).toEqual( [ 0, 1, 3, 0, 1 ] );
	} );
} );
