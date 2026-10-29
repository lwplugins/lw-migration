/**
 * What each reset removes and keeps, built from `GET /reset`: the two short
 * lists of a card and of the dialog, and the drop-in notice. Counts of zero
 * are left out; a line both parts of a full reset would show appears once.
 */

/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

const count = ( value ) => Number( value ) || 0;

/**
 * A line of what stays, with the place the layers view puts it: the LW
 * Migration row (`lwm`), the WordPress row (`core`), the "you" row (`you`)
 * or the layer of that name (`plugins`, `themes`, `media`, `database`).
 *
 * @param {string} place Where it is shown.
 * @param {string} line  The translated line.
 * @return {{place: string, line: string}} Tagged line.
 */
const stay = ( place, line ) => ( { place, line } );

/**
 * @param {string[]} names Names shown.
 * @param {number}   more  How many more there are.
 * @return {string} "a, b and 3 more".
 */
export function namesWithMore( names, more ) {
	const list = ( Array.isArray( names ) ? names : [] ).join( ', ' );
	return more > 0
		? sprintf(
				/* translators: 1: names, 2: how many more */
				__( '%1$s and %2$d more', 'lw-migration' ),
				list,
				more
			)
		: list;
}

function pluginFacts( summary ) {
	const part = summary.types?.plugins;
	const plugins = count( part?.count );
	const goes = [
		0 === plugins
			? __( 'No other plugin is installed.', 'lw-migration' )
			: sprintf(
					/* translators: 1: number of plugins, 2: their names */
					_n(
						'%1$d plugin: %2$s',
						'%1$d plugins: %2$s',
						plugins,
						'lw-migration'
					),
					plugins,
					namesWithMore( part.names, count( part.more ) )
				),
	];
	if ( plugins > 0 ) {
		goes.push(
			__(
				'Their settings and tables, through their own uninstall routines',
				'lw-migration'
			)
		);
	}
	const stays = [
		stay( 'lwm', __( 'LW Migration and its backups', 'lw-migration' ) ),
	];
	( summary.keptPlugins || [] ).forEach( ( kept ) =>
		stays.push(
			stay(
				'core',
				sprintf(
					/* translators: 1: plugin file, 2: what loads it, e.g. db.php */
					__( '%1$s — loaded by %2$s', 'lw-migration' ),
					kept.plugin,
					kept.by
				)
			)
		)
	);
	const mu = count( summary.muPlugins );
	if ( mu > 0 ) {
		stays.push(
			stay(
				'core',
				sprintf(
					/* translators: %d: number of must-use plugins */
					_n(
						'%d must-use plugin',
						'%d must-use plugins',
						mu,
						'lw-migration'
					),
					mu
				)
			)
		);
	}
	return { goes, stays };
}

function themeFacts( summary ) {
	const part = summary.types?.themes;
	const target = part?.target || {};
	const themes = count( part?.count );
	const goes = [
		0 === themes
			? __( 'No other theme is installed.', 'lw-migration' )
			: sprintf(
					/* translators: 1: number of themes, 2: their names */
					_n(
						'%1$d theme: %2$s',
						'%1$d themes: %2$s',
						themes,
						'lw-migration'
					),
					themes,
					namesWithMore( part.names, count( part.more ) )
				),
	];
	let active = sprintf(
		/* translators: %s: theme name */
		__( '%s, as the active theme', 'lw-migration' ),
		target.name || target.slug || ''
	);
	if ( 'download' === target.source ) {
		active +=
			' ' + __( '(downloaded from wordpress.org first)', 'lw-migration' );
	} else if ( 'stub' === target.source ) {
		active +=
			' ' +
			__(
				'(a minimal blank theme, because none can be installed)',
				'lw-migration'
			);
	}
	return { goes, stays: [ stay( 'themes', active ) ] };
}

function mediaFacts( summary, backupsNamed ) {
	const attachments = summary.types?.media?.attachments;
	const goes = [
		null === attachments || undefined === attachments
			? __(
					'Every media item of every site and every file in the uploads folder',
					'lw-migration'
				)
			: sprintf(
					/* translators: %d: number of media items */
					_n(
						'%d media item and every file in the uploads folder',
						'%d media items and every file in the uploads folder',
						count( attachments ),
						'lw-migration'
					),
					count( attachments )
				),
		__(
			'Files other plugins keep there (caches, form uploads, downloads)',
			'lw-migration'
		),
	];
	const stays = [
		stay(
			'media',
			backupsNamed
				? __( "The uploads folder's protection files", 'lw-migration' )
				: __(
						"The uploads folder's protection files and your backups",
						'lw-migration'
					)
		),
	];
	return { goes, stays };
}

export function connectionLines( part ) {
	const lines = [];
	const connections = count( part.connections );
	const schedules = count( part.schedules );
	if ( connections > 0 ) {
		lines.push(
			sprintf(
				/* translators: %d: number of connections */
				_n(
					'%d connection made here',
					'%d connections made here',
					connections,
					'lw-migration'
				),
				connections
			)
		);
	}
	if ( schedules > 0 ) {
		lines.push(
			sprintf(
				/* translators: %d: number of schedules */
				_n( '%d schedule', '%d schedules', schedules, 'lw-migration' ),
				schedules
			)
		);
	}
	return lines;
}

