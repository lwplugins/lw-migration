/**
 * External dependencies
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * WordPress dependencies
 */
import { Button, Notice } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { runtime } from '../backups/hookRuntime';
import { api } from '../data/api';
import AddShelf, { focusShelf } from './AddShelf';
import DestinationCard from './DestinationCard';
import DestinationsLead from './DestinationsLead';
import DestinationsSkeleton from './DestinationsSkeleton';
import DestinationsView from './DestinationsView';
import DraftCard from './DraftCard';
import { button, byType, find, findAll, textOf } from './elementTree';

vi.mock( '@wordpress/element', async () => ( {
	...( await import( '../backups/hookRuntime' ) ).runtime.element,
} ) );
vi.mock( '@wordpress/components', () => ( {
	Button: () => null,
	Notice: () => null,
} ) );
const { createSuccessNotice } = vi.hoisted( () => ( {
	createSuccessNotice: vi.fn(),
} ) );
vi.mock( '@wordpress/data', () => ( {
	useDispatch: () => ( { createSuccessNotice } ),
} ) );
vi.mock( '@wordpress/notices', () => ( { store: 'core/notices' } ) );
vi.mock( '@wordpress/icons', () => ( { plus: 'plus' } ) );
vi.mock( '../components/LoadError', () => ( { default: () => null } ) );
vi.mock( '../data/api', () => ( {
	api: { destinations: vi.fn(), deleteDestination: vi.fn() },
} ) );
vi.mock( '../data/useRemote', () => ( {
	default: () => remote,
} ) );
vi.mock( './AddShelf', async ( importOriginal ) => ( {
	...( await importOriginal() ),
	default: () => null,
	focusShelf: vi.fn(),
} ) );
vi.mock( './DestinationCard', () => ( { default: () => null } ) );
vi.mock( './DestinationsSkeleton', () => ( { default: () => null } ) );
vi.mock( './DraftCard', () => ( {
	default: () => null,
	DRAFT_PREFIX: 'lw-destination-new',
} ) );
vi.mock( './destinations.scss', () => ( {} ) );

let remote;

const SFTP = { type: 'sftp', label: 'SFTP (SSH)', available: true };
const S3 = { type: 's3', label: 'S3-compatible storage', available: true };
const DROPBOX = {
	type: 'dropbox',
	label: 'Dropbox',
	available: true,
	oauth: {},
};
const TYPES = [ SFTP, S3, DROPBOX ];

const dest = ( id, type = 'sftp' ) => ( { id, type, name: `Dest ${ id }` } );
const tile = ( typeKey, preset, label = typeKey ) => ( {
	typeKey,
	preset,
	label,
	available: true,
} );

/**
 * @param {Object[]|null} items The list (null: still loading).
 * @param {Object[]}      types Schema types.
 */
function setList( items, types = TYPES ) {
	remote.data = items ? { items, types } : null;
}

let log;
let elements;

beforeEach( () => {
	log = [];
	elements = {};
	remote = { data: null, error: null, reload: vi.fn( async () => {} ) };
	focusShelf.mockReset();
	api.deleteDestination.mockReset();
	globalThis.window = {
		location: { search: '', href: 'https://site.test/wp-admin/' },
		history: { replaceState: vi.fn() },
	};
	globalThis.document = {
		getElementById: ( id ) => elements[ id ] ?? null,
	};
} );

afterEach( () => {
	delete globalThis.window;
	delete globalThis.document;
} );

const mount = () =>
	runtime.mount( ( p ) => DestinationsView( p ), {
		busy: false,
		onStart: vi.fn(),
	} );
const shelf = ( hook ) => byType( hook.result(), AddShelf );
const draft = ( hook ) => byType( hook.result(), DraftCard );
const cards = ( hook ) =>
	findAll( hook.result(), ( el ) => el.type === DestinationCard );
const card = ( hook, id ) =>
	cards( hook ).find( ( el ) => el.props.item.id === id );

