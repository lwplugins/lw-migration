/**
 * External dependencies
 */
import { readFileSync } from 'node:fs';
import { afterEach, describe, expect, it, vi } from 'vitest';

/**
 * Internal dependencies
 */
import {
	CLIENT_SECRET,
	DESTINATION_PARAM,
	OAUTH_CODES,
	OAUTH_PARAM,
	OWN_APP,
	clientChanged,
	connectLabel,
	connectsOnSave,
	eligibility,
	forgetsConnection,
	isConnectMarker,
	isOAuth,
	isOwnApp,
	isProviderAddress,
	needsConnect,
	readOAuthNotice,
	TENANT,
	saveDestination,
	withoutOAuthParams,
} from './oauth';

describe( 'the OAuth field keys', () => {
	it( 'are the keys of the PHP types', () => {
		const base = readFileSync(
			new URL(
				'../../includes/Storage/Destinations/OAuth/OAuthDestinationType.php',
				import.meta.url
			),
			'utf8'
		);
		const onedrive = readFileSync(
			new URL(
				'../../includes/Storage/Destinations/OneDrive/OneDriveType.php',
				import.meta.url
			),
			'utf8'
		);
		expect( base ).toContain( `CLIENT_SECRET = '${ CLIENT_SECRET }';` );
		expect( onedrive ).toContain( `TENANT = '${ TENANT }';` );
		const mode = readFileSync(
			new URL(
				'../../includes/Storage/Destinations/OAuth/OAuthMode.php',
				import.meta.url
			),
			'utf8'
		);
		expect( mode ).toContain( `OWN_APP = '${ OWN_APP }';` );
	} );
} );

describe( 'the OAuth callback contract', () => {
	const source = readFileSync(
		new URL( '../../includes/Rest/OAuthApi.php', import.meta.url ),
		'utf8'
	);

	it( 'uses the parameter names and codes of Rest\\OAuthApi', () => {
		expect( source ).toContain( `'${ OAUTH_PARAM }'` );
		expect( source ).toContain( `'${ DESTINATION_PARAM }'` );
		for ( const code of OAUTH_CODES ) {
			expect( source ).toContain( `= '${ code }';` );
		}
	} );
} );

describe( 'readOAuthNotice', () => {
	it( 'reads a known code and the destination', () => {
		const notice = readOAuthNotice(
			'?page=lw-migration&lwm-oauth=connected&lwm-destination=abcd1234'
		);
		expect( notice ).toMatchObject( {
			code: 'connected',
			status: 'success',
			id: 'abcd1234',
		} );
		expect( notice.text ).not.toBe( '' );
	} );

	it( 'ignores an unknown code or none', () => {
		expect( readOAuthNotice( '?lwm-oauth=<script>' ) ).toBeNull();
		expect( readOAuthNotice( '?page=lw-migration' ) ).toBeNull();
	} );

	it( 'ignores the names every object inherits', () => {
		for ( const code of [
			'toString',
			'__proto__',
			'constructor',
			'hasOwnProperty',
		] ) {
			expect( readOAuthNotice( `?lwm-oauth=${ code }` ) ).toBeNull();
		}
	} );

	it( 'maps every code to a tone', () => {
		expect(
			OAUTH_CODES.map(
				( code ) => readOAuthNotice( `?lwm-oauth=${ code }` ).status
			)
		).toEqual( [ 'success', 'warning', 'error', 'error', 'error' ] );
	} );

	it( 'keeps only a destination id of the profile id pattern', () => {
		expect(
			readOAuthNotice( '?lwm-oauth=failed&lwm-destination=ABCD1234' ).id
		).toBe( '' );
		expect(
			readOAuthNotice( '?lwm-oauth=failed&lwm-destination=abcd12345' ).id
		).toBe( '' );
		expect(
			readOAuthNotice( '?lwm-oauth=failed&lwm-destination=<b>x</b>' ).id
		).toBe( '' );
	} );
} );

describe( 'withoutOAuthParams', () => {
	it( 'drops only the two parameters and keeps the page and the hash', () => {
		expect(
			withoutOAuthParams(
				'https://s.test/wp-admin/admin.php?page=lw-migration&lwm-oauth=failed&lwm-destination=abcd1234#destinations'
			)
		).toBe(
			'https://s.test/wp-admin/admin.php?page=lw-migration#destinations'
		);
	} );
} );

describe( 'isProviderAddress', () => {
	it( 'sends the browser only to an https address', () => {
		expect(
			isProviderAddress(
				'https://accounts.google.com/o/oauth2/v2/auth?client_id=x'
			)
		).toBe( true );
		expect( isProviderAddress( 'http://accounts.google.com/' ) ).toBe(
			false
		);
		expect( isProviderAddress( 'javascript:alert(1)' ) ).toBe( false );
		expect( isProviderAddress( '//evil.test/' ) ).toBe( false );
		expect( isProviderAddress( undefined ) ).toBe( false );
	} );
} );

