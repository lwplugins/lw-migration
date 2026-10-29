/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import {
	DEFAULT_EXPIRY,
	EXPIRY_OPTIONS,
	expiryOptions,
	liveLinksFor,
	usesLabel,
} from './shareLink';

const link = ( patch = {} ) => ( {
	id: '0a1b2c3d',
	folder: 'own',
	name: 'site.wpress',
	createdAt: 900,
	expiresAt: 2000,
	expired: false,
	available: true,
	revoked: false,
	uses: 0,
	lastUsedAt: 0,
	...patch,
} );

describe( 'expiry choices', () => {
	it( 'offers an hour, a day and a week, a day by default', () => {
		expect( EXPIRY_OPTIONS ).toEqual( [ 3600, 86400, 604800 ] );
		expect( DEFAULT_EXPIRY ).toBe( 86400 );
		expect( Object.keys( expiryOptions() ) ).toEqual( [
			'3600',
			'86400',
			'604800',
		] );
		expect( Object.values( expiryOptions() ) ).toEqual( [
			'1 hour',
			'24 hours',
			'7 days',
		] );
	} );
} );

describe( 'liveLinksFor', () => {
	it( 'keeps a revoked link (its file was replaced) so it can be removed', () => {
		const items = [ link( { id: 'r', available: false, revoked: true } ) ];
		expect(
			liveLinksFor( items, { folder: 'own', name: 'site.wpress' }, 1000 )
		).toHaveLength( 1 );
	} );

	it( 'keeps the live links of the same folder and name, latest expiry first', () => {
		const items = [
			link( { id: 'a', expiresAt: 1500 } ),
			link( { id: 'b', folder: 'external' } ),
			link( { id: 'c', name: 'other.wpress' } ),
			link( { id: 'd', expired: true } ),
			link( { id: 'e', expiresAt: 999 } ),
			link( { id: 'f', expiresAt: 3000 } ),
		];
		const backup = { folder: 'own', name: 'site.wpress' };
		expect(
			liveLinksFor( items, backup, 1000 ).map( ( item ) => item.id )
		).toEqual( [ 'f', 'a' ] );
	} );

	it( 'is empty without items', () => {
		const backup = { folder: 'own', name: 'x' };
		expect( liveLinksFor( undefined, backup, 1 ) ).toEqual( [] );
	} );
} );

describe( 'usesLabel', () => {
	const at = ( ts ) => `at ${ ts }`;

	it( 'says never used for a link nobody opened', () => {
		expect( usesLabel( link(), at ) ).toBe( 'never used' );
	} );

	it( 'counts downloads and names the last use', () => {
		expect( usesLabel( link( { uses: 1, lastUsedAt: 1200 } ), at ) ).toBe(
			'used 1 time, last at 1200'
		);
		expect( usesLabel( link( { uses: 3, lastUsedAt: 1300 } ), at ) ).toBe(
			'used 3 times, last at 1300'
		);
	} );
} );
