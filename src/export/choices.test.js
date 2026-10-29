/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import { DEFAULTS, toRequest } from '../views/exportRequest';
import {
	advancedCount,
	codeChoice,
	codePatch,
	excludesByName,
} from './choices';

const form = ( patch = {} ) => ( { ...DEFAULTS, ...patch } );

describe( 'code choices', () => {
	it.each( [
		[ 'plugins', 'all', { noPlugins: false, noInactivePlugins: false } ],
		[ 'plugins', 'active', { noPlugins: false, noInactivePlugins: true } ],
		[ 'plugins', 'none', { noPlugins: true, noInactivePlugins: false } ],
		[ 'themes', 'all', { noThemes: false, noInactiveThemes: false } ],
		[ 'themes', 'active', { noThemes: false, noInactiveThemes: true } ],
		[ 'themes', 'none', { noThemes: true, noInactiveThemes: false } ],
	] )( '%s "%s" sets both flags and reads back', ( kind, choice, flags ) => {
		expect( codePatch( kind, choice ) ).toEqual( flags );
		expect( codeChoice( form( flags ), kind ) ).toBe( choice );
		// The request carries exactly these flags.
		const sent = toRequest( form( codePatch( kind, choice ) ), false );
		Object.entries( flags ).forEach( ( [ key, value ] ) =>
			expect( sent[ key ] ).toBe( value )
		);
	} );

	it( 'reads "none" when both flags are set', () => {
		expect(
			codeChoice(
				form( { noPlugins: true, noInactivePlugins: true } ),
				'plugins'
			)
		).toBe( 'none' );
	} );

	it( 'leaves the other kind alone', () => {
		const o = form( {
			...codePatch( 'themes', 'active' ),
			...codePatch( 'plugins', 'none' ),
		} );
		expect( codeChoice( o, 'themes' ) ).toBe( 'active' );
		expect( codeChoice( o, 'plugins' ) ).toBe( 'none' );
	} );
} );

describe( 'advancedCount', () => {
	it( 'is zero for the defaults and an empty pair row', () => {
		expect( advancedCount( form() ) ).toBe( 0 );
		expect( advancedCount( form( { replace: [ [ '', ' ' ] ] } ) ) ).toBe(
			0
		);
	} );

	it( 'counts each Advanced setting once', () => {
		expect(
			advancedCount(
				form( {
					replace: [
						[ 'a', 'b' ],
						[ 'c', 'd' ],
					],
					excludeFiles: 'uploads/videos',
					excludeTables: 'wp_logs',
					includeTables: ' ',
					noEmailReplace: true,
				} )
			)
		).toBe( 4 );
	} );
} );

describe( 'excludesByName', () => {
	it( 'is set by the file and table lists only', () => {
		expect( excludesByName( form() ) ).toBe( false );
		expect( excludesByName( form( { noEmailReplace: true } ) ) ).toBe(
			false
		);
		expect( excludesByName( form( { includeTables: 'wp_posts' } ) ) ).toBe(
			true
		);
		expect( excludesByName( form( { excludeFiles: 'uploads/x' } ) ) ).toBe(
			true
		);
	} );
} );
