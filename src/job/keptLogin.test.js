/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import { keptByCodeMaker, keptLogin } from './keptLogin';

const restore = ( result, patch = {} ) => ( {
	type: 'import',
	status: 'done',
	result,
	...patch,
} );

describe( 'keptLogin', () => {
	it( 'is true when the finished restore kept the login', () => {
		expect( keptLogin( restore( { loginKept: true } ) ) ).toBe( true );
	} );

	it( 'is false when the server says it was not kept', () => {
		expect( keptLogin( restore( { loginKept: false } ) ) ).toBe( false );
	} );

	it( 'is false when it is not known (missing, null, not a boolean)', () => {
		expect( keptLogin( restore( { safetyBackup: null } ) ) ).toBe( false );
		expect( keptLogin( restore( { loginKept: null } ) ) ).toBe( false );
		expect( keptLogin( restore( { loginKept: 'yes' } ) ) ).toBe( false );
		expect( keptLogin( restore( undefined ) ) ).toBe( false );
	} );

	it( 'is false for anything but a finished restore', () => {
		expect(
			keptLogin( restore( { loginKept: true }, { status: 'failed' } ) )
		).toBe( false );
		expect(
			keptLogin( restore( { loginKept: true }, { type: 'export' } ) )
		).toBe( false );
	} );
} );

describe( 'a restore a connected site pushed here', () => {
	const pushed = ( result ) =>
		restore( result, {
			link: { id: 'aaaaaaaaaaaaaaaa', name: 'A', address: 'a' },
			source: { kind: 'upload', name: 'x.wpress', size: 1 },
		} );

	it( 'keeps only the login of the admin who made the code', () => {
		expect( keptLogin( pushed( { loginKept: true } ) ) ).toBe( false );
		expect( keptByCodeMaker( pushed( { loginKept: true } ) ) ).toBe( true );
		expect( keptByCodeMaker( pushed( { loginKept: false } ) ) ).toBe(
			false
		);
	} );

	it( 'says the viewer kept the login for this site’s own pull', () => {
		const pull = restore(
			{ loginKept: true },
			{
				link: { id: 'aaaaaaaaaaaaaaaa', name: 'B', address: 'b' },
				source: { kind: 'link', name: 'B' },
			}
		);
		expect( keptLogin( pull ) ).toBe( true );
		expect( keptByCodeMaker( pull ) ).toBe( false );
		expect( keptByCodeMaker( restore( { loginKept: true } ) ) ).toBe(
			false
		);
	} );
} );
