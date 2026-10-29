/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import {
	TOUCH_EVERY,
	actionsFor,
	allowText,
	clock,
	codeAddress,
	codeLeft,
	codeOutcome,
	drivenElsewhere,
	incoming,
	isHttp,
	looksLikeCode,
	neverUsed,
	normalizeCode,
	stateText,
} from './links';

const NONCE = '0123456789abcdef0123456789abcdef';
const b64url = ( text ) =>
	Buffer.from( text, 'utf8' )
		.toString( 'base64' )
		.replace( /\+/g, '-' )
		.replace( /\//g, '_' )
		.replace( /=+$/, '' );
const codeFor = ( address ) => `lwm1.${ b64url( address ) }.${ NONCE }`;

const link = ( over = {} ) => ( {
	id: 'aaaaaaaaaaaaaaaa',
	direction: 'out',
	name: 'Site B',
	address: 'https://b.example',
	allow: [ 'pull' ],
	state: 'ready',
	createdAt: 1000,
	lastUsedAt: 0,
	pairedFrom: '',
	insecure: false,
	...over,
} );

describe( 'drivenElsewhere', () => {
	it( 'is a job a connection runs here for the other site', () => {
		const mark = { id: 'aaaaaaaaaaaaaaaa', name: 'A', address: 'a' };
		expect(
			drivenElsewhere( {
				type: 'import',
				link: mark,
				source: { kind: 'upload' },
			} )
		).toBe( true );
		expect( drivenElsewhere( { type: 'export', link: mark } ) ).toBe(
			true
		);
	} );

	it( 'is never this site’s own push, pull or a job without a connection', () => {
		const mark = { id: 'aaaaaaaaaaaaaaaa', name: 'B', address: 'b' };
		expect( drivenElsewhere( { type: 'push', link: mark } ) ).toBe( false );
		expect(
			drivenElsewhere( {
				type: 'import',
				link: mark,
				source: { kind: 'link', name: 'B' },
			} )
		).toBe( false );
		expect( drivenElsewhere( { type: 'import', link: null } ) ).toBe(
			false
		);
		expect( drivenElsewhere( { type: 'export' } ) ).toBe( false );
		expect( drivenElsewhere( null ) ).toBe( false );
	} );
} );

describe( 'codes', () => {
	it( 'drops the whitespace a copy adds', () => {
		expect( normalizeCode( ' lwm1.ab\n cd.\tef ' ) ).toBe( 'lwm1.abcd.ef' );
		expect( normalizeCode( undefined ) ).toBe( '' );
	} );

	it( 'recognises a well-formed code, also with line breaks in it', () => {
		const code = codeFor( 'https://b.example' );
		expect( looksLikeCode( code ) ).toBe( true );
		expect( looksLikeCode( code.replace( '.', '.\n' ) ) ).toBe( true );
	} );

	it( 'refuses what is not a code', () => {
		const code = codeFor( 'https://b.example' );
		expect( looksLikeCode( '' ) ).toBe( false );
		expect( looksLikeCode( code.replace( 'lwm1', 'lwm2' ) ) ).toBe( false );
		expect( looksLikeCode( code.slice( 0, -1 ) ) ).toBe( false );
		expect( looksLikeCode( code.toUpperCase() ) ).toBe( false );
		expect( looksLikeCode( `lwm1.abc.${ NONCE }` ) ).toBe( false );
		expect( looksLikeCode( `lwm1.ab+d.${ NONCE }` ) ).toBe( false );
	} );

	it( 'reads the address inside a code', () => {
		expect( codeAddress( codeFor( 'http://127.0.0.1:8895' ) ) ).toBe(
			'http://127.0.0.1:8895'
		);
		expect(
			codeAddress( ` ${ codeFor( 'https://b.example/wp' ) }\n` )
		).toBe( 'https://b.example/wp' );
	} );

	it( 'has no address for a malformed code or a non-web address', () => {
		expect( codeAddress( 'garbage' ) ).toBeNull();
		expect( codeAddress( codeFor( 'ftp://b.example' ) ) ).toBeNull();
		expect( codeAddress( `lwm1.!!!!.${ NONCE }` ) ).toBeNull();
	} );

	it( 'knows a plain http address', () => {
		expect( isHttp( 'http://b.example' ) ).toBe( true );
		expect( isHttp( 'HTTP://b.example' ) ).toBe( true );
		expect( isHttp( 'https://b.example' ) ).toBe( false );
		expect( isHttp( null ) ).toBe( false );
	} );

	it( 'counts down to the expiry, never below zero', () => {
		expect( codeLeft( 1900, 1000 ) ).toBe( 900 );
		expect( codeLeft( 1900, 1000.6 ) ).toBe( 899 );
		expect( codeLeft( 1000, 1900 ) ).toBe( 0 );
		expect( clock( 900 ) ).toBe( '15:00' );
		expect( clock( 61 ) ).toBe( '1:01' );
		expect( clock( 0 ) ).toBe( '0:00' );
	} );
} );

describe( 'actionsFor', () => {
	it( 'offers sending and copying on a ready outgoing link with the rights', () => {
		const both = actionsFor( link( { allow: [ 'pull', 'push' ] } ), false );
		expect( both.push ).toEqual( { shown: true, disabled: false } );
		expect( both.pull ).toEqual( { shown: true, disabled: false } );
		expect( both.check ).toEqual( { shown: true, disabled: false } );
		expect( both.remove ).toEqual( { shown: true, disabled: false } );
	} );

	it( 'shows only the action of the one right', () => {
		const pull = actionsFor( link( { allow: [ 'pull' ] } ), false );
		expect( pull.push.shown ).toBe( false );
		expect( pull.pull.shown ).toBe( true );
		const push = actionsFor( link( { allow: [ 'push' ] } ), false );
		expect( push.push.shown ).toBe( true );
		expect( push.pull.shown ).toBe( false );
	} );

	it( 'disables sending and copying while another job runs', () => {
		const busy = actionsFor( link( { allow: [ 'pull', 'push' ] } ), true );
		expect( busy.push ).toEqual( { shown: true, disabled: true } );
		expect( busy.pull ).toEqual( { shown: true, disabled: true } );
		expect( busy.check.disabled ).toBe( false );
		expect( busy.remove.disabled ).toBe( false );
	} );

	it( 'offers only checking and removing on a pending or broken outgoing link', () => {
		for ( const state of [ 'pending', 'broken' ] ) {
			const actions = actionsFor(
				link( { state, allow: [ 'pull', 'push' ] } ),
				false
			);
			expect( actions.push.shown ).toBe( false );
			expect( actions.pull.shown ).toBe( false );
			expect( actions.check.shown ).toBe( true );
			expect( actions.remove.shown ).toBe( true );
		}
	} );

	it( 'offers only removing on an incoming link, whatever it allows', () => {
		for ( const state of [ 'ready', 'pending', 'broken' ] ) {
			const actions = actionsFor(
				link( { direction: 'in', state, allow: [ 'pull', 'push' ] } ),
				false
			);
			expect( actions.push.shown ).toBe( false );
			expect( actions.pull.shown ).toBe( false );
			expect( actions.check.shown ).toBe( false );
			expect( actions.remove ).toEqual( {
				shown: true,
				disabled: false,
			} );
		}
	} );
} );

describe( 'texts', () => {
	it( 'says what an outgoing link lets this site do', () => {
		expect( allowText( [ 'push' ], 'out' ) ).toBe(
			'May send this site there'
		);
		expect( allowText( [ 'pull' ], 'out' ) ).toBe(
			'May copy that site here'
		);
		expect( allowText( [ 'pull', 'push' ], 'out' ) ).toBe(
			'May send this site there and copy that site here'
		);
	} );

	it( 'says what an incoming link lets the other site do', () => {
		expect( allowText( [ 'pull' ], 'in' ) ).toBe(
			'The other site may copy this site'
		);
		expect( allowText( [ 'push' ], 'in' ) ).toBe(
			"The other site may replace this site's content"
		);
		expect( allowText( [ 'push', 'pull' ], 'in' ) ).toBe(
			"The other site may copy this site and replace this site's content"
		);
		expect( allowText( [], 'in' ) ).toBe( '' );
	} );

	it( 'names a state that is not ready', () => {
		expect( stateText( link( { state: 'pending' } ) ) ).toBe(
			'Waiting for the other site'
		);
		expect( stateText( link( { state: 'broken' } ) ) ).toBe(
			'No longer works here — pair again'
		);
		expect( stateText( link() ) ).toBe( '' );
	} );

	it( 'counts the pairing hello as no use', () => {
		expect( neverUsed( link( { lastUsedAt: 0 } ) ) ).toBe( true );
		expect( neverUsed( link( { lastUsedAt: 1003 } ) ) ).toBe( true );
		expect( neverUsed( link( { lastUsedAt: 1000 + TOUCH_EVERY } ) ) ).toBe(
			true
		);
		expect( neverUsed( link( { lastUsedAt: 1001 + TOUCH_EVERY } ) ) ).toBe(
			false
		);
	} );
} );

describe( 'codeOutcome', () => {
	it( 'says a site connected only when an incoming connection was added', () => {
		expect( codeOutcome( 1, 2 ) ).toBe( 'used' );
		expect( codeOutcome( 0, 1 ) ).toBe( 'used' );
	} );

	it( 'is neutral when the code went without a new connection', () => {
		// Cancelled elsewhere, replaced by a newer code, or used but not saved.
		expect( codeOutcome( 1, 1 ) ).toBe( 'closed' );
		expect( codeOutcome( 2, 1 ) ).toBe( 'closed' );
	} );

	it( 'counts the incoming connections of a list', () => {
		expect(
			incoming( [
				{ direction: 'in' },
				{ direction: 'out' },
				{ direction: 'in' },
			] )
		).toBe( 2 );
		expect( incoming( undefined ) ).toBe( 0 );
	} );
} );