describe( 'connect state', () => {
	const connected = {
		status: 'ok',
		connection: { connected: true, account: 'a@b.c' },
	};

	it( 'knows OAuth types and the connect marker', () => {
		expect( isOAuth( { oauth: { redirectUri: 'x' } } ) ).toBe( true );
		expect( isOAuth( { fields: [] } ) ).toBe( false );
		expect( isConnectMarker( { kind: 'oauth' } ) ).toBe( true );
	} );

	it( 'asks to connect when not connected or when reauth', () => {
		expect( needsConnect( connected ) ).toBe( false );
		expect( needsConnect( { ...connected, status: 'reauth' } ) ).toBe(
			true
		);
		expect(
			needsConnect( { status: 'new', connection: { connected: false } } )
		).toBe( true );
		expect( needsConnect( { status: 'ok', connection: null } ) ).toBe(
			false
		);
	} );

	it( 'labels Connect or Reconnect', () => {
		expect( connectLabel( { connection: { connected: false } } ) ).toBe(
			'Connect'
		);
		expect( connectLabel( connected ) ).toBe( 'Reconnect' );
	} );

	it( 'prefers the profile view over the schema for eligibility', () => {
		const type = {
			oauth: { eligible: true, text: '', relay: { eligible: true } },
		};
		expect( eligibility( type, null ) ).toEqual( {
			eligible: true,
			text: '',
		} );
		expect(
			eligibility( type, {
				connection: { eligible: false, text: 'No query.' },
			} )
		).toEqual( { eligible: false, text: 'No query.' } );
	} );
} );

describe( 'an edit that changes the app or the sign-in settings', () => {
	const item = {
		settings: { clientId: 'cid', tenant: 'common', access: 'appfolder' },
		connection: { connected: true },
	};
	const form = ( settings ) => ( {
		settings: { ...item.settings, ...settings },
	} );

	it( 'notices a new Client ID (it needs its own client secret)', () => {
		expect( clientChanged( form( {} ), item ) ).toBe( false );
		expect( clientChanged( form( { clientId: ' cid ' } ), item ) ).toBe(
			false
		);
		expect( clientChanged( form( { clientId: 'other' } ), item ) ).toBe(
			true
		);
		expect( clientChanged( form( { clientId: 'other' } ), null ) ).toBe(
			false
		);
	} );

	it( 'forgets a connected account on a Client ID, tenant or permission change', () => {
		expect( forgetsConnection( form( {} ), item ) ).toBe( false );
		expect( forgetsConnection( form( { clientId: 'other' } ), item ) ).toBe(
			true
		);
		expect(
			forgetsConnection( form( { tenant: 'contoso.com' } ), item )
		).toBe( true );
		expect( forgetsConnection( form( { access: 'files' } ), item ) ).toBe(
			true
		);
	} );

	it( 'reads the tenant without regard to case, as the server does', () => {
		expect( forgetsConnection( form( { tenant: 'Common' } ), item ) ).toBe(
			false
		);
		expect(
			forgetsConnection( form( { tenant: ' COMMON ' } ), item )
		).toBe( false );
		expect( forgetsConnection( form( { clientId: 'CID' } ), item ) ).toBe(
			true
		);
	} );

	it( 'has nothing to forget without a connected account', () => {
		const loose = { ...item, connection: { connected: false } };
		expect(
			forgetsConnection( form( { clientId: 'other' } ), loose )
		).toBe( false );
		expect( forgetsConnection( form( { clientId: 'other' } ), null ) ).toBe(
			false
		);
	} );
} );

