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
import { DEFAULTS } from '../views/exportRequest';
import ContentChoices from './ContentChoices';
import { pluginsHelp, themesHelp } from './choiceText';
import { estimateStatus } from './estimate';
import {
	estimate,
	FILE_PARTS,
	firstWalk,
	withoutFiles,
} from './fixtures/estimate';

// The real components need the browser; plain stand-ins keep what they show.
vi.mock( '@wordpress/components', () => import( './fixtures/components' ) );

const form = ( patch = {} ) => ( { ...DEFAULTS, ...patch } );

const render = ( Component, props ) =>
	renderToStaticMarkup( createElement( Component, props ) );

const choices = ( given, error = null, options = form() ) =>
	render( ContentChoices, {
		options,
		onChange: () => {},
		estimate: given,
		status: estimateStatus( given, error ),
	} );

describe( 'ContentChoices', () => {
	it( 'leaves the cache out by default, and only the cache', () => {
		const html = choices( estimate() );
		expect( html ).toContain(
			'Everything except the cache folders is in by default.'
		);
		expect( html ).toMatch(
			/Leave out the cache <span[^>]*>[^<]*<\/span><\/label>.*?<input[^>]*readOnly="" checked=""/
		);
		expect( html ).toMatch(
			/Leave out spam comments<\/label>.*?<input[^>]*readOnly=""\/>/
		);
	} );

	it( 'shows each part with its size and count', () => {
		const html = choices( estimate() );
		expect( html ).toContain( '1.1 GB · 4,812 files' );
		expect( html ).toContain( '212 MB · 64 tables' );
		expect( html ).toContain( '286 MB · 31 plugins' );
		expect( html ).toContain( '96 MB · 4 themes' );
		// The must-use count is of the plugins WordPress loads, not of every file.
		expect( html ).toContain(
			'Must-use plugins <span class="lw-choice__size">2 MB · 2 plugins</span>'
		);
		expect( html ).toContain( '−38 MB' );
		expect( html ).toContain( 'All 31, the inactive ones too.' );
	} );

	it( 'shows the spam and revision counts, never a byte saving', () => {
		const html = choices( estimate() );
		expect( html ).toContain( '412 spam comments.' );
		expect( html ).toContain(
			'3,270 earlier versions of posts and pages.'
		);
		expect( html ).not.toMatch( /Leave out spam comments <span/ );
		expect( html ).not.toMatch( /Leave out post revisions <span/ );
	} );

	it( 'shows no spam or revision count while the tables are still being counted', () => {
		const counting = withoutFiles( {
			complete: false,
			measuredAt: null,
			parts: {
				...withoutFiles().parts,
				database: {
					bytes: 212 * 1024 * 1024,
					tables: 64,
					spamComments: null,
					revisions: null,
				},
			},
		} );
		const html = choices( counting );
		expect( html ).toContain( '212 MB · 64 tables' );
		expect( html ).toContain( 'Comments marked as spam.' );
		expect( html ).toContain( 'Earlier versions of posts and pages.' );
		expect( html ).not.toMatch( /\d spam comments?\./ );
	} );

	it( 'says "counting…" while a part is measured and nothing without an estimate', () => {
		expect( choices( withoutFiles( { complete: false } ) ) ).toContain(
			'Media library <span class="lw-choice__size">counting…</span>'
		);
		const html = choices( null, { code: 'fetch_error' } );
		expect( html ).not.toContain( 'lw-choice__size' );
		expect( html ).toContain( 'Every plugin, the inactive ones too.' );
		expect( html ).toContain( 'Comments marked as spam.' );
	} );

	it( 'gives a first measuring file sizes as lower bounds, with no counts in the help', () => {
		for ( const error of [ null, { code: 'fetch_error' } ] ) {
			const html = choices( firstWalk(), error );
			expect( html ).toContain( 'at least 1.1 GB · 4,812 files' );
			expect( html ).toContain( 'at least 286 MB · 31 plugins' );
			expect( html ).toContain( '212 MB · 64 tables' );
			expect( html ).not.toContain( 'at least 212 MB' );
			expect( html ).toContain( 'Every plugin, the inactive ones too.' );
			expect( html ).toContain( '412 spam comments.' );
		}
	} );

	it( 'says which parts could not be counted', () => {
		const html = choices( withoutFiles( { partial: FILE_PARTS } ) );
		expect( html ).toContain(
			'Media library <span class="lw-choice__size">could not be counted</span>'
		);
		expect( html ).toContain( '212 MB · 64 tables' );
	} );

	it( 'disables the database clean-ups when the database is left out', () => {
		const html = choices( estimate(), null, form( { noDatabase: true } ) );
		expect( html.match( /disabled=""/g ) ).toHaveLength( 2 );
	} );

	it( 'puts the choice in words', () => {
		const part = estimate().parts.plugins;
		expect( pluginsHelp( 'active', part ) ).toBe(
			'The 24 active ones; the 7 inactive ones stay out.'
		);
		expect( pluginsHelp( 'none', part ) ).toBe( 'No plugin goes in.' );
		expect( themesHelp( 'active', null ) ).toBe(
			'Only the theme in use and its parent.'
		);
		expect( themesHelp( 'all', estimate().parts.themes ) ).toBe(
			'All 4, the unused ones too.'
		);
	} );
} );
