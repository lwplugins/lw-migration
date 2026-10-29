/**
 * External dependencies
 */
// react-dom comes with @wordpress/element; these tests render on the server.
// eslint-disable-next-line import/no-extraneous-dependencies
import { renderToStaticMarkup } from 'react-dom/server';
import { afterEach, describe, expect, it, vi } from 'vitest';

/**
 * WordPress dependencies
 */
import { createElement } from '@wordpress/element';

/**
 * Internal dependencies
 */
import AddShelf, { focusShelf, tileId } from './AddShelf';
import { findAll } from './elementTree';

vi.mock( '../components/DestinationIcon', () => ( {
	default: ( { type, preset } ) =>
		createElement( 'i', { 'data-icon': `${ type }:${ preset }` } ),
} ) );

const TYPES = [
	{ type: 'gdrive', label: 'Google Drive', available: true, oauth: {} },
	{
		type: 'dropbox',
		label: 'Dropbox',
		available: false,
		reason: 'needs the cURL extension',
		oauth: {},
	},
	{
		type: 's3',
		label: 'S3-compatible storage',
		available: true,
		fields: [
			{
				key: 'preset',
				options: [
					{ value: 'aws', label: 'Amazon S3' },
					{ value: 'hetzner', label: 'Hetzner Object Storage' },
				],
			},
		],
	},
	{ type: 'webdav', label: 'WebDAV (Nextcloud and others)', available: true },
	{ type: 'sftp', label: 'SFTP (SSH)', available: true },
];

const props = ( patch = {} ) => ( {
	types: TYPES,
	empty: false,
	full: false,
	isPressed: () => false,
	onPick: vi.fn(),
	...patch,
} );

const render = ( patch ) =>
	renderToStaticMarkup( createElement( AddShelf, props( patch ) ) );

/**
 * @param {Object} shelf AddShelf's element tree.
 * @return {Object[]} Each tile's button element (the Tile stand-in rendered).
 */
const tiles = ( shelf ) =>
	findAll( shelf, ( el ) => el.props?.tile ).map( ( el ) =>
		el.type( el.props )
	);

describe( 'AddShelf', () => {
	afterEach( () => {
		delete globalThis.document;
	} );

	it( 'groups the places by how you get in, in shelf order', () => {
		const html = render();
		const order = [
			'With a sign-in',
			'Nothing to type in or register.',
			'With an access key',
			'S3-compatible storage, with the key from the provider.',
			'On your own server',
			'With a user name and a password or a key.',
		].map( ( text ) => html.indexOf( text ) );
		expect( order.every( ( at ) => at >= 0 ) ).toBe( true );
		expect( [ ...order ].sort( ( a, b ) => a - b ) ).toEqual( order );
		expect( html ).toContain( '<h2 id="lw-dest-add-title" tabindex="-1"' );
		expect( html ).toContain( '>New destination</h2>' );
		expect( html ).toContain( 'Hetzner Object Storage' );
		expect( html ).toContain( 'data-icon="s3:hetzner"' );
		expect( html ).toContain( 'data-icon="sftp:"' );
		// Servers: SFTP before WebDAV.
		expect( html.indexOf( 'SFTP (SSH)' ) ).toBeLessThan(
			html.indexOf( 'WebDAV (Nextcloud' )
		);
	} );

	it( 'asks where the backups should go when there is no destination yet', () => {
		expect( render( { empty: true } ) ).toContain(
			'>Where should the backups go?</h2>'
		);
	} );

	it( 'keeps an unavailable type as a closed tile with its reason', () => {
		const html = render();
		expect( html ).toMatch(
			/id="lw-dest-pick-dropbox"[^>]*aria-disabled="true"/
		);
		expect( html ).toContain( 'needs the cURL extension' );
		expect( html ).not.toMatch(
			/id="lw-dest-pick-gdrive"[^>]*aria-disabled/
		);
	} );

	it( 'closes every tile when the list is full and says why', () => {
		const html = render( { full: true } );
		expect( html ).toContain( 'You can have at most 20 destinations.' );
		const closed = html.match( /aria-disabled="true"/g ) ?? [];
		expect( closed ).toHaveLength( 6 );
		expect( html ).toMatch(
			/id="lw-dest-pick-gdrive"[^>]*aria-describedby="lw-dest-add-full"/
		);
		// The shelf is never hidden, and an unavailable type keeps its own reason.
		expect( html ).toContain( 'With a sign-in' );
		expect( html ).toContain( 'needs the cURL extension' );
	} );

	it( 'marks the open draft tile as pressed', () => {
		const html = render( {
			isPressed: ( tile ) =>
				'aws' === tile.preset || 'sftp' === tile.typeKey,
		} );
		expect( html ).toMatch(
			/id="lw-dest-pick-s3-aws"[^>]*aria-pressed="true"/
		);
		expect( html ).toMatch(
			/id="lw-dest-pick-s3-hetzner"[^>]*aria-pressed="false"/
		);
		expect( html ).toMatch(
			/id="lw-dest-pick-sftp"[^>]*aria-pressed="true"/
		);
	} );

	it( 'picks an open tile, never a closed one', () => {
		const onPick = vi.fn();
		const buttons = tiles( AddShelf( props( { onPick } ) ) );
		const byId = ( id ) => buttons.find( ( b ) => b.props.id === id );

		byId( 'lw-dest-pick-dropbox' ).props.onClick();
		expect( onPick ).not.toHaveBeenCalled();
		byId( 'lw-dest-pick-s3-hetzner' ).props.onClick();
		expect( onPick ).toHaveBeenCalledWith(
			expect.objectContaining( { typeKey: 's3', preset: 'hetzner' } )
		);

		const full = tiles( AddShelf( props( { onPick, full: true } ) ) );
		full[ 0 ].props.onClick();
		expect( onPick ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'names tiles by type and S3 provider', () => {
		expect( tileId( { typeKey: 'sftp' } ) ).toBe( 'lw-dest-pick-sftp' );
		expect( tileId( { typeKey: 's3', preset: 'r2' } ) ).toBe(
			'lw-dest-pick-s3-r2'
		);
	} );

	it( 'focuses the first open tile, else the heading', () => {
		const tile = { focus: vi.fn() };
		const title = { focus: vi.fn() };
		const shelf = {
			scrollIntoView: vi.fn(),
			querySelector: vi.fn( () => tile ),
		};
		globalThis.document = {
			getElementById: ( id ) =>
				( { 'lw-dest-add': shelf, 'lw-dest-add-title': title } )[ id ],
		};

		focusShelf();
		expect( shelf.querySelector ).toHaveBeenCalledWith(
			'.lw-shelf-place:not([aria-disabled="true"])'
		);
		expect( shelf.scrollIntoView ).toHaveBeenCalled();
		expect( tile.focus ).toHaveBeenCalledWith( { preventScroll: true } );
		expect( title.focus ).not.toHaveBeenCalled();

		shelf.querySelector.mockReturnValue( null );
		focusShelf();
		expect( title.focus ).toHaveBeenCalledTimes( 1 );

		focusShelf( false );
		expect( title.focus ).toHaveBeenCalledTimes( 2 );
		expect( tile.focus ).toHaveBeenCalledTimes( 1 );
	} );
} );
