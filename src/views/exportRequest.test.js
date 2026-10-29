/**
 * External dependencies
 */
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import {
	DEFAULTS,
	FLAG_KEYS,
	buildRequest,
	checkForm,
	countChanged,
	toRequest,
} from './exportRequest';

// The server's allowlist (Export\ExportOptions::FLAGS values), read from the
// PHP source so a renamed or added flag fails here, not in the browser.
const phpFlags = () => {
	const source = readFileSync(
		new URL( '../../includes/Export/ExportOptions.php', import.meta.url ),
		'utf8'
	);
	const block = source.match( /const FLAGS = \[([\s\S]*?)\];/ )[ 1 ];
	return [ ...block.matchAll( /=>\s*'(\w+)'/g ) ].map( ( m ) => m[ 1 ] );
};

// Rest\JobInput::exportOptions(): the keys it lets through, read from the PHP
// source (its flag list, LISTS and the literal keys of its onlyKeys() call).
const ALLOWED = () => {
	const source = readFileSync(
		new URL( '../../includes/Rest/JobInput.php', import.meta.url ),
		'utf8'
	);
	const quoted = ( text ) =>
		[ ...text.matchAll( /'(\w+)'/g ) ].map( ( m ) => m[ 1 ] );
	const lists = quoted( source.match( /const LISTS = \[([^\]]*)\];/ )[ 1 ] );
	const body = source.match( /function exportOptions\([\s\S]*?\n\t}\n/ )[ 0 ];
	const flags = body.match(
		/\$flags = array_merge\( array_values\( ExportOptions::FLAGS \), (\[[^\]]*\]) \);/
	);
	const only = body.match(
		/self::onlyKeys\( \$raw, array_merge\( \$flags, self::LISTS, (\[[^\]]*\]) \)/
	);
	return [
		...phpFlags(),
		...quoted( flags[ 1 ] ),
		...lists,
		...quoted( only[ 1 ] ),
	];
};

// The case table shared with tests/WordPress/Rest/ExportOptionsCasesTest.php.
const CASES = JSON.parse(
	readFileSync(
		new URL(
			'../../tests/Fixtures/export-options-cases.json',
			import.meta.url
		),
		'utf8'
	)
);

const form = ( patch = {} ) => ( { ...DEFAULTS, ...patch } );

describe( 'FLAG_KEYS', () => {
	it( 'are exactly the server flags', () => {
		expect( [ ...FLAG_KEYS ].sort() ).toEqual( phpFlags().sort() );
		expect( FLAG_KEYS ).toHaveLength( 11 );
	} );
} );

describe( 'ALLOWED', () => {
	it( 'is read from JobInput: 11 flags, encrypt, 3 lists, replace, compression, sites', () => {
		const keys = ALLOWED();

		expect( keys ).toHaveLength( 18 );
		expect( new Set( keys ).size ).toBe( 18 );
		expect( keys ).toEqual(
			expect.arrayContaining( [
				'encrypt',
				'excludeFiles',
				'excludeTables',
				'includeTables',
				'replace',
				'compression',
				'sites',
			] )
		);
	} );
} );

// The chosen sites of a network backup: sent only on a network, and only when
// the scope is "Chosen sites" (the server's default is the whole network).
const NETWORK_ONLY = [ 'sites' ];

describe( 'the shared case table', () => {
	const files = ( line ) =>
		checkForm( form( { excludeFiles: line } ), '', '' );
	const pair = ( value ) => form( { replace: [ value ] } );

	it.each( CASES.excludeFiles.accepted )(
		'sends the path %j the server takes',
		( line ) => {
			expect( files( line ) ).toBeNull();
			expect(
				toRequest( form( { excludeFiles: line } ) ).excludeFiles
			).toHaveLength( 1 );
		}
	);

	it.each( CASES.excludeFiles.refused )(
		'refuses the path %j the server refuses',
		( line ) => {
			expect( files( line ) ).toEqual( {
				field: 'excludeFiles',
				reason: 'path',
			} );
		}
	);

	it.each( CASES.excludeFiles.serverOnly )(
		'leaves %j to the server, which names the field',
		( line ) => {
			expect( files( line ) ).toBeNull();
		}
	);

	it.each( CASES.replace.accepted )(
		'sends the pair %j trimmed',
		( from, to ) => {
			expect( checkForm( pair( [ from, to ] ), '', '' ) ).toBeNull();
			expect( toRequest( pair( [ from, to ] ) ).replace ).toEqual( [
				[ from.trim(), to.trim() ],
			] );
		}
	);

	it.each( CASES.replace.refused )(
		'refuses the half-blank pair %j',
		( from, to ) => {
			expect( checkForm( pair( [ from, to ] ), '', '' ) ).toEqual( {
				field: 'replace',
				reason: 'pair',
				index: 0,
			} );
		}
	);

	it.each( CASES.replace.dropped )(
		'never sends the empty row %j (the server would refuse it)',
		( from, to ) => {
			expect( checkForm( pair( [ from, to ] ), '', '' ) ).toBeNull();
			expect( toRequest( pair( [ from, to ] ) ).replace ).toEqual( [] );
		}
	);
} );

