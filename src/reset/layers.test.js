/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import { choiceScope, resetLayers } from './layers';
import { stepsReady } from './reset';
import { cardFacts } from './resetFacts';

const open = { available: true, reason: null, reasonText: '' };

const SUMMARY = {
	network: false,
	phrase: 'example.com/shop',
	backupReady: true,
	dropIns: [ 'object-cache.php' ],
	muPlugins: 2,
	keptPlugins: [ { plugin: 'cache/cache.php', by: 'object-cache.php' } ],
	types: {
		plugins: { ...open, count: 12, names: [ 'a/a.php' ], more: 11 },
		themes: {
			...open,
			target: {
				slug: 'tt5',
				name: 'Twenty Twenty-Five',
				source: 'installed',
			},
			count: 3,
			names: [ 'one', 'two', 'three' ],
			more: 0,
		},
		media: { ...open, attachments: 1284, folder: '[uploads]' },
		database: {
			...open,
			user: 'admin',
			tables: {
				replaced: 12,
				retired: 2,
				kept: 9,
				retiredNames: [ 'wp_a', 'wp_b' ],
				more: 0,
			},
			prefixWarning: false,
			connections: 2,
			schedules: 3,
			otherUsers: 5,
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
			reasonText: '',
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
		},
		full: { available: false, reason: 'network', reasonText: '' },
	},
};

const TYPES = [ 'plugins', 'themes', 'media', 'database', 'full' ];
const KEYS = [ 'plugins', 'themes', 'media', 'database' ];
const flat = ( result ) => [
	...result.layers.flatMap( ( layer ) => layer.lines ),
	...result.base.flatMap( ( row ) => row.lines ),
];
const row = ( result, key ) => result.base.find( ( r ) => r.key === key );

