/**
 * External dependencies
 */
// react-dom comes with @wordpress/element; these tests render on the server.
// eslint-disable-next-line import/no-extraneous-dependencies
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';

/**
 * WordPress dependencies
 */
import { createElement } from '@wordpress/element';

/**
 * Internal dependencies
 */
import CheckView from './CheckView';
import {
	encrypted,
	lowSpace,
	networkSites,
	plain,
	reference,
	unreadable,
} from './fixtures/summaries';

// The real components need the browser; plain stand-ins keep what they show.
vi.mock(
	'@wordpress/components',
	() => import( '../export/fixtures/components' )
);

// The markup escapes apostrophes; the texts are compared as written.
const render = ( summary, props = {} ) =>
	renderToStaticMarkup(
		createElement( CheckView, {
			summary,
			back: true,
			onConfirm: () => Promise.resolve(),
			onDecline: () => Promise.resolve(),
			...props,
		} )
	).replaceAll( '&#x27;', "'" );

const factRow = ( html, id ) => {
	const at = html.indexOf( `data-fact="${ id }"` );
	return html.slice(
		html.lastIndexOf( '<tr', at ),
		html.indexOf( '</tr>', at )
	);
};

describe( 'the check view in page order', () => {
	it( 'compares, notes the address, shows the parts, the switch, the logins and the actions', () => {
		const html = render( plain );
		const order = [
			'Check before restoring',
			'In the archive',
			'lw-facts-table',
			'The old address (dev2.example) is replaced with croco2.example',
			'What gets replaced',
			'Safety backup first',
			'After the restore the users come from the backup.',
			'>Back<',
			'Type croco2.example to confirm',
			'Restore now',
		].map( ( needle ) => html.indexOf( needle ) );
		expect( order.every( ( at ) => at >= 0 ) ).toBe( true );
		expect( [ ...order ].sort( ( a, b ) => a - b ) ).toEqual( order );
	} );

	it( 'highlights what differs and keeps what is the same plain', () => {
		const html = render( plain );
		expect( factRow( html, 'address' ) ).toContain( 'is-changed' );
		expect( factRow( html, 'php' ) ).toContain( 'is-changed' );
		expect( factRow( html, 'wordpress' ) ).toContain( 'is-same' );
		expect( factRow( html, 'prefix' ) ).toContain( 'wp_ (stays)' );
	} );

	it( 'keeps Restore now off until the host is typed', () => {
		const html = render( plain );
		const button = html.slice(
			html.lastIndexOf( '<button', html.indexOf( 'Restore now' ) )
		);
		expect( button ).toMatch( /^<button aria-disabled="true">Restore now/ );
	} );

	it( 'says the themes are not in the backup and never counts tables', () => {
		const html = render( plain );
		expect( html ).toContain( 'not in the backup' );
		expect( html ).toContain( 'this plugin stays' );
		expect( html ).not.toMatch( /\d+ tables?\b/ );
	} );
} );

describe( 'the check view for other archives', () => {
	it( 'reads an encrypted archive with stored sizes', () => {
		const html = render( encrypted );
		expect( factRow( html, 'encryption' ) ).toContain(
			'encrypted, password accepted'
		);
		expect( html ).toContain( 'stored: ' );
		expect( html ).not.toContain( 'The old address' );
	} );

	it( 'shows what the reference tool leaves out as unknown, and still asks', () => {
		const html = render( reference );
		expect( factRow( html, 'wordpress' ) ).toContain( 'unknown' );
		expect( factRow( html, 'prefix' ) ).toContain( 'is-unknown' );
		expect( html ).toContain( 'Type croco2.example to confirm' );
	} );

	it( 'marks the parts it could not read as unknown', () => {
		const html = render( unreadable );
		expect( html ).toContain( 'Could not be read from the archive.' );
		expect( html ).toContain( 'unknown address' );
		expect( html ).toContain( 'free space unknown' );
	} );

	it( "shows a network's sites and keeps its code", () => {
		const html = render( networkSites );
		expect( html ).toContain( 'Where each site goes' );
		expect( html ).toContain( "The network's stay as they are." );
		expect( html ).toContain( "Only the archive's sites" );
		expect( html ).toContain(
			"The archive's users are added to this network."
		);
		expect( html ).toContain( 'Nothing on this network has changed yet.' );
		expect( html ).toContain( 'Type net.example to confirm' );
		expect( html ).not.toContain( 'The old address' );
	} );

	it( 'turns the safety backup off when it does not fit, and says why', () => {
		const html = render( lowSpace );
		const toggle = /<input[^>]*role="switch"[^>]*>/.exec( html )[ 0 ];
		expect( toggle ).toContain( 'disabled=""' );
		expect( toggle ).not.toContain( 'checked=""' );
		expect( html ).toContain( 'There is not enough free space for it' );
	} );

	it( 'tells the safety backup size and the free space next to the switch', () => {
		const html = render( plain );
		expect( html ).toContain( 'A backup of the current database, about' );
		expect( html ).toMatch( /Backups\. [\d.,]+ \S+ is free\./ );
		expect( render( { ...plain, diskFree: null } ) ).not.toContain(
			' is free.'
		);
	} );

	it( 'names the remote site in the push caption and hides header cells, not the thead', () => {
		const html = render( plain, {
			back: false,
			target: { name: 'Shop', address: 'https://croco2.example' },
		} );
		expect( html ).toContain( 'The archive next to the connected site' );
		expect( html ).toContain( '<thead><tr>' );
		expect(
			html.match( /<th scope="col" class="screen-reader-text"/g )
		).toHaveLength( 4 );
		expect( render( plain ) ).toContain( 'The archive next to this site' );
	} );

	it( 'asks about the other site on a push, with Cancel', () => {
		const html = render( plain, {
			back: false,
			target: { name: 'Shop', address: 'https://croco2.example' },
		} );
		expect( html ).toContain( 'Restore this site on Shop?' );
		expect( html ).toContain( 'Goes there' );
		expect( html ).toContain( '>Cancel<' );
		expect( html ).toContain( 'Restore there' );
		expect( html ).toContain( 'connection code' );
	} );
} );
