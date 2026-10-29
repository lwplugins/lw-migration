/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import { GB, bytesFrom, gbShown } from './retentionSize';

const MAX = 1125899906842624;

describe( 'gbShown', () => {
	it( 'keeps up to two decimals', () => {
		expect( gbShown( 0 ) ).toBe( 0 );
		expect( gbShown( 10 * GB ) ).toBe( 10 );
		expect( gbShown( 1.25 * GB ) ).toBe( 1.25 );
		expect( gbShown( 1.37 * GB + 12345 ) ).toBe( 1.37 );
	} );
} );

describe( 'bytesFrom', () => {
	it( 'gives back the exact stored bytes for an untouched value', () => {
		const stored = 1.234567 * GB;
		const exact = Math.round( stored );
		expect( bytesFrom( String( gbShown( exact ) ), exact, MAX ) ).toBe(
			exact
		);
		expect( bytesFrom( '1.23', exact, MAX ) ).toBe( exact );
	} );

	it( 'turns a changed value into whole bytes', () => {
		expect( bytesFrom( '2.5', 1 * GB, MAX ) ).toBe( 2.5 * GB );
		expect( bytesFrom( '0.01', 0, MAX ) ).toBe( Math.round( 0.01 * GB ) );
	} );

	it( 'reads an empty or broken field as 0 and clamps to the maximum', () => {
		expect( bytesFrom( '', 5 * GB, MAX ) ).toBe( 0 );
		expect( bytesFrom( 'abc', 5 * GB, MAX ) ).toBe( 0 );
		expect( bytesFrom( '-3', 5 * GB, MAX ) ).toBe( 0 );
		expect( bytesFrom( String( MAX / GB + 1 ), 0, MAX ) ).toBe( MAX );
	} );
} );
