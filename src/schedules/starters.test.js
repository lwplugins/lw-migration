/**
 * External dependencies
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Internal dependencies
 */
import { NEW_SCHEDULE, checkSchedule, toRequest } from './scheduleForm';
import { whatPhrase } from './what';
import { starters } from './starters';

beforeEach( () => {
	vi.stubGlobal( 'document', { documentElement: { lang: 'en-GB' } } );
} );

afterEach( () => {
	vi.unstubAllGlobals();
} );

describe( 'starters', () => {
	it( 'fill the editor with the three ready-made schedules', () => {
		const [ nightly, weekly, database ] = starters();
		expect( nightly ).toMatchObject( {
			title: 'Daily backup at night',
			text: 'Every day at 03:00, the whole site. The last 7 stay here.',
			form: {
				title: 'Daily backup at night',
				frequency: 'daily',
				hour: 3,
				minute: 0,
				retention: { count: 7, bytes: 0, days: 0 },
			},
		} );
		expect( weekly.form ).toMatchObject( {
			title: 'Weekly full backup',
			frequency: 'weekly',
			weekday: 7,
			hour: 2,
			minute: 30,
			retention: { count: 4, bytes: 0, days: 0 },
		} );
		expect( database.form ).toMatchObject( {
			title: 'Database every 6 hours',
			frequency: 'hours',
			every: 6,
			minute: 15,
			retention: { count: 12, bytes: 0, days: 0 },
		} );
		expect( whatPhrase( nightly.form.export ) ).toBe( 'the whole site' );
		expect( whatPhrase( database.form.export ) ).toBe(
			'only the database'
		);
	} );

	it( 'are new schedules the server takes as they are', () => {
		starters().forEach( ( { form } ) => {
			expect( form.hasPassword ).toBe( false );
			expect( form.enabled ).toBe( true );
			expect( form.notify ).toEqual( NEW_SCHEDULE.notify );
			expect( checkSchedule( form, '', '', false ) ).toBeNull();
			expect( toRequest( form, '', false ).title ).toBe( form.title );
		} );
	} );
} );