describe( 'toRequest', () => {
	it( 'sends only the allowlisted keys, with the JSON types the server takes', () => {
		const options = toRequest( form() );

		expect( Object.keys( options ).sort() ).toEqual(
			ALLOWED()
				.filter( ( key ) => ! NETWORK_ONLY.includes( key ) )
				.sort()
		);
		// Every part goes in by default except the cache folders.
		FLAG_KEYS.forEach( ( key ) =>
			expect( options[ key ] ).toBe( 'noCache' === key )
		);
		expect( options.encrypt ).toBe( false );
		expect( options.compression ).toBe( '' );
		expect( options.excludeFiles ).toEqual( [] );
		expect( options.excludeTables ).toEqual( [] );
		expect( options.includeTables ).toEqual( [] );
		expect( options.replace ).toEqual( [] );
	} );

	it( 'drops any key the form state carries besides its own', () => {
		const options = toRequest( form( { noFiles: true, kind: 'x' } ) );

		expect( options ).not.toHaveProperty( 'noFiles' );
		expect( options ).not.toHaveProperty( 'kind' );
	} );

	it( 'keeps booleans strict', () => {
		const options = toRequest( form( { noMedia: 1, noCache: 0 } ) );

		expect( options.noMedia ).toBe( true );
		expect( options.noCache ).toBe( false );
	} );

	it( 'maps the compression choice', () => {
		expect( toRequest( form( { compression: 'none' } ) ).compression ).toBe(
			''
		);
		expect( toRequest( form( { compression: 'gzip' } ) ).compression ).toBe(
			'gzip'
		);
		expect(
			toRequest( form( { compression: 'bzip2' } ) ).compression
		).toBe( 'bzip2' );
	} );

	it( 'turns the text areas into trimmed lists without blank lines', () => {
		const options = toRequest(
			form( {
				excludeFiles: ' uploads/videos \n\n  cache\r\n',
				excludeTables: 'wp_logs\n   \n',
				includeTables: '',
			} )
		);

		expect( options.excludeFiles ).toEqual( [ 'uploads/videos', 'cache' ] );
		expect( options.excludeTables ).toEqual( [ 'wp_logs' ] );
		expect( options.includeTables ).toEqual( [] );
	} );

	it( 'sends trimmed pairs and leaves out rows nobody filled in', () => {
		const options = toRequest(
			form( {
				replace: [
					[ ' https://old.test ', 'https://new.test' ],
					[ '', '' ],
					[ '  ', ' ' ],
				],
			} )
		);

		expect( options.replace ).toEqual( [
			[ 'https://old.test', 'https://new.test' ],
		] );
	} );
} );

describe( 'the scope of a network backup', () => {
	const chosen = form( { scope: 'sites', sites: [ 3, 2 ] } );

	it( 'starts at the whole network with nothing chosen', () => {
		expect( DEFAULTS.scope ).toBe( 'network' );
		expect( DEFAULTS.sites ).toEqual( [] );
	} );

	it( 'sends the chosen site ids on a network', () => {
		const options = toRequest( chosen, true );

		expect( options.sites ).toEqual( [ 3, 2 ] );
		expect( Object.keys( options ).sort() ).toEqual( ALLOWED().sort() );
	} );

	it( 'sends no sites for the whole network (the server default)', () => {
		expect(
			toRequest( form( { scope: 'network', sites: [ 2 ] } ), true )
		).not.toHaveProperty( 'sites' );
	} );

	it( 'never sends sites on a single site', () => {
		expect( toRequest( chosen ) ).not.toHaveProperty( 'sites' );
		expect( toRequest( chosen, false ) ).not.toHaveProperty( 'sites' );
		expect( buildRequest( chosen, '' ).options ).not.toHaveProperty(
			'sites'
		);
	} );

	it( 'carries the sites in the start request on a network', () => {
		expect( buildRequest( chosen, '', true ).options.sites ).toEqual( [
			3, 2,
		] );
	} );

	it( 'refuses chosen sites with none chosen, on a network only', () => {
		const none = form( { scope: 'sites', sites: [] } );

		expect( checkForm( none, '', '', true ) ).toEqual( {
			field: 'sites',
			reason: 'empty',
		} );
		expect( checkForm( none, '', '' ) ).toBeNull();
		expect( checkForm( chosen, '', '', true ) ).toBeNull();
	} );

	it( 'is not counted among the folded options', () => {
		expect( countChanged( chosen ) ).toBe( 0 );
	} );
} );

