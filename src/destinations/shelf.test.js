/**
 * External dependencies
 */
import { readFileSync } from 'node:fs';
import { describe, expect, it, vi } from 'vitest';

/**
 * Internal dependencies
 */
import { nameForPreset, presetLabel, previewWhere, shelfGroups } from './shelf';

vi.mock( '../data/boot', () => ( { SITE_FOLDER: 'wphu-org' } ) );

const phpSource = ( file ) =>
	readFileSync(
		new URL( `../../includes/${ file }`, import.meta.url ),
		'utf8'
	);

const s3 = {
	type: 's3',
	label: 'S3-compatible',
	available: true,
	reason: null,
	fields: [
		{
			key: 'preset',
			options: [
				{ value: 'aws', label: 'Amazon S3' },
				{ value: 'b2', label: 'Backblaze B2' },
			],
		},
	],
};
const type = ( key, extra = {} ) => ( {
	type: key,
	label: key.toUpperCase(),
	available: true,
	reason: null,
	fields: [],
	...extra,
} );

describe( 'shelfGroups', () => {
	it( 'groups cloud accounts, S3 providers and servers in order', () => {
		const groups = shelfGroups( [
			type( 'webdav' ),
			s3,
			type( 'ftp' ),
			type( 'gdrive', { oauth: {} } ),
			type( 'sftp' ),
			type( 'dropbox', { oauth: {} } ),
		] );
		expect( groups.map( ( g ) => g.key ) ).toEqual( [
			'oauth',
			'keys',
			'servers',
		] );
		expect( groups[ 0 ].tiles.map( ( t ) => t.typeKey ) ).toEqual( [
			'gdrive',
			'dropbox',
		] );
		expect( groups[ 1 ].tiles ).toEqual( [
			{
				typeKey: 's3',
				preset: 'aws',
				label: 'Amazon S3',
				type: 's3',
				available: true,
				reason: '',
			},
			{
				typeKey: 's3',
				preset: 'b2',
				label: 'Backblaze B2',
				type: 's3',
				available: true,
				reason: '',
			},
		] );
		expect( groups[ 2 ].tiles.map( ( t ) => t.typeKey ) ).toEqual( [
			'sftp',
			'ftp',
			'webdav',
		] );
	} );

	it( 'puts an unknown type without an oauth block into servers', () => {
		const groups = shelfGroups( [ type( 'sftp' ), type( 'rclone' ) ] );
		expect( groups ).toHaveLength( 1 );
		expect( groups[ 0 ].tiles.map( ( t ) => t.typeKey ) ).toEqual( [
			'sftp',
			'rclone',
		] );
	} );

	it( 'leaves out an empty group', () => {
		expect( shelfGroups( [ s3 ] ).map( ( g ) => g.key ) ).toEqual( [
			'keys',
		] );
		expect( shelfGroups( [] ) ).toEqual( [] );
	} );

	it( 'gives the keys group the S3 type availability and reason', () => {
		const groups = shelfGroups( [
			{ ...s3, available: false, reason: 'No SimpleXML.' },
		] );
		expect( groups[ 0 ].tiles.map( ( t ) => t.available ) ).toEqual( [
			false,
			false,
		] );
		expect( groups[ 0 ].tiles[ 0 ].reason ).toBe( 'No SimpleXML.' );
	} );

	it( 'keeps an unavailable OAuth tile with its reason', () => {
		const [ group ] = shelfGroups( [
			type( 'gdrive', {
				oauth: {},
				available: false,
				reason: 'Needs https.',
			} ),
		] );
		expect( group.tiles[ 0 ] ).toMatchObject( {
			available: false,
			reason: 'Needs https.',
		} );
	} );
} );

