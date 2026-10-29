/**
 * External dependencies
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * WordPress dependencies
 */
import { speak } from '@wordpress/a11y';
import { Button, DropdownMenu, Notice } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { runtime } from '../backups/hookRuntime';
import DestinationCard from './DestinationCard';
import DestinationEditor from './DestinationEditor';
import { button, byType, elements, find, textOf } from './elementTree';
import HostKeyNotice from './HostKeyNotice';
import { checkConnection, trustHostKey } from './hostKeyFlow';
import InlineConfirm from './InlineConfirm';
import RemoteFilesPanel from './RemoteFilesPanel';

vi.mock( '@wordpress/element', async () => ( {
	...( await import( '../backups/hookRuntime' ) ).runtime.element,
} ) );
vi.mock( '@wordpress/a11y', () => ( { speak: vi.fn() } ) );
vi.mock( '@wordpress/components', () => ( {
	Button: () => null,
	DropdownMenu: () => null,
	Notice: () => null,
} ) );
vi.mock( '@wordpress/icons', () => ( {
	link: 'link',
	moreVertical: 'more',
	pencil: 'pencil',
	trash: 'trash',
} ) );
vi.mock( '../components/DestinationIcon', () => ( {
	default: () => null,
	presetOf: () => '',
} ) );
vi.mock( '../components/LoginNotice', () => ( {
	default: () => null,
} ) );
vi.mock( '../components/StatusBadge', () => ( {
	default: () => null,
} ) );
vi.mock( '../data/api', () => ( { api: { connectDestination: vi.fn() } } ) );
vi.mock( '../data/format', () => ( {
	formatDatetime: ( ts ) => `at ${ ts }`,
} ) );
vi.mock( './DestinationEditor', () => ( {
	default: () => null,
} ) );
vi.mock( './HostKeyNotice', () => ( {
	default: () => null,
} ) );
vi.mock( './InlineConfirm', () => ( {
	default: () => null,
} ) );
vi.mock( './RemoteFilesPanel', () => ( {
	default: () => null,
} ) );
vi.mock( './hostKeyFlow', () => ( {
	checkConnection: vi.fn(),
	trustHostKey: vi.fn(),
} ) );

const SFTP = { type: 'sftp', label: 'SFTP', fields: [] };
const DROPBOX = { type: 'dropbox', label: 'Dropbox', fields: [], oauth: {} };
const KEY = {
	kind: 'unknown',
	field: 'hostKey',
	fingerprint: 'SHA256:' + 'a'.repeat( 43 ),
	keyType: 'ssh-ed25519',
	expected: '',
	before: '',
};

const nas = ( patch = {} ) => ( {
	id: 'abcd1234',
	type: 'sftp',
	name: 'Office NAS',
	where: 'sftp://nas.test:22',
	status: 'new',
	message: '',
	settings: { host: 'nas.test', port: '22' },
	retention: { count: 0, bytes: 0, days: 0 },
	lastUploadAt: 0,
	usedBy: 0,
	...patch,
} );

const dropbox = ( patch = {} ) =>
	nas( {
		type: 'dropbox',
		name: 'Team Dropbox',
		where: 'Dropbox app folder',
		settings: { ownApp: false },
		connection: {
			connected: true,
			account: 'team@example.test',
			eligible: true,
		},
		...patch,
	} );

/**
 * @param {Object} props Card props over the defaults.
 * @return {Object} The mounted card and its callbacks.
 */
function mount( props = {} ) {
	const callbacks = {
		onStart: vi.fn(),
		onDelete: vi.fn( () => Promise.resolve() ),
		onChanged: vi.fn( () => Promise.resolve() ),
		onWorks: vi.fn(),
	};
	const all = {
		item: nas(),
		type: SFTP,
		busy: false,
		...callbacks,
		...props,
	};
	const hook = runtime.mount( ( p ) => DestinationCard( p ), all );
	return { hook, props: all, ...callbacks };
}

const tree = ( hook ) => hook.result();
const filesToggle = ( hook ) => button( tree( hook ), Button, 'Backups there' );
const menu = ( hook ) => byType( tree( hook ), DropdownMenu );
const choose = ( hook, title ) =>
	menu( hook )
		.props.controls.find( ( control ) => control.title === title )
		.onClick();
const open = ( hook ) => ( {
	files: byType( tree( hook ), RemoteFilesPanel ),
	confirm: byType( tree( hook ), InlineConfirm ),
	hostKey: byType( tree( hook ), HostKeyNotice ),
	editor: byType( tree( hook ), DestinationEditor ),
} );
const focusable = () => ( { focus: vi.fn() } );