/** @return {string} The line about LW Migration's own settings. */
export const settingsLine = () =>
	__( "LW Migration's settings and transfer links", 'lw-migration' );

function databaseFacts( summary ) {
	const part = summary.types?.database || {};
	const tables = part.tables || {};
	const others = count( part.otherUsers );
	const retired = count( tables.retired );
	const goes = [
		__(
			'All content: posts, pages, comments, menus and settings',
			'lw-migration'
		),
	];
	if ( others > 0 ) {
		goes.push(
			sprintf(
				/* translators: %d: number of other users */
				_n( '%d other user', '%d other users', others, 'lw-migration' ),
				others
			)
		);
	}
	goes.push(
		__(
			'Your application passwords and other personal settings',
			'lw-migration'
		)
	);
	if ( retired > 0 ) {
		goes.push(
			sprintf(
				/* translators: 1: number of tables, 2: their names */
				_n(
					'%1$d other table: %2$s',
					'%1$d other tables: %2$s',
					retired,
					'lw-migration'
				),
				retired,
				namesWithMore( tables.retiredNames, count( tables.more ) )
			)
		);
	}
	if ( part.prefixWarning ) {
		goes.push(
			__(
				"This site's table prefix has no underscore at its end, so tables of other software that start with it would be removed too — check the list.",
				'lw-migration'
			)
		);
	}
	goes.push(
		__( 'Connections made by other admins stop working', 'lw-migration' )
	);
	const stays = [
		stay(
			'you',
			sprintf(
				/* translators: %s: your login */
				__(
					'You (%s): login, password, profile and sessions',
					'lw-migration'
				),
				part.user || ''
			)
		),
		stay( 'lwm', settingsLine() ),
		...connectionLines( part ).map( ( line ) => stay( 'lwm', line ) ),
		stay(
			'you',
			__( 'The site address and the security keys', 'lw-migration' )
		),
	];
	const kept = count( tables.kept );
	if ( kept > 0 ) {
		stays.push(
			stay(
				'core',
				sprintf(
					/* translators: %d: number of tables */
					_n(
						'%d table of another installation in this database',
						'%d tables of another installation in this database',
						kept,
						'lw-migration'
					),
					kept
				)
			)
		);
	}
	return { goes, stays };
}

/**
 * What one layer removes and keeps, with the place of each kept line.
 *
 * @param {string}  layer        `plugins`, `themes`, `media` or `database`.
 * @param {Object}  summary      `GET /reset`.
 * @param {boolean} backupsNamed Whether the backups are already named elsewhere.
 * @return {{goes: string[], stays: {place: string, line: string}[]}} Lines.
 */
export function layerFacts( layer, summary, backupsNamed = false ) {
	const s = summary || {};
	if ( 'plugins' === layer ) {
		return pluginFacts( s );
	}
	if ( 'themes' === layer ) {
		return themeFacts( s );
	}
	if ( 'media' === layer ) {
		return mediaFacts( s, backupsNamed );
	}
	if ( 'database' === layer ) {
		return databaseFacts( s );
	}
	return { goes: [], stays: [] };
}

/**
 * What a reset removes and keeps, from the summary.
 *
 * @param {string} type    Reset type.
 * @param {Object} summary `GET /reset`.
 * @return {{goes: string[], stays: string[]}} Translated lines.
 */
export function cardFacts( type, summary ) {
	const s = summary || {};
	const lines = ( facts ) => ( {
		goes: facts.goes,
		stays: facts.stays.map( ( kept ) => kept.line ),
	} );
	if ( 'database' === type ) {
		const facts = lines( databaseFacts( s ) );
		facts.stays.push(
			__(
				'Plugins and themes stay on disk; only LW Migration stays active',
				'lw-migration'
			)
		);
		return facts;
	}
	if ( 'full' !== type ) {
		return lines( layerFacts( type, s ) );
	}
	// The plugins part already names the backups.
	const parts = [
		pluginFacts( s ),
		themeFacts( s ),
		mediaFacts( s, true ),
		databaseFacts( s ),
	].map( lines );
	const unique = ( list ) => [ ...new Set( list ) ];
	return {
		goes: unique( parts.flatMap( ( part ) => part.goes ) ),
		stays: unique( parts.flatMap( ( part ) => part.stays ) ),
	};
}

/**
 * The drop-in notice (plugins and full resets): drop-ins stay, one of them
 * may belong to a deleted plugin, and a page cache may serve old pages.
 *
 * @param {string[]} dropIns Drop-in file names present.
 * @return {string} Text, '' without drop-ins.
 */
export function dropInNote( dropIns ) {
	if ( ! Array.isArray( dropIns ) || ! dropIns.length ) {
		return '';
	}
	return sprintf(
		/* translators: %s: drop-in file names, e.g. object-cache.php */
		__(
			'Drop-ins stay: %s. If one of these belonged to a deleted plugin, remove it by hand. A page cache may still serve the old pages: empty it.',
			'lw-migration'
		),
		dropIns.join( ', ' )
	);
}
