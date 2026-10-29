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
import StepRail from './StepRail';

const html = ( current ) =>
	renderToStaticMarkup( createElement( StepRail, { current } ) );
const filled = ( markup ) =>
	( markup.match( /lw-steps__segment is-filled/g ) || [] ).length;

describe( 'the steps bar', () => {
	it( 'names the first step, its place and fills one segment', () => {
		const markup = html( 0 );

		expect( markup ).toContain(
			'<strong class="lw-steps__name" aria-current="step">Source</strong>'
		);
		expect( markup ).toContain( 'Step 1 of 3' );
		expect( filled( markup ) ).toBe( 1 );
		expect( markup ).toContain( 'role="group" aria-label="Restore steps"' );
		expect( markup ).toContain( 'lw-steps__bar" aria-hidden="true"' );
	} );

	it( 'fills up to the current step', () => {
		const markup = html( 1 );

		expect( markup ).toContain( '>Check</strong>' );
		expect( markup ).toContain( 'Step 2 of 3' );
		expect( filled( markup ) ).toBe( 2 );
	} );

	it( 'says Done with every segment filled once all steps are done', () => {
		const markup = html( 3 );

		expect( markup ).toContain( '>Restore</strong>' );
		expect( markup ).toContain( '>Done<' );
		expect( markup ).not.toContain( 'Step ' );
		expect( markup ).not.toContain( 'aria-current' );
		expect( filled( markup ) ).toBe( 3 );
	} );
} );
