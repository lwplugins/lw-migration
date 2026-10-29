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
import CompositionBar from './CompositionBar';
import { codePatch } from './choices';
import { composition, estimateStatus } from './estimate';
import {
	FILE_PARTS,
	estimate,
	firstWalk,
	withoutFiles,
} from './fixtures/estimate';

// The real components need the browser; plain stand-ins keep what they show.
vi.mock( '@wordpress/components', () => import( './fixtures/components' ) );

const form = ( patch = {} ) => ( { ...DEFAULTS, ...patch } );

const render = ( Component, props ) =>
	renderToStaticMarkup( createElement( Component, props ) );

const bar = ( given, error = null, options = form(), extra = {} ) =>
	render( CompositionBar, {
		status: estimateStatus( given, error ),
		estimate: given,
		comp: composition( given, options ),
		compression: options.compression,
		byName: false,
		chosen: false,
		...extra,
	} );

describe( 'CompositionBar', () => {
	it( 'shows the total, the five parts and "approximate" when done', () => {
		const html = bar( estimate() );
		expect( html ).toContain( '1.7 GB' );
		expect( html ).toContain( 'goes into the backup, without compression' );
		expect( html ).toContain( 'Approximate' );
		expect( html ).toContain( 'Media library 1.1 GB' );
		expect( html ).toContain( 'Other (must-use, wp-content) 94 MB' );
		expect( html ).not.toContain( 'whole network' );
	} );

	it( 'follows the choices: left-out parts are struck and slivered', () => {
		const html = bar(
			estimate(),
			null,
			form( { noMedia: true, ...codePatch( 'plugins', 'active' ) } )
		);
		expect( html ).toContain( 'Media library left out' );
		expect( html ).toContain( 'lw-seg lw-seg--media is-off' );
		expect( html ).toContain( 'Plugins 198 MB' );
	} );

	it( 'says "at least" and "still counting" with the figures so far', () => {
		const html = bar( withoutFiles( { complete: false } ) );
		expect( html ).toContain( 'at least 212 MB' );
		expect( html ).toContain( 'Still counting…' );
		expect( html ).toContain( 'Media library counting…' );
		expect( html ).toContain( 'aria-busy="true"' );
	} );

	it( 'gives a first measuring growing file sizes as lower bounds', () => {
		const html = bar( firstWalk() );
		expect( html ).toContain( 'at least 1.7 GB' );
		expect( html ).toContain( 'Still counting…' );
		expect( html ).toContain( 'Media library at least 1.1 GB' );
		expect( html ).toContain( 'Plugins at least 286 MB' );
		// The database is sized in one go: its figure is final.
		expect( html ).toContain( 'Database 212 MB' );
	} );

	it( 'keeps them lower bounds when counting stopped', () => {
		const html = bar( firstWalk(), { code: 'fetch_error' } );
		expect( html ).toContain( 'Counting stopped' );
		expect( html ).toContain( 'at least 1.7 GB' );
		expect( html ).toContain( 'Media library at least 1.1 GB' );
	} );

	it( 'says a part was not counted when counting stopped before it', () => {
		const html = bar( withoutFiles( { complete: false } ), {
			code: 'fetch_error',
		} );
		expect( html ).toContain( 'Media library could not be counted' );
	} );

	it( 'shows a skeleton while nothing is counted yet', () => {
		const nothing = withoutFiles( { complete: false } );
		nothing.parts.database = null;
		const html = bar( nothing );
		expect( html ).toContain( 'role="status"' );
		expect( html ).toContain( 'Measuring the backup…' );
	} );

	it( 'names the parts that could not be counted', () => {
		const html = bar( withoutFiles( { partial: FILE_PARTS } ) );
		expect( html ).toContain( 'Media library could not be counted' );
		expect( html ).toContain(
			'Approximate; some parts could not be counted.'
		);
	} );

	it( 'works without sizes when the estimate fails', () => {
		const html = bar( null, { code: 'fetch_error' } );
		expect( html ).toContain(
			'The sizes could not be measured now. The backup can still be started.'
		);
		expect( html ).not.toContain( 'lw-composition__bar' );
	} );

	it( 'keeps the figures so far when a later request fails', () => {
		const html = bar( withoutFiles( { complete: false } ), {
			code: 'fetch_error',
		} );
		expect( html ).toContain( 'Counting stopped' );
		expect( html ).toContain( 'at least 212 MB' );
	} );

	it( 'says the compressed file is smaller without inventing its size', () => {
		const html = bar( estimate(), null, form( { compression: 'gzip' } ) );
		expect( html ).toContain(
			'goes into the backup before compression; the gzip file will be smaller'
		);
	} );

	it( 'says the figures are the whole network’s on a network', () => {
		const html = bar( estimate( { scope: 'network' } ) );
		expect( html ).toContain( 'The sizes are for the whole network.' );
	} );

	it( 'says the exclusions by name are not taken off', () => {
		const html = bar( estimate(), null, form(), { byName: true } );
		expect( html ).toContain( 'is not taken off' );
	} );
} );
