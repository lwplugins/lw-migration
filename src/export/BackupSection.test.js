/**
 * External dependencies
 */
// react-dom comes with @wordpress/element; these tests render on the server.
// eslint-disable-next-line import/no-extraneous-dependencies
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';

/**
 * WordPress dependencies
 */
import { createElement } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { DEFAULTS } from '../views/exportRequest';
import ExportView from '../views/ExportView';
import DestinationTiles, { keepText } from './DestinationTiles';
import FileOptions, { compressionHelp } from './FileOptions';
import { composition } from './estimate';
import { estimate, firstWalk, withoutFiles } from './fixtures/estimate';
import { routeText, sizeLead } from './summary';

// The real components need the browser; plain stand-ins keep what they show.
vi.mock( '@wordpress/components', () => import( './fixtures/components' ) );

// Nothing is fetched in a server render (effects do not run).
vi.mock( '../data/api', () => ( {
	api: {
		destinations: () => new Promise( () => {} ),
		exportEstimate: () => new Promise( () => {} ),
		startJob: vi.fn(),
	},
} ) );

const form = ( patch = {} ) => ( { ...DEFAULTS, ...patch } );

const render = ( Component, props ) =>
	renderToStaticMarkup( createElement( Component, props ) );

describe( 'the Backup section in page order', () => {
	it( 'draws the lead, the bar, the cards and the dock with Start last', () => {
		const html = render( ExportView, { busy: false, onStart: () => {} } );
		const order = [
			'lw-export__lead',
			'lw-composition',
			'What goes in',
			'Where it goes',
			'The file',
			'Advanced',
			'lw-dock',
			'Start backup',
		].map( ( needle ) => html.indexOf( needle ) );
		expect( order.every( ( at ) => at >= 0 ) ).toBe( true );
		expect( [ ...order ].sort( ( a, b ) => a - b ) ).toEqual( order );
		// The sizes are being measured: a skeleton, not a spinner.
		expect( html ).toContain( 'Measuring the backup…' );
		expect( html ).not.toContain( 'components-spinner' );
		// The Advanced inputs are mounted while folded.
		expect( html ).toContain( 'Only these tables' );
		expect( html ).toContain(
			'id="lw-advanced-body" class="lw-advanced__body" hidden=""'
		);
	} );
} );

describe( 'DestinationTiles', () => {
	const items = [
		{
			id: 'd1',
			type: 'gdrive',
			name: 'Office Drive',
			where: 'me@example.com',
			connection: { connected: true },
		},
		{
			id: 'd2',
			type: 'dropbox',
			name: 'Dropbox',
			where: 'Apps/LW Migration',
			connection: { connected: false },
		},
	];
	const tiles = ( value ) =>
		render( DestinationTiles, {
			value: form( value ),
			onChange: () => {},
			remote: { data: { items }, error: null, isLoading: false },
		} );

	it( 'offers each destination as a checkbox tile, a not connected one disabled with the reason', () => {
		const html = tiles( { destinations: [ 'd1' ] } );
		expect( html ).toContain( 'lw-dest-choice is-checked' );
		expect( html ).toContain( 'Google Drive' );
		expect( html ).toContain( 'Office Drive · me@example.com' );
		expect( html ).toContain( 'lw-dest-choice is-off' );
		expect( html ).toContain( 'Connect it first in Destinations.' );
		expect( html ).toContain( 'lw-dest-icon--google-drive' );
	} );

	it( 'disables "keep the local copy" without a destination', () => {
		const html = tiles( {} );
		expect( html ).toContain(
			'Without a destination the backup stays in the backups folder.'
		);
		expect( html ).toMatch(
			/role="switch" disabled="" readOnly="" checked=""/
		);
		expect( keepText( 1, false ) ).toContain( 'local file is deleted' );
	} );

	it( 'shows a skeleton while the destinations load', () => {
		const html = render( DestinationTiles, {
			value: form(),
			onChange: () => {},
			remote: { data: null, error: null, isLoading: true },
		} );
		expect( html ).toContain( 'Loading the destinations…' );
	} );
} );

describe( 'FileOptions', () => {
	it( 'reveals the password fields and the note only with encryption on', () => {
		const props = {
			onChange: () => {},
			password: '',
			again: '',
			onPassword: () => {},
			onAgain: () => {},
			errors: {},
		};
		expect(
			render( FileOptions, { ...props, options: form() } )
		).toContain( 'class="lw-file__passwords" hidden=""' );
		const html = render( FileOptions, {
			...props,
			options: form( { encrypt: true } ),
		} );
		expect( html ).not.toContain( 'hidden=""' );
		expect( html ).toContain( 'protects confidentiality only' );
		expect( compressionHelp( 'bzip2' ) ).toBe(
			'The smallest file, the slowest backup.'
		);
	} );
} );

describe( 'summary dock text', () => {
	const comp = composition( estimate(), form() );

	it( 'leads with the size, "at least" while counting, before compression', () => {
		expect( sizeLead( comp, 'none' ) ).toBe( '1.7 GB' );
		expect( sizeLead( comp, 'gzip' ) ).toBe( '1.7 GB before compression' );
		expect(
			sizeLead(
				composition( withoutFiles( { complete: false } ), form() ),
				'none'
			)
		).toBe( 'at least 212 MB' );
		expect( sizeLead( composition( null, form() ), 'none' ) ).toBe( '' );
		expect( sizeLead( composition( firstWalk(), form() ), 'none' ) ).toBe(
			'at least 1.7 GB'
		);
	} );

	it( "says when the size is the whole network's, not the chosen sites'", () => {
		expect( sizeLead( comp, 'none', true ) ).toBe(
			'1.7 GB (whole network)'
		);
		expect( sizeLead( comp, 'gzip', true ) ).toBe(
			'1.7 GB before compression (whole network)'
		);
	} );

	it( 'says where it goes and how the file is made', () => {
		expect(
			routeText( {
				chosen: [],
				keepLocal: true,
				compression: 'none',
				encrypt: false,
			} )
		).toBe( 'the backups folder only' );
		expect(
			routeText( {
				chosen: [ { name: 'Drive', type: 'gdrive' }, { type: 'sftp' } ],
				keepLocal: false,
				compression: 'bzip2',
				encrypt: true,
			} )
		).toBe(
			'backups folder (deleted after the uploads) + Drive, SFTP · bzip2 · encrypted'
		);
	} );
} );
