/**
 * External dependencies
 */
import { describe, expect, it, vi } from 'vitest';

/**
 * Internal dependencies
 */
import useBackupActions from './useBackupActions';

// A minimal hook runtime: refs and memos keep their slot across renders,
// like React does for one mounted component (the memo has no dependencies).
const slots = vi.hoisted( () => ( { list: [], index: 0 } ) );
vi.mock( '@wordpress/element', () => ( {
	useRef: ( value ) => {
		const key = slots.index++;
		if ( ! ( key in slots.list ) ) {
			slots.list[ key ] = { current: value };
		}
		return slots.list[ key ];
	},
	useMemo: ( make ) => {
		const key = slots.index++;
		if ( ! ( key in slots.list ) ) {
			slots.list[ key ] = make();
		}
		return slots.list[ key ];
	},
} ) );

// Named like a hook: it stands for one render of a component using it.
const useOneRender = ( handlers ) => {
	slots.index = 0;
	return useBackupActions( handlers );
};

const handlers = () => ( {
	onRestore: vi.fn(),
	download: vi.fn(),
	setOpen: vi.fn(),
	setProblem: vi.fn(),
} );

describe( 'useBackupActions', () => {
	it( 'keeps the same actions when the parent hands new handlers (a job poll)', () => {
		slots.list = [];
		const first = useOneRender( handlers() );
		const latest = handlers();
		const second = useOneRender( latest );
		expect( second ).toBe( first );

		const item = { id: 'own:a.wpress' };
		second.restore( item );
		expect( latest.setProblem ).toHaveBeenCalledWith( null );
		expect( latest.onRestore ).toHaveBeenCalledWith( item );
		second.download( item );
		expect( latest.download ).toHaveBeenCalledWith( item );
		second.open( 'label', item, 'menu' );
		expect( latest.setOpen ).toHaveBeenCalledWith( {
			what: 'label',
			backup: item,
			from: 'menu',
		} );
	} );
} );
