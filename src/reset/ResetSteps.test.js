/**
 * External dependencies
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * WordPress dependencies
 */
import { Button, TextControl, ToggleControl } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { runtime } from '../backups/hookRuntime';
import Callout from '../components/Callout';
import LoginNotice from '../components/LoginNotice';
import { api } from '../data/api';
import { byType, find, findAll, textOf } from '../destinations/elementTree';
import BackupStep from './BackupStep';
import ResetLock from './ResetLock';
import ResetSteps from './ResetSteps';
import { SITE } from './fixtures/summaries';

vi.mock( '@wordpress/element', async ( importOriginal ) => ( {
	...( await importOriginal() ),
	...( await import( '../backups/hookRuntime' ) ).runtime.element,
} ) );
vi.mock( '@wordpress/components', () => ( {
	Button: () => null,
	TextControl: () => null,
	ToggleControl: () => null,
} ) );
vi.mock( '@wordpress/compose', () => ( {
	useInstanceId: () => 'steps',
} ) );
vi.mock( '../components/Callout', () => ( { default: () => null } ) );
vi.mock( '../components/LoginNotice', () => ( { default: () => null } ) );
vi.mock( '../data/api', () => ( { api: { startReset: vi.fn() } } ) );

const mount = ( patch = {} ) => {
	const props = {
		type: 'plugins',
		summary: SITE,
		busy: false,
		onStart: vi.fn(),
		...patch,
	};
	return { hook: runtime.mount( ( p ) => ResetSteps( p ), props ), props };
};

/**
 * The steps' own parts rendered in place (the backup step, the lock), so a
 * test sees their controls and texts; a numbered Step keeps its props.
 *
 * @param {unknown} node A React node.
 * @return {unknown} The node with those parts expanded.
 */
function expand( node ) {
	if ( Array.isArray( node ) ) {
		return node.map( expand );
	}
	if ( ! node || 'object' !== typeof node || ! node.props ) {
		return node;
	}
	if ( BackupStep === node.type || ResetLock === node.type ) {
		return expand( node.type( node.props ) );
	}
	return {
		...node,
		props: { ...node.props, children: expand( node.props.children ) },
	};
}

const tree = ( hook ) => expand( hook.result() );
const controls = ( hook ) =>
	findAll( tree( hook ), ( el ) => el.type === TextControl );
const password = ( hook ) =>
	controls( hook ).find( ( el ) => 'Your password' === el.props.label );
// The address field's label carries the address in a <code>.
const phrase = ( hook ) =>
	controls( hook ).find( ( el ) => 'string' !== typeof el.props.label );
const start = ( hook ) => byType( tree( hook ), Button );
const steps = ( hook ) =>
	findAll( tree( hook ), ( el ) => 'number' in ( el.props ?? {} ) );
const type = ( hook, el, value ) => el.props.onChange( value );
const submit = ( hook ) =>
	tree( hook ).props.onSubmit( { preventDefault: () => {} } );
const why = ( hook ) =>
	find( tree( hook ), ( el ) => 'steps-why' === el.props?.id );

/**
 * @param {Object} data Rejection fields.
 * @return {Object} A rejection of request().
 */
const refusal = ( data ) =>
	Object.assign( new Error( data.message ?? '' ), data );