describe( 'previewWhere', () => {
	it( 'accounts: provider label · the site folder, as describe() reads', () => {
		// OAuthDestinationType::describe(): label . ' · ' . folderShown(); Drive's folderShown(): DriveFolder::NAME . '/' . folder.
		expect(
			phpSource( 'Storage/Destinations/OAuth/OAuthDestinationType.php' )
		).toContain(
			"return $this->provider( $profile->settings )->label . ' · ' . $this->folderShown("
		);
		expect(
			phpSource( 'Storage/Destinations/GoogleDrive/GoogleDriveType.php' )
		).toContain( "return DriveFolder::NAME . '/' . $folder;" );
		const f = { settings: {} };
		expect(
			previewWhere( 'gdrive', f, {
				type: 'gdrive',
				label: 'Google Drive',
			} )
		).toBe( 'Google Drive · LW Migration/wphu-org' );
		expect(
			previewWhere( 'dropbox', f, { type: 'dropbox', label: 'Dropbox' } )
		).toBe( 'Dropbox · wphu-org' );
		expect(
			previewWhere( 'onedrive', f, {
				type: 'onedrive',
				label: 'OneDrive',
			} )
		).toBe( 'OneDrive · wphu-org' );
	} );

	it( 'S3: bucket[/prefix] · provider label', () => {
		// S3Type.php:128-133 describe(): $where = bucket . ( '' === prefix ? '' : '/' . prefix ); return $where . ' · ' . label.
		expect( phpSource( 'Storage/Destinations/S3/S3Type.php' ) ).toContain(
			"return $where . ' · ' . ( $labels[ (string) ( $s['preset'] ?? '' ) ] ?? '' );"
		);
		const form = ( settings ) => ( { settings } );
		expect(
			previewWhere( 's3', form( { bucket: 'b', preset: 'b2' } ), s3 )
		).toBe( 'b · Backblaze B2' );
		expect(
			previewWhere(
				's3',
				form( { bucket: ' b ', prefix: 'x/y', preset: 'aws' } ),
				s3
			)
		).toBe( 'b/x/y · Amazon S3' );
		expect( previewWhere( 's3', form( { preset: 'aws' } ), s3 ) ).toBe(
			''
		);
	} );

	it( 'FTP: [folder · ]ftp(s)://host, IPv6 bracketed', () => {
		// FtpType.php:150 describe(): ( '' === $folder ? '' : $folder . ' · ' ) . ( 'ftp' === ( $s['security'] ?? 'ftps' ) ? 'ftp://' : 'ftps://' ) . self::bracketed( host ).
		// FtpType.php:210-211 bracketed(): str_contains( $host, ':' ) ? '[' . $host . ']' : $host.
		const source = phpSource( 'Storage/Destinations/Ftp/FtpType.php' );
		expect( source ).toContain(
			"( 'ftp' === ( $s['security'] ?? 'ftps' ) ? 'ftp://' : 'ftps://' ) . self::bracketed("
		);
		expect( source ).toContain(
			"return str_contains( $host, ':' ) ? '[' . $host . ']' : $host;"
		);
		const f = ( settings ) => ( { settings } );
		expect(
			previewWhere( 'ftp', f( { host: 'h.example', security: 'ftp' } ) )
		).toBe( 'ftp://h.example' );
		expect(
			previewWhere( 'ftp', f( { host: 'h.example', security: 'ftps' } ) )
		).toBe( 'ftps://h.example' );
		expect( previewWhere( 'ftp', f( { host: 'h.example' } ) ) ).toBe(
			'ftps://h.example'
		);
		expect(
			previewWhere( 'ftp', f( { host: '::1', folder: 'backups' } ) )
		).toBe( 'backups · ftps://[::1]' );
		expect( previewWhere( 'ftp', f( { folder: 'backups' } ) ) ).toBe( '' );
	} );

	it( 'SFTP: [folder · ]sftp://host:port, IPv6 bracketed', () => {
		// SftpType.php:148-152 describe(): ( '' === $folder ? '' : $folder . ' · ' ) . self::where( settings ).
		// SftpType.php:184-186 where(): 'sftp://' . ( str_contains( $host, ':' ) ? '[' . $host . ']' : $host ) . ':' . (int) ( $settings['port'] ?? 22 ).
		const source = phpSource( 'Storage/Destinations/Sftp/SftpType.php' );
		expect( source ).toContain(
			"return ( '' === $folder ? '' : $folder . ' · ' ) . self::where( $profile->settings );"
		);
		expect( source ).toContain(
			"'sftp://' . ( str_contains( $host, ':' ) ? '[' . $host . ']' : $host ) . ':' . (int) ( $settings['port'] ?? 22 )"
		);
		const f = ( settings ) => ( { settings } );
		expect( previewWhere( 'sftp', f( { host: 'h.example' } ) ) ).toBe(
			'sftp://h.example:22'
		);
		expect(
			previewWhere( 'sftp', f( { host: 'h.example', port: '2222' } ) )
		).toBe( 'sftp://h.example:2222' );
		expect(
			previewWhere( 'sftp', f( { host: 'fe80::1', folder: '/bk' } ) )
		).toBe( '/bk · sftp://[fe80::1]:22' );
		expect( previewWhere( 'sftp', f( { host: ' ' } ) ) ).toBe( '' );
	} );

	it( 'WebDAV: [folder · ]scheme://host[:port], no path or credentials', () => {
		// WebDavType.php:146-155 describe(): ( '' === $folder ? '' : $folder . ' · ' ) . WebDavAddress::shown( url ); '' when shown() throws.
		// WebDavAddress.php shown(): root( lower scheme, lower host without [], port ).
		// WebDavAddress.php root(): scheme://[host]:port, the scheme's default port (443 / 80) dropped.
		expect(
			phpSource( 'Storage/Destinations/WebDav/WebDavType.php' )
		).toContain( 'WebDavAddress::shown(' );
		const address = phpSource(
			'Storage/Destinations/WebDav/WebDavAddress.php'
		);
		expect( address ).toContain(
			"( 'https' === $scheme ? 443 : 80 ) !== $port ? ':' . $port : ''"
		);
		expect( address ).toContain(
			"strtolower( trim( (string) $parts['host'], '[]' ) )"
		);
		const w = ( url, folder = '' ) => ( { settings: { url, folder } } );
		expect(
			previewWhere(
				'webdav',
				w( 'https://Cloud.Example.com/remote.php/dav/files/me/' )
			)
		).toBe( 'https://cloud.example.com' );
		expect(
			previewWhere( 'webdav', w( 'https://c.example:443/dav' ) )
		).toBe( 'https://c.example' );
		expect( previewWhere( 'webdav', w( 'http://c.example:80' ) ) ).toBe(
			'http://c.example'
		);
		expect(
			previewWhere( 'webdav', w( 'https://c.example:8443/dav', 'bk' ) )
		).toBe( 'bk · https://c.example:8443' );
		expect( previewWhere( 'webdav', w( 'https://[::1]:8080/' ) ) ).toBe(
			'https://[::1]:8080'
		);
	} );

	it( 'WebDAV: a refused address shows nothing, as describe() returns ""', () => {
		// WebDavType.php:149-151: catch ( FieldException $e ) { return ''; }.
		const w = ( url ) => ( { settings: { url, folder: 'bk' } } );
		expect( previewWhere( 'webdav', w( '' ) ) ).toBe( '' );
		expect( previewWhere( 'webdav', w( 'cloud.example.com' ) ) ).toBe( '' );
		expect( previewWhere( 'webdav', w( 'ftp://c.example' ) ) ).toBe( '' );
		expect( previewWhere( 'webdav', w( 'https://u:p@c.example' ) ) ).toBe(
			''
		);
		expect( previewWhere( 'webdav', w( 'https://c.example/?a=1' ) ) ).toBe(
			''
		);
		expect( previewWhere( 'webdav', w( 'https://c.example/a b' ) ) ).toBe(
			''
		);
	} );

	it( 'OAuth and unknown types have no line', () => {
		expect(
			previewWhere( 'gdrive', { settings: { host: 'x' } }, null )
		).toBe( '' );
	} );
} );

