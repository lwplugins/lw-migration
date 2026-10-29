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
import CancelConfirm from './CancelConfirm';
import { FooterNote } from './JobFooter';
import JobScreen from './JobScreen';

// The real components need the browser; plain stand-ins keep what they show.
vi.mock( '@wordpress/components', async () => ( {
	...( await import( '../export/fixtures/components' ) ),
	__experimentalConfirmDialog: () => null,
	Button: ( { children, disabled, variant, ...rest } ) =>
		createElement(
			'button',
			{
				className: variant,
				'aria-disabled': disabled ? 'true' : undefined,
				'aria-describedby': rest[ 'aria-describedby' ],
			},
			children
		),
} ) );

vi.mock( '@wordpress/date', () => ( {
	getSettings: () => ( { timezone: { string: 'UTC', offset: 0 } } ),
} ) );

vi.mock( '../data/api', () => ( {
	api: {},
	errorMessage: () => '',
} ) );

const NOW = Math.floor( Date.now() / 1000 );
const EXPORT_STEPS = [
	'init',
	'config',
	'enumerate',
	'content',
	'database',
	'database_file',
	'finalize',
	'store',
	'clean',
];
const IMPORT_STEPS = [
	'source',
	'validate',
	'safety_backup',
	'database',
	'swap',
];

const backup = ( extra = {} ) => ( {
	id: 'job1',
	type: 'export',
	status: 'running',
	step: 'content',
	steps: EXPORT_STEPS,
	stepIndex: 3,
	progress: 0,
	message: 'Listing files: 26939 so far',
	warnings: [],
	warningCount: 0,
	createdAt: NOW - 134,
	updatedAt: NOW - 2,
	pastNoReturn: false,
	cancelDeferred: false,
	...extra,
} );

const screen = ( job, resumed = false ) =>
	renderToStaticMarkup(
		createElement( JobScreen, {
			job,
			file: null,
			resumed,
			onChange: () => {},
			onClose: () => {},
			onBack: () => {},
		} )
	).replaceAll( '&#x27;', "'" );

