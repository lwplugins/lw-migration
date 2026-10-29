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
import JobTrack, { trackPhases } from './JobTrack';

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
const IMPORT_STEPS = [ 'upload', 'validate', 'content', 'swap' ];
const RESET_STEPS = [
	'reset_backup',
	'reset_plugins',
	'reset_themes',
	'reset_media',
	'reset_db_build',
	'reset_db_swap',
	'reset_db_finish',
];

const exportAt = ( step, extra = {} ) => ( {
	type: 'export',
	status: 'running',
	steps: EXPORT_STEPS,
	step,
	stepIndex: EXPORT_STEPS.indexOf( step ),
	progress: 0,
	...extra,
} );

const states = ( job ) => trackPhases( job ).map( ( phase ) => phase.state );

const html = ( props ) =>
	renderToStaticMarkup( createElement( JobTrack, props ) );

describe( 'trackPhases', () => {
	it( 'marks the phases before the current one done, the rest next', () => {
		expect( states( exportAt( 'init' ) ) ).toEqual( [
			'current',
			'next',
			'next',
			'next',
		] );
		expect( states( exportAt( 'content' ) ) ).toEqual( [
			'done',
			'current',
			'next',
			'next',
		] );
		expect( states( exportAt( 'store' ) ) ).toEqual( [
			'done',
			'done',
			'done',
			'current',
		] );
	} );

	it( 'marks every phase done once the job is done, none current when it failed', () => {
		expect( states( exportAt( 'clean', { status: 'done' } ) ) ).toEqual( [
			'done',
			'done',
			'done',
			'done',
		] );
		expect(
			states( exportAt( 'database', { status: 'failed' } ) )
		).toEqual( [ 'done', 'done', 'next', 'next' ] );
	} );

	it( 'keeps a move (two phases) and a full reset (five phases)', () => {
		expect(
			states( {
				type: 'move',
				status: 'running',
				steps: [ 'move', 'finish' ],
				step: 'finish',
			} )
		).toEqual( [ 'done', 'current' ] );
		const reset = trackPhases( {
			type: 'reset',
			status: 'running',
			steps: RESET_STEPS,
			step: 'reset_media',
		} );
		expect( reset.map( ( phase ) => phase.label ) ).toEqual( [
			'Backup',
			'Plugins',
			'Themes',
			'Media',
			'Database',
		] );
		expect( reset.map( ( phase ) => phase.state ) ).toEqual( [
			'done',
			'done',
			'done',
			'current',
			'next',
		] );
	} );
} );

describe( 'JobTrack', () => {
	it( 'draws one progressbar with the percent, the message and the power line', () => {
		const markup = html( {
			job: exportAt( 'content', { progress: 50 } ),
			message: 'Listing files: 26939 so far',
		} );
		expect( markup.match( /role="progressbar"/g ) ).toHaveLength( 1 );
		expect( markup ).toContain(
			'<ol class="lw-track__phases has-current">'
		);
		// 3 of 9 steps done plus half of the fourth: 38 %.
		expect( markup ).toContain( 'aria-valuenow="38"' );
		expect( markup ).toContain(
			'<span class="lw-track__percent">38</span>'
		);
		expect( markup ).toContain(
			'<p class="lw-job__message">Listing files: 26939 so far</p>'
		);
		// The current phase: the flow, the spark and the "now" pill.
		expect( markup ).toMatch(
			/<li class="is-current" aria-current="step"><span class="lw-track__seg" aria-hidden="true"><span class="lw-track__flow"><\/span><span class="lw-track__spark">/
		);
		expect( markup ).toContain(
			'<span class="lw-track__name">Files</span><span class="lw-track__pill">now</span>'
		);
		// A done phase: its bolt and a screen-reader "(done)".
		expect( markup ).toMatch(
			/<li class="is-done"><span class="lw-track__seg" aria-hidden="true"><span class="lw-track__flow"><\/span><\/span><span class="lw-track__label"><svg [^>]*aria-hidden="true"[^>]*class="lw-track__bolt"/
		);
		expect( markup ).toContain(
			'Preparing</span><span class="screen-reader-text"> (done)</span>'
		);
		expect( markup.match( /lw-track__spark/g ) ).toHaveLength( 1 );
		expect( markup.match( /aria-current/g ) ).toHaveLength( 1 );
		expect( markup ).not.toContain( 'is-stalled' );
	} );

	it( 'stops the flow and says "stopped" while the job stands still', () => {
		const markup = html( {
			job: exportAt( 'content' ),
			stalled: true,
		} );
		expect( markup ).toContain( 'class="lw-track is-running is-stalled"' );
		expect( markup ).toContain(
			'<span class="lw-track__pill">stopped</span>'
		);
		expect( markup ).not.toContain( '>now<' );
	} );

	it( 'follows the upload while a file is sent, in the receiving phase', () => {
		const markup = html( {
			job: {
				type: 'import',
				status: 'needs_input',
				steps: IMPORT_STEPS,
				step: 'upload',
				stepIndex: 0,
				progress: 0,
				input: { kind: 'upload' },
			},
			uploaded: 42,
			message: 'Uploading the archive…',
		} );
		expect( markup ).toContain( 'aria-valuenow="42"' );
		expect( markup ).toContain(
			'<span class="lw-track__percent">42</span>'
		);
		expect( markup ).toContain(
			'aria-current="step"><span class="lw-track__seg" aria-hidden="true"><span class="lw-track__flow"></span><span class="lw-track__spark">'
		);
		expect( markup ).toMatch(
			/aria-current="step">.*?<span class="lw-track__name">Receiving<\/span>/
		);
	} );

	it( 'shows no message line without one, and no pill once done', () => {
		const markup = html( {
			job: exportAt( 'clean', { status: 'done' } ),
		} );
		expect( markup ).toContain( 'aria-valuenow="100"' );
		// No current phase: no room kept for its line on a phone.
		expect( markup ).toContain( '<ol class="lw-track__phases">' );
		expect( markup ).not.toContain( 'lw-job__message' );
		expect( markup ).not.toContain( 'lw-track__pill' );
		expect( markup ).not.toContain( 'lw-track__spark' );
	} );
} );
