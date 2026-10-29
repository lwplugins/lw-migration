/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import {
	confirmHost,
	hostOf,
	isLow,
	matchesHost,
	neededSpace,
	safetyFits,
} from './check';
import {
	lowSpace,
	networkSites,
	plain,
	unreadable,
} from './fixtures/summaries';

describe( 'the typed confirmation', () => {
	it( 'asks for the host of the target, with its port', () => {
		expect( confirmHost( plain ) ).toBe( 'croco2.example' );
		expect( hostOf( 'http://127.0.0.1:8894/' ) ).toBe( '127.0.0.1:8894' );
		expect( hostOf( 'https://Example.COM/blog/' ) ).toBe( 'example.com' );
		expect( hostOf( '' ) ).toBe( '' );
		expect( hostOf( undefined ) ).toBe( '' );
	} );

	it( "on a network asks for the network's main host", () => {
		expect( confirmHost( networkSites ) ).toBe( 'net.example' );
	} );

	it( 'accepts the host trimmed and in any letter case', () => {
		expect( matchesHost( '  CROCO2.example ', 'croco2.example' ) ).toBe(
			true
		);
		expect( matchesHost( 'croco2.example', 'croco2.example' ) ).toBe(
			true
		);
	} );

	it( 'refuses anything else, and an empty target', () => {
		expect( matchesHost( 'croco2.exampl', 'croco2.example' ) ).toBe(
			false
		);
		expect(
			matchesHost( 'https://croco2.example', 'croco2.example' )
		).toBe( false );
		expect( matchesHost( '', '' ) ).toBe( false );
		expect( matchesHost( undefined, 'croco2.example' ) ).toBe( false );
	} );
} );

describe( 'the space the restore needs', () => {
	it( 'follows the safety-backup switch', () => {
		expect( neededSpace( plain, true ) ).toBe( plain.diskNeeded );
		expect( neededSpace( plain, false ) ).toBe(
			plain.diskNeeded - plain.safetyBytes
		);
		expect( neededSpace( { ...plain, safetyBackup: false }, true ) ).toBe(
			plain.diskNeeded + plain.safetyBytes
		);
	} );

	it( 'is low only when the free space is known and smaller', () => {
		expect( isLow( plain, true ) ).toBe( false );
		expect( isLow( lowSpace, true ) ).toBe( true );
		expect( isLow( lowSpace, false ) ).toBe( false );
		expect( isLow( unreadable, true ) ).toBe( false );
	} );

	it( 'lets the safety backup start only when it fits next to the restore', () => {
		expect( safetyFits( plain ) ).toBe( true );
		expect( safetyFits( lowSpace ) ).toBe( false );
		expect( safetyFits( unreadable ) ).toBe( true );
	} );
} );
