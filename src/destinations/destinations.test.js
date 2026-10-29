/**
 * External dependencies
 */
import { readFileSync } from 'node:fs';
import { afterEach, describe, expect, it, vi } from 'vitest';

/**
 * Internal dependencies
 */
import {
	CERT_FINGERPRINT,
	CERTIFICATE_COMMAND,
	FINGERPRINT,
	MAX_SECRET_BYTES,
	PATH_STYLE_DEFAULT,
	REQUEST_KEYS,
	checkForm,
	deleteText,
	emptyForm,
	fromItem,
	hostKeyCommand,
	hostKeyDiffers,
	hostKeyState,
	isSecret,
	isSecretText,
	statusLabel,
	statusTone,
	toRequest,
	toggleChecked,
	visible,
	withSetting,
} from './destinations';

const site = vi.hoisted( () => ( { folder: 'wphu-org' } ) );
vi.mock( '../data/boot', () => ( {
	get SITE_FOLDER() {
		return site.folder;
	},
} ) );

const phpSource = ( file ) =>
	readFileSync(
		new URL( `../../includes/${ file }`, import.meta.url ),
		'utf8'
	);

// Rest\DestinationInput::KEYS, read from the PHP source: an unknown key is a 400.
const SERVER_KEYS = () => {
	const list = phpSource( 'Rest/DestinationInput.php' ).match(
		/const KEYS = \[([^\]]*)\];/
	)[ 1 ];
	return [ ...list.matchAll( /'(\w+)'/g ) ].map( ( m ) => m[ 1 ] );
};

// S3Presets::PRESETS (preset => [host, region, path-style]), read from the PHP
// source: the presets whose path-style default is on.
const SERVER_PATH_STYLE_ON = () => {
	const source = phpSource( 'Storage/Destinations/S3/S3Presets.php' );
	const names = Object.fromEntries(
		[ ...source.matchAll( /public const (\w+) = '(\w+)';/g ) ].map(
			( m ) => [ m[ 1 ], m[ 2 ] ]
		)
	);
	const block = source.match( /const PRESETS = \[([\s\S]*?)\n\t\];/ )[ 1 ];
	const rows = [
		...block.matchAll(
			/self::(\w+)\s*=>\s*\[\s*'[^']*',\s*'[^']*',\s*(true|false)\s*\]/g
		),
	];
	expect( rows.length ).toBe( Object.keys( names ).length );
	return Object.fromEntries(
		rows
			.filter( ( m ) => 'true' === m[ 2 ] )
			.map( ( m ) => [ names[ m[ 1 ] ], true ] )
	);
};

// The shape of DestinationRegistry::schema() for the S3 type (Task 7).
const S3 = {
	type: 's3',
	label: 'S3-compatible storage',
	available: true,
	reason: '',
	fields: [
		{
			key: 'preset',
			kind: 'select',
			required: true,
			default: 'aws',
			when: {},
			options: [],
		},
		{
			key: 'accountId',
			kind: 'text',
			required: true,
			default: '',
			when: { preset: [ 'r2' ] },
			options: [],
		},
		{
			key: 'endpoint',
			kind: 'text',
			required: true,
			default: '',
			when: { preset: [ 'custom' ] },
			options: [],
		},
		{
			key: 'region',
			kind: 'text',
			required: false,
			default: '',
			when: { preset: [ 'aws', 'custom' ] },
			options: [],
		},
		{
			key: 'bucket',
			kind: 'text',
			required: true,
			default: null,
			when: {},
			options: [],
		},
		{
			key: 'prefix',
			kind: 'text',
			required: false,
			default: null,
			when: {},
			options: [],
		},
		{
			key: 'pathStyle',
			kind: 'toggle',
			required: false,
			default: null,
			when: {},
			options: [],
		},
		{
			key: 'accessKey',
			kind: 'text',
			required: true,
			default: null,
			when: {},
			options: [],
		},
		{
			key: 'secretKey',
			kind: 'secret',
			required: true,
			default: null,
			when: {},
			options: [],
		},
	],
};
const PATH_STYLE = S3.fields[ 6 ];

const filled = () => {
	const form = emptyForm( S3 );
	return {
		...form,
		name: ' Offsite ',
		settings: { ...form.settings, bucket: ' bkt ', accessKey: 'AKIA' },
		secrets: { secretKey: 'S' },
	};
};

