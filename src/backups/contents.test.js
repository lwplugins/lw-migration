/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import { contentRows, countText, readsOn } from './contents';

const part = ( included, bytes, count ) => ( { included, bytes, count } );

describe( 'contentRows', () => {
	it( 'lists the parts the backup holds, the largest first, each with its share', () => {
		const { rows, absent } = contentRows( {
			parts: {
				database: part( true, 100, null ),
				media: part( true, 600, 8412 ),
				plugins: part( true, 300, 14 ),
				themes: part( false, 0, 0 ),
				muPlugins: part( false, 0, 0 ),
			},
			partial: false,
		} );
		expect( rows.map( ( r ) => [ r.id, r.bytes, r.count ] ) ).toEqual( [
			[ 'media', 600, 8412 ],
			[ 'plugins', 300, 14 ],
			[ 'database', 100, null ],
		] );
		expect( rows.map( ( r ) => r.share ) ).toEqual( [ 0.6, 0.3, 0.1 ] );
		expect( absent ).toEqual( [ 'themes', 'muPlugins' ] );
	} );

	it( 'keeps a part it could not read, after the known ones, without a size', () => {
		const { rows, absent } = contentRows( {
			parts: {
				database: part( true, 50, null ),
				media: part( null, null, null ),
				plugins: part( true, null, null ),
			},
		} );
		expect( rows.map( ( r ) => [ r.id, r.bytes, r.share ] ) ).toEqual( [
			[ 'database', 50, 1 ],
			[ 'media', null, 0 ],
			[ 'plugins', null, 0 ],
			[ 'themes', null, 0 ],
			[ 'muPlugins', null, 0 ],
		] );
		expect( absent ).toEqual( [] );
	} );

	it( 'gives an empty archive no share', () => {
		const { rows } = contentRows( {
			parts: {
				database: part( true, 0, null ),
				media: part( false, 0, 0 ),
				plugins: part( false, 0, 0 ),
				themes: part( false, 0, 0 ),
				muPlugins: part( false, 0, 0 ),
			},
		} );
		expect( rows ).toEqual( [
			{ id: 'database', bytes: 0, count: null, share: 0 },
		] );
	} );
} );

describe( 'countText', () => {
	it( 'counts files, plugins and themes, never the database', () => {
		expect( countText( 'media', 1 ) ).toBe( '1 file' );
		expect( countText( 'plugins', 14 ) ).toBe( '14 plugins' );
		expect( countText( 'muPlugins', 2 ) ).toBe( '2 plugins' );
		expect( countText( 'themes', 2 ) ).toBe( '2 themes' );
		expect( countText( 'database', 424 ) ).toBe( '' );
		expect( countText( 'media', null ) ).toBe( '' );
	} );
} );

describe( 'readsOn', () => {
	it( 'asks again only while the read is unfinished', () => {
		expect( readsOn( { partial: true } ) ).toBe( true );
		expect( readsOn( { partial: false } ) ).toBe( false );
		expect( readsOn( null ) ).toBe( false );
	} );
} );