describe( 'DestinationsView', () => {
	it( 'draws the skeleton while the list loads', () => {
		setList( null );
		const hook = mount();
		expect( hook.result().type ).toBe( DestinationsSkeleton );
		hook.unmount();
	} );

	it( 'asks where the backups should go when there is none yet', () => {
		setList( [] );
		const hook = mount();
		expect(
			find( hook.result(), ( el ) => DestinationsLead === el.type )
		).not.toBe( null );
		expect( find( hook.result(), ( el ) => 'ul' === el.type ) ).toBe(
			null
		);
		expect( button( hook.result(), Button, 'New destination' ) ).toBe(
			null
		);
		expect( shelf( hook ).props ).toMatchObject( {
			empty: true,
			full: false,
			types: TYPES,
		} );
		hook.unmount();
	} );

	it( 'lists four destinations above the shelf, with the lead line', () => {
		setList( [ dest( 'a' ), dest( 'b' ), dest( 'c' ), dest( 'd' ) ] );
		const hook = mount();
		const list = find( hook.result(), ( el ) => 'ul' === el.type );
		expect( list.props.className ).toBe( 'lw-destinations' );
		expect( cards( hook ).map( ( el ) => el.props.item.id ) ).toEqual( [
			'a',
			'b',
			'c',
			'd',
		] );
		expect( card( hook, 'a' ).props.type ).toBe( SFTP );
		expect( shelf( hook ).props ).toMatchObject( {
			empty: false,
			full: false,
		} );
		const children = hook.result().props.children.filter( Boolean );
		expect( children.indexOf( list ) ).toBeLessThan(
			children.indexOf( shelf( hook ) )
		);
		hook.unmount();
	} );

	it( 'names the destination as the card has it now in the success snackbar', () => {
		setList( [ dest( 'a' ) ] );
		const hook = mount();
		createSuccessNotice.mockReset();
		card( hook, 'a' ).props.onWorks( 'Renamed A' );
		expect( createSuccessNotice ).toHaveBeenCalledWith(
			'The connection to Renamed A works.',
			{ type: 'snackbar' }
		);
		hook.unmount();
	} );

	it( 'closes the shelf at 20 destinations', () => {
		setList( Array.from( { length: 20 }, ( _, i ) => dest( `id${ i }` ) ) );
		const hook = mount();
		expect( shelf( hook ).props.full ).toBe( true );
		hook.unmount();
	} );

	it( 'keeps the notice for a type this site cannot use', () => {
		const off = {
			type: 'ftp',
			label: 'FTP or FTPS',
			available: false,
			reason: 'needs the FTP extension',
		};
		setList( [ dest( 'a' ) ], [ ...TYPES, off ] );
		const hook = mount();
		const notices = findAll(
			hook.result(),
			( el ) => el.type === Notice
		).map( textOf );
		expect( notices ).toContain(
			'FTP or FTPS cannot be used here: needs the FTP extension'
		);
		expect( shelf( hook ).props.types ).toContain( off );
		hook.unmount();
	} );

	it( 'leads with the section title and no button of its own', () => {
		setList( [ dest( 'a' ) ] );
		const hook = mount();
		expect(
			find( hook.result(), ( el ) => DestinationsLead === el.type )
		).not.toBe( null );
		expect( button( hook.result(), Button, 'New destination' ) ).toBe(
			null
		);
		hook.unmount();
	} );

	it( 'opens one draft for a picked tile; another tile replaces it', () => {
		setList( [ dest( 'a' ) ] );
		const hook = mount();
		expect( draft( hook ) ).toBe( null );

		shelf( hook ).props.onPick( tile( 'sftp', undefined, 'SFTP (SSH)' ) );
		const first = draft( hook );
		expect( first.props.type ).toBe( SFTP );
		expect( first.props.draft ).toMatchObject( {
			typeKey: 'sftp',
			preset: '',
			name: 'SFTP (SSH)',
		} );
		expect( shelf( hook ).props.isPressed( tile( 'sftp' ) ) ).toBe( true );

		shelf( hook ).props.onPick(
			tile( 's3', 'hetzner', 'Hetzner Object Storage' )
		);
		const second = draft( hook );
		expect(
			findAll( hook.result(), ( el ) => el.type === DraftCard )
		).toHaveLength( 1 );
		expect( second.key ).not.toBe( first.key );
		expect( second.props.draft ).toMatchObject( {
			typeKey: 's3',
			preset: 'hetzner',
			name: 'Hetzner Object Storage',
		} );
		const { isPressed } = shelf( hook ).props;
		expect( isPressed( tile( 'sftp' ) ) ).toBe( false );
		expect( isPressed( tile( 's3', 'hetzner' ) ) ).toBe( true );
		expect( isPressed( tile( 's3', 'aws' ) ) ).toBe( false );
		expect( remote.reload ).not.toHaveBeenCalled();
		hook.unmount();
	} );

	it( 'keeps the open draft when its own tile is picked again', () => {
		setList( [] );
		const hook = mount();
		const name = { focus: vi.fn() };
		elements[ 'lw-destination-new-name' ] = name;
		shelf( hook ).props.onPick( tile( 'sftp' ) );
		const key = draft( hook ).key;
		shelf( hook ).props.onPick( tile( 'sftp' ) );
		expect( draft( hook ).key ).toBe( key );
		expect( name.focus ).toHaveBeenCalled();
		hook.unmount();
	} );

	it( 'presses the tile of the provider chosen in the draft', () => {
		setList( [] );
		const hook = mount();
		shelf( hook ).props.onPick( tile( 's3', 'hetzner' ) );
		draft( hook ).props.onPreset( 'aws' );
		const { isPressed } = shelf( hook ).props;
		expect( isPressed( tile( 's3', 'aws' ) ) ).toBe( true );
		expect( isPressed( tile( 's3', 'hetzner' ) ) ).toBe( false );
		hook.unmount();
	} );

	it( 'reads the list again when an account draft is replaced (its stored account shows up)', () => {
		setList( [] );
		const hook = mount();
		shelf( hook ).props.onPick( tile( 'dropbox' ) );
		shelf( hook ).props.onPick( tile( 'sftp' ) );
		expect( remote.reload ).toHaveBeenCalledTimes( 1 );
		hook.unmount();
	} );

	it( 'discards the draft and gives the focus back to its tile', () => {
		setList( [] );
		const hook = mount();
		const hetzner = { focus: vi.fn() };
		elements[ 'lw-dest-pick-s3-hetzner' ] = hetzner;
		shelf( hook ).props.onPick( tile( 's3', 'hetzner' ) );
		draft( hook ).props.onDiscard();
		expect( draft( hook ) ).toBe( null );
		expect( hetzner.focus ).toHaveBeenCalled();
		hook.unmount();
	} );

	it( 'saves a server: closes the draft, reads the list, checks the new card, then focuses its heading', async () => {
		setList( [ dest( 'a' ) ] );
		const hook = mount();
		const saved = dest( 'new1' );
		remote.reload.mockImplementation( async () => {
			log.push( 'reload' );
			setList( [ dest( 'a' ), saved ] );
		} );
		elements[ 'lw-dest-new1-title' ] = {
			focus: () =>
				log.push(
					`focus (check: ${
						card( hook, 'new1' ).props.checkOnMount
					})`
				),
		};
		shelf( hook ).props.onPick( tile( 'sftp' ) );

		await draft( hook ).props.onSaved( saved, { connected: true } );
		expect( draft( hook ) ).toBe( null );
		expect( log ).toEqual( [ 'reload', 'focus (check: true)' ] );
		// Checked once: the flag is spent after the focus moved.
		expect( card( hook, 'new1' ).props.checkOnMount ).toBe( false );
		expect( card( hook, 'a' ).props.checkOnMount ).toBe( false );
		hook.unmount();
	} );

	it( 'does not check an account handed over after a failed connect, and focuses its card', async () => {
		setList( [] );
		const hook = mount();
		const saved = dest( 'acc1', 'dropbox' );
		remote.reload.mockImplementation( async () => {
			setList( [ saved ] );
		} );
		const heading = { focus: vi.fn() };
		elements[ 'lw-dest-acc1-title' ] = heading;
		shelf( hook ).props.onPick( tile( 'dropbox' ) );

		await draft( hook ).props.onSaved( saved, { connected: false } );
		expect( remote.reload ).toHaveBeenCalledTimes( 1 );
		expect( card( hook, 'acc1' ).props.checkOnMount ).toBe( false );
		expect( heading.focus ).toHaveBeenCalled();
		hook.unmount();
	} );

	it( 'after a delete focuses the next card, or the shelf once none is left', async () => {
		setList( [ dest( 'a' ), dest( 'b' ) ] );
		const hook = mount();
		api.deleteDestination.mockResolvedValue( { warnings: [] } );
		remote.reload.mockImplementation( async () => {
			setList( [ dest( 'b' ) ] );
		} );
		const next = { focus: vi.fn() };
		elements[ 'lw-dest-b-title' ] = next;

		await card( hook, 'a' ).props.onDelete( dest( 'a' ) );
		expect( api.deleteDestination ).toHaveBeenCalledWith( 'a' );
		expect( next.focus ).toHaveBeenCalled();
		expect( focusShelf ).not.toHaveBeenCalled();

		remote.reload.mockImplementation( async () => setList( [] ) );
		await card( hook, 'b' ).props.onDelete( dest( 'b' ) );
		expect( focusShelf ).toHaveBeenCalledWith( false );
		hook.unmount();
	} );

	it( 'counts a destination already gone as deleted and shows the warnings', async () => {
		setList( [ dest( 'a' ) ] );
		const hook = mount();
		api.deleteDestination.mockRejectedValue( {
			code: 'lw_migration_destination_missing',
		} );
		remote.reload.mockImplementation( async () => setList( [] ) );
		await card( hook, 'a' ).props.onDelete( dest( 'a' ) );
		expect( focusShelf ).toHaveBeenCalledWith( false );

		setList( [ dest( 'b' ), dest( 'c' ) ] );
		api.deleteDestination.mockResolvedValue( {
			warnings: [ 'The uploads record could not be cleared.' ],
		} );
		remote.reload.mockImplementation( async () =>
			setList( [ dest( 'c' ) ] )
		);
		hook.update( { busy: false, onStart: vi.fn() } );
		await card( hook, 'b' ).props.onDelete( dest( 'b' ) );
		expect(
			findAll( hook.result(), ( el ) => el.type === Notice ).map( textOf )
		).toContain( 'The uploads record could not be cleared.' );
		hook.unmount();
	} );
} );
