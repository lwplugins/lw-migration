/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import {
	answerSites,
	editable,
	initialAddresses,
	rowNote,
} from './siteAddresses';

const site = ( index, patch = {} ) => ( {
	index,
	from: { blogId: index + 2, url: `http://old.test/b${ index + 2 }` },
	proposal: `http://127.0.0.1:8896/b${ index + 2 }`,
	address: `http://127.0.0.1:8896/b${ index + 2 }`,
	existing: null,
	error: null,
	...patch,
} );

const summary = ( sites, kind = 'sites' ) => ( {
	network: { kind, sites, unlisted: [], error: null },
} );

describe( 'editable', () => {
	it( 'is true for a sites or single-site archive on a network', () => {
		expect( editable( summary( [], 'sites' ) ) ).toBe( true );
		expect( editable( summary( [], 'single' ) ) ).toBe( true );
	} );

	it( 'is false for a whole-network archive and on a single site', () => {
		expect( editable( summary( [], 'network' ) ) ).toBe( false );
		expect( editable( {} ) ).toBe( false );
	} );
} );

describe( 'initialAddresses', () => {
	it( 'starts each row at the address the server holds, by index', () => {
		expect(
			initialAddresses(
				summary( [
					site( 0 ),
					site( 1, { address: 'http://127.0.0.1:8896/typed' } ),
				] )
			)
		).toEqual( {
			0: 'http://127.0.0.1:8896/b2',
			1: 'http://127.0.0.1:8896/typed',
		} );
	} );

	it( 'falls back to the proposal', () => {
		expect(
			initialAddresses( summary( [ site( 0, { address: '' } ) ] ) )
		).toEqual( { 0: 'http://127.0.0.1:8896/b2' } );
	} );

	it( 'is empty without a network part', () => {
		expect( initialAddresses( {} ) ).toEqual( {} );
	} );
} );

describe( 'answerSites', () => {
	const two = summary( [ site( 0 ), site( 1 ) ] );

	it( 'sends only the rows that changed, by index, trimmed', () => {
		expect(
			answerSites( two, {
				0: 'http://127.0.0.1:8896/b2',
				1: '  http://127.0.0.1:8896/shop ',
			} )
		).toEqual( { 1: 'http://127.0.0.1:8896/shop' } );
	} );

	it( 'is empty when nothing changed', () => {
		expect( answerSites( two, initialAddresses( two ) ) ).toEqual( {} );
	} );

	it( 'sends a cleared row (the server says why it cannot be used)', () => {
		expect(
			answerSites( two, { ...initialAddresses( two ), 0: ' ' } )
		).toEqual( { 0: '' } );
	} );

	it( 'never answers for a whole-network archive', () => {
		const network = summary( [ site( 0 ) ], 'network' );
		expect( answerSites( network, { 0: 'http://x.test' } ) ).toEqual( {} );
	} );
} );

describe( 'rowNote', () => {
	it( 'names a replaced site and a new one', () => {
		expect( rowNote( site( 0, { existing: 5 } ) ) ).toBe(
			'Replaces an existing site'
		);
		expect( rowNote( site( 0 ) ) ).toBe( 'New site' );
	} );

	it( 'says nothing for a typed address the server has not checked yet', () => {
		expect(
			rowNote( site( 0, { existing: 5 } ), 'http://127.0.0.1:8896/x' )
		).toBe( '' );
		expect( rowNote( site( 0 ), 'http://127.0.0.1:8896/b2 ' ) ).toBe(
			'New site'
		);
	} );
} );
