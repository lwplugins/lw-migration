/**
 * The "add a destination" shelf and the live preview of where a draft will
 * keep its files, without React. The shelf groups the server's types (cloud
 * accounts, key-based storage, servers); the preview mirrors each type's
 * PHP describe() so a draft reads like the card it becomes.
 */

/**
 * Internal dependencies
 */
import { SITE_FOLDER } from '../data/boot';

/** The server types, in shelf order. */
const SERVER_TYPES = [ 'sftp', 'ftp', 'webdav' ];

const text = ( value ) => String( value ?? '' ).trim();

/**
 * An IPv6 address in brackets, as in a URL (FtpType::bracketed(),
 * SftpType::where()).
 *
 * @param {string} host Host as typed.
 * @return {string} Host.
 */
const bracketed = ( host ) => ( host.includes( ':' ) ? `[${ host }]` : host );

/**
 * The shelf: one tile per cloud account, one per S3 provider, one per
 * server type. A type the server does not know and that has no `oauth`
 * block joins the servers.
 *
 * @param {Object[]} types Schema types (GET /destinations `types`).
 * @return {{key: string, tiles: Object[]}[]} Groups with at least one tile.
 */
export function shelfGroups( types ) {
	const tile = ( type, extra = {} ) => ( {
		typeKey: type.type,
		preset: undefined,
		label: type.label,
		type: type.type,
		available: type.available,
		reason: type.reason ?? '',
		...extra,
	} );
	const oauth = [];
	const keys = [];
	const servers = [];
	for ( const type of types ) {
		if ( type.oauth ) {
			oauth.push( tile( type ) );
		} else if ( 's3' === type.type ) {
			const options =
				type.fields?.find( ( field ) => 'preset' === field.key )
					?.options ?? [];
			for ( const option of options ) {
				keys.push(
					tile( type, {
						preset: option.value,
						label: option.label,
					} )
				);
			}
		} else {
			servers.push( tile( type ) );
		}
	}
	servers.sort( ( a, b ) => rank( a.typeKey ) - rank( b.typeKey ) );

	return [
		{ key: 'oauth', tiles: oauth },
		{ key: 'keys', tiles: keys },
		{ key: 'servers', tiles: servers },
	].filter( ( group ) => group.tiles.length > 0 );
}

/**
 * Known servers in SERVER_TYPES order, the unknown ones after them.
 *
 * @param {string} key Type key.
 * @return {number} Sort rank.
 */
const rank = ( key ) => {
	const index = SERVER_TYPES.indexOf( key );
	return index < 0 ? SERVER_TYPES.length : index;
};

/**
 * @param {Object} schemaType The S3 type's schema.
 * @param {string} preset     Provider key.
 * @return {string} The provider's label ('' when unknown).
 */
export const presetLabel = ( schemaType, preset ) =>
	schemaType?.fields
		?.find( ( field ) => 'preset' === field.key )
		?.options?.find( ( option ) => option.value === preset )?.label ?? '';

/**
 * A draft named after its provider follows the provider select; a name the
 * user typed stays.
 *
 * @param {Object} form       Form state (name, settings.preset).
 * @param {Object} schemaType The S3 type's schema.
 * @param {string} preset     The provider chosen now.
 * @return {string} The name to keep.
 */
export function nameForPreset( form, schemaType, preset ) {
	const before = presetLabel( schemaType, form.settings?.preset );
	const after = presetLabel( schemaType, preset );
	return '' !== before && '' !== after && form.name === before
		? after
		: form.name;
}

/**
 * WebDavAddress::shown(): scheme://host[:port] of the address, lower case,
 * the scheme's default port dropped. '' where PHP throws (not a plain
 * http(s) address: more than 2048 characters, white space or a control
 * character or a backslash, a user name, "?" or "#", no host).
 *
 * @param {string} url Address as typed.
 * @return {string} The shown address, or ''.
 */
function webDavShown( url ) {
	if ( url.length > 2048 || /[\s\x00-\x1F\x7F\\]/.test( url ) ) {
		return '';
	}
	const parts = /^([A-Za-z][A-Za-z0-9+.-]*):\/\/([^/?#@]*)(\/[^?#]*)?$/.exec(
		url
	);
	if ( ! parts ) {
		return '';
	}
	const scheme = parts[ 1 ].toLowerCase();
	const at =
		/^\[([^\]]+)\](?::(\d+))?$/.exec( parts[ 2 ] ) ||
		/^([^:[\]]+)(?::(\d+))?$/.exec( parts[ 2 ] );
	const port = Number( at?.[ 2 ] ?? 0 );
	if ( ! at || ! [ 'http', 'https' ].includes( scheme ) || port > 65535 ) {
		return '';
	}
	const host = at[ 1 ].toLowerCase();
	const shownPort =
		port > 0 && ( 'https' === scheme ? 443 : 80 ) !== port
			? `:${ port }`
			: '';

	return `${ scheme }://${ bracketed( host ) }${ shownPort }`;
}

/**
 * The line a card will show for a draft (the server's describe()), '' until
 * the identifying field (bucket, host, address) is filled.
 *
 * @param {string} typeKey    Type key (s3, ftp, sftp, webdav, gdrive…).
 * @param {Object} form       Form state (settings).
 * @param {Object} schemaType The type's schema (for the S3 provider labels).
 * @return {string} The line, or ''.
 */
export function previewWhere( typeKey, form, schemaType ) {
	const s = form?.settings ?? {};
	const folder = text( s.folder );
	const inFolder = ( where ) =>
		'' === where ? '' : ( folder ? `${ folder } · ` : '' ) + where;

	switch ( typeKey ) {
		case 's3': {
			const bucket = text( s.bucket );
			if ( '' === bucket ) {
				return '';
			}
			const prefix = text( s.prefix );
			const label = presetLabel( schemaType, s.preset );
			return `${ bucket }${ prefix ? `/${ prefix }` : '' } · ${ label }`;
		}
		case 'ftp': {
			const host = text( s.host );
			return inFolder(
				'' === host
					? ''
					: `${ 'ftp' === s.security ? 'ftp' : 'ftps' }://${ bracketed(
							host
						) }`
			);
		}
		case 'sftp': {
			const host = text( s.host );
			const port = parseInt( s.port, 10 ) || 22;
			return inFolder(
				'' === host ? '' : `sftp://${ bracketed( host ) }:${ port }`
			);
		}
		case 'webdav':
			return inFolder( webDavShown( text( s.url ) ) );
		case 'gdrive':
		case 'dropbox':
		case 'onedrive':
			// OAuthDestinationType::describe(): label · folderShown (Drive: LW Migration/<site>).
			return '' === SITE_FOLDER || ! schemaType?.label
				? ''
				: `${ schemaType.label } · ${
						'gdrive' === typeKey ? 'LW Migration/' : ''
					}${ SITE_FOLDER }`;
		default:
			return '';
	}
}
