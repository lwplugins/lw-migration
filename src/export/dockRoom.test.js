/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import { DOCK_ROOM, watchDockRoom } from './dockRoom';

/** A ResizeObserver stand-in that resizes on demand. */
class FakeObserver {
	static last = null;

	constructor( callback ) {
		this.callback = callback;
		this.watched = [];
		this.disconnected = false;
		FakeObserver.last = this;
	}

	observe( element ) {
		this.watched.push( element );
	}

	disconnect() {
		this.disconnected = true;
	}

	resize() {
		this.callback( [] );
	}
}

const section = () => {
	const props = {};
	return {
		props,
		style: {
			setProperty: ( name, value ) => {
				props[ name ] = value;
			},
			removeProperty: ( name ) => {
				delete props[ name ];
			},
		},
	};
};

describe( 'watchDockRoom', () => {
	it( 'gives the section the dock height, also when the dock grows', () => {
		const dock = { offsetHeight: 76 };
		const target = section();

		watchDockRoom( dock, target, FakeObserver );
		expect( target.props[ DOCK_ROOM ] ).toBe( '76px' );
		expect( FakeObserver.last.watched ).toEqual( [ dock ] );

		// A refused Start puts an error notice into the dock.
		dock.offsetHeight = 172;
		FakeObserver.last.resize();
		expect( target.props[ DOCK_ROOM ] ).toBe( '172px' );
	} );

	it( 'stops watching and leaves the stylesheet fallback when it is done', () => {
		const target = section();
		const stop = watchDockRoom(
			{ offsetHeight: 76 },
			target,
			FakeObserver
		);

		stop();

		expect( FakeObserver.last.disconnected ).toBe( true );
		expect( target.props ).toEqual( {} );
	} );

	it( 'does nothing without a ResizeObserver or a dock', () => {
		const target = section();

		watchDockRoom( { offsetHeight: 76 }, target, undefined )();
		watchDockRoom( null, target, FakeObserver )();

		expect( target.props ).toEqual( {} );
	} );
} );