describe( 'saveDestination', () => {
	const type = {
		type: 'gdrive',
		oauth: { redirectUri: 'https://s.test/cb' },
	};
	const fresh = {
		id: 'abcd1234',
		status: 'new',
		connection: { connected: false, eligible: true },
	};
	const fakeApi = (
		saved,
		url = 'https://accounts.google.com/o/oauth2/v2/auth?x=1'
	) => ( {
		createDestination: vi.fn( async () => saved ),
		updateDestination: vi.fn( async () => saved ),
		connectDestination: vi.fn( async () => ( { url } ) ),
	} );
	const assign = vi.fn();

	afterEach( () => {
		vi.unstubAllGlobals();
		assign.mockReset();
	} );

	it( 'creates, remembers, then sends the browser to the sign-in', async () => {
		vi.stubGlobal( 'window', { location: { assign } } );
		const api = fakeApi( fresh );
		const onStored = vi.fn();

		expect(
			await saveDestination( api, null, { name: 'D' }, type, onStored )
		).toBeNull();
		expect( api.createDestination ).toHaveBeenCalledWith( { name: 'D' } );
		expect( onStored ).toHaveBeenCalledWith( fresh );
		expect( api.connectDestination ).toHaveBeenCalledWith( 'abcd1234' );
		expect( assign ).toHaveBeenCalledWith(
			'https://accounts.google.com/o/oauth2/v2/auth?x=1'
		);
	} );

	it( 'updates the remembered destination on a second Save', async () => {
		vi.stubGlobal( 'window', { location: { assign } } );
		const api = fakeApi( fresh );

		await saveDestination( api, fresh, { name: 'D' }, type, () => {} );
		expect( api.updateDestination ).toHaveBeenCalledWith( 'abcd1234', {
			name: 'D',
		} );
		expect( api.createDestination ).not.toHaveBeenCalled();
	} );

	it( 'only saves when connected, not eligible, or not an OAuth type', async () => {
		const cases = [
			[
				{ ...fresh, status: 'ok', connection: { connected: true } },
				type,
			],
			[
				{ ...fresh, connection: { connected: false, eligible: false } },
				type,
			],
			[ { ...fresh, connection: null }, { type: 's3' } ],
		];
		for ( const [ saved, t ] of cases ) {
			const api = fakeApi( saved );
			expect( await saveDestination( api, null, {}, t, () => {} ) ).toBe(
				saved
			);
			expect( api.connectDestination ).not.toHaveBeenCalled();
		}
	} );

	it( 'never sends the browser to an address that is not https', async () => {
		vi.stubGlobal( 'window', { location: { assign } } );
		const api = fakeApi( fresh, 'javascript:alert(1)' );
		const onStored = vi.fn();

		await expect(
			saveDestination( api, null, {}, type, onStored )
		).rejects.toMatchObject( { code: 'lw_migration_invalid_address' } );
		expect( onStored ).toHaveBeenCalledWith( fresh );
		expect( assign ).not.toHaveBeenCalled();
	} );
} );

describe( 'isOwnApp', () => {
	it( 'reads the switch, else a stored Client ID (OAuthMode::ownApp())', () => {
		expect( isOwnApp( { ownApp: true } ) ).toBe( true );
		expect( isOwnApp( { ownApp: false, clientId: 'c' } ) ).toBe( false );
		expect( isOwnApp( { clientId: 'c' } ) ).toBe( true );
		expect( isOwnApp( { clientId: '  ' } ) ).toBe( false );
		expect( isOwnApp( {} ) ).toBe( false );
		expect( isOwnApp( undefined ) ).toBe( false );
	} );
} );

describe( 'eligibility follows the mode', () => {
	const type = {
		oauth: {
			eligible: false,
			text: 'Provider says no.',
			relay: { eligible: true, text: '' },
		},
	};

	it( 'uses the relay rule for the LW app and the provider rule for the own app', () => {
		expect( eligibility( type, null, { ownApp: false } ) ).toEqual( {
			eligible: true,
			text: '',
		} );
		expect( eligibility( type, null, { ownApp: true } ) ).toEqual( {
			eligible: false,
			text: 'Provider says no.',
		} );
	} );

	it( 'uses the profile view only while the form keeps its mode', () => {
		const item = {
			settings: { ownApp: false },
			connection: { eligible: false, text: 'Relay says no.' },
		};
		expect( eligibility( type, item, { ownApp: false } ).text ).toBe(
			'Relay says no.'
		);
		expect( eligibility( type, item, { ownApp: true } ).text ).toBe(
			'Provider says no.'
		);
		expect( eligibility( type, item ).text ).toBe( 'Relay says no.' );
	} );
} );

describe( 'a new LW-app destination the relay rule refuses', () => {
	const type = {
		type: 'dropbox',
		oauth: {
			eligible: true,
			text: '',
			relay: { eligible: false, text: 'Our relay notice.' },
		},
	};
	const form = { settings: { ownApp: false } };

	it( 'is not eligible and shows our text', () => {
		expect( eligibility( type, null, form.settings ) ).toEqual( {
			eligible: false,
			text: 'Our relay notice.',
		} );
	} );

	it( 'keeps Save and connect off, and on for the own app', () => {
		expect( connectsOnSave( type, null, form ) ).toBe( false );
		expect(
			connectsOnSave( type, null, { settings: { ownApp: true } } )
		).toBe( true );
		expect( connectsOnSave( { type: 's3' }, null, form ) ).toBe( false );
	} );

	it( 'fails closed when the type has no relay block', () => {
		const bare = { oauth: { eligible: true, text: '' } };
		expect( eligibility( bare, null, { ownApp: false } ).eligible ).toBe(
			false
		);
		expect( connectsOnSave( bare, null, form ) ).toBe( false );
		expect( eligibility( bare, null, { ownApp: true } ).eligible ).toBe(
			true
		);
	} );
} );
