/**
 * External dependencies
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { runtime } from '../backups/hookRuntime';
import StatusBadge from '../components/StatusBadge';
import { api } from '../data/api';
import DestinationEditor from './DestinationEditor';
import DraftCard from './DraftCard';
import Field from './DestinationField';
import { button, byType, find, findAll, textOf } from './elementTree';

vi.mock( '@wordpress/element', async () => ( {
	...( await import( '../backups/hookRuntime' ) ).runtime.element,
} ) );
vi.mock( '@wordpress/components', () => ( {
	Button: () => null,
	Notice: () => null,
	TextControl: () => null,
} ) );
vi.mock( '../components/DestinationIcon', () => ( {
	default: () => null,
	presetOf: ( item ) => item.settings?.preset ?? '',
} ) );
vi.mock( '../components/LoginNotice', () => ( { default: () => null } ) );
vi.mock( '../components/StatusBadge', () => ( { default: () => null } ) );
vi.mock( '../data/boot', () => ( { SITE_FOLDER: 'wphu-org' } ) );
vi.mock( '../data/api', () => ( {
	api: {
		createDestination: vi.fn(),
		updateDestination: vi.fn(),
		connectDestination: vi.fn(),
	},
} ) );
vi.mock( './DestinationField', () => ( { default: () => null } ) );
vi.mock( './KeepSentence', () => ( { default: () => null } ) );
vi.mock( './OAuthPanel', () => ( {
	OAuthIntro: () => null,
	OwnAppGuide: () => null,
} ) );

const SFTP = {
	type: 'sftp',
	label: 'SFTP (SSH)',
	available: true,
	fields: [ { key: 'host', kind: 'text', label: 'Host' } ],
};

const S3 = {
	type: 's3',
	label: 'S3-compatible storage',
	available: true,
	fields: [
		{
			key: 'preset',
			kind: 'select',
			label: 'Provider',
			default: 'aws',
			options: [
				{ value: 'aws', label: 'Amazon S3' },
				{ value: 'hetzner', label: 'Hetzner Object Storage' },
			],
		},
		{ key: 'bucket', kind: 'text', label: 'Bucket' },
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
	],
};

/**
 * @param {Object} type  Schema type.
 * @param {Object} draft The picked tile.
 * @return {Object} The card's element and its callbacks.
 */
function draftCard( type, draft ) {
	const callbacks = {
		onPreset: vi.fn(),
		onSaved: vi.fn(),
		onDiscard: vi.fn(),
	};
	const card = DraftCard( { type, draft, ...callbacks } );
	return { card, editor: byType( card, DestinationEditor ), ...callbacks };
}

/**
 * @param {Object} editor The DestinationEditor element.
 * @return {Object} The editor mounted with its props, and its last head.
 */
function mountEditor( editor ) {
	const heads = [];
	const hook = runtime.mount( ( p ) => DestinationEditor( p ), {
		...editor.props,
		renderHead: ( head ) => {
			heads.push( head );
			return editor.props.renderHead( head );
		},
	} );
	return { hook, head: () => heads[ heads.length - 1 ] };
}

const submit = ( hook ) =>
	hook.result().props.onSubmit( { preventDefault: () => {} } );

