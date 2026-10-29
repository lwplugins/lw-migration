/**
 * External dependencies
 */
import { describe, expect, it, vi } from 'vitest';

/**
 * Internal dependencies
 */
import { runtime } from '../backups/hookRuntime';
import WeekLane from './WeekLane';
import WeekRuler from './WeekRuler';
import { nextMark, weekStarts } from './week';

vi.mock( '@wordpress/element', async () => ( {
	...( await import( '../backups/hookRuntime' ) ).runtime.element,
} ) );

const TZ = 'Europe/Budapest';
const utc = ( day, hour ) => Date.UTC( 2026, 9, day, hour ) / 1000;
const NOW = utc( 16, 13 );
const ITEM = {
	title: 'Nightly',
	// The last one is past the ruler's end (the server's week is now + 7 days).
	week: [ utc( 17, 1 ), utc( 18, 1 ), utc( 19, 1 ), utc( 23, 1 ) ],
};

/**
 * @param {Object} lane The lane element (a React element tree).
 * @return {Object[]} The props of its marks.
 */
const marks = ( lane ) =>
	lane.props.children[ 0 ].map( ( mark ) => mark.props );

const mount = ( onPick ) =>
	runtime.mount( ( props ) => WeekLane( props ), {
		item: ITEM,
		starts: weekStarts( NOW, TZ ),
		nowAt: 5,
		write: ( ts ) => `at ${ ts }`,
		onPick,
	} );

describe( 'nextMark', () => {
	it( 'moves with the arrows, Home and End, within the lane', () => {
		expect( nextMark( 'ArrowRight', 0, 3 ) ).toBe( 1 );
		expect( nextMark( 'ArrowRight', 2, 3 ) ).toBe( 2 );
		expect( nextMark( 'ArrowLeft', 0, 3 ) ).toBe( 0 );
		expect( nextMark( 'ArrowUp', 2, 3 ) ).toBe( 1 );
		expect( nextMark( 'ArrowDown', 1, 3 ) ).toBe( 2 );
		expect( nextMark( 'Home', 2, 3 ) ).toBe( 0 );
		expect( nextMark( 'End', 0, 3 ) ).toBe( 2 );
		expect( nextMark( 'Tab', 0, 3 ) ).toBeNull();
		expect( nextMark( 'Enter', 1, 3 ) ).toBeNull();
	} );
} );

describe( 'WeekLane', () => {
	it( 'gives the lane one tab stop, every mark a label', () => {
		const lane = mount( vi.fn() ).result();
		expect( lane.props.role ).toBe( 'group' );
		// It counts the marks it draws, not the run past the ruler's end.
		expect( marks( lane ) ).toHaveLength( 3 );
		expect( lane.props[ 'aria-label' ] ).toBe(
			`Nightly: 3 runs in the next 7 days, the first at ${ utc( 17, 1 ) }.`
		);
		expect( marks( lane ).map( ( m ) => m.tabIndex ) ).toEqual( [
			0, -1, -1,
		] );
		expect( marks( lane )[ 1 ][ 'aria-label' ] ).toBe(
			`Nightly: at ${ utc( 18, 1 ) }`
		);
	} );

	it( 'shows the time of a focused mark and keeps the tab stop on it', () => {
		const onPick = vi.fn();
		const hook = mount( onPick );
		marks( hook.result() )[ 2 ].onFocus();
		expect( onPick ).toHaveBeenCalledWith(
			`Nightly: at ${ utc( 19, 1 ) }`,
			false
		);
		expect( marks( hook.result() ).map( ( m ) => m.tabIndex ) ).toEqual( [
			-1, -1, 0,
		] );
		marks( hook.result() )[ 0 ].onClick();
		expect( onPick ).toHaveBeenLastCalledWith(
			`Nightly: at ${ utc( 17, 1 ) }`,
			true
		);
		hook.unmount();
	} );

	it( 'moves focus with the keys and leaves other keys alone', () => {
		const hook = mount( vi.fn() );
		const buttons = [ 0, 1, 2 ].map( () => ( { focus: vi.fn() } ) );
		const lane = hook.result();
		const ref = lane.ref ?? lane.props.ref;
		ref.current = { querySelectorAll: () => buttons };

		const end = { key: 'End', preventDefault: vi.fn() };
		marks( hook.result() )[ 0 ].onKeyDown( end );
		expect( end.preventDefault ).toHaveBeenCalled();
		expect( buttons[ 2 ].focus ).toHaveBeenCalled();
		expect( marks( hook.result() )[ 2 ].tabIndex ).toBe( 0 );

		const tab = { key: 'Tab', preventDefault: vi.fn() };
		marks( hook.result() )[ 2 ].onKeyDown( tab );
		expect( tab.preventDefault ).not.toHaveBeenCalled();
		hook.unmount();
	} );
} );

/**
 * @param {Object|null} node      A React element tree.
 * @param {string}      className A class name.
 * @return {Object|null} The first element with it.
 */
function findClass( node, className ) {
	if ( ! node || 'object' !== typeof node ) {
		return null;
	}
	if ( Array.isArray( node ) ) {
		for ( const child of node ) {
			const found = findClass( child, className );
			if ( found ) {
				return found;
			}
		}
		return null;
	}
	if (
		String( node.props?.className || '' )
			.split( ' ' )
			.includes( className )
	) {
		return node;
	}
	return findClass( node.props?.children, className );
}

/**
 * @param {Object|null} node A React element tree.
 * @return {Object|null} The first lane element (WeekLane).
 */
function findLane( node ) {
	if ( ! node || 'object' !== typeof node ) {
		return null;
	}
	if ( Array.isArray( node ) ) {
		for ( const child of node ) {
			const found = findLane( child );
			if ( found ) {
				return found;
			}
		}
		return null;
	}
	return WeekLane === node.type ? node : findLane( node.props?.children );
}

describe( 'the ruler readout', () => {
	const mountRuler = () =>
		runtime.mount( ( props ) => WeekRuler( props ), {
			items: [ { ...ITEM, id: 'a', enabled: true } ],
			timezone: TZ,
			now: NOW,
		} );
	const live = ( tree ) =>
		findClass( tree, 'screen-reader-text' ).props.children;
	const shown = ( tree ) =>
		findClass( tree, 'lw-week__picked' ).props.children;

	it( 'shows a focused mark without announcing it again, announces a tap', () => {
		const hook = mountRuler();
		expect(
			findClass( hook.result(), 'lw-week__picked' ).props[ 'aria-hidden' ]
		).toBe( 'true' );

		findLane( hook.result() ).props.onPick( 'Nightly: soon', false );
		expect( shown( hook.result() ) ).toBe( 'Nightly: soon' );
		expect( live( hook.result() ) ).toBe( '' );

		findLane( hook.result() ).props.onPick( 'Nightly: later', true );
		expect( shown( hook.result() ) ).toBe( 'Nightly: later' );
		expect( live( hook.result() ) ).toBe( 'Nightly: later' );
		hook.unmount();
	} );
} );
