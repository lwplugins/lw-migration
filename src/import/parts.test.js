/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import {
	encrypted,
	networkSites,
	plain,
	subsite,
	unreadable,
	wholeNetwork,
} from './fixtures/summaries';
import { partLine, partStates } from './parts';

const byId = ( summary ) =>
	Object.fromEntries(
		partStates( summary ).map( ( part ) => [ part.id, part ] )
	);

describe( 'what gets replaced', () => {
	it( 'has the four tiles in page order', () => {
		expect( partStates( plain ).map( ( part ) => part.id ) ).toEqual( [
			'database',
			'media',
			'plugins',
			'themes',
		] );
	} );

	it( 'replaces the parts the archive holds and keeps the rest', () => {
		const parts = byId( plain );
		expect( parts.database.state ).toBe( 'replaced' );
		expect( parts.media.state ).toBe( 'replaced' );
		expect( parts.plugins.state ).toBe( 'replaced' );
		expect( parts.themes.state ).toBe( 'absent' );
		expect( parts.plugins.mustUse ).toEqual( {
			state: 'replaced',
			count: 2,
		} );
	} );

	it( 'is unknown where the headers could not be read', () => {
		const parts = byId( unreadable );
		expect( parts.database.state ).toBe( 'unknown' );
		expect( parts.plugins.state ).toBe( 'absent' );
		expect( partLine( parts.database, true ) ).toBe(
			'Could not be read from the archive.'
		);
	} );

	it( 'falls back to the older flags when the summary has no parts', () => {
		const { parts, ...older } = plain;
		expect( Object.keys( parts ) ).toHaveLength( 5 );
		const states = byId( older );
		expect( states.database.state ).toBe( 'replaced' );
		expect( states.themes.state ).toBe( 'absent' );
		expect( states.media.bytes ).toBeNull();
	} );

	it( 'keeps the code of a network a site restore goes into', () => {
		const parts = byId( networkSites );
		expect( parts.plugins.state ).toBe( 'shared' );
		expect( parts.plugins.mustUse.state ).toBe( 'shared' );
		expect( parts.themes.state ).toBe( 'absent' );
		expect( parts.database ).toMatchObject( {
			state: 'replaced',
			scope: 'sites',
		} );
		expect( parts.media.scope ).toBe( 'sites' );
		// A whole network archive replaces the network's code as it is.
		expect( byId( wholeNetwork ).plugins.state ).toBe( 'replaced' );
	} );

	it( "replaces only that site's media for a network site", () => {
		const media = byId( subsite ).media;
		expect( media ).toMatchObject( { state: 'replaced', scope: 'site' } );
		expect( partLine( media, true ) ).toBe(
			"Only that network site's media."
		);
	} );
} );

describe( 'the line under a tile', () => {
	it( 'shows counts and sizes, never a table count', () => {
		const parts = byId( plain );
		expect( partLine( parts.database, true ) ).toMatch( /^\S+ \S+$/ );
		expect( partLine( parts.database, true ) ).not.toMatch( /table/ );
		expect( partLine( parts.media, true ) ).toMatch( /^6,120 files · / );
		expect( partLine( parts.plugins, true ) ).toMatch(
			/^27 plugins · 2 must-use · .+ · this plugin stays$/
		);
		expect( partLine( parts.themes, true ) ).toBe(
			'The current ones stay.'
		);
	} );

	it( 'says the must-use plugins are replaced when the plugins are absent', () => {
		const noPlugins = {
			...plain,
			parts: {
				...plain.parts,
				plugins: { included: false, bytes: 0, count: 0 },
			},
		};
		const parts = byId( noPlugins );
		expect( partLine( parts.plugins, true ) ).toBe(
			'The current plugins stay. The must-use plugins are replaced.'
		);
		const neither = {
			...noPlugins,
			parts: {
				...noPlugins.parts,
				muPlugins: { included: false, bytes: 0, count: 0 },
			},
		};
		expect( partLine( byId( neither ).plugins, true ) ).toBe(
			'The current ones stay.'
		);
	} );

	it( 'labels stored sizes of a packed archive', () => {
		expect( partLine( byId( encrypted ).database, true ) ).toMatch(
			/^stored: /
		);
	} );

	it( "says why a network's code stays", () => {
		expect( partLine( byId( networkSites ).plugins, true ) ).toBe(
			"The network's stay as they are."
		);
		expect( partLine( byId( networkSites ).database, true ) ).toMatch(
			/^Only the archive's sites · /
		);
	} );
} );
