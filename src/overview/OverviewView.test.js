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
import { OverviewContent, endedNow } from './OverviewView';
import OverviewSkeleton from './OverviewSkeleton';
import { defaultChoice, moveText } from './QuickActions';
import { destinationMeta, destinationPill } from './DestinationList';
import empty from './fixtures/empty.json';
import seeded from './fixtures/seeded.json';

// The real components need the browser; plain stand-ins keep what they show.
vi.mock( '@wordpress/components', () => {
	const box =
		( tag, className = '' ) =>
		( { children } ) =>
			createElement( tag, className ? { className } : {}, children );
	return {
		Button: ( { children, href, disabled } ) =>
			href
				? createElement( 'a', { href }, children )
				: createElement(
						'button',
						{ 'aria-disabled': disabled ? 'true' : undefined },
						children
					),
		Card: ( { children, className } ) =>
			createElement( 'div', { className }, children ),
		CardBody: box( 'div' ),
		CardHeader: box( 'div' ),
		CheckboxControl: ( { label, checked, disabled } ) =>
			createElement( 'label', {}, [
				createElement( 'input', {
					key: 'box',
					type: 'checkbox',
					checked,
					disabled,
					readOnly: true,
				} ),
				label,
			] ),
		Notice: box( 'div', 'notice' ),
		__experimentalHeading: box( 'h3' ),
		__experimentalHStack: box( 'div' ),
		__experimentalVStack: box( 'div' ),
		__experimentalText: box( 'p' ),
		__experimentalToggleGroupControl: box( 'div', 'segmented' ),
		__experimentalToggleGroupControlOption: ( { label } ) =>
			createElement( 'button', {}, label ),
	};
} );

// The site's time zone: Budapest (UTC+2 in October).
vi.mock( '@wordpress/date', () => ( {
	getSettings: () => ( {
		timezone: { string: 'Europe/Budapest', offset: 2 },
	} ),
} ) );

// The page's own reads: the overview arrives as a prop; the chips' list of
// destinations (GET /destinations) is whatever `destinations.state` says
// (loading unless a test sets it), since a server render runs no effect.
vi.mock( '../data/api', () => ( {
	api: {
		destinations: () => new Promise( () => {} ),
		startJob: vi.fn(),
	},
} ) );

const LOADING = { data: null, error: null, isLoading: true };
const destinations = vi.hoisted( () => ( { state: null } ) );
vi.mock( '../data/useRemote', () => ( {
	default: () => ( {
		...( destinations.state || {
			data: null,
			error: null,
			isLoading: true,
		} ),
		reload: () => {},
	} ),
} ) );

/**
 * Render with GET /destinations in the given state, then back to loading.
 *
 * @param {Object}       state  `{ data, error, isLoading }`.
 * @param {() => string} render Renders.
 * @return {string} Markup.
 */
const withDestinations = ( state, render ) => {
	destinations.state = state;
	try {
		return render();
	} finally {
		destinations.state = null;
	}
};

const NOW = Date.UTC( 2026, 9, 16, 9, 0 ) / 1000; // 11:00 in Budapest.
const DAY = 86400;

/**
 * 30 site days ending today, like the server sends them.
 *
 * @param {(index: number) => Object[]} fill The backups of a day.
 * @return {Object[]} History.
 */
const history = ( fill = () => [] ) =>
	Array.from( { length: 30 }, ( _, index ) => {
		const date = new Date( ( NOW - ( 29 - index ) * DAY ) * 1000 );
		return {
			day: date.toISOString().slice( 0, 10 ),
			backups: fill( index ),
		};
	} );