describe( 'the running job card', () => {
	it( 'shows the kind, title, clock, figures, power line and the footer', () => {
		const markup = screen( backup() );
		const order = [
			'<p class="lw-job__kind">Backup</p>',
			'<h2 id="lw-job-title" class="lw-job__title">Backing up</h2>',
			'<p class="lw-job__clock">Started ',
			'role="progressbar"',
			'<p class="lw-job__message">Listing files: 26939 so far</p>',
			'<ol class="lw-track__phases has-current">',
			'<footer class="lw-job__foot">',
		].map( ( needle ) => markup.indexOf( needle ) );
		expect( order.every( ( at ) => at >= 0 ) ).toBe( true );
		expect( [ ...order ].sort( ( a, b ) => a - b ) ).toEqual( order );
		expect( markup ).toContain( 'class="lw-job__elapsed">2 min 14 s<' );
		expect( markup ).toContain( '<strong>Keep this tab open.</strong>' );
		expect( markup ).toContain(
			'<button class="secondary">Cancel</button>'
		);
		expect( markup ).not.toContain( 'Continue' );
		expect( markup ).not.toContain( 'lw-job__stalled' );
		expect( markup ).toContain( '<span class="lw-track__pill">now</span>' );
		// The confirmation is inline and closed; no dialog.
		expect( markup ).not.toContain( 'lw-job__confirm' );
		expect( markup ).not.toContain( 'components-modal' );
		// The live region is still there.
		expect( markup ).toContain(
			'<p class="screen-reader-text" aria-live="polite">Now: Files</p>'
		);
	} );

	it( 'shows a stopped job with the strip, its last progress, Continue and Cancel', () => {
		const markup = screen(
			backup( { createdAt: NOW - 900, updatedAt: NOW - 600 } ),
			true
		);
		expect( markup ).toContain( 'class="lw-job is-running is-stalled"' );
		expect( markup ).toContain(
			'This job stopped when its page was closed or the connection broke. Continue it, or cancel it.'
		);
		expect( markup ).toContain( 'Last progress at <strong>' );
		expect( markup ).not.toContain( 'Keep this tab open.' );
		expect( markup ).toContain(
			'<span class="lw-track__pill">stopped</span>'
		);
		expect( markup ).toContain( 'class="lw-track is-running is-stalled"' );
		// The clock stands at the last progress, 5 minutes after the start.
		expect( markup ).toContain( 'class="lw-job__elapsed">5 min 0 s<' );
		const cancel = markup.indexOf( '<button class="secondary">Cancel' );
		const resume = markup.indexOf( '<button class="primary">Continue' );
		expect( cancel ).toBeGreaterThan( 0 );
		expect( resume ).toBeGreaterThan( cancel );
	} );

	it( 'says a stopped job saved moments ago may still run elsewhere', () => {
		const markup = screen( backup( { updatedAt: NOW - 1 } ), true );
		expect( markup ).toContain(
			'This job was saved moments ago, so it may still be running in another window or tab.'
		);
		expect( markup ).toContain( 'Last progress at <strong>' );
	} );

	it( 'turns Cancel off past the point of no return and says why', () => {
		const markup = screen(
			{
				...backup(),
				type: 'import',
				step: 'database',
				steps: IMPORT_STEPS,
				stepIndex: 3,
				message: 'Restoring the database',
				pastNoReturn: true,
				source: { kind: 'backup', name: 'site.wpress' },
			},
			true
		);
		// The Restore section keeps its lead and rail above the card.
		expect( markup.indexOf( 'lw-restore__lead' ) ).toBeLessThan(
			markup.indexOf( 'lw-job__kind' )
		);
		expect( markup ).toContain(
			'<button class="secondary" aria-disabled="true" aria-describedby="lw-job-note">Cancel</button>'
		);
		expect( markup ).toMatch(
			/<p class="lw-job__note" id="lw-job-note"><svg[^]*?<strong>It can no longer be cancelled at this point.<\/strong> The database is already replaced; the last steps are finishing. Keep this page open until it is done./
		);
		expect( markup ).not.toContain( 'Keep this tab open.' );
		expect( markup ).not.toContain( 'is-stalled' );
	} );

	it( 'keeps the asked-for callout and leaves the reason out of the note once a cancel was deferred', () => {
		const markup = screen(
			backup( {
				type: 'reset',
				steps: [ 'reset_backup', 'reset_db_build', 'reset_db_swap' ],
				step: 'reset_db_swap',
				stepIndex: 2,
				pastNoReturn: true,
				cancelDeferred: true,
			} )
		);
		expect( markup ).toContain(
			'Cancelling was asked for after the database was replaced'
		);
		expect( markup ).toContain(
			'<strong>It can no longer be cancelled at this point.</strong></span>'
		);
	} );

	it( 'shows the result and no footer once the job ended', () => {
		const markup = screen(
			backup( { status: 'cancelled', message: 'Old message' } )
		);
		expect( markup ).toContain( 'Backup cancelled' );
		expect( markup ).not.toContain( 'lw-job__foot' );
		expect( markup ).not.toContain( 'lw-job__clock' );
		expect( markup ).not.toContain( 'Old message' );
	} );
} );

describe( 'CancelConfirm', () => {
	it( 'says what cancelling leaves, with the confirm button described by it', () => {
		const markup = renderToStaticMarkup(
			createElement( CancelConfirm, {
				text: 'Cancel this backup? The unfinished file is removed.',
				confirm: { onKeyDown: () => {}, close: () => {} },
				onConfirm: () => {},
			} )
		);
		expect( markup ).toBe(
			'<div class="lw-job__confirm"><p id="lw-job-confirm-text">Cancel this backup? The unfinished file is removed.</p><div class="lw-actions"><button class="primary" aria-describedby="lw-job-confirm-text">Cancel the job</button><button class="secondary">Keep going</button></div></div>'
		);
	} );
} );

describe( 'FooterNote', () => {
	const note = ( job, stopped ) =>
		renderToStaticMarkup(
			createElement( FooterNote, { job, past: '', stopped } )
		);

	it( 'never says "keep this tab open" for a job that stopped, also after a failure', () => {
		// The driver stopped on an error: stalled, but not paused.
		const markup = note( backup( { updatedAt: NOW - 60 } ), true );
		expect( markup ).toContain( 'Last progress at <strong>' );
		expect( markup ).not.toContain( 'Keep this tab open.' );
		expect( note( backup( { updatedAt: 0 } ), true ) ).toBe( '' );
	} );

	it( 'asks to keep the tab open while the job runs', () => {
		expect( note( backup(), false ) ).toContain(
			'<strong>Keep this tab open.</strong>'
		);
	} );
} );
