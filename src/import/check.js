/**
 * The check view's rules without React: which host the typed confirmation
 * asks for (a UI gate only; the server's own `confirm` input is unchanged)
 * and how much room the restore needs with the safety-backup switch as set.
 */

/**
 * The host of an address, with its port, in lower case ('' when there is none).
 *
 * @param {string|undefined} url Address.
 * @return {string} Host.
 */
export function hostOf( url ) {
	const text = String( url || '' ).trim();
	if ( ! text ) {
		return '';
	}
	try {
		return new URL( text ).host.toLowerCase();
	} catch {
		return text
			.replace( /^[a-z][a-z0-9+.-]*:\/\//i, '' )
			.split( /[/?#]/ )[ 0 ]
			.toLowerCase();
	}
}

/**
 * What the confirmation asks to type: the target's host (on a network the
 * main site's, which is the summary's `to`).
 *
 * @param {Object} summary Confirm summary.
 * @return {string} Host.
 */
export const confirmHost = ( summary ) => hostOf( summary?.to );

/**
 * @param {string|undefined} typed What was typed.
 * @param {string}           host  confirmHost().
 * @return {boolean} The host, trimmed, in any letter case.
 */
export const matchesHost = ( typed, host ) =>
	'' !== host &&
	String( typed || '' )
		.trim()
		.toLowerCase() === host.toLowerCase();

/**
 * Bytes the restore needs without the safety backup (`diskNeeded` counts it
 * when the server's default had it on).
 *
 * @param {Object} summary Confirm summary.
 * @return {number} Bytes.
 */
const baseNeed = ( summary ) =>
	Math.max(
		0,
		( Number( summary.diskNeeded ) || 0 ) -
			( summary.safetyBackup ? Number( summary.safetyBytes ) || 0 : 0 )
	);

/**
 * @param {Object}  summary Confirm summary.
 * @param {boolean} safety  The safety-backup switch.
 * @return {number} Bytes the restore needs as chosen.
 */
export const neededSpace = ( summary, safety ) =>
	baseNeed( summary ) + ( safety ? Number( summary.safetyBytes ) || 0 : 0 );

/**
 * @param {Object}  summary Confirm summary.
 * @param {boolean} safety  The safety-backup switch.
 * @return {boolean} The free space is known and smaller than needed.
 */
export const isLow = ( summary, safety ) =>
	null !== summary.diskFree &&
	undefined !== summary.diskFree &&
	summary.diskFree < neededSpace( summary, safety );

/**
 * Whether the safety backup may be switched on: it fits next to the restore
 * (or the host does not tell its free space).
 *
 * @param {Object} summary Confirm summary.
 * @return {boolean} Fits.
 */
export const safetyFits = ( summary ) => ! isLow( summary, true );
