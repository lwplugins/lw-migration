/**
 * External dependencies
 */
import { describe, expect, it, vi } from 'vitest';

/**
 * WordPress dependencies
 */
import { Button, Notice } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { runtime } from '../backups/hookRuntime';
import { button, byType, find, textOf } from './elementTree';
import InlineConfirm from './InlineConfirm';

vi.mock( '@wordpress/element', async () => ( {
	...( await import( '../backups/hookRuntime' ) ).runtime.element,
} ) );
vi.mock( '@wordpress/components', () => ( {
	Button: () => null,
	Notice: () => null,
} ) );
vi.mock( '@wordpress/compose', () => ( {
	useInstanceId: () => 'lw-confirm-1',
} ) );
vi.mock( '../components/LoginNotice', () => ( {
	default: () => null,
} ) );

const mount = ( props ) =>
	runtime.mount( ( p ) => InlineConfirm( p ), {
		text: 'It goes for good.',
		confirmLabel: 'Delete',
		onConfirm: vi.fn( () => Promise.resolve() ),
		onCancel: vi.fn(),
		...props,
	} );
const key = ( name ) => ( {
	key: name,
	preventDefault: vi.fn(),
	stopPropagation: vi.fn(),
} );

describe( 'InlineConfirm', () => {
	it( 'is an alert dialog in place, named by its title, the button described by the text', () => {
		const hook = mount( { title: 'Delete this destination?' } );
		const root = hook.result();
		expect( root.type ).toBe( 'div' );
		expect( root.props.role ).toBe( 'alertdialog' );
		expect( root.props[ 'aria-labelledby' ] ).toBe( 'lw-confirm-1-title' );
		expect( textOf( find( root, ( el ) => 'h4' === el.type ) ) ).toBe(
			'Delete this destination?'
		);
		const confirm = button( root, Button, 'Delete' );
		expect( confirm.props.isDestructive ).toBe( true );
		expect( confirm.props[ 'aria-describedby' ] ).toBe(
			'lw-confirm-1-text'
		);
		hook.unmount();
	} );

	it( 'is a plain row in a list, its button described by what is deleted', () => {
		const hook = mount( { tag: 'li', lead: 'site.wpress' } );
		const root = hook.result();
		expect( root.type ).toBe( 'li' );
		expect( root.props.role ).toBeUndefined();
		expect(
			button( root, Button, 'Delete' ).props[ 'aria-describedby' ]
		).toBe( 'lw-confirm-1-lead lw-confirm-1-text' );
		hook.unmount();
	} );

	it( 'cancels on Escape and on Cancel; other keys do nothing', () => {
		const onCancel = vi.fn();
		const hook = mount( { onCancel } );
		const enter = key( 'Enter' );
		hook.result().props.onKeyDown( enter );
		expect( onCancel ).not.toHaveBeenCalled();

		const escape = key( 'Escape' );
		hook.result().props.onKeyDown( escape );
		expect( escape.stopPropagation ).toHaveBeenCalled();
		expect( onCancel ).toHaveBeenCalledTimes( 1 );

		button( hook.result(), Button, 'Cancel' ).props.onClick();
		expect( onCancel ).toHaveBeenCalledTimes( 2 );
		hook.unmount();
	} );

	it( 'keeps a refusal in place and lets the button be pressed again', async () => {
		const onConfirm = vi.fn( () =>
			Promise.reject( { code: 'x', message: 'Not deleted.' } )
		);
		const hook = mount( { onConfirm } );
		await button( hook.result(), Button, 'Delete' ).props.onClick();
		expect( textOf( byType( hook.result(), Notice ) ) ).toBe(
			'Not deleted.'
		);
		expect( button( hook.result(), Button, 'Delete' ).props.disabled ).toBe(
			false
		);
		hook.unmount();
	} );

	it( 'ignores Escape while the delete is on its way', () => {
		const onCancel = vi.fn();
		const hook = mount( {
			onCancel,
			onConfirm: () => new Promise( () => {} ),
		} );
		button( hook.result(), Button, 'Delete' ).props.onClick();
		expect( button( hook.result(), Button, 'Delete' ).props.isBusy ).toBe(
			true
		);
		hook.result().props.onKeyDown( key( 'Escape' ) );
		expect( onCancel ).not.toHaveBeenCalled();
		hook.unmount();
	} );
} );
