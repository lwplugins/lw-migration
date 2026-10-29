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
import JobScreen from '../job/JobScreen';
import { plain } from './fixtures/summaries';
import RestoreView from './RestoreView';
import { EMPTY_DRAFT } from './sources';

// The real components need the browser; plain stand-ins keep what they show.
vi.mock( '@wordpress/components', async () => ( {
	...( await import( '../export/fixtures/components' ) ),
	DropZone: () => null,
	FormFileUpload: ( { render } ) => render( { openFileDialog() {} } ),
	__experimentalConfirmDialog: () => null,
	// With its class and help: a refused field is marked.
	TextControl: ( { label, help, className } ) =>
		createElement( 'label', { className }, label, help ),
} ) );

// Nothing is fetched in a server render (effects do not run).
vi.mock( '../data/api', () => ( {
	api: {
		backups: () => new Promise( () => {} ),
		destinations: () => new Promise( () => {} ),
		startJob: vi.fn(),
	},
	errorMessage: () => '',
} ) );

const html = ( Component, props ) =>
	renderToStaticMarkup( createElement( Component, props ) ).replaceAll(
		'&#x27;',
		"'"
	);

const source = ( draft ) =>
	html( RestoreView, {
		busy: false,
		onStart: () => {},
		draft: { ...EMPTY_DRAFT, ...draft },
		onDraft: () => {},
	} );

const pressed = ( markup ) =>
	/<button[^>]*aria-pressed="true"[^>]*>.*?lw-source__title">([^<]+)/.exec(
		markup
	)[ 1 ];

describe( 'the source step', () => {
	it( 'draws the lead, the rail at Source and the four tiles', () => {
		const markup = source( {} );
		const order = [
			'lw-restore__lead',
			'lw-steps',
			'Where to restore from?',
			'From a backup',
			'From a file',
			'From an address',
			'From the cloud',
			'Continue to the check',
		].map( ( needle ) => markup.indexOf( needle ) );
		expect( order.every( ( at ) => at >= 0 ) ).toBe( true );
		expect( [ ...order ].sort( ( a, b ) => a - b ) ).toEqual( order );
		expect( markup ).toMatch(
			/lw-steps__name" aria-current="step">Source<\/strong><span class="lw-steps__count">Step 1 of 3/
		);
		expect( pressed( markup ) ).toBe( 'From a backup' );
		// The list is loading: a skeleton, never a spinner.
		expect( markup ).toContain( 'Loading backups…' );
		expect( markup ).not.toContain( 'components-spinner' );
		expect( markup ).toContain(
			'<button aria-disabled="true">Continue to the check'
		);
	} );

	it( 'keeps a typed address and lets the check start', () => {
		const markup = source( {
			source: 'url',
			url: 'https://example.com/site.wpress',
		} );
		expect( pressed( markup ) ).toBe( 'From an address' );
		expect( markup ).toContain( 'Address of the backup' );
		expect( markup ).toContain(
			'<strong>https://example.com/site.wpress</strong>'
		);
		expect( markup ).toContain( '<button>Continue to the check' );
	} );

	it( 'keeps a chosen file', () => {
		const markup = source( {
			source: 'upload',
			file: { name: 'site.wpress', size: 2048 },
		} );
		expect( pressed( markup ) ).toBe( 'From a file' );
		expect( markup ).toContain( 'site.wpress · 2 KB' );
		expect( markup ).toContain( 'it is sent in parts' );
		expect( markup ).toContain( '<button>Continue to the check' );
	} );
} );

const STEP_LIST = [ 'source', 'validate', 'decrypt', 'confirm', 'content' ];

const waiting = ( input ) => ( {
	id: 'job1',
	type: 'import',
	status: 'needs_input',
	step: input.kind === 'password' ? 'decrypt' : 'confirm',
	steps: STEP_LIST,
	stepIndex: input.kind === 'password' ? 2 : 3,
	progress: 63,
	// The header walk leaves its message behind while the job waits.
	message: "Reading the archive's contents...",
	source: { kind: 'backup', name: 'site.wpress' },
	input,
	warnings: [],
	warningCount: 0,
	updatedAt: 0,
} );

const screen = ( job ) =>
	html( JobScreen, {
		job,
		file: null,
		resumed: true,
		onChange: () => {},
		onClose: () => {},
		onBack: () => {},
	} );

describe( 'the check step on the job screen', () => {
	it( 'shows the check under the rail, without the old message or progress', () => {
		const markup = screen( waiting( { kind: 'confirm', summary: plain } ) );
		expect( markup ).toContain( 'lw-restore__lead' );
		expect( markup ).toMatch(
			/aria-current="step">Check<\/strong><span class="lw-steps__count">Step 2 of 3/
		);
		expect( markup ).toContain( 'Check before restoring' );
		expect( markup ).toContain( '>Back<' );
		expect( markup ).not.toContain( 'Reading the archive' );
		expect( markup ).not.toContain( 'lw-track' );
		expect( markup ).not.toContain( '>63<' );
	} );

	it( 'asks for the password there, and marks the field after a wrong one', () => {
		const markup = screen(
			waiting( { kind: 'password', reason: 'wrong' } )
		);
		expect( markup ).toContain( 'lw-steps' );
		expect( markup ).toContain( 'This archive is encrypted' );
		expect( markup ).toContain(
			'<label class="is-invalid">Archive password'
		);
		expect( markup ).toContain( 'That password did not open the archive.' );
		expect( markup ).not.toContain( 'lw-track' );
	} );
} );
