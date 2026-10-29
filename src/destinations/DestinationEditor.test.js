/**
 * External dependencies
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * WordPress dependencies
 */
import { Button, Notice } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { runtime } from '../backups/hookRuntime';
import { api } from '../data/api';
import Field from './DestinationField';
import DestinationEditor from './DestinationEditor';
import { button, byType, find, findAll, textOf } from './elementTree';
import KeepSentence from './KeepSentence';
import { OAuthIntro, OwnAppGuide } from './OAuthPanel';

vi.mock( '@wordpress/element', async () => ( {
	...( await import( '../backups/hookRuntime' ) ).runtime.element,
} ) );
vi.mock( '@wordpress/components', () => ( {
	Button: () => null,
	Notice: () => null,
	TextControl: () => null,
} ) );
vi.mock( '../components/DestinationIcon', () => ( {
	presetOf: ( item ) => item.settings?.preset ?? '',
} ) );
vi.mock( '../components/LoginNotice', () => ( {
	default: () => null,
} ) );
vi.mock( '../data/api', () => ( {
	api: {
		createDestination: vi.fn(),
		updateDestination: vi.fn(),
		connectDestination: vi.fn(),
	},
} ) );
vi.mock( '../data/boot', () => ( { SITE_FOLDER: 'wphu-org' } ) );
vi.mock( './DestinationField', () => ( { default: () => null } ) );
vi.mock( './KeepSentence', () => ( {
	default: () => null,
} ) );
vi.mock( './OAuthPanel', () => ( {
	OAuthIntro: () => null,
	OwnAppGuide: () => null,
} ) );

const SFTP = {
	type: 'sftp',
	label: 'SFTP',
	available: true,
	fields: [
		{ key: 'host', kind: 'text', label: 'Host', required: true },
		{ key: 'port', kind: 'number', label: 'Port', default: '22' },
		{ key: 'user', kind: 'text', label: 'User', required: true },
		{ key: 'password', kind: 'secret', label: 'Password' },
	],
};

const DROPBOX = {
	type: 'dropbox',
	label: 'Dropbox',
	available: true,
	oauth: {
		redirectUri: 'https://site.test/cb',
		eligible: true,
		relay: { eligible: true, text: '' },
		steps: [],
		consoleUrl: 'https://console.test',
	},
	fields: [
		{
			key: 'ownApp',
			kind: 'toggle',
			label: 'Use your own app',
			default: false,
		},
		{
			key: 'clientId',
			kind: 'text',
			label: 'Client ID',
			required: true,
			when: { ownApp: [ true ] },
		},
		{ key: 'account', kind: 'oauth', label: 'Account' },
	],
};

const nas = {
	id: 'abcd1234',
	type: 'sftp',
	name: 'Office NAS',
	where: 'sftp://nas.test:22',
	status: 'ok',
	settings: { host: 'nas.test', port: '22', user: 'backup' },
	secrets: { password: { set: true } },
	retention: { count: 0, bytes: 0, days: 0 },
};

/**
 * @param {Object} props Editor props over the defaults.
 * @return {Object} The mounted editor, its callbacks and the last head.
 */
function mount( props ) {
	const heads = [];
	const callbacks = { onSaved: vi.fn(), onCancel: vi.fn() };
	const hook = runtime.mount( ( p ) => DestinationEditor( p ), {
		...callbacks,
		renderHead: ( head ) => {
			heads.push( head );
			return null;
		},
		...props,
	} );
	return { hook, ...callbacks, head: () => heads[ heads.length - 1 ] };
}

const field = ( hook, key ) =>
	findAll( hook.result(), ( el ) => el.type === Field ).find(
		( el ) => el.props.field.key === key
	);
const notices = ( hook ) =>
	findAll( hook.result(), ( el ) => el.type === Notice ).map( ( el ) =>
		textOf( el )
	);
const submit = ( hook ) =>
	hook.result().props.onSubmit( { preventDefault: () => {} } );
const MOVED =
	'If you change the location, the backups already there stay at the old one.';

