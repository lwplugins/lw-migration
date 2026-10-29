/**
 * External dependencies
 */
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import { DEFAULTS } from '../views/exportRequest';
import {
	NEW_SCHEDULE,
	checkSchedule,
	duplicateForm,
	fromItem,
	keepsPassword,
	toRequest,
	withKnownDestinations,
} from './scheduleForm';

// Rest\ScheduleInput::KEYS, read from the PHP source: a key the server does
// not know would be a 400.
const SERVER_KEYS = () => {
	const source = readFileSync(
		new URL( '../../includes/Rest/ScheduleInput.php', import.meta.url ),
		'utf8'
	);
	const list = source.match( /const KEYS = \[([^\]]*)\];/ )[ 1 ];
	return [ ...list.matchAll( /'(\w+)'/g ) ].map( ( m ) => m[ 1 ] );
};

const item = ( patch = {} ) => ( {
	id: '0a1b2c3d',
	title: 'Nightly',
	enabled: true,
	frequency: 'days',
	every: 3,
	minute: 15,
	hour: 2,
	weekday: 1,
	monthday: 1,
	export: {
		noSpamComments: false,
		noPostRevisions: false,
		noMedia: true,
		noThemes: false,
		noInactiveThemes: false,
		noMustUsePlugins: false,
		noPlugins: false,
		noInactivePlugins: false,
		noCache: true,
		noDatabase: false,
		noEmailReplace: false,
		encrypt: true,
		excludeFiles: [ 'uploads/big', 'cache' ],
		excludeTables: [],
		includeTables: [],
		replace: [ [ 'http://a.test', 'https://a.test' ] ],
		compression: '',
	},
	hasPassword: true,
	retention: { count: 5, bytes: 0, days: 30 },
	notify: { when: 'always', email: 'ops@a.test' },
	destinations: [],
	keepLocal: true,
	nextRun: 1000,
	upcoming: [ 1000 ],
	lastRun: null,
	running: false,
	...patch,
} );

describe( 'schedule requests', () => {
	it( 'sends only keys the server knows', () => {
		const keys = SERVER_KEYS();
		const sent = Object.keys(
			toRequest( fromItem( item() ), 'new-password' )
		);
		expect( sent.filter( ( key ) => ! keys.includes( key ) ) ).toEqual(
			[]
		);
	} );

	it( 'round-trips a stored schedule through the form', () => {
		const body = toRequest( fromItem( item() ), '' );
		expect( body ).toEqual( {
			title: 'Nightly',
			enabled: true,
			frequency: 'days',
			every: 3,
			minute: 15,
			hour: 2,
			weekday: 1,
			monthday: 1,
			retention: { count: 5, bytes: 0, days: 30 },
			notify: { when: 'always', email: 'ops@a.test' },
			export: {
				...item().export,
				excludeFiles: [ 'uploads/big', 'cache' ],
			},
			destinations: [],
			keepLocal: true,
		} );
		expect( body ).not.toHaveProperty( 'password' );
	} );

	it( 'leaves out "every" where the frequency has none', () => {
		const body = toRequest(
			fromItem( item( { frequency: 'weekly', every: 1 } ) ),
			''
		);
		expect( body ).not.toHaveProperty( 'every' );
	} );

	it( 'always sends the complete export object, also on an unrelated edit', () => {
		const body = toRequest(
			{ ...fromItem( item() ), title: 'Renamed' },
			''
		);
		expect( Object.keys( body.export ).sort() ).toEqual(
			Object.keys( item().export ).sort()
		);
	} );

	it( 'sends the destinations with their retention and keep-local', () => {
		const form = fromItem(
			item( {
				destinations: [
					{
						id: 'aaaa1111',
						retention: { count: 3, bytes: 0, days: 0 },
					},
				],
				keepLocal: false,
			} )
		);

		const body = toRequest( form, '', false );

		expect( body.destinations ).toEqual( [
			{ id: 'aaaa1111', retention: { count: 3, bytes: 0, days: 0 } },
		] );
		expect( body.keepLocal ).toBe( false );
		expect(
			Object.keys( body ).every( ( key ) =>
				SERVER_KEYS().includes( key )
			)
		).toBe( true );
		expect( NEW_SCHEDULE.keepLocal ).toBe( true );
		expect( NEW_SCHEDULE.destinations ).toEqual( [] );
	} );

	it( 'drops a destination deleted since, once the list is known', () => {
		const form = fromItem(
			item( {
				destinations: [
					{
						id: 'aaaa1111',
						retention: { count: 3, bytes: 0, days: 0 },
					},
					{
						id: 'dddd4444',
						retention: { count: 1, bytes: 0, days: 0 },
					},
				],
			} )
		);

		expect(
			withKnownDestinations( form, [ { id: 'aaaa1111' } ] ).destinations
		).toEqual( [
			{ id: 'aaaa1111', retention: { count: 3, bytes: 0, days: 0 } },
		] );
		// Not loaded (null): the schedule keeps what it had.
		expect( withKnownDestinations( form, null ) ).toBe( form );
	} );

	it( 'sends a password only when one was typed and protection is on', () => {
		const form = fromItem( item() );
		expect( toRequest( form, 'pw' ).password ).toBe( 'pw' );
		const off = { ...form, export: { ...form.export, encrypt: false } };
		expect( toRequest( off, 'pw' ) ).not.toHaveProperty( 'password' );
	} );
} );

