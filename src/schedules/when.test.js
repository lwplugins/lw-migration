/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import { dayLabels, relativeIn, runWriter } from './when';
import { weekStarts } from './week';

const TZ = 'Europe/Budapest';
// Friday 16 October 2026, 15:20 in Budapest.
const NOW = Date.UTC( 2026, 9, 16, 13, 20 ) / 1000;
const plain = ( text ) => text.replace( /\s/g, ' ' );

describe( 'relativeIn', () => {
	it( 'says the wait in minutes, hours and minutes, or days', () => {
		expect( relativeIn( NOW, NOW ) ).toBe( 'due now' );
		expect( relativeIn( NOW - 600, NOW ) ).toBe( 'due now' );
		expect( relativeIn( NOW + 60, NOW ) ).toBe( 'in 1 minute' );
		expect( relativeIn( NOW + 25 * 60, NOW ) ).toBe( 'in 25 minutes' );
		expect( relativeIn( NOW + 3600, NOW ) ).toBe( 'in 1 hour' );
		expect( relativeIn( NOW + 3 * 3600, NOW ) ).toBe( 'in 3 hours' );
		expect( relativeIn( NOW + 3660, NOW ) ).toBe( 'in 1 hour 1 minute' );
		expect( relativeIn( NOW + 11 * 3600 + 40 * 60, NOW ) ).toBe(
			'in 11 hours 40 minutes'
		);
		expect( relativeIn( NOW + 30 * 3600, NOW ) ).toBe( 'in 1 day' );
		expect( relativeIn( NOW + 5 * 86400, NOW ) ).toBe( 'in 5 days' );
	} );
} );

describe( 'runWriter', () => {
	it( 'writes today, tomorrow, then the weekday and date', () => {
		const write = runWriter( NOW, TZ, 'en-GB' );
		expect( write( NOW + 3600 ) ).toBe( 'today 16:20' );
		expect( write( Date.UTC( 2026, 9, 16, 21, 59 ) / 1000 ) ).toBe(
			'today 23:59'
		);
		expect( write( Date.UTC( 2026, 9, 16, 22, 0 ) / 1000 ) ).toBe(
			'tomorrow 00:00'
		);
		expect( plain( write( Date.UTC( 2026, 9, 18, 0, 30 ) / 1000 ) ) ).toBe(
			'Sunday 18 Oct, 02:30'
		);
		expect( write( NOW - 7200 ) ).toBe( 'today 13:20' );
	} );
} );

describe( 'dayLabels', () => {
	it( 'names today and the six days after it', () => {
		const labels = dayLabels( weekStarts( NOW, TZ ), TZ, 'en-GB' );
		expect( labels ).toEqual( [
			'Today 16',
			'Sat 17',
			'Sun 18',
			'Mon 19',
			'Tue 20',
			'Wed 21',
			'Thu 22',
		] );
	} );
} );
