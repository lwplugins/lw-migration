/**
 * The backup form's request, without React: what the Backup section sends to
 * `POST /jobs` and what it refuses before sending. Only the keys of
 * Rest\JobInput::exportOptions() leave the browser (an unknown key is a 400).
 * On a network the form also holds the scope: the whole network (the
 * server's default, nothing sent) or the chosen sites (`sites`).
 */

/**
 * Internal dependencies
 */
import { NETWORK } from '../data/boot';
import { scopeRequest } from '../network/sitePicker';

const ON_NETWORK = Boolean( NETWORK );

/** Export\ExportOptions::FLAGS values (pinned by exportRequest.test.js). */
export const FLAG_KEYS = [
	'noMedia',
	'noThemes',
	'noInactiveThemes',
	'noPlugins',
	'noInactivePlugins',
	'noMustUsePlugins',
	'noCache',
	'noDatabase',
	'noSpamComments',
	'noPostRevisions',
	'noEmailReplace',
];

/**
 * Form state; `compression` 'none' stands for the server's ''. The web forms
 * leave the cache folders out by default (they are not needed for a restore);
 * the CLI keeps its own default (everything in).
 */
export const DEFAULTS = {
	...Object.fromEntries( FLAG_KEYS.map( ( key ) => [ key, false ] ) ),
	noCache: true,
	compression: 'none',
	encrypt: false,
	excludeFiles: '',
	excludeTables: '',
	includeTables: '',
	replace: [],
	// Network only: 'network' or 'sites', the chosen ids, and their labels for the chips.
	scope: 'network',
	sites: [],
	siteLabels: {},
	// Where the backup goes besides the backups folder: destination ids, and whether the local copy stays.
	destinations: [],
	keepLocal: true,
};

const lines = ( text ) =>
	String( text || '' )
		.split( /\r?\n/ )
		.map( ( line ) => line.trim() )
		.filter( Boolean );

const blank = ( value ) => '' === String( value || '' ).trim();

/**
 * Same reading as ExportOptions::relativePath(): inside wp-content, no "." or
 * ".." segment, not empty once the slashes are trimmed.
 *
 * @param {string} line One excluded path.
 * @return {boolean} Whether the server takes it.
 */
const insideContent = ( line ) => {
	const path = line
		.replace( /\\/g, '/' )
		.replace( /\/+/g, '/' )
		.replace( /^\/|\/$/g, '' );
	const segments = path.split( '/' );
	return (
		'' !== path && ! segments.includes( '..' ) && ! segments.includes( '.' )
	);
};

/**
 * @param {Object}  o       Form state.
 * @param {boolean} network The page runs on a network.
 * @return {Object} `{ sites }` for chosen sites on a network, else nothing.
 */
const scopeOf = ( o, network ) => {
	const sites = network ? scopeRequest( o.scope, o.sites || [] ) : [];
	return sites?.length ? { sites } : {};
};

/**
 * The request's "options" object.
 *
 * @param {Object}  o       Form state.
 * @param {boolean} network The page runs on a network (default: the boot data).
 * @return {Object} Options with only the allowlisted keys.
 */
export const toRequest = ( o, network = ON_NETWORK ) => ( {
	...Object.fromEntries(
		FLAG_KEYS.map( ( key ) => [ key, Boolean( o[ key ] ) ] )
	),
	compression: 'none' === o.compression ? '' : o.compression,
	encrypt: Boolean( o.encrypt ),
	excludeFiles: lines( o.excludeFiles ),
	excludeTables: lines( o.excludeTables ),
	includeTables: lines( o.includeTables ),
	// A row nobody filled in is not a pair; a half-filled one never gets here (checkForm).
	replace: o.replace
		.filter( ( [ from, to ] ) => ! blank( from ) || ! blank( to ) )
		.map( ( [ from, to ] ) => [ from.trim(), to.trim() ] ),
	...scopeOf( o, network ),
} );

/**
 * What the server would refuse (or what would lose the backup), checked
 * before sending: a pair with one blank side (the server refuses it, R15), an
 * excluded path outside wp-content (the server's refusal names no field), a
 * missing or mistyped password, chosen sites with none chosen.
 *
 * @param {Object}  o        Form state.
 * @param {string}  password Password.
 * @param {string}  again    Password repeated.
 * @param {boolean} network  The page runs on a network (default: the boot data).
 * @return {Object|null} `{ field, reason, index? }`, or null when it can go.
 */
export function checkForm( o, password, again, network = ON_NETWORK ) {
	if ( network && null === scopeRequest( o.scope, o.sites || [] ) ) {
		return { field: 'sites', reason: 'empty' };
	}
	const half = o.replace.findIndex(
		( [ from, to ] ) => blank( from ) !== blank( to )
	);
	if ( -1 !== half ) {
		return { field: 'replace', reason: 'pair', index: half };
	}
	if ( ! lines( o.excludeFiles ).every( insideContent ) ) {
		return { field: 'excludeFiles', reason: 'path' };
	}
	if ( o.encrypt && '' === password ) {
		return { field: 'password', reason: 'empty' };
	}
	if ( o.encrypt && password !== again ) {
		return { field: 'password', reason: 'mismatch' };
	}
	return null;
}

/**
 * How many settings differ from the defaults (shown while the section is folded).
 *
 * @param {Object} o Form state.
 * @return {number} Count.
 */
export const countChanged = ( o ) => {
	const options = toRequest( o, false ); // The scope is not one of the folded options.
	return (
		FLAG_KEYS.filter( ( key ) => options[ key ] !== DEFAULTS[ key ] )
			.length +
		[ 'compression', 'encrypt' ].filter( ( key ) => options[ key ] )
			.length +
		[ 'excludeFiles', 'excludeTables', 'includeTables', 'replace' ].filter(
			( key ) => options[ key ].length
		).length
	);
};

/**
 * The cloud keys of the request, next to `options`: nothing without a chosen
 * destination (the server refuses `keepLocal` alone), `keepLocal` only with one.
 *
 * @param {Object} o Form state.
 * @return {Object} `{ destinations, keepLocal }` or nothing.
 */
const cloudOf = ( o ) =>
	o.destinations?.length
		? {
				destinations: o.destinations,
				...( false === o.keepLocal ? { keepLocal: false } : {} ),
			}
		: {};

/**
 * The `POST /jobs` body. The password goes only into this one request (never
 * trimmed, never stored by the page).
 *
 * @param {Object}  o        Form state.
 * @param {string}  password Password.
 * @param {boolean} network  The page runs on a network (default: the boot data).
 * @return {Object} Body.
 */
export const buildRequest = ( o, password, network = ON_NETWORK ) => ( {
	type: 'export',
	options: toRequest( o, network ),
	...cloudOf( o ),
	...( o.encrypt ? { password } : {} ),
} );
