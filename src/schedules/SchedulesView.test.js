/**
 * External dependencies
 */
// react-dom comes with @wordpress/element; these tests render on the server.
// eslint-disable-next-line import/no-extraneous-dependencies
import { renderToStaticMarkup } from 'react-dom/server';
import {
	afterAll,
	beforeAll,
	beforeEach,
	describe,
	expect,
	it,
	vi,
} from 'vitest';

/**
 * WordPress dependencies
 */
import { createElement } from '@wordpress/element';

/**
 * Internal dependencies
 */
import SchedulesView from './SchedulesView';

// The real components need the browser; plain stand-ins keep what they show.
vi.mock( '@wordpress/components', () => {
	const box =
		( tag ) =>
		( { children, className } ) =>
			createElement( tag, { className }, children );
	return {
		Button: ( { children, disabled } ) =>
			createElement(
				'button',
				{ 'aria-disabled': disabled ? 'true' : undefined },
				children
			),
		Card: box( 'div' ),
		CardBody: box( 'div' ),
		CardHeader: box( 'div' ),
		DropdownMenu: ( { label, controls } ) =>
			createElement(
				'div',
				{ className: 'menu', 'aria-label': label },
				controls.map( ( c ) =>
					createElement( 'span', { key: c.title }, c.title )
				)
			),
		FormToggle: ( { checked } ) =>
			createElement( 'input', {
				type: 'checkbox',
				checked,
				readOnly: true,
			} ),
		Modal: () => null,
		Notice: box( 'div' ),
	};
} );

vi.mock( '@wordpress/data', () => ( {
	useDispatch: () => ( {
		createSuccessNotice: () => {},
		createWarningNotice: () => {},
	} ),
} ) );

vi.mock( '@wordpress/date', () => ( {
	getSettings: () => ( {
		timezone: { string: 'Europe/Budapest', offset: 2 },
	} ),
} ) );

vi.mock( '../data/api', () => ( {
	api: { schedules: () => null, destinations: () => null },
} ) );

// What GET /schedules and GET /destinations answer (a server render runs no
// effect, so the hook hands the state over directly).
const remote = vi.hoisted( () => ( { schedules: null, destinations: null } ) );
vi.mock( '../data/useRemote', async () => {
	const { api } = await import( '../data/api' );
	return {
		default: ( fetcher ) => ( {
			data:
				fetcher === api.schedules
					? remote.schedules
					: remote.destinations,
			error: null,
			isLoading: false,
			reload: () => Promise.resolve(),
		} ),
	};
} );

const utc = ( month, day, hour, minute = 0 ) =>
	Date.UTC( 2026, month - 1, day, hour, minute ) / 1000;
// Friday 16 October 2026, 15:20 in Budapest.
const NOW = utc( 10, 16, 13, 20 );
const NIGHT = ( day ) => utc( 10, day, 1 ); // 03:00 in Budapest.

const EXPORT = {
	noMedia: false,
	noThemes: false,
	noPlugins: false,
	noDatabase: false,
	encrypt: false,
};

const schedule = ( patch ) => ( {
	id: 'aaaa1111',
	title: 'Nightly',
	enabled: true,
	frequency: 'daily',
	every: 2,
	minute: 0,
	hour: 3,
	weekday: 1,
	monthday: 1,
	export: EXPORT,
	hasPassword: false,
	retention: { count: 7, bytes: 0, days: 0 },
	destinations: [],
	keepLocal: true,
	notify: { when: 'failure', email: '' },
	nextRun: NIGHT( 17 ),
	upcoming: [ NIGHT( 17 ), NIGHT( 18 ), NIGHT( 19 ), NIGHT( 20 ) ],
	lastRun: {
		status: 'done',
		endedAt: utc( 10, 16, 1, 4 ),
		backup: 'a.wpress',
		size: 1825361100,
		trigger: 'cron',
		cloud: [],
	},
	running: false,
	week: [ 17, 18, 19, 20, 21, 22 ].map( NIGHT ),
	history: [
		{ status: 'done', endedAt: utc( 10, 14, 1, 2 ), trigger: 'cron' },
		{ status: 'failed', endedAt: utc( 10, 15, 1, 1 ), trigger: 'cron' },
		{ status: 'cancelled', endedAt: utc( 10, 15, 9 ), trigger: 'manual' },
		{ status: 'done', endedAt: utc( 10, 16, 1, 4 ), trigger: 'cron' },
	],
	...patch,
} );

