/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import { compareState, factRows } from './compare';
import {
	encrypted,
	lowSpace,
	networkSites,
	plain,
	reference,
	unreadable,
	wholeNetwork,
} from './fixtures/summaries';

const row = ( summary, id ) =>
	factRows( summary ).find( ( fact ) => fact.id === id );

describe( 'compareState', () => {
	it( 'tells same, changed and unknown apart', () => {
		expect( compareState( '7.1', '7.1' ) ).toBe( 'same' );
		expect( compareState( '8.2.29', '8.4.20' ) ).toBe( 'changed' );
		expect( compareState( '', '7.1' ) ).toBe( 'unknown' );
		expect( compareState( null, 'wp_' ) ).toBe( 'unknown' );
	} );
} );

describe( 'the comparison rows', () => {
	it( 'come in the order of the check view', () => {
		expect( factRows( plain ).map( ( fact ) => fact.id ) ).toEqual( [
			'address',
			'wordpress',
			'php',
			'prefix',
			'space',
			'encryption',
		] );
	} );

	it( 'mark what differs between the archive and this site', () => {
		expect( row( plain, 'address' ) ).toMatchObject( {
			archive: 'https://dev2.example',
			site: 'https://croco2.example',
			state: 'changed',
		} );
		expect( row( plain, 'wordpress' ).state ).toBe( 'same' );
		expect( row( plain, 'php' ).state ).toBe( 'changed' );
		expect( row( plain, 'prefix' ) ).toMatchObject( {
			archive: 'wp_',
			site: 'wp_ (stays)',
			state: 'same',
		} );
		expect( row( encrypted, 'address' ).state ).toBe( 'same' );
	} );

	it( 'leave the address of a site restore into a network to its sites', () => {
		expect( row( networkSites, 'address' ) ).toMatchObject( {
			site: 'each site gets its own below',
			state: 'info',
		} );
		expect( row( wholeNetwork, 'address' ).state ).toBe( 'changed' );
	} );

	it( 'show what the reference tool leaves out as unknown', () => {
		expect( row( reference, 'wordpress' ) ).toMatchObject( {
			archive: 'unknown',
			state: 'unknown',
		} );
		expect( row( reference, 'php' ).state ).toBe( 'unknown' );
		expect( row( reference, 'prefix' ) ).toMatchObject( {
			archive: 'unknown',
			state: 'unknown',
		} );
		expect( row( unreadable, 'address' ) ).toMatchObject( {
			archive: 'unknown',
			state: 'unknown',
		} );
	} );

	it( 'compare the expanded size with the free space', () => {
		const space = row( plain, 'space' );
		expect( space.archive ).toMatch( /^about .+ expanded$/ );
		expect( space.site ).toMatch( / free$/ );
		expect( space.warn ).toBe( false );
		expect( row( encrypted, 'space' ).archive ).toBe(
			'expanded size unknown'
		);
		expect( row( unreadable, 'space' ).site ).toBe( 'free space unknown' );
		expect( row( lowSpace, 'space' ).warn ).toBe( true );
		expect( factRows( lowSpace, false ).at( 4 ).warn ).toBe( false );
	} );

	it( 'say whether the archive is encrypted', () => {
		expect( row( plain, 'encryption' ).archive ).toBe( 'none' );
		expect( row( encrypted, 'encryption' ).archive ).toBe(
			'encrypted, password accepted'
		);
	} );
} );
