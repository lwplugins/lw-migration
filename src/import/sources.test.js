/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import { EMPTY_DRAFT, pickDestination, readyToCheck } from './sources';

const drive = { id: 'a', type: 'gdrive', connection: { connected: true } };
const box = { id: 'b', type: 'dropbox', connection: { connected: false } };
const s3 = { id: 'c', type: 's3' };

describe( 'the cloud source', () => {
	it( 'takes the chosen destination, else the first usable one', () => {
		expect( pickDestination( [ box, drive, s3 ], 'c' ) ).toBe( s3 );
		expect( pickDestination( [ box, drive, s3 ], '' ) ).toBe( drive );
		expect( pickDestination( [ box, drive ], 'gone' ) ).toBe( drive );
	} );

	it( 'never takes one that waits for its account', () => {
		expect( pickDestination( [ box, drive ], 'b' ) ).toBe( drive );
		expect( pickDestination( [ box ], 'b' ) ).toBeNull();
		expect( pickDestination( [], '' ) ).toBeNull();
	} );
} );

describe( 'Continue to the check', () => {
	const choice = ( patch ) => ( { ...EMPTY_DRAFT, ...patch } );

	it( 'needs the chosen source filled in', () => {
		expect( readyToCheck( 'backup', choice( {} ), null ) ).toBe( false );
		expect(
			readyToCheck( 'backup', choice( {} ), null, { id: 'own:x' } )
		).toBe( true );
		expect(
			readyToCheck(
				'url',
				choice( { url: 'https://a.example/x.wpress' } )
			)
		).toBe( true );
		expect( readyToCheck( 'url', choice( { url: 'a.example' } ) ) ).toBe(
			false
		);
		expect( readyToCheck( 'upload', choice( { file: {} } ) ) ).toBe( true );
	} );

	it( 'from the cloud needs a usable destination and a file', () => {
		expect(
			readyToCheck( 'cloud', choice( { cloudName: 'x.wpress' } ), drive )
		).toBe( true );
		expect( readyToCheck( 'cloud', choice( {} ), drive ) ).toBe( false );
		expect(
			readyToCheck( 'cloud', choice( { cloudName: 'x.wpress' } ), null )
		).toBe( false );
	} );
} );