describe( 'destination forms', () => {
	it( 'starts with each field default, no path-style choice and an empty secret', () => {
		const form = emptyForm( S3 );

		expect( form.settings.preset ).toBe( 'aws' );
		expect( form.settings.pathStyle ).toBe( null );
		expect( form.settings.bucket ).toBe( '' );
		expect( form.secrets ).toEqual( { secretKey: '' } );
		expect( form.retention ).toEqual( { count: 0, bytes: 0, days: 0 } );
	} );

	it( 'shows a field only for the presets that use it', () => {
		const accountId = S3.fields[ 1 ];

		expect( visible( accountId, { preset: 'aws' } ) ).toBe( false );
		expect( visible( accountId, { preset: 'r2' } ) ).toBe( true );
		expect( visible( S3.fields[ 4 ], { preset: 'r2' } ) ).toBe( true );
	} );

	it( 'sends only keys the server knows, never an empty secret and never a hidden field', () => {
		const body = toRequest( filled(), S3, true );

		expect(
			Object.keys( body ).every( ( key ) =>
				SERVER_KEYS().includes( key )
			)
		).toBe( true );
		expect( REQUEST_KEYS ).toEqual( SERVER_KEYS() );
		expect( body.name ).toBe( 'Offsite' );
		expect( body.settings.bucket ).toBe( 'bkt' );
		expect( body.settings ).not.toHaveProperty( 'accountId' );
		expect( body.settings ).not.toHaveProperty( 'secretKey' );
		expect( body.secrets ).toEqual( { secretKey: 'S' } );

		const update = toRequest(
			{ ...filled(), secrets: { secretKey: '' } },
			S3,
			false
		);
		expect( update ).not.toHaveProperty( 'type' );
		expect( update.secrets ).toEqual( {} );
	} );

	it( 'leaves path-style to the server until it is switched', () => {
		expect( toRequest( filled(), S3, true ).settings ).not.toHaveProperty(
			'pathStyle'
		);

		const off = withSetting( filled(), 'pathStyle', false );
		expect( toRequest( off, S3, true ).settings.pathStyle ).toBe( false );

		const stored = fromItem(
			{
				name: 'Offsite',
				settings: { preset: 'aws', bucket: 'bkt', pathStyle: true },
				secrets: {},
				retention: {},
			},
			S3
		);
		expect( toRequest( stored, S3, false ).settings.pathStyle ).toBe(
			true
		);
	} );

	it( 'forgets the path-style choice when the provider changes', () => {
		const on = withSetting( filled(), 'pathStyle', true );
		const moved = withSetting( on, 'preset', 'custom' );

		expect( moved.settings.preset ).toBe( 'custom' );
		expect( moved.settings.pathStyle ).toBe( null );
		expect( toRequest( moved, S3, true ).settings ).not.toHaveProperty(
			'pathStyle'
		);
		expect( withSetting( on, 'bucket', 'x' ).settings.pathStyle ).toBe(
			true
		);
	} );

	it( "shows the preset's path-style default until a choice is made", () => {
		expect( PATH_STYLE_DEFAULT ).toEqual( SERVER_PATH_STYLE_ON() );
		expect( PATH_STYLE_DEFAULT ).toEqual( {
			b2: true,
			r2: true,
			custom: true,
		} );

		expect(
			toggleChecked( PATH_STYLE, { preset: 'custom', pathStyle: null } )
		).toBe( true );
		expect(
			toggleChecked( PATH_STYLE, { preset: 'aws', pathStyle: null } )
		).toBe( false );
		expect(
			toggleChecked( PATH_STYLE, { preset: 'custom', pathStyle: false } )
		).toBe( false );
		expect(
			toggleChecked( PATH_STYLE, { preset: 'aws', pathStyle: true } )
		).toBe( true );
	} );

	it( 'needs the secret on a new destination but not when one is stored', () => {
		const form = { ...filled(), secrets: { secretKey: '' } };

		expect( checkForm( form, S3 ) ).toEqual( {
			field: 'secretKey',
			reason: 'empty',
		} );
		expect(
			checkForm( form, S3, { secrets: { secretKey: { set: true } } } )
		).toBe( null );
		expect( checkForm( { ...filled(), name: ' ' }, S3 ) ).toEqual( {
			field: 'name',
			reason: 'empty',
		} );
		expect(
			checkForm(
				{
					...filled(),
					settings: { ...filled().settings, preset: 'r2' },
				},
				S3
			)
		).toEqual( { field: 'accountId', reason: 'empty' } );
	} );

	it( 'loads a stored item without its secret', () => {
		const form = fromItem(
			{
				name: 'Offsite',
				settings: { preset: 'b2', bucket: 'bkt' },
				secrets: { secretKey: { set: true } },
				retention: { count: 3, bytes: 0, days: 0 },
			},
			S3
		);

		expect( form.settings.preset ).toBe( 'b2' );
		expect( form.settings.pathStyle ).toBe( null );
		expect( form.secrets.secretKey ).toBe( '' );
		expect( form.retention.count ).toBe( 3 );
	} );

	it( 'maps every status to a tone and a label', () => {
		expect( [ 'ok', 'error', 'reauth', 'new' ].map( statusTone ) ).toEqual(
			[ 'ok', 'critical', 'warning', 'idle' ]
		);
		expect( statusLabel( 'unknown' ) ).toBe( statusLabel( 'new' ) );
	} );
} );

