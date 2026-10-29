/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import { DEFAULTS } from '../views/exportRequest';
import { codePatch } from './choices';
import {
	composition,
	estimateStatus,
	knownPart,
	partState,
	segment,
} from './estimate';
import { MB, estimate as done, firstWalk } from './fixtures/estimate';

const form = ( patch = {} ) => ( { ...DEFAULTS, ...patch } );

const bytesOf = ( comp ) =>
	Object.fromEntries( comp.segments.map( ( s ) => [ s.key, s.bytes ] ) );

describe( 'composition', () => {
	it( 'leaves the cache out by default', () => {
		const comp = composition( done(), form() );
		expect( bytesOf( comp ).other ).toBe( 94 * MB );
		expect( comp.total ).toBe( 1788 * MB );
	} );

	it( 'adds every part with everything in, must-use and cache under other', () => {
		const comp = composition( done(), form( { noCache: false } ) );
		expect( bytesOf( comp ) ).toEqual( {
			media: 1100 * MB,
			database: 212 * MB,
			plugins: 286 * MB,
			themes: 96 * MB,
			other: 132 * MB,
		} );
		expect( comp.total ).toBe( 1826 * MB );
		expect( comp.known ).toBe( true );
		expect( comp.missing ).toBe( false );
		expect( comp.failed ).toBe( false );
	} );

	it( 'follows every request flag the way the estimate defines it', () => {
		const comp = composition(
			done(),
			form( {
				noMedia: true,
				noDatabase: true,
				...codePatch( 'plugins', 'active' ),
				...codePatch( 'themes', 'none' ),
				noMustUsePlugins: true,
				noCache: true,
			} )
		);
		expect( bytesOf( comp ) ).toEqual( {
			media: 0,
			database: 0,
			plugins: 198 * MB,
			themes: 0,
			other: 92 * MB,
		} );
		expect(
			comp.segments.filter( ( s ) => s.off ).map( ( s ) => s.key )
		).toEqual( [ 'media', 'database', 'themes' ] );
		// A left-out part keeps its full size for the sliver it leaves.
		expect( segment( done(), 'media', form( { noMedia: true } ) ) ).toEqual(
			{
				key: 'media',
				bytes: 0,
				full: 1100 * MB,
				off: true,
				state: 'known',
			}
		);
		expect( comp.total ).toBe( 290 * MB );
	} );

	it( 'drops only the inactive themes with "active only"', () => {
		const comp = composition(
			done(),
			form( codePatch( 'themes', 'active' ) )
		);
		expect( bytesOf( comp ).themes ).toBe( 41 * MB );
	} );

	it( 'counts what is known while the file walk has not begun', () => {
		const estimate = done( {
			complete: false,
			measuredAt: null,
			parts: {
				...done().parts,
				media: null,
				plugins: null,
				themes: null,
				muPlugins: null,
				cache: null,
				other: null,
			},
		} );
		const comp = composition( estimate, form() );
		expect( comp.total ).toBe( 212 * MB );
		expect( comp.known ).toBe( true );
		expect( comp.missing ).toBe( true );
		expect( comp.failed ).toBe( false );
		expect( partState( estimate, 'media' ) ).toBe( 'unknown' );
		// A part not measured yet but left out is not missing from the total.
		expect(
			composition(
				estimate,
				form( {
					noMedia: true,
					noPlugins: true,
					noThemes: true,
				} )
			).segments.find( ( s ) => 'other' === s.key ).state
		).toBe( 'unknown' );
	} );

	it( 'has nothing to show before the first figure', () => {
		const nothing = {
			parts: Object.fromEntries(
				Object.keys( done().parts ).map( ( key ) => [ key, null ] )
			),
			complete: false,
		};
		const comp = composition( nothing, form() );
		expect( comp.known ).toBe( false );
		expect( comp.total ).toBe( 0 );
		expect( composition( null, form() ).known ).toBe( false );
	} );

	it( 'marks the parts a finished result could not count', () => {
		const files = [
			'media',
			'plugins',
			'themes',
			'muPlugins',
			'cache',
			'other',
		];
		const estimate = done( {
			partial: files,
			parts: {
				...done().parts,
				...Object.fromEntries( files.map( ( key ) => [ key, null ] ) ),
			},
		} );
		const comp = composition( estimate, form() );
		expect( comp.total ).toBe( 212 * MB );
		expect( comp.failed ).toBe( true );
		expect( partState( estimate, 'media' ) ).toBe( 'failed' );
		expect( partState( estimate, 'database' ) ).toBe( 'known' );
		expect( knownPart( estimate, 'media' ) ).toBeNull();
		expect( comp.segments.find( ( s ) => 'other' === s.key ).state ).toBe(
			'failed'
		);
	} );

	it( 'counts a part a finished result has no figure for as not counted', () => {
		// The database stays null when the plugin cannot read it.
		const estimate = done( {
			parts: { ...done().parts, database: null },
		} );
		expect( partState( estimate, 'database' ) ).toBe( 'failed' );
		expect( composition( estimate, form() ).failed ).toBe( true );
	} );

	it( "takes a first measuring's file figures as lower bounds", () => {
		const estimate = firstWalk();
		expect( partState( estimate, 'media' ) ).toBe( 'growing' );
		expect( partState( estimate, 'database' ) ).toBe( 'known' );
		expect( knownPart( estimate, 'plugins' ) ).toBeNull();
		const comp = composition( estimate, form() );
		expect( comp.total ).toBe( 1788 * MB );
		expect( comp.missing ).toBe( true );
		expect( comp.failed ).toBe( false );
		expect( comp.segments.find( ( s ) => 'other' === s.key ).state ).toBe(
			'growing'
		);
		// "Other" always goes in, so with every other file part left out
		// the total is still a lower bound while the walk grows it.
		const onlyDb = composition(
			estimate,
			form( { noMedia: true, noPlugins: true, noThemes: true } )
		);
		expect( onlyDb.missing ).toBe( true );
		// A re-measuring answers the last finished result: final figures.
		expect(
			partState( firstWalk( { measuredAt: 1760600000 } ), 'media' )
		).toBe( 'known' );
	} );

	it( 'judges "other" by the parts that go in only', () => {
		const estimate = done( {
			partial: [ 'cache' ],
			parts: { ...done().parts, cache: null },
		} );
		const other = ( o ) =>
			composition( estimate, form( o ) ).segments.find(
				( s ) => 'other' === s.key
			);
		expect( other( { noCache: true } ) ).toMatchObject( {
			state: 'known',
			bytes: 94 * MB,
		} );
		expect( other( { noCache: false } ).state ).toBe( 'failed' );
		expect( composition( estimate, form() ).missing ).toBe( false );
	} );

	it( 'reads `partial` only in a finished result', () => {
		const estimate = done( { complete: false, partial: [ 'media' ] } );
		expect( partState( estimate, 'media' ) ).toBe( 'known' );
	} );
} );

describe( 'estimateStatus', () => {
	it( 'tells loading, unavailable, counting, stopped and done apart', () => {
		const failure = { code: 'fetch_error' };
		expect( estimateStatus( null, null ) ).toBe( 'loading' );
		expect( estimateStatus( null, failure ) ).toBe( 'unavailable' );
		expect( estimateStatus( done( { complete: false } ), null ) ).toBe(
			'counting'
		);
		expect( estimateStatus( done( { complete: false } ), failure ) ).toBe(
			'stopped'
		);
		expect( estimateStatus( done(), null ) ).toBe( 'done' );
	} );
} );
