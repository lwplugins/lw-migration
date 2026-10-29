/**
 * External dependencies
 */
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import { LANES } from './week';

// The built stylesheet (build/ is committed and checked for drift).
const css = () =>
	readFileSync( new URL( '../../build/index.css', import.meta.url ), 'utf8' );

describe( 'lane colours', () => {
	it( 'give every lane a plain colour that works without light-dark()', () => {
		const text = css();
		for ( let lane = 0; lane < LANES; lane++ ) {
			expect( text ).toMatch(
				new RegExp( `\\.lw-lane-${ lane }\\{--lw-lane:(?!light-dark)` )
			);
		}
	} );

	it( 'declare the dark variant only where light-dark() is supported', () => {
		const text = css();
		const all = text.match( /--lw-lane:light-dark\(/g ) || [];
		const guarded =
			text.match(
				/@supports\s*\(color:\s*light-dark\(#000,\s*#fff\)\)\s*\{\.lw-lane-\d\{--lw-lane:light-dark\(/g
			) || [];
		expect( all ).toHaveLength( LANES );
		expect( guarded ).toHaveLength( LANES );
	} );
} );
