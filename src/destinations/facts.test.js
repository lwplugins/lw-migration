/**
 * External dependencies
 */
import { describe, expect, it, vi } from 'vitest';

/**
 * Internal dependencies
 */
import { cardFacts, retentionFact } from './facts';

vi.mock( '../data/format', () => ( {
	formatDatetime: ( ts ) => `at ${ ts }`,
} ) );

const GB = 1073741824;

describe( 'retentionFact', () => {
	it.each( [
		[ { count: 0, bytes: 0, days: 0 }, 'no limit' ],
		[ undefined, 'no limit' ],
		[ { count: 1, bytes: 0, days: 0 }, '1 backup' ],
		[ { count: 10, bytes: 0, days: 0 }, '10 backups' ],
		[ { count: 0, bytes: 5 * GB, days: 0 }, '5 GB' ],
		[ { count: 0, bytes: 1.5 * GB, days: 0 }, '1.5 GB' ],
		[ { count: 0, bytes: 0, days: 1 }, '1 day' ],
		[ { count: 0, bytes: 0, days: 30 }, '30 days' ],
		[ { count: 10, bytes: 0, days: 30 }, '10 backups · 30 days' ],
		[ { count: 10, bytes: 2 * GB, days: 0 }, '10 backups · 2 GB' ],
		[ { count: 0, bytes: 2 * GB, days: 7 }, '2 GB · 7 days' ],
		[ { count: 3, bytes: 2 * GB, days: 7 }, '3 backups · 2 GB · 7 days' ],
	] )( '%j is "%s"', ( retention, expected ) => {
		expect( retentionFact( retention ) ).toBe( expected );
	} );
} );

describe( 'cardFacts', () => {
	const base = {
		lastUploadAt: 0,
		usedBy: 0,
		retention: { count: 0, bytes: 0, days: 0 },
		connection: null,
	};

	it( 'says nothing was uploaded yet, and keeps only the always-on pairs', () => {
		expect( cardFacts( base ) ).toEqual( [
			[ 'Last upload', 'Nothing uploaded yet' ],
			[ 'Keeping', 'no limit' ],
		] );
	} );

	it( 'formats the last upload with the shared date helper', () => {
		expect(
			cardFacts( { ...base, lastUploadAt: 1700000000 } )[ 0 ]
		).toEqual( [ 'Last upload', 'at 1700000000' ] );
	} );

	it( 'lists schedules only above 0, singular and plural', () => {
		expect(
			cardFacts( { ...base, usedBy: 1 } ).find(
				( [ l ] ) => 'Schedules' === l
			)
		).toEqual( [ 'Schedules', '1 schedule' ] );
		expect(
			cardFacts( { ...base, usedBy: 3 } ).find(
				( [ l ] ) => 'Schedules' === l
			)
		).toEqual( [ 'Schedules', '3 schedules' ] );
		expect( cardFacts( base ).some( ( [ l ] ) => 'Schedules' === l ) ).toBe(
			false
		);
	} );

	it( 'shows the retention text', () => {
		expect(
			cardFacts( {
				...base,
				retention: { count: 10, bytes: 0, days: 30 },
			} )
		).toContainEqual( [ 'Keeping', '10 backups · 30 days' ] );
	} );

	it( 'lists the account only when set', () => {
		expect(
			cardFacts( { ...base, connection: { account: 'me@example.com' } } )
		).toContainEqual( [ 'Account', 'me@example.com' ] );
		expect(
			cardFacts( { ...base, connection: { account: '' } } ).some(
				( [ l ] ) => 'Account' === l
			)
		).toBe( false );
		expect( cardFacts( base ).some( ( [ l ] ) => 'Account' === l ) ).toBe(
			false
		);
	} );

	it( 'orders last upload, schedules, retention, account', () => {
		expect(
			cardFacts( {
				...base,
				lastUploadAt: 5,
				usedBy: 2,
				connection: { account: 'a' },
			} ).map( ( [ l ] ) => l )
		).toEqual( [ 'Last upload', 'Schedules', 'Keeping', 'Account' ] );
	} );
} );
