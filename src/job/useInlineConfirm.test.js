/**
 * External dependencies
 */
import { describe, expect, it, vi } from 'vitest';

/**
 * Internal dependencies
 */
import { runtime } from '../backups/hookRuntime';
import useInlineConfirm from './useInlineConfirm';

vi.mock( '@wordpress/element', async () => ( {
	...( await import( '../backups/hookRuntime' ) ).runtime.element,
} ) );

/**
 * Mounts the hook with a stand-in confirm button and the button that opens it.
 *
 * @return {Object} { hook, confirm, opener }.
 */
function setup() {
	const hook = runtime.mount( () => useInlineConfirm(), {} );
	const confirm = { focus: vi.fn() };
	const opener = { focus: vi.fn() };
	hook.result().confirmRef.current = confirm;
	return { hook, confirm, opener };
}

const key = ( name ) => ( {
	key: name,
	preventDefault: vi.fn(),
	stopPropagation: vi.fn(),
} );

describe( 'useInlineConfirm', () => {
	it( 'starts closed and moves no focus', () => {
		const { hook, confirm, opener } = setup();
		expect( hook.result().open ).toBe( false );
		expect( confirm.focus ).not.toHaveBeenCalled();
		expect( opener.focus ).not.toHaveBeenCalled();
	} );

	it( 'focuses the confirm button when it opens', () => {
		const { hook, confirm, opener } = setup();
		hook.result().ask( { currentTarget: opener } );
		expect( hook.result().open ).toBe( true );
		expect( confirm.focus ).toHaveBeenCalledTimes( 1 );
		expect( opener.focus ).not.toHaveBeenCalled();
	} );

	it( 'gives the focus back to Cancel on "Keep going"', () => {
		const { hook, opener } = setup();
		hook.result().ask( { currentTarget: opener } );
		hook.result().close();
		expect( hook.result().open ).toBe( false );
		expect( opener.focus ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'closes on Escape and gives the focus back, other keys do nothing', () => {
		const { hook, opener } = setup();
		hook.result().ask( { currentTarget: opener } );
		const enter = key( 'Enter' );
		hook.result().onKeyDown( enter );
		expect( hook.result().open ).toBe( true );
		expect( enter.preventDefault ).not.toHaveBeenCalled();

		const escape = key( 'Escape' );
		hook.result().onKeyDown( escape );
		expect( hook.result().open ).toBe( false );
		expect( escape.stopPropagation ).toHaveBeenCalled();
		expect( opener.focus ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'opens without an event (no focus to give back)', () => {
		const { hook, confirm } = setup();
		hook.result().ask();
		expect( confirm.focus ).toHaveBeenCalledTimes( 1 );
		expect( () => hook.result().close() ).not.toThrow();
	} );
} );

describe( 'useInlineConfirm past the point of no return', () => {
	it( 'closes and gives the focus back once cancelling is blocked', () => {
		const hook = runtime.mount(
			( props ) => useInlineConfirm( props.blocked ),
			{ blocked: false }
		);
		const confirm = { focus: vi.fn() };
		const opener = { focus: vi.fn() };
		hook.result().confirmRef.current = confirm;
		hook.result().ask( { currentTarget: opener } );
		expect( hook.result().open ).toBe( true );

		hook.update( { blocked: true } );
		expect( hook.result().open ).toBe( false );
		expect( opener.focus ).toHaveBeenCalledTimes( 1 );

		// It does not open any more.
		hook.result().ask( { currentTarget: opener } );
		expect( hook.result().open ).toBe( false );
	} );
} );
