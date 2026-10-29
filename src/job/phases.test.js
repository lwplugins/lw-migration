/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import {
	ELSEWHERE_SECONDS,
	busyElsewhereFor,
	isFinished,
	percentOf,
	phaseIndex,
	phasesFor,
	tabOf,
} from './phases';

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

describe( 'phases', () => {
	it( 'knows which statuses end a job', () => {
		expect( isFinished( { status: 'done' } ) ).toBe( true );
		expect( isFinished( { status: 'failed' } ) ).toBe( true );
		expect( isFinished( { status: 'cancelled' } ) ).toBe( true );
		expect( isFinished( { status: 'needs_input' } ) ).toBe( false );
		expect( isFinished( undefined ) ).toBe( false );
	} );

	it( 'places the current step in its phase', () => {
		expect(
			phaseIndex( { type: 'export', status: 'running', step: 'content' } )
		).toBe( 1 );
		expect(
			phaseIndex( { type: 'import', status: 'running', step: 'swap' } )
		).toBe( phasesFor( 'import' ).length - 1 );
		expect(
			phaseIndex( { type: 'import', status: 'done', step: 'clean' } )
		).toBe( phasesFor( 'import' ).length );
		expect(
			phaseIndex( { type: 'export', status: 'pending', step: '' } )
		).toBe( 0 );
	} );

	it( 'adds the current step share to the finished ones, 99 at most until done', () => {
		const job = {
			type: 'export',
			status: 'running',
			steps: EXPORT_STEPS,
			stepIndex: 3,
			progress: 50,
		};
		expect( percentOf( job ) ).toBe( Math.floor( 3.5 * ( 100 / 9 ) ) );
		expect( percentOf( { ...job, stepIndex: 8, progress: 100 } ) ).toBe(
			99
		);
		expect( percentOf( { ...job, status: 'done' } ) ).toBe( 100 );
		expect( percentOf( { ...job, steps: [], stepIndex: 0 } ) ).toBe( 50 );
	} );
} );

describe( 'moves', () => {
	it( 'has a moving and a finishing phase', () => {
		expect( phasesFor( 'move' ).map( ( phase ) => phase.id ) ).toEqual( [
			'move',
			'finish',
		] );
		expect(
			phaseIndex( { type: 'move', status: 'running', step: 'move' } )
		).toBe( 0 );
		expect(
			phaseIndex( { type: 'move', status: 'running', step: 'finish' } )
		).toBe( 1 );
		expect(
			phaseIndex( { type: 'move', status: 'done', step: 'finish' } )
		).toBe( 2 );
	} );

	it( 'belongs to Settings; backups to Back up, the rest to Restore', () => {
		expect( tabOf( { type: 'move' } ) ).toBe( 'settings' );
		expect( tabOf( { type: 'export' } ) ).toBe( 'export' );
		expect( tabOf( { type: 'import' } ) ).toBe( 'import' );
		expect( tabOf( { type: 'reset' } ) ).toBe( 'reset' );
	} );
} );

describe( 'resets', () => {
	const FULL = [
		'reset_backup',
		'reset_plugins',
		'reset_themes',
		'reset_media',
		'reset_db_build',
		'reset_db_swap',
		'reset_db_finish',
	];

	it( 'shows only the phases whose steps the job has', () => {
		expect(
			phasesFor( 'reset', [ 'reset_backup', 'reset_plugins' ] ).map(
				( phase ) => phase.label
			)
		).toEqual( [ 'Backup', 'Plugins' ] );
		expect(
			phasesFor( 'reset', [ 'reset_media' ] ).map( ( phase ) => phase.id )
		).toEqual( [ 'media' ] );
		expect(
			phasesFor( 'reset', FULL ).map( ( phase ) => phase.id )
		).toEqual( [ 'backup', 'plugins', 'themes', 'media', 'database' ] );
	} );

	it( 'places the swap in the database phase of a full reset', () => {
		expect(
			phaseIndex( {
				type: 'reset',
				status: 'running',
				step: 'reset_db_swap',
				steps: FULL,
			} )
		).toBe( 4 );
		expect(
			phaseIndex( {
				type: 'reset',
				status: 'running',
				step: 'reset_db_build',
				steps: [ 'reset_db_build', 'reset_db_swap', 'reset_db_finish' ],
			} )
		).toBe( 0 );
		expect(
			phaseIndex( {
				type: 'reset',
				status: 'done',
				step: 'reset_plugins',
				steps: [ 'reset_backup', 'reset_plugins' ],
			} )
		).toBe( 2 );
	} );
} );

