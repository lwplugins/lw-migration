/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import {
	afterDelete,
	headingId,
	movesLocation,
	saveLabel,
	togglePanel,
} from './card';

const SFTP = { type: 'sftp', label: 'SFTP' };
const DROPBOX = { type: 'dropbox', label: 'Dropbox', oauth: {} };

describe( 'togglePanel', () => {
	it( 'opens a panel, replaces another one and closes itself', () => {
		expect( togglePanel( null, 'files' ) ).toBe( 'files' );
		expect( togglePanel( 'delete', 'files' ) ).toBe( 'files' );
		expect( togglePanel( 'files', 'files' ) ).toBeNull();
	} );
} );

describe( 'afterDelete', () => {
	const items = [ { id: 'a' }, { id: 'b' }, { id: 'c' } ];

	it( 'goes to the next card', () => {
		expect( afterDelete( items, 'a' ) ).toBe( 'b' );
		expect( afterDelete( items, 'b' ) ).toBe( 'c' );
	} );

	it( 'goes to the card before the last one', () => {
		expect( afterDelete( items, 'c' ) ).toBe( 'b' );
	} );

	it( 'has nowhere to go after the only card or an unknown one', () => {
		expect( afterDelete( [ { id: 'a' } ], 'a' ) ).toBeNull();
		expect( afterDelete( items, 'x' ) ).toBeNull();
	} );

	it( 'names the heading the focus goes to', () => {
		expect( headingId( 'abcd1234' ) ).toBe( 'lw-dest-abcd1234-title' );
	} );
} );

describe( 'saveLabel', () => {
	it( 'saves and checks a server or a storage', () => {
		expect( saveLabel( SFTP, false ) ).toBe( 'Save and check' );
		expect( saveLabel( SFTP, false, true ) ).toBe( 'Save and check' );
	} );

	it( 'only saves an account that needs no connect', () => {
		expect( saveLabel( DROPBOX, false ) ).toBe( 'Save' );
		expect( saveLabel( DROPBOX, false, true ) ).toBe( 'Save' );
	} );

	it( 'saves and connects, the big button naming the provider', () => {
		expect( saveLabel( DROPBOX, true ) ).toBe( 'Save and connect' );
		expect( saveLabel( DROPBOX, true, true ) ).toBe(
			'Save and connect: Dropbox'
		);
	} );
} );

describe( 'movesLocation', () => {
	const item = { where: 'sftp://nas.test:22' };

	it( 'is true only when the live line differs from the stored one', () => {
		expect( movesLocation( 'sftp://nas.test:22', item ) ).toBe( false );
		expect( movesLocation( 'sftp://other.test:22', item ) ).toBe( true );
	} );

	it( 'is false for a new destination and an unknown line', () => {
		expect( movesLocation( 'sftp://nas.test:22', null ) ).toBe( false );
		expect( movesLocation( '', item ) ).toBe( false );
	} );
} );
