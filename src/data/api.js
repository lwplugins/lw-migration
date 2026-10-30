/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies
 */
import { NAMESPACE } from './boot';
import { headRefusal } from './download';
import { withRetry } from './errors';
import { createSliceSender } from './uploadSlice';

// The message helper lives with the error kinds (unit-tested there).
export { errorMessage } from './errors';

const path = ( route, args ) =>
	addQueryArgs( `/${ NAMESPACE }${ route }`, args );
const post = ( route, data, args ) =>
	apiFetch( { path: path( route, args ), method: 'POST', data } );
const backupArgs = ( backup ) => ( {
	folder: backup.folder,
	name: backup.name,
} );

const parseBody = ( text ) => {
	try {
		return '' === text ? null : JSON.parse( text );
	} catch {
		return undefined;
	}
};

/**
 * One nonce refresh, the way apiFetch does it for parsed calls (with
 * `parse: false` it hands back the raw response instead).
 *
 * @return {Promise<boolean>} Whether a fresh nonce is in place.
 */
async function refreshNonce() {
	if ( ! apiFetch.nonceEndpoint || ! apiFetch.nonceMiddleware ) {
		return false;
	}
	try {
		const response = await window.fetch( apiFetch.nonceEndpoint );
		const nonce = response.ok ? ( await response.text() ).trim() : '';
		if ( ! nonce || '0' === nonce ) {
			return false;
		}
		apiFetch.nonceMiddleware.nonce = nonce;
		return true;
	} catch {
		return false;
	}
}

/**
 * apiFetch that keeps the HTTP status (ruling P5): resolves with the parsed
 * JSON (null for an empty body) on 2xx; rejects with `{ status, code,
 * message, data }`, where a non-JSON body gets the code `http_<status>`.
 * Network failures reject like apiFetch (`fetch_error` / `offline_error`).
 *
 * @param {Object}  options      apiFetch options.
 * @param {boolean} nonceRetried Internal: the nonce was refreshed once.
 * @return {Promise<Object|null>} Body.
 */
export async function request( options, nonceRetried = false ) {
	let response;
	try {
		response = await apiFetch( { ...options, parse: false } );
	} catch ( e ) {
		if ( ! ( e instanceof window.Response ) ) {
			throw e;
		}
		response = e;
	}
	const body = parseBody( await response.text() );

	if ( response.ok ) {
		if ( undefined === body ) {
			throw {
				status: response.status,
				code: 'invalid_json',
				message: __(
					'The server sent an answer this page cannot read.',
					'lw-migration'
				),
			};
		}
		return body;
	}
	if (
		! nonceRetried &&
		'rest_cookie_invalid_nonce' === body?.code &&
		( await refreshNonce() )
	) {
		return request( options, true );
	}
	throw {
		status: response.status,
		code: body?.code || `http_${ response.status }`,
		message:
			body?.message ||
			sprintf(
				/* translators: %d: HTTP status code, e.g. 502. */
				__( 'The server answered with error %d.', 'lw-migration' ),
				response.status
			),
		data: body?.data || {},
	};
}

// A slice as a file part; the raw body only where the server takes no uploads.
const sendSlice = createSliceSender( request );

