/**
 * External dependencies
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Internal dependencies
 */
import { api } from '../data/api';
import { runtime } from './hookRuntime';
import DetailLinks from './DetailLinks';

vi.mock( '@wordpress/element', async () => ( {
	...( await import( './hookRuntime' ) ).runtime.element,
} ) );
vi.mock( '@wordpress/components', () => ( { Button: () => null } ) );
vi.mock( '../data/api', () => ( { api: { revokeShare: vi.fn() } } ) );
vi.mock( '../views/ProblemNotice', () => ( {
	default: () => null,
	describeProblem: ( error ) => ( { message: error.message } ),
} ) );
vi.mock( './DetailSection', () => ( { default: () => null } ) );
vi.mock( './LiveLinkList', () => ( {
	default: () => null,
	RevokeDialog: () => null,
} ) );

/**
 * @param {Object|null} node A React element tree.
 * @param {string}      prop A prop name.
 * @return {Object|null} The first element that has the prop.
 */
function findProp( node, prop ) {
	if ( ! node || 'object' !== typeof node ) {
		return null;
	}
	if ( node.props && prop in node.props ) {
		return node.props;
	}
	const children = [ node.props?.children, node.props?.action ].flat();
	for ( const child of children ) {
		const found = findProp( child, prop );
		if ( found ) {
			return found;
		}
	}
	return null;
}

const LINK = { id: 'link-1' };
const mount = ( onChanged ) =>
	runtime.mount( ( props ) => DetailLinks( props ), {
		backup: { folder: 'own', name: 'a.wpress', size: 1, mtime: 1 },
		shares: { data: { items: [] }, error: null, isLoading: false },
		onCreate: vi.fn(),
		onChanged,
	} );

describe( 'DetailLinks revoke', () => {
	beforeEach( () => api.revokeShare.mockReset() );

	it( 'revokes the asked link only after the confirmation, then reads the lists again', async () => {
		api.revokeShare.mockResolvedValue( {} );
		const onChanged = vi.fn();
		const hook = mount( onChanged );
		expect( findProp( hook.result(), 'onConfirm' ).isOpen ).toBe( false );

		findProp( hook.result(), 'onRevoke' ).onRevoke( LINK );
		expect( findProp( hook.result(), 'onConfirm' ).isOpen ).toBe( true );
		expect( api.revokeShare ).not.toHaveBeenCalled();

		await findProp( hook.result(), 'onConfirm' ).onConfirm();
		expect( api.revokeShare ).toHaveBeenCalledWith( 'link-1' );
		expect( onChanged ).toHaveBeenCalledTimes( 1 );
		expect( findProp( hook.result(), 'onConfirm' ).isOpen ).toBe( false );
		hook.unmount();
	} );

	it( 'closes the dialog and sends no request on cancel', () => {
		const onChanged = vi.fn();
		const hook = mount( onChanged );
		findProp( hook.result(), 'onRevoke' ).onRevoke( LINK );
		findProp( hook.result(), 'onConfirm' ).onCancel();
		expect( findProp( hook.result(), 'onConfirm' ).isOpen ).toBe( false );
		expect( api.revokeShare ).not.toHaveBeenCalled();
		expect( onChanged ).not.toHaveBeenCalled();
		hook.unmount();
	} );

	it( 'still reads the lists again when the revoke is refused', async () => {
		// A plain function: vi.fn() reports a rejected result of its own as
		// unhandled even when the caller handles it.
		const spy = api.revokeShare;
		api.revokeShare = () => Promise.reject( new Error( 'No.' ) );
		const onChanged = vi.fn();
		const hook = mount( onChanged );
		findProp( hook.result(), 'onRevoke' ).onRevoke( LINK );
		await findProp( hook.result(), 'onConfirm' ).onConfirm();
		api.revokeShare = spy;
		expect( onChanged ).toHaveBeenCalledTimes( 1 );
		hook.unmount();
	} );
} );
