/**
 * External dependencies
 */
import { describe, expect, it, vi } from 'vitest';

/**
 * Internal dependencies
 */
import { createSliceSender, UPLOAD_OFF } from './uploadSlice';

const blob = new Blob( [ 'slice' ] );

describe( 'createSliceSender', () => {
	it( 'sends a slice as the file part "chunk", without a raw octet-stream type', async () => {
		const request = vi.fn().mockResolvedValue( { id: 'j' } );
		await createSliceSender( request )( '/u', blob, null );

		const options = request.mock.calls[ 0 ][ 0 ];
		expect( options.body ).toBeInstanceOf( FormData );
		expect( options.body.get( 'chunk' ) ).toBeInstanceOf( Blob );
		expect( options.headers ).toBeUndefined();
	} );

	it( 'falls back to the raw body for good where the server takes no file uploads', async () => {
		const request = vi
			.fn()
			.mockRejectedValueOnce( { code: UPLOAD_OFF, status: 415 } )
			.mockResolvedValue( { id: 'j' } );
		const send = createSliceSender( request );

		await expect( send( '/u', blob, null ) ).resolves.toEqual( {
			id: 'j',
		} );
		await send( '/u', blob, null );

		expect( request ).toHaveBeenCalledTimes( 3 );
		[ 1, 2 ].forEach( ( call ) => {
			expect( request.mock.calls[ call ][ 0 ].body ).toBe( blob );
			expect( request.mock.calls[ call ][ 0 ].headers ).toEqual( {
				'Content-Type': 'application/octet-stream',
			} );
		} );
	} );

	it( 'passes any other refusal on', async () => {
		const refusal = { code: 'http_406', status: 406 };
		const request = vi.fn().mockRejectedValue( refusal );

		await expect(
			createSliceSender( request )( '/u', blob, null )
		).rejects.toBe( refusal );
		expect( request ).toHaveBeenCalledTimes( 1 );
	} );
} );
