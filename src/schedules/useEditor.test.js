/**
 * External dependencies
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Internal dependencies
 */
import { runtime } from '../backups/hookRuntime';
import useEditor from './useEditor';

vi.mock( '@wordpress/element', async () => ( {
	...( await import( '../backups/hookRuntime' ) ).runtime.element,
} ) );

const button = ( connected = true ) => ( {
	isConnected: connected,
	focus: vi.fn(),
} );

describe( 'useEditor', () => {
	beforeEach( () => {
		vi.useFakeTimers();
		vi.stubGlobal( 'window', globalThis );
	} );

	afterEach( () => {
		vi.useRealTimers();
		vi.unstubAllGlobals();
	} );

	it( 'opens with the state and gives focus back to the opener once closed', async () => {
		const fallback = button();
		const opener = button();
		const hook = runtime.mount( () => useEditor( () => fallback ), {} );
		expect( hook.result().editing ).toBeNull();

		hook.result().open( { item: null }, opener );
		expect( hook.result().editing ).toEqual( { item: null } );

		hook.result().close();
		expect( hook.result().editing ).toBeNull();
		expect( opener.focus ).not.toHaveBeenCalled();
		await vi.runAllTimersAsync();
		expect( opener.focus ).toHaveBeenCalledTimes( 1 );
		expect( fallback.focus ).not.toHaveBeenCalled();
		hook.unmount();
	} );

	it( 'falls back to New schedule when the opener is gone', async () => {
		const fallback = button();
		const hook = runtime.mount( () => useEditor( () => fallback ), {} );

		hook.result().open( { item: { id: 'a' } }, button( false ) );
		hook.result().close();
		await vi.runAllTimersAsync();
		expect( fallback.focus ).toHaveBeenCalledTimes( 1 );

		hook.result().open( { item: null }, null );
		hook.result().close();
		await vi.runAllTimersAsync();
		expect( fallback.focus ).toHaveBeenCalledTimes( 2 );
		hook.unmount();
	} );

	it( 'waits for the reload a save starts: a starter gone by then hands focus on', async () => {
		const fallback = button();
		const starter = button();
		const hook = runtime.mount( () => useEditor( () => fallback ), {} );
		let finish;
		const reload = new Promise( ( resolve ) => ( finish = resolve ) );

		hook.result().open( { item: null }, starter );
		hook.result().close( reload );
		await vi.runAllTimersAsync();
		expect( starter.focus ).not.toHaveBeenCalled();
		expect( fallback.focus ).not.toHaveBeenCalled();

		starter.isConnected = false; // The list now has the schedule.
		finish();
		await vi.runAllTimersAsync();
		expect( starter.focus ).not.toHaveBeenCalled();
		expect( fallback.focus ).toHaveBeenCalledTimes( 1 );
		hook.unmount();
	} );
} );