export const api = {
	// The home page in one read (Rest\OverviewApi, docs/react-admin-api.md).
	overview: () => request( { path: path( '/overview' ) } ),
	activeJob: () => apiFetch( { path: path( '/jobs/active' ) } ),
	// request(), not apiFetch: a web-server 413/502 or a 401 keeps its status.
	startJob: ( data ) =>
		request( { path: path( '/jobs' ), method: 'POST', data } ),
	// The Backup section's sizes; asked again while not `complete` (Rest\EstimateApi).
	exportEstimate: ( signal ) =>
		request( { path: path( '/export/estimate' ), signal } ),
	// `signal` (optional) lets the job screen abort its requests when it stops.
	job: ( id, signal ) => request( { path: path( `/jobs/${ id }` ), signal } ),
	// The job driver classifies tick failures itself (data/errors.js).
	tick: ( id, signal ) =>
		request( {
			path: path( `/jobs/${ id }/tick` ),
			method: 'POST',
			signal,
		} ),
	answer: ( id, data ) =>
		withRetry( () =>
			request( {
				path: path( `/jobs/${ id }/input` ),
				method: 'POST',
				data,
			} )
		),
	cancel: ( id ) =>
		withRetry( () =>
			request( { path: path( `/jobs/${ id }` ), method: 'DELETE' } )
		),
	upload: ( id, offset, blob, signal ) =>
		withRetry(
			() =>
				sendSlice(
					path( `/jobs/${ id }/upload`, { offset } ),
					blob,
					signal
				),
			signal
		),

	backups: () => request( { path: path( '/backups' ) } ),
	// request(): the Backups section reads the status of a refusal (401, 404).
	label: ( backup, label ) =>
		request( {
			path: path( '/backups/label' ),
			method: 'POST',
			data: { ...backupArgs( backup ), label },
		} ),
	deleteBackup: ( backup ) =>
		request( {
			path: path( '/backups', backupArgs( backup ) ),
			method: 'DELETE',
		} ),
	// `after` = the header offset the previous page returned as `next`.
	entries: ( backup, after ) =>
		withRetry( () =>
			request( {
				path: path( '/backups/entries', {
					...backupArgs( backup ),
					after,
				} ),
			} )
		),
	// The chosen backup's parts (Rest\BackupContentsApi); `partial: true` = ask again.
	backupContents: ( backup, signal ) =>
		withRetry(
			() =>
				request( {
					path: path( '/backups/contents', backupArgs( backup ) ),
					signal,
				} ),
			signal
		),
	downloadLink: ( backup ) =>
		request( {
			path: path( '/backups/download-token' ),
			method: 'POST',
			data: backupArgs( backup ),
		} ),
	// Transfer links: the answer of createShare is the only one with the link in it.
	shares: () => request( { path: path( '/backups/shares' ) } ),
	createShare: ( backup, expires ) =>
		request( {
			path: path( '/backups/shares' ),
			method: 'POST',
			data: { ...backupArgs( backup ), expires },
		} ),
	revokeShare: ( id ) =>
		request( {
			path: path( `/backups/shares/${ id }` ),
			method: 'DELETE',
		} ),

	settings: () => apiFetch( { path: path( '/settings' ) } ),
	// request(): the Settings section reads the refused field and the status.
	backupDir: () => request( { path: path( '/backup-dir' ) } ),
	// { path } ('' = the default folder) or { action: 'finish' | 'undo' }; answers the new job.
	moveBackups: ( body ) =>
		request( { path: path( '/backup-dir' ), method: 'POST', data: body } ),
	saveSettings: ( patch ) => post( '/settings', patch ),

	// request(): the Schedules section reads the status of a refusal (401, 404, 409).
	schedules: () => request( { path: path( '/schedules' ) } ),
	createSchedule: ( data ) =>
		request( { path: path( '/schedules' ), method: 'POST', data } ),
	updateSchedule: ( id, data ) =>
		request( { path: path( `/schedules/${ id }` ), method: 'POST', data } ),
	deleteSchedule: ( id ) =>
		request( { path: path( `/schedules/${ id }` ), method: 'DELETE' } ),
	runSchedule: ( id ) =>
		request( { path: path( `/schedules/${ id }/run` ), method: 'POST' } ),
	scheduleLog: ( id ) =>
		request( { path: path( `/schedules/${ id }/log` ) } ),
	clearScheduleLog: ( id ) =>
		request( { path: path( `/schedules/${ id }/log` ), method: 'DELETE' } ),

	// Connections (single sites only): only createCode answers with a code, once.
	links: () => request( { path: path( '/links' ) } ),
	addLink: ( code ) =>
		request( { path: path( '/links' ), method: 'POST', data: { code } } ),
	createCode: ( allow ) =>
		request( {
			path: path( '/links/code' ),
			method: 'POST',
			data: { allow },
		} ),
	dropCode: () =>
		request( { path: path( '/links/code' ), method: 'DELETE' } ),
	removeLink: ( id ) =>
		request( { path: path( `/links/${ id }` ), method: 'DELETE' } ),
	checkLink: ( id ) =>
		request( { path: path( `/links/${ id }/check` ), method: 'POST' } ),
	pushTo: ( id, safetyBackup ) =>
		request( {
			path: path( `/links/${ id }/push` ),
			method: 'POST',
			data: { safetyBackup },
		} ),
	pullFrom: ( id, safetyBackup ) =>
		request( {
			path: path( `/links/${ id }/pull` ),
			method: 'POST',
			data: { safetyBackup },
		} ),

	// Reset: what each reset removes and keeps, and the start (password checked once, never kept).
	resetSummary: () => request( { path: path( '/reset' ) } ),
	startReset: ( { type, password, backup, confirm } ) =>
		request( {
			path: path( '/reset' ),
			method: 'POST',
			data: {
				type,
				password,
				backup,
				...( undefined === confirm ? {} : { confirm } ),
			},
		} ),

	// Cloud destinations: a secret is sent only when typed, never answered (`secrets.<key>.set`).
	destinations: () => request( { path: path( '/destinations' ) } ),
	createDestination: ( data ) =>
		request( { path: path( '/destinations' ), method: 'POST', data } ),
	updateDestination: ( id, data ) =>
		request( {
			path: path( `/destinations/${ id }` ),
			method: 'POST',
			data,
		} ),
	deleteDestination: ( id ) =>
		request( { path: path( `/destinations/${ id }` ), method: 'DELETE' } ),
	testDestination: ( id ) =>
		request( {
			path: path( `/destinations/${ id }/test` ),
			method: 'POST',
		} ),
	// Stores the fingerprint the user confirmed under its field (hostKey: SFTP,
	// certFingerprint: FTPS), then tests again (409: the server shows another).
	trustDestination: ( id, field, fingerprint ) =>
		request( {
			path: path( `/destinations/${ id }/trust` ),
			method: 'POST',
			data: { [ field ]: fingerprint },
		} ),
	// `{ url }`: the provider's sign-in for an OAuth destination (Rest\OAuthRoutes).
	connectDestination: ( id ) =>
		request( {
			path: path( `/destinations/${ id }/connect` ),
			method: 'POST',
		} ),
	remoteFiles: ( id ) =>
		request( { path: path( `/destinations/${ id }/files` ) } ),
	deleteRemote: ( id, name ) =>
		request( {
			path: path( `/destinations/${ id }/files`, { name } ),
			method: 'DELETE',
		} ),
	// An encrypted backup's password: given up front (CLI-like callers), or
	// asked by the job's own password prompt like any restore ('' = not sent).
	restoreRemote: ( id, name, safetyBackup, password = '' ) =>
		request( {
			path: path( `/destinations/${ id }/files/restore` ),
			method: 'POST',
			data: {
				name,
				safetyBackup,
				...( '' === password ? {} : { password } ),
			},
		} ),
	fetchRemote: ( id, name ) =>
		request( {
			path: path( `/destinations/${ id }/files/fetch` ),
			method: 'POST',
			data: { name },
		} ),
	uploadBackup: ( backup, destinations ) =>
		request( {
			path: path( '/backups/upload' ),
			method: 'POST',
			data: { ...backupArgs( backup ), destinations },
		} ),

	// Network only: one page of the site picker (the search matches addresses).
	networkSites: ( { page, perPage, search }, signal ) =>
		request( {
			path: path( '/network/sites', { page, perPage, search } ),
			signal,
		} ),
};

/**
 * Starts the browser download of one backup (ruling P1): a fresh token link,
 * checked with HEAD first (same request as the real GET: login cookie, no
 * nonce), so a dead link never replaces the admin page with an error; on
 * 401/403 a new link is asked for once (data/download.js). Rejects like
 * request(); a second refusal keeps its status (401 = logged out), a 404 is a
 * missing backup.
 *
 * @param {Object} backup { folder, name }.
 * @return {Promise<void>} Resolves when the download was handed to the browser.
 */
export async function downloadBackup( backup ) {
	for ( let attempt = 1; ; attempt++ ) {
		const { url } = await api.downloadLink( backup );
		let response;
		try {
			response = await window.fetch( url, {
				method: 'HEAD',
				credentials: 'same-origin',
			} );
		} catch {
			throw {
				status: 0,
				code: 'fetch_error',
				message: __(
					'Could not reach the server. Check the connection and try again.',
					'lw-migration'
				),
			};
		}
		if ( response.ok ) {
			window.location.assign( url );
			return;
		}
		const refusal = headRefusal( response.status, attempt );
		if ( refusal ) {
			throw refusal;
		}
	}
}
