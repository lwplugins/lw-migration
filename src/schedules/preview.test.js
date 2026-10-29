/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import { previewRuns } from './preview';
import { dayStart, moment, wall, zoneOf } from './zone';

const TZ = 'Europe/Budapest';
// Friday 16 October 2026, 15:20 in Budapest (CEST, UTC+2).
const NOW = Date.UTC( 2026, 9, 16, 13, 20 ) / 1000;
const utc = ( month, day, hour, minute ) =>
	Date.UTC( 2026, month - 1, day, hour, minute ) / 1000;

const form = ( patch ) => ( {
	frequency: 'daily',
	every: 2,
	minute: 0,
	hour: 3,
	weekday: 1,
	monthday: 1,
	...patch,
} );

describe( 'previewRuns', () => {
	it( 'runs daily at the time, from tomorrow once today’s is past', () => {
		expect( previewRuns( form( {} ), NOW, TZ ) ).toEqual( [
			utc( 10, 17, 1, 0 ),
			utc( 10, 18, 1, 0 ),
			utc( 10, 19, 1, 0 ),
		] );
		expect(
			previewRuns( form( { hour: 18, minute: 5 } ), NOW, TZ, 1 )
		).toEqual( [ utc( 10, 16, 16, 5 ) ] );
	} );

	it( 'runs every hour at the minute', () => {
		expect(
			previewRuns( form( { frequency: 'hourly', minute: 15 } ), NOW, TZ )
		).toEqual( [
			utc( 10, 16, 14, 15 ),
			utc( 10, 16, 15, 15 ),
			utc( 10, 16, 16, 15 ),
		] );
		expect(
			previewRuns(
				form( { frequency: 'hourly', minute: 45 } ),
				NOW,
				TZ,
				1
			)
		).toEqual( [ utc( 10, 16, 13, 45 ) ] );
	} );

	it( 'runs every few hours from the next matching minute', () => {
		expect(
			previewRuns(
				form( { frequency: 'hours', every: 6, minute: 15 } ),
				NOW,
				TZ
			)
		).toEqual( [
			utc( 10, 16, 14, 15 ),
			utc( 10, 16, 20, 15 ),
			utc( 10, 17, 2, 15 ),
		] );
	} );

	it( 'runs every few days counting from today', () => {
		expect(
			previewRuns( form( { frequency: 'days', every: 3 } ), NOW, TZ )
		).toEqual( [
			utc( 10, 19, 1, 0 ),
			utc( 10, 22, 1, 0 ),
			// Winter time from 25 October: 03:00 is UTC+1.
			utc( 10, 25, 2, 0 ),
		] );
		expect(
			previewRuns(
				form( { frequency: 'days', every: 2, hour: 20 } ),
				NOW,
				TZ,
				2
			)
		).toEqual( [ utc( 10, 16, 18, 0 ), utc( 10, 18, 18, 0 ) ] );
	} );

	it( 'runs weekly on the ISO weekday, the first of a doubled hour', () => {
		expect(
			previewRuns(
				form( {
					frequency: 'weekly',
					weekday: 7,
					hour: 2,
					minute: 30,
				} ),
				NOW,
				TZ
			)
		).toEqual( [
			utc( 10, 18, 0, 30 ),
			// 02:30 happens twice on 25 October: the first one (still UTC+2).
			utc( 10, 25, 0, 30 ),
			utc( 11, 1, 1, 30 ),
		] );
		expect(
			previewRuns(
				form( { frequency: 'weekly', weekday: 5, hour: 18 } ),
				NOW,
				TZ,
				1
			)
		).toEqual( [ utc( 10, 16, 16, 0 ) ] );
	} );

	it( 'runs monthly on the day, on the last day of a shorter month', () => {
		expect(
			previewRuns(
				form( { frequency: 'monthly', monthday: 31 } ),
				NOW,
				TZ
			)
		).toEqual( [
			utc( 10, 31, 2, 0 ),
			utc( 11, 30, 2, 0 ),
			utc( 12, 31, 2, 0 ),
		] );
		expect(
			previewRuns(
				form( { frequency: 'monthly', monthday: 1 } ),
				NOW,
				TZ,
				1
			)
		).toEqual( [ utc( 11, 1, 2, 0 ) ] );
	} );

	it( 'reads an offset-only site zone', () => {
		expect( previewRuns( form( {} ), NOW, '+05:30', 1 ) ).toEqual( [
			utc( 10, 16, 21, 30 ),
		] );
	} );
} );

describe( 'zone arithmetic', () => {
	it( 'finds local midnights across the DST change', () => {
		const zone = zoneOf( TZ );
		expect( dayStart( NOW, zone ) ).toBe( utc( 10, 15, 22, 0 ) );
		expect( dayStart( utc( 10, 25, 12, 0 ), zone, 1 ) ).toBe(
			utc( 10, 25, 23, 0 )
		);
		expect( moment( zone, 2026, 10, 32 ) ).toBe( utc( 10, 31, 23, 0 ) );
		expect( wall( NOW, zone ) ).toMatchObject( { hour: 15, minute: 20 } );
	} );

	it( 'falls back to UTC for a zone the browser does not know', () => {
		expect( zoneOf( 'Nowhere/Atlantis' ) ).toEqual( {
			timeZone: 'UTC',
			shift: 0,
		} );
		expect( zoneOf( '-03:00' ).shift ).toBe( -10800 );
	} );
} );
