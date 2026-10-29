/**
 * External dependencies
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Internal dependencies
 */
import { api } from '../data/api';
import { checkConnection, trustHostKey } from './hostKeyFlow';

vi.mock( '../data/api', () => ( {
	api: { testDestination: vi.fn(), trustDestination: vi.fn() },
} ) );

const SHOWN = 'SHA256:' + 'S'.repeat( 43 );
const NOW = 'SHA256:' + 'N'.repeat( 43 );
const CONFIRMED = 'SHA256:' + 'C'.repeat( 43 );

const failed = ( data ) => ( {
	status: 422,
	code: 'lw_migration_destination_failed',
	message: 'Recorded text.',
	data,
} );
const unknownKey = failed( {
	reason: 'host_key_unknown',
	hostKey: SHOWN,
	hostKeyType: 'ssh-ed25519',
} );
const onCard = {
	kind: 'unknown',
	field: 'hostKey',
	fingerprint: SHOWN,
	keyType: 'ssh-ed25519',
	expected: '',
	before: '',
};

let calls;
let card;

beforeEach( () => {
	api.testDestination.mockReset();
	api.trustDestination.mockReset();
	calls = [];
	card = {
		onChanged: vi.fn( async () => calls.push( 'changed' ) ),
		onWorks: vi.fn( () => calls.push( 'works' ) ),
	};
} );

describe( 'checkConnection', () => {
	it( 'reports a working connection and reads the list again', async () => {
		api.testDestination.mockResolvedValue( {} );

		expect( await checkConnection( 'aaaa1111', card ) ).toEqual( {
			hostKey: null,
			failure: null,
		} );
		expect( api.testDestination ).toHaveBeenCalledWith( 'aaaa1111' );
		expect( calls ).toEqual( [ 'works', 'changed' ] );
	} );

	it( 'keeps a host key to confirm on the card and still reads the stored status', async () => {
		api.testDestination.mockRejectedValue( unknownKey );

		expect( await checkConnection( 'aaaa1111', card ) ).toEqual( {
			hostKey: onCard,
			failure: null,
		} );
		expect( calls ).toEqual( [ 'changed' ] );
	} );

	it( 'leaves any other recorded failure to the stored message', async () => {
		api.testDestination.mockRejectedValue( failed( { reason: 'auth' } ) );

		expect( await checkConnection( 'aaaa1111', card ) ).toEqual( {
			hostKey: null,
			failure: null,
		} );
		expect( calls ).toEqual( [ 'changed' ] );
	} );

	it( 'shows a refusal nothing recorded on the card', async () => {
		const loggedOut = { status: 401, code: 'rest_not_logged_in' };
		api.testDestination.mockRejectedValue( loggedOut );

		expect( await checkConnection( 'aaaa1111', card ) ).toEqual( {
			hostKey: null,
			failure: loggedOut,
		} );
		expect( calls ).toEqual( [] );
	} );
} );

describe( 'trustHostKey', () => {
	it( 'sends the fingerprint shown on the card, then reports the connection works', async () => {
		api.trustDestination.mockResolvedValue( {} );

		expect( await trustHostKey( 'aaaa1111', onCard, card ) ).toEqual( {
			hostKey: null,
			failure: null,
			trusted: true,
		} );
		expect( api.trustDestination ).toHaveBeenCalledWith(
			'aaaa1111',
			'hostKey',
			SHOWN
		);
		expect( calls ).toEqual( [ 'works', 'changed' ] );
	} );

	it( 'sends an FTPS certificate under its own field', async () => {
		const cert = Array( 32 ).fill( 'AB' ).join( ':' );
		api.trustDestination.mockResolvedValue( {} );

		await trustHostKey(
			'aaaa1111',
			{ ...onCard, field: 'certFingerprint', fingerprint: cert },
			card
		);

		expect( api.trustDestination ).toHaveBeenCalledWith(
			'aaaa1111',
			'certFingerprint',
			cert
		);
	} );

	it( 'shows a key that changed before the trust as a changed key, with the server text', async () => {
		api.trustDestination.mockRejectedValue( {
			status: 409,
			code: 'lw_migration_host_key_differs',
			message: 'The server now shows another host key.',
			data: { hostKey: NOW, hostKeyType: 'ssh-rsa' },
		} );

		expect( await trustHostKey( 'aaaa1111', onCard, card ) ).toEqual( {
			hostKey: {
				kind: 'changed',
				field: 'hostKey',
				fingerprint: NOW,
				keyType: 'ssh-rsa',
				expected: SHOWN,
				before: 'shown',
				note: 'The server now shows another host key.',
			},
			failure: null,
			trusted: false,
		} );
		expect( calls ).toEqual( [] );
	} );

	it( 'shows an address changed meanwhile as a refusal and drops the key', async () => {
		const moved = {
			status: 409,
			code: 'lw_migration_destination_changed',
			message: 'The address changed.',
			data: {},
		};
		api.trustDestination.mockRejectedValue( moved );

		expect( await trustHostKey( 'aaaa1111', onCard, card ) ).toEqual( {
			hostKey: null,
			failure: moved,
			trusted: false,
		} );
		expect( calls ).toEqual( [] );
	} );

	it( 'reads a changed key from the test that follows the trust', async () => {
		api.trustDestination.mockRejectedValue(
			failed( {
				reason: 'host_key_changed',
				hostKey: NOW,
				hostKeyType: 'ssh-ed25519',
				expected: CONFIRMED,
			} )
		);

		const result = await trustHostKey( 'aaaa1111', onCard, card );

		expect( result.hostKey ).toEqual( {
			kind: 'changed',
			field: 'hostKey',
			fingerprint: NOW,
			keyType: 'ssh-ed25519',
			expected: CONFIRMED,
			before: 'confirmed',
		} );
		expect( calls ).toEqual( [ 'changed' ] );
	} );
} );
