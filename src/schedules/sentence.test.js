/**
 * External dependencies
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Internal dependencies
 */
import { DEFAULTS } from '../views/exportRequest';
import {
	liveSentence,
	mailSentence,
	timingSentence,
	whereSentence,
} from './sentence';
import { whatPhrase } from './what';

beforeEach( () => {
	vi.stubGlobal( 'document', { documentElement: { lang: 'en-GB' } } );
} );

afterEach( () => {
	vi.unstubAllGlobals();
} );

const form = ( patch ) => ( {
	frequency: 'daily',
	every: 2,
	minute: 5,
	hour: 3,
	weekday: 7,
	monthday: 8,
	keepLocal: true,
	notify: { when: 'failure', email: '' },
	export: DEFAULTS,
	...patch,
} );

describe( 'timingSentence', () => {
	it( 'has a whole sentence for every frequency', () => {
		const what = 'the whole site';
		expect( timingSentence( form( {} ), what ) ).toBe(
			'<b>Every day at 03:05</b>, it backs up the whole site.'
		);
		expect( timingSentence( form( { frequency: 'hourly' } ), what ) ).toBe(
			'<b>Every hour at minute 5</b>, it backs up the whole site.'
		);
		expect(
			timingSentence( form( { frequency: 'hours', every: 6 } ), what )
		).toBe(
			'<b>Every 6 hours at minute 5</b>, it backs up the whole site.'
		);
		expect(
			timingSentence( form( { frequency: 'days', every: 3 } ), what )
		).toBe( '<b>Every 3 days at 03:05</b>, it backs up the whole site.' );
		expect( timingSentence( form( { frequency: 'weekly' } ), what ) ).toBe(
			'<b>Every Sunday at 03:05</b>, it backs up the whole site.'
		);
		expect( timingSentence( form( { frequency: 'monthly' } ), what ) ).toBe(
			'<b>On day 8 of every month at 03:05</b>, it backs up the whole site.'
		);
	} );
} );

describe( 'the other sentences', () => {
	it( 'name what a run backs up', () => {
		expect( whatPhrase( DEFAULTS, 'en-GB', false ) ).toBe(
			'the whole site'
		);
		expect(
			whatPhrase( {
				...DEFAULTS,
				noMedia: true,
				noPlugins: true,
				noThemes: true,
			} )
		).toBe( 'only the database' );
		expect(
			whatPhrase( { ...DEFAULTS, noDatabase: true, noThemes: true } )
		).toBe( 'these parts: media and plugins' );
		expect(
			whatPhrase( {
				...DEFAULTS,
				noDatabase: true,
				noMedia: true,
				noPlugins: true,
				noThemes: true,
			} )
		).toBe( 'nothing yet (choose at least one part)' );
	} );

	it( 'say where the backups go and when mail goes out', () => {
		expect( whereSentence( form( {} ), [] ) ).toBe(
			'The backups stay on this server.'
		);
		expect( whereSentence( form( {} ), [ 'Office', 'Dropbox' ] ) ).toBe(
			'They stay on this server and are uploaded to: Office and Dropbox.'
		);
		expect(
			whereSentence( form( { keepLocal: false } ), [ 'Office' ] )
		).toBe(
			'They are uploaded to: Office. The copy on this server is deleted once every upload succeeded.'
		);
		expect( mailSentence( { when: 'off' } ) ).toBe( 'No e-mail is sent.' );
		expect( mailSentence( { when: 'always' } ) ).toBe(
			'An e-mail goes out after every run.'
		);
	} );

	it( 'put the whole sentence together, with the last-day note', () => {
		expect(
			liveSentence( form( { frequency: 'monthly', monthday: 31 } ), [] )
		).toEqual( {
			timing: '<b>On day 31 of every month at 03:05</b>, it backs up the whole site.',
			rest: 'In a month without this day it runs on the last day. The backups stay on this server. An e-mail goes out when a run fails.',
		} );
	} );
} );
