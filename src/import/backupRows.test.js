/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import { alsoHere, backupKind, backupTags, kindText } from './backupRows';

const item = ( patch = {} ) => ( {
	id: 'own:croco2-example-20261016-030012-418.wpress',
	name: 'croco2-example-20261016-030012-418.wpress',
	folder: 'own',
	size: 1,
	mtime: 1760583612,
	label: '',
	safety: false,
	beforeReset: false,
	cloud: [],
	encrypted: false,
	fromSite: null,
	...patch,
} );

const gdrive = { id: 'aaaa1111', type: 'gdrive', name: 'Google Drive' };

describe( 'how a backup was made', () => {
	it( 'reads it from the name and the safety flags', () => {
		expect( backupKind( item() ) ).toBe( 'manual' );
		expect(
			backupKind(
				item( {
					name: 'croco2-example-20261016-030012-418-1a2b3c4d.wpress',
				} )
			)
		).toBe( 'scheduled' );
		expect(
			backupKind(
				item( {
					name: 'pre-import-20261016-030012.wpress',
					safety: true,
				} )
			)
		).toBe( 'safety' );
		expect(
			backupKind(
				item( {
					name: 'pre-reset-20261016-030012.wpress',
					safety: true,
					beforeReset: true,
				} )
			)
		).toBe( 'reset' );
		expect( backupKind( item( { name: 'my-site.wpress' } ) ) ).toBe( '' );
	} );

	it( 'has a word for each kind', () => {
		expect( kindText( 'scheduled' ) ).toBe( 'scheduled' );
		expect( kindText( 'manual' ) ).toBe( 'manual' );
		expect( kindText( '' ) ).toBe( '' );
	} );
} );

describe( 'the tags of a backup', () => {
	it( 'shows none for a plain backup of this site', () => {
		expect( backupTags( item(), [ gdrive ] ) ).toEqual( [] );
	} );

	it( 'names the destinations it is uploaded to, encryption and another site', () => {
		const tags = backupTags(
			item( {
				cloud: [
					{ id: 'aaaa1111', status: 'up', at: 1 },
					{ id: 'bbbb2222', status: 'failed', at: 2 },
					{ id: 'gone0000', status: 'up', at: 3 },
				],
				encrypted: true,
				fromSite: 'dev2.example',
				folder: 'external',
			} ),
			[ gdrive, { id: 'bbbb2222', type: 's3', name: 'S3' } ]
		);
		expect( tags.map( ( tag ) => tag.kind ) ).toEqual( [
			'cloud',
			'lock',
			'site',
			'folder',
		] );
		expect( tags[ 0 ] ).toMatchObject( {
			text: 'Also at Google Drive',
			destination: gdrive,
		} );
		expect( tags[ 1 ].text ).toBe( 'Encrypted' );
		expect( tags[ 2 ].text ).toBe( 'Another site' );
	} );

	it( 'reads an older list without the new fields as plain', () => {
		const { encrypted, fromSite, ...older } = item();
		expect( [ encrypted, fromSite ] ).toEqual( [ false, null ] );
		expect( backupTags( older, [] ) ).toEqual( [] );
	} );
} );

describe( 'a cloud backup that is here too', () => {
	it( 'matches a local backup by name', () => {
		const items = [ item() ];
		expect(
			alsoHere( 'croco2-example-20261016-030012-418.wpress', items )
		).toBe( true );
		expect( alsoHere( 'other.wpress', items ) ).toBe( false );
	} );
} );
