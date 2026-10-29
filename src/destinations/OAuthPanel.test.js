/**
 * External dependencies
 */
// react-dom comes with @wordpress/element; only this test renders on the server.
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
import OAuthPanel from './OAuthPanel';

// The real components need the browser; plain stand-ins keep what they show.
vi.mock( '@wordpress/components', () => ( {
	Button: ( { children } ) =>
		createElement( 'button', { className: 'copy' }, children ),
	ExternalLink: ( { href, children } ) =>
		createElement( 'a', { href }, children ),
	Notice: ( { children } ) =>
		createElement( 'div', { className: 'notice' }, children ),
} ) );
vi.mock( '@wordpress/compose', () => ( { useCopyToClipboard: () => null } ) );

const type = ( relay ) => ( {
	type: 'dropbox',
	label: 'Dropbox',
	oauth: {
		redirectUri: 'https://site.test/wp-json/lw-migration/v1/oauth/callback',
		eligible: true,
		text: '',
		steps: [ 'Register the app step.' ],
		consoleUrl: 'https://console.provider.test/apps',
		relay,
	},
} );

const render = ( props ) =>
	renderToStaticMarkup( createElement( OAuthPanel, props ) );

describe( 'OAuthPanel', () => {
	it( 'shows no redirect address, copy button, steps or console link for the LW app', () => {
		const html = render( {
			type: type( { eligible: true, text: '' } ),
			item: null,
			settings: { ownApp: false },
		} );
		expect( html ).toContain( 'Dropbox' );
		expect( html ).not.toContain( 'oauth/callback' );
		expect( html ).not.toContain( '<button' );
		expect( html ).not.toContain( 'Register the app step.' );
		expect( html ).not.toContain( 'console.provider.test' );
		expect( html ).not.toContain( 'class="notice"' );
	} );

	it( 'shows our notice when the relay rule refuses this site', () => {
		const html = render( {
			type: type( { eligible: false, text: 'Our relay notice.' } ),
			item: null,
			settings: { ownApp: false },
		} );
		expect( html ).toContain( 'Our relay notice.' );
		expect( html ).not.toContain( 'oauth/callback' );
	} );

	it( 'shows the address, copy button, steps and console for the own app', () => {
		const html = render( {
			type: type( { eligible: true, text: '' } ),
			item: null,
			settings: { ownApp: true },
		} );
		expect( html ).toContain( 'oauth/callback' );
		expect( html ).toContain( '<button' );
		expect( html ).toContain( 'Register the app step.' );
		expect( html ).toContain( 'console.provider.test' );
	} );
} );
