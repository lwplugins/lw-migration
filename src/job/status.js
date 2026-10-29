/**
 * The job screen's texts (title, source, status line, live announcement)
 * and the "may still run in another window" timing.
 */

/**
 * WordPress dependencies
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { formatBytes } from '../data/format';
import { busyElsewhereFor, isFinished, phaseIndex, phasesFor } from './phases';

const TITLES = {
	export: {
		running: __( 'Backing up', 'lw-migration' ),
		done: __( 'Backup ready', 'lw-migration' ),
		failed: __( 'The backup stopped', 'lw-migration' ),
		cancelled: __( 'Backup cancelled', 'lw-migration' ),
	},
	import: {
		running: __( 'Restoring', 'lw-migration' ),
		done: __( 'Restore finished', 'lw-migration' ),
		failed: __( 'The restore stopped', 'lw-migration' ),
		cancelled: __( 'Restore cancelled', 'lw-migration' ),
	},
	push: {
		running: __( 'Sending this site', 'lw-migration' ),
		done: __( 'Site sent', 'lw-migration' ),
		failed: __( 'Sending stopped', 'lw-migration' ),
		cancelled: __( 'Sending was cancelled', 'lw-migration' ),
	},
	reset: {
		running: __( 'Resetting', 'lw-migration' ),
		done: __( 'Reset finished', 'lw-migration' ),
		failed: __( 'The reset stopped', 'lw-migration' ),
		cancelled: __( 'Reset cancelled', 'lw-migration' ),
	},
	move: {
		running: __( 'Moving the backups', 'lw-migration' ),
		done: __( 'Backups moved', 'lw-migration' ),
		failed: __( 'Moving the backups stopped', 'lw-migration' ),
		cancelled: __( 'Moving the backups was cancelled', 'lw-migration' ),
	},
	// A cloud job by its action (job view result.action).
	cloud_upload: {
		running: __( 'Uploading the backup', 'lw-migration' ),
		done: __( 'Upload finished', 'lw-migration' ),
		failed: __( 'The upload stopped', 'lw-migration' ),
		cancelled: __( 'Upload cancelled', 'lw-migration' ),
	},
	cloud_fetch: {
		running: __( 'Downloading the backup', 'lw-migration' ),
		done: __( 'Download finished', 'lw-migration' ),
		failed: __( 'The download stopped', 'lw-migration' ),
		cancelled: __( 'Download cancelled', 'lw-migration' ),
	},
};

/**
 * What kind of job it is: the job card's eyebrow (the section names where
 * they fit; a cloud job by its action, a folder move by what it moves).
 *
 * @param {Object} job Job view.
 * @return {string} Kind.
 */
export function kindOf( job ) {
	if ( 'cloud' === job.type ) {
		return 'fetch' === job.result?.action
			? __( 'Download', 'lw-migration' )
			: __( 'Upload', 'lw-migration' );
	}
	const kinds = {
		export: __( 'Backup', 'lw-migration' ),
		move: __( 'Backups folder', 'lw-migration' ),
		push: __( 'Sending', 'lw-migration' ),
		reset: __( 'Reset', 'lw-migration' ),
	};
	return kinds[ job.type ] ?? __( 'Restore', 'lw-migration' );
}

/**
 * @param {Object} job Job view.
 * @return {Object} Its titles.
 */
const titlesOf = ( job ) =>
	'cloud' === job.type
		? ( TITLES[ `cloud_${ job.result?.action }` ] ?? TITLES.cloud_upload )
		: ( TITLES[ job.type ] ?? TITLES.import );

