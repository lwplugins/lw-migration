/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import { cardFacts, dropInNote, namesWithMore } from './resetFacts';

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

const NETWORK = {
	...SUMMARY,
	network: true,
	dropIns: [],
	keptPlugins: [],
	muPlugins: 0,
	types: {
		...SUMMARY.types,
		media: { ...open, attachments: null, folder: '[uploads]' },
		database: {
			available: false,
			reason: 'network',
			reasonText:
				'On a network only plugins, themes and media can be reset in this version.',
			user: 'admin',
			tables: {
				replaced: 0,
				retired: 0,
				kept: 0,
				retiredNames: [],
				more: 0,
			},
			prefixWarning: false,
			connections: 0,
			schedules: 0,
			otherUsers: 0,
		},
		full: {
			available: false,
			reason: 'network',
			reasonText: '',
		},
	},
};

describe( 'cardFacts', () => {
	it( 'names the plugins that go and the ones a loader keeps', () => {
		const { goes, stays } = cardFacts( 'plugins', SUMMARY );
		expect( goes[ 0 ] ).toBe( '12 plugins: a/a.php, b/b.php and 10 more' );
		expect( stays ).toContain( 'LW Migration and its backups' );
		expect( stays ).toContain(
			'cache/cache.php — loaded by object-cache.php'
		);
		expect( stays ).toContain( '2 must-use plugins' );
	} );

	it( 'names the theme that stays active', () => {
		const { goes, stays } = cardFacts( 'themes', SUMMARY );
		expect( goes ).toEqual( [ '1 theme: old' ] );
		expect( stays ).toEqual( [
			'Twenty Twenty-Five, as the active theme',
		] );
		const stub = cardFacts( 'themes', {
			types: {
				themes: {
					target: {
						slug: 'lw-migration-blank',
						name: 'Blank',
						source: 'stub',
					},
					count: 0,
					names: [],
					more: 0,
				},
			},
		} );
		expect( stub.goes ).toEqual( [ 'No other theme is installed.' ] );
		expect( stub.stays[ 0 ] ).toContain( 'minimal blank theme' );
	} );

	it( 'counts the media items, or names every site on a network', () => {
		expect( cardFacts( 'media', SUMMARY ).goes[ 0 ] ).toBe(
			'145 media items and every file in the uploads folder'
		);
		expect( cardFacts( 'media', NETWORK ).goes[ 0 ] ).toBe(
			'Every media item of every site and every file in the uploads folder'
		);
		expect( cardFacts( 'media', SUMMARY ).stays ).toEqual( [
			"The uploads folder's protection files and your backups",
		] );
	} );

	it( 'names the retired tables, the other users and who stays after a database reset', () => {
		const { goes, stays } = cardFacts( 'database', SUMMARY );
		expect( goes[ 1 ] ).toBe( '3 other users' );
		expect( goes ).toContain(
			'Your application passwords and other personal settings'
		);
		expect( goes ).toContain(
			'2 other tables: wp_shop_orders, wp_shop_items'
		);
		expect( goes ).toContain(
			'Connections made by other admins stop working'
		);
		expect( stays ).toContain(
			'You (admin): login, password, profile and sessions'
		);
		expect( stays ).toContain(
			"LW Migration's settings and transfer links"
		);
		expect( stays ).toContain( '2 connections made here' );
		expect( stays ).toContain( '1 schedule' );
		expect( stays ).toContain(
			'9 tables of another installation in this database'
		);
		expect( goes.join( ' ' ) ).not.toContain( 'underscore' );
	} );

	it( 'names more tables than shown, and warns about a prefix without an underscore', () => {
		const summary = {
			...SUMMARY,
			types: {
				...SUMMARY.types,
				database: {
					...SUMMARY.types.database,
					prefixWarning: true,
					tables: {
						replaced: 12,
						retired: 13,
						kept: 0,
						retiredNames: [ 'wpa', 'wpb' ],
						more: 11,
					},
				},
			},
		};
		const { goes } = cardFacts( 'database', summary );
		expect( goes ).toContain( '13 other tables: wpa, wpb and 11 more' );
		expect( goes.join( ' ' ) ).toContain(
			'no underscore at its end, so tables of other software that start with it would be removed too'
		);
	} );

	it( 'joins every part for a full reset, each line once', () => {
		const { goes, stays } = cardFacts( 'full', SUMMARY );
		expect( goes[ 0 ] ).toBe( '12 plugins: a/a.php, b/b.php and 10 more' );
		expect( goes ).toContain( '1 theme: old' );
		expect( goes ).toContain(
			'2 other tables: wp_shop_orders, wp_shop_items'
		);
		expect( stays ).toContain(
			'cache/cache.php — loaded by object-cache.php'
		);
		expect( new Set( goes ).size ).toBe( goes.length );
		expect( new Set( stays ).size ).toBe( stays.length );
	} );

	it( 'leaves out counts of zero', () => {
		const { goes, stays } = cardFacts( 'database', {
			types: {
				database: {
					user: 'admin',
					tables: { retired: 0, kept: 0, retiredNames: [], more: 0 },
					connections: 0,
					schedules: 0,
					otherUsers: 0,
				},
			},
		} );
		const all = [ ...goes, ...stays ].join( ' | ' );
		expect( all ).not.toMatch( /\b0 / );
		expect( stays ).toContain(
			"LW Migration's settings and transfer links"
		);
	} );

	it( 'names the backups once on a full reset', () => {
		const { stays } = cardFacts( 'full', SUMMARY );
		expect(
			stays.filter( ( line ) => line.includes( 'backups' ) )
		).toEqual( [ 'LW Migration and its backups' ] );
		expect( stays ).toContain( "The uploads folder's protection files" );
	} );

	it( 'gives a network summary without failing on its unavailable parts', () => {
		expect( cardFacts( 'media', NETWORK ).goes[ 0 ] ).toBe(
			'Every media item of every site and every file in the uploads folder'
		);
		expect( cardFacts( 'full', NETWORK ).goes.length ).toBeGreaterThan( 0 );
		expect( cardFacts( 'unknown', NETWORK ) ).toEqual( {
			goes: [],
			stays: [],
		} );
	} );
} );

describe( 'dropInNote', () => {
	it( 'notes the drop-ins with the page cache', () => {
		expect( dropInNote( [] ) ).toBe( '' );
		expect( dropInNote( [ 'advanced-cache.php', 'db.php' ] ) ).toBe(
			'Drop-ins stay: advanced-cache.php, db.php. If one of these belonged to a deleted plugin, remove it by hand. A page cache may still serve the old pages: empty it.'
		);
	} );
} );

describe( 'namesWithMore', () => {
	it( 'counts the names not shown', () => {
		expect( namesWithMore( [ 'a', 'b' ], 0 ) ).toBe( 'a, b' );
		expect( namesWithMore( [ 'a' ], 3 ) ).toBe( 'a and 3 more' );
		expect( namesWithMore( undefined, 0 ) ).toBe( '' );
	} );
} );
