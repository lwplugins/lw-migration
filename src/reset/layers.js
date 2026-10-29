/**
 * The Reset tab's two summaries without React: the layers of the site (what
 * goes and what stays, per type), the count a choice tile shows and whether
 * the steps before the start are done. Lines come from resetFacts.js; a
 * count the server does not know falls back to a plain line.
 */

/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { formatNumber, pageLocale } from '../data/format';
import {
	connectionLines,
	dropInNote,
	layerFacts,
	settingsLine,
} from './resetFacts';

/** The four layers, in the order the tab shows them. */
export const LAYERS = [ 'plugins', 'themes', 'media', 'database' ];

const known = ( value ) =>
	null !== value && undefined !== value && ! Number.isNaN( Number( value ) );

/**
 * @param {string} layer   Layer key.
 * @param {Object} summary `GET /reset`.
 * @return {string} The one line a layer that stays shows.
 */
function staysLine( layer, summary ) {
	const part = summary?.types?.[ layer ];
	if ( 'plugins' === layer ) {
		return known( part?.count )
			? sprintf(
					/* translators: %d: number of plugins */
					_n(
						'%d plugin, it stays',
						'%d plugins, all stay',
						Number( part.count ),
						'lw-migration'
					),
					Number( part.count )
				)
			: __( 'The plugins stay', 'lw-migration' );
	}
	if ( 'themes' === layer ) {
		return known( part?.count )
			? sprintf(
					/* translators: %d: number of themes */
					_n(
						'%d theme, it stays',
						'%d themes, all stay',
						Number( part.count ),
						'lw-migration'
					),
					Number( part.count )
				)
			: __( 'The themes stay', 'lw-migration' );
	}
	if ( 'media' === layer ) {
		return known( part?.attachments )
			? sprintf(
					/* translators: %s: number of media items, already formatted */
					_n(
						'%s media item, it stays',
						'%s media items, all stay',
						Number( part.attachments ),
						'lw-migration'
					),
					formatNumber( part.attachments )
				)
			: __( 'The media of every site stays', 'lw-migration' );
	}
	if ( false === part?.available || ! known( part?.otherUsers ) ) {
		return __( 'All content and users stay', 'lw-migration' );
	}
	const users = Number( part.otherUsers ) + 1;
	return sprintf(
		/* translators: %d: number of users, you included */
		_n(
			'Content and %d user stay',
			'Content and %d users stay',
			users,
			'lw-migration'
		),
		users
	);
}

/**
 * A database reset builds a fresh install: the plugins stay on disk but
 * only LW Migration stays active, and the media library is emptied while
 * the files stay in the uploads folder.
 *
 * @param {string} layer   `plugins` or `media`.
 * @param {Object} summary `GET /reset`.
 * @return {string[]} The lines of that layer.
 */
function diskLines( layer, summary ) {
	const part = summary?.types?.[ layer ];
	if ( 'plugins' === layer ) {
		return [
			known( part?.count )
				? sprintf(
						/* translators: %d: number of plugins */
						_n(
							'%d plugin stays on disk, switched off',
							'%d plugins stay on disk, switched off',
							Number( part.count ),
							'lw-migration'
						),
						Number( part.count )
					)
				: __(
						'The plugins stay on disk, switched off',
						'lw-migration'
					),
			__( 'Only LW Migration stays active', 'lw-migration' ),
		];
	}
	return [
		known( part?.attachments ) && Number( part.attachments ) > 0
			? sprintf(
					/* translators: %s: number of media items, already formatted */
					_n(
						'%s media item: the files stay on disk, but the library is emptied',
						'%s media items: the files stay on disk, but the library is emptied',
						Number( part.attachments ),
						'lw-migration'
					),
					formatNumber( part.attachments )
				)
			: __(
					'The files stay on disk, but the library is emptied',
					'lw-migration'
				),
	];
}

/**
 * @return {Object<string, string>} The fixed title of each base row.
 */
const baseTitles = () => ( {
	lwm: __( 'LW Migration and your backups', 'lw-migration' ),
	core: __( 'WordPress core and wp-config.php', 'lw-migration' ),
	you: __( 'You (admin)', 'lw-migration' ),
} );