describe( 'ResetSteps', () => {
	beforeEach( () => {
		api.startReset.mockReset();
	} );

	afterEach( () => {
		vi.useRealTimers();
	} );

	it( 'for plugins asks for the backup and the password, nothing to type', () => {
		const { hook } = mount();
		expect( tree( hook ).type ).toBe( 'form' );
		expect( byType( tree( hook ), 'ol' ) ).not.toBeNull();
		expect( steps( hook ).map( ( el ) => el.props.number ) ).toEqual( [
			1, 2,
		] );
		expect( phrase( hook ) ).toBeUndefined();
		const toggle = byType( tree( hook ), ToggleControl );
		expect( toggle.props.label ).toBe( 'Back up the whole site first' );
		expect( toggle.props.checked ).toBe( true );
		expect( password( hook ).props.help ).toBe(
			'Checked once before starting, never stored.'
		);
		expect( password( hook ).props.type ).toBe( 'password' );
		// The backup's name pattern and how long it is kept.
		const file = find(
			tree( hook ),
			( el ) => 'BackupFile' === el.type?.name
		);
		const said = textOf( file.type( file.props ) );
		expect( said ).toContain(
			'Named pre-reset-<time>.wpress, marked “Before a reset” in Backups.'
		);
		expect( said ).toContain(
			'The backup is kept until you delete it (resets never remove backups).'
		);
		expect( said ).not.toMatch( /\d{8}-\d{6}/ );
		// Done: the backup; open: the password, so the start is off.
		expect( steps( hook ).map( ( el ) => el.props.done ) ).toEqual( [
			true,
			false,
		] );
		expect( start( hook ).props ).toMatchObject( {
			type: 'submit',
			variant: 'primary',
			isDestructive: true,
			disabled: true,
			accessibleWhenDisabled: true,
			'aria-describedby': 'steps-why',
		} );
		expect( textOf( start( hook ).props.children ) ).toBe(
			'Delete plugins'
		);
		expect( textOf( why( hook ) ) ).toBe( 'Enter your password.' );
	} );

	it( 'turns the start on once the password is typed, with the keep-this-tab note', () => {
		const { hook } = mount();
		type( hook, password( hook ), 'secret' );
		expect( start( hook ).props.disabled ).toBe( false );
		expect( start( hook ).props[ 'aria-describedby' ] ).toBeUndefined();
		expect( steps( hook ).map( ( el ) => el.props.done ) ).toEqual( [
			true,
			true,
		] );
		expect( textOf( tree( hook ) ) ).toContain(
			'After starting, keep this tab open: the steps run from here.'
		);
	} );

	it( 'for the database asks to type the site address as a third step', () => {
		const { hook } = mount( { type: 'database' } );
		expect( steps( hook ) ).toHaveLength( 3 );
		const label = phrase( hook ).props.label;
		expect( textOf( label ) ).toBe( 'Type example.com/shop to confirm' );
		expect( byType( label, 'code' ) ).not.toBeNull();
		type( hook, password( hook ), 'secret' );
		expect( textOf( why( hook ) ) ).toBe(
			'Type the site address to confirm.'
		);
		type( hook, phrase( hook ), ' example.com/shop ' );
		expect( start( hook ).props.disabled ).toBe( false );
		expect( steps( hook )[ 2 ].props.done ).toBe( true );
		expect( textOf( start( hook ).props.children ) ).toBe(
			'Reset the database'
		);
	} );

	it( 'for everything adds the theme download note only when the theme is downloaded', () => {
		const download = {
			...SITE,
			types: {
				...SITE.types,
				themes: {
					...SITE.types.themes,
					target: { slug: 'tt5', name: 'TT5', source: 'download' },
				},
			},
		};
		const note =
			'The default theme is not installed: it is downloaded from wordpress.org first. This is the only network call of a reset.';
		expect(
			textOf( tree( mount( { type: 'full', summary: download } ).hook ) )
		).toContain( note );
		expect(
			textOf(
				tree( mount( { type: 'themes', summary: download } ).hook )
			)
		).toContain( note );
		expect(
			textOf( tree( mount( { type: 'media', summary: download } ).hook ) )
		).not.toContain( note );
		expect(
			textOf( tree( mount( { type: 'full' } ).hook ) )
		).not.toContain( note );
	} );

	it( 'keeps the start off on a busy site, and says why', () => {
		const { hook } = mount( { busy: true } );
		type( hook, password( hook ), 'secret' );
		expect( start( hook ).props.disabled ).toBe( true );
		expect( textOf( why( hook ) ) ).toBe( 'Another job is running.' );
	} );

	it( 'blocks a backed-up start when the backups folder cannot take one, until the backup is off', () => {
		const { hook } = mount( {
			summary: { ...SITE, backupReady: false },
		} );
		type( hook, password( hook ), 'secret' );
		expect( start( hook ).props.disabled ).toBe( true );
		expect( textOf( tree( hook ) ) ).toContain(
			'The backups folder cannot take a backup right now — check Settings, or switch the backup off to reset without one.'
		);
		expect( steps( hook )[ 0 ].props.done ).toBe( false );
		byType( tree( hook ), ToggleControl ).props.onChange( false );
		expect( start( hook ).props.disabled ).toBe( false );
		expect( textOf( tree( hook ) ) ).toContain(
			'Without a backup this cannot be undone.'
		);
		expect( textOf( tree( hook ) ) ).not.toContain(
			'cannot take a backup'
		);
		// Switched off, the backup step is not ticked.
		expect( steps( hook )[ 0 ].props.done ).toBe( false );
	} );

	it( 'keeps the password and clears the typed address when the choice changes', () => {
		const { hook, props } = mount( { type: 'database' } );
		type( hook, password( hook ), 'secret' );
		type( hook, phrase( hook ), 'example.com/shop' );
		hook.update( { ...props, type: 'full' } );
		expect( password( hook ).props.value ).toBe( 'secret' );
		expect( phrase( hook ).props.value ).toBe( '' );
		hook.update( { ...props, type: 'plugins' } );
		expect( password( hook ).props.value ).toBe( 'secret' );
		expect( api.startReset ).not.toHaveBeenCalled();
	} );

	it( 'never brings a typed address back when you return to its choice', () => {
		const { hook, props } = mount( { type: 'database' } );
		type( hook, password( hook ), 'secret' );
		type( hook, phrase( hook ), 'example.com/shop' );
		expect( start( hook ).props.disabled ).toBe( false );
		hook.update( { ...props, type: 'plugins' } );
		hook.update( { ...props, type: 'database' } );
		expect( phrase( hook ).props.value ).toBe( '' );
		expect( password( hook ).props.value ).toBe( 'secret' );
		expect( start( hook ).props.disabled ).toBe( true );
		expect( textOf( why( hook ) ) ).toBe(
			'Type the site address to confirm.'
		);
	} );

	it( 'turns the backup back on when the choice changes', () => {
		const { hook, props } = mount( { type: 'media' } );
		type( hook, password( hook ), 'secret' );
		byType( tree( hook ), ToggleControl ).props.onChange( false );
		expect( byType( tree( hook ), ToggleControl ).props.checked ).toBe(
			false
		);
		hook.update( { ...props, type: 'full' } );
		expect( byType( tree( hook ), ToggleControl ).props.checked ).toBe(
			true
		);
		expect( password( hook ).props.value ).toBe( 'secret' );
		// Back on the first choice, the backup stays on.
		hook.update( { ...props, type: 'media' } );
		expect( byType( tree( hook ), ToggleControl ).props.checked ).toBe(
			true
		);
	} );

	it( 'drops a refusal when the choice changes, also when you come back', async () => {
		api.startReset.mockRejectedValue(
			refusal( {
				code: 'lw_migration_reset_refused',
				message: 'This server runs as root.',
				data: { status: 409, reason: 'as_root' },
			} )
		);
		const { hook, props } = mount();
		type( hook, password( hook ), 'secret' );
		await submit( hook );
		const alert = () =>
			find( tree( hook ), ( el ) => 'alert' === el.props?.role );
		expect( alert() ).not.toBeNull();
		hook.update( { ...props, type: 'media' } );
		expect( alert() ).toBeNull();
		hook.update( { ...props, type: 'plugins' } );
		expect( alert() ).toBeNull();
		expect( textOf( tree( hook ) ) ).not.toContain(
			'This server runs as root.'
		);
	} );

	it( 'tells the view while a start is on its way, and when it was refused', async () => {
		let refuse;
		api.startReset.mockReturnValue(
			new Promise( ( resolve, reject ) => {
				refuse = reject;
			} )
		);
		const onStarting = vi.fn();
		const { hook } = mount( { onStarting } );
		type( hook, password( hook ), 'secret' );
		const sent = submit( hook );
		expect( onStarting ).toHaveBeenLastCalledWith( true );
		expect( start( hook ).props.isBusy ).toBe( true );
		refuse(
			refusal( {
				code: 'lw_migration_reset_refused',
				message: 'No.',
				data: { status: 409 },
			} )
		);
		await sent;
		expect( onStarting ).toHaveBeenLastCalledWith( false );
	} );

	it( 'names the blocked backup once: the step says it, the button points to it', () => {
		const { hook } = mount( {
			summary: { ...SITE, backupReady: false },
		} );
		type( hook, password( hook ), 'secret' );
		const blocked =
			'The backups folder cannot take a backup right now — check Settings, or switch the backup off to reset without one.';
		const said = textOf( tree( hook ) );
		expect( said.split( 'cannot take a backup' ) ).toHaveLength( 2 );
		expect( said ).toContain( blocked );
		expect( said ).toContain(
			'Fill in the steps and the button turns on.'
		);
		const describedBy = start( hook ).props[ 'aria-describedby' ];
		expect( describedBy ).toBe( 'steps-backup-error' );
		expect(
			textOf(
				find( tree( hook ), ( el ) => describedBy === el.props?.id )
			)
		).toBe( blocked );
		// A busy site still says why first.
		const busy = mount( {
			busy: true,
			summary: { ...SITE, backupReady: false },
		} ).hook;
		expect( textOf( why( busy ) ) ).toBe( 'Another job is running.' );
		expect( start( busy ).props[ 'aria-describedby' ] ).toBe( 'steps-why' );
	} );

	it( 'starts with exactly the old request body, clears the password and hands over the job', async () => {
		const job = { id: 'j1', kind: 'full' };
		api.startReset.mockResolvedValue( job );
		const { hook, props } = mount( { type: 'full' } );
		type( hook, password( hook ), 'secret' );
		type( hook, phrase( hook ), ' example.com/shop ' );
		await submit( hook );
		expect( api.startReset ).toHaveBeenCalledWith( {
			type: 'full',
			password: 'secret',
			backup: true,
			confirm: ' example.com/shop ',
		} );
		expect( props.onStart ).toHaveBeenCalledWith( job );
		expect( password( hook ).props.value ).toBe( '' );

		api.startReset.mockClear();
		const plugins = mount();
		type( plugins.hook, password( plugins.hook ), 'pw' );
		byType( tree( plugins.hook ), ToggleControl ).props.onChange( false );
		await submit( plugins.hook );
		expect( api.startReset.mock.calls[ 0 ][ 0 ] ).toEqual( {
			type: 'plugins',
			password: 'pw',
			backup: false,
		} );
	} );

	it( 'sends nothing while a step is open', async () => {
		const { hook } = mount( { type: 'database' } );
		type( hook, password( hook ), 'secret' );
		type( hook, phrase( hook ), 'not-this-site.test' );
		await submit( hook );
		expect( api.startReset ).not.toHaveBeenCalled();
	} );

	it( 'marks a refused password, clears it and focuses the field', async () => {
		api.startReset.mockRejectedValue(
			refusal( {
				code: 'lw_migration_invalid',
				data: { field: 'password', generic: true },
			} )
		);
		const { hook } = mount();
		const focus = vi.fn();
		password( hook ).props.ref.current = { focus };
		type( hook, password( hook ), 'wrong' );
		await submit( hook );
		expect( focus ).toHaveBeenCalled();
		expect( password( hook ).props ).toMatchObject( {
			value: '',
			'aria-invalid': true,
			'aria-describedby': 'steps-password-error',
			help: undefined,
		} );
		expect(
			textOf(
				find(
					tree( hook ),
					( el ) => 'steps-password-error' === el.props?.id
				)
			)
		).toBe( 'This is not your password.' );
		// Typing again clears the mark.
		type( hook, password( hook ), 'x' );
		expect( password( hook ).props[ 'aria-invalid' ] ).toBeUndefined();
	} );

	it( 'marks a refused address on its field', async () => {
		api.startReset.mockRejectedValue(
			refusal( {
				code: 'lw_migration_invalid',
				data: { field: 'confirm', generic: true },
			} )
		);
		const { hook } = mount( { type: 'database' } );
		const focus = vi.fn();
		phrase( hook ).props.ref.current = { focus };
		type( hook, password( hook ), 'secret' );
		type( hook, phrase( hook ), 'example.com/shop' );
		await submit( hook );
		expect( focus ).toHaveBeenCalled();
		expect( phrase( hook ).props[ 'aria-invalid' ] ).toBe( true );
		expect( textOf( tree( hook ) ) ).toContain(
			'Type the site address exactly as shown.'
		);
		expect( password( hook ).props.value ).toBe( '' );
	} );

	it( 'shows any other refusal for the whole panel and focuses it', async () => {
		api.startReset.mockRejectedValue(
			refusal( {
				code: 'lw_migration_reset_refused',
				message: 'This server runs as root.',
				data: { status: 409, reason: 'as_root' },
			} )
		);
		const { hook } = mount();
		type( hook, password( hook ), 'secret' );
		await submit( hook );
		const alert = find(
			tree( hook ),
			( el ) => 'alert' === el.props?.role
		);
		expect( textOf( byType( alert, Callout ).props.children ) ).toBe(
			'This server runs as root.'
		);
		const focus = vi.fn();
		alert.props.ref.current = { focus };
		// The focus moved on the refusal (the ref was empty then); a new
		// refusal moves it again.
		type( hook, password( hook ), 'again' );
		await submit( hook );
		expect( focus ).toHaveBeenCalled();
		expect( password( hook ).props.value ).toBe( '' );
	} );

	it( 'shows the login notice when the session ended', async () => {
		api.startReset.mockRejectedValue(
			refusal( {
				code: 'rest_not_logged_in',
				status: 401,
				data: { status: 401 },
			} )
		);
		const { hook } = mount();
		type( hook, password( hook ), 'secret' );
		await submit( hook );
		expect( byType( tree( hook ), LoginNotice ).props.tab ).toBe( 'reset' );
		expect( password( hook ).props.value ).toBe( '' );
		// The focus moves to the notice, as for any other refusal.
		const holder = find(
			tree( hook ),
			( el ) =>
				-1 === el.props?.tabIndex && byType( el, LoginNotice ) !== null
		);
		expect( holder ).not.toBeNull();
		const focus = vi.fn();
		holder.props.ref.current = { focus };
		type( hook, password( hook ), 'again' );
		await submit( hook );
		expect( focus ).toHaveBeenCalled();
	} );

	it( 'replaces the steps with the lock after too many wrong passwords, until its time passes', async () => {
		vi.useFakeTimers();
		api.startReset.mockRejectedValue(
			refusal( {
				code: 'lw_migration_reset_locked',
				data: { status: 429, retryAfter: 840 },
			} )
		);
		const { hook, props } = mount();
		type( hook, password( hook ), 'wrong' );
		await submit( hook );
		const panel = tree( hook );
		expect( panel.props.className ).toBe( 'lw-reset-locked' );
		expect( panel.props.role ).toBe( 'alert' );
		expect( textOf( panel ) ).toBe(
			'You cannot start a reset right now.Too many wrong passwords. Try again in 14 minutes.'
		);
		expect( textOf( panel ) ).not.toMatch( /attempt|left/ );
		expect( byType( panel, Button ) ).toBeNull();
		expect( byType( panel, 'ol' ) ).toBeNull();
		// Another choice keeps the lock.
		hook.update( { ...props, type: 'media' } );
		expect( tree( hook ).props.className ).toBe( 'lw-reset-locked' );
		vi.advanceTimersByTime( 14 * 60000 );
		expect( tree( hook ).type ).toBe( 'form' );
		expect( start( hook ).props.disabled ).toBe( true );
		hook.unmount();
	} );
} );
