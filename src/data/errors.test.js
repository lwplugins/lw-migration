/**
 * External dependencies
 */
import { afterEach, describe, expect, it, vi } from 'vitest';

/**
 * Internal dependencies
 */
import {
	BUSY,
	BUSY_DELAY,
	LOGGED_OUT,
	MAX_RETRIES,
	NO_PERMISSION,
	OFFSET,
	REREAD,
	RETRY,
	RETRY_DELAY,
	STOP,
	TOO_LARGE,
	classify,
	errorMessage,
	invalidField,
	isLoggedOut,
	retryDelay,
	withRetry,
} from './errors';

const CASES = [
	[ 'job busy', { status: 409, code: 'lw_migration_job_busy' }, BUSY ],
	[
		'unexpected input',
		{ status: 409, code: 'lw_migration_unexpected_input' },
		REREAD,
	],
	[
		'not uploading',
		{ status: 409, code: 'lw_migration_not_uploading' },
		REREAD,
	],
	[
		'upload offset',
		{ status: 409, code: 'lw_migration_upload_offset' },
		OFFSET,
	],
	[
		'JSON chunk too large',
		{ status: 413, code: 'lw_migration_chunk_too_large' },
		TOO_LARGE,
	],
	[ 'web-server 413 page', { status: 413, code: 'http_413' }, TOO_LARGE ],
	[
		'logged out (401)',
		{ status: 401, code: 'rest_not_logged_in' },
		LOGGED_OUT,
	],
	[
		'nonce still invalid after the refresh',
		{ status: 403, code: 'rest_cookie_invalid_nonce' },
		LOGGED_OUT,
	],
	[ 'no capability', { status: 403, code: 'rest_forbidden' }, NO_PERMISSION ],
	[
		'plugin fallback error',
		{ status: 500, code: 'lw_migration_error' },
		RETRY,
	],
	[ 'unreadable answer', { code: 'invalid_json' }, RETRY ],
	[ 'network failure', { code: 'fetch_error' }, RETRY ],
	[ 'bad gateway page', { status: 502, code: 'http_502' }, RETRY ],
	[ 'PHP crash page', { status: 500, code: 'http_500' }, RETRY ],
	[ 'storage failure', { status: 500, code: 'lw_migration_storage' }, STOP ],
	[
		'site busy',
		{
			status: 409,
			code: 'lw_migration_site_busy',
			message: 'Another job runs until 14:02.',
		},
		STOP,
	],
	[ 'job gone', { status: 404, code: 'lw_migration_no_job' }, STOP ],
	[
		'not an archive',
		{ status: 400, code: 'lw_migration_not_wpress' },
		STOP,
	],
	[
		'invalid request',
		{
			status: 400,
			code: 'lw_migration_invalid',
			data: { field: 'url' },
		},
		STOP,
	],
	[ 'offline', { code: 'offline_error' }, RETRY ],
	[ 'nothing at all', undefined, STOP ],
];

describe( 'classify', () => {
	it.each( CASES )( '%s', ( _name, error, kind ) => {
		expect( classify( error ) ).toBe( kind );
	} );
} );

describe( 'retryDelay', () => {
	it( 'waits the same short time for a busy job', () => {
		expect( retryDelay( BUSY, 1 ) ).toBe( BUSY_DELAY );
		expect( retryDelay( BUSY, 4 ) ).toBe( BUSY_DELAY );
	} );

	it( 'waits longer after each passing failure', () => {
		expect( retryDelay( RETRY, 1 ) ).toBe( RETRY_DELAY );
		expect( retryDelay( RETRY, 3 ) ).toBe( 3 * RETRY_DELAY );
	} );
} );

describe( 'invalidField', () => {
	it( 'names the refused field of a validation error', () => {
		expect(
			invalidField( {
				code: 'lw_migration_invalid',
				data: { field: 'days' },
			} )
		).toBe( 'days' );
	} );

	it( 'gives null for other errors or no field', () => {
		expect( invalidField( { code: 'lw_migration_invalid' } ) ).toBeNull();
		expect(
			invalidField( {
				code: 'lw_migration_storage',
				data: { field: 'days' },
			} )
		).toBeNull();
	} );
} );

