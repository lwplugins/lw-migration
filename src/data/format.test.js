/**
 * External dependencies
 */
import { afterEach, describe, expect, it, vi } from 'vitest';

/**
 * WordPress dependencies
 */
import { getSettings, setSettings } from '@wordpress/date';

/**
 * Internal dependencies
 */
import {
	formatBytes,
	formatClock,
	formatDatetime,
	formatDayTime,
	formatNumber,
	pageLocale,
} from './format';

const DEFAULT_DATE_SETTINGS = getSettings();

/**
 * Runs the date helpers as if the site had this time zone setting.
 *
 * @param {string} string Zone name ('' = offset only).
 * @param {number} offset Hours from UTC.
 */
const siteZone = ( string, offset = 0 ) =>
	setSettings( {
		...DEFAULT_DATE_SETTINGS,
		timezone: { ...DEFAULT_DATE_SETTINGS.timezone, string, offset },
	} );

// Intl may put a narrow no-break space before AM/PM.
const plain = ( text ) => text.replace( /\s/g, ' ' );

// 2026-10-11 20:00 UTC = 22:00 in Budapest (summer time).
const SUNDAY_EVENING = Date.UTC( 2026, 9, 11, 20, 0 ) / 1000;

const MB = 1024 * 1024;

afterEach( () => {
	vi.unstubAllGlobals();
	setSettings( DEFAULT_DATE_SETTINGS );
} );

describe( 'site date and time', () => {
	it( 'writes the day and time the way the page language does, in the site time zone', () => {
		siteZone( 'Europe/Budapest', 2 );
		expect( formatDayTime( SUNDAY_EVENING, 'hu' ) ).toBe(
			'okt. 11. 22:00'
		);
		expect( plain( formatDayTime( SUNDAY_EVENING, 'en-US' ) ) ).toBe(
			'Oct 11, 10:00 PM'
		);
	} );

	it( 'shifts by the offset on a site set to an offset only', () => {
		siteZone( '', 5.5 );
		expect( formatDayTime( SUNDAY_EVENING, 'hu' ) ).toBe(
			'okt. 12. 01:30'
		);
		siteZone( 'Not/AZone', -3 );
		expect( formatDayTime( SUNDAY_EVENING, 'hu' ) ).toBe(
			'okt. 11. 17:00'
		);
	} );

	it( 'says "today" for a time of the current site day', () => {
		siteZone( 'Europe/Budapest', 2 );
		vi.useFakeTimers();
		vi.setSystemTime( ( SUNDAY_EVENING + 3600 ) * 1000 );
		try {
			expect( formatDatetime( SUNDAY_EVENING, 'hu' ) ).toBe(
				'today 22:00'
			);
			expect( formatDatetime( SUNDAY_EVENING - 86400, 'hu' ) ).toBe(
				'okt. 10. 22:00'
			);
		} finally {
			vi.useRealTimers();
		}
		expect( formatDatetime( 0 ) ).toBe( '—' );
	} );

	it( 'writes a clock time in the page language', () => {
		expect( formatClock( 7, 0, 'hu' ) ).toBe( '07:00' );
		expect( plain( formatClock( 22, 5, 'en-US' ) ) ).toBe( '10:05 PM' );
	} );
} );

describe( 'formatBytes', () => {
	it( 'keeps whole numbers and rounds the rest to one decimal', () => {
		expect( formatBytes( 0, 'en-US' ) ).toBe( '0 B' );
		expect( formatBytes( 512, 'en-US' ) ).toBe( '512 B' );
		expect( formatBytes( 1023, 'en-US' ) ).toBe( '1023 B' );
		expect( formatBytes( 1024, 'en-US' ) ).toBe( '1 KB' );
		expect( formatBytes( 1536, 'en-US' ) ).toBe( '1.5 KB' );
		expect( formatBytes( 5 * 1024 * 1024 * 1024, 'en-US' ) ).toBe( '5 GB' );
	} );

	it( 'reads a missing or negative size as zero', () => {
		expect( formatBytes( undefined, 'en-US' ) ).toBe( '0 B' );
		expect( formatBytes( -10, 'en-US' ) ).toBe( '0 B' );
	} );

	it( 'writes the decimal separator of the given locale', () => {
		expect( formatBytes( 20.4 * MB, 'hu-HU' ) ).toBe( '20,4 MB' );
		expect( formatBytes( 20.4 * MB, 'de-DE' ) ).toBe( '20,4 MB' );
		expect( formatBytes( 20.4 * MB, 'en-US' ) ).toBe( '20.4 MB' );
		// No grouping: 1023 B stays one number in every locale.
		expect( formatBytes( 1023, 'hu-HU' ) ).toBe( '1023 B' );
	} );

	it( 'uses the language of the page by default', () => {
		vi.stubGlobal( 'document', { documentElement: { lang: 'hu-HU' } } );
		expect( formatBytes( 20.4 * MB ) ).toBe( '20,4 MB' );
		vi.stubGlobal( 'document', { documentElement: { lang: 'en-US' } } );
		expect( formatBytes( 20.4 * MB ) ).toBe( '20.4 MB' );
	} );
} );

describe( 'pageLocale', () => {
	it( 'is the lang of the page, or undefined (the browser default) without one', () => {
		vi.stubGlobal( 'document', { documentElement: { lang: 'hu-HU' } } );
		expect( pageLocale() ).toBe( 'hu-HU' );
		vi.stubGlobal( 'document', { documentElement: { lang: '' } } );
		expect( pageLocale() ).toBeUndefined();
		vi.stubGlobal( 'document', undefined );
		expect( pageLocale() ).toBeUndefined();
	} );

	it( 'drops a lang the browser cannot format with', () => {
		vi.stubGlobal( 'document', {
			documentElement: { lang: 'not a tag!' },
		} );
		expect( pageLocale() ).toBeUndefined();
		expect( formatBytes( 1536 ) ).toMatch( /^1[.,]5 KB$/ );
	} );
} );

describe( 'formatNumber', () => {
	it( 'groups thousands the way the page language does', () => {
		expect( formatNumber( 12345, 'en-US' ) ).toBe( '12,345' );
		expect( formatNumber( 12345, 'hu-HU' ) ).toBe(
			new Intl.NumberFormat( 'hu-HU' ).format( 12345 )
		);
		expect( formatNumber( 'x', 'en-US' ) ).toBe( '0' );
	} );
} );