describe( 'nameForPreset', () => {
	const S3 = {
		type: 's3',
		fields: [
			{
				key: 'preset',
				options: [
					{ value: 'aws', label: 'Amazon S3' },
					{ value: 'r2', label: 'Cloudflare R2' },
				],
			},
		],
	};
	const form = ( name, preset ) => ( { name, settings: { preset } } );

	it( 'reads a provider label', () => {
		expect( presetLabel( S3, 'r2' ) ).toBe( 'Cloudflare R2' );
		expect( presetLabel( S3, 'nope' ) ).toBe( '' );
		expect( presetLabel( null, 'r2' ) ).toBe( '' );
	} );

	it( 'follows the provider while the name is the old provider', () => {
		expect( nameForPreset( form( 'Amazon S3', 'aws' ), S3, 'r2' ) ).toBe(
			'Cloudflare R2'
		);
	} );

	it( 'keeps a name the user typed, and an unknown provider changes nothing', () => {
		expect( nameForPreset( form( 'Offsite', 'aws' ), S3, 'r2' ) ).toBe(
			'Offsite'
		);
		expect( nameForPreset( form( 'Amazon S3', 'aws' ), S3, 'x' ) ).toBe(
			'Amazon S3'
		);
		expect( nameForPreset( form( '', 'x' ), S3, 'r2' ) ).toBe( '' );
	} );
} );