// The SFTP schema shape (SftpType::fields(), Task 9).
const SFTP = {
	type: 'sftp',
	fields: [
		{ key: 'host', kind: 'text', required: true, when: {}, default: null },
		{
			key: 'port',
			kind: 'number',
			required: false,
			when: {},
			default: 22,
		},
		{
			key: 'auth',
			kind: 'select',
			required: true,
			when: {},
			default: 'password',
			options: [],
		},
		{
			key: 'password',
			kind: 'secret',
			required: true,
			when: { auth: [ 'password' ] },
			default: null,
		},
		{
			key: 'privateKey',
			kind: 'secret_text',
			required: true,
			when: { auth: [ 'key' ] },
			default: null,
		},
		{
			key: 'passphrase',
			kind: 'secret',
			required: false,
			when: { auth: [ 'key' ] },
			default: null,
		},
	],
};

describe( 'multi-line and shown secrets', () => {
	it( 'treats a private key as a secret that starts empty', () => {
		expect( isSecret( SFTP.fields[ 4 ] ) ).toBe( true );
		expect( isSecretText( SFTP.fields[ 4 ] ) ).toBe( true );
		expect( isSecretText( SFTP.fields[ 3 ] ) ).toBe( false );
		expect( emptyForm( SFTP ).secrets ).toEqual( {
			password: '',
			privateKey: '',
			passphrase: '',
		} );
	} );

	it( 'sends only the secrets of the chosen login', () => {
		const form = {
			...emptyForm( SFTP ),
			name: 'Box',
			settings: { host: 'h', port: 22, auth: 'key' },
			secrets: { password: 'old', privateKey: 'KEY', passphrase: '' },
		};

		expect( toRequest( form, SFTP, true ).secrets ).toEqual( {
			privateKey: 'KEY',
		} );
		expect( toRequest( form, SFTP, true ).settings.port ).toBe( '22' );
	} );

	it( 'asks for the key, not the password, for a key login', () => {
		const form = {
			...emptyForm( SFTP ),
			name: 'Box',
			settings: { host: 'h', port: 22, auth: 'key' },
		};

		expect( checkForm( form, SFTP ) ).toEqual( {
			field: 'privateKey',
			reason: 'empty',
		} );
	} );

	it( 'takes the PHP limit for a secret', () => {
		expect( phpSource( 'Rest/DestinationInput.php' ) ).toContain(
			`SECRET_BYTES = ${ MAX_SECRET_BYTES };`
		);
	} );

	it( 'takes the PHP fingerprint pattern', () => {
		const pattern = phpSource(
			'Storage/Destinations/Sftp/HostKey.php'
		).match( /const PATTERN = '\/(.*)\/';/ )[ 1 ];

		expect( pattern.replace( '\\z', '$' ).replace( '\\/', '/' ) ).toBe(
			FINGERPRINT.source.replace( '\\/', '/' )
		);
	} );
} );

