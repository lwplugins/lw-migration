/**
 * The address rows of a restore into a network, without React: which rows
 * can be edited, where they start, and what the confirmation sends
 * (Rest\NetworkInput::siteAddresses(): index => address, changed rows only).
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

const sitesOf = ( summary ) => summary?.network?.sites || [];

const current = ( site ) => site.address || site.proposal || '';

/**
 * @param {Object} summary Confirm summary.
 * @return {boolean} A sites or single-site archive on a network: each site gets an address.
 */
export const editable = ( summary ) =>
	Boolean( summary?.network ) && 'network' !== summary.network.kind;

/**
 * @param {Object} summary Confirm summary.
 * @return {Object} Index => the address the server holds (else its proposal).
 */
export const initialAddresses = ( summary ) =>
	Object.fromEntries(
		sitesOf( summary ).map( ( site ) => [ site.index, current( site ) ] )
	);

/**
 * @param {Object} summary   Confirm summary.
 * @param {Object} addresses Index => typed address.
 * @return {Object} Index => trimmed address, only for rows that differ from the server's.
 */
export function answerSites( summary, addresses ) {
	if ( ! editable( summary ) ) {
		return {};
	}
	const changed = {};
	sitesOf( summary ).forEach( ( site ) => {
		const typed = String(
			addresses[ site.index ] ?? current( site )
		).trim();
		if ( typed !== current( site ) ) {
			changed[ site.index ] = typed;
		}
	} );
	return changed;
}

/**
 * @param {Object}           site  One site of the summary.
 * @param {string|undefined} typed The row's text (undefined = as the server holds it).
 * @return {string} What happens at the address; '' while a typed one is unchecked.
 */
export function rowNote( site, typed = undefined ) {
	if ( undefined !== typed && typed.trim() !== current( site ) ) {
		return '';
	}
	return null === site.existing || undefined === site.existing
		? __( 'New site', 'lw-migration' )
		: __( 'Replaces an existing site', 'lw-migration' );
}
