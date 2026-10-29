/**
 * External dependencies
 */
import { describe, expect, it, vi } from 'vitest';

/**
 * Internal dependencies
 */
import { runtime } from '../backups/hookRuntime';
import useReloadWhenDone from './useReloadWhenDone';

vi.mock( '@wordpress/element', async () => ( {
	...( await import( '../backups/hookRuntime' ) ).runtime.element,
} ) );

const job = ( status, id = 'j1' ) => ( { id, type: 'export', status } );
const mount = ( reload, first ) =>
	runtime.mount( ( { current } ) => useReloadWhenDone( current, reload ), {
		current: first,
	} );

describe( 'useReloadWhenDone', () => {
	it( 'reloads once when the running job finishes', () => {
		const reload = vi.fn();
		const hook = mount( reload, job( 'running' ) );
		hook.update( { current: job( 'running' ) } );
		expect( reload ).not.toHaveBeenCalled();

		hook.update( { current: job( 'done' ) } );
		expect( reload ).toHaveBeenCalledTimes( 1 );
		hook.update( { current: job( 'done' ) } );
		hook.update( { current: null } );
		expect( reload ).toHaveBeenCalledTimes( 1 );
		hook.unmount();
	} );

	it( 'reloads when the job fails, or is closed while still watched', () => {
		const reload = vi.fn();
		const failed = mount( reload, job( 'running' ) );
		failed.update( { current: job( 'failed' ) } );
		expect( reload ).toHaveBeenCalledTimes( 1 );
		failed.unmount();

		const closed = mount( reload, job( 'running' ) );
		closed.update( { current: null } );
		expect( reload ).toHaveBeenCalledTimes( 2 );
		closed.unmount();
	} );

	it( 'leaves the list alone without a running job', () => {
		const reload = vi.fn();
		const hook = mount( reload, null );
		hook.update( { current: job( 'done' ) } );
		hook.update( { current: null } );
		expect( reload ).not.toHaveBeenCalled();
		hook.unmount();
	} );

	it( 'reloads when another job replaces the watched one', () => {
		const reload = vi.fn();
		const hook = mount( reload, job( 'running', 'j1' ) );
		hook.update( { current: job( 'running', 'j2' ) } );
		expect( reload ).toHaveBeenCalledTimes( 1 );
		hook.update( { current: job( 'done', 'j2' ) } );
		expect( reload ).toHaveBeenCalledTimes( 2 );
		hook.unmount();
	} );
} );