describe( 'checkForm', () => {
	it( 'accepts the defaults', () => {
		expect( checkForm( form(), '', '' ) ).toBeNull();
	} );

	it( 'refuses a pair with one blank side (like the server, R15)', () => {
		expect(
			checkForm(
				form( {
					replace: [
						[ 'old', 'new' ],
						[ 'old2', '  ' ],
					],
				} ),
				'',
				''
			)
		).toEqual( { field: 'replace', reason: 'pair', index: 1 } );
		expect(
			checkForm( form( { replace: [ [ '', 'new' ] ] } ), '', '' )
		).toEqual( { field: 'replace', reason: 'pair', index: 0 } );
	} );

	it( 'lets an empty row through (it is not sent)', () => {
		expect(
			checkForm( form( { replace: [ [ '', '' ] ] } ), '', '' )
		).toBeNull();
	} );

	it( 'refuses an excluded path outside wp-content', () => {
		[
			'../wp-config.php',
			'uploads/../..',
			'./uploads',
			'/',
			'\\..\\x',
		].forEach( ( line ) =>
			expect(
				checkForm(
					form( { excludeFiles: `cache\n${ line }` } ),
					'',
					''
				)
			).toEqual( { field: 'excludeFiles', reason: 'path' } )
		);
		expect(
			checkForm(
				form( { excludeFiles: '/uploads/videos/\nplugins\\big' } ),
				'',
				''
			)
		).toBeNull();
	} );

	it( 'needs the same non-empty password twice when encrypting', () => {
		const encrypt = form( { encrypt: true } );

		expect( checkForm( encrypt, '', '' ) ).toEqual( {
			field: 'password',
			reason: 'empty',
		} );
		expect( checkForm( encrypt, 'secret', 'secreT' ) ).toEqual( {
			field: 'password',
			reason: 'mismatch',
		} );
		expect( checkForm( encrypt, 'secret', 'secret' ) ).toBeNull();
		expect( checkForm( form(), 'secret', 'other' ) ).toBeNull();
	} );
} );

describe( 'buildRequest', () => {
	it( 'is the export envelope without a password when not encrypting', () => {
		const body = buildRequest( form( { noMedia: true } ), 'ignored' );

		expect( Object.keys( body ).sort() ).toEqual( [ 'options', 'type' ] );
		expect( body.type ).toBe( 'export' );
		expect( body.options.noMedia ).toBe( true );
	} );

	it( 'carries the password, untrimmed, only when encrypting', () => {
		const body = buildRequest( form( { encrypt: true } ), ' pass word ' );

		expect( Object.keys( body ).sort() ).toEqual( [
			'options',
			'password',
			'type',
		] );
		expect( body.password ).toBe( ' pass word ' );
		expect( body.options.encrypt ).toBe( true );
	} );
} );

describe( 'buildRequest destinations', () => {
	it( 'sends neither key when no destination is chosen', () => {
		const body = buildRequest( form() );

		expect( body ).not.toHaveProperty( 'destinations' );
		expect( body ).not.toHaveProperty( 'keepLocal' );
	} );

	it( 'sends the chosen ids next to the options, not inside them', () => {
		const body = buildRequest(
			form( { destinations: [ 'aaaa1111', 'bbbb2222' ] } )
		);

		expect( body.destinations ).toEqual( [ 'aaaa1111', 'bbbb2222' ] );
		expect( body ).not.toHaveProperty( 'keepLocal' );
		expect( body.options ).not.toHaveProperty( 'destinations' );
	} );

	it( 'sends keepLocal when the local copy is not kept', () => {
		const body = buildRequest(
			form( { destinations: [ 'aaaa1111' ], keepLocal: false } )
		);

		expect( body.keepLocal ).toBe( false );
	} );

	it( 'never sends keepLocal without destinations', () => {
		const body = buildRequest(
			form( { destinations: [], keepLocal: false } )
		);

		expect( body ).not.toHaveProperty( 'keepLocal' );
		expect( body ).not.toHaveProperty( 'destinations' );
	} );

	it( 'uses exactly the keys the server reads', () => {
		const source = readFileSync(
			new URL(
				'../../includes/Rest/ExportCloudInput.php',
				import.meta.url
			),
			'utf8'
		);
		const keys = [
			...source
				.match( /const KEYS = \[([^\]]*)\];/ )[ 1 ]
				.matchAll( /'(\w+)'/g ),
		].map( ( m ) => m[ 1 ] );
		const body = buildRequest(
			form( { destinations: [ 'aaaa1111' ], keepLocal: false } )
		);

		expect(
			Object.keys( body )
				.filter( ( k ) => keys.includes( k ) )
				.sort()
		).toEqual( [ ...keys ].sort() );
	} );
} );

describe( 'countChanged', () => {
	it( 'counts every setting that differs from the defaults', () => {
		expect( DEFAULTS.noCache ).toBe( true );
		expect( countChanged( form() ) ).toBe( 0 );
		expect( countChanged( form( { noCache: true } ) ) ).toBe( 0 );
		expect( countChanged( form( { replace: [ [ '', '' ] ] } ) ) ).toBe( 0 );
		expect(
			countChanged(
				form( {
					noMedia: true,
					noCache: false, // The cache is left out by default.
					compression: 'gzip',
					encrypt: true,
					excludeTables: 'wp_logs',
					replace: [ [ 'a', 'b' ] ],
				} )
			)
		).toBe( 6 );
	} );
} );