describe( 'DestinationEditor', () => {
	beforeEach( () => {
		api.updateDestination.mockReset();
		api.createDestination.mockReset();
		api.connectDestination.mockReset();
	} );

	it( 'edits a server: its fields with the edit ids, the retention line, Save and check', () => {
		const { hook, head } = mount( { mode: 'edit', type: SFTP, item: nas } );
		expect( field( hook, 'host' ).props.idPrefix ).toBe( 'lw-destination' );
		expect( byType( hook.result(), KeepSentence ).props.id ).toBe(
			'lw-destination-keep'
		);
		expect(
			button( hook.result(), Button, 'Save and check' )
		).not.toBeNull();
		expect( button( hook.result(), Button, 'Cancel' ) ).not.toBeNull();
		expect( head().where ).toBe( 'sftp://nas.test:22' );
		expect( head().nameInput.props.children[ 0 ].props.id ).toBe(
			'lw-destination-name'
		);
		hook.unmount();
	} );

	it( 'takes another id prefix for a draft', () => {
		const { hook, head } = mount( {
			mode: 'new',
			type: SFTP,
			idPrefix: 'lw-destination-new',
		} );
		expect( field( hook, 'host' ).props.idPrefix ).toBe(
			'lw-destination-new'
		);
		expect( head().nameInput.props.children[ 0 ].props.id ).toBe(
			'lw-destination-new-name'
		);
		expect( head().where ).toBe( '' );
		hook.unmount();
	} );

	it( 'previews where the backups go while the address is typed', () => {
		const { hook, head } = mount( { mode: 'new', type: SFTP } );
		field( hook, 'host' ).props.onSetting( 'nas.example.test' );
		expect( head().where ).toBe( 'sftp://nas.example.test:22' );
		field( hook, 'port' ).props.onSetting( '2222' );
		expect( head().where ).toBe( 'sftp://nas.example.test:2222' );
		hook.unmount();
	} );

	it( 'notes that the backups stay behind only when the location changes', () => {
		const { hook } = mount( { mode: 'edit', type: SFTP, item: nas } );
		expect( notices( hook ) ).not.toContain( MOVED );
		field( hook, 'user' ).props.onSetting( 'other' );
		expect( notices( hook ) ).not.toContain( MOVED );
		field( hook, 'host' ).props.onSetting( 'new.test' );
		expect( notices( hook ) ).toContain( MOVED );
		field( hook, 'host' ).props.onSetting( 'nas.test' );
		expect( notices( hook ) ).not.toContain( MOVED );
		hook.unmount();
	} );

	it( 'saves an edit and hands it over to be checked', async () => {
		const saved = { ...nas, settings: { ...nas.settings, user: 'other' } };
		api.updateDestination.mockResolvedValue( saved );
		const { hook, onSaved } = mount( {
			mode: 'edit',
			type: SFTP,
			item: nas,
		} );
		field( hook, 'user' ).props.onSetting( 'other' );
		await submit( hook );
		expect( api.updateDestination ).toHaveBeenCalledWith(
			'abcd1234',
			expect.objectContaining( {
				name: 'Office NAS',
				settings: { host: 'nas.test', port: '22', user: 'other' },
				secrets: {},
			} )
		);
		expect( api.createDestination ).not.toHaveBeenCalled();
		expect( onSaved ).toHaveBeenCalledWith( saved, { connected: true } );
		hook.unmount();
	} );

	it( 'asks for an empty required field before sending', async () => {
		const { hook, onSaved } = mount( {
			mode: 'edit',
			type: SFTP,
			item: nas,
		} );
		field( hook, 'host' ).props.onSetting( '' );
		await submit( hook );
		expect( api.updateDestination ).not.toHaveBeenCalled();
		expect( field( hook, 'host' ).props.error ).toBe( 'Fill this in.' );
		expect( onSaved ).not.toHaveBeenCalled();
		hook.unmount();
	} );

	it( 'shows a refusal that names a field under it', async () => {
		api.updateDestination.mockRejectedValue( {
			code: 'lw_migration_invalid',
			message: 'The host is not valid.',
			data: { status: 400, field: 'host' },
		} );
		const { hook } = mount( { mode: 'edit', type: SFTP, item: nas } );
		await submit( hook );
		expect( field( hook, 'host' ).props.error ).toBe(
			'The host is not valid.'
		);
		hook.unmount();
	} );

	it( "focuses the refused field of this card's form, by its own id", async () => {
		api.updateDestination.mockRejectedValue( {
			code: 'lw_migration_invalid',
			message: 'The host is not valid.',
			data: { status: 400, field: 'host' },
		} );
		const { hook } = mount( {
			mode: 'edit',
			type: SFTP,
			item: nas,
			idPrefix: 'lw-destination-abcd1234',
		} );
		expect( field( hook, 'host' ).props.idPrefix ).toBe(
			'lw-destination-abcd1234'
		);
		const target = { focus: vi.fn(), closest: () => null };
		const querySelector = vi.fn( () => target );
		hook.result().props.ref.current = { querySelector };
		await submit( hook );
		expect( querySelector ).toHaveBeenLastCalledWith(
			'#lw-destination-abcd1234-host'
		);
		expect( target.focus ).toHaveBeenCalledTimes( 1 );
		hook.unmount();
	} );

	it( 'reads the list again when an edited destination is gone', async () => {
		api.updateDestination.mockRejectedValue( {
			code: 'lw_migration_destination_missing',
			message: 'This destination does not exist.',
			data: { status: 404 },
		} );
		const onMissing = vi.fn();
		const { hook } = mount( {
			mode: 'edit',
			type: SFTP,
			item: nas,
			onMissing,
		} );
		await submit( hook );
		expect( onMissing ).toHaveBeenCalledTimes( 1 );
		hook.unmount();
	} );

	it( 'creates a new account again when the one it stored is gone', async () => {
		const saved = {
			id: 'eeee5555',
			type: 'dropbox',
			name: 'Team',
			settings: { ownApp: false },
			connection: { connected: false, eligible: true },
		};
		api.createDestination.mockResolvedValue( saved );
		api.updateDestination.mockRejectedValue( {
			code: 'lw_migration_destination_missing',
			message: 'This destination does not exist.',
			data: { status: 404 },
		} );
		api.connectDestination.mockRejectedValue( {
			code: 'lw_migration_refused',
			message: 'The provider refused this site.',
		} );
		const onMissing = vi.fn();
		const { hook, head, onSaved, onCancel } = mount( {
			mode: 'new',
			type: DROPBOX,
			onMissing,
		} );
		head().nameInput.props.children[ 0 ].props.onChange( 'Team' );

		await submit( hook ); // Stored, the connect failed.
		await submit( hook ); // The stored one is gone meanwhile.
		expect( api.updateDestination ).toHaveBeenCalledTimes( 1 );
		expect( onMissing ).not.toHaveBeenCalled();
		expect( notices( hook ) ).toContain(
			'This destination does not exist.'
		);

		// Nothing stored is left to hand over.
		button( hook.result(), Button, 'Cancel' ).props.onClick();
		expect( onCancel ).toHaveBeenCalledTimes( 1 );
		expect( onSaved ).not.toHaveBeenCalled();

		await submit( hook ); // Created again, not updated.
		expect( api.createDestination ).toHaveBeenCalledTimes( 2 );
		expect( api.updateDestination ).toHaveBeenCalledTimes( 1 );
		hook.unmount();
	} );

	it( 'puts a new account first: the connect block with the big button, the own app under Advanced', () => {
		const { hook } = mount( { mode: 'new', type: DROPBOX } );
		const tree = hook.result();
		const body = tree.props.children[ 1 ];
		const connect = body.props.children[ 0 ];
		expect( connect.props.className ).toBe( 'lw-connect' );
		expect( byType( connect, OAuthIntro ) ).not.toBeNull();
		expect(
			button( connect, Button, 'Save and connect: Dropbox' )
		).not.toBeNull();

		const details = byType( tree, 'details' );
		expect( details.props.open ).toBe( false );
		expect( textOf( byType( details, 'summary' ) ) ).toBe(
			'Advanced: use your own app'
		);
		expect( field( hook, 'ownApp' ) ).toBeDefined();
		expect( field( hook, 'account' ) ).toBeUndefined();
		expect( byType( details, OwnAppGuide ) ).toBeNull();
		// The footer only cancels: the big button saves.
		const foot = find(
			tree,
			( el ) => 'lw-dest-form__foot' === el.props.className
		);
		expect( findAll( foot, ( el ) => el.type === Button ) ).toHaveLength(
			1
		);
		expect( button( foot, Button, 'Cancel' ) ).not.toBeNull();
		hook.unmount();
	} );

	it( 'opens Advanced for an account that uses its own app', () => {
		const item = {
			id: 'dddd4444',
			type: 'dropbox',
			name: 'Own',
			where: 'Dropbox',
			status: 'ok',
			settings: { ownApp: true, clientId: 'abc' },
			secrets: { clientSecret: { set: true } },
			retention: { count: 0, bytes: 0, days: 0 },
			connection: { connected: true, eligible: true, account: 'x' },
		};
		const { hook } = mount( { mode: 'edit', type: DROPBOX, item } );
		const details = byType( hook.result(), 'details' );
		expect( details.props.open ).toBe( true );
		expect( byType( details, OwnAppGuide ) ).not.toBeNull();
		expect( field( hook, 'clientId' ) ).toBeDefined();
		// A connected account is only saved (no check, no connect).
		expect( button( hook.result(), Button, 'Save' ) ).not.toBeNull();
		hook.unmount();
	} );

	it( 'shows the server line and no move warning for an account in edit mode', () => {
		const item = {
			id: 'ffff6666',
			type: 'dropbox',
			name: 'Team',
			where: 'Dropbox · old-site',
			status: 'ok',
			settings: { ownApp: false },
			secrets: {},
			retention: { count: 0, bytes: 0, days: 0 },
			connection: { connected: true, eligible: true, account: 'x' },
		};
		const { hook, head } = mount( { mode: 'edit', type: DROPBOX, item } );
		expect( head().where ).toBe( 'Dropbox · old-site' );
		expect( notices( hook ) ).not.toContain( MOVED );
		hook.unmount();
	} );

	it( 'previews the site folder only for a new, not yet stored account', () => {
		const { hook, head } = mount( { mode: 'new', type: DROPBOX } );
		expect( head().where ).toBe( 'Dropbox · wphu-org' );
		expect( notices( hook ) ).not.toContain( MOVED );
		hook.unmount();
	} );

	it( 'shows no move warning for an account stored before its connect failed', async () => {
		const saved = {
			id: 'eeee7777',
			type: 'dropbox',
			name: 'Team',
			where: 'Dropbox · old-site',
			settings: { ownApp: false },
			connection: { connected: false, eligible: true },
		};
		api.createDestination.mockResolvedValue( saved );
		api.connectDestination.mockRejectedValue( {
			code: 'lw_migration_refused',
			message: 'The provider refused this site.',
		} );
		const { hook, head } = mount( { mode: 'new', type: DROPBOX } );
		head().nameInput.props.children[ 0 ].props.onChange( 'Team' );
		await submit( hook );
		expect( head().where ).toBe( 'Dropbox · old-site' );
		expect( notices( hook ) ).not.toContain( MOVED );
		hook.unmount();
	} );

	it( 'keeps a saved account after a failed connect: a second press updates it, Cancel hands it over', async () => {
		const saved = {
			id: 'eeee5555',
			type: 'dropbox',
			name: 'Team',
			settings: { ownApp: false },
			connection: { connected: false, eligible: true },
		};
		api.createDestination.mockResolvedValue( saved );
		api.updateDestination.mockResolvedValue( saved );
		api.connectDestination.mockRejectedValue( {
			code: 'lw_migration_refused',
			message: 'The provider refused this site.',
		} );
		const { hook, head, onSaved, onCancel } = mount( {
			mode: 'new',
			type: DROPBOX,
		} );
		head().nameInput.props.children[ 0 ].props.onChange( 'Team' );

		await submit( hook );
		expect( api.createDestination ).toHaveBeenCalledTimes( 1 );
		expect( api.connectDestination ).toHaveBeenCalledWith( 'eeee5555' );
		expect( notices( hook ) ).toContain(
			'The provider refused this site.'
		);
		expect( onSaved ).not.toHaveBeenCalled();

		await submit( hook );
		expect( api.createDestination ).toHaveBeenCalledTimes( 1 );
		expect( api.updateDestination ).toHaveBeenCalledWith(
			'eeee5555',
			expect.objectContaining( { name: 'Team' } )
		);

		button( hook.result(), Button, 'Cancel' ).props.onClick();
		expect( onCancel ).not.toHaveBeenCalled();
		expect( onSaved ).toHaveBeenCalledWith( saved, { connected: false } );
		hook.unmount();
	} );

	it( 'starts a new destination with the given name, and shows a footer note', () => {
		const { hook, head } = mount( {
			mode: 'new',
			type: SFTP,
			startName: 'SFTP (SSH)',
			footNote: 'The result shows on this card.',
		} );
		const note = find(
			hook.result(),
			( el ) => 'lw-dest-form__note' === el.props.className
		);
		expect( textOf( note ) ).toBe( 'The result shows on this card.' );
		expect( head().nameInput.props.children[ 0 ].props.value ).toBe(
			'SFTP (SSH)'
		);
		hook.unmount();
	} );

	it( 'cancels an untouched form', () => {
		const { hook, onSaved, onCancel } = mount( {
			mode: 'edit',
			type: SFTP,
			item: nas,
			cancelLabel: 'Discard',
		} );
		button( hook.result(), Button, 'Discard' ).props.onClick();
		expect( onCancel ).toHaveBeenCalledTimes( 1 );
		expect( onSaved ).not.toHaveBeenCalled();
		hook.unmount();
	} );
} );
