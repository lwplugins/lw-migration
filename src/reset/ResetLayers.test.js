/**
 * External dependencies
 */
// react-dom comes with @wordpress/element; these tests render on the server.
// eslint-disable-next-line import/no-extraneous-dependencies
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';

/**
 * WordPress dependencies
 */
import { createElement } from '@wordpress/element';

/**
 * Internal dependencies
 */
import ResetLayers from './ResetLayers';
import { NETWORK, SITE } from './fixtures/summaries';

const render = ( type, summary = SITE ) =>
	renderToStaticMarkup( createElement( ResetLayers, { type, summary } ) );

const count = ( html, needle ) => html.split( needle ).length - 1;

/**
 * @param {string} html Markup.
 * @return {string[]} Every line under a row's title.
 */
const lines = ( html ) =>
	[
		...html.matchAll( /class="lw-reset-layer__(?:line|name)">([^<]*)</g ),
	].map( ( match ) => match[ 1 ] );

describe( 'ResetLayers', () => {
	it( 'shows the four layers as a list, only the chosen one going', () => {
		const html = render( 'plugins' );
		expect( html ).toContain(
			'<ul class="lw-reset-layers" aria-label="The layers of the site">'
		);
		expect( count( html, 'class="lw-reset-layer is-goes"' ) ).toBe( 1 );
		expect( count( html, 'class="lw-reset-layer is-stays"' ) ).toBe( 3 );
		expect( count( html, '>Goes</span>' ) ).toBe( 1 );
		// The tags are words, not colour only.
		expect( html ).toContain( 'class="lw-reset-tag is-goes">Goes<' );
		expect( html ).toContain( 'class="lw-reset-tag is-stays">Stays<' );
		expect( html ).toContain( '12 plugins: a/a.php and 11 more' );
		expect( html ).toContain( '3 themes, all stay' );
		expect( html ).toContain( 'Content and 6 users stay' );
	} );

	it( 'puts what no reset touches under the rule', () => {
		const html = render( 'plugins' );
		const rule = html.indexOf(
			'id="lw-reset-bedrock" class="lw-reset-bedrock">Nothing here is touched by any reset<'
		);
		expect( rule ).toBeGreaterThan( 0 );
		expect( html ).toContain(
			'<ul class="lw-reset-layers" aria-labelledby="lw-reset-bedrock">'
		);
		expect(
			html.indexOf( 'WordPress core and wp-config.php' )
		).toBeGreaterThan( rule );
		expect(
			html.indexOf( 'LW Migration and its backups' )
		).toBeGreaterThan( rule );
		expect( count( html, 'class="lw-reset-layer is-base"' ) ).toBe( 2 );
		expect( html ).not.toContain( 'You (admin)' );
		// Each base row has its title, its lines under it.
		expect( html ).toContain(
			'class="lw-reset-layer__name">LW Migration and your backups<'
		);
		expect( html ).toContain(
			'class="lw-reset-layer__line">LW Migration and its backups<'
		);
		expect( html ).toContain(
			'class="lw-reset-layer__name">WordPress core and wp-config.php<'
		);
		expect( count( html, 'WordPress core and wp-config.php' ) ).toBe( 1 );
	} );

	it( 'for the database shows plugins and media as staying on disk only', () => {
		const html = render( 'database' );
		expect( count( html, 'class="lw-reset-layer is-disk"' ) ).toBe( 2 );
		expect( count( html, 'class="lw-reset-layer is-stays"' ) ).toBe( 1 );
		expect( count( html, 'class="lw-reset-layer is-goes"' ) ).toBe( 1 );
		expect(
			count( html, 'class="lw-reset-tag is-disk">Stays on disk<' )
		).toBe( 2 );
		expect( html ).toContain( '12 plugins stay on disk, switched off' );
		expect( html ).toContain( 'Only LW Migration stays active' );
		expect( html ).toContain(
			'1,284 media items: the files stay on disk, but the library is emptied'
		);
		// The only-active line sits on the plugins layer, above the rule.
		expect( html.indexOf( 'Only LW Migration stays active' ) ).toBeLessThan(
			html.indexOf( 'Nothing here is touched by any reset' )
		);
		expect( html ).toContain( 'class="lw-reset-layer__name">You (admin)<' );
		expect( render( 'full' ) ).not.toContain( 'is-disk' );
	} );

	it( 'carries the drop-in note as a warning line when plugins go', () => {
		expect( render( 'plugins' ) ).toContain( 'class="lw-reset-warnline"' );
		expect( render( 'full' ) ).toContain(
			'Drop-ins stay: object-cache.php'
		);
		expect( render( 'media' ) ).not.toContain( 'lw-reset-warnline' );
	} );

	it( 'for everything strikes all four, shows you in the base and never repeats a line', () => {
		const html = render( 'full' );
		expect( count( html, 'class="lw-reset-layer is-goes"' ) ).toBe( 4 );
		expect( count( html, 'class="lw-reset-layer is-base"' ) ).toBe( 3 );
		expect( html ).toContain(
			'You (admin): login, password, profile and sessions'
		);
		const all = lines( html );
		expect( new Set( all ).size ).toBe( all.length );
	} );

	it( 'shows the media of every site on a network', () => {
		expect( render( 'media', NETWORK ) ).toContain(
			'Every media item of every site and every file in the uploads folder'
		);
	} );
} );
