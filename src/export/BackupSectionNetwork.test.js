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
import ExportView from '../views/ExportView';
import CompositionBar from './CompositionBar';
import { composition } from './estimate';

// The page runs in Network Admin.
vi.mock( '../data/boot', () => ( {
	NAMESPACE: 'lw-migration/v1',
	NETWORK: { subdomain: false, mainUrl: 'https://example.com', siteCount: 3 },
} ) );

vi.mock( '@wordpress/components', () => {
	const box =
		( tag ) =>
		( { children, className } ) =>
			createElement( tag, { className }, children );
	return {
		Button: box( 'button' ),
		Card: box( 'div' ),
		CardBody: box( 'div' ),
		CardHeader: box( 'div' ),
		CheckboxControl: box( 'label' ),
		FormToggle: () => createElement( 'input', { type: 'checkbox' } ),
		Notice: box( 'div' ),
		TextControl: ( { label } ) => createElement( 'label', {}, label ),
		TextareaControl: ( { label } ) => createElement( 'label', {}, label ),
		__experimentalHeading: box( 'h3' ),
		__experimentalHStack: box( 'div' ),
		__experimentalVStack: box( 'div' ),
		__experimentalText: box( 'p' ),
		__experimentalToggleGroupControl: box( 'div' ),
		__experimentalToggleGroupControlOption: ( { label } ) =>
			createElement( 'button', {}, label ),
	};
} );

vi.mock( '../data/api', () => ( {
	api: {
		destinations: () => new Promise( () => {} ),
		exportEstimate: () => new Promise( () => {} ),
		networkSites: () => new Promise( () => {} ),
		startJob: vi.fn(),
	},
} ) );

const estimate = {
	parts: {
		media: { bytes: 1000, files: 1 },
		database: { bytes: 1000, tables: 9, spamComments: 0, revisions: 0 },
		plugins: { bytes: 10, count: 1, activeBytes: 10, activeCount: 1 },
		themes: { bytes: 10, count: 1, activeBytes: 10, activeCount: 1 },
		muPlugins: { bytes: 0, count: 0 },
		cache: { bytes: 0 },
		other: { bytes: 10 },
	},
	complete: true,
	partial: [],
	scope: 'network',
};

describe( 'the Backup section on a network', () => {
	it( 'keeps the site scope picker at the top, before the sizes', () => {
		const html = renderToStaticMarkup(
			createElement( ExportView, { busy: false, onStart: () => {} } )
		);
		expect( html ).toContain( 'Back up this network' );
		const scope = html.indexOf( 'Which sites' );
		expect( scope ).toBeGreaterThan( html.indexOf( 'lw-export__lead' ) );
		expect( scope ).toBeLessThan( html.indexOf( 'lw-composition' ) );
		expect( html ).toContain( 'This network has 3 sites.' );
	} );

	it( 'says the sizes are the whole network’s when only chosen sites go in', () => {
		const html = renderToStaticMarkup(
			createElement( CompositionBar, {
				status: 'done',
				estimate,
				comp: composition( estimate, DEFAULTS ),
				compression: 'none',
				byName: false,
				chosen: true,
			} )
		);
		expect( html ).toContain(
			'The sizes are for the whole network, not only the chosen sites.'
		);
	} );
} );