describe( 'hostKeyState', () => {
	const shown = 'SHA256:4U+iyVJ67eRjXO5juHU2wtdIAKv3vfEN0q53GZZr/a4';
	const failed = ( data ) => ( {
		code: 'lw_migration_destination_failed',
		message: 'x',
		data,
	} );

	it( 'reads a key to confirm and a changed key by their codes, never by text', () => {
		expect(
			hostKeyState(
				failed( {
					reason: 'host_key_unknown',
					hostKey: shown,
					hostKeyType: 'ssh-ed25519',
				} )
			)
		).toEqual( {
			kind: 'unknown',
			field: 'hostKey',
			fingerprint: shown,
			keyType: 'ssh-ed25519',
			expected: '',
			before: '',
		} );
		expect(
			hostKeyState(
				failed( {
					reason: 'host_key_changed',
					hostKey: shown,
					expected: 'SHA256:' + 'A'.repeat( 43 ),
				} )
			).kind
		).toBe( 'changed' );
	} );

	it( 'ignores other failures and anything that is not a fingerprint', () => {
		expect(
			hostKeyState( failed( { reason: 'auth', hostKey: shown } ) )
		).toBeNull();
		expect(
			hostKeyState(
				failed( { reason: 'host_key_unknown', hostKey: 'MD5:ab' } )
			)
		).toBeNull();
		expect(
			hostKeyState(
				failed( {
					reason: 'host_key_unknown',
					hostKey: shown + '\nx',
				} )
			)
		).toBeNull();
		expect(
			hostKeyState( {
				code: 'lw_migration_invalid',
				data: { reason: 'host_key_unknown', hostKey: shown },
			} )
		).toBeNull();
		expect( hostKeyState( null ) ).toBeNull();
		expect(
			hostKeyState( { code: 'lw_migration_destination_failed' } )
		).toBeNull();
	} );

	it( 'shows a changed key only next to the confirmed one', () => {
		const confirmed = 'SHA256:' + 'A'.repeat( 43 );

		expect(
			hostKeyState(
				failed( {
					reason: 'host_key_changed',
					hostKey: shown,
					hostKeyType: 'ssh-rsa',
					expected: confirmed,
				} )
			)
		).toEqual( {
			kind: 'changed',
			field: 'hostKey',
			fingerprint: shown,
			keyType: 'ssh-rsa',
			expected: confirmed,
			before: 'confirmed',
		} );
		for ( const expected of [ undefined, '', '<b>', 'MD5:ab' ] ) {
			expect(
				hostKeyState(
					failed( {
						reason: 'host_key_changed',
						hostKey: shown,
						expected,
					} )
				)
			).toBeNull();
		}
	} );

	it( 'names the host key file of the key type shown', () => {
		expect( hostKeyCommand( 'ssh-ed25519' ) ).toBe(
			'ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub'
		);
		expect( hostKeyCommand( 'ecdsa-sha2-nistp256' ) ).toBe(
			'ssh-keygen -lf /etc/ssh/ssh_host_ecdsa_key.pub'
		);
		expect( hostKeyCommand( 'ssh-rsa' ) ).toBe(
			'ssh-keygen -lf /etc/ssh/ssh_host_rsa_key.pub'
		);
		expect( hostKeyCommand( '' ) ).toBe(
			'ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub'
		);
	} );

	it( 'reads an FTPS certificate to confirm or a changed one under its own field', () => {
		const cert = Array( 32 ).fill( 'AB' ).join( ':' );
		const confirmed = Array( 32 ).fill( 'CD' ).join( ':' );

		expect(
			hostKeyState(
				failed( { reason: 'cert_unknown', certFingerprint: cert } )
			)
		).toEqual( {
			kind: 'unknown',
			field: 'certFingerprint',
			fingerprint: cert,
			keyType: '',
			expected: '',
			before: '',
		} );
		expect(
			hostKeyState(
				failed( {
					reason: 'cert_changed',
					certFingerprint: cert,
					expected: confirmed,
				} )
			)
		).toMatchObject( {
			kind: 'changed',
			field: 'certFingerprint',
			expected: confirmed,
		} );
		expect(
			hostKeyState( failed( { reason: 'cert_unknown', hostKey: shown } ) )
		).toBeNull();
		expect(
			hostKeyState(
				failed( {
					reason: 'cert_unknown',
					certFingerprint: cert.toLowerCase(),
				} )
			)
		).toBeNull();
		expect(
			hostKeyState(
				failed( {
					reason: 'cert_changed',
					certFingerprint: cert,
					expected: shown,
				} )
			)
		).toBeNull();
	} );

	it( 'uses the reason codes of PermanentFailure', () => {
		const source = phpSource( 'Storage/Destinations/PermanentFailure.php' );

		expect( source ).toContain( "HOST_KEY_UNKNOWN = 'host_key_unknown'" );
		expect( source ).toContain( "HOST_KEY_CHANGED = 'host_key_changed'" );
		expect( source ).toContain( "CERT_UNKNOWN = 'cert_unknown'" );
		expect( source ).toContain( "CERT_CHANGED = 'cert_changed'" );
	} );

	it( 'takes the PHP certificate pattern and command', () => {
		const source = phpSource(
			'Storage/Destinations/Ftp/CertificatePin.php'
		);
		const pattern = source.match( /const PATTERN = '\/(.*)\/';/ )[ 1 ];

		expect( pattern.replace( '\\z', '$' ) ).toBe( CERT_FINGERPRINT.source );
		expect( source ).toContain( `COMMAND = '${ CERTIFICATE_COMMAND }';` );
	} );
} );

