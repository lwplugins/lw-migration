/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * What the UI does with a failed request (M2c-1 REST error contract). Reads
 * the HTTP status as well as the code: request() keeps the status even for a
 * non-JSON body (web-server 413/502 pages), plain apiFetch does not.
 */

/** Same request again after BUSY_DELAY (a tick or upload holds the job lock). */
export const BUSY = 'busy';
/** Same request again after RETRY_DELAY × attempt (network, gateway, PHP crash). */
export const RETRY = 'retry';
/** Read the job again and continue from its state. */
export const REREAD = 'reread';
/** The upload offset differs: go on from `data.received`. */
export const OFFSET = 'offset';
/** The upload slice was too large: shrink it and send again. */
export const TOO_LARGE = 'tooLarge';
/** The session ended (or a restore replaced the login keys): log in again. */
export const LOGGED_OUT = 'loggedOut';
/** Logged in, but the user may not do this (403 `rest_forbidden`): stop. */
export const NO_PERMISSION = 'noPermission';
/** Lasting: show the message, do not retry. */
export const STOP = 'stop';

export const MAX_RETRIES = 5;
export const BUSY_DELAY = 1500;
export const RETRY_DELAY = 2000;

const REREAD_CODES = [
	'lw_migration_unexpected_input',
	'lw_migration_not_uploading',
];
const TRANSIENT_CODES = [
	'lw_migration_error',
	'invalid_json',
	'fetch_error',
	'offline_error',
];

/**
 * @param {Object} error A rejection of request() or apiFetch.
 * @return {string} One of the kinds above.
 */
export function classify( error ) {
	const code = error?.code || '';
	const status = Number( error?.status ) || 0;

	if ( 'lw_migration_job_busy' === code ) {
		return BUSY;
	}
	if ( REREAD_CODES.includes( code ) ) {
		return REREAD;
	}
	if ( 'lw_migration_upload_offset' === code ) {
		return OFFSET;
	}
	if ( 413 === status || 'lw_migration_chunk_too_large' === code ) {
		return TOO_LARGE;
	}
	// request() has already tried one fresh nonce before an invalid one ends here.
	if ( 401 === status || 'rest_cookie_invalid_nonce' === code ) {
		return LOGGED_OUT;
	}
	if ( 'rest_forbidden' === code ) {
		return NO_PERMISSION;
	}
	if (
		TRANSIENT_CODES.includes( code ) ||
		( status >= 502 && status <= 504 ) ||
		( status >= 500 && `http_${ status }` === code )
	) {
		return RETRY;
	}
	return STOP;
}

/**
 * @param {Object} error Rejection.
 * @return {boolean} Whether it means the session ended (show LoginNotice).
 */
export const isLoggedOut = ( error ) => LOGGED_OUT === classify( error );

/**
 * Wait before the next attempt of a BUSY or RETRY failure.
 *
 * @param {string} kind    BUSY or RETRY.
 * @param {number} attempt 1-based attempt number.
 * @return {number} Milliseconds.
 */
export const retryDelay = ( kind, attempt ) =>
	BUSY === kind ? BUSY_DELAY : RETRY_DELAY * Math.max( 1, attempt );

/**
 * The request field a `lw_migration_invalid` refusal names, if any.
 *
 * @param {Object} error Rejection.
 * @return {string|null} Field name.
 */
export const invalidField = ( error ) =>
	'lw_migration_invalid' === error?.code ? error?.data?.field || null : null;

/**
 * A pause that ends early (and clears its timer) when any of the signals
 * aborts: a stopped or paused loop never sits out a wait.
 *
 * @param {number}        ms      Milliseconds.
 * @param {AbortSignal[]} signals Signals that end the wait.
 * @return {Promise<void>} Resolves after `ms` or on the first abort.
 */
export function sleep( ms, signals = [] ) {
	return new Promise( ( resolve ) => {
		const live = signals.filter( Boolean );
		if ( live.some( ( signal ) => signal.aborted ) ) {
			resolve();
			return;
		}
		const done = () => {
			clearTimeout( timer );
			live.forEach( ( signal ) =>
				signal.removeEventListener( 'abort', done )
			);
			resolve();
		};
		const timer = setTimeout( done, ms );
		live.forEach( ( signal ) =>
			signal.addEventListener( 'abort', done, { once: true } )
		);
	} );
}

/**
 * Runs `send` again while it fails with a BUSY or RETRY kind, at most
 * MAX_RETRIES times; any other failure (or the last one) is thrown. When
 * `signal` aborts, the wait ends at once and the last failure is thrown.
 *
 * @param {() => Promise<Object|null>} send   The request.
 * @param {AbortSignal}                signal Optional: ends the retries.
 * @return {Promise} The first success.
 */
export async function withRetry( send, signal ) {
	for ( let attempt = 1; ; attempt++ ) {
		try {
			return await send();
		} catch ( e ) {
			const kind = classify( e );
			if (
				( BUSY !== kind && RETRY !== kind ) ||
				attempt >= MAX_RETRIES ||
				signal?.aborted
			) {
				throw e;
			}
			await sleep( retryDelay( kind, attempt ), [ signal ] );
			if ( signal?.aborted ) {
				throw e;
			}
		}
	}
}

/**
 * Human message from a rejection of apiFetch or request(). A logout and a
 * missing permission always get our own text (the server's says nothing
 * about what to do); the login link next to it comes from LoginNotice.
 *
 * @param {Object} error Rejection.
 * @return {string} Message.
 */
export function errorMessage( error ) {
	const kind = classify( error );
	if ( LOGGED_OUT === kind ) {
		return __(
			'You were logged out. Log in again, then try once more.',
			'lw-migration'
		);
	}
	if ( NO_PERMISSION === kind ) {
		return __( 'You do not have permission to do this.', 'lw-migration' );
	}
	return (
		error?.message ||
		__(
			'That did not work. Reload the page and try again.',
			'lw-migration'
		)
	);
}
