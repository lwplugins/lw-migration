/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import {
	NO_ENTRIES,
	appendEntries,
	backupProblem,
	hasMoreEntries,
} from './backupList';

describe( 'appendEntries', () => {
	const entry = ( name ) => ( { name, size: 1, mtime: 0 } );

	it( 'starts the list with the first page and keeps its next offset', () => {
		const list = appendEntries( NO_ENTRIES, {
			entries: [ entry( 'package.json' ), entry( 'database.sql' ) ],
			next: 4096,
			encrypted: false,
		} );
		expect( list.entries.map( ( e ) => e.name ) ).toEqual( [
			'package.json',
			'database.sql',
		] );
		expect( list.next ).toBe( 4096 );
		expect( hasMoreEntries( list ) ).toBe( true );
	} );

	it( 'appends the next page and stops when the server says there is no more', () => {
		const first = appendEntries( NO_ENTRIES, {
			entries: [ entry( 'package.json' ) ],
			next: 512,
			encrypted: true,
		} );
		const second = appendEntries( first, {
			entries: [ entry( 'uploads/a.jpg' ) ],
			next: null,
			encrypted: true,
		} );
		expect( second.entries.map( ( e ) => e.name ) ).toEqual( [
			'package.json',
			'uploads/a.jpg',
		] );
		expect( second.next ).toBeNull();
		expect( second.encrypted ).toBe( true );
		expect( hasMoreEntries( second ) ).toBe( false );
	} );

	it( 'never pages on from a malformed answer', () => {
		const list = appendEntries( NO_ENTRIES, { next: '12', encrypted: 1 } );
		expect( list ).toEqual( NO_ENTRIES );
		expect( hasMoreEntries( list ) ).toBe( false );
		expect( hasMoreEntries( NO_ENTRIES ) ).toBe( false );
	} );
} );

describe( 'backupProblem', () => {
	it( 'asks for a new login on 401 and on an invalid nonce', () => {
		expect(
			backupProblem( { status: 401, code: 'http_401' } ).loggedOut
		).toBe( true );
		expect(
			backupProblem( {
				status: 403,
				code: 'rest_cookie_invalid_nonce',
			} ).loggedOut
		).toBe( true );
		expect(
			backupProblem( { status: 403, code: 'rest_forbidden' } ).loggedOut
		).toBe( false );
		expect(
			backupProblem( {
				status: 403,
				code: 'lw_migration_download_refused',
			} ).loggedOut
		).toBe( false );
	} );

	it( 'shows our hint for a generic field refusal, the server text otherwise', () => {
		expect(
			backupProblem(
				{
					status: 400,
					code: 'lw_migration_invalid',
					message: 'This value is not accepted: label',
					data: { field: 'label', generic: true },
				},
				'Our hint'
			).text
		).toBe( 'Our hint' );
		expect(
			backupProblem(
				{
					status: 400,
					code: 'lw_migration_invalid',
					message: 'Exact reason',
					data: { field: 'label' },
				},
				'Our hint'
			).text
		).toBe( 'Exact reason' );
	} );

	it( 'leaves other refusals to the general message', () => {
		expect(
			backupProblem(
				{
					status: 409,
					code: 'lw_migration_backup_in_use',
					message: 'A restore is reading this backup.',
				},
				'Our hint'
			)
		).toEqual( { loggedOut: false, text: '', gone: false } );
	} );

	it( 'marks a backup that is gone, so the list is read again', () => {
		expect(
			backupProblem( {
				status: 404,
				code: 'lw_migration_backup_missing',
			} ).gone
		).toBe( true );
	} );
} );
