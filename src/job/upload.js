/**
 * Sending an upload slice by slice, without React (ruling P4). The server's
 * count is the truth: every slice starts where the server says it holds the
 * file, so a reload, a lost answer or a resent slice never writes twice.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
	LOGGED_OUT as LOGGED_OUT_KIND,
	OFFSET,
	REREAD as REREAD_KIND,
	TOO_LARGE,
	classify,
} from '../data/errors';

/** Every byte is there; the job was handed its upload. */
export const DONE = 'done';
/** The job no longer waits for the upload: its new view was applied. */
export const REREAD = 'reread';
/** The caller stopped (screen gone). */
export const STOPPED = 'stopped';
/** The session ended: the user has to log in again. */
export const LOGGED_OUT = 'loggedOut';
/** The upload stopped: `error` says why. */
export const FAILED = 'failed';

/** Halving after a 413 never goes below this. */
export const MIN_CHUNK_BYTES = 256 * 1024;

/** Offset or size refusals in a row before the upload gives up. */
const MAX_REFUSALS = 8;

/**
 * The slice size after a 413: the server's own limit when the JSON answer
 * names a smaller one, else half (a web-server page names nothing), never
 * below MIN_CHUNK_BYTES.
 *
 * @param {number} current Slice size that was refused.
 * @param {Object} error   The 413 rejection.
 * @return {number|null} New size, or null when it cannot get smaller.
 */
export function smallerChunk( current, error ) {
	const named = Number( error?.data?.chunkBytes ) || 0;
	if ( named > 0 && named < current ) {
		return named;
	}
	const half = Math.max( MIN_CHUNK_BYTES, Math.floor( current / 2 ) );
	return half < current ? half : null;
}

/**
 * @param {Object}                                                      options
 * @param {Object}                                                      options.job           View with `input.kind === 'upload'`.
 * @param {Blob}                                                        options.file          The chosen file (same size as `input.size`).
 * @param {number}                                                      options.fallbackChunk Slice size when the view names none.
 * @param {(id: string, offset: number, blob: Blob) => Promise<Object>} options.send          Sends one slice; retries BUSY/RETRY itself.
 * @param {(id: string) => Promise<Object>}                             options.read          Reads the job.
 * @param {(view: Object) => void}                                      options.onView        Called with every new view.
 * @param {(percent: number) => void}                                   options.onProgress    Called before each slice.
 * @param {() => boolean}                                               options.stopped       Whether the caller stopped.
 * @return {Promise<Object>} `{ outcome, error? }`.
 */
export async function sendFile( {
	job,
	file,
	fallbackChunk,
	send,
	read,
	onView,
	onProgress,
	stopped,
} ) {
	const size = job.input.size;
	let offset = job.input.received;
	let chunk = job.input.chunkBytes || fallbackChunk;
	let refusals = 0;
	let handover = false;

	for (;;) {
		if ( stopped() ) {
			return { outcome: STOPPED };
		}
		// Every byte is there but the job still waits (the answer that handed
		// it over got lost before it was saved): send the last slice once
		// more — the server then hands the upload over.
		let from = offset;
		if ( offset >= size ) {
			if ( handover ) {
				return { outcome: FAILED, error: stuckError() };
			}
			handover = true;
			from = Math.max( 0, size - chunk );
		}
		onProgress( Math.floor( ( from / size ) * 100 ) );
		let next;
		try {
			next = await send(
				job.id,
				from,
				file.slice( from, Math.min( from + chunk, size ) )
			);
		} catch ( error ) {
			if ( stopped() ) {
				return { outcome: STOPPED };
			}
			const kind = classify( error );
			if ( LOGGED_OUT_KIND === kind ) {
				return { outcome: LOGGED_OUT, error };
			}
			if ( ++refusals > MAX_REFUSALS ) {
				return { outcome: FAILED, error };
			}
			const received = Number( error?.data?.received );
			if ( OFFSET === kind && Number.isFinite( received ) ) {
				offset = received;
				continue;
			}
			if ( TOO_LARGE === kind ) {
				chunk = smallerChunk( chunk, error );
				if ( null === chunk ) {
					return { outcome: FAILED, error };
				}
			} else if ( OFFSET !== kind && REREAD_KIND !== kind ) {
				return { outcome: FAILED, error };
			}
			// Go on from what the server holds now.
			let view;
			try {
				view = await read( job.id );
			} catch ( e ) {
				if ( stopped() ) {
					return { outcome: STOPPED };
				}
				return {
					outcome:
						LOGGED_OUT_KIND === classify( e ) ? LOGGED_OUT : FAILED,
					error: e,
				};
			}
			onView( view );
			if ( 'upload' !== view?.input?.kind ) {
				return { outcome: REREAD };
			}
			offset = view.input.received;
			continue;
		}
		refusals = 0;
		onView( next );
		if ( 'upload' !== next?.input?.kind ) {
			return { outcome: DONE };
		}
		offset = next.input.received;
		if ( next.input.chunkBytes ) {
			chunk = Math.min( chunk, next.input.chunkBytes );
		}
	}
}

/**
 * @return {Object} Rejection-like error for an upload the server never took over.
 */
const stuckError = () => ( {
	code: 'lw_migration_upload_stuck',
	message: __(
		'The whole file arrived, but the restore did not go on. Reload the page and try again.',
		'lw-migration'
	),
	data: {},
} );
