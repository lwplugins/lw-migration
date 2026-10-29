/**
 * External dependencies
 */
import { describe, expect, it, vi } from 'vitest';

/**
 * Internal dependencies
 */
import { copyText } from './copy';

const codeNode = () => {
	const selection = { selectAllChildren: vi.fn() };
	const node = {
		ownerDocument: { defaultView: { getSelection: () => selection } },
	};
	return { node, selection };
};

describe( 'copyText', () => {
	it( 'writes to the clipboard and leaves the text unselected', async () => {
		const { node, selection } = codeNode();
		const clipboard = { writeText: vi.fn().mockResolvedValue( undefined ) };
		await expect( copyText( 'wp x', clipboard, node ) ).resolves.toBe(
			true
		);
		expect( clipboard.writeText ).toHaveBeenCalledWith( 'wp x' );
		expect( selection.selectAllChildren ).not.toHaveBeenCalled();
	} );

	it( 'selects the text when the clipboard refuses or is missing', async () => {
		const { node, selection } = codeNode();
		const clipboard = {
			writeText: vi.fn().mockRejectedValue( new Error( 'denied' ) ),
		};
		await expect( copyText( 'wp x', clipboard, node ) ).resolves.toBe(
			false
		);
		await expect( copyText( 'wp x', undefined, node ) ).resolves.toBe(
			false
		);
		expect( selection.selectAllChildren ).toHaveBeenCalledTimes( 2 );
		expect( selection.selectAllChildren ).toHaveBeenCalledWith( node );
		await expect( copyText( 'wp x', undefined, null ) ).resolves.toBe(
			false
		);
	} );
} );