describe( 'a schedule of chosen network sites', () => {
	const limited = ( patch = {} ) =>
		item( { export: { ...item().export, sites: [ 3, 2 ] }, ...patch } );

	it( 'reads the stored sites into the scope', () => {
		const form = fromItem( limited() );
		expect( form.export.scope ).toBe( 'sites' );
		expect( form.export.sites ).toEqual( [ 3, 2 ] );
		expect( fromItem( item() ).export.scope ).toBe( 'network' );
		expect(
			fromItem( item( { export: { ...item().export, sites: [] } } ) )
				.export.scope
		).toBe( 'network' );
	} );

	it( 'round-trips export.sites on a network', () => {
		const body = toRequest( fromItem( limited() ), '', true );
		expect( body.export ).toEqual( limited().export );
	} );

	it( 'keeps the sites on an unrelated edit, never widening to the whole network', () => {
		const body = toRequest(
			{ ...fromItem( limited() ), title: 'Renamed' },
			'',
			true
		);
		expect( body.export.sites ).toEqual( [ 3, 2 ] );
	} );

	it( 'sends no sites when switched to the whole network', () => {
		const form = fromItem( limited() );
		const whole = {
			...form,
			export: { ...form.export, scope: 'network' },
		};
		expect( toRequest( whole, '', true ).export ).not.toHaveProperty(
			'sites'
		);
	} );

	it( 'needs at least one chosen site', () => {
		const form = fromItem( limited() );
		const none = { ...form, export: { ...form.export, sites: [] } };
		expect( checkSchedule( none, '', '', true ) ).toEqual( {
			field: 'sites',
			reason: 'empty',
		} );
	} );

	it( 'never sends sites from a single site', () => {
		expect(
			toRequest( fromItem( limited() ), '' ).export
		).not.toHaveProperty( 'sites' );
	} );
} );

describe( 'checkSchedule', () => {
	it( 'needs a name and an "every" in range', () => {
		const form = fromItem( item() );
		expect( checkSchedule( { ...form, title: '  ' }, '', '' ) ).toEqual( {
			field: 'title',
			reason: 'empty',
		} );
		expect( checkSchedule( { ...form, every: 1 }, '', '' ) ).toEqual( {
			field: 'every',
			reason: 'range',
		} );
		expect( checkSchedule( { ...form, every: 91 }, '', '' ) ).toEqual( {
			field: 'every',
			reason: 'range',
		} );
	} );

	it( 'keeps a stored password, but checks a new one', () => {
		const form = fromItem( item() );
		expect( keepsPassword( form, '', '' ) ).toBe( true );
		expect( checkSchedule( form, '', '' ) ).toBeNull();
		expect( checkSchedule( form, 'a', 'b' ) ).toEqual( {
			field: 'password',
			reason: 'mismatch',
		} );
		const fresh = {
			...NEW_SCHEDULE,
			title: 'New',
			export: { ...DEFAULTS, encrypt: true },
		};
		expect( checkSchedule( fresh, '', '' ) ).toEqual( {
			field: 'password',
			reason: 'empty',
		} );
	} );

	it( 'uses the Backup section rules for the export options', () => {
		const form = fromItem( item() );
		const half = {
			...form,
			export: { ...form.export, replace: [ [ 'a', '' ] ] },
		};
		expect( checkSchedule( half, '', '' ) ).toEqual( {
			field: 'replace',
			reason: 'pair',
			index: 0,
		} );
	} );
} );

describe( 'duplicateForm', () => {
	it( 'copies every field, names it a copy and never carries the password', () => {
		const copy = duplicateForm( item() );
		expect( copy ).toEqual( {
			...fromItem( item() ),
			title: 'Nightly (copy)',
			hasPassword: false,
		} );
		// Encrypted without a stored password: the editor asks for a new one.
		expect( copy.export.encrypt ).toBe( true );
		expect( checkSchedule( copy, '', '', false ) ).toMatchObject( {
			field: 'password',
		} );
		expect( toRequest( copy, '', false ) ).not.toHaveProperty( 'password' );
		expect( toRequest( copy, '', false ) ).not.toHaveProperty( 'id' );
	} );
} );