const THREE = [
	schedule( {} ),
	schedule( {
		id: 'bbbb2222',
		title: 'Weekly to the cloud',
		frequency: 'weekly',
		weekday: 7,
		destinations: [
			{ id: 'dddd4444', retention: { count: 8, days: 0 } },
			{ id: 'gone0000', retention: { count: 3, days: 0 } },
		],
		keepLocal: false,
		upcoming: [ utc( 10, 18, 0, 30 ) ],
		// Due in the same minute as Nightly on Sunday.
		week: [ NIGHT( 18 ) ],
		history: [
			{
				status: 'partial',
				endedAt: utc( 10, 11, 0, 41 ),
				trigger: 'cron',
			},
		],
		running: true,
	} ),
	schedule( {
		id: 'cccc3333',
		title: 'Database by day',
		enabled: false,
		frequency: 'hours',
		every: 6,
		minute: 15,
		export: { ...EXPORT, noMedia: true, noPlugins: true, noThemes: true },
		nextRun: null,
		upcoming: [],
		week: [],
		history: [],
		lastRun: null,
	} ),
];

const list = ( items, cron = { disabled: false, late: false } ) => ( {
	items,
	cron,
	timezone: 'Europe/Budapest',
} );

const render = ( props = {} ) =>
	renderToStaticMarkup(
		createElement( SchedulesView, {
			busy: false,
			onStart: () => {},
			settings: {},
			...props,
		} )
	);

const count = ( html, needle ) => html.split( needle ).length - 1;

beforeAll( () => {
	vi.useFakeTimers( { toFake: [ 'Date' ] } );
	vi.setSystemTime( NOW * 1000 );
	vi.stubGlobal( 'document', { documentElement: { lang: 'en-GB' } } );
} );

afterAll( () => {
	vi.useRealTimers();
	vi.unstubAllGlobals();
} );

beforeEach( () => {
	remote.schedules = null;
	remote.destinations = {
		items: [ { id: 'dddd4444', type: 'dropbox', name: 'Team' } ],
	};
} );

