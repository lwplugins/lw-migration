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
import JobFollower, { followStalled } from './JobFollower';

// The real components need the browser; plain stand-ins keep what they show.
vi.mock( '@wordpress/components', async () => ( {
	...( await import( '../export/fixtures/components' ) ),
	Button: ( { children, disabled, variant } ) =>
		createElement(
			'button',
			{
				className: variant,
				'aria-disabled': disabled ? 'true' : undefined,
			},
			children
		),
} ) );

vi.mock( '@wordpress/date', () => ( {
	getSettings: () => ( { timezone: { string: 'UTC', offset: 0 } } ),
} ) );

vi.mock( '../data/api', () => ( { api: {} } ) );

const NOW = Math.floor( Date.now() / 1000 );
const STEPS = [ 'link_export', 'link_download', 'validate', 'content' ];

const pulled = ( extra = {} ) => ( {
	id: 'job1',
	type: 'import',
	status: 'running',
	step: 'link_download',
	steps: STEPS,
	stepIndex: 1,
	progress: 30,
	message: 'Downloading the archive',
	warnings: [],
	warningCount: 0,
	createdAt: NOW - 300,
	updatedAt: NOW - 2,
	link: { id: 'l1', name: 'Shop', address: 'shop.example' },
	source: { kind: 'link', name: 'Shop' },
	...extra,
} );

const html = ( job ) =>
	renderToStaticMarkup(
		createElement( JobFollower, {
			job,
			onChange: () => {},
			onTakeOver: () => {},
			onClose: () => {},
		} )
	);

describe( 'followStalled', () => {
	const moving = {
		finished: false,
		stopped: false,
		quiet: false,
		confirming: false,
	};

	it( 'stands still once reading stopped or the job went quiet', () => {
		expect( followStalled( moving ) ).toBe( false );
		expect( followStalled( { ...moving, stopped: true } ) ).toBe( true );
		expect( followStalled( { ...moving, quiet: true } ) ).toBe( true );
	} );

	it( "keeps moving while the other site's admin is asked to confirm", () => {
		expect(
			followStalled( { ...moving, quiet: true, confirming: true } )
		).toBe( false );
		expect(
			followStalled( { ...moving, stopped: true, confirming: true } )
		).toBe( true );
	} );

	it( 'never stands still once the job ended', () => {
		expect(
			followStalled( { ...moving, finished: true, stopped: true } )
		).toBe( false );
	} );
} );

describe( 'JobFollower', () => {
	it( 'moves the track while the other site saves the job', () => {
		const markup = html( pulled() );
		expect( markup ).toContain( 'class="lw-track is-running"' );
		expect( markup ).toContain( '<span class="lw-track__pill">now</span>' );
	} );

	it( 'stops the flow and says "stopped" once the job went quiet', () => {
		const markup = html( pulled( { updatedAt: NOW - 600 } ) );
		expect( markup ).toContain( 'class="lw-track is-running is-stalled"' );
		expect( markup ).toContain(
			'<span class="lw-track__pill">stopped</span>'
		);
	} );

	it( "keeps it moving while the other site's admin is asked to confirm", () => {
		const markup = html(
			pulled( {
				status: 'needs_input',
				step: 'validate',
				stepIndex: 2,
				updatedAt: NOW - 600,
				input: { kind: 'confirm' },
			} )
		);
		expect( markup ).not.toContain( 'is-stalled' );
		expect( markup ).toContain( '<span class="lw-track__pill">now</span>' );
	} );
} );

describe( 'the followed job card', () => {
	it( 'has the kind, title, clock, track and a footer with the note and Cancel', () => {
		const markup = html( pulled() );
		const order = [
			'<p class="lw-job__kind">Restore</p>',
			'<h2 id="lw-job-title" class="lw-job__title">Restoring</h2>',
			'<p class="lw-job__clock">Started ',
			'role="progressbar"',
			'<p class="lw-job__message">Downloading the archive</p>',
			'<footer class="lw-job__foot">',
			'Driven by Shop. This screen only follows it; you can cancel it here.',
			'<button class="secondary">Cancel</button>',
		].map( ( needle ) => markup.indexOf( needle ) );
		expect( order.every( ( at ) => at >= 0 ) ).toBe( true );
		expect( [ ...order ].sort( ( a, b ) => a - b ) ).toEqual( order );
		expect( markup ).toContain( 'class="lw-job__elapsed">5 min 0 s<' );
		// Still saved moments ago: not offered to continue here.
		expect( markup ).not.toContain( 'Continue here' );
		// The confirmation is inline and closed; no dialog, no callout.
		expect( markup ).not.toContain( 'lw-job__confirm' );
		expect( markup ).not.toContain( 'lw-admin-callout' );
	} );

	it( 'offers to continue here once the job went quiet, with the clock standing', () => {
		const markup = html( pulled( { updatedAt: NOW - 60 } ) );
		expect( markup ).toContain(
			'<button class="secondary">Cancel</button><button class="primary">Continue here</button>'
		);
		// 300 - 60 s: the clock stands at the last save.
		expect( markup ).toContain( 'class="lw-job__elapsed">4 min 0 s<' );
	} );

	it( "does not offer it while the other site's admin is asked to confirm", () => {
		const markup = html(
			pulled( {
				status: 'needs_input',
				updatedAt: NOW - 600,
				input: { kind: 'confirm' },
			} )
		);
		expect( markup ).not.toContain( 'Continue here' );
	} );

	it( 'shows the result and no footer once the job ended', () => {
		const markup = html( pulled( { status: 'cancelled' } ) );
		expect( markup ).toContain( 'Restore cancelled' );
		expect( markup ).not.toContain( 'lw-job__foot' );
		expect( markup ).not.toContain( 'lw-job__clock' );
	} );
} );
