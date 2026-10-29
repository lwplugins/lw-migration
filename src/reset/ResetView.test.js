/**
 * External dependencies
 */
import { readFileSync, readdirSync } from 'node:fs';
import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Internal dependencies
 */
import { runtime } from '../backups/hookRuntime';
import Callout from '../components/Callout';
import LoadError from '../components/LoadError';
import { byType, find, findAll, textOf } from '../destinations/elementTree';
import ResetChoices from './ResetChoices';
import ResetLayers from './ResetLayers';
import ResetLead from './ResetLead';
import ResetSkeleton from './ResetSkeleton';
import ResetSteps from './ResetSteps';
import ResetView from './ResetView';
import { NETWORK, SITE } from './fixtures/summaries';

vi.mock( '@wordpress/element', async () => ( {
	...( await import( '../backups/hookRuntime' ) ).runtime.element,
} ) );
vi.mock( '../components/Callout', () => ( { default: () => null } ) );
vi.mock( '../components/LoadError', () => ( { default: () => null } ) );
vi.mock( '../data/api', () => ( { api: { resetSummary: vi.fn() } } ) );
vi.mock( '../data/useRemote', () => ( { default: () => remote } ) );
vi.mock( './ResetChoices', () => ( { default: () => null } ) );
vi.mock( './ResetLayers', () => ( { default: () => null } ) );
vi.mock( './ResetSkeleton', () => ( { default: () => null } ) );
vi.mock( './ResetSteps', () => ( { default: () => null } ) );
vi.mock( './reset.scss', () => ( {} ) );

let remote;

const mount = ( patch = {} ) =>
	runtime.mount( ( p ) => ResetView( p ), {
		busy: false,
		onStart: vi.fn(),
		...patch,
	} );

const choose = ( hook, type ) =>
	byType( hook.result(), ResetChoices ).props.onChange( type );

describe( 'ResetView', () => {
	beforeEach( () => {
		remote = { data: SITE, error: null, reload: vi.fn() };
	} );

	it( 'shows the skeleton while the summary loads, the load error when it failed', () => {
		remote.data = null;
		expect( mount().result().type ).toBe( ResetSkeleton );
		remote.error = { code: 'x' };
		const hook = mount();
		expect( hook.result().type ).toBe( LoadError );
		expect( hook.result().props.tab ).toBe( 'reset' );
	} );

	it( 'opens with the lead, the question and no choice: the panel waits', () => {
		const hook = mount();
		expect(
			find( hook.result(), ( el ) => ResetLead === el.type )
		).not.toBe( null );
		const lead = byType( hook.result(), Callout );
		expect( lead.props.tone ).toBe( 'warning' );
		expect( textOf( lead.props.children ) ).toBe(
			'These tools delete. Each one backs up the whole site first unless you switch that off, and asks for your password. Before that, you see here what goes and what stays.'
		);
		expect(
			findAll( hook.result(), ( el ) => el.type === Callout )
		).toHaveLength( 1 );
		const title = byType( hook.result(), 'h2' );
		expect( textOf( title ) ).toBe( 'What do you want to reset?' );
		const choices = byType( hook.result(), ResetChoices );
		expect( choices.props ).toMatchObject( {
			summary: SITE,
			value: null,
			labelledBy: title.props.id,
		} );
		expect( byType( hook.result(), ResetLayers ) ).toBeNull();
		expect( byType( hook.result(), ResetSteps ) ).toBeNull();
		expect(
			textOf(
				find(
					hook.result(),
					( el ) => 'lw-reset-plan__idle' === el.props?.className
				)
			)
		).toContain( 'Choose a tool above' );
	} );

	it( 'shows the layers and the steps of the chosen reset', () => {
		const onStart = vi.fn();
		const hook = mount( { busy: true, onStart } );
		choose( hook, 'database' );
		expect( byType( hook.result(), ResetChoices ).props.value ).toBe(
			'database'
		);
		expect( byType( hook.result(), ResetLayers ).props ).toEqual( {
			type: 'database',
			summary: SITE,
		} );
		expect( byType( hook.result(), ResetSteps ).props ).toMatchObject( {
			type: 'database',
			summary: SITE,
			busy: true,
			onStart,
		} );
		const titles = findAll( hook.result(), ( el ) => 'h3' === el.type ).map(
			textOf
		);
		expect( titles ).toEqual( [
			'What goes and what stays',
			'Before you start',
		] );
		// The same steps follow the next choice (their state is kept).
		const steps = byType( hook.result(), ResetSteps );
		choose( hook, 'full' );
		expect( byType( hook.result(), ResetSteps ).key ).toBe( steps.key );
		expect( byType( hook.result(), ResetSteps ).props.type ).toBe( 'full' );
	} );

	it( 'holds the choices while a start is on its way, so a refusal lands on the choice on screen', () => {
		const hook = mount();
		choose( hook, 'plugins' );
		const choices = () => byType( hook.result(), ResetChoices );
		expect( choices().props.disabled ).toBe( false );
		byType( hook.result(), ResetSteps ).props.onStarting( true );
		expect( choices().props.disabled ).toBe( true );
		// Even a change that gets through is ignored.
		choose( hook, 'media' );
		expect( byType( hook.result(), ResetSteps ).props.type ).toBe(
			'plugins'
		);
		byType( hook.result(), ResetSteps ).props.onStarting( false );
		expect( choices().props.disabled ).toBe( false );
		choose( hook, 'media' );
		expect( byType( hook.result(), ResetSteps ).props.type ).toBe(
			'media'
		);
	} );

	it( 'on a network says so in the lead and keeps the network callout', () => {
		remote.data = NETWORK;
		const hook = mount();
		const callouts = findAll(
			hook.result(),
			( el ) => el.type === Callout
		);
		expect( callouts ).toHaveLength( 2 );
		expect( textOf( callouts[ 0 ].props.children ) ).toContain(
			'Each one backs up the whole network first'
		);
		expect( textOf( callouts[ 1 ].props.children ) ).toBe(
			'On a network the database cannot be reset in this version; plugins, themes and media are reset for every site.'
		);
	} );
} );

describe( 'the Reset section', () => {
	it( 'opens no dialog and asks no browser confirm', () => {
		const folder = new URL( '.', import.meta.url );
		readdirSync( folder )
			.filter(
				( file ) => /\.js$/.test( file ) && ! /\.test\.js$/.test( file )
			)
			.forEach( ( file ) => {
				const source = readFileSync( new URL( file, folder ), 'utf8' );
				expect( source ).not.toMatch( /\bModal\b/ );
				expect( source ).not.toMatch( /window\.confirm|\bconfirm\(/ );
			} );
	} );
} );