/** The documented payload (docs/react-admin-api.md), filled out. */
const FULL = {
	site: { host: 'example.com', network: false },
	last: {
		name: 'example-com-20261016-030000-001-abcd1234.wpress',
		time: NOW - 3 * 3600,
		size: 1868000000,
		files: 18412,
		dbSize: null,
		dbTables: 64,
		cloud: [ { id: 'd447b8f2', name: 'Google Drive', status: 'ok' } ],
		local: true,
		outcome: 'ok',
	},
	running: null,
	days: { window: 30, covered: 13, of: 14 },
	history: history( ( index ) => {
		if ( 21 === index ) {
			return [ { size: 0, state: 'failed', manual: false } ];
		}
		return 4 === index
			? []
			: [ { size: 1700000000, state: 'cloud', manual: false } ];
	} ),
	attention: [
		{
			kind: 'reauth',
			destination: 'bbbb2222',
			text: 'Dropbox needs a new sign-in: the last upload was refused.',
			action: 'destinations',
		},
		{
			kind: 'certificate',
			destination: 'cccc3333',
			text: 'FTPS shows a certificate to confirm.',
			action: 'destinations',
		},
	],
	destinations: [
		{
			id: 'd447b8f2',
			name: 'Google Drive',
			type: 'gdrive',
			where: 'LW Migration folder',
			status: 'ok',
			lastUpload: NOW - 3 * 3600 + 720,
			count: 7,
		},
		{
			id: 'bbbb2222',
			name: 'Dropbox',
			type: 'dropbox',
			where: 'Apps/LW Migration',
			status: 'reauth',
			lastUpload: null,
			count: 0,
		},
		{
			id: 'cccc3333',
			name: 'FTPS',
			type: 'ftp',
			where: 'ftp.example.com',
			status: 'pending',
			lastUpload: null,
			count: 0,
		},
	],
	schedule: {
		next: NOW + 16 * 3600,
		enabled: true,
		interval: 86400,
		name: 'Nightly',
		destinations: [ 'Google Drive', 'Dropbox' ],
		keep: '7 days',
		runs: Array.from( { length: 16 }, ( _, index ) => ( {
			time: NOW - ( index + 1 ) * DAY,
			outcome: 7 === index ? 'failed' : 'ok',
			reason: 7 === index ? 'Not enough free space' : null,
		} ) ),
	},
	links: [
		{
			id: 'cccccccccccccccc',
			host: 'dev2.example.com',
			direction: 'pull',
			incoming: false,
			secure: true,
			status: 'ready',
		},
		{
			id: 'dddddddddddddddd',
			host: 'staging.example.com',
			direction: 'push',
			incoming: true,
			secure: false,
			status: 'pending',
		},
	],
	folder: { path: '', protected: true, free: 44200000000 },
};

/** An empty site: what GET /overview answers before anything was set up. */
const EMPTY = {
	site: { host: 'example.com', network: false },
	last: null,
	running: null,
	days: { window: 30, covered: 0, of: 14 },
	history: history(),
	attention: [],
	destinations: [],
	schedule: null,
	links: [],
	folder: { path: '', protected: true, free: null },
};

const render = ( data, props = {} ) =>
	renderToStaticMarkup(
		createElement( OverviewContent, {
			data,
			busy: false,
			job: null,
			onStart: () => {},
			now: NOW,
			...props,
		} )
	);

/**
 * The text a reader sees: tags and entities out.
 *
 * @param {string} html Markup.
 * @return {string} Text.
 */
