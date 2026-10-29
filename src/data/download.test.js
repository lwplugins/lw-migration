/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import { LOGGED_OUT, STOP, classify } from './errors';
import { headRefusal } from './download';

describe( 'headRefusal', () => {
	it( 'asks for a new link once after a 401 or 403', () => {
		expect( headRefusal( 401, 1 ) ).toBeNull();
		expect( headRefusal( 403, 1 ) ).toBeNull();
	} );

	it( 'reads a second 401 as a logout', () => {
		const error = headRefusal( 401, 2 );
		expect( error ).toMatchObject( {
			status: 401,
			code: 'lw_migration_download_refused',
		} );
		expect( classify( error ) ).toBe( LOGGED_OUT );
	} );

	it( 'stops after a second 403', () => {
		const error = headRefusal( 403, 2 );
		expect( error.code ).toBe( 'lw_migration_download_refused' );
		expect( classify( error ) ).toBe( STOP );
	} );

	it( 'names a 404 as a missing backup, at once', () => {
		expect( headRefusal( 404, 1 ) ).toMatchObject( {
			status: 404,
			code: 'lw_migration_backup_missing',
		} );
	} );

	it( 'keeps any other status as http_<status>', () => {
		expect( headRefusal( 502, 1 ) ).toMatchObject( {
			status: 502,
			code: 'http_502',
		} );
	} );
} );
