/**
 * "What gets replaced" without React: one state per tile, derived from the
 * summary's `parts` (the archive's contents), its network and its subsite —
 * never from `parts` alone:
 * - a part the archive does not hold is kept (`absent`);
 * - a sites or single-site archive restored into a network never writes
 *   plugins, themes or must-use plugins (`shared`: the network's stay), and
 *   its database and media are only the archive's sites (scope `sites`);
 * - a network site restored into a single site brings only that site's
 *   media (scope `site`).
 * Must-use plugins go with the plugins tile. Sizes are what a part takes in
 * the archive: for a compressed or encrypted one (no `expandedSize`) the
 * stored size, said so.
 */

/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { formatBytes, formatNumber } from '../data/format';

export const PART_IDS = [ 'database', 'media', 'plugins', 'themes' ];

const CODE = [ 'plugins', 'themes', 'muPlugins' ];

/**
 * Whether the archive holds a part: true, false, or null (unknown). A
 * summary of an older version has only the `overwrites` flags.
 *
 * @param {Object} summary Confirm summary.
 * @param {string} id      Part key.
 * @return {boolean|null} Included.
 */
export function included( summary, id ) {
	const part = summary.parts?.[ id ];
	if ( part && 'included' in part ) {
		return part.included;
	}
	const flag = summary.overwrites?.[ id ];
	return 'boolean' === typeof flag ? flag : null;
}

/**
 * @param {Object} summary Confirm summary.
 * @param {string} id      Part key.
 * @return {string} replaced|absent|shared|unknown.
 */
function stateOf( summary, id ) {
	const has = included( summary, id );
	if ( false === has ) {
		return 'absent';
	}
	const sitesIntoNetwork =
		Boolean( summary.network ) && 'network' !== summary.network.kind;
	if ( sitesIntoNetwork && CODE.includes( id ) ) {
		return 'shared';
	}
	return null === has ? 'unknown' : 'replaced';
}

/**
 * @param {Object} summary Confirm summary.
 * @param {string} id      Part key.
 * @return {string} '' | sites | site.
 */
function scopeOf( summary, id ) {
	if (
		summary.network &&
		'network' !== summary.network.kind &&
		[ 'database', 'media' ].includes( id )
	) {
		return 'sites';
	}
	const subsite = null !== summary.subsite && undefined !== summary.subsite;
	return subsite && 'media' === id ? 'site' : '';
}

const numberOr = ( value ) => ( Number.isFinite( value ) ? value : null );

/**
 * @param {Object} summary Confirm summary.
 * @return {Object[]} `{ id, state, scope, bytes, count, stored, mustUse }`
 *                    per tile; mustUse (plugins only) `{ state, count }` or null.
 */
export function partStates( summary ) {
	const stored = ! summary.expandedSize;
	return PART_IDS.map( ( id ) => {
		const part = summary.parts?.[ id ] || {};
		const mu = summary.parts?.muPlugins;
		return {
			id,
			state: stateOf( summary, id ),
			scope: scopeOf( summary, id ),
			bytes: numberOr( part.bytes ),
			count: 'database' === id ? null : numberOr( part.count ),
			stored,
			mustUse:
				'plugins' === id && mu
					? {
							state: stateOf( summary, 'muPlugins' ),
							count: numberOr( mu.count ),
						}
					: null,
		};
	} );
}

/**
 * @param {Object} part One of partStates().
 * @return {string} How many there are ('' when unknown).
 */
function countText( part ) {
	if ( null === part.count ) {
		return '';
	}
	const n = formatNumber( part.count );
	if ( 'media' === part.id ) {
		return sprintf(
			/* translators: %s: number of media files. */
			_n( '%s file', '%s files', part.count, 'lw-migration' ),
			n
		);
	}
	if ( 'plugins' === part.id ) {
		return sprintf(
			/* translators: %s: number of plugins. */
			_n( '%s plugin', '%s plugins', part.count, 'lw-migration' ),
			n
		);
	}
	return sprintf(
		/* translators: %s: number of themes. */
		_n( '%s theme', '%s themes', part.count, 'lw-migration' ),
		n
	);
}

/**
 * @param {Object} part One of partStates().
 * @return {string} The part's size, the stored one said so ('' when unknown).
 */
function sizeText( part ) {
	if ( null === part.bytes ) {
		return '';
	}
	return part.stored
		? sprintf(
				/* translators: %s: size the part takes in a compressed or encrypted archive. */
				__( 'stored: %s', 'lw-migration' ),
				formatBytes( part.bytes )
			)
		: formatBytes( part.bytes );
}

/**
 * @param {Object|null} mu The plugins tile's mustUse.
 * @return {string} Must-use plugins replaced with it ('' = none or not known).
 */
function mustUseText( mu ) {
	if ( ! mu || 'replaced' !== mu.state || ! mu.count ) {
		return '';
	}
	return sprintf(
		/* translators: %s: number of must-use plugins. */
		__( '%s must-use', 'lw-migration' ),
		formatNumber( mu.count )
	);
}

/**
 * The line under a tile.
 *
 * @param {Object}  part            One of partStates().
 * @param {boolean} keepsThisPlugin The summary's keepsThisPlugin.
 * @return {string} Text.
 */
export function partLine( part, keepsThisPlugin ) {
	if (
		'absent' === part.state &&
		'plugins' === part.id &&
		'replaced' === part.mustUse?.state
	) {
		// A backup made without plugins can still hold must-use plugins.
		return __(
			'The current plugins stay. The must-use plugins are replaced.',
			'lw-migration'
		);
	}
	if ( 'absent' === part.state ) {
		return __( 'The current ones stay.', 'lw-migration' );
	}
	if ( 'shared' === part.state ) {
		return __( "The network's stay as they are.", 'lw-migration' );
	}
	if ( 'unknown' === part.state ) {
		return __( 'Could not be read from the archive.', 'lw-migration' );
	}
	if ( 'site' === part.scope ) {
		return __( "Only that network site's media.", 'lw-migration' );
	}
	return [
		'sites' === part.scope
			? __( "Only the archive's sites", 'lw-migration' )
			: '',
		countText( part ),
		'plugins' === part.id ? mustUseText( part.mustUse ) : '',
		sizeText( part ),
		'plugins' === part.id && keepsThisPlugin
			? __( 'this plugin stays', 'lw-migration' )
			: '',
	]
		.filter( Boolean )
		.join( ' · ' );
}