describe( 'DraftCard', () => {
	let assign;

	beforeEach( () => {
		api.createDestination.mockReset();
		api.updateDestination.mockReset();
		api.connectDestination.mockReset();
		assign = vi.fn();
		globalThis.window = { location: { assign } };
	} );

	afterEach( () => {
		delete globalThis.window;
	} );

	it( 'opens the new-mode editor with the draft ids, the name and Discard', () => {
		const { card, editor } = draftCard( SFTP, {
			typeKey: 'sftp',
			preset: '',
			name: 'SFTP (SSH)',
		} );
		expect( card.type ).toBe( 'section' );
		expect( card.props.className ).toBe( 'lw-dest is-draft' );
		expect( card.props[ 'aria-label' ] ).toBe(
			'Setting up a new destination'
		);
		expect( editor.props ).toMatchObject( {
			mode: 'new',
			type: SFTP,
			idPrefix: 'lw-destination-new',
			startName: 'SFTP (SSH)',
			cancelLabel: 'Discard',
		} );
		expect( editor.props.footNote ).toContain( 'on this card' );
	} );

	it( 'shows the hint until there is a place, then the live line', () => {
		const { editor } = draftCard( SFTP, {
			typeKey: 'sftp',
			name: 'SFTP (SSH)',
		} );
		const head = ( where ) =>
			editor.props.renderHead( {
				nameInput: null,
				where,
				preset: '',
			} );
		const line = ( where ) =>
			find( head( where ), ( el ) => 'p' === el.type );

		expect( line( '' ).props.className ).toContain( 'is-empty' );
		expect( line( '' ).props[ 'aria-live' ] ).toBe( 'polite' );
		expect( textOf( line( '' ) ) ).toBe(
			'Fill in the connection: you see here where the backups go.'
		);
		expect( line( 'sftp://nas.test:22' ).props.className ).toContain(
			'is-live'
		);
		expect( textOf( line( 'sftp://nas.test:22' ) ) ).toBe(
			'sftp://nas.test:22'
		);
		expect( textOf( byType( head( '' ), StatusBadge ) ) ).toBe( 'New' );
	} );

	it( 'falls back to the plain folder line when the preview is empty, and has no footer note', () => {
		const { editor } = draftCard( DROPBOX, {
			typeKey: 'dropbox',
			name: 'Dropbox',
		} );
		const head = editor.props.renderHead( {
			nameInput: null,
			where: '',
			preset: '',
		} );
		expect( textOf( find( head, ( el ) => 'p' === el.type ) ) ).toBe(
			'The folder is created after you sign in.'
		);
		expect( editor.props.footNote ).toBe( '' );
	} );

	it( 'starts an S3 draft with the tile provider and its name, and reports a changed provider', () => {
		const { editor, onPreset } = draftCard( S3, {
			typeKey: 's3',
			preset: 'hetzner',
			name: 'Hetzner Object Storage',
		} );
		expect( editor.props.preset ).toBe( 'hetzner' );
		const { hook, head } = mountEditor( editor );
		expect( head().preset ).toBe( 'hetzner' );
		expect( onPreset ).toHaveBeenLastCalledWith( 'hetzner' );
		hook.unmount();
	} );

	it( 'saves a server under the draft name and hands it over to be checked', async () => {
		const saved = { id: 'abcd1234', type: 'sftp', name: 'SFTP (SSH)' };
		api.createDestination.mockResolvedValue( saved );
		const { editor, onSaved } = draftCard( SFTP, {
			typeKey: 'sftp',
			name: 'SFTP (SSH)',
		} );
		const { hook } = mountEditor( editor );
		expect(
			textOf( button( hook.result(), Button, 'Save and check' ) )
		).toBe( 'Save and check' );

		await submit( hook );
		expect( api.createDestination ).toHaveBeenCalledWith(
			expect.objectContaining( { name: 'SFTP (SSH)', type: 'sftp' } )
		);
		expect( onSaved ).toHaveBeenCalledWith( saved, { connected: true } );
		hook.unmount();
	} );

	it( 'saves an account and goes to the provider', async () => {
		api.createDestination.mockResolvedValue( {
			id: 'eeee5555',
			type: 'dropbox',
			name: 'Dropbox',
			settings: { ownApp: false },
			connection: { connected: false, eligible: true },
		} );
		api.connectDestination.mockResolvedValue( {
			url: 'https://www.dropbox.test/oauth2/authorize',
		} );
		const { editor, onSaved } = draftCard( DROPBOX, {
			typeKey: 'dropbox',
			name: 'Dropbox',
		} );
		const { hook } = mountEditor( editor );

		await submit( hook );
		expect( api.connectDestination ).toHaveBeenCalledWith( 'eeee5555' );
		expect( assign ).toHaveBeenCalledWith(
			'https://www.dropbox.test/oauth2/authorize'
		);
		expect( onSaved ).not.toHaveBeenCalled();
		hook.unmount();
	} );

	it( 'renames an S3 draft with the provider while the name is the provider; a typed name stays', () => {
		const { editor } = draftCard( S3, {
			typeKey: 's3',
			preset: 'hetzner',
			name: 'Hetzner Object Storage',
		} );
		const { hook, head } = mountEditor( editor );
		const provider = () =>
			findAll( hook.result(), ( el ) => el.type === Field ).find(
				( el ) => 'preset' === el.props.field.key
			);
		const name = () => head().nameInput.props.children[ 0 ].props.value;

		provider().props.onSetting( 'aws' );
		expect( name() ).toBe( 'Amazon S3' );
		expect( head().preset ).toBe( 'aws' );

		head().nameInput.props.children[ 0 ].props.onChange( 'Offsite' );
		provider().props.onSetting( 'hetzner' );
		expect( name() ).toBe( 'Offsite' );
		expect( head().preset ).toBe( 'hetzner' );
		hook.unmount();
	} );

	it( 'keeps the stored account after a failed connect: a second press updates it, Close hands it over', async () => {
		const saved = {
			id: 'eeee5555',
			type: 'dropbox',
			name: 'Dropbox',
			settings: { ownApp: false },
			connection: { connected: false, eligible: true },
		};
		api.createDestination.mockResolvedValue( saved );
		api.updateDestination.mockResolvedValue( saved );
		api.connectDestination.mockRejectedValue( {
			code: 'lw_migration_refused',
			message: 'The provider refused this site.',
		} );
		const { editor, onSaved, onDiscard } = draftCard( DROPBOX, {
			typeKey: 'dropbox',
			name: 'Dropbox',
		} );
		const { hook } = mountEditor( editor );
		const note = () =>
			find(
				hook.result(),
				( el ) => 'lw-dest-form__note' === el.props.className
			);
		expect( button( hook.result(), Button, 'Discard' ) ).not.toBeNull();
		expect( note() ).toBeNull();

		await submit( hook );
		// Stored now: closing the draft keeps it, and says where to connect.
		expect( button( hook.result(), Button, 'Discard' ) ).toBeNull();
		expect( textOf( note() ) ).toBe(
			'The destination is saved; you can connect it from its card.'
		);
		await submit( hook );
		expect( api.createDestination ).toHaveBeenCalledTimes( 1 );
		expect( api.updateDestination ).toHaveBeenCalledWith(
			'eeee5555',
			expect.objectContaining( { name: 'Dropbox' } )
		);

		button( hook.result(), Button, 'Close' ).props.onClick();
		expect( onDiscard ).not.toHaveBeenCalled();
		expect( onSaved ).toHaveBeenCalledWith( saved, { connected: false } );
		hook.unmount();
	} );
} );