/**
 * What goes and what stays on each layer for a reset of this type.
 *
 * @param {string} type    Reset type (`plugins`, `themes`, `media`, `database`, `full`).
 * @param {Object} summary `GET /reset`.
 * @return {{
 *   layers: {key: string, goes: boolean, disk: boolean, lines: string[]}[],
 *   base: {key: 'lwm'|'core'|'you', title: string, lines: string[]}[],
 *   note: string
 * }} `layers` has the four layers in order. A layer that goes lists what
 *   goes and, after it, what it leaves; one that stays has a single line.
 *   `disk`: the layer stays on disk only (plugins switched off, the media
 *   library emptied), which a database reset alone does. `base` is what no
 *   reset touches, each row under its title (`you` only when the database
 *   goes). `note` is the drop-in notice, '' unless plugins go.
 */
export function resetLayers( type, summary ) {
	const s = summary || {};
	const going =
		'full' === type ? LAYERS : LAYERS.filter( ( k ) => k === type );
	const seen = new Set();
	const once = ( line ) => {
		if ( ! line || seen.has( line ) ) {
			return false;
		}
		seen.add( line );
		return true;
	};
	const rows = { lwm: [], core: [], you: [] };
	const layers = LAYERS.map( ( key ) => {
		if ( 'database' === type && ( 'plugins' === key || 'media' === key ) ) {
			return {
				key,
				goes: false,
				disk: true,
				lines: diskLines( key, s ).filter( once ),
			};
		}
		if ( ! going.includes( key ) ) {
			return {
				key,
				goes: false,
				disk: false,
				lines: [ staysLine( key, s ) ],
			};
		}
		const facts = layerFacts( key, s, 'full' === type );
		const lines = facts.goes.filter( once );
		facts.stays.forEach( ( kept ) => {
			if ( rows[ kept.place ] && once( kept.line ) ) {
				rows[ kept.place ].push( kept.line );
			} else if ( ! rows[ kept.place ] && once( kept.line ) ) {
				lines.push( kept.line );
			}
		} );
		return { key, goes: true, disk: false, lines };
	} );
	// The settings and links of LW Migration stay whatever goes.
	[ settingsLine(), ...connectionLines( s.types?.database || {} ) ].forEach(
		( line ) => {
			if ( once( line ) ) {
				rows.lwm.push( line );
			}
		}
	);
	const core = [ ...rows.core ];
	if ( Array.isArray( s.dropIns ) && s.dropIns.length ) {
		core.push(
			sprintf(
				/* translators: %s: drop-in file names, e.g. object-cache.php */
				__( 'Drop-ins: %s', 'lw-migration' ),
				s.dropIns.join( ', ' )
			)
		);
	}
	const titles = baseTitles();
	const base = [
		{ key: 'lwm', title: titles.lwm, lines: rows.lwm },
		{ key: 'core', title: titles.core, lines: core },
	];
	if ( going.includes( 'database' ) ) {
		base.push( { key: 'you', title: titles.you, lines: rows.you } );
	}
	return {
		layers,
		base,
		note: going.includes( 'plugins' ) ? dropInNote( s.dropIns ) : '',
	};
}

/**
 * The count a choice tile shows under its title.
 *
 * @param {string} type    Reset type.
 * @param {Object} summary `GET /reset`.
 * @param {string} locale  BCP 47 tag for the media count (default: the page language).
 * @return {string} "14 plugins", "3 themes", "1,284 media items" ...
 */
export function choiceScope( type, summary, locale = pageLocale() ) {
	const types = summary?.types || {};
	if ( 'plugins' === type ) {
		const n = types.plugins?.count;
		return known( n )
			? sprintf(
					/* translators: %d: number of plugins */
					_n(
						'%d plugin',
						'%d plugins',
						Number( n ),
						'lw-migration'
					),
					Number( n )
				)
			: __( 'the plugins', 'lw-migration' );
	}
	if ( 'themes' === type ) {
		const n = types.themes?.count;
		return known( n )
			? sprintf(
					/* translators: %d: number of themes */
					_n( '%d theme', '%d themes', Number( n ), 'lw-migration' ),
					Number( n )
				)
			: __( 'the themes', 'lw-migration' );
	}
	if ( 'media' === type ) {
		const n = types.media?.attachments;
		return known( n )
			? sprintf(
					/* translators: %s: number of media items, already formatted */
					_n(
						'%s media item',
						'%s media items',
						Number( n ),
						'lw-migration'
					),
					formatNumber( n, locale )
				)
			: __( "every site's media", 'lw-migration' );
	}
	if ( 'database' === type ) {
		return __( 'all content', 'lw-migration' );
	}
	return 'full' === type ? __( 'all four', 'lw-migration' ) : '';
}
