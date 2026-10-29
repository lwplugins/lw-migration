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
import DestinationCard from './DestinationCard';

// Plain stand-ins keep what the card shows: a button its text, the menu its
// items, a badge its label.
vi.mock( '@wordpress/components', () => {
	const box =
		( tag ) =>
		( { children, className } ) =>
			createElement( tag, { className }, children );
	return {
		Button: ( { children, variant, disabled } ) =>
			createElement(
				'button',
				{
					'data-variant': variant,
					'aria-disabled': disabled ? 'true' : undefined,
				},
				children
			),
		DropdownMenu: ( { label, controls } ) =>
			createElement(
				'div',
				{ className: 'menu', 'aria-label': label },
				controls.map( ( c ) =>
					createElement( 'span', { key: c.title }, c.title )
				)
			),
		Notice: ( { children, status } ) =>
			createElement(
				'div',
				{ className: `notice-${ status }` },
				children
			),
		TextControl: box( 'div' ),
	};
} );
vi.mock( '../components/StatusBadge', () => ( {
	default: ( { status, children } ) =>
		createElement( 'span', { className: `badge-${ status }` }, children ),
} ) );
vi.mock( '../data/api', () => ( { api: {} } ) );
vi.mock( '../data/format', () => ( {
	formatBytes: ( n ) => `${ n } B`,
	formatDatetime: () => 'today 03:12',
} ) );

const SFTP = { type: 'sftp', label: 'SFTP', fields: [] };

const item = ( patch = {} ) => ( {
	id: 'abcd1234',
	type: 'sftp',
	name: 'Office NAS',
	where: 'sftp://nas.test:22',
	status: 'ok',
	message: '',
	settings: { host: 'nas.test' },
	retention: { count: 10, bytes: 0, days: 0 },
	lastUploadAt: 1760000000,
	usedBy: 1,
	...patch,
} );

const render = ( props ) =>
	renderToStaticMarkup(
		createElement( DestinationCard, {
			type: SFTP,
			busy: false,
			onStart: () => {},
			onDelete: () => Promise.resolve(),
			onChanged: () => Promise.resolve(),
			onWorks: () => {},
			...props,
		} )
	);

describe( 'DestinationCard', () => {
	it( 'shows a working destination: chip, where, facts, the actions', () => {
		const html = render( { item: item() } );
		expect( html ).toMatch( /^<li class="lw-dest"/ );
		expect( html ).toContain( '<span class="badge-ok">Working</span>' );
		expect( html ).toContain(
			'<h3 id="lw-dest-abcd1234-title" tabindex="-1" class="lw-dest__name">Office NAS</h3>'
		);
		expect( html ).toContain( 'sftp://nas.test:22' );
		expect( html ).toContain( 'Last upload <b>today 03:12</b>' );
		expect( html ).toContain( 'Schedules <b>1 schedule</b>' );
		expect( html ).toContain( 'Keeping <b>10 backups</b>' );
		expect( html ).toContain( 'Check connection' );
		expect( html ).toContain( 'Backups there' );
		expect( html ).toContain( '<span>Edit</span><span>Delete</span>' );
		expect( html ).not.toContain( 'lw-dest__message' );
		expect( html ).not.toContain( 'lw-dest__panel' );
	} );

	it( 'shows a new destination as not tested yet, nothing uploaded', () => {
		const html = render( {
			item: item( { status: 'new', lastUploadAt: 0, usedBy: 0 } ),
		} );
		expect( html ).toContain(
			'<span class="badge-idle">Not tested yet</span>'
		);
		expect( html ).toContain( 'Last upload <b>Nothing uploaded yet</b>' );
		expect( html ).not.toContain( 'Schedules' );
	} );

	it( 'shows a failing destination with its stored message', () => {
		const html = render( {
			item: item( { status: 'error', message: 'Login refused.' } ),
		} );
		expect( html ).toContain(
			'<span class="badge-critical">Failing</span>'
		);
		expect( html ).toContain(
			'<p class="lw-dest__message is-error">Login refused.</p>'
		);
	} );

	it( 'shows an account that needs a new sign-in: Reconnect first, and the message', () => {
		const html = render( {
			type: { type: 'dropbox', label: 'Dropbox', fields: [], oauth: {} },
			item: item( {
				type: 'dropbox',
				status: 'reauth',
				message: 'Dropbox withdrew the access.',
				settings: { ownApp: false },
				connection: {
					connected: true,
					eligible: true,
					account: 'team@example.test',
				},
			} ),
		} );
		expect( html ).toContain(
			'<span class="badge-warning">Credentials needed</span>'
		);
		expect( html ).toMatch(
			/<div class="lw-dest__actions"><button data-variant="primary">Reconnect<\/button>/
		);
		expect( html ).toContain(
			'<p class="lw-dest__message is-warning">Dropbox withdrew the access.</p>'
		);
		expect( html ).toContain( 'Account <b>team@example.test</b>' );
	} );

	it( 'warns under the row about an unencrypted destination', () => {
		const html = render( { item: item( { insecure: true } ) } );
		expect( html ).toContain(
			'<div class="notice-warning">This destination sends backups unencrypted.</div>'
		);
	} );
} );
