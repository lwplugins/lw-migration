/**
 * The connection check and the fingerprint trust (an SFTP host key, an
 * FTPS certificate) of a destination card, without React (plan D31, P-I9,
 * issue #5): what the card shows afterwards. A fingerprint to check stays
 * on the card; any other failure the server recorded on
 * the destination is shown from the list read again; a refusal that
 * recorded nothing (logged out, address changed meanwhile) is shown here.
 */

/**
 * Internal dependencies
 */
import { api } from '../data/api';
import { errorMessage } from '../data/errors';
import { hostKeyDiffers, hostKeyState } from './destinations';

/** A refused test the server recorded on the destination (status + message). */
const RECORDED = [
	'lw_migration_destination_failed',
	'lw_migration_destination_missing',
];

/**
 * @typedef {Object} CardCallbacks
 * @property {() => Promise<void>} onChanged The list is out of date (read it again).
 * @property {() => void}          onWorks   The connection works.
 */

/**
 * @param {Object}        e    A rejection of the check or the trust.
 * @param {CardCallbacks} card Card callbacks.
 * @return {Promise<{hostKey: ?Object, failure: ?Object}>} What the card shows.
 */
async function refused( e, card ) {
	const hostKey = hostKeyState( e );
	if ( hostKey || RECORDED.includes( e?.code ) ) {
		await card.onChanged();
		return { hostKey, failure: null };
	}
	return { hostKey: null, failure: e };
}

/**
 * @param {string}        id   Destination id.
 * @param {CardCallbacks} card Card callbacks.
 * @return {Promise<{hostKey: ?Object, failure: ?Object}>} What the card shows.
 */
export async function checkConnection( id, card ) {
	try {
		await api.testDestination( id );
	} catch ( e ) {
		return refused( e, card );
	}
	card.onWorks();
	await card.onChanged();
	return { hostKey: null, failure: null };
}

/**
 * Trusts the fingerprint shown on the card. When the server shows yet
 * another one by then (409), that one becomes a changed one against the one
 * shown before, with the server's text; nothing was stored.
 *
 * @param {string}        id      Destination id.
 * @param {Object}        current The fingerprint state on the card.
 * @param {CardCallbacks} card    Card callbacks.
 * @return {Promise<{hostKey: ?Object, failure: ?Object, trusted: boolean}>} What the card shows.
 */
export async function trustHostKey( id, current, card ) {
	try {
		await api.trustDestination( id, current.field, current.fingerprint );
	} catch ( e ) {
		const changed = hostKeyDiffers( current, e );
		if ( changed ) {
			return {
				hostKey: { ...changed, note: errorMessage( e ) },
				failure: null,
				trusted: false,
			};
		}
		return { ...( await refused( e, card ) ), trusted: false };
	}
	card.onWorks();
	await card.onChanged();
	return { hostKey: null, failure: null, trusted: true };
}
