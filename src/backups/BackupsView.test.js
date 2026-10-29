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
import BackupList from './BackupList';
import BackupRow from './BackupRow';
import DetailContents from './DetailContents';
import DetailPanel from './DetailPanel';
import StorageStrip from './StorageStrip';
import { menuControls } from './RowMenu';
import { toItems } from './listing';

// The real components need the browser; plain stand-ins keep what they show.
vi.mock( '@wordpress/components', () => {
	const box =
		( tag ) =>
		( { children, className } ) =>
			createElement( tag, { className }, children );
	return {
		Button: ( { children, href, disabled } ) =>
			href
				? createElement( 'a', { href }, children )
				: createElement(
						'button',
						{ 'aria-disabled': disabled ? 'true' : undefined },
						children
					),
		Card: box( 'div' ),
		CardBody: box( 'div' ),
		CardHeader: box( 'div' ),
		DropdownMenu: ( { label } ) =>
			createElement( 'button', { className: 'menu' }, label ),
		Notice: box( 'div' ),
		__experimentalConfirmDialog: () => null,
	};
} );

// The site's time zone: Budapest; relative times are fixed words.
vi.mock( '@wordpress/date', () => ( {
	getSettings: () => ( {
		timezone: { string: 'Europe/Budapest', offset: 2 },
	} ),
	humanTimeDiff: () => 'a while ago',
} ) );

const render = ( component, props ) =>
	renderToStaticMarkup( createElement( component, props ) );

const NOW = Math.floor( Date.now() / 1000 );
const backup = ( name, patch = {} ) =>
	toItems( [
		{
			id: `own:${ name }`,
			name,
			folder: 'own',
			size: 1024,
			mtime: NOW,
			label: '',
			kind: 'manual',
			cloud: [],
			links: 0,
			encrypted: false,
			fromSite: null,
			...patch,
		},
	] )[ 0 ];
const actions = { restore: vi.fn(), download: vi.fn(), open: vi.fn() };
const SHARES = {
	data: { items: [] },
	error: null,
	isLoading: false,
	reload: () => {},
};
const GDRIVE = { id: 'aaaa1111', type: 'gdrive', name: 'Office' };

describe( 'the row menu', () => {
	const titles = ( controls ) => controls.map( ( c ) => c.title );

	it( 'offers every action of our own backup, Upload only with a destination', () => {
		const item = backup( 'a.wpress' );
		expect( titles( menuControls( item, actions, false, true ) ) ).toEqual(
			[
				'Restore',
				'Download',
				'Upload to the cloud…',
				'Transfer link',
				'Files',
				'Label',
				'Delete',
			]
		);
		expect(
			titles( menuControls( item, actions, false, false ) )
		).not.toContain( 'Upload to the cloud…' );
	} );

	it( "gives the other tool's backups no label", () => {
		const item = backup( 'x.wpress', {
			id: 'external:x.wpress',
			folder: 'external',
			kind: 'other',
		} );
		expect( titles( menuControls( item, actions, false, true ) ) ).toEqual(
			[
				'Restore',
				'Download',
				'Upload to the cloud…',
				'Transfer link',
				'Files',
				'Delete',
			]
		);
	} );

	it( 'disables Download only while that backup is being prepared', () => {
		const item = backup( 'a.wpress' );
		const controls = menuControls( item, actions, false, true, true );
		expect(
			controls.filter( ( c ) => c.isDisabled ).map( ( c ) => c.title )
		).toEqual( [ 'Download' ] );
		expect(
			menuControls( item, actions, false, true ).some(
				( c ) => c.isDisabled
			)
		).toBe( false );
	} );

	it( 'keeps Restore and Upload disabled while a job runs, and opens the dialogs it names', () => {
		const item = backup( 'a.wpress' );
		const controls = menuControls( item, actions, true, true );
		expect(
			controls.filter( ( c ) => c.isDisabled ).map( ( c ) => c.title )
		).toEqual( [ 'Restore', 'Upload to the cloud…' ] );
		controls.find( ( c ) => 'Label' === c.title ).onClick();
		expect( actions.open ).toHaveBeenCalledWith( 'label', item, 'menu' );
	} );
} );

describe( 'the storage strip', () => {
	it( "shows the other tool's folder and the free space only when there are any", () => {
		const html = render( StorageStrip, {
			storage: {
				ownBytes: 1024,
				otherBytes: 0,
				free: null,
				keep: [
					{ schedule: null, name: '', count: 0, bytes: 0, days: 0 },
				],
			},
			count: 1,
		} );
		expect( html ).toContain( 'Own backups 1 KB' );
		expect( html ).not.toContain( 'is-other' );
		expect( html ).not.toContain( 'is-free' );
		expect( html ).toContain( 'Manual backups are never deleted' );
	} );

	it( 'draws all three parts with their sizes', () => {
		const html = render( StorageStrip, {
			storage: { ownBytes: 2048, otherBytes: 1024, free: 4096, keep: [] },
			count: 3,
		} );
		expect( html ).toContain(
			'aria-label="Own backups 2 KB, The other .wpress tool&#x27;s folder 1 KB, Free 4 KB"'
		);
		expect( html ).toContain( '3 KB' );
	} );
} );