describe( 'resetLayers', () => {
	it( 'always lists the four layers in order', () => {
		TYPES.forEach( ( type ) =>
			[ SUMMARY, NETWORK ].forEach( ( summary ) =>
				expect(
					resetLayers( type, summary ).layers.map( ( l ) => l.key )
				).toEqual( KEYS )
			)
		);
	} );

	it( 'marks only the chosen layer as going, all four for full', () => {
		KEYS.forEach( ( key ) =>
			expect(
				resetLayers( key, SUMMARY ).layers.map( ( l ) => l.goes )
			).toEqual( KEYS.map( ( k ) => k === key ) )
		);
		expect(
			resetLayers( 'full', SUMMARY ).layers.every( ( l ) => l.goes )
		).toBe( true );
	} );

	it( 'gives a layer that goes its goes lines', () => {
		const plugins = resetLayers( 'plugins', SUMMARY ).layers[ 0 ];
		expect( plugins.lines[ 0 ] ).toBe( '12 plugins: a/a.php and 11 more' );
		const themes = resetLayers( 'themes', SUMMARY ).layers[ 1 ];
		expect( themes.lines ).toContain( '3 themes: one, two, three' );
		expect( themes.lines ).toContain(
			'Twenty Twenty-Five, as the active theme'
		);
	} );

	it( 'gives a layer that stays one counted line', () => {
		const result = resetLayers( 'plugins', SUMMARY );
		expect( result.layers[ 1 ].lines ).toEqual( [ '3 themes, all stay' ] );
		expect( result.layers[ 2 ].lines ).toEqual( [
			'1,284 media items, all stay',
		] );
		expect( result.layers[ 3 ].lines ).toEqual( [
			'Content and 6 users stay',
		] );
		expect( resetLayers( 'media', SUMMARY ).layers[ 0 ].lines ).toEqual( [
			'12 plugins, all stay',
		] );
	} );

	it( 'falls back to plain lines when a count is unknown', () => {
		const result = resetLayers( 'plugins', NETWORK );
		expect( result.layers[ 2 ].lines ).toEqual( [
			'The media of every site stays',
		] );
		expect( result.layers[ 3 ].lines ).toEqual( [
			'All content and users stay',
		] );
		expect( resetLayers( 'themes', {} ).layers[ 0 ].lines ).toEqual( [
			'The plugins stay',
		] );
		expect( resetLayers( 'plugins', {} ).layers[ 1 ].lines ).toEqual( [
			'The themes stay',
		] );
	} );

	it( 'on a network shows the every-site media line when media goes', () => {
		expect( resetLayers( 'media', NETWORK ).layers[ 2 ].lines[ 0 ] ).toBe(
			'Every media item of every site and every file in the uploads folder'
		);
	} );

	it( 'groups what always stays', () => {
		const result = resetLayers( 'plugins', SUMMARY );
		expect( result.base.map( ( r ) => r.key ) ).toEqual( [
			'lwm',
			'core',
		] );
		expect( row( result, 'lwm' ).title ).toBe(
			'LW Migration and your backups'
		);
		expect( row( result, 'core' ).title ).toBe(
			'WordPress core and wp-config.php'
		);
		expect( row( result, 'lwm' ).lines ).toEqual( [
			'LW Migration and its backups',
			"LW Migration's settings and transfer links",
			'2 connections made here',
			'3 schedules',
		] );
		expect( row( result, 'core' ).lines ).toEqual( [
			'cache/cache.php — loaded by object-cache.php',
			'2 must-use plugins',
			'Drop-ins: object-cache.php',
		] );
	} );

	it( 'shows the you row only when the database goes', () => {
		[ 'plugins', 'themes', 'media' ].forEach( ( type ) =>
			expect( row( resetLayers( type, SUMMARY ), 'you' ) ).toBeUndefined()
		);
		[ 'database', 'full' ].forEach( ( type ) => {
			const you = row( resetLayers( type, SUMMARY ), 'you' );
			expect( you.title ).toBe( 'You (admin)' );
			expect( you.lines ).toEqual( [
				'You (admin): login, password, profile and sessions',
				'The site address and the security keys',
			] );
		} );
	} );

	it( 'gives every base row its fixed title, whatever goes', () => {
		TYPES.forEach( ( type ) =>
			resetLayers( type, SUMMARY ).base.forEach( ( r ) =>
				expect( r.title ).toBe(
					{
						lwm: 'LW Migration and your backups',
						core: 'WordPress core and wp-config.php',
						you: 'You (admin)',
					}[ r.key ]
				)
			)
		);
		// The core row shows the title once, not again as its first line.
		expect( row( resetLayers( 'media', {} ), 'core' ).lines ).toEqual( [] );
	} );

	it( 'for the database says that plugins and media stay on disk, not in use', () => {
		const result = resetLayers( 'database', SUMMARY );
		const [ plugins, themes, media, database ] = result.layers;
		expect( plugins ).toEqual( {
			key: 'plugins',
			goes: false,
			disk: true,
			lines: [
				'12 plugins stay on disk, switched off',
				'Only LW Migration stays active',
			],
		} );
		expect( media ).toEqual( {
			key: 'media',
			goes: false,
			disk: true,
			lines: [
				'1,284 media items: the files stay on disk, but the library is emptied',
			],
		} );
		// The current theme stays when it is installed: the themes stay.
		expect( themes ).toEqual( {
			key: 'themes',
			goes: false,
			disk: false,
			lines: [ '3 themes, all stay' ],
		} );
		expect( database.goes ).toBe( true );
		// Nothing in the base speaks of what a database reset switches off.
		result.base
			.flatMap( ( r ) => r.lines )
			.forEach( ( line ) => {
				expect( line ).not.toMatch( /active|on disk/i );
			} );
	} );

	it( 'for the database falls back to plain disk lines when a count is unknown', () => {
		const [ plugins, , media ] = resetLayers( 'database', {} ).layers;
		expect( plugins.lines ).toEqual( [
			'The plugins stay on disk, switched off',
			'Only LW Migration stays active',
		] );
		expect( media.lines ).toEqual( [
			'The files stay on disk, but the library is emptied',
		] );
		expect(
			resetLayers( 'database', {
				types: { plugins: { count: 1 }, media: { attachments: 1 } },
			} ).layers.map( ( l ) => l.lines[ 0 ] )
		).toEqual( [
			'1 plugin stays on disk, switched off',
			'The themes stay',
			'1 media item: the files stay on disk, but the library is emptied',
			'All content: posts, pages, comments, menus and settings',
		] );
	} );

	it( 'keeps stays-on-disk for the database alone', () => {
		[ 'plugins', 'themes', 'media', 'full' ].forEach( ( type ) =>
			[ SUMMARY, NETWORK ].forEach( ( summary ) =>
				resetLayers( type, summary ).layers.forEach( ( layer ) =>
					expect( layer.disk ).toBe( false )
				)
			)
		);
		const full = flat( resetLayers( 'full', SUMMARY ) );
		expect( full.join( '\n' ) ).not.toMatch(
			/switched off|library is emptied|stays active/
		);
	} );

	it( 'carries the drop-in note only when plugins go', () => {
		expect( resetLayers( 'plugins', SUMMARY ).note ).toContain(
			'object-cache.php'
		);
		expect( resetLayers( 'full', SUMMARY ).note ).toContain(
			'object-cache.php'
		);
		[ 'themes', 'media', 'database' ].forEach( ( type ) =>
			expect( resetLayers( type, SUMMARY ).note ).toBe( '' )
		);
		expect( resetLayers( 'plugins', NETWORK ).note ).toBe( '' );
	} );

	it( 'loses no line of cardFacts and shows none twice', () => {
		// The old database card's line on plugins and themes is now said on
		// those layers (plugins: on disk, switched off, only LW Migration
		// active; themes: they stay).
		const moved = [
			'Plugins and themes stay on disk; only LW Migration stays active',
		];
		TYPES.forEach( ( type ) =>
			[ SUMMARY, NETWORK ].forEach( ( summary ) => {
				const lines = flat( resetLayers( type, summary ) );
				const facts = cardFacts( type, summary );
				[ ...facts.goes, ...facts.stays ]
					.filter( ( line ) => ! moved.includes( line ) )
					.forEach( ( line ) =>
						expect(
							lines.filter( ( l ) => l === line )
						).toHaveLength( 1 )
					);
				expect( new Set( lines ).size ).toBe( lines.length );
			} )
		);
	} );

	it( 'makes full the union of the four, without duplicates', () => {
		const full = flat( resetLayers( 'full', SUMMARY ) );
		// Every line of the layer a reset removes, and of the base; not the
		// lines of the layers it leaves (those differ when all four go).
		KEYS.forEach( ( key ) => {
			const one = resetLayers( key, SUMMARY );
			[
				...one.layers
					.filter( ( l ) => l.goes )
					.flatMap( ( l ) => l.lines ),
				...one.base.flatMap( ( r ) => r.lines ),
			]
				.filter( ( line ) => ! /protection files/.test( line ) )
				.forEach( ( line ) => expect( full ).toContain( line ) );
		} );
		expect( new Set( full ).size ).toBe( full.length );
	} );
} );

