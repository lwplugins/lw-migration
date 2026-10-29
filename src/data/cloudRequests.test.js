/**
 * External dependencies
 */
import { readFileSync } from 'node:fs';
import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { api } from './api';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );

// Rest\CloudApi's request allowlists, read from the PHP source so a renamed
// or added key fails here, not in the browser.
const phpKeys = ( name ) => {
	const source = readFileSync(
		new URL( '../../includes/Rest/CloudApi.php', import.meta.url ),
		'utf8'
	);
	const list = source.match(
		new RegExp( `const ${ name } = \\[([^\\]]*)\\];` )
	)[ 1 ];
	return [ ...list.matchAll( /'(\w+)'/g ) ].map( ( m ) => m[ 1 ] ).sort();
};

const sent = () => apiFetch.mock.calls[ 0 ][ 0 ];

describe( 'cloud request bodies', () => {
	beforeEach( () => {
		apiFetch.mockReset();
		apiFetch.mockResolvedValue( {
			ok: true,
			status: 200,
			text: async () => '{}',
		} );
	} );

	it( 'restoreRemote sends the restore keys, the password only when given', async () => {
		await api.restoreRemote( 'aaaa1111', 'a.wpress', true, 'secret' );
		expect( Object.keys( sent().data ).sort() ).toEqual(
			phpKeys( 'RESTORE_KEYS' )
		);
		expect( sent().data.password ).toBe( 'secret' );

		apiFetch.mockClear();
		await api.restoreRemote( 'aaaa1111', 'a.wpress', false );
		expect( sent().data ).toEqual( {
			name: 'a.wpress',
			safetyBackup: false,
		} );
		expect(
			phpKeys( 'RESTORE_KEYS' ).filter( ( k ) => 'password' !== k )
		).toEqual( Object.keys( sent().data ).sort() );
	} );

	it( 'fetchRemote sends the fetch keys', async () => {
		await api.fetchRemote( 'aaaa1111', 'a.wpress' );
		expect( Object.keys( sent().data ).sort() ).toEqual(
			phpKeys( 'FETCH_KEYS' )
		);
	} );

	it( 'uploadBackup sends the upload keys', async () => {
		await api.uploadBackup(
			{ folder: 'own', name: 'a.wpress', size: 5, label: 'x' },
			[ 'aaaa1111' ]
		);
		expect( Object.keys( sent().data ).sort() ).toEqual(
			phpKeys( 'UPLOAD_KEYS' )
		);
	} );
} );
