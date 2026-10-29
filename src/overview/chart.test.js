/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import {
	BOX,
	axisScale,
	barTitle,
	dayLabel,
	dayName,
	labelled,
	lastDays,
	layout,
	tickLabel,
	totals,
} from './chart';

const GB = 1024 * 1024 * 1024;
const MB = 1024 * 1024;

const days = ( count, fill = () => [] ) =>
	Array.from( { length: count }, ( _, index ) => ( {
		day: `2026-10-${ String( index + 1 ).padStart( 2, '0' ) }`,
		backups: fill( index ),
	} ) );

describe( 'lastDays', () => {
	it( 'keeps the newest days, oldest first', () => {
		const history = days( 30 );
		const shown = lastDays( history, 14 );
		expect( shown ).toHaveLength( 14 );
		expect( shown[ 0 ].day ).toBe( '2026-10-17' );
		expect( shown[ 13 ].day ).toBe( '2026-10-30' );
	} );

	it( 'takes a missing history as no days', () => {
		expect( lastDays( undefined, 14 ) ).toEqual( [] );
	} );
} );

describe( 'totals', () => {
	it( 'counts backups, not failed runs, and adds only the known sizes', () => {
		const entries = [
			{
				day: '2026-10-15',
				backups: [
					{ size: GB, state: 'cloud', manual: false },
					{ size: null, state: 'cloud', manual: false },
				],
			},
			{
				day: '2026-10-16',
				backups: [ { size: 0, state: 'failed', manual: false } ],
			},
		];
		expect( totals( entries ) ).toEqual( {
			count: 2,
			bytes: GB,
			failed: 1,
		} );
	} );
} );

describe( 'axisScale', () => {
	it( 'puts 1.87 GB under a 2 GB top in half-GB steps (the mockup)', () => {
		const scale = axisScale( 1.87 * GB );
		expect( scale.unit ).toBe( 3 );
		expect( scale.ticks ).toEqual( [ 0, 0.5, 1, 1.5, 2 ] );
		expect( scale.topBytes ).toBe( 2 * GB );
	} );

	it( 'writes a small site in MB', () => {
		const scale = axisScale( 37 * MB );
		expect( scale.unit ).toBe( 2 );
		expect( scale.ticks ).toEqual( [ 0, 10, 20, 30, 40 ] );
		expect( scale.topBytes ).toBe( 40 * MB );
	} );

	it( 'keeps an exact round size as the top', () => {
		expect( axisScale( GB ).ticks ).toEqual( [ 0, 0.25, 0.5, 0.75, 1 ] );
	} );

	it( 'never divides by zero when nothing has a size', () => {
		const scale = axisScale( 0 );
		expect( scale.topBytes ).toBeGreaterThan( 0 );
		expect( scale.ticks ).toEqual( [ 0, 1 ] );
	} );
} );

describe( 'labels', () => {
	it( 'writes the axis in the page language', () => {
		expect( tickLabel( 1.5, 3, 'hu-HU' ) ).toBe( '1,5 GB' );
		expect( tickLabel( 1.5, 3, 'en-US' ) ).toBe( '1.5 GB' );
	} );

	it( "writes the server's day as that day, whatever the browser's zone", () => {
		// 2026-10-16 is the site's day: never shifted to the 15th or 17th.
		expect( dayLabel( '2026-10-16', 'en-US' ) ).toBe( '10/16' );
		expect( dayName( '2026-10-16', 'en-US' ) ).toBe( 'Oct 16' );
		expect( dayName( '2026-01-01', 'en-US' ) ).toBe( 'Jan 1' );
	} );

	it( 'labels today and every second day back (every fifth over 30)', () => {
		expect( [ 0, 1, 2, 3, 4 ].map( ( n ) => labelled( n, 14 ) ) ).toEqual( [
			true,
			false,
			true,
			false,
			true,
		] );
		expect( [ 0, 1, 4, 5, 10 ].map( ( n ) => labelled( n, 30 ) ) ).toEqual(
			[ true, false, false, true, true ]
		);
	} );

	it( 'titles a bar with its day, size and place', () => {
		expect(
			barTitle(
				'2026-10-16',
				{ size: 1.5 * GB, state: 'cloud', manual: false },
				'en-US'
			)
		).toBe( 'Oct 16: 1.5 GB, uploaded to the cloud too' );
		expect(
			barTitle(
				'2026-10-16',
				{ size: null, state: 'local', manual: true },
				'en-US'
			)
		).toBe( 'Oct 16: manual backup, size unknown, only on this server' );
		expect(
			barTitle(
				'2026-10-08',
				{ size: 0, state: 'failed', manual: false },
				'en-US'
			)
		).toBe( 'Oct 8: the scheduled backup failed' );
	} );
} );