const textOf = ( html ) =>
	html
		.replace( /<[^>]+>/g, ' ' )
		.replace( /&#x27;/g, "'" )
		.replace( /&amp;/g, '&' )
		.replace( /\s+/g, ' ' );

describe( 'OverviewContent', () => {
	it( 'shows every block of the documented payload, in the mockup order', () => {
		const html = render( FULL );
		const text = textOf( html );
		const order = [
			'Your site is protected: the last backup was made 3 hours ago',
			'Dropbox needs a new sign-in',
			'Back up now',
			'Restore',
			'Move between sites',
			'Last 14 days · 13 backups',
			'Where the backups go',
			'Next backup',
			'Pairing code',
			'Backups folder: the default backups folder',
		].map( ( part ) => text.indexOf( part ) );
		expect( order.every( ( at ) => at >= 0 ) ).toBe( true );
		expect( [ ...order ].sort( ( a, b ) => a - b ) ).toEqual( order );

		// The facts that are known, never the null database size.
		expect( text ).toContain( '1.7 GB' );
		expect( text ).toContain( '18,412' );
		expect( text ).toContain( '64 tables' );
		expect( text ).toContain( 'Backups folder protected' );
		expect( text ).toContain( '13/14' );
		expect( text ).toContain( '41.2 GB free' );
		expect( text ).not.toMatch( /NaN|undefined|null/ );
	} );

	it( 'links each attention item to its section and words the links by side', () => {
		const html = render( FULL );
		const text = textOf( html );
		expect( html ).toContain( 'href="#destinations"' );
		expect( text ).toContain( 'Sign in again' );
		expect( text ).toContain( 'Check the fingerprint' );
		expect( text ).toContain( 'Confirm the fingerprint' );
		expect( text ).toContain( 'May copy that site here · https' );
		expect( text ).toContain(
			"The other site may replace this site's content · http, unencrypted"
		);
		expect( text ).toContain( 'Copy dev2.example.com here in one step.' );
	} );

	it( 'draws the chart with a title on each bar and the failed day', () => {
		const html = render( FULL );
		// Before it is measured the box is drawn at its default 760px width.
		expect( html ).toContain( 'viewBox="0 0 760 190"' );
		expect( html ).toContain( 'width="760" height="190"' );
		const bars = html.match( /lw-overview-chart__bar is-cloud/g ) || [];
		expect( bars ).toHaveLength( 13 ); // 14 days, one of them a failed run.
		expect( html ).toContain( 'the scheduled backup failed' );
		expect( html ).toMatch(
			/<title>Oct 16: 1\.6 GB, uploaded to the cloud too<\/title>/
		);
		expect( textOf( html ) ).toContain( 'today' );
	} );

	it( 'shows the next run, its dots and the failure note', () => {
		const html = render( FULL );
		const text = textOf( html );
		expect( text ).toContain(
			'Nightly → Google Drive and Dropbox · Kept: 7 days'
		);
		expect(
			( html.match( /<li class="is-(ok|failed|partial)"/g ) || [] ).length
		).toBe( 14 );
		expect( text ).toContain(
			'On Oct 8 the backup failed: Not enough free space Every run since then worked.'
		);
	} );

	it( 'tells a switched-off schedule from one with no run planned', () => {
		const off = textOf(
			render( {
				...FULL,
				schedule: { ...FULL.schedule, next: null, enabled: false },
			} )
		);
		expect( off ).toContain( 'Switched off' );
		const unplanned = textOf(
			render( {
				...FULL,
				schedule: { ...FULL.schedule, next: null, enabled: true },
			} )
		);
		expect( unplanned ).not.toContain( 'Switched off' );
		expect( unplanned ).toContain( 'Switched on, but no run is planned' );
	} );

	it( 'gives each block of an empty site its empty state and one action', () => {
		const html = render( EMPTY );
		const text = textOf( html );
		expect( text ).toContain( 'No backup of this site yet' );
		expect( text ).toContain( '0/14' );
		expect( text ).toContain( 'No backup in these days.' );
		expect( text ).toContain( 'No destination yet' );
		expect( text ).toContain( 'No scheduled backup yet.' );
		expect( text ).toContain( 'No connected site yet.' );
		expect( text ).toContain( 'Pair a site' );
		expect( html ).toContain( 'href="#schedules"' );
		expect( html ).toContain( 'href="#destinations"' );
		expect( html ).toContain( 'href="#connections"' );
		expect( html ).not.toContain( 'lw-overview-attention' );
		expect( html ).not.toContain( 'lw-overview-chart__bar' );
		expect( text ).not.toMatch( /0 of 0|0\/0|NaN|undefined|null/ );
	} );

	it( 'says the last scheduled backup failed and still shows the good one', () => {
		const text = textOf(
			render( {
				...FULL,
				last: { ...FULL.last, outcome: 'failed' },
			} )
		);
		expect( text ).toContain( 'The last scheduled backup failed' );
		expect( text ).toContain(
			'The newest good backup was made 3 hours ago.'
		);
	} );

	it( 'hides the facts the server does not know', () => {
		const text = textOf(
			render( {
				...FULL,
				last: {
					...FULL.last,
					size: null,
					files: null,
					dbTables: null,
				},
			} )
		);
		expect( text ).not.toContain( 'Size' );
		expect( text ).not.toContain( 'Files' );
		expect( text ).not.toContain( 'tables' );
	} );

	it( 'says a job runs and links to it', () => {
		const html = render(
			{
				...FULL,
				running: {
					id: '0123456789abcdef',
					type: 'export',
					progress: 0.42,
				},
			},
			{ busy: true }
		);
		expect( textOf( html ) ).toContain(
			'A backup is running: 42% done. Open it'
		);
		expect( html ).toContain( 'href="#export"' );
		expect( html ).toMatch( /Start backup<\/button>/ );
		expect( html ).toMatch( /aria-disabled="true"[^>]*>Start backup/ );
	} );

	it( 'follows App when the job ends: no running line, Start backup enabled', () => {
		const data = {
			...FULL,
			running: { id: '0123456789abcdef', type: 'export', progress: 0.42 },
		};
		const job = {
			id: '0123456789abcdef',
			type: 'export',
			status: 'running',
			steps: [ 'init', 'content', 'database', 'finalize' ],
			stepIndex: 2,
			progress: 50,
		};
		const running = render( data, { busy: true, job } );
		expect( textOf( running ) ).toContain(
			'A backup is running: 62% done. Open it'
		);
		expect( running ).toMatch( /aria-disabled="true"[^>]*>Start backup/ );

		// App says it ended; the overview read before still says running.
		const ended = withDestinations(
			{ data: { items: [] }, error: null, isLoading: false },
			() => render( data, { busy: false, job: null } )
		);
		expect( textOf( ended ) ).not.toContain( 'is running' );
		expect( ended ).toMatch( /<button>Start backup<\/button>/ );

		// ...and once the overview is read again, the new state shows.
		const fresh = render( {
			...data,
			running: null,
			last: { ...FULL.last, time: NOW - 60 },
		} );
		expect( textOf( fresh ) ).toContain(
			'Your site is protected: the last backup was made 1 minute ago'
		);
	} );

	it( 'puts no live region on the running line, so its percent is not read out on every tick', () => {
		const html = render(
			{
				...FULL,
				running: {
					id: '0123456789abcdef',
					type: 'export',
					progress: 0.42,
				},
			},
			{ busy: true }
		);
		expect( html ).toContain( 'lw-overview-status__running' );
		expect( html ).not.toMatch(
			/class="lw-overview-status__running"[^>]*role=/
		);
	} );

	it( 'counts the ring sentence on the covered days', () => {
		const one = textOf(
			render( { ...FULL, days: { window: 30, covered: 1, of: 14 } } )
		);
		expect( one ).toContain( '1 of the last 14 days has a backup.' );
		const many = textOf( render( FULL ) );
		expect( many ).toContain( '13 of the last 14 days have a backup.' );
	} );

	it( 'keeps Start backup disabled while the destinations load', () => {
		const html = withDestinations( LOADING, () => render( FULL ) );
		expect( html ).toContain( 'Loading the destinations…' );
		expect( html ).toMatch( /aria-disabled="true"[^>]*>Start backup/ );

		const loaded = withDestinations(
			{
				data: {
					items: [ { id: 'a', name: 'Google Drive', status: 'ok' } ],
				},
				error: null,
				isLoading: false,
			},
			() => render( FULL )
		);
		expect( loaded ).toMatch( /<button>Start backup<\/button>/ );
		expect( textOf( loaded ) ).toContain( 'Google Drive' );
	} );

	it( 'says when the destinations could not be loaded, and still lets a backup start', () => {
		const html = withDestinations(
			{
				data: null,
				error: { code: 'fetch_error', message: 'Network down' },
				isLoading: false,
			},
			() => render( FULL )
		);
		expect( textOf( html ) ).toContain(
			'The destinations could not be loaded. The backup stays in the backups folder.'
		);
		expect( html ).toMatch( /<button>Start backup<\/button>/ );
	} );

	it( 'asks for a login when the destinations fail on a logout', () => {
		const text = textOf(
			withDestinations(
				{
					data: null,
					error: { status: 401, code: 'rest_not_logged_in' },
					isLoading: false,
				},
				() => render( FULL )
			)
		);
		expect( text ).toContain( 'You were logged out.' );
		expect( text ).not.toContain( 'could not be loaded' );
	} );

	it( 'reads the overview again only when a job has just ended', () => {
		expect( endedNow( true, false ) ).toBe( true );
		expect( endedNow( false, false ) ).toBe( false );
		expect( endedNow( false, true ) ).toBe( false );
		expect( endedNow( true, true ) ).toBe( false );
	} );

	it( 'leaves out the connections on a network', () => {
		const html = render( {
			...FULL,
			site: { host: 'example.com', network: true },
			links: null,
		} );
		const text = textOf( html );
		expect( text ).not.toContain( 'Connections' );
		expect( text ).not.toContain( 'Move between sites' );
		expect( text ).toContain( 'Your network is protected' );
		expect( html ).toContain( 'lw-overview-actions has-two' );
	} );

	it( 'shows a chosen folder and leaves out an unknown free space', () => {
		const text = textOf(
			render( {
				...FULL,
				folder: {
					path: '…/lw-migration-backups-…',
					protected: false,
					free: null,
				},
			} )
		);
		expect( text ).toContain( 'Backups folder: …/lw-migration-backups-…' );
		expect( text ).not.toMatch( /B free/ );
		expect( text ).toContain( 'Backups folder needs a look' );
		expect( text ).not.toContain( 'Your site is protected' );
		expect( text ).toContain(
			'The last backup was made 3 hours ago, but the backups folder needs a look'
		);
	} );
} );

describe( 'OverviewSkeleton', () => {
	it( 'is a status region of skeletons, never a spinner', () => {
		const html = renderToStaticMarkup( createElement( OverviewSkeleton ) );
		expect( html ).toContain( 'role="status"' );
		expect( html ).toContain( 'Loading the overview…' );
		expect( html ).not.toContain( 'components-spinner' );
	} );

	it( 'has the Connections card on a single site and not on a network', () => {
		const sections = ( html ) =>
			html.split( 'class="lw-admin-section"' ).length - 1;
		const single = renderToStaticMarkup(
			createElement( OverviewSkeleton, { network: false } )
		);
		const network = renderToStaticMarkup(
			createElement( OverviewSkeleton, { network: true } )
		);
		// Chart, destinations, next backup (+ connections on a single site).
		expect( sections( single ) ).toBe( 4 );
		expect( sections( network ) ).toBe( 3 );
	} );

	it( 'sizes the chart placeholder like the chart, not at a fixed height', () => {
		const html = renderToStaticMarkup( createElement( OverviewSkeleton ) );
		expect( html ).toContain( 'lw-overview-chart is-skeleton' );
		expect( html ).not.toContain( 'height:190px' );
	} );
} );

describe( 'helpers', () => {
	it( 'ticks the working destinations that need no account', () => {
		expect(
			defaultChoice( [
				{ id: 'a', status: 'ok', connection: null },
				{ id: 'b', status: 'ok', connection: { connected: false } },
				{ id: 'c', status: 'error', connection: null },
				{ id: 'd', status: 'ok', connection: { connected: true } },
			] )
		).toEqual( [ 'a', 'd' ] );
	} );

	it( 'offers moving only with a connection this site uses', () => {
		expect( moveText( [] ).button ).toBe( 'Pair a site' );
		expect(
			moveText( [
				{
					host: 'a.test',
					direction: 'pull',
					incoming: true,
					status: 'ready',
				},
			] ).button
		).toBe( 'Pair a site' );
		expect(
			moveText( [
				{
					host: 'b.test',
					direction: 'both',
					incoming: false,
					status: 'ready',
				},
			] ).text
		).toBe( 'Copy b.test here, or send this site there, in one step.' );
		expect(
			moveText( [
				{
					host: 'c.test',
					direction: 'push',
					incoming: false,
					status: 'ready',
				},
			] ).text
		).toBe( 'Send this site to c.test in one step.' );
	} );

	it( "words a destination's state like the Destinations section", () => {
		const row = {
			id: 'x',
			status: 'pending',
			where: 'host',
			lastUpload: null,
			count: 0,
		};
		expect( destinationPill( row, [] ) ).toEqual( {
			tone: 'idle',
			label: 'Not tested yet',
		} );
		expect(
			destinationPill( row, [ { kind: 'hostKey', destination: 'x' } ] )
				.label
		).toBe( 'Confirm the fingerprint' );
		expect( destinationPill( { ...row, status: 'reauth' }, [] ) ).toEqual( {
			tone: 'warning',
			label: 'Credentials needed',
		} );
		expect( destinationMeta( row ) ).toBe( 'host · no upload yet' );
		expect( destinationMeta( { ...row, count: 2, where: '' } ) ).toBe(
			'no upload yet · 2 backups'
		);
	} );
} );

// Answers of the real endpoint (Rest\OverviewApi::view() on the seeded site
// and on an empty one of tests/WordPress/Rest/OverviewApiTest.php).
describe( 'OverviewContent with the endpoint answers', () => {
	it( 'renders the seeded site', () => {
		const text = textOf(
			render( seeded, { now: seeded.last.time + 3 * 3600 } )
		);
		expect( text ).toContain(
			'Your site is protected: the last backup was made 3 hours ago'
		);
		expect( text ).toContain( 'It was uploaded to Offsite.' );
		expect( text ).toContain( 'Dropbox needs a new sign-in' );
		expect( text ).toContain( 'Nightly → Offsite · Kept: 7 days' );
		expect( text ).toContain( 'dev2.example.test' );
		expect( text ).not.toMatch( /NaN|undefined|null/ );
	} );

	it( 'renders the empty site', () => {
		const text = textOf( render( empty ) );
		expect( text ).toContain( 'No backup of this site yet' );
		expect( text ).not.toMatch( /0 of 0|0\/0|NaN|undefined|null/ );
	} );
} );
