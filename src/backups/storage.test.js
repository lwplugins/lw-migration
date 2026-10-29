/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import { keepText, storageSums } from './storage';

const GB = 1024 * 1024 * 1024;
const manual = ( patch = {} ) => ( {
	schedule: null,
	name: '',
	count: 0,
	bytes: 0,
	days: 0,
	...patch,
} );
const schedule = ( id, name, patch = {} ) => ( {
	schedule: id,
	name,
	count: 0,
	bytes: 0,
	days: 0,
	...patch,
} );

describe( 'storageSums', () => {
	it( 'adds the two folders and keeps an unknown free space unknown', () => {
		expect(
			storageSums( { ownBytes: 3 * GB, otherBytes: GB, free: 9 * GB } )
		).toEqual( { own: 3 * GB, other: GB, free: 9 * GB, used: 4 * GB } );
		expect(
			storageSums( { ownBytes: 5, otherBytes: 0, free: null } )
		).toEqual( { own: 5, other: 0, free: null, used: 5 } );
		expect( storageSums( undefined ).free ).toBeNull();
	} );
} );

describe( 'keepText', () => {
	it( 'says only that manual backups stay when nothing deletes them', () => {
		expect( keepText( [ manual() ] ) ).toBe(
			'Manual backups are never deleted'
		);
		expect( keepText( undefined ) ).toBe(
			'Manual backups are never deleted'
		);
	} );

	it( "words one schedule's rule, then the manual backups", () => {
		expect(
			keepText( [
				manual(),
				schedule( 'abcd1234', 'Daily', { days: 7 } ),
			] )
		).toBe(
			'Scheduled backups: kept for 7 days · manual ones are never deleted'
		);
	} );

	it( 'names each schedule when there are several, and every limit of a rule', () => {
		expect(
			keepText( [
				manual( { count: 10 } ),
				schedule( 'a', 'Daily', { days: 1, count: 5, bytes: 20 * GB } ),
				schedule( 'b', 'Weekly' ),
			] )
		).toBe(
			'Daily: kept for 1 day, the newest 5 are kept, at most 20 GB in total · Weekly: never deleted · ones made here by hand: the newest 10 are kept'
		);
	} );

	it( 'says when a single schedule never deletes, and words a manual rule alone', () => {
		expect( keepText( [ manual(), schedule( 'a', 'Daily' ) ] ) ).toBe(
			'Scheduled backups are never deleted · manual ones are never deleted'
		);
		expect( keepText( [ manual( { days: 30 } ) ] ) ).toBe(
			'Backups made here by hand: kept for 30 days'
		);
	} );
} );
