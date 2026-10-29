/**
 * Internal dependencies
 */
import { ICONS } from './destinationIcons';

/** Brand marks keep their colour (admin.scss); the rest follow the accent. */
const BY_TYPE = {
	gdrive: 'google-drive',
	dropbox: 'dropbox',
	onedrive: 'microsoft',
	ftp: 'folder-tree',
	sftp: 'server',
};

const BY_S3_PRESET = {
	aws: 'aws',
	b2: 'backblaze',
	wasabi: 'wasabi',
	r2: 'cloudflare',
	digitalocean: 'digital-ocean',
	hetzner: 'hetzner',
	scaleway: 'scaleway',
};

/**
 * Which icon a destination gets.
 *
 * @param {string} type   Destination type key (gdrive, s3, webdav…).
 * @param {string} preset S3 provider preset, or the WebDAV server mode.
 * @return {string} Key in ICONS.
 */
export function iconName( type, preset ) {
	if ( 's3' === type ) {
		return BY_S3_PRESET[ preset ] ?? 'database';
	}
	if ( 'webdav' === type ) {
		return 'nextcloud' === preset ? 'nextcloud' : 'globe';
	}
	return BY_TYPE[ type ] ?? 'cloud';
}

/**
 * The preset argument for a destination view: the S3 provider, or the WebDAV
 * server mode (only an explicit "nextcloud" choice is known).
 *
 * @param {?Object} item Destination view (GET /destinations or /overview).
 * @return {string} Preset, '' when unknown.
 */
export function presetOf( item ) {
	// The Destinations list nests them in `settings`; the Overview row has
	// them at the top.
	const settings = item?.settings;
	if ( 's3' === item?.type ) {
		return settings?.preset ?? item.preset ?? '';
	}
	return 'webdav' === item?.type ? ( settings?.mode ?? item.mode ?? '' ) : '';
}

const S3_NAMES = {
	aws: 'AWS',
	b2: 'Backblaze B2',
	wasabi: 'Wasabi',
	r2: 'Cloudflare R2',
	digitalocean: 'DigitalOcean',
	hetzner: 'Hetzner',
	scaleway: 'Scaleway',
};

const TYPE_NAMES = {
	gdrive: 'Google Drive',
	dropbox: 'Dropbox',
	onedrive: 'OneDrive',
	ftp: 'FTP',
	sftp: 'SFTP',
};

/**
 * The type's display name: the provider for the cloud accounts, "S3 · AWS"
 * for a known S3 provider, Nextcloud or WebDAV, FTP, SFTP; an unknown type
 * keeps the server's label (or its key).
 *
 * @param {Object} item Destination view (type, typeLabel, settings).
 * @return {string} Name.
 */
export function typeText( item ) {
	const preset = presetOf( item );
	if ( 's3' === item?.type ) {
		return S3_NAMES[ preset ] ? `S3 \u00B7 ${ S3_NAMES[ preset ] }` : 'S3';
	}
	if ( 'webdav' === item?.type ) {
		return 'nextcloud' === preset ? 'Nextcloud' : 'WebDAV';
	}
	return TYPE_NAMES[ item?.type ] ?? item?.typeLabel ?? item?.type ?? '';
}

/**
 * What a destination chip says: the type's name, then the destination's own
 * name unless it is the same.
 *
 * @param {Object} item Destination view.
 * @return {{type: string, name: string}} Parts (name '' when not needed).
 */
export function chipParts( item ) {
	const type = typeText( item );
	const name = item?.name ?? '';
	return { type, name: name && name !== type ? name : '' };
}

/**
 * A destination chip/checkbox label: small icon, the type in bold, the
 * destination's own name muted after it.
 *
 * @param {Object} props
 * @param {Object} props.item Destination view (type, settings, name).
 */
export function DestinationLabel( { item } ) {
	const { type, name } = chipParts( item );
	return (
		<span className="lw-dest-label">
			<DestinationIcon
				type={ item.type }
				preset={ presetOf( item ) }
				size={ 14 }
			/>
			<strong>{ type }</strong>
			{ name && (
				<span className="lw-dest-label__name">{ `\u00B7 ${ name }` }</span>
			) }
		</span>
	);
}

/**
 * A destination's icon: inline SVG, decorative (the name is always beside it).
 *
 * @param {Object} props
 * @param {string} props.type      Destination type key.
 * @param {string} props.preset    S3 preset or WebDAV mode.
 * @param {number} props.size      Pixels.
 * @param {string} props.className Extra class.
 * @param {string} props.icon      An ICONS key instead of the type's (e.g. server for this site's own folder).
 */
export default function DestinationIcon( {
	type,
	preset,
	size = 20,
	className,
	icon,
} ) {
	const name = icon && ICONS[ icon ] ? icon : iconName( type, preset );
	const { viewBox, d } = ICONS[ name ];
	return (
		<svg
			className={ [ 'lw-dest-icon', `lw-dest-icon--${ name }`, className ]
				.filter( Boolean )
				.join( ' ' ) }
			xmlns="http://www.w3.org/2000/svg"
			viewBox={ viewBox }
			width={ size }
			height={ size }
			aria-hidden="true"
			focusable="false"
		>
			<path fill="currentColor" d={ d } />
		</svg>
	);
}
