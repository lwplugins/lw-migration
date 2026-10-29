/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import { networkSites, plain, wholeNetwork } from './fixtures/summaries';
import { addressNote, loginText } from './notes';

describe( 'the login note', () => {
	it( 'says the users change but the admin keeps the login', () => {
		expect( loginText( plain, false ) ).toMatch(
			/^After the restore the users come from the backup\. You keep your own username and password/
		);
		expect( loginText( plain, false ) ).toMatch( /stay on this site\.$/ );
		expect( loginText( wholeNetwork, false ) ).toMatch(
			/stay on this network\.$/
		);
	} );

	it( 'says a site restore into a network adds users', () => {
		expect( loginText( networkSites, false ) ).toBe(
			"The archive's users are added to this network. The network's own users stay, and so does your login."
		);
	} );

	it( "names the code-maker's login on a pushed site", () => {
		expect( loginText( plain, true ) ).toMatch( /connection code/ );
	} );

	it( 'is not shown for an archive without a database', () => {
		const noDatabase = {
			...plain,
			parts: {
				...plain.parts,
				database: { included: false, bytes: 0, count: null },
			},
		};
		expect( loginText( noDatabase, false ) ).toBe( '' );
	} );
} );

describe( 'the address note', () => {
	it( 'is shown when the address is replaced', () => {
		expect( addressNote( plain ) ).toEqual( {
			from: 'dev2.example',
			to: 'croco2.example',
		} );
		expect( addressNote( wholeNetwork ) ).not.toBeNull();
	} );

	it( 'shows the whole addresses when only the scheme or folder changes', () => {
		expect(
			addressNote( {
				...plain,
				from: 'http://dev2.example/',
				to: 'https://dev2.example/',
			} )
		).toEqual( {
			from: 'http://dev2.example',
			to: 'https://dev2.example',
		} );
		expect(
			addressNote( {
				...plain,
				from: 'https://dev2.example/old',
				to: 'https://dev2.example',
			} )
		).toEqual( {
			from: 'https://dev2.example/old',
			to: 'https://dev2.example',
		} );
	} );

	it( 'is not shown when it stays, is unknown, or each site has its own row', () => {
		expect(
			addressNote( { ...plain, replacesAddress: false } )
		).toBeNull();
		expect( addressNote( { ...plain, replacesAddress: null } ) ).toBeNull();
		expect( addressNote( networkSites ) ).toBeNull();
	} );
} );
