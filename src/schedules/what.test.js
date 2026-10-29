/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import { DEFAULTS } from '../views/exportRequest';
import { liveSentence } from './sentence';
import { scopeLabel, scopeOf, whatPhrase } from './what';

const DB_ONLY = { noMedia: true, noPlugins: true, noThemes: true };
const SOME = { noDatabase: true, noThemes: true };
const options = ( patch ) => ( { ...DEFAULTS, ...patch } );

describe( 'scopeOf', () => {
	it( 'reads this site, the whole network or the chosen sites', () => {
		expect( scopeOf( options( {} ), false ) ).toEqual( {
			kind: 'site',
			count: 1,
		} );
		expect( scopeOf( options( {} ), true ) ).toEqual( {
			kind: 'network',
			count: 0,
		} );
		expect(
			scopeOf( options( { scope: 'sites', sites: [ 2, 3 ] } ), true )
		).toEqual( { kind: 'sites', count: 2 } );
		// A saved item has no `scope`: its sites tell.
		expect( scopeOf( { sites: [ 4 ] }, true ) ).toEqual( {
			kind: 'sites',
			count: 1,
		} );
		expect( scopeOf( { sites: [] }, true ).kind ).toBe( 'network' );
	} );
} );

describe( 'whatPhrase on a single site', () => {
	it( 'names the site', () => {
		expect( whatPhrase( options( {} ), 'en-GB', false ) ).toBe(
			'the whole site'
		);
		expect( whatPhrase( options( DB_ONLY ), 'en-GB', false ) ).toBe(
			'only the database'
		);
		expect( whatPhrase( options( SOME ), 'en-GB', false ) ).toBe(
			'these parts: media and plugins'
		);
	} );
} );

describe( 'whatPhrase on a network', () => {
	it( 'names the whole network', () => {
		expect( whatPhrase( options( {} ), 'en-GB', true ) ).toBe(
			'the whole network'
		);
		expect( whatPhrase( options( DB_ONLY ), 'en-GB', true ) ).toBe(
			'only the database of the whole network'
		);
		expect( whatPhrase( options( SOME ), 'en-GB', true ) ).toBe(
			'these parts of the whole network: media and plugins'
		);
	} );

	it( 'names the chosen sites, one or more', () => {
		const one = { scope: 'sites', sites: [ 3 ] };
		const two = { scope: 'sites', sites: [ 2, 3 ] };
		expect( whatPhrase( options( one ), 'en-GB', true ) ).toBe(
			'the 1 chosen site'
		);
		expect( whatPhrase( options( two ), 'en-GB', true ) ).toBe(
			'the 2 chosen sites'
		);
		expect(
			whatPhrase( options( { ...two, ...DB_ONLY } ), 'en-GB', true )
		).toBe( 'only the database of the 2 chosen sites' );
		expect(
			whatPhrase( options( { ...one, ...SOME } ), 'en-GB', true )
		).toBe( 'these parts of the 1 chosen site: media and plugins' );
	} );

	it( 'asks for a site or a part when none is chosen', () => {
		expect(
			whatPhrase(
				options( { scope: 'sites', sites: [] } ),
				'en-GB',
				true
			)
		).toBe( 'no site yet (choose at least one)' );
		expect(
			whatPhrase(
				options( {
					noDatabase: true,
					noMedia: true,
					noPlugins: true,
					noThemes: true,
				} ),
				'en-GB',
				true
			)
		).toBe( 'nothing yet (choose at least one part)' );
	} );

	it( 'puts the scope into the live sentence', () => {
		const form = {
			frequency: 'daily',
			every: 2,
			minute: 0,
			hour: 3,
			weekday: 1,
			monthday: 1,
			keepLocal: true,
			notify: { when: 'off', email: '' },
			export: options( { scope: 'sites', sites: [ 2, 3 ] } ),
		};
		expect( liveSentence( form, [], 'en-GB', true ).timing ).toMatch(
			/^<b>Every day at .+<\/b>, it backs up the 2 chosen sites\.$/
		);
	} );
} );

describe( 'scopeLabel', () => {
	it( 'names the scope on a network only', () => {
		expect( scopeLabel( { sites: [] }, false ) ).toBe( '' );
		expect( scopeLabel( { sites: [] }, true ) ).toBe( 'The whole network' );
		expect( scopeLabel( { sites: [ 2 ] }, true ) ).toBe( '1 chosen site' );
		expect( scopeLabel( { sites: [ 2, 5 ] }, true ) ).toBe(
			'2 chosen sites'
		);
	} );
} );