describe( 'choiceScope', () => {
	it( 'counts what each choice touches', () => {
		expect( choiceScope( 'plugins', SUMMARY ) ).toBe( '12 plugins' );
		expect( choiceScope( 'themes', SUMMARY ) ).toBe( '3 themes' );
		expect( choiceScope( 'media', SUMMARY, 'en-US' ) ).toBe(
			'1,284 media items'
		);
		expect( choiceScope( 'database', SUMMARY ) ).toBe( 'all content' );
		expect( choiceScope( 'full', SUMMARY ) ).toBe( 'all four' );
	} );

	it( 'uses the singular and zero', () => {
		const one = {
			types: {
				plugins: { count: 1 },
				themes: { count: 1 },
				media: { attachments: 1 },
			},
		};
		expect( choiceScope( 'plugins', one ) ).toBe( '1 plugin' );
		expect( choiceScope( 'themes', one ) ).toBe( '1 theme' );
		expect( choiceScope( 'media', one, 'en-US' ) ).toBe( '1 media item' );
		expect(
			choiceScope( 'plugins', { types: { plugins: { count: 0 } } } )
		).toBe( '0 plugins' );
	} );

	it( 'falls back when the count is unknown', () => {
		expect( choiceScope( 'media', NETWORK ) ).toBe( "every site's media" );
		expect( choiceScope( 'plugins', {} ) ).toBe( 'the plugins' );
		expect( choiceScope( 'themes', undefined ) ).toBe( 'the themes' );
		expect( choiceScope( 'nope', SUMMARY ) ).toBe( '' );
	} );
} );

describe( 'stepsReady', () => {
	const base = {
		type: 'plugins',
		backup: true,
		backupReady: true,
		password: 'pw',
		phrase: '',
	};

	it( 'is ready for a plugin reset with a password and a backup place', () => {
		expect( stepsReady( base, SUMMARY ) ).toEqual( {
			backup: true,
			password: true,
			phrase: null,
			ready: true,
			why: '',
		} );
	} );

	it( 'needs the password first after the backup', () => {
		const r = stepsReady( { ...base, password: '' }, SUMMARY );
		expect( r ).toMatchObject( {
			backup: true,
			password: false,
			ready: false,
		} );
		expect( r.why ).toBe( 'Enter your password.' );
	} );

	it( 'needs the typed address for the database and everything', () => {
		[ 'database', 'full' ].forEach( ( type ) => {
			const wrong = stepsReady(
				{ ...base, type, phrase: 'nope' },
				SUMMARY
			);
			expect( wrong ).toMatchObject( { phrase: false, ready: false } );
			expect( wrong.why ).toBe( 'Type the site address to confirm.' );
			const right = stepsReady(
				{ ...base, type, phrase: ' example.com/shop ' },
				SUMMARY
			);
			expect( right ).toMatchObject( {
				phrase: true,
				ready: true,
				why: '',
			} );
		} );
	} );

	it( 'names the password before the phrase when both are open', () => {
		const r = stepsReady(
			{ ...base, type: 'full', password: '', phrase: '' },
			SUMMARY
		);
		expect( r ).toMatchObject( {
			password: false,
			phrase: false,
			ready: false,
		} );
		expect( r.why ).toBe( 'Enter your password.' );
	} );

	it( 'blocks a backed-up start without room for a backup, not an unbacked one', () => {
		const on = stepsReady( { ...base, backupReady: false }, SUMMARY );
		expect( on ).toMatchObject( { backup: false, ready: false } );
		expect( on.why ).toContain( 'backup folder' );
		const off = stepsReady(
			{ ...base, backup: false, backupReady: false },
			SUMMARY
		);
		expect( off ).toMatchObject( { backup: true, ready: true, why: '' } );
	} );

	it( 'names the backup first even when the password is empty', () => {
		const r = stepsReady(
			{ ...base, backupReady: false, password: '' },
			SUMMARY
		);
		expect( r.why ).toContain( 'backup folder' );
	} );

	it( 'treats a missing backupReady as ready, like the dialog', () => {
		expect(
			stepsReady( { ...base, backupReady: undefined }, SUMMARY ).ready
		).toBe( true );
	} );

	it( 'never gets ready from a phrase when the summary has none', () => {
		expect(
			stepsReady( { ...base, type: 'database', phrase: '' }, {} ).ready
		).toBe( false );
	} );
} );
