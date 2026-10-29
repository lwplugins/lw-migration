/**
 * External dependencies
 */
// react-dom comes with @wordpress/element; these tests render on the server.
// eslint-disable-next-line import/no-extraneous-dependencies
import { renderToStaticMarkup } from 'react-dom/server';
import { afterEach, describe, expect, it, vi } from 'vitest';

/**
 * WordPress dependencies
 */
import { createElement } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { findAll } from '../destinations/elementTree';
import ResetChoices, { choiceId } from './ResetChoices';
import { NETWORK, SITE } from './fixtures/summaries';

const props = ( patch = {} ) => ( {
	summary: SITE,
	value: null,
	onChange: vi.fn(),
	labelledBy: 'pick-title',
	...patch,
} );

const render = ( patch ) =>
	renderToStaticMarkup( createElement( ResetChoices, props( patch ) ) );

/**
 * @param {Object} patch Props.
 * @return {Object} The tiles by type, and the props used.
 */
function tiles( patch ) {
	const used = props( patch );
	const buttons = findAll(
		ResetChoices( used ),
		( el ) => 'radio' === el.props?.role
	);
	return {
		used,
		tile: ( type ) =>
			buttons.find( ( el ) => el.props.id === choiceId( type ) ),
	};
}

describe( 'ResetChoices', () => {
	afterEach( () => {
		delete globalThis.document;
	} );

	it( 'is a radio group of five tiles, none chosen at first', () => {
		const html = render();
		expect( html ).toContain(
			'role="radiogroup" aria-labelledby="pick-title"'
		);
		expect( html.match( /role="radio"/g ) ).toHaveLength( 5 );
		expect( html.match( /aria-checked="false"/g ) ).toHaveLength( 5 );
		[
			'Delete plugins',
			'Reset themes',
			'Clean up media',
			'Reset the database',
			'Reset everything',
		].forEach( ( title ) => expect( html ).toContain( `>${ title }<` ) );
		// The scope of each, by count where it is known.
		[
			'12 plugins',
			'3 themes',
			'1,284 media items',
			'all content',
			'all four',
		].forEach( ( line ) => expect( html ).toContain( `>${ line }<` ) );
		// The first tile takes the tab stop while nothing is chosen.
		expect( html ).toMatch(
			/id="lw-reset-choice-plugins"[^>]*tabindex="0"/
		);
		expect( html.match( /tabindex="-1"/g ) ).toHaveLength( 4 );
	} );

	it( 'names each tile by its title and describes it by its line', () => {
		const html = render();
		expect( html ).toMatch(
			/id="lw-reset-choice-media"[^>]*aria-labelledby="lw-reset-choice-media-title"[^>]*aria-describedby="lw-reset-choice-media-line"/
		);
		expect( html ).toContain( 'id="lw-reset-choice-media-line"' );
	} );

	it( 'marks the chosen tile and gives it the tab stop', () => {
		const html = render( { value: 'database' } );
		expect( html ).toMatch(
			/id="lw-reset-choice-database"[^>]*aria-checked="true"[^>]*tabindex="0"/
		);
		expect( html.match( /aria-checked="true"/g ) ).toHaveLength( 1 );
	} );

	it( 'on a network closes the database and everything with the reason in place of the count', () => {
		const html = render( { summary: NETWORK } );
		[ 'database', 'full' ].forEach( ( type ) =>
			expect( html ).toMatch(
				new RegExp(
					`id="lw-reset-choice-${ type }"[^>]*aria-disabled="true"[^>]*aria-describedby="lw-reset-choice-${ type }-line"`
				)
			)
		);
		expect(
			html.match(
				/>On a network only plugins, themes and media can be reset in this version.</g
			)
		).toHaveLength( 2 );
		expect( html ).not.toContain( '>all content<' );
		expect( html ).toContain( '>every site&#x27;s media<' );
		expect( html.match( /aria-disabled/g ) ).toHaveLength( 2 );
	} );

	it( 'chooses an open tile on a click, never a closed one', () => {
		const { used, tile } = tiles( { summary: NETWORK } );
		tile( 'full' ).props.onClick();
		expect( used.onChange ).not.toHaveBeenCalled();
		tile( 'themes' ).props.onClick();
		expect( used.onChange ).toHaveBeenCalledWith( 'themes' );
	} );

	it( 'holds the choice while a start is on its way', () => {
		const html = render( { value: 'plugins', disabled: true } );
		expect( html.match( /aria-disabled="true"/g ) ).toHaveLength( 5 );
		expect( html ).toMatch(
			/id="lw-reset-choice-plugins"[^>]*aria-checked="true"[^>]*tabindex="0"/
		);
		globalThis.document = { getElementById: () => ( { focus() {} } ) };
		const { used, tile } = tiles( { value: 'plugins', disabled: true } );
		tile( 'media' ).props.onClick();
		tile( 'plugins' ).props.onKeyDown( {
			key: 'ArrowRight',
			preventDefault: vi.fn(),
		} );
		expect( used.onChange ).not.toHaveBeenCalled();
	} );

	it( 'moves the choice and the focus with the arrow keys, round the ends, past closed tiles', () => {
		const focused = [];
		globalThis.document = {
			getElementById: ( id ) => ( { focus: () => focused.push( id ) } ),
		};
		const press = ( patch, type, key ) => {
			const { used, tile } = tiles( patch );
			const event = { key, preventDefault: vi.fn() };
			tile( type ).props.onKeyDown( event );
			return { used, event };
		};

		let { used, event } = press(
			{ value: 'plugins' },
			'plugins',
			'ArrowRight'
		);
		expect( used.onChange ).toHaveBeenCalledWith( 'themes' );
		expect( event.preventDefault ).toHaveBeenCalled();
		expect( focused.pop() ).toBe( 'lw-reset-choice-themes' );

		( { used } = press( { value: 'plugins' }, 'plugins', 'ArrowLeft' ) );
		expect( used.onChange ).toHaveBeenCalledWith( 'full' );

		( { used } = press( { value: 'full' }, 'full', 'ArrowDown' ) );
		expect( used.onChange ).toHaveBeenCalledWith( 'plugins' );

		( { used } = press( { value: 'media' }, 'media', 'ArrowUp' ) );
		expect( used.onChange ).toHaveBeenCalledWith( 'themes' );

		// On a network the closed database and everything are skipped.
		( { used } = press(
			{ summary: NETWORK, value: 'media' },
			'media',
			'ArrowRight'
		) );
		expect( used.onChange ).toHaveBeenCalledWith( 'plugins' );
		expect( focused.pop() ).toBe( 'lw-reset-choice-plugins' );

		// Other keys are left alone.
		( { used, event } = press( { value: 'media' }, 'media', 'Tab' ) );
		expect( used.onChange ).not.toHaveBeenCalled();
		expect( event.preventDefault ).not.toHaveBeenCalled();
	} );
} );