describe( 'withRetry', () => {
	afterEach( () => {
		vi.useRealTimers();
	} );

	it( 'sends again after a busy job and returns the first success', async () => {
		vi.useFakeTimers();
		const send = vi
			.fn()
			.mockRejectedValueOnce( { code: 'lw_migration_job_busy' } )
			.mockRejectedValueOnce( { code: 'lw_migration_job_busy' } )
			.mockResolvedValueOnce( { ok: true } );

		const result = withRetry( send );
		await vi.runAllTimersAsync();

		await expect( result ).resolves.toEqual( { ok: true } );
		expect( send ).toHaveBeenCalledTimes( 3 );
	} );

	it( 'waits the growing delay between passing failures', async () => {
		vi.useFakeTimers();
		const send = vi
			.fn()
			.mockRejectedValueOnce( { status: 502, code: 'http_502' } )
			.mockResolvedValueOnce( 'ok' );

		const result = withRetry( send );
		await vi.advanceTimersByTimeAsync( RETRY_DELAY - 1 );
		expect( send ).toHaveBeenCalledTimes( 1 );
		await vi.advanceTimersByTimeAsync( 1 );

		await expect( result ).resolves.toBe( 'ok' );
		expect( send ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'throws a lasting failure at once', async () => {
		const error = { status: 500, code: 'lw_migration_storage' };
		const send = vi.fn().mockRejectedValue( error );

		await expect( withRetry( send ) ).rejects.toBe( error );
		expect( send ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'throws a logout at once', async () => {
		const error = { status: 401, code: 'rest_not_logged_in' };
		const send = vi.fn().mockRejectedValue( error );

		await expect( withRetry( send ) ).rejects.toBe( error );
		expect( send ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'stops waiting and sends nothing more once its signal aborts', async () => {
		vi.useFakeTimers();
		const error = { code: 'lw_migration_job_busy' };
		const send = vi.fn().mockRejectedValue( error );
		const controller = new AbortController();

		const caught = withRetry( send, controller.signal ).catch( ( e ) => e );
		await vi.advanceTimersByTimeAsync( BUSY_DELAY / 2 );
		controller.abort();
		await vi.advanceTimersByTimeAsync( 0 );

		expect( await caught ).toBe( error );
		expect( send ).toHaveBeenCalledTimes( 1 );
		expect( vi.getTimerCount() ).toBe( 0 );
	} );

	it( 'does not retry when its signal is already aborted', async () => {
		const error = { status: 502, code: 'http_502' };
		const send = vi.fn().mockRejectedValue( error );
		const controller = new AbortController();
		controller.abort();

		await expect( withRetry( send, controller.signal ) ).rejects.toBe(
			error
		);
		expect( send ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'gives up after the last attempt', async () => {
		vi.useFakeTimers();
		const error = { code: 'lw_migration_job_busy' };
		const send = vi.fn().mockRejectedValue( error );

		const caught = withRetry( send ).catch( ( e ) => e );
		await vi.runAllTimersAsync();

		expect( await caught ).toBe( error );
		expect( send ).toHaveBeenCalledTimes( MAX_RETRIES );
	} );
} );

describe( 'errorMessage', () => {
	const LOGOUT_TEXT =
		'You were logged out. Log in again, then try once more.';

	it.each( [
		[
			'401 not logged in',
			{
				status: 401,
				code: 'rest_not_logged_in',
				message: 'You are not currently logged in.',
			},
		],
		[
			'invalid nonce without a status',
			{
				code: 'rest_cookie_invalid_nonce',
				message: 'Cookie check failed',
			},
		],
	] )( 'gives our own text for a logout (%s)', ( name, error ) => {
		expect( errorMessage( error ) ).toBe( LOGOUT_TEXT );
	} );

	it( 'gives our own text for a missing permission', () => {
		expect(
			errorMessage( {
				status: 403,
				code: 'rest_forbidden',
				message: 'Sorry, you are not allowed to do that.',
			} )
		).toBe( 'You do not have permission to do this.' );
	} );

	it.each( [
		[ 'site busy', { status: 409, code: 'lw_migration_site_busy' } ],
		[ 'storage', { status: 500, code: 'lw_migration_storage' } ],
		[ 'gateway', { status: 502, code: 'http_502' } ],
	] )( 'keeps the server text otherwise (%s)', ( name, error ) => {
		expect(
			errorMessage( { ...error, message: 'Said by the server.' } )
		).toBe( 'Said by the server.' );
	} );

	it( 'falls back to a general text without a message', () => {
		expect(
			errorMessage( { status: 500, code: 'lw_migration_error' } )
		).toBe( 'That did not work. Reload the page and try again.' );
		expect( errorMessage( undefined ) ).toBe(
			'That did not work. Reload the page and try again.'
		);
	} );
} );

describe( 'isLoggedOut', () => {
	it( 'is true only for a logout', () => {
		expect(
			isLoggedOut( { status: 401, code: 'rest_not_logged_in' } )
		).toBe( true );
		expect( isLoggedOut( { code: 'rest_cookie_invalid_nonce' } ) ).toBe(
			true
		);
		expect( isLoggedOut( { status: 403, code: 'rest_forbidden' } ) ).toBe(
			false
		);
		expect( isLoggedOut( null ) ).toBe( false );
	} );
} );
