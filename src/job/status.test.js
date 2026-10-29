/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import { ELSEWHERE_SECONDS } from './phases';
import {
	announcement,
	kindOf,
	recheckDelay,
	sourceLine,
	statusLine,
	titleOf,
} from './status';

const WINDOW_MS = ELSEWHERE_SECONDS * 1000;

/**
 * Runs the re-check loop of useBusyElsewhere on a fake clock: each check
 * reads the clock, and a timer is armed while the window is open.
 *
 * @param {() => number} updatedAt Last save at the time of the check.
 * @param {number}       start     Clock at mount (seconds).
 * @param {number}       limit     Most checks to run.
 * @return {number[]} Clock (seconds) of every check.
 */
function runChecks( updatedAt, start, limit = 20 ) {
	const checks = [];
	let clock = start;
	for ( let i = 0; i < limit; i++ ) {
		checks.push( clock );
		const delay = recheckDelay( updatedAt( clock ), clock );
		if ( null === delay ) {
			break;
		}
		clock += delay / 1000;
	}
	return checks;
}

describe( 'recheckDelay', () => {
	it( 'waits out the rest of the window after a recent save', () => {
		expect( recheckDelay( 1000, 1000 ) ).toBe( WINDOW_MS + 250 );
		expect( recheckDelay( 1000, 1015 ) ).toBe( 5000 + 250 );
	} );

	it( 'is null once the window is over', () => {
		expect( recheckDelay( 1000, 1000 + ELSEWHERE_SECONDS ) ).toBeNull();
		expect( recheckDelay( 0, 1000 ) ).toBeNull();
	} );

	it( 'ends with a quiet check once the saves stop', () => {
		// Saved every 5 s until 1030, then nothing (paused or cancelled).
		const saved = ( clock ) => Math.min( 1030, clock - ( clock % 5 ) );
		const checks = runChecks( saved, 1000 );
		const last = checks[ checks.length - 1 ];
		expect( recheckDelay( saved( last ), last ) ).toBeNull();
		expect( last ).toBeGreaterThanOrEqual( 1030 + ELSEWHERE_SECONDS );
	} );

	it( 'keeps re-checking while a server clock ahead keeps the window full', () => {
		// Saved at 1100 by a server clock 100 s ahead of the browser.
		const checks = runChecks( () => 1100, 1000 );
		expect( checks.length ).toBeGreaterThan( 1 );
		const last = checks[ checks.length - 1 ];
		expect( last ).toBeGreaterThanOrEqual( 1100 + ELSEWHERE_SECONDS );
		expect( recheckDelay( 1100, last ) ).toBeNull();
	} );
} );

describe( 'job texts', () => {
	const job = {
		type: 'export',
		status: 'running',
		step: 'content',
		message: 'Adding wp-content/uploads/2026/photo.jpg',
	};

	it( 'announces the phase, not the tick message', () => {
		expect( statusLine( job, null ) ).toBe( job.message );
		expect( announcement( job, null, titleOf( job ) ) ).toBe(
			'Now: Files'
		);
		expect(
			announcement(
				{ ...job, message: 'Adding another file' },
				null,
				titleOf( job )
			)
		).toBe( 'Now: Files' );
	} );

	it( 'announces an upload, a question and the end', () => {
		expect( announcement( job, 40, '' ) ).toBe( 'Uploading the archive…' );
		expect(
			announcement(
				{ ...job, status: 'needs_input', input: { kind: 'password' } },
				null,
				''
			)
		).toBe( 'The archive needs its password.' );
		const done = { ...job, status: 'done' };
		expect( titleOf( done ) ).toBe( 'Backup ready' );
		expect( announcement( done, null, titleOf( done ) ) ).toBe(
			'Backup ready'
		);
	} );
} );

describe( 'reset texts', () => {
	const reset = {
		type: 'reset',
		status: 'running',
		step: 'reset_plugins',
		steps: [ 'reset_backup', 'reset_plugins' ],
	};

	it( 'names a reset in every state', () => {
		expect( titleOf( reset ) ).toBe( 'Resetting' );
		expect( titleOf( { ...reset, status: 'done' } ) ).toBe(
			'Reset finished'
		);
		expect( titleOf( { ...reset, status: 'failed' } ) ).toBe(
			'The reset stopped'
		);
		expect( titleOf( { ...reset, status: 'cancelled' } ) ).toBe(
			'Reset cancelled'
		);
	} );

	it( 'announces the reset phases the job has', () => {
		expect( announcement( reset, null, '' ) ).toBe( 'Now: Plugins' );
	} );
} );

