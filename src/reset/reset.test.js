/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import {
	TYPES,
	canConfirm,
	lockMinutes,
	needsPhrase,
	needsReload,
	refusalReason,
	resultLines,
	titleOf,
	unavailableText,
} from './reset';

const open = { available: true, reason: null, reasonText: '' };

const SUMMARY = {
	network: false,
	phrase: 'example.com/shop',
	backupReady: true,
	dropIns: [ 'object-cache.php' ],
	muPlugins: 2,
	keptPlugins: [ { plugin: 'cache/cache.php', by: 'object-cache.php' } ],
	types: {
		plugins: {
			...open,
			count: 12,
			names: [ 'a/a.php', 'b/b.php' ],
			more: 10,
		},
		themes: {
			...open,
			target: {
				slug: 'twentytwentyfive',
				name: 'Twenty Twenty-Five',
				source: 'installed',
			},
			count: 1,
			names: [ 'old' ],
			more: 0,
		},
		media: { ...open, attachments: 145, folder: '[uploads]' },
		database: {
			...open,
			user: 'admin',
			tables: {
				replaced: 12,
				retired: 2,
				kept: 9,
				retiredNames: [ 'wp_shop_orders', 'wp_shop_items' ],
				more: 0,
			},
			prefixWarning: false,
			connections: 2,
			schedules: 1,
			otherUsers: 3,
		},
		full: open,
	},
};

describe( 'reset types', () => {
	it( 'lists the five resets with their titles', () => {
		expect( TYPES ).toEqual( [
			'plugins',
			'themes',
			'media',
			'database',
			'full',
		] );
		expect( TYPES.map( titleOf ) ).toEqual( [
			'Delete plugins',
			'Reset themes',
			'Clean up media',
			'Reset the database',
			'Reset everything',
		] );
	} );

	it( 'asks for the site address only for the database and everything', () => {
		expect( TYPES.filter( needsPhrase ) ).toEqual( [ 'database', 'full' ] );
	} );
} );

describe( 'canConfirm', () => {
	it( 'needs a password', () => {
		expect( canConfirm( 'plugins', { password: '' }, SUMMARY ) ).toBe(
			false
		);
		expect(
			canConfirm( 'plugins', { password: 'x', phrase: '' }, SUMMARY )
		).toBe( true );
	} );

	it( 'needs the exact site address for the database, spaces around it allowed', () => {
		expect(
			canConfirm( 'database', { password: 'x', phrase: '' }, SUMMARY )
		).toBe( false );
		expect(
			canConfirm(
				'database',
				{ password: 'x', phrase: '  example.com/shop ' },
				SUMMARY
			)
		).toBe( true );
		expect(
			canConfirm(
				'full',
				{ password: 'x', phrase: 'example.com' },
				SUMMARY
			)
		).toBe( false );
		expect(
			canConfirm(
				'full',
				{ password: '', phrase: 'example.com/shop' },
				SUMMARY
			)
		).toBe( false );
		expect(
			canConfirm( 'full', { password: 'x', phrase: '' }, { phrase: '' } )
		).toBe( false );
	} );
} );

describe( 'refusals', () => {
	const locked = ( retryAfter ) => ( {
		code: 'lw_migration_reset_locked',
		data: { retryAfter },
	} );

	it( 'rounds the lock up to whole minutes', () => {
		expect( lockMinutes( locked( 60 ) ) ).toBe( 1 );
		expect( lockMinutes( locked( 61 ) ) ).toBe( 2 );
		expect( lockMinutes( locked( 900 ) ) ).toBe( 15 );
		expect( lockMinutes( locked( 0 ) ) ).toBe( 1 );
		expect( lockMinutes( { code: 'lw_migration_invalid' } ) ).toBe( 0 );
	} );

	it( 'reads the reason of a refused reset only', () => {
		for ( const reason of [
			'network',
			'database_setup',
			'file_mods',
			'not_writable',
			'uploads_unsafe',
			'as_root',
			'no_user',
		] ) {
			expect(
				refusalReason( {
					code: 'lw_migration_reset_refused',
					data: { reason },
				} )
			).toBe( reason );
		}
		expect(
			refusalReason( {
				code: 'lw_migration_invalid',
				data: { field: 'password' },
			} )
		).toBeNull();
		expect( refusalReason( { code: 'lw_migration_reset_busy' } ) ).toBe(
			null
		);
	} );

	it( "shows the server's reason, our hint when it sent none", () => {
		expect(
			unavailableText( {
				reason: 'as_root',
				reasonText: 'Run it as www.',
			} )
		).toBe( 'Run it as www.' );
		expect( unavailableText( { reason: 'network', reasonText: '' } ) ).toBe(
			'On a network only plugins, themes and media can be reset in this version.'
		);
		expect( unavailableText( { reason: 'unknown_code' } ) ).toBe(
			'This reset cannot run on this site.'
		);
	} );
} );

describe( 'the done screen', () => {
	it( 'says what each part did', () => {
		expect(
			resultLines( {
				kind: 'full',
				plugins: { count: 1, names: [ 'a/a.php' ] },
				themes: { count: 3, names: [] },
				media: { attachments: 4, files: 9, failed: 0, kept: 0 },
				database: { replaced: 12, retired: 0, kept: 0, views: [] },
				defaultTheme: { slug: 'twentytwentyfive', source: 'installed' },
				user: 'admin',
				dropIns: [],
			} )
		).toEqual( [
			'1 plugin deleted.',
			'twentytwentyfive is active; 3 themes deleted.',
			'Media library emptied: 4 items, 9 files.',
			'Fresh database ready. You are still logged in as admin; every other user was removed.',
		] );
	} );

	it( 'says when the active theme is the minimal blank one', () => {
		expect(
			resultLines( {
				kind: 'themes',
				themes: { count: 1, names: [ 'old' ] },
				defaultTheme: { slug: 'lw-migration-blank', source: 'stub' },
			} )
		).toEqual( [
			'lw-migration-blank is active; 1 theme deleted. (a minimal blank theme, because none could be installed)',
		] );
	} );

	it( 'says when deleted plugins or themes kept files', () => {
		expect(
			resultLines( {
				kind: 'full',
				plugins: { count: 2, names: [], leftovers: 1 },
				themes: { count: 1, names: [], leftovers: 0 },
				defaultTheme: { slug: 'twentytwentyfive', source: 'installed' },
			} )
		).toEqual( [
			'2 plugins deleted. 1 of them kept files that could not be removed (see the warnings).',
			'twentytwentyfive is active; 1 theme deleted.',
		] );
	} );

	it( 'asks for a reload after a database or full reset only', () => {
		expect( needsReload( { kind: 'database', database: {} } ) ).toBe(
			true
		);
		expect( needsReload( { kind: 'full', database: null } ) ).toBe( true );
		expect( needsReload( { kind: 'plugins' } ) ).toBe( false );
	} );
} );
