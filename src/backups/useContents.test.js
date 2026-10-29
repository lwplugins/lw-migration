/**
 * External dependencies
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Internal dependencies
 */
import { api } from '../data/api';
import { runtime } from './hookRuntime';
import { POLL_MS } from './contents';
import useContents from './useContents';

vi.mock( '@wordpress/element', async () => ( {
	...( await import( './hookRuntime' ) ).runtime.element,
} ) );
vi.mock( '../data/api', () => ( { api: { backupContents: vi.fn() } } ) );

const backup = ( patch = {} ) => ( {
	id: 'own:a.wpress',
	folder: 'own',
	name: 'a.wpress',
	mtime: 100,
	size: 10,
	...patch,
} );
const PARTIAL = { partial: true };
const DONE = { partial: false, parts: {} };

const mount = ( props ) =>
	runtime.mount(
		( { backup: chosen, onGone } ) => useContents( chosen, onGone ),
		props
	);
// Lets the pending promise callbacks run.
const settle = () => vi.advanceTimersByTimeAsync( 0 );

describe( 'useContents', () => {
	beforeEach( () => {
		vi.useFakeTimers();
		// The node environment has no window; the hook only needs these.
		vi.stubGlobal( 'window', {
			AbortController,
			setTimeout: ( ...args ) => setTimeout( ...args ),
			clearTimeout: ( ...args ) => clearTimeout( ...args ),
		} );
		api.backupContents.mockReset();
	} );
	afterEach( () => {
		vi.useRealTimers();
		vi.unstubAllGlobals();
	} );

	it( 'asks again every POLL_MS while the answer is partial, then stops', async () => {
		api.backupContents
			.mockResolvedValueOnce( PARTIAL )
			.mockResolvedValueOnce( PARTIAL )
			.mockResolvedValue( DONE );
		const hook = mount( { backup: backup(), onGone: vi.fn() } );
		await settle();
		expect( hook.result().answer ).toBe( PARTIAL );
		expect( api.backupContents ).toHaveBeenCalledTimes( 1 );

		await vi.advanceTimersByTimeAsync( POLL_MS - 1 );
		expect( api.backupContents ).toHaveBeenCalledTimes( 1 );
		await vi.advanceTimersByTimeAsync( 1 );
		expect( api.backupContents ).toHaveBeenCalledTimes( 2 );
		await vi.advanceTimersByTimeAsync( POLL_MS );
		expect( api.backupContents ).toHaveBeenCalledTimes( 3 );
		expect( hook.result().answer ).toBe( DONE );

		await vi.advanceTimersByTimeAsync( POLL_MS * 4 );
		expect( api.backupContents ).toHaveBeenCalledTimes( 3 );
		hook.unmount();
	} );

	it( 'stops polling when the panel closes', async () => {
		api.backupContents.mockResolvedValue( PARTIAL );
		const hook = mount( { backup: backup(), onGone: vi.fn() } );
		await settle();
		hook.unmount();
		await vi.advanceTimersByTimeAsync( POLL_MS * 3 );
		expect( api.backupContents ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'drops an answer that comes after another backup was chosen', async () => {
		let resolveFirst;
		api.backupContents
			.mockImplementationOnce(
				() =>
					new Promise( ( resolve ) => {
						resolveFirst = resolve;
					} )
			)
			.mockResolvedValue( DONE );
		const hook = mount( { backup: backup(), onGone: vi.fn() } );
		hook.update( {
			backup: backup( { id: 'own:b.wpress', name: 'b.wpress' } ),
			onGone: vi.fn(),
		} );
		await settle();
		expect( hook.result().answer ).toBe( DONE );

		resolveFirst( { partial: false, parts: { stale: true } } );
		await settle();
		expect( hook.result().answer ).toBe( DONE );
		hook.unmount();
	} );

	it( 'shows nothing of an earlier choice while the next answer is awaited', async () => {
		api.backupContents.mockResolvedValueOnce( DONE );
		const hook = mount( { backup: backup(), onGone: vi.fn() } );
		await settle();
		expect( hook.result().answer ).toBe( DONE );

		api.backupContents.mockReturnValue( new Promise( () => {} ) );
		hook.update( {
			backup: backup( { id: 'own:b.wpress', name: 'b.wpress' } ),
			onGone: vi.fn(),
		} );
		expect( hook.result().answer ).toBeNull();
		hook.unmount();
	} );

	it( 'reads again when the file under the same name was replaced', async () => {
		api.backupContents.mockResolvedValue( DONE );
		const hook = mount( { backup: backup(), onGone: vi.fn() } );
		await settle();
		hook.update( { backup: backup( { mtime: 200 } ), onGone: vi.fn() } );
		await settle();
		expect( api.backupContents ).toHaveBeenCalledTimes( 2 );
		hook.unmount();
	} );

	it( 'calls onGone when the backup is not there any more', async () => {
		const error = { code: 'lw_migration_backup_missing' };
		api.backupContents.mockRejectedValue( error );
		const onGone = vi.fn();
		const hook = mount( { backup: backup(), onGone } );
		await settle();
		expect( onGone ).toHaveBeenCalledTimes( 1 );
		expect( hook.result().error ).toBe( error );
		hook.unmount();
	} );

	it( 'keeps another failure and asks again on retry()', async () => {
		const error = { code: 'lw_migration_not_readable' };
		const onGone = vi.fn();
		api.backupContents.mockRejectedValueOnce( error );
		const hook = mount( { backup: backup(), onGone } );
		await settle();
		expect( hook.result().error ).toBe( error );
		expect( onGone ).not.toHaveBeenCalled();
		await vi.advanceTimersByTimeAsync( POLL_MS * 2 );
		expect( api.backupContents ).toHaveBeenCalledTimes( 1 );

		api.backupContents.mockResolvedValue( DONE );
		hook.result().retry();
		expect( hook.result().error ).toBeNull();
		await settle();
		expect( api.backupContents ).toHaveBeenCalledTimes( 2 );
		expect( hook.result().answer ).toBe( DONE );
		hook.unmount();
	} );
} );