describe( 'DestinationCard panels', () => {
	beforeEach( () => {
		checkConnection.mockReset();
		trustHostKey.mockReset();
		speak.mockReset();
	} );

	it( 'gives each card in edit its own field ids (two may be open at once)', () => {
		const first = mount();
		choose( first.hook, 'Edit' );
		const one = open( first.hook ).editor.props.idPrefix;
		first.hook.unmount();
		const second = mount( { item: nas( { id: 'ffff0000' } ) } );
		choose( second.hook, 'Edit' );
		const other = open( second.hook ).editor.props.idPrefix;
		second.hook.unmount();

		expect( one ).toBe( 'lw-destination-abcd1234' );
		expect( other ).toBe( 'lw-destination-ffff0000' );
		expect( one ).not.toBe( 'lw-destination-new' ); // The draft's.
	} );

	/**
	 * The check records a failure: the list read again carries it.
	 *
	 * @param {Object} props The card's props.
	 * @param {Object} hook  The mounted card.
	 */
	const failsWithRecord = ( props, hook ) => {
		props.onChanged.mockImplementation( async () =>
			hook.update( {
				...props,
				item: nas( { status: 'error', message: 'Login refused.' } ),
			} )
		);
		checkConnection.mockImplementation( async ( id, card ) => {
			await card.onChanged();
			return { hostKey: null, failure: null };
		} );
	};

	it( 'speaks a failure the check recorded, once the list is read again', async () => {
		const { hook, props } = mount();
		failsWithRecord( props, hook );
		await button(
			tree( hook ),
			Button,
			'Check connection'
		).props.onClick();
		expect( speak ).toHaveBeenCalledTimes( 1 );
		expect( speak ).toHaveBeenCalledWith( 'Login refused.', 'assertive' );
		// The stored message stays on the card.
		expect(
			textOf(
				find( tree( hook ), ( el ) =>
					String( el.props.className ).includes( 'lw-dest__message' )
				)
			)
		).toBe( 'Login refused.' );
		hook.unmount();
	} );

	it( 'speaks the recorded failure of the first check of a card just added', async () => {
		const all = {
			item: nas(),
			type: SFTP,
			busy: false,
			onStart: vi.fn(),
			onDelete: vi.fn(),
			onChanged: vi.fn(),
			onWorks: vi.fn(),
			checkOnMount: true,
		};
		checkConnection.mockImplementation( async ( id, card ) => {
			await Promise.resolve(); // The test runs first: the card is mounted.
			await card.onChanged();
			return { hostKey: null, failure: null };
		} );
		let hook = null;
		all.onChanged.mockImplementation( async () =>
			hook.update( {
				...all,
				item: nas( { status: 'error', message: 'Login refused.' } ),
			} )
		);
		hook = runtime.mount( ( p ) => DestinationCard( p ), all );
		await vi.waitFor( () =>
			expect( speak ).toHaveBeenCalledWith(
				'Login refused.',
				'assertive'
			)
		);
		hook.unmount();
	} );

	it( 'does not speak a stored failure on page load, nor after a check that works', async () => {
		const { hook, props } = mount( {
			item: nas( { status: 'error', message: 'Login refused.' } ),
		} );
		expect( speak ).not.toHaveBeenCalled();

		props.onChanged.mockImplementation( async () =>
			hook.update( { ...props, item: nas( { status: 'ok' } ) } )
		);
		checkConnection.mockImplementation( async ( id, card ) => {
			card.onWorks();
			await card.onChanged();
			return { hostKey: null, failure: null };
		} );
		await button(
			tree( hook ),
			Button,
			'Check connection'
		).props.onClick();
		expect( speak ).not.toHaveBeenCalled();
		expect( props.onWorks ).toHaveBeenCalledWith( 'Office NAS' );
		hook.unmount();
	} );

	it( 'names a renamed destination in the success after "Save and check"', async () => {
		checkConnection.mockImplementation( async ( id, card ) => {
			card.onWorks();
			return { hostKey: null, failure: null };
		} );
		const { hook, onWorks } = mount();
		choose( hook, 'Edit' );
		await open( hook ).editor.props.onSaved(
			nas( { name: 'Basement NAS' } ),
			{ connected: true }
		);
		expect( onWorks ).toHaveBeenCalledTimes( 1 );
		expect( onWorks ).toHaveBeenCalledWith( 'Basement NAS' );
		hook.unmount();
	} );

	it( 'is a list item named by its heading, the facts listed', () => {
		const { hook } = mount( { item: nas( { usedBy: 2 } ) } );
		const li = tree( hook );
		expect( li.type ).toBe( 'li' );
		const heading = find( li, ( el ) => 'h3' === el.type );
		expect( heading.props.id ).toBe( 'lw-dest-abcd1234-title' );
		expect( heading.props.tabIndex ).toBe( -1 );
		expect( li.props[ 'aria-labelledby' ] ).toBe( heading.props.id );
		const facts = find(
			li,
			( el ) => 'lw-dest__facts' === el.props.className
		);
		expect( textOf( facts ) ).toContain( 'Last upload' );
		expect( textOf( facts ) ).toContain( '2 schedules' );
		hook.unmount();
	} );

	it( 'opens one panel at a time and closes the files again', () => {
		const { hook } = mount();
		expect( filesToggle( hook ).props[ 'aria-expanded' ] ).toBe( false );
		expect( filesToggle( hook ).props[ 'aria-controls' ] ).toBe(
			'lw-dest-abcd1234-files'
		);

		filesToggle( hook ).props.onClick();
		expect( open( hook ).files.props.id ).toBe( 'lw-dest-abcd1234-files' );
		expect( filesToggle( hook ).props[ 'aria-expanded' ] ).toBe( true );

		choose( hook, 'Delete' );
		expect( open( hook ).confirm ).not.toBeNull();
		expect( open( hook ).files ).toBeNull();
		expect( filesToggle( hook ).props[ 'aria-expanded' ] ).toBe( false );

		filesToggle( hook ).props.onClick();
		expect( open( hook ).files ).not.toBeNull();
		expect( open( hook ).confirm ).toBeNull();

		filesToggle( hook ).props.onClick();
		expect( open( hook ).files ).toBeNull();
		hook.unmount();
	} );

	it( 'asks before a delete with the plain text, and deletes on confirm', async () => {
		const { hook, onDelete } = mount();
		choose( hook, 'Delete' );
		const { confirm } = open( hook );
		expect( confirm.props.title ).toBe( 'Delete this destination?' );
		expect( confirm.props.text ).toContain(
			'Office NAS and its stored credentials are deleted'
		);
		expect( confirm.props.text ).not.toContain( 'withdraws its access' );
		await confirm.props.onConfirm();
		expect( onDelete ).toHaveBeenCalledWith(
			expect.objectContaining( { id: 'abcd1234' } )
		);
		hook.unmount();
	} );

	it( 'says a connected account loses its access on delete', () => {
		const { hook } = mount( { item: dropbox(), type: DROPBOX } );
		choose( hook, 'Delete' );
		expect( open( hook ).confirm.props.text ).toContain(
			'withdraws its access to the account'
		);
		hook.unmount();
	} );

	it( 'gives the focus back to the menu when the delete is cancelled', () => {
		const { hook, onDelete } = mount();
		const toggle = focusable();
		menu( hook ).props.toggleProps.ref.current = toggle;
		choose( hook, 'Delete' );
		open( hook ).confirm.props.onCancel();
		expect( open( hook ).confirm ).toBeNull();
		expect( toggle.focus ).toHaveBeenCalledTimes( 1 );
		expect( onDelete ).not.toHaveBeenCalled();
		hook.unmount();
	} );

	it( 'shows the host key to confirm as a panel, and Later gives the focus to the check', async () => {
		checkConnection.mockResolvedValue( { hostKey: KEY, failure: null } );
		const { hook } = mount();
		const check = button( tree( hook ), Button, 'Check connection' );
		const target = focusable();
		check.props.ref.current = target;
		filesToggle( hook ).props.onClick();

		await check.props.onClick();
		expect( checkConnection ).toHaveBeenCalledWith(
			'abcd1234',
			expect.any( Object )
		);
		expect( open( hook ).hostKey.props.state ).toBe( KEY );
		expect( open( hook ).files ).toBeNull();

		open( hook ).hostKey.props.onLater();
		expect( open( hook ).hostKey ).toBeNull();
		expect( target.focus ).toHaveBeenCalledTimes( 1 );
		hook.unmount();
	} );

	it( 'checks a destination just added as soon as its card is there, the host key on it', async () => {
		checkConnection.mockResolvedValue( { hostKey: KEY, failure: null } );
		const { hook } = mount( { checkOnMount: true } );
		await vi.waitFor( () =>
			expect( open( hook ).hostKey ).not.toBe( null )
		);
		expect( checkConnection ).toHaveBeenCalledTimes( 1 );
		expect( checkConnection.mock.calls[ 0 ][ 0 ] ).toBe( 'abcd1234' );
		hook.unmount();
	} );

	it( 'does not check on its own otherwise', () => {
		const { hook } = mount();
		expect( checkConnection ).not.toHaveBeenCalled();
		hook.unmount();
	} );

	it( 'closes the host key panel when another panel opens', async () => {
		checkConnection.mockResolvedValue( { hostKey: KEY, failure: null } );
		const { hook } = mount();
		await button(
			tree( hook ),
			Button,
			'Check connection'
		).props.onClick();
		filesToggle( hook ).props.onClick();
		expect( open( hook ).hostKey ).toBeNull();
		expect( open( hook ).files ).not.toBeNull();
		hook.unmount();
	} );

	it( 'closes the host key panel once it is trusted', async () => {
		checkConnection.mockResolvedValue( { hostKey: KEY, failure: null } );
		trustHostKey.mockResolvedValue( {
			hostKey: null,
			failure: null,
			trusted: true,
		} );
		const { hook } = mount();
		await button(
			tree( hook ),
			Button,
			'Check connection'
		).props.onClick();
		await open( hook ).hostKey.props.onTrust();
		expect( trustHostKey ).toHaveBeenCalledWith(
			'abcd1234',
			KEY,
			expect.any( Object )
		);
		expect( open( hook ).hostKey ).toBeNull();
		hook.unmount();
	} );

	it( 'keeps a check failure that recorded nothing as a notice under the row', async () => {
		checkConnection.mockResolvedValue( {
			hostKey: null,
			failure: { code: 'x', message: 'No route to the server.' },
		} );
		const { hook } = mount();
		await button(
			tree( hook ),
			Button,
			'Check connection'
		).props.onClick();
		const notices = elements( tree( hook ) ).filter(
			( el ) => el.type === Notice
		);
		expect( notices.map( ( n ) => textOf( n ) ) ).toContain(
			'No route to the server.'
		);
		hook.unmount();
	} );

	it( 'edits in place, then reads the list again and checks the connection', async () => {
		checkConnection.mockResolvedValue( { hostKey: null, failure: null } );
		const { hook, onChanged } = mount();
		const toggle = focusable();
		menu( hook ).props.toggleProps.ref.current = toggle;
		choose( hook, 'Edit' );
		const { editor } = open( hook );
		expect( editor.props.mode ).toBe( 'edit' );
		expect( editor.props.type ).toBe( SFTP );
		expect( tree( hook ).props.className ).toContain( 'is-editing' );

		// The card's title row hosts the name input and the live line.
		const head = editor.props.renderHead( {
			nameInput: 'NAME',
			where: '',
			preset: '',
		} );
		expect( textOf( head ) ).toContain( 'NAME' );
		expect( textOf( head ) ).toContain( 'sftp://nas.test:22' );
		const live = find( head, ( el ) => 'p' === el.type );
		expect( live.props[ 'aria-live' ] ).toBe( 'polite' );
		expect(
			textOf(
				editor.props.renderHead( {
					nameInput: 'NAME',
					where: 'sftp://other.test:22',
					preset: '',
				} )
			)
		).toContain( 'sftp://other.test:22' );

		expect( menu( hook ) ).toBeNull(); // The row is replaced by the form.
		await editor.props.onSaved( nas(), { connected: true } );
		expect( open( hook ).editor ).toBeNull();
		expect( onChanged ).toHaveBeenCalled();
		expect( checkConnection ).toHaveBeenCalledWith(
			'abcd1234',
			expect.any( Object )
		);
		expect( toggle.focus ).toHaveBeenCalled();
		expect( onChanged.mock.invocationCallOrder[ 0 ] ).toBeLessThan(
			checkConnection.mock.invocationCallOrder[ 0 ]
		);
		hook.unmount();
	} );

	it( 'does not check an account after its edit is saved', async () => {
		const { hook, onChanged } = mount( {
			item: dropbox(),
			type: DROPBOX,
		} );
		choose( hook, 'Edit' );
		await open( hook ).editor.props.onSaved( dropbox(), {
			connected: true,
		} );
		expect( onChanged ).toHaveBeenCalled();
		expect( checkConnection ).not.toHaveBeenCalled();
		hook.unmount();
	} );

	it( 'closes the edit form on cancel without saving', () => {
		const { hook, onChanged } = mount();
		choose( hook, 'Edit' );
		open( hook ).editor.props.onCancel();
		expect( open( hook ).editor ).toBeNull();
		expect( onChanged ).not.toHaveBeenCalled();
		hook.unmount();
	} );

	it( 'has no Edit for a type this site does not know', () => {
		const { hook } = mount( { type: null } );
		const titles = menu( hook ).props.controls.map( ( c ) => c.title );
		expect( titles ).toEqual( [ 'Delete' ] );
		hook.unmount();
	} );

	it( 'starts a job from the backups there and closes the panel', () => {
		const { hook, onStart } = mount();
		filesToggle( hook ).props.onClick();
		open( hook ).files.props.onStart( { id: 'job' } );
		expect( onStart ).toHaveBeenCalledWith( { id: 'job' } );
		expect( open( hook ).files ).toBeNull();
		hook.unmount();
	} );
} );