describe( 'layout', () => {
	const base = BOX.height - BOX.bottom;

	it( 'draws each bar to scale against the axis top', () => {
		const entries = days( 14, ( index ) =>
			13 === index
				? [ { size: 2 * GB, state: 'cloud', manual: false } ]
				: [ { size: GB, state: 'local', manual: false } ]
		);
		const drawing = layout( entries, 'en-US' );
		const plot = BOX.height - BOX.top - BOX.bottom;
		const full = drawing.days[ 13 ].marks[ 0 ];
		const half = drawing.days[ 0 ].marks[ 0 ];
		expect( drawing.base ).toBe( base );
		expect( full.height ).toBeCloseTo( plot );
		expect( full.y ).toBeCloseTo( BOX.top );
		expect( half.height ).toBeCloseTo( plot / 2 );
		expect( half.kind ).toBe( 'local' );
		expect( full.kind ).toBe( 'cloud' );
		expect( drawing.grid.map( ( line ) => line.label ) ).toEqual( [
			'0 GB',
			'0.5 GB',
			'1 GB',
			'1.5 GB',
			'2 GB',
		] );
	} );

	it( 'spreads the days over the width it is given, keeping the margins', () => {
		const narrow = layout( days( 14 ), 'en-US', 520 );
		const wide = layout( days( 14 ), 'en-US', 1200 );
		const slot = ( width ) => ( width - BOX.left - BOX.right ) / 14;
		expect( narrow.days[ 0 ].x ).toBeCloseTo( BOX.left + slot( 520 ) / 2 );
		expect( wide.days[ 13 ].x ).toBeCloseTo(
			1200 - BOX.right - slot( 1200 ) / 2
		);
	} );

	it( 'labels today as today and leaves the days between unlabelled', () => {
		const drawing = layout( days( 14 ), 'en-US' );
		expect( drawing.days[ 13 ].label ).toBe( 'today' );
		expect( drawing.days[ 12 ].label ).toBeNull();
		expect( drawing.days[ 11 ].label ).toBe( '10/12' );
	} );

	it( 'marks a failed run with a cross and an unknown size with a short bar', () => {
		const entries = [
			{
				day: '2026-10-16',
				backups: [
					{ size: 0, state: 'failed', manual: false },
					{ size: null, state: 'cloud', manual: false },
				],
			},
		];
		const [ failed, unknown ] = layout( entries, 'en-US' ).days[ 0 ].marks;
		expect( failed.kind ).toBe( 'failed' );
		expect( failed.title ).toBe( 'Oct 16: the scheduled backup failed' );
		expect( unknown.kind ).toBe( 'cloud' );
		expect( unknown.height ).toBeGreaterThan( 0 );
		expect( Number.isFinite( unknown.y ) ).toBe( true );
	} );

	it( 'puts several backups of a day side by side within the day', () => {
		const entries = days( 14, ( index ) =>
			0 === index
				? [
						{ size: GB, state: 'cloud', manual: false },
						{ size: GB, state: 'local', manual: true },
					]
				: []
		);
		const [ first, second ] = layout( entries, 'en-US' ).days[ 0 ].marks;
		expect( second.x ).toBeGreaterThan( first.x + first.width - 0.001 );
		expect( first.width ).toBeLessThan( 26 / 2 );
	} );
} );