describe( 'the list', () => {
	it( 'keeps rows memoized: one with the same props is not drawn again', () => {
		// memo() with the default shallow compare; the view hands stable
		// actions (useBackupActions), destinations and onChoose.
		expect( BackupRow.$$typeof ).toBe( Symbol.for( 'react.memo' ) );
		expect( BackupRow.compare ).toBeNull();
	} );

	it( 'says why nothing is shown', () => {
		expect( render( BackupList, { items: [], narrowed: true } ) ).toContain(
			'No backup matches this filter.'
		);
		expect(
			render( BackupList, { items: [], narrowed: false } )
		).toContain( 'No backups yet' );
	} );

	it( 'groups the rows by day and marks the chosen one', () => {
		const html = render( BackupList, {
			items: [
				backup( 'new.wpress' ),
				backup( 'old.wpress', { mtime: NOW - 40 * 86400 } ),
			],
			narrowed: false,
			chosenId: 'own:old.wpress',
			onChoose: () => {},
			destinations: [],
			actions,
			busy: false,
		} );
		expect( html ).toContain( '>Today</h3>' );
		expect( html ).toContain( '>Older</h3>' );
		expect( html.match( /aria-current="true"/g ) ).toHaveLength( 1 );
		expect( html.indexOf( 'aria-current' ) ).toBeGreaterThan(
			html.indexOf( 'Older' )
		);
	} );
} );

describe( 'the contents', () => {
	it( 'shows a skeleton until the read ends, and says when it is still reading', () => {
		const first = render( DetailContents, { answer: null, error: null } );
		expect( first ).toContain( 'role="status"' );
		expect( first ).not.toContain( 'Still reading' );
		expect(
			render( DetailContents, {
				answer: { partial: true, parts: {} },
				error: null,
			} )
		).toContain( 'Still reading the archive…' );
	} );

	it( 'lists the parts, an unknown one as unknown, and what the backup leaves out', () => {
		const html = render( DetailContents, {
			answer: {
				partial: false,
				expandedSize: 3000,
				parts: {
					database: { included: true, bytes: 1024, count: null },
					media: { included: null, bytes: null, count: null },
					plugins: { included: true, bytes: 2048, count: 3 },
					themes: { included: false, bytes: 0, count: 0 },
					muPlugins: { included: false, bytes: 0, count: 0 },
				},
			},
			error: null,
		} );
		expect( html ).toContain( 'Plugins · 3 plugins' );
		expect( html ).toContain( 'unknown' );
		expect( html ).toContain(
			'Not in this backup: Themes, Must-use plugins'
		);
		expect( html ).not.toContain( 'Sizes as stored' );
		const empty = render( DetailContents, {
			answer: { partial: false, expandedSize: 0, parts: {} },
			error: null,
		} );
		expect( empty ).not.toContain( 'Sizes as stored' );
		expect( html.indexOf( 'Plugins' ) ).toBeLessThan(
			html.indexOf( 'Database' )
		);
	} );

	it( 'shows a refusal instead of the parts', () => {
		const html = render( DetailContents, {
			answer: null,
			error: {
				status: 422,
				code: 'lw_migration_not_readable',
				message: 'This file cannot be read.',
			},
		} );
		expect( html ).toContain( 'This file cannot be read.' );
		expect( html ).toContain( 'Try again' );
		expect( html ).not.toContain( 'role="status"' );
	} );
} );

describe( 'the detail panel', () => {
	const panel = ( item, patch = {} ) =>
		render( DetailPanel, {
			item,
			destinations: [ GDRIVE ],
			shares: SHARES,
			actions,
			busy: false,
			downloading: null,
			onChanged: () => {},
			onGone: () => {},
			...patch,
		} );

	it( 'lets our own backup take a label, and lists its cloud copies', () => {
		const html = panel(
			backup( 'a.wpress', {
				cloud: [ { id: 'aaaa1111', status: 'failed', at: 1 } ],
			} )
		);
		expect( html ).toContain( '>Add</button>' );
		expect( html ).toContain( 'In the backups folder' );
		expect( html ).toContain( 'Google Drive' );
		expect( html ).toContain( 'upload failed' );
		expect( html ).toContain( 'Upload…' );
		expect( html ).toContain( 'This backup has no live link.' );
	} );

	it( "gives the other tool's backup no label button", () => {
		const html = panel(
			backup( 'x.wpress', {
				id: 'external:x.wpress',
				folder: 'external',
				kind: 'other',
				label: 'kept',
			} )
		);
		expect( html ).toContain( 'kept' );
		expect( html ).not.toContain( '>Change</button>' );
		expect( html ).toContain( 'In the other .wpress tool&#x27;s folder' );
	} );

	it( 'keeps Restore and Upload disabled while a job runs', () => {
		const html = panel( backup( 'a.wpress' ), { busy: true } );
		expect( html ).toContain( '<button aria-disabled="true">Restore' );
		expect( html ).toContain( '<button aria-disabled="true">Upload…' );
	} );
} );
