/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import {
	awaitsConnect,
	choiceText,
	cloudBadges,
	cloudLogText,
	cloudStateLabel,
	notAllUploaded,
	whereText,
} from './cloudStatus';

describe( 'cloud badges', () => {
	it( 'names each destination of a backup with its state; a deleted one by its id', () => {
		const item = {
			cloud: [
				{ id: 'aaaa1111', status: 'up', at: 5 },
				{ id: 'bbbb2222', status: 'failed', at: 6 },
				{ id: 'cccc3333', status: 'pending', at: 7 },
			],
		};
		const destinations = [
			{ id: 'aaaa1111', name: 'Offsite' },
			{ id: 'bbbb2222', name: 'Second' },
		];

		expect(
			cloudBadges( item, destinations ).map( ( b ) => [
				b.name,
				b.tone,
				b.state,
			] )
		).toEqual( [
			[ 'Offsite', 'ok', 'up' ],
			[ 'Second', 'critical', 'failed' ],
			[ 'cccc3333', 'info', 'pending' ],
		] );
		expect(
			cloudBadges( item, destinations ).map( ( b ) => b.destination )
		).toEqual( [ destinations[ 0 ], destinations[ 1 ], null ] );
		expect( cloudBadges( {}, destinations ) ).toEqual( [] );
	} );
} );

describe( 'a partial cloud result', () => {
	it( 'is one where any destination did not end ok', () => {
		expect( notAllUploaded( [] ) ).toBe( false );
		expect( notAllUploaded( undefined ) ).toBe( false );
		expect( notAllUploaded( [ { status: 'ok' }, { status: 'ok' } ] ) ).toBe(
			false
		);
		expect(
			notAllUploaded( [ { status: 'ok' }, { status: 'skipped' } ] )
		).toBe( true );
		expect( notAllUploaded( [ { status: 'failed' } ] ) ).toBe( true );
		expect( notAllUploaded( [ { status: 'pending' } ] ) ).toBe( true );
	} );
} );

describe( 'a destination to choose', () => {
	it( 'says where it is, and that it is unencrypted', () => {
		expect(
			whereText( { where: 'bkt · Amazon S3', insecure: false } )
		).toBe( 'bkt · Amazon S3' );
		expect( whereText( { where: 'bkt · Other', insecure: true } ) ).toBe(
			'bkt · Other · unencrypted'
		);
	} );
} );

describe( 'cloud rows of a run log', () => {
	it( 'gives a row without a reason its state word', () => {
		expect(
			cloudLogText( { name: 'Offsite', status: 'pending', error: '' } )
		).toBe( 'Offsite: not uploaded' );
		expect(
			cloudLogText( { name: 'Offsite', status: 'skipped', error: '' } )
		).toBe( 'Offsite: skipped' );
		expect(
			cloudLogText( { name: 'Offsite', status: 'failed', error: '' } )
		).toBe( 'Offsite: failed' );
	} );

	it( 'gives the reason when there is one', () => {
		expect(
			cloudLogText( {
				name: 'Offsite',
				status: 'failed',
				error: 'Access denied.',
			} )
		).toBe( 'Offsite: Access denied.' );
	} );

	it( 'labels each state like the job result does', () => {
		expect(
			[ 'ok', 'failed', 'skipped', 'pending' ].map( cloudStateLabel )
		).toEqual( [ 'uploaded', 'failed', 'skipped', 'not uploaded' ] );
	} );
} );

describe( 'upload pickers', () => {
	it( 'offers a cloud account destination only once it is connected', () => {
		const waiting = {
			where: 'Dropbox',
			connection: { connected: false },
		};
		const ready = { where: 'Dropbox', connection: { connected: true } };
		const plain = { where: 'sftp://example.org/backups' };

		expect( [ waiting, ready, plain ].map( awaitsConnect ) ).toEqual( [
			true,
			false,
			false,
		] );
		expect( choiceText( waiting ) ).toBe(
			'Dropbox · connect it first in Destinations'
		);
		expect( choiceText( ready ) ).toBe( 'Dropbox' );
	} );
} );
