/**
 * External dependencies
 */
// react-dom comes with @wordpress/element; these tests render on the server.
// eslint-disable-next-line import/no-extraneous-dependencies
import { renderToStaticMarkup } from 'react-dom/server';
import { afterAll, beforeAll, describe, expect, it, vi } from 'vitest';

/**
 * WordPress dependencies
 */
import { createElement } from '@wordpress/element';

/**
 * Internal dependencies
 */
import ScheduleEditor from './ScheduleEditor';
import { duplicateForm } from './scheduleForm';
import { starters } from './starters';

// Plain stand-ins: a control shows its label and value, the Modal its title,
// its focus setting and its content.
vi.mock( '@wordpress/components', () => {
	const field = ( { label, value } ) =>
		createElement( 'label', {}, `${ label }=${ value ?? '' }` );
	const box =
		( tag ) =>
		( { children, className } ) =>
			createElement( tag, { className }, children );
	return {
		Button: ( { children } ) => createElement( 'button', {}, children ),
		Card: box( 'div' ),
		CardBody: box( 'div' ),
		CardHeader: box( 'div' ),
		CheckboxControl: ( { label, checked } ) =>
			createElement( 'label', {}, [
				createElement( 'input', {
					key: 'box',
					type: 'checkbox',
					checked,
					readOnly: true,
				} ),
				label,
			] ),
		FormToggle: ( { checked } ) =>
			createElement( 'input', {
				type: 'checkbox',
				checked,
				readOnly: true,
			} ),
		Modal: ( { title, focusOnMount, className, children } ) =>
			createElement(
				'div',
				{ className, 'data-focus': focusOnMount, role: 'dialog' },
				[ createElement( 'h1', { key: 't' }, title ), children ]
			),
		Notice: box( 'div' ),
		SelectControl: field,
		TextControl: field,
		TextareaControl: field,
		__experimentalHeading: box( 'h3' ),
		__experimentalHStack: box( 'div' ),
		__experimentalNumberControl: field,
		__experimentalText: box( 'p' ),
		__experimentalToggleGroupControl: box( 'div' ),
		__experimentalToggleGroupControlOption: ( { label } ) =>
			createElement( 'button', {}, label ),
		__experimentalVStack: box( 'div' ),
	};
} );

vi.mock( '../data/api', () => ( { api: { destinations: () => null } } ) );
vi.mock( '../data/useRemote', () => ( {
	default: () => ( {
		data: { items: [ { id: 'dddd4444', type: 'dropbox', name: 'Team' } ] },
		error: null,
		isLoading: false,
	} ),
} ) );

// Friday 16 October 2026, 15:20 in Budapest.
const NOW = Date.UTC( 2026, 9, 16, 13, 20 );

beforeAll( () => {
	vi.useFakeTimers( { toFake: [ 'Date' ] } );
	vi.setSystemTime( NOW );
	vi.stubGlobal( 'document', { documentElement: { lang: 'en-GB' } } );
} );

afterAll( () => {
	vi.useRealTimers();
	vi.unstubAllGlobals();
} );

const ITEM = {
	id: 'aaaa1111',
	title: 'Nightly',
	enabled: true,
	frequency: 'daily',
	every: 2,
	minute: 0,
	hour: 3,
	weekday: 1,
	monthday: 1,
	export: { noMedia: true, encrypt: true, compression: '' },
	hasPassword: true,
	retention: { count: 7, bytes: 0, days: 0 },
	destinations: [ { id: 'dddd4444', retention: { count: 8, days: 0 } } ],
	keepLocal: true,
	notify: { when: 'failure', email: '' },
};

const render = ( props ) =>
	renderToStaticMarkup(
		createElement( ScheduleEditor, {
			item: null,
			timezone: 'Europe/Budapest',
			fallback: '',
			onClose: () => {},
			onSaved: () => {},
			...props,
		} )
	);

describe( 'ScheduleEditor', () => {
	it( 'opens as a sheet that focuses its first field', () => {
		const html = render( { initial: starters()[ 0 ].form } );
		expect( html ).toContain( 'lw-sheet' );
		expect( html ).toContain( 'data-focus="firstContentElement"' );
		expect( html ).toContain( '<h1>New schedule</h1>' );
		expect( html ).toContain( 'Name=Daily backup at night' );
	} );

	it( 'says what the form does, with a preview of its next runs', () => {
		const html = render( { initial: starters()[ 0 ].form } );
		expect( html ).toContain(
			'<b>Every day at 03:00</b>, it backs up the whole site.'
		);
		expect( html ).toContain( 'The backups stay on this server.' );
		expect( html ).toContain(
			'Next runs (a preview): tomorrow 03:00 · Sunday 18 Oct, 03:00 · Monday 19 Oct, 03:00'
		);
	} );

	it( 'edits a schedule with its stored password kept', () => {
		const html = render( { item: ITEM } );
		expect( html ).toContain( '<h1>Edit schedule</h1>' );
		expect( html ).toContain( 'A password is stored for this schedule.' );
		expect( html ).toContain(
			'it backs up these parts: database, plugins and themes.'
		);
		expect( html ).toContain(
			'They stay on this server and are uploaded to: Team.'
		);
	} );

	it( 'duplicates as a new schedule without the password', () => {
		const html = render( { initial: duplicateForm( ITEM ) } );
		expect( html ).toContain( '<h1>New schedule</h1>' );
		expect( html ).toContain( 'Name=Nightly (copy)' );
		expect( html ).not.toContain(
			'A password is stored for this schedule.'
		);
	} );

	it( 'says when a switched-off schedule runs', () => {
		const html = render( {
			initial: { ...starters()[ 1 ].form, enabled: false },
		} );
		expect( html ).toContain(
			'Switched off: it runs only when you start it.'
		);
	} );
} );
