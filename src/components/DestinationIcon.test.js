/**
 * External dependencies
 */
import { renderToString } from '@wordpress/element';
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import DestinationIcon, {
	DestinationLabel,
	chipParts,
	iconName,
	presetOf,
	typeText,
} from './DestinationIcon';

const html = ( props ) => renderToString( <DestinationIcon { ...props } /> );

describe( 'iconName', () => {
	it.each( [
		[ 'gdrive', '', 'google-drive' ],
		[ 'dropbox', '', 'dropbox' ],
		[ 'onedrive', '', 'microsoft' ],
		[ 's3', 'aws', 'aws' ],
		[ 's3', 'r2', 'cloudflare' ],
		[ 's3', 'digitalocean', 'digital-ocean' ],
		[ 's3', 'b2', 'backblaze' ],
		[ 's3', 'wasabi', 'wasabi' ],
		[ 's3', 'hetzner', 'hetzner' ],
		[ 's3', 'scaleway', 'scaleway' ],
		[ 's3', 'custom', 'database' ],
		[ 's3', undefined, 'database' ],
		[ 'webdav', 'nextcloud', 'nextcloud' ],
		[ 'webdav', 'auto', 'globe' ],
		[ 'webdav', undefined, 'globe' ],
		[ 'ftp', '', 'folder-tree' ],
		[ 'sftp', '', 'server' ],
		[ 'whatever', '', 'cloud' ],
		[ undefined, undefined, 'cloud' ],
	] )( '%s / %s -> %s', ( type, preset, expected ) => {
		expect( iconName( type, preset ) ).toBe( expected );
	} );
} );

describe( 'presetOf', () => {
	it( 'reads the S3 provider and the WebDAV mode', () => {
		expect( presetOf( { type: 's3', settings: { preset: 'r2' } } ) ).toBe(
			'r2'
		);
		expect(
			presetOf( { type: 'webdav', settings: { mode: 'nextcloud' } } )
		).toBe( 'nextcloud' );
		expect( presetOf( { type: 's3', preset: 'aws' } ) ).toBe( 'aws' );
		expect(
			presetOf( { type: 'webdav', mode: 'nextcloud', preset: null } )
		).toBe( 'nextcloud' );
		expect( presetOf( { type: 's3', preset: null } ) ).toBe( '' );
		expect( presetOf( { type: 'ftp', settings: {} } ) ).toBe( '' );
		expect( presetOf( { type: 's3' } ) ).toBe( '' );
	} );
} );

describe( 'DestinationIcon', () => {
	it( 'is always decorative', () => {
		for ( const type of [ 'gdrive', 's3', 'webdav', 'ftp', 'nope' ] ) {
			const out = html( { type } );
			expect( out ).toContain( 'aria-hidden="true"' );
			expect( out ).toContain( 'focusable="false"' );
		}
	} );

	it( 'falls back to the cloud for an unknown type', () => {
		expect( html( { type: 'nope' } ) ).toContain( 'lw-dest-icon--cloud' );
	} );

	it( 'carries the brand class, size and its own viewBox', () => {
		const out = html( { type: 's3', preset: 'r2', size: 14 } );
		expect( out ).toContain( 'lw-dest-icon--cloudflare' );
		expect( out ).toContain( 'width="14"' );
		expect( out ).toContain( 'viewBox="' );
	} );
} );

describe( 'chip label', () => {
	it( 'names the type, then the own name when it differs', () => {
		expect(
			chipParts( { type: 'gdrive', name: 'trueqap@gmail.com' } )
		).toEqual( { type: 'Google Drive', name: 'trueqap@gmail.com' } );
		expect( chipParts( { type: 'dropbox', name: 'Dropbox' } ) ).toEqual( {
			type: 'Dropbox',
			name: '',
		} );
	} );

	it( 'names S3 by provider and WebDAV by server', () => {
		const s3 = ( preset ) => ( { type: 's3', settings: { preset } } );
		expect( typeText( s3( 'aws' ) ) ).toBe( 'S3 \u00B7 AWS' );
		expect( typeText( s3( 'r2' ) ) ).toBe( 'S3 \u00B7 Cloudflare R2' );
		expect( typeText( s3( 'custom' ) ) ).toBe( 'S3' );
		expect( typeText( { type: 'onedrive' } ) ).toBe( 'OneDrive' );
		expect( typeText( { type: 'sftp' } ) ).toBe( 'SFTP' );
		expect( typeText( { type: 'ftp' } ) ).toBe( 'FTP' );
		expect(
			typeText( { type: 'webdav', settings: { mode: 'nextcloud' } } )
		).toBe( 'Nextcloud' );
		expect(
			typeText( { type: 'webdav', settings: { mode: 'auto' } } )
		).toBe( 'WebDAV' );
		expect( typeText( { type: 'x', typeLabel: 'Odd' } ) ).toBe( 'Odd' );
	} );

	it( 'renders icon, bold type and muted name', () => {
		const out = renderToString(
			<DestinationLabel
				item={ { type: 'gdrive', name: 'trueqap@gmail.com' } }
			/>
		);
		expect( out ).toContain( 'aria-hidden="true"' );
		expect( out ).toContain( '<strong>Google Drive</strong>' );
		expect( out ).toContain( 'lw-dest-label__name' );
		expect( out ).toContain( 'trueqap@gmail.com' );
	} );
} );
