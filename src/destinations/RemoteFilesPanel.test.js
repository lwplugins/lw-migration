/**
 * External dependencies
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { runtime } from '../backups/hookRuntime';
import { api } from '../data/api';
import { button, byType, find, findAll, textOf } from './elementTree';
import InlineConfirm from './InlineConfirm';
import RemoteFilesPanel from './RemoteFilesPanel';

vi.mock( '@wordpress/element', async () => ( {
	...( await import( '../backups/hookRuntime' ) ).runtime.element,
} ) );
vi.mock( '@wordpress/components', () => ( {
	Button: () => null,
	Notice: () => null,
} ) );
vi.mock( '@wordpress/icons', () => ( {
	cloudDownload: 'dl',
	trash: 'trash',
} ) );
vi.mock( '../components/LoginNotice', () => ( {
	default: () => null,
} ) );
vi.mock( '../components/StatusBadge', () => ( {
	default: () => null,
} ) );
vi.mock( '../components/skeleton', () => ( {
	SkeletonFields: () => null,
	SkeletonRegion: () => null,
} ) );
vi.mock( '../data/api', () => ( {
	api: {
		remoteFiles: vi.fn(),
		deleteRemote: vi.fn(),
		restoreRemote: vi.fn(),
		fetchRemote: vi.fn(),
	},
} ) );
vi.mock( '../data/format', () => ( {
	formatBytes: ( n ) => `${ n } B`,
	formatDatetime: () => 'today',
} ) );
const remote = vi.hoisted( () => ( { data: null, reload: null } ) );
vi.mock( '../data/useRemote', () => ( {
	default: () => ( {
		data: remote.data,
		error: null,
		reload: remote.reload,
	} ),
} ) );
vi.mock( './InlineConfirm', () => ( {
	default: () => null,
} ) );

const ITEM = { id: 'abcd1234', name: 'Office NAS' };
const FILES = [
	{ name: 'site-1.wpress', size: 10, mtime: 1, ours: true },
	{ name: 'other-2.wpress', size: 20, mtime: 2, ours: false },
];

const mount = ( props = {} ) =>
	runtime.mount( ( p ) => RemoteFilesPanel( p ), {
		id: 'lw-dest-abcd1234-files',
		item: ITEM,
		busy: false,
		onStart: vi.fn(),
		...props,
	} );
const trashOf = ( hook, name ) =>
	button( hook.result(), Button, `Delete ${ name } at the destination` );
const rows = ( hook ) => findAll( hook.result(), ( el ) => 'li' === el.type );
const focusable = () => ( { focus: vi.fn() } );

describe( 'RemoteFilesPanel', () => {
	beforeEach( () => {
		remote.data = { files: FILES, insecure: false };
		remote.reload = vi.fn();
		api.deleteRemote.mockReset();
		api.restoreRemote.mockReset();
	} );

	it( 'is the region the toggle controls, named by its heading', () => {
		const hook = mount();
		const panel = hook.result();
		expect( panel.props.id ).toBe( 'lw-dest-abcd1234-files' );
		expect( panel.props.role ).toBe( 'region' );
		const heading = find( panel, ( el ) => 'h4' === el.type );
		expect( panel.props[ 'aria-labelledby' ] ).toBe( heading.props.id );
		expect( textOf( heading ) ).toBe( 'Backups at Office NAS' );
		expect( rows( hook ) ).toHaveLength( 2 );
		expect( textOf( rows( hook )[ 0 ] ) ).toContain( 'site-1.wpress' );
		hook.unmount();
	} );

	it( 'asks in the row before a delete, and Cancel gives the focus back', () => {
		const hook = mount();
		const trash = focusable();
		trashOf( hook, 'site-1.wpress' ).props.ref( trash );
		trashOf( hook, 'site-1.wpress' ).props.onClick();

		const confirm = byType( hook.result(), InlineConfirm );
		expect( confirm.key ).toBe( 'site-1.wpress' );
		expect( confirm.props.tag ).toBe( 'li' );
		expect( confirm.props.text ).toBe(
			'It is deleted at the destination for good.'
		);
		expect( confirm.props.confirmLabel ).toBe( 'Delete' );
		expect( textOf( confirm.props.lead ) ).toBe( 'site-1.wpress' );
		// The other row stays as it was.
		expect( trashOf( hook, 'other-2.wpress' ) ).not.toBeNull();

		confirm.props.onCancel();
		expect( byType( hook.result(), InlineConfirm ) ).toBeNull();
		expect( trash.focus ).toHaveBeenCalledTimes( 1 );
		expect( api.deleteRemote ).not.toHaveBeenCalled();
		hook.unmount();
	} );

	it( 'deletes on confirm, reads the list again and focuses the heading', async () => {
		api.deleteRemote.mockResolvedValue( {} );
		const hook = mount();
		const heading = focusable();
		find( hook.result(), ( el ) => 'h4' === el.type ).props.ref.current =
			heading;
		trashOf( hook, 'other-2.wpress' ).props.onClick();
		await byType( hook.result(), InlineConfirm ).props.onConfirm();
		expect( api.deleteRemote ).toHaveBeenCalledWith(
			'abcd1234',
			'other-2.wpress'
		);
		expect( remote.reload ).toHaveBeenCalledTimes( 1 );
		expect( byType( hook.result(), InlineConfirm ) ).toBeNull();
		expect( heading.focus ).toHaveBeenCalledTimes( 1 );
		hook.unmount();
	} );

	it( 'starts a restore and hands the job over', async () => {
		api.restoreRemote.mockResolvedValue( { id: 'job' } );
		const onStart = vi.fn();
		const hook = mount( { onStart } );
		await button(
			hook.result(),
			Button,
			'Restore the site from site-1.wpress'
		).props.onClick();
		expect( api.restoreRemote ).toHaveBeenCalledWith(
			'abcd1234',
			'site-1.wpress',
			true
		);
		expect( onStart ).toHaveBeenCalledWith( { id: 'job' } );
		hook.unmount();
	} );

	it( 'turns the actions off while another job runs', () => {
		const hook = mount( { busy: true } );
		const buttons = findAll( hook.result(), ( el ) => el.type === Button );
		expect( buttons ).toHaveLength( 6 );
		expect( buttons.every( ( b ) => b.props.disabled ) ).toBe( true );
		hook.unmount();
	} );

	it( 'says when there is no backup there yet', () => {
		remote.data = { files: [], insecure: false };
		const hook = mount();
		expect( textOf( hook.result() ) ).toContain( 'No backup there yet.' );
		hook.unmount();
	} );
} );