describe( 'hostKeyDiffers', () => {
	const before = 'SHA256:' + 'B'.repeat( 43 );
	const now = 'SHA256:' + 'C'.repeat( 43 );
	const shownBefore = {
		kind: 'unknown',
		field: 'hostKey',
		fingerprint: before,
		keyType: 'ssh-ed25519',
		expected: '',
		before: '',
	};
	const differs = ( data ) => ( {
		code: 'lw_migration_host_key_differs',
		message: 'x',
		status: 409,
		data,
	} );

	it( 'turns a key that changed before the trust into a changed key against the one shown before', () => {
		expect(
			hostKeyDiffers(
				shownBefore,
				differs( { hostKey: now, hostKeyType: 'ssh-rsa' } )
			)
		).toEqual( {
			kind: 'changed',
			field: 'hostKey',
			fingerprint: now,
			keyType: 'ssh-rsa',
			expected: before,
			before: 'shown',
		} );
		expect(
			hostKeyDiffers( shownBefore, differs( { hostKey: now } ) ).keyType
		).toBe( '' );
	} );

	it( 'reads a changed certificate under its own field', () => {
		const cert = Array( 32 ).fill( 'AB' ).join( ':' );
		const certNow = Array( 32 ).fill( 'EF' ).join( ':' );
		const certBefore = {
			...shownBefore,
			field: 'certFingerprint',
			fingerprint: cert,
			keyType: '',
		};

		expect(
			hostKeyDiffers(
				certBefore,
				differs( { certFingerprint: certNow } )
			)
		).toEqual( {
			kind: 'changed',
			field: 'certFingerprint',
			fingerprint: certNow,
			keyType: '',
			expected: cert,
			before: 'shown',
		} );
		expect(
			hostKeyDiffers( certBefore, differs( { hostKey: now } ) )
		).toBeNull();
	} );

	it( 'ignores other refusals, no shown key and anything that is not a new fingerprint', () => {
		expect(
			hostKeyDiffers( null, differs( { hostKey: now } ) )
		).toBeNull();
		expect(
			hostKeyDiffers( shownBefore, differs( { hostKey: 'MD5:ab' } ) )
		).toBeNull();
		expect(
			hostKeyDiffers( shownBefore, differs( { hostKey: before } ) )
		).toBeNull();
		expect(
			hostKeyDiffers( shownBefore, {
				code: 'lw_migration_destination_changed',
				data: { hostKey: now },
			} )
		).toBeNull();
	} );

	it( 'uses the code and the data keys of DestinationTrust', () => {
		const source = phpSource( 'Rest/DestinationTrust.php' );

		expect( source ).toContain( "'lw_migration_host_key_differs'" );
		expect( source ).toContain( '$shown = [ $field =>' );
		expect( source ).toMatch(
			/'lw_migration_host_key_differs',[^;]*409,\s*\$shown \+ \[\s*'hostKeyType'\s*=>/
		);
	} );
} );

describe( 'the connect marker', () => {
	const type = {
		type: 'gdrive',
		fields: [
			{
				key: 'clientId',
				kind: 'text',
				required: true,
				default: null,
				when: {},
			},
			{
				key: 'clientSecret',
				kind: 'secret',
				required: true,
				default: null,
				when: {},
			},
			{
				key: 'account',
				kind: 'oauth',
				required: false,
				default: null,
				when: {},
			},
		],
		oauth: { redirectUri: 'https://s.test/cb', eligible: true },
	};

	it( 'is neither a setting nor a secret, in the form or the request', () => {
		const form = { ...emptyForm( type ), name: 'Drive' };
		form.settings.clientId = 'cid';
		form.secrets.clientSecret = 's';

		expect( Object.keys( form.settings ) ).toEqual( [ 'clientId' ] );
		expect( Object.keys( form.secrets ) ).toEqual( [ 'clientSecret' ] );
		expect( toRequest( form, type, true ) ).toEqual( {
			type: 'gdrive',
			name: 'Drive',
			settings: { clientId: 'cid' },
			secrets: { clientSecret: 's' },
			retention: { count: 0, bytes: 0, days: 0 },
		} );
		expect( checkForm( form, type, null ) ).toBeNull();
	} );

	it( 'always sends the Use your own app switch (the server reads a missing one by the Client ID)', () => {
		const modal = {
			...type,
			fields: [
				{
					key: 'ownApp',
					kind: 'toggle',
					required: false,
					default: false,
					when: {},
				},
				...type.fields.map( ( field ) =>
					'account' === field.key
						? field
						: { ...field, when: { ownApp: [ true ] } }
				),
			],
		};
		const form = { ...emptyForm( modal ), name: 'Drive' };
		expect( toRequest( form, modal, true ).settings ).toEqual( {
			ownApp: false,
		} );

		const own = fromItem(
			{ name: 'Drive', settings: { ownApp: true, clientId: 'cid' } },
			modal
		);
		expect( toRequest( own, modal, false ).settings ).toEqual( {
			ownApp: true,
			clientId: 'cid',
		} );
	} );

	it( 'asks for the client secret again with a new Client ID', () => {
		const item = {
			name: 'Drive',
			settings: { clientId: 'cid' },
			secrets: { clientSecret: { set: true } },
			retention: { count: 0, bytes: 0, days: 0 },
			connection: { connected: true },
		};
		const form = fromItem( item, type );
		expect( checkForm( form, type, item ) ).toBeNull();

		form.settings.clientId = 'other';
		expect( checkForm( form, type, item ) ).toEqual( {
			field: 'clientSecret',
			reason: 'newClient',
		} );

		form.secrets.clientSecret = 'new secret';
		expect( checkForm( form, type, item ) ).toBeNull();
	} );
} );

describe( 'deleteText', () => {
	it( 'adds the provider hint only for a connected account (#6)', () => {
		const plain = deleteText( { name: 'Bucket' } );
		const oauth = deleteText( {
			name: 'Drive',
			connection: { connected: true, account: 'a@example.com' },
		} );

		expect( plain ).toContain( 'Bucket' );
		expect( plain ).not.toContain( 'withdraws' );
		expect( oauth ).toContain( 'withdraws its access' );
		expect( oauth ).toContain(
			'Uninstalling the plugin does not withdraw'
		);
		expect(
			deleteText( { name: 'Drive', connection: { connected: false } } )
		).toBe( deleteText( { name: 'Drive' } ) );
	} );
} );

describe( 'a new destination starts in the site folder', () => {
	const type = ( key, field ) => ( {
		type: key,
		fields: [ { key: field, kind: 'text', default: null } ],
	} );

	afterEach( () => {
		site.folder = 'wphu-org';
	} );

	it.each( [
		[ 's3', 'prefix' ],
		[ 'ftp', 'folder' ],
		[ 'sftp', 'folder' ],
		[ 'webdav', 'folder' ],
	] )( '%s: %s is the site folder', ( key, field ) => {
		expect( emptyForm( type( key, field ) ).settings[ field ] ).toBe(
			'wphu-org'
		);
		site.folder = '';
		expect( emptyForm( type( key, field ) ).settings[ field ] ).toBe( '' );
	} );

	it( 'keeps a field default of its own and leaves other fields alone', () => {
		const own = {
			type: 'ftp',
			fields: [
				{ key: 'folder', kind: 'text', default: 'backups' },
				{ key: 'host', kind: 'text', default: null },
			],
		};
		expect( emptyForm( own ).settings ).toEqual( {
			folder: 'backups',
			host: '',
		} );
	} );

	it( 'edit keeps a stored empty folder empty', () => {
		const form = fromItem(
			{
				name: 'NAS',
				settings: { folder: '' },
				secrets: {},
				retention: {},
			},
			type( 'ftp', 'folder' )
		);
		expect( form.settings.folder ).toBe( '' );
	} );
} );
