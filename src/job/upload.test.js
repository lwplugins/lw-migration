/**
 * External dependencies
 */
import { describe, expect, it, vi } from 'vitest';

/**
 * Internal dependencies
 */
import {
	DONE,
	FAILED,
	LOGGED_OUT,
	MIN_CHUNK_BYTES,
	REREAD,
	sendFile,
	smallerChunk,
} from './upload';

const KB = 1024;
const MB = 1024 * KB;
const ID = 'abcdef0123456789';

const fakeFile = ( size ) => ( {
	size,
	slice: ( start, end ) => ( {
		start,
		end: Math.min( end, size ),
	} ),
} );

const waiting = ( received, size, chunkBytes ) => ( {
	id: ID,
	type: 'import',
	status: 'needs_input',
	input: { kind: 'upload', received, size, chunkBytes },
} );

const handedOver = () => ( {
	id: ID,
	type: 'import',
	status: 'running',
	input: null,
} );

/**
 * A server that stores what arrives in order and hands the job over when
 * every byte is there; `refuse( call, offset, blob )` may throw first.
 *
 * @param {number}                                                              size       Declared size.
 * @param {number}                                                              chunkBytes What the views report.
 * @param {(call: number, offset: number, blob: Object, state: Object) => void} refuse     Optional refusal hook.
 */
function server( size, chunkBytes, refuse = () => {} ) {
	const state = { received: 0, calls: 0 };
	const send = vi.fn( async ( id, offset, blob ) => {
		state.calls++;
		refuse( state.calls, offset, blob, state );
		if ( offset !== state.received ) {
			throw {
				status: 409,
				code: 'lw_migration_upload_offset',
				data: { received: state.received },
			};
		}
		state.received = blob.end;
		return state.received >= size
			? handedOver()
			: waiting( state.received, size, chunkBytes );
	} );
	const read = vi.fn( async () =>
		waiting( state.received, size, chunkBytes )
	);
	return { state, send, read };
}

const go = ( job, file, srv, extra = {} ) =>
	sendFile( {
		job,
		file,
		fallbackChunk: 2 * MB,
		send: srv.send,
		read: srv.read,
		onView: extra.onView || vi.fn(),
		onProgress: extra.onProgress || vi.fn(),
		stopped: extra.stopped || ( () => false ),
	} );

const slices = ( send ) =>
	send.mock.calls.map( ( [ , offset, blob ] ) => [
		offset,
		blob.end - blob.start,
	] );

describe( 'smallerChunk', () => {
	it( 'takes the size the server names', () => {
		expect(
			smallerChunk( 8 * MB, {
				code: 'lw_migration_chunk_too_large',
				data: { chunkBytes: MB },
			} )
		).toBe( MB );
	} );

	it( 'halves without a usable server size', () => {
		expect( smallerChunk( 8 * MB, { code: 'http_413' } ) ).toBe( 4 * MB );
		expect(
			smallerChunk( 8 * MB, {
				code: 'lw_migration_chunk_too_large',
				data: { chunkBytes: 8 * MB },
			} )
		).toBe( 4 * MB );
	} );

	it( 'never halves below the floor, and gives up at it', () => {
		expect( smallerChunk( 300 * KB, { code: 'http_413' } ) ).toBe(
			MIN_CHUNK_BYTES
		);
		expect(
			smallerChunk( MIN_CHUNK_BYTES, { code: 'http_413' } )
		).toBeNull();
	} );
} );