describe( 'SchedulesView', () => {
	it( 'shows the layout as a skeleton while loading, never a spinner', () => {
		const html = render();
		expect( html ).toContain( 'role="status"' );
		expect( html ).toContain( 'Loading schedules…' );
		expect( html ).toContain( 'lw-week' );
		expect( count( html, 'class="lw-sched"' ) ).toBe( 2 );
		expect( html ).not.toContain( 'components-spinner' );
		expect( html ).not.toContain( 'New schedule' );
	} );

	it( 'offers three starters when there is no schedule', () => {
		remote.schedules = list( [] );
		const html = render();
		expect( html ).toContain( 'No schedules yet' );
		expect( html ).toContain( 'Daily backup at night' );
		expect( html ).toContain( 'Weekly full backup' );
		expect( html ).toContain( 'Database every 6 hours' );
		expect( count( html, 'class="lw-starter"' ) ).toBe( 3 );
		expect( html ).not.toContain( 'The next 7 days' );
		expect( html ).not.toContain( 'lw-cron' );
		expect( html ).toContain( 'New schedule' );
	} );

	it( 'draws three schedules: the ruler, one card each', () => {
		remote.schedules = list( THREE );
		const html = render();
		expect( count( html, '<article' ) ).toBe( 3 );
		expect( html ).toContain( 'The next 7 days' );
		expect( html ).toContain( 'Today 16' );
		// Six runs of Nightly and one of Weekly on the ruler; none of the switched-off one.
		expect( count( html, 'class="lw-week__tick"' ) ).toBe( 7 );
		expect( html ).toContain( 'Nightly: tomorrow 03:00' );
		expect( html ).toContain( 'switched off, runs only when you start it' );
		expect( html ).toContain( 'lw-lane-0' );
		expect( html ).toContain( 'lw-lane-2' );
		// Nightly and Weekly are both due on Sunday at 03:00.
		expect( html ).toContain(
			'Nightly and Weekly to the cloud are due in the same minute'
		);
		// The card facts: next run, the wait, the runs after it.
		expect( html ).toContain( 'tomorrow 03:00' );
		expect( html ).toContain( 'in 11 hours 40 minutes' );
		expect( html ).toContain( 'Every day at 03:00' );
		// The strip: four bars, the cancelled one neutral.
		expect( html ).toContain( 'The last 4 runs: 2 ready, 2 not.' );
		expect( count( html, 'class="is-unknown"' ) ).toBe( 1 );
		expect( html ).toContain( 'The last 1 run: 0 ready, 1 not.' );
		expect( html ).toContain( 'today 03:04 · 1.7 GB' );
		// What and where.
		expect( html ).toContain( 'Here, on this server' );
		expect( html ).toContain( 'At most 7 backups' );
		expect( html ).toContain( 'Team' );
		expect( html ).toContain( 'At most 8 backups' );
		expect( html ).toContain( 'A deleted destination (its runs skip it)' );
		expect( html ).toContain( 'When a run fails, to the default address' );
		expect( html ).toContain( 'Duplicate' );
		expect( html ).toContain( 'Delete schedule' );
	} );

	it( 'marks a running schedule and keeps Run now off while a job runs', () => {
		remote.schedules = list( THREE );
		const html = render( { busy: true } );
		// No job on this page (the cron started it): the badge alone.
		expect( html ).toContain( '>running<' );
		expect( html ).not.toContain( 'role="progressbar"' );
		expect( count( html, 'aria-disabled="true"' ) ).toBe( 3 );
	} );

	it( 'shows the progress of the job the page drives on the running card', () => {
		remote.schedules = list( THREE );
		const job = {
			type: 'export',
			status: 'running',
			steps: [
				'init',
				'config',
				'enumerate',
				'content',
				'database',
				'database_file',
				'finalize',
				'store',
				'clean',
			],
			step: 'content',
			stepIndex: 3,
			progress: 80,
			createdAt: utc( 10, 16, 13, 12 ),
		};
		const html = render( { busy: true, job } );
		expect( html ).toContain( 'running · 42%' );
		expect( count( html, 'role="progressbar"' ) ).toBe( 1 );
		expect( html ).toContain( 'aria-valuenow="42"' );
		expect( html ).toContain( 'Started today 15:12 · Files' );
		// A finished job or another kind of job is not this schedule's.
		const done = render( { job: { ...job, status: 'done' } } );
		expect( done ).not.toContain( 'role="progressbar"' );
		const restore = render( { job: { ...job, type: 'import' } } );
		expect( restore ).not.toContain( 'role="progressbar"' );
	} );

	it( 'shows why the last run failed', () => {
		remote.schedules = list( [
			schedule( {
				lastRun: {
					status: 'failed',
					endedAt: utc( 10, 14, 1, 1 ),
					backup: '',
					size: 0,
					trigger: 'cron',
					error: 'Not enough free space in the backups folder.',
					cloud: [],
				},
			} ),
		] );
		const html = render();
		expect( html ).toContain(
			'<p class="lw-fact__error">Not enough free space in the backups folder.</p>'
		);
		remote.schedules = list( [ schedule( {} ) ] );
		expect( render() ).not.toContain( 'lw-fact__error' );
	} );

	it( 'says a switched-off schedule runs only by hand', () => {
		remote.schedules = list( [ THREE[ 2 ] ] );
		const html = render();
		expect( html ).toContain( 'switched off' );
		expect( html ).toContain( 'It runs only when you start it.' );
		expect( html ).toContain( 'Not run yet' );
		expect( count( html, 'class="is-out"' ) ).toBe( 3 );
		expect( html ).not.toContain( 'class="lw-week__tick"' );
		expect( html ).not.toContain( 'lw-week__clash' );
	} );

	it( 'warns when WP-Cron is late, with the command to copy', () => {
		remote.schedules = list( THREE, { disabled: false, late: true } );
		const html = render();
		expect( html ).toContain( 'lw-cron is-late' );
		expect( html ).toContain( 'Scheduled backups are late' );
		expect( html ).toContain(
			'<code>wp lw-migration schedule run --due --quiet</code>'
		);
		expect( html ).toContain( 'Copy' );
	} );

	it( 'informs when WP-Cron is switched off', () => {
		remote.schedules = list( THREE, { disabled: true, late: false } );
		const html = render();
		expect( html ).toContain( 'lw-cron is-off' );
		expect( html ).toContain( 'WP-Cron is switched off on this site' );
		expect( html ).not.toContain( 'Scheduled backups are late' );
	} );

	it( 'shows no WP-Cron box when it runs', () => {
		remote.schedules = list( THREE );
		expect( render() ).not.toContain( 'lw-cron' );
	} );
} );
