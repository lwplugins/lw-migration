/**
 * The "Total size in GB" field over the byte value the server keeps: shown
 * with up to two decimals, and a value the user did not change goes back as
 * the exact bytes it came from (no rounding drift, no phantom edit).
 */

export const GB = 1073741824;

/**
 * @param {number} bytes Stored value.
 * @return {number} GB with at most two decimals.
 */
export const gbShown = ( bytes ) => Math.round( ( bytes / GB ) * 100 ) / 100;

/**
 * Bytes for what the field holds now.
 *
 * @param {string|number} value   Field value (GB).
 * @param {number}        current Bytes stored now.
 * @param {number}        max     Largest allowed bytes.
 * @return {number} Whole bytes, 0..max.
 */
export function bytesFrom( value, current, max ) {
	const gb = parseFloat( value ) || 0;
	if ( gb === gbShown( current ) ) {
		return current;
	}
	return Math.min( max, Math.max( 0, Math.round( gb * GB ) ) );
}
