/**
 * External dependencies
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Internal dependencies
 */
import { runtime } from '../backups/hookRuntime';
import { api } from './api';
import useCopiesCheck, {
	copiesToCheck,
	resetCopiesCheck,
} from './useCopiesCheck';

vi.mock( '@wordpress/element', async () => ( {
	...( await import( '../backups/hookRuntime' ) ).runtime.element,
} ) );
vi.mock( './api', () => ( { api: { remoteFiles: vi.fn() } } ) );

const up = ( id ) => ( { id, status: 'up', at: 1 } );
const ITEMS = [
	{ id: 'own:a.wpress', cloud: [ up( 'aaaa1111' ), up( 'bbbb2222' ) ] },
	{ id: 'own:b.wpress', cloud: [ up( 'aaaa1111' ) ] },
	{
		id: 'own:c.wpress',
		cloud: [ { id: 'cccc3333', status: 'failed', at: 1 } ],
	},
];
const DESTINATIONS = [
	{ id: 'aaaa1111', connection: null },
	{ id: 'bbbb2222', connection: { connected: true } },
	{ id: 'cccc3333', connection: null },
];

const remote = ( data ) => ( { data, error: null, isLoading: false } );
const mount = ( reload, destinations = DESTINATIONS ) =>
	runtime.mount( ( { list, cloud } ) => useCopiesCheck( list, cloud ), {
		list: { data: { items: ITEMS }, reload },
		cloud: remote( { items: destinations } ),
	} );
// Lets the pending promise callbacks run.
const settle = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

describe( 'copiesToCheck', () => {
	it( 'asks every destination but one waiting for its account; without them known, the ones a copy names', () => {
		const destinations = [
			{ id: 'aaaa1111', connection: { connected: false } },
			{ id: 'bbbb2222', connection: null },
			{ id: 'eeee5555', connection: { connected: true } },
		];
		const items = [
			{ cloud: [ up( 'aaaa1111' ), up( 'bbbb2222' ), up( 'dddd4444' ) ] },
		];

		expect( copiesToCheck( items, destinations ) ).toEqual( [
			'bbbb2222',
			'eeee5555',
		] );
		expect( copiesToCheck( items, null ) ).toEqual( [
			'aaaa1111',
			'bbbb2222',
			'dddd4444',
		] );
	} );

	it( 'asks nothing for an empty list', () => {
		expect( copiesToCheck( [], DESTINATIONS ) ).toEqual( [] );
	} );
} );

describe( 'useCopiesCheck', () => {
	beforeEach( () => {
		resetCopiesCheck();
		api.remoteFiles.mockReset();
	} );

	it( 'lists every destination once, also one no copy names yet', async () => {
		api.remoteFiles.mockResolvedValue( { files: [] } );
		const hook = mount( vi.fn() );
		await settle();

		expect( api.remoteFiles.mock.calls ).toEqual( [
			[ 'aaaa1111' ],
			[ 'bbbb2222' ],
			[ 'cccc3333' ],
		] );
		hook.unmount();
	} );

	it( 'skips a destination waiting for its account', async () => {
		api.remoteFiles.mockResolvedValue( { files: [] } );
		const hook = mount( vi.fn(), [
			{ id: 'aaaa1111', connection: { connected: false } },
			{ id: 'bbbb2222', connection: { connected: true } },
		] );
		await settle();

		expect( api.remoteFiles.mock.calls ).toEqual( [ [ 'bbbb2222' ] ] );
		hook.unmount();
	} );

	it( 'loads the backups list again once after a listing changed the copies', async () => {
		api.remoteFiles
			.mockRejectedValueOnce( new Error( 'No answer.' ) )
			.mockResolvedValueOnce( { files: [], copiesChanged: true } )
			.mockResolvedValueOnce( { files: [], copiesChanged: true } );
		const reload = vi.fn();
		const hook = mount( reload );
		await settle();

		expect( reload ).toHaveBeenCalledTimes( 1 );
		hook.unmount();
	} );

	it( 'does not load the list again when no listing changed the copies', async () => {
		api.remoteFiles.mockResolvedValue( {
			files: [],
			copiesChanged: false,
		} );
		const reload = vi.fn();
		const hook = mount( reload );
		await settle();

		expect( reload ).not.toHaveBeenCalled();
		hook.unmount();
	} );

	it( 'does not load the list again when every listing failed', async () => {
		api.remoteFiles.mockRejectedValue( new Error( 'No answer.' ) );
		const reload = vi.fn();
		const hook = mount( reload );
		await settle();

		expect( reload ).not.toHaveBeenCalled();
		hook.unmount();
	} );

	it( 'asks no destination twice in one page load', async () => {
		api.remoteFiles.mockResolvedValue( { files: [] } );
		const reload = vi.fn();
		mount( vi.fn() ).unmount();
		await settle();

		const hook = mount( reload );
		await settle();

		expect( api.remoteFiles ).toHaveBeenCalledTimes( 3 );
		expect( reload ).not.toHaveBeenCalled();
		hook.unmount();
	} );

	it( 'waits for the destinations before asking', async () => {
		api.remoteFiles.mockResolvedValue( { files: [] } );
		const props = {
			list: { data: { items: ITEMS }, reload: vi.fn() },
			cloud: { data: null, error: null, isLoading: true },
		};
		const hook = runtime.mount(
			( { list, cloud } ) => useCopiesCheck( list, cloud ),
			props
		);
		await settle();
		expect( api.remoteFiles ).not.toHaveBeenCalled();

		hook.update( { ...props, cloud: remote( { items: DESTINATIONS } ) } );
		await settle();
		expect( api.remoteFiles ).toHaveBeenCalledTimes( 3 );
		hook.unmount();
	} );
} );
