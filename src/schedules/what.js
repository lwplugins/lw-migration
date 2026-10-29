/**
 * What a schedule backs up, without React: the four parts the card and the
 * editor show, the scope on a network (the whole network or chosen sites),
 * and the phrase the live sentence uses as its object. Each phrase is whole
 * (scope and parts together), so every language can word it.
 */

/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { NETWORK } from '../data/boot';
import { pageLocale } from '../data/format';
import { listText } from '../overview/status';

const ON_NETWORK = Boolean( NETWORK );

/**
 * The four parts of a backup the card and the editor show, with the flag
 * that leaves each out (exportRequest.js FLAG_KEYS).
 *
 * @return {{key: string, flag: string, label: string}[]} Parts.
 */
export const parts = () => [
	{
		key: 'database',
		flag: 'noDatabase',
		label: __( 'Database', 'lw-migration' ),
	},
	{ key: 'media', flag: 'noMedia', label: __( 'Media', 'lw-migration' ) },
	{
		key: 'plugins',
		flag: 'noPlugins',
		label: __( 'Plugins', 'lw-migration' ),
	},
	{ key: 'themes', flag: 'noThemes', label: __( 'Themes', 'lw-migration' ) },
];

/**
 * What a run covers: this site, the whole network, or chosen sites (a
 * network schedule with `export.sites`; the form also keeps `scope`).
 *
 * @param {Object}  options Export state (form or item).
 * @param {boolean} network The page runs on a network.
 * @return {{kind: 'site'|'network'|'sites', count: number}} Scope.
 */
export function scopeOf( options, network = ON_NETWORK ) {
	if ( ! network ) {
		return { kind: 'site', count: 1 };
	}
	const sites = options.sites || [];
	const chosen =
		'sites' === options.scope ||
		( undefined === options.scope && sites.length > 0 );
	return chosen
		? { kind: 'sites', count: sites.length }
		: { kind: 'network', count: 0 };
}

/**
 * Everything of the scope ("the whole site").
 *
 * @param {Object} scope scopeOf().
 * @return {string} Phrase, the object of the timing sentence.
 */
function wholePhrase( scope ) {
	if ( 'network' === scope.kind ) {
		/* translators: The object of "Every day at 03:00, it backs up …". */
		return __( 'the whole network', 'lw-migration' );
	}
	if ( 'sites' === scope.kind ) {
		return sprintf(
			/* translators: %d: number of sites. The object of "Every day at 03:00, it backs up …". */
			_n(
				'the %d chosen site',
				'the %d chosen sites',
				scope.count,
				'lw-migration'
			),
			scope.count
		);
	}
	/* translators: The object of "Every day at 03:00, it backs up …". */
	return __( 'the whole site', 'lw-migration' );
}

/**
 * Only the database of the scope.
 *
 * @param {Object} scope scopeOf().
 * @return {string} Phrase, the object of the timing sentence.
 */
function databasePhrase( scope ) {
	if ( 'network' === scope.kind ) {
		/* translators: The object of "Every day at 03:00, it backs up …". */
		return __( 'only the database of the whole network', 'lw-migration' );
	}
	if ( 'sites' === scope.kind ) {
		return sprintf(
			/* translators: %d: number of sites. The object of "Every day at 03:00, it backs up …". */
			_n(
				'only the database of the %d chosen site',
				'only the database of the %d chosen sites',
				scope.count,
				'lw-migration'
			),
			scope.count
		);
	}
	/* translators: The object of "Every day at 03:00, it backs up …". */
	return __( 'only the database', 'lw-migration' );
}

/**
 * Some parts of the scope.
 *
 * @param {Object} scope scopeOf().
 * @param {string} list  The parts, e.g. "media and plugins".
 * @return {string} Phrase, the object of the timing sentence.
 */
function partsPhrase( scope, list ) {
	if ( 'network' === scope.kind ) {
		return sprintf(
			/* translators: %s: list of parts, e.g. media and plugins. The object of "Every day at 03:00, it backs up …". */
			__( 'these parts of the whole network: %s', 'lw-migration' ),
			list
		);
	}
	if ( 'sites' === scope.kind ) {
		return sprintf(
			/* translators: 1: number of sites; 2: list of parts, e.g. media and plugins. The object of "Every day at 03:00, it backs up …". */
			_n(
				'these parts of the %1$d chosen site: %2$s',
				'these parts of the %1$d chosen sites: %2$s',
				scope.count,
				'lw-migration'
			),
			scope.count,
			list
		);
	}
	return sprintf(
		/* translators: %s: list of parts, e.g. database and media. The object of "Every day at 03:00, it backs up …". */
		__( 'these parts: %s', 'lw-migration' ),
		list
	);
}

/**
 * @param {Object}  options Export state (flags; on a network scope and sites).
 * @param {string}  locale  BCP 47 tag.
 * @param {boolean} network The page runs on a network (default: the boot data).
 * @return {string} What a run backs up, as the object of the timing sentence.
 */
export function whatPhrase(
	options,
	locale = pageLocale(),
	network = ON_NETWORK
) {
	const included = parts().filter( ( part ) => ! options[ part.flag ] );
	if ( 0 === included.length ) {
		/* translators: The object of "Every day at 03:00, it backs up …". */
		return __( 'nothing yet (choose at least one part)', 'lw-migration' );
	}
	const scope = scopeOf( options, network );
	if ( 'sites' === scope.kind && 0 === scope.count ) {
		/* translators: The object of "Every day at 03:00, it backs up …". */
		return __( 'no site yet (choose at least one)', 'lw-migration' );
	}
	if ( 4 === included.length ) {
		return wholePhrase( scope );
	}
	if ( 1 === included.length && 'database' === included[ 0 ].key ) {
		return databasePhrase( scope );
	}
	return partsPhrase(
		scope,
		listText(
			included.map( ( part ) => part.label.toLocaleLowerCase( locale ) ),
			locale
		)
	);
}

/**
 * The card's scope line on a network ('' on a single site).
 *
 * @param {Object}  options Item export state.
 * @param {boolean} network The page runs on a network.
 * @return {string} "The whole network" | "2 chosen sites" | ''.
 */
export function scopeLabel( options, network = ON_NETWORK ) {
	const scope = scopeOf( options, network );
	if ( 'site' === scope.kind ) {
		return '';
	}
	return 'network' === scope.kind
		? __( 'The whole network', 'lw-migration' )
		: sprintf(
				/* translators: %d: number of sites. */
				_n(
					'%d chosen site',
					'%d chosen sites',
					scope.count,
					'lw-migration'
				),
				scope.count
			);
}
