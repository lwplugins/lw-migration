/**
 * External dependencies
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Internal dependencies
 */
import {
	clock,
	describeNotify,
	hourLabel,
	retentionLimits,
	describeTiming,
	mailLabel,
	statusLabel,
	statusTone,
	weekdayName,
} from './timing';

const at = ( patch ) => ( {
	frequency: 'daily',
	every: 1,
	minute: 5,
	hour: 3,
	weekday: 1,
	monthday: 1,
	...patch,
} );

// A 24-hour English page; the other languages are pinned below.
beforeEach( () => {
	vi.stubGlobal( 'document', { documentElement: { lang: 'en-GB' } } );
} );

afterEach( () => {
	vi.unstubAllGlobals();
} );

const plain = ( text ) => text.replace( /\s/g, ' ' );

describe( 'describeTiming', () => {
	it( 'says every frequency in one line', () => {
		expect( describeTiming( at( {} ) ) ).toBe( 'Every day at 03:05' );
		expect( describeTiming( at( { frequency: 'hourly' } ) ) ).toBe(
			'Every hour, at minute 5'
		);
		expect( describeTiming( at( { frequency: 'hours', every: 6 } ) ) ).toBe(
			'Every 6 hours, at minute 5'
		);
		expect( describeTiming( at( { frequency: 'days', every: 3 } ) ) ).toBe(
			'Every 3 days at 03:05'
		);
		expect(
			describeTiming( at( { frequency: 'weekly', weekday: 7 } ) )
		).toBe( 'Weekly on Sunday at 03:05' );
		expect(
			describeTiming( at( { frequency: 'monthly', monthday: 8 } ) )
		).toBe( 'Monthly on day 8 at 03:05' );
		expect(
			describeTiming( at( { frequency: 'monthly', monthday: 31 } ) )
		).toBe(
			'Monthly on day 31 at 03:05 (on the last day in shorter months)'
		);
	} );

	it( 'writes the time the way the page language does', () => {
		vi.stubGlobal( 'document', { documentElement: { lang: 'en-US' } } );
		expect( plain( describeTiming( at( {} ) ) ) ).toBe(
			'Every day at 03:05 AM'
		);
		expect( plain( hourLabel( 22 ) ) ).toBe( '10 PM' );
		vi.stubGlobal( 'document', { documentElement: { lang: 'hu' } } );
		expect( clock( 22, 0 ) ).toBe( '22:00' );
		expect( hourLabel( 7 ) ).toBe( '07' );
	} );

	it( 'pads the clock and names the ISO weekdays', () => {
		expect( clock( 7, 0 ) ).toBe( '07:00' );
		expect( weekdayName( 1 ) ).toBe( 'Monday' );
		expect( weekdayName( 8 ) ).toBe( '' );
	} );
} );

describe( 'the other one-liners', () => {
	it( 'describe retention, notification and results', () => {
		expect( retentionLimits( { count: 0, bytes: 0, days: 0 } ) ).toEqual( [
			'Every backup of this schedule',
		] );
		expect( retentionLimits( { count: 7, bytes: 0, days: 30 } ) ).toEqual( [
			'At most 7 backups',
			'At most 30 days old',
		] );
		expect(
			retentionLimits( { count: 1, bytes: 2 * 1073741824, days: 1 } )
		).toEqual( [
			'At most 1 backup',
			'At most 1 day old',
			'At most 2 GB in total',
		] );
		expect( describeNotify( { when: 'off', email: '' } ) ).toBe(
			'No e-mail'
		);
		expect(
			describeNotify( { when: 'failure', email: 'ops@a.test' } )
		).toBe( 'When a run fails, to ops@a.test' );
		expect( describeNotify( { when: 'always', email: '' } ) ).toBe(
			'After every run, to the default address'
		);
		expect( statusLabel( 'done' ) ).toBe( 'Ready' );
		expect( statusTone( 'failed' ) ).toBe( 'critical' );
		expect( statusTone( 'skipped' ) ).toBe( 'warning' );
		expect( mailLabel( 'throttled' ) ).toBe(
			'Not sent (one failure mail a day)'
		);
		expect( mailLabel( '' ) ).toBe( '—' );
	} );

	it( 'labels a partial run as a warning', () => {
		expect( statusTone( 'partial' ) ).toBe( 'warning' );
		expect( statusLabel( 'partial' ) ).not.toBe( statusLabel( 'unknown' ) );
	} );

	it( 'name a mail still on its way and an unknown value sanely', () => {
		expect( mailLabel( 'pending' ) ).toBe( 'No result recorded' );
		expect( mailLabel( 'sent' ) ).toBe( 'Sent' );
		expect( mailLabel( 'failed' ) ).toBe( 'Could not be sent' );
		expect( mailLabel( 'something-new' ) ).toBe( '—' );
		expect( mailLabel( undefined ) ).toBe( '—' );
		expect( mailLabel( 'toString' ) ).toBe( '—' );
		expect( statusLabel( 'constructor' ) ).toBe( 'Unknown' );
		expect( statusLabel( 'something-new' ) ).toBe( 'Unknown' );
		expect( statusTone( 'something-new' ) ).toBe( 'idle' );
	} );
} );