describe( 'sendFile', () => {
	it( 'sends the slices of the input chunk size from the server count', async () => {
		const srv = server( 10, 4 );
		srv.state.received = 2;
		const onView = vi.fn();
		const result = await go( waiting( 2, 10, 4 ), fakeFile( 10 ), srv, {
			onView,
		} );

		expect( result.outcome ).toBe( DONE );
		expect( slices( srv.send ) ).toEqual( [
			[ 2, 4 ],
			[ 6, 4 ],
		] );
		expect( srv.send.mock.calls[ 0 ][ 0 ] ).toBe( ID );
		expect( onView ).toHaveBeenLastCalledWith( handedOver() );
	} );

	it( 'uses the boot chunk size when the view names none', async () => {
		const srv = server( 3 * MB, 0 );
		await go( waiting( 0, 3 * MB, 0 ), fakeFile( 3 * MB ), srv );

		expect( slices( srv.send ) ).toEqual( [
			[ 0, 2 * MB ],
			[ 2 * MB, MB ],
		] );
	} );

	it( 'reports the share already sent before each slice', async () => {
		const onProgress = vi.fn();
		await go( waiting( 0, 8, 4 ), fakeFile( 8 ), server( 8, 4 ), {
			onProgress,
		} );

		expect( onProgress.mock.calls ).toEqual( [ [ 0 ], [ 50 ] ] );
	} );

	it( 'shrinks to the size of a JSON 413 and goes on from the server count', async () => {
		const srv = server( 3 * MB, 2 * MB, ( call ) => {
			if ( 1 === call ) {
				throw {
					status: 413,
					code: 'lw_migration_chunk_too_large',
					data: { chunkBytes: MB },
				};
			}
		} );
		const result = await go(
			waiting( 0, 3 * MB, 2 * MB ),
			fakeFile( 3 * MB ),
			srv
		);

		expect( result.outcome ).toBe( DONE );
		expect( srv.read ).toHaveBeenCalledWith( ID );
		expect( slices( srv.send ) ).toEqual( [
			[ 0, 2 * MB ],
			[ 0, MB ],
			[ MB, MB ],
			[ 2 * MB, MB ],
		] );
	} );

	it( 'halves on a web-server 413 page down to the floor and keeps the smaller size', async () => {
		const limit = 300 * KB;
		const size = 3 * MB;
		const srv = server( size, 2 * MB, ( call, offset, blob ) => {
			if ( blob.end - blob.start > limit ) {
				throw { status: 413, code: 'http_413', message: 'Too large' };
			}
		} );
		const result = await go(
			waiting( 0, size, 2 * MB ),
			fakeFile( size ),
			srv
		);

		expect( result.outcome ).toBe( DONE );
		const sizes = slices( srv.send ).map( ( [ , length ] ) => length );
		// 2 MB, 1 MB, 512 KB refused; 256 KB (the floor) passes and stays.
		expect( sizes.slice( 0, 3 ) ).toEqual( [ 2 * MB, MB, 512 * KB ] );
		expect( new Set( sizes.slice( 3 ) ) ).toEqual(
			new Set( [ MIN_CHUNK_BYTES ] )
		);
		expect( srv.state.received ).toBe( size );
	} );

	it( 'stops when a slice at the floor is still too large', async () => {
		const error = { status: 413, code: 'http_413' };
		const srv = server( MB, MIN_CHUNK_BYTES, () => {
			throw error;
		} );
		const result = await go(
			waiting( 0, MB, MIN_CHUNK_BYTES ),
			fakeFile( MB ),
			srv
		);

		expect( result ).toEqual( { outcome: FAILED, error } );
		expect( srv.send ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'goes on from the server count when a resent slice had already landed', async () => {
		// The answer to the first slice got lost: the server holds 4 bytes,
		// the page still thinks 0 and sends the first slice again.
		const srv = server( 10, 4 );
		srv.state.received = 4;
		const result = await go( waiting( 0, 10, 4 ), fakeFile( 10 ), srv );

		expect( result.outcome ).toBe( DONE );
		expect( slices( srv.send ) ).toEqual( [
			[ 0, 4 ],
			[ 4, 4 ],
			[ 8, 2 ],
		] );
	} );

	it( 'is done when a resent last slice is answered with the handed-over job', async () => {
		const send = vi.fn( async () => handedOver() );
		const result = await sendFile( {
			job: waiting( 6, 10, 4 ),
			file: fakeFile( 10 ),
			fallbackChunk: MB,
			send,
			read: vi.fn(),
			onView: vi.fn(),
			onProgress: vi.fn(),
			stopped: () => false,
		} );

		expect( result.outcome ).toBe( DONE );
		expect( send ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'resends the last slice once when every byte is there but the job still waits', async () => {
		// received === size, yet the view still asks for the upload: the
		// server hands it over when a slice inside the file arrives again.
		const send = vi.fn( async () => handedOver() );
		const onView = vi.fn();
		const result = await sendFile( {
			job: waiting( 10, 10, 4 ),
			file: fakeFile( 10 ),
			fallbackChunk: MB,
			send,
			read: vi.fn(),
			onView,
			onProgress: vi.fn(),
			stopped: () => false,
		} );

		expect( result.outcome ).toBe( DONE );
		expect( slices( send ) ).toEqual( [ [ 6, 4 ] ] );
		expect( onView ).toHaveBeenCalledWith( handedOver() );
	} );

	it( 'resends from the start of a file smaller than one slice', async () => {
		const send = vi.fn( async () => handedOver() );
		await sendFile( {
			job: waiting( 3, 3, 4 ),
			file: fakeFile( 3 ),
			fallbackChunk: MB,
			send,
			read: vi.fn(),
			onView: vi.fn(),
			onProgress: vi.fn(),
			stopped: () => false,
		} );

		expect( slices( send ) ).toEqual( [ [ 0, 3 ] ] );
	} );

	it( 'gives up when the resent last slice still leaves the job waiting', async () => {
		const send = vi.fn( async () => waiting( 10, 10, 4 ) );
		const result = await sendFile( {
			job: waiting( 10, 10, 4 ),
			file: fakeFile( 10 ),
			fallbackChunk: MB,
			send,
			read: vi.fn(),
			onView: vi.fn(),
			onProgress: vi.fn(),
			stopped: () => false,
		} );

		expect( result.outcome ).toBe( FAILED );
		expect( result.error.code ).toBe( 'lw_migration_upload_stuck' );
		expect( send ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'stops quietly when a request fails because the screen went away', async () => {
		let gone = false;
		const send = vi.fn( async () => {
			gone = true;
			throw { name: 'AbortError', code: 20 };
		} );
		const result = await sendFile( {
			job: waiting( 0, 10, 4 ),
			file: fakeFile( 10 ),
			fallbackChunk: MB,
			send,
			read: vi.fn(),
			onView: vi.fn(),
			onProgress: vi.fn(),
			stopped: () => gone,
		} );

		expect( result.outcome ).toBe( 'stopped' );
	} );

	it( 'reads the job and hands back when it no longer waits for the upload', async () => {
		const running = handedOver();
		const onView = vi.fn();
		const srv = {
			send: vi.fn( async () => {
				throw { status: 409, code: 'lw_migration_not_uploading' };
			} ),
			read: vi.fn( async () => running ),
		};
		const result = await go( waiting( 0, 10, 4 ), fakeFile( 10 ), srv, {
			onView,
		} );

		expect( result.outcome ).toBe( REREAD );
		expect( onView ).toHaveBeenCalledWith( running );
	} );

	it( 'stops as logged out on a 401', async () => {
		const error = { status: 401, code: 'rest_not_logged_in' };
		const srv = server( 10, 4, () => {
			throw error;
		} );
		const result = await go( waiting( 0, 10, 4 ), fakeFile( 10 ), srv );

		expect( result ).toEqual( { outcome: LOGGED_OUT, error } );
	} );

	it( 'stops on a file that is not an archive', async () => {
		const error = { status: 400, code: 'lw_migration_not_wpress' };
		const srv = server( 10, 4, () => {
			throw error;
		} );
		const result = await go( waiting( 0, 10, 4 ), fakeFile( 10 ), srv );

		expect( result ).toEqual( { outcome: FAILED, error } );
		expect( srv.send ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'stops with the error when the passing failures were already retried', async () => {
		// api.upload wraps every slice in withRetry(): a BUSY/RETRY that
		// reaches sendFile is the last attempt.
		const error = { status: 502, code: 'http_502' };
		const srv = server( 10, 4, () => {
			throw error;
		} );
		const result = await go( waiting( 0, 10, 4 ), fakeFile( 10 ), srv );

		expect( result ).toEqual( { outcome: FAILED, error } );
	} );

	it( 'stops quietly when the screen goes away', async () => {
		const srv = server( 10, 4 );
		const result = await go( waiting( 0, 10, 4 ), fakeFile( 10 ), srv, {
			stopped: () => true,
		} );

		expect( result.outcome ).toBe( 'stopped' );
		expect( srv.send ).not.toHaveBeenCalled();
	} );
} );
