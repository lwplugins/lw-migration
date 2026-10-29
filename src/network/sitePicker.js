/**
 * The site picker's logic, without React: which sites are chosen, how many
 * pages the list has, what a backup request carries for the scope, and how
 * a site is named in the list and on its chip.
 */

/** Sites per page (Rest\NetworkApi::PER_PAGE). */
export const PER_PAGE = 20;

/**
 * @param {number[]} chosen Chosen site ids.
 * @param {number}   id     Site id.
 * @return {number[]} A new list with the site added (at the end) or removed.
 */
export const toggle = ( chosen, id ) =>
	chosen.includes( id )
		? chosen.filter( ( item ) => item !== id )
		: [ ...chosen, id ];

/**
 * @param {number} total   Sites found.
 * @param {number} perPage Sites per page.
 * @return {number} Pages, at least one.
 */
export const pageCount = ( total, perPage ) =>
	Math.max( 1, Math.ceil( total / perPage ) );

/**
 * The `sites` of a backup request (Rest\NetworkInput::siteIds()).
 *
 * @param {string}   scope  'network' or 'sites'.
 * @param {number[]} chosen Chosen site ids.
 * @return {number[]|null} [] = the whole network; null = chosen sites with none chosen (not sendable).
 */
export function scopeRequest( scope, chosen ) {
	if ( 'sites' !== scope ) {
		return [];
	}
	return chosen.length ? [ ...chosen ] : null;
}

/**
 * @param {Object} site `{ url }` of the site list.
 * @return {string} The address without the scheme and the trailing slash.
 */
export const label = ( site ) =>
	String( site.url || '' )
		.replace( /^[a-z][a-z0-9+.-]*:\/\//i, '' )
		.replace( /\/+$/, '' );

/**
 * Labels of the sites seen so far, so a chip keeps its name on other pages.
 *
 * @param {Object}   labels Id => label.
 * @param {Object[]} items  One page of the site list.
 * @return {Object} New id => label.
 */
export const remember = ( labels, items ) => ( {
	...labels,
	...Object.fromEntries(
		items.map( ( site ) => [ site.id, label( site ) ] )
	),
} );