describe( 'move texts', () => {
	const move = { type: 'move', status: 'running', step: 'move' };

	it( 'names a move in every state', () => {
		expect( titleOf( move ) ).toBe( 'Moving the backups' );
		expect( titleOf( { ...move, status: 'done' } ) ).toBe(
			'Backups moved'
		);
		expect( titleOf( { ...move, status: 'failed' } ) ).toBe(
			'Moving the backups stopped'
		);
		expect( titleOf( { ...move, status: 'cancelled' } ) ).toBe(
			'Moving the backups was cancelled'
		);
	} );

	it( 'announces the move phases', () => {
		expect( announcement( move, null, '' ) ).toBe( 'Now: Moving' );
		expect( announcement( { ...move, step: 'finish' }, null, '' ) ).toBe(
			'Now: Finishing'
		);
	} );
} );

describe( 'push and pull texts', () => {
	const push = {
		type: 'push',
		status: 'running',
		step: 'link_upload',
		link: { id: 'aaaaaaaaaaaaaaaa', name: 'Site B', address: 'b' },
	};

	it( 'names a push in every state', () => {
		expect( titleOf( push ) ).toBe( 'Sending this site' );
		expect( titleOf( { ...push, status: 'needs_input' } ) ).toBe(
			'Sending this site'
		);
		expect( titleOf( { ...push, status: 'done' } ) ).toBe( 'Site sent' );
		expect( titleOf( { ...push, status: 'failed' } ) ).toBe(
			'Sending stopped'
		);
		expect( titleOf( { ...push, status: 'cancelled' } ) ).toBe(
			'Sending was cancelled'
		);
	} );

	it( 'announces the push phases', () => {
		expect( announcement( push, null, '' ) ).toBe( 'Now: Sending' );
		expect(
			announcement( { ...push, step: 'link_import' }, null, '' )
		).toBe( 'Now: Restoring there' );
	} );

	it( 'names the site a pull copies', () => {
		expect( sourceLine( { kind: 'link', name: 'Site B' } ) ).toBe(
			'From the connected site Site B'
		);
	} );
} );

describe( 'cloud jobs', () => {
	const upload = {
		type: 'cloud',
		status: 'running',
		result: { action: 'upload', backup: null, cloud: [] },
	};
	const fetch = { ...upload, result: { ...upload.result, action: 'fetch' } };

	it( 'names an upload and a download in their own words', () => {
		expect( titleOf( upload ) ).toBe( 'Uploading the backup' );
		expect( titleOf( { ...upload, status: 'done' } ) ).toBe(
			'Upload finished'
		);
		expect( titleOf( fetch ) ).toBe( 'Downloading the backup' );
		expect( titleOf( { ...fetch, status: 'failed' } ) ).toBe(
			'The download stopped'
		);
		expect( titleOf( { ...fetch, status: 'cancelled' } ) ).toBe(
			'Download cancelled'
		);
	} );

	it( 'names the destination a restore downloads from', () => {
		expect(
			sourceLine( {
				kind: 'cloud',
				name: 'a.wpress',
				destination: 'Offsite',
			} )
		).toBe( 'From the backup a.wpress at Offsite' );
		// The destination was deleted since.
		expect(
			sourceLine( { kind: 'cloud', name: 'a.wpress', destination: '' } )
		).toBe( 'From the cloud backup a.wpress' );
	} );
} );

describe( 'kindOf', () => {
	it( 'names the kind of job, not the section it belongs to', () => {
		expect( kindOf( { type: 'export' } ) ).toBe( 'Backup' );
		expect( kindOf( { type: 'import' } ) ).toBe( 'Restore' );
		expect( kindOf( { type: 'import', link: { id: 'l1' } } ) ).toBe(
			'Restore'
		);
		expect( kindOf( { type: 'push' } ) ).toBe( 'Sending' );
		expect( kindOf( { type: 'reset' } ) ).toBe( 'Reset' );
		// A folder move is not "Settings".
		expect( kindOf( { type: 'move' } ) ).toBe( 'Backups folder' );
	} );

	it( 'tells a cloud upload from a download', () => {
		expect(
			kindOf( { type: 'cloud', result: { action: 'upload' } } )
		).toBe( 'Upload' );
		expect( kindOf( { type: 'cloud', result: { action: 'fetch' } } ) ).toBe(
			'Download'
		);
		expect( kindOf( { type: 'cloud' } ) ).toBe( 'Upload' );
	} );
} );