describe( 'busyElsewhereFor', () => {
	it( 'counts down from a recent save', () => {
		expect( busyElsewhereFor( { updatedAt: 1000 }, 1000 ) ).toBe(
			ELSEWHERE_SECONDS
		);
		expect( busyElsewhereFor( { updatedAt: 1000 }, 1005 ) ).toBe(
			ELSEWHERE_SECONDS - 5
		);
	} );

	it( 'is 0 once the job has been quiet long enough', () => {
		expect(
			busyElsewhereFor( { updatedAt: 1000 }, 1000 + ELSEWHERE_SECONDS )
		).toBe( 0 );
		expect( busyElsewhereFor( { updatedAt: 1000 }, 5000 ) ).toBe( 0 );
		expect( busyElsewhereFor( {}, 1000 ) ).toBe( 0 );
	} );

	it( 'never waits longer than the window for a clock ahead of the browser', () => {
		expect( busyElsewhereFor( { updatedAt: 2000 }, 1000 ) ).toBe(
			ELSEWHERE_SECONDS
		);
	} );
} );

describe( 'connections', () => {
	it( 'gives a push the steps of the export, the upload and the restore there', () => {
		expect( phasesFor( 'push' ) ).toEqual( [
			expect.objectContaining( {
				id: 'prepare',
				steps: [ 'init', 'config', 'enumerate' ],
			} ),
			expect.objectContaining( { id: 'files', steps: [ 'content' ] } ),
			expect.objectContaining( {
				id: 'database',
				steps: [ 'database', 'database_file', 'finalize' ],
			} ),
			expect.objectContaining( { id: 'send', steps: [ 'link_upload' ] } ),
			expect.objectContaining( {
				id: 'remote',
				steps: [ 'link_import', 'link_clean' ],
			} ),
		] );
		expect(
			phaseIndex( {
				type: 'push',
				status: 'running',
				step: 'link_import',
			} )
		).toBe( 4 );
		expect(
			phaseIndex( { type: 'push', status: 'done', step: 'link_clean' } )
		).toBe( 5 );
	} );

	it( 'receives a pull in the first phase of the restore', () => {
		for ( const step of [ 'link_export', 'link_download' ] ) {
			expect(
				phaseIndex( { type: 'import', status: 'running', step } )
			).toBe( 0 );
		}
	} );

	it( 'puts every job of a connection on Connections', () => {
		const mark = { id: 'aaaaaaaaaaaaaaaa', name: 'B', address: 'b' };
		expect( tabOf( { type: 'push', link: mark } ) ).toBe( 'connections' );
		expect(
			tabOf( {
				type: 'import',
				link: mark,
				source: { kind: 'link', name: 'B' },
			} )
		).toBe( 'connections' );
		// The other site's push and pull, run here.
		expect(
			tabOf( {
				type: 'import',
				link: mark,
				source: { kind: 'upload', name: 'x.wpress', size: 1 },
			} )
		).toBe( 'connections' );
		expect( tabOf( { type: 'export', link: mark } ) ).toBe( 'connections' );
		expect(
			tabOf( {
				type: 'import',
				link: null,
				source: { kind: 'upload' },
			} )
		).toBe( 'import' );
	} );
} );

describe( 'cloud work', () => {
	it( 'shows the upload phase only for a backup with destinations', () => {
		const plain = phasesFor( 'export', [ 'init', 'store', 'clean' ] ).map(
			( p ) => p.id
		);
		const cloud = phasesFor( 'export', [
			'init',
			'store',
			'clean',
			'cloud_upload',
			'cloud_retention',
			'local_cleanup',
		] ).map( ( p ) => p.id );

		expect( plain ).not.toContain( 'cloud' );
		expect( cloud[ cloud.length - 1 ] ).toBe( 'cloud' );
	} );

	it( 'has one phase per cloud job kind and belongs to Backups', () => {
		expect(
			phasesFor( 'cloud', [ 'cloud_upload' ] ).map( ( p ) => p.id )
		).toEqual( [ 'upload' ] );
		expect(
			phasesFor( 'cloud', [ 'cloud_download' ] ).map( ( p ) => p.id )
		).toEqual( [ 'download' ] );
		expect( tabOf( { type: 'cloud' } ) ).toBe( 'backups' );
		expect(
			phasesFor( 'import', [ 'cloud_download', 'validate' ] )[ 0 ].steps
		).toContain( 'cloud_download' );
	} );
} );