export function sourceLine( source ) {
	if ( 'url' === source.kind ) {
		return sprintf(
			/* translators: %s: address of the archive, shortened. */
			__( 'From %s', 'lw-migration' ),
			source.name
		);
	}
	if ( 'upload' === source.kind ) {
		return sprintf(
			/* translators: 1: file name, 2: file size. */
			__( 'From the file %1$s (%2$s)', 'lw-migration' ),
			source.name,
			formatBytes( source.size )
		);
	}
	if ( 'link' === source.kind ) {
		return sprintf(
			/* translators: %s: name of the connected site. */
			__( 'From the connected site %s', 'lw-migration' ),
			source.name
		);
	}
	if ( 'cloud' === source.kind && source.destination ) {
		return sprintf(
			/* translators: 1: backup file name, 2: destination name. */
			__( 'From the backup %1$s at %2$s', 'lw-migration' ),
			source.name,
			source.destination
		);
	}
	if ( 'cloud' === source.kind ) {
		return sprintf(
			/* translators: %s: backup file name (its destination was deleted since). */
			__( 'From the cloud backup %s', 'lw-migration' ),
			source.name
		);
	}
	if ( 'file' === source.kind ) {
		return sprintf(
			/* translators: %s: archive file name on the server. */
			__( 'From the server file %s', 'lw-migration' ),
			source.name
		);
	}
	return sprintf(
		/* translators: %s: backup file name. */
		__( 'From the backup %s', 'lw-migration' ),
		source.name
	);
}

/**
 * What the live region says: it changes only with the phase or the status
 * (not with every tick message), so a screen reader is not flooded.
 *
 * @param {Object}      job       Job view.
 * @param {number|null} uploading Upload percent, null when not uploading.
 * @param {string}      title     The screen's title (said once finished).
 * @return {string} Announcement.
 */
export function announcement( job, uploading, title ) {
	if ( isFinished( job ) ) {
		return title;
	}
	if ( null !== uploading || 'needs_input' === job.status ) {
		return statusLine( job, uploading );
	}
	const phase = phasesFor( job.type, job.steps )[ phaseIndex( job ) ];
	return phase
		? sprintf(
				/* translators: %s: name of the job's current phase, e.g. "Files". */
				__( 'Now: %s', 'lw-migration' ),
				phase.label
			)
		: '';
}

export function statusLine( job, uploading ) {
	if ( null !== uploading ) {
		return __( 'Uploading the archive…', 'lw-migration' );
	}
	if ( 'needs_input' === job.status ) {
		const lines = {
			password: __( 'The archive needs its password.', 'lw-migration' ),
			// The summary below says that nothing has changed yet.
			confirm: __( 'Waiting for your confirmation.', 'lw-migration' ),
			upload: __( 'Waiting for the file.', 'lw-migration' ),
		};
		return lines[ job.input?.kind ] || '';
	}
	return job.message || __( 'Starting…', 'lw-migration' );
}

/**
 * @param {Object} job Job view.
 * @return {string} The job screen's title for its type and state.
 */
export const titleOf = ( job ) =>
	titlesOf( job )[ isFinished( job ) ? job.status : 'running' ];

/**
 * Milliseconds until the "may run elsewhere" window of a job saved at
 * `updatedAt` should be checked again, null when it is already over.
 *
 * @param {number} updatedAt Unix seconds of the job's last save.
 * @param {number} now       Unix seconds.
 * @return {number|null} Delay.
 */
export function recheckDelay( updatedAt, now ) {
	const left = busyElsewhereFor( { updatedAt }, now );
	return left > 0 ? Math.ceil( left * 1000 ) + 250 : null;
}

/**
 * Seconds left in which a stopped-looking job may still be driven from
 * another window, 0 = quiet. Starts over on every new save (`updatedAt`),
 * and re-checks until the window is over, also when a save keeps it full.
 *
 * @param {Object} job Job view.
 * @return {number} Seconds.
 */
export function useBusyElsewhere( job ) {
	const updatedAt = job.updatedAt;
	const [ now, setNow ] = useState( () => Date.now() / 1000 );
	const [ check, setCheck ] = useState( 0 );
	useEffect( () => {
		const fresh = Date.now() / 1000;
		setNow( fresh );
		const delay = recheckDelay( updatedAt, fresh );
		if ( null === delay ) {
			return undefined;
		}
		const timer = setTimeout( () => setCheck( ( n ) => n + 1 ), delay );
		return () => clearTimeout( timer );
	}, [ updatedAt, check ] );
	return busyElsewhereFor( job, now );
}
