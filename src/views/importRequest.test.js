/**
 * External dependencies
 */
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import {
	SOURCE_KEYS,
	buildImportRequest,
	findBackup,
	isReady,
	startProblem,
} from './importRequest';

// Rest\JobInput::SOURCES: the keys each import source takes, read from PHP.
const phpSources = () => {
	const source = readFileSync(
		new URL( '../../includes/Rest/JobInput.php', import.meta.url ),
		'utf8'
	);
	const block = source.match( /const SOURCES = \[([\s\S]*?)\];/ )[ 1 ];
	const consts = readFileSync(
		new URL( '../../includes/Import/ImportOptions.php', import.meta.url ),
		'utf8'
	);
	const value = ( name ) =>
		consts.match( new RegExp( `const ${ name } = '(\\w+)'` ) )[ 1 ];
	return Object.fromEntries(
		[
			...block.matchAll( /ImportOptions::(\w+)\s*=>\s*\[([^\]]*)\]/g ),
		].map( ( m ) => [
			value( m[ 1 ] ),
			[ ...m[ 2 ].matchAll( /'(\w+)'/g ) ].map( ( k ) => k[ 1 ] ),
		] )
	);
};

const file = { name: 'site.wpress', size: 4096 };
const items = [
	{ id: 'own:a.wpress', folder: 'own', name: 'a.wpress' },
	{ id: 'external:a.wpress', folder: 'external', name: 'a.wpress' },
	{ id: 'own:b:c.wpress', folder: 'own', name: 'b:c.wpress' },
];

describe( 'SOURCE_KEYS', () => {
	it( 'are the browser sources of JobInput, with their keys', () => {
		expect( SOURCE_KEYS ).toEqual( phpSources() );
	} );
} );

describe( 'buildImportRequest', () => {
	it( 'sends only the keys its source takes', () => {
		const choice = {
			file,
			url: ' https://files.test/site.wpress ',
			backup: items[ 1 ],
		};

		Object.keys( SOURCE_KEYS ).forEach( ( source ) => {
			const body = buildImportRequest( source, choice );
			expect( Object.keys( body ).sort() ).toEqual(
				[ 'type', 'source', ...SOURCE_KEYS[ source ] ].sort()
			);
			expect( body.type ).toBe( 'import' );
			expect( body.source ).toBe( source );
		} );
	} );

	it( 'takes name and size from the file', () => {
		expect( buildImportRequest( 'upload', { file } ) ).toEqual( {
			type: 'import',
			source: 'upload',
			name: 'site.wpress',
			size: 4096,
		} );
	} );

	it( 'trims the address and leaves its checks to the server', () => {
		expect( buildImportRequest( 'url', { url: '  ftp://x  ' } ).url ).toBe(
			'ftp://x'
		);
	} );

	it( 'names the backup by the folder and name of the list entry', () => {
		expect(
			buildImportRequest( 'backup', { backup: items[ 2 ] } )
		).toEqual( {
			type: 'import',
			source: 'backup',
			folder: 'own',
			name: 'b:c.wpress',
		} );
	} );
} );

describe( 'isReady', () => {
	it( 'needs the chosen source filled in', () => {
		expect( isReady( 'upload', { file: null } ) ).toBe( false );
		expect( isReady( 'upload', { file } ) ).toBe( true );
		expect( isReady( 'backup', { backup: null } ) ).toBe( false );
		expect( isReady( 'backup', { backup: items[ 0 ] } ) ).toBe( true );
		expect( isReady( 'file', { file } ) ).toBe( false );
	} );

	it( 'takes only an http(s) address with no spaces (the server judges the rest)', () => {
		[
			'',
			'   ',
			'files.test/a.wpress',
			'ftp://x/a.wpress',
			'https://',
			'https://a b',
		].forEach( ( url ) =>
			expect( isReady( 'url', { url } ) ).toBe( false )
		);
		[
			'http://x',
			' https://files.test/a.wpress ',
			'HTTPS://X/Y?z=1',
		].forEach( ( url ) =>
			expect( isReady( 'url', { url } ) ).toBe( true )
		);
	} );
} );

describe( 'findBackup', () => {
	it( 'matches folder and name, never a split id', () => {
		expect(
			findBackup( items, { folder: 'external', name: 'a.wpress' } )
		).toBe( items[ 1 ] );
		expect(
			findBackup( items, { folder: 'own', name: 'b:c.wpress' } )
		).toBe( items[ 2 ] );
		expect(
			findBackup( items, { folder: 'own', name: 'gone.wpress' } )
		).toBeNull();
		expect( findBackup( items, null ) ).toBeNull();
	} );
} );

describe( 'startProblem', () => {
	const invalid = ( field ) => ( {
		code: 'lw_migration_invalid',
		message: 'Server text.',
		data: field ? { field } : {},
	} );

	it( 'points a refused field at its control', () => {
		expect( startProblem( invalid( 'url' ) ) ).toEqual( {
			control: 'url',
			field: 'url',
			reload: false,
		} );
		expect( startProblem( invalid( 'name' ) ) ).toEqual( {
			control: 'upload',
			field: 'name',
			reload: false,
		} );
		expect( startProblem( invalid( 'size' ) ).control ).toBe( 'upload' );
		expect( startProblem( invalid( 'folder' ) ).control ).toBe( 'backup' );
	} );

	it( 'shows a refusal without a control as the notice', () => {
		expect( startProblem( invalid( 'source' ) ).control ).toBeNull();
		expect( startProblem( invalid( null ) ).control ).toBeNull();
	} );

	it( 'marks the file when there is no room for it', () => {
		expect(
			startProblem( { code: 'lw_migration_no_room', status: 400 } )
		).toEqual( { control: 'upload', field: null, reload: false } );
	} );

	it( 'reloads the list when the chosen backup is gone', () => {
		expect(
			startProblem( { code: 'lw_migration_backup_missing', status: 404 } )
		).toEqual( { control: 'backup', field: null, reload: true } );
	} );

	it( 'leaves a busy site, a logout, web-server pages and other failures to the notice', () => {
		[
			{ code: 'lw_migration_site_busy', status: 409 },
			{ code: 'rest_cookie_invalid_nonce', status: 403 },
			{ code: 'http_401', status: 401 },
			{ code: 'http_413', status: 413 },
			{ code: 'http_502', status: 502 },
			{ code: 'lw_migration_storage', status: 500 },
			{ code: 'fetch_error' },
		].forEach( ( e ) =>
			expect( startProblem( e ) ).toEqual( {
				control: null,
				field: null,
				reload: false,
			} )
		);
	} );
} );
