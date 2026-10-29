/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import {
	PER_PAGE,
	label,
	pageCount,
	remember,
	scopeRequest,
	toggle,
} from './sitePicker';

describe( 'toggle', () => {
	it( 'adds a site that is not chosen, at the end', () => {
		expect( toggle( [ 3 ], 7 ) ).toEqual( [ 3, 7 ] );
	} );

	it( 'removes a chosen site and keeps the others in order', () => {
		expect( toggle( [ 3, 7, 9 ], 7 ) ).toEqual( [ 3, 9 ] );
	} );

	it( 'never changes the list it was given', () => {
		const chosen = [ 2 ];
		toggle( chosen, 4 );
		expect( chosen ).toEqual( [ 2 ] );
	} );
} );

describe( 'pageCount', () => {
	it( 'is at least one page', () => {
		expect( pageCount( 0, PER_PAGE ) ).toBe( 1 );
	} );

	it( 'rounds up', () => {
		expect( PER_PAGE ).toBe( 20 );
		expect( pageCount( 20, 20 ) ).toBe( 1 );
		expect( pageCount( 21, 20 ) ).toBe( 2 );
		expect( pageCount( 45, 20 ) ).toBe( 3 );
	} );
} );

describe( 'scopeRequest', () => {
	it( 'is an empty list for the whole network', () => {
		expect( scopeRequest( 'network', [ 2, 3 ] ) ).toEqual( [] );
	} );

	it( 'is the chosen ids for chosen sites', () => {
		expect( scopeRequest( 'sites', [ 3, 2 ] ) ).toEqual( [ 3, 2 ] );
	} );

	it( 'is null (nothing to send) when chosen sites has none', () => {
		expect( scopeRequest( 'sites', [] ) ).toBeNull();
	} );
} );

describe( 'label', () => {
	it( 'drops the scheme and the trailing slash', () => {
		expect( label( { url: 'https://net.test/shop/' } ) ).toBe(
			'net.test/shop'
		);
		expect( label( { url: 'http://127.0.0.1:8896' } ) ).toBe(
			'127.0.0.1:8896'
		);
		expect( label( { url: 'HTTP://b2.net.test//' } ) ).toBe(
			'b2.net.test'
		);
	} );

	it( 'is empty for a site without an address', () => {
		expect( label( {} ) ).toBe( '' );
	} );
} );

describe( 'remember', () => {
	it( 'keeps the labels it had and adds those of a page', () => {
		expect(
			remember( { 2: 'net.test/b2' }, [
				{ id: 3, url: 'http://net.test/b3/' },
				{ id: 2, url: 'http://net.test/b2-new' },
			] )
		).toEqual( { 2: 'net.test/b2-new', 3: 'net.test/b3' } );
	} );
} );
