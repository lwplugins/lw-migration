/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

const EXPORT = [
	{
		id: 'prepare',
		label: __( 'Preparing', 'lw-migration' ),
		steps: [ 'init', 'config', 'enumerate' ],
	},
	{ id: 'files', label: __( 'Files', 'lw-migration' ), steps: [ 'content' ] },
	{
		id: 'database',
		label: __( 'Database', 'lw-migration' ),
		steps: [ 'database', 'database_file' ],
	},
	{
		id: 'save',
		label: __( 'Saving', 'lw-migration' ),
		steps: [ 'finalize', 'store', 'clean' ],
	},
];

// After the backup is saved: the uploads, their retention and the local cleanup (only a backup with destinations).
const CLOUD_PHASE = {
	id: 'cloud',
	label: __( 'Uploading', 'lw-migration' ),
	steps: [ 'cloud_upload', 'cloud_retention', 'local_cleanup' ],
};

// A cloud job (job type `cloud`): one backup uploaded, or one downloaded here.
const CLOUD = [
	{
		id: 'upload',
		label: __( 'Uploading', 'lw-migration' ),
		steps: [ 'cloud_upload' ],
	},
	{
		id: 'download',
		label: __( 'Downloading', 'lw-migration' ),
		steps: [ 'cloud_download' ],
	},
];

const IMPORT = [
	{
		id: 'source',
		label: __( 'Receiving', 'lw-migration' ),
		steps: [
			'upload',
			'cloud_download',
			'source',
			'url_resolve',
			'url_download',
			'link_export',
			'link_download',
		],
	},
	{
		id: 'check',
		label: __( 'Checking', 'lw-migration' ),
		steps: [ 'validate', 'crc', 'decrypt', 'confirm' ],
	},
	{
		id: 'safety',
		label: __( 'Safety backup', 'lw-migration' ),
		steps: [ 'safety_backup' ],
	},
	{
		id: 'restore',
		label: __( 'Restoring', 'lw-migration' ),
		steps: [ 'content', 'database_file', 'database', 'preserve' ],
	},
	{
		id: 'finish',
		label: __( 'Finishing', 'lw-migration' ),
		steps: [ 'swap', 'post_import', 'mu_plugins', 'done', 'clean' ],
	},
];

// This site's archive, sent to a connected site and restored there.
const PUSH = [
	EXPORT[ 0 ],
	EXPORT[ 1 ],
	{
		id: 'database',
		label: __( 'Database', 'lw-migration' ),
		steps: [ 'database', 'database_file', 'finalize' ],
	},
	{
		id: 'send',
		label: __( 'Sending', 'lw-migration' ),
		steps: [ 'link_upload' ],
	},
	{
		id: 'remote',
		label: __( 'Restoring there', 'lw-migration' ),
		steps: [ 'link_import', 'link_clean' ],
	},
];

const MOVE = [
	{ id: 'move', label: __( 'Moving', 'lw-migration' ), steps: [ 'move' ] },
	{
		id: 'finish',
		label: __( 'Finishing', 'lw-migration' ),
		steps: [ 'finish' ],
	},
];

// A reset shows only the phases of the parts it runs (filtered by its steps).
const RESET = [
	{
		id: 'backup',
		label: __( 'Backup', 'lw-migration' ),
		steps: [ 'reset_backup' ],
	},
	{
		id: 'plugins',
		label: __( 'Plugins', 'lw-migration' ),
		steps: [ 'reset_plugins' ],
	},
	{
		id: 'themes',
		label: __( 'Themes', 'lw-migration' ),
		steps: [ 'reset_themes' ],
	},
	{
		id: 'media',
		label: __( 'Media', 'lw-migration' ),
		steps: [ 'reset_media' ],
	},
	{
		id: 'database',
		label: __( 'Database', 'lw-migration' ),
		steps: [ 'reset_db_build', 'reset_db_swap', 'reset_db_finish' ],
	},
];

export const isFinished = ( job ) =>
	[ 'done', 'failed', 'cancelled' ].includes( job?.status );

/**
 * @param {string}   type  Job type.
 * @param {string[]} steps The job's steps (a reset and a cloud job show only
 *                         the phases they run; a backup with destinations
 *                         ends with the uploads).
 * @return {Array} Phases.
 */
export function phasesFor( type, steps = [] ) {
	const has = Array.isArray( steps ) ? steps : [];
	const only = ( list ) =>
		list.filter( ( phase ) =>
			phase.steps.some( ( step ) => has.includes( step ) )
		);
	if ( 'reset' === type ) {
		return only( RESET );
	}
	if ( 'cloud' === type ) {
		return only( CLOUD );
	}
	if ( 'export' === type ) {
		return has.includes( 'cloud_upload' )
			? [ ...EXPORT, CLOUD_PHASE ]
			: EXPORT;
	}
	return { move: MOVE, push: PUSH }[ type ] ?? IMPORT;
}

/**
 * @param {Object} job Job view.
 * @return {string} The section the job belongs to: every job of a
 *                  connection (this site's push and pull, and the other
 *                  site's, run here) to Connections, a backup to Back up, a
 *                  move to Settings, a reset to Reset, a cloud upload or
 *                  download to Backups, everything else to Restore.
 */
export const tabOf = ( job ) =>
	job.link || 'push' === job.type
		? 'connections'
		: ( {
				export: 'export',
				move: 'settings',
				reset: 'reset',
				cloud: 'backups',
			}[ job.type ] ?? 'import' );

/**
 * @param {Object} job Job view.
 * @return {number} Index of the current phase (the phase count when done).
 */
export function phaseIndex( job ) {
	const phases = phasesFor( job.type, job.steps );
	if ( 'done' === job.status ) {
		return phases.length;
	}
	return Math.max(
		0,
		phases.findIndex( ( phase ) => phase.steps.includes( job.step ) )
	);
}

/**
 * Whole-job percentage: the share of finished steps plus the current step's own progress.
 *
 * @param {Object} job Job view.
 * @return {number} 0–100 (99 until done).
 */
export function percentOf( job ) {
	if ( 'done' === job.status ) {
		return 100;
	}
	const share = 100 / Math.max( 1, job.steps.length );
	return Math.min(
		99,
		Math.floor( job.stepIndex * share + ( job.progress / 100 ) * share )
	);
}

/** Seconds after its last save in which a job may still be driven elsewhere (the server's tick length is not given to the page). */
export const ELSEWHERE_SECONDS = 20;

/**
 * Seconds left in which a stopped-looking job may still be running in
 * another window or tab (it was saved moments ago), 0 when it is quiet.
 *
 * @param {Object} job Job view (`updatedAt` in Unix seconds).
 * @param {number} now Unix seconds.
 * @return {number} Seconds, 0 = quiet.
 */
export function busyElsewhereFor( job, now ) {
	const left = ( Number( job.updatedAt ) || 0 ) + ELSEWHERE_SECONDS - now;
	return Math.max( 0, Math.min( ELSEWHERE_SECONDS, left ) );
}
