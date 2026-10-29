/**
 * External dependencies
 */
import { afterAll, beforeAll, describe, expect, it, vi } from 'vitest';

/**
 * Internal dependencies
 */
import {
	STALE_AFTER,
	agoText,
	attentionAction,
	failureNote,
	headline,
	headlineKind,
	lastRuns,
	linkRights,
	listText,
	runTitle,
	runsSummary,
} from './status';

// The site's time zone: Budapest (UTC+2 in October).
vi.mock( '@wordpress/date', () => ( {
	getSettings: () => ( {
		timezone: { string: 'Europe/Budapest', offset: 2 },
	} ),
} ) );

const NOW = Date.UTC( 2026, 9, 16, 9, 0 ) / 1000; // 11:00 in Budapest.
const HOUR = 3600;

// "today" in the texts is the real clock's day: hold it at NOW.
beforeAll( () => {
	vi.useFakeTimers( { toFake: [ 'Date' ] } );
	vi.setSystemTime( NOW * 1000 );
} );
afterAll( () => vi.useRealTimers() );

const last = ( patch = {} ) => ( {
	name: 'example-com-20261016-030000-001-abcd1234.wpress',
	time: NOW - 3 * HOUR,
	size: 1868000000,
	files: 18412,
	dbSize: null,
	dbTables: 64,
	cloud: [ { id: 'd447b8f2', name: 'Google Drive', status: 'ok' } ],
	local: true,
	outcome: 'ok',
	...patch,
} );

describe( 'agoText', () => {
	it( 'says minutes, hours, then days', () => {
		expect( agoText( NOW - 120, NOW, 'en-US' ) ).toBe( '2 minutes ago' );
		expect( agoText( NOW - 3 * HOUR, NOW, 'en-US' ) ).toBe( '3 hours ago' );
		expect( agoText( NOW - 30 * HOUR, NOW, 'en-US' ) ).toBe( 'yesterday' );
		expect( agoText( NOW - 5 * 86400, NOW, 'en-US' ) ).toBe( '5 days ago' );
	} );

	it( 'never says "in" for a clock a little ahead', () => {
		expect( agoText( NOW + 30, NOW, 'en-US' ) ).toBe( '1 minute ago' );
	} );
} );

describe( 'headlineKind', () => {
	const DAY = 86400;
	const on = ( interval ) => ( { enabled: true, interval } );
	const off = { enabled: false, interval: DAY };
	const kind = ( age, schedule, protectedFolder = true ) =>
		headlineKind(
			{
				last: last( { time: NOW - age } ),
				schedule,
				folder: { protected: protectedFolder },
			},
			NOW
		);

	it( 'tells none, failed and partial apart first', () => {
		expect( headlineKind( { last: null }, NOW ) ).toBe( 'none' );
		expect(
			headlineKind( { last: last( { outcome: 'failed' } ) }, NOW )
		).toBe( 'failed' );
		expect(
			headlineKind( { last: last( { outcome: 'partial' } ) }, NOW )
		).toBe( 'partial' );
	} );

	it( 'gives a daily schedule its day plus 12 hours', () => {
		expect( kind( 36 * HOUR, on( DAY ) ) ).toBe( 'ok' );
		expect( kind( 36 * HOUR + 1, on( DAY ) ) ).toBe( 'late' );
	} );

	it( 'gives a weekly schedule its week plus 12 hours', () => {
		expect( kind( 7 * DAY + 12 * HOUR, on( 7 * DAY ) ) ).toBe( 'ok' );
		expect( kind( 7 * DAY + 12 * HOUR + 1, on( 7 * DAY ) ) ).toBe( 'late' );
		// Two days would be stale without a schedule, not with a weekly one.
		expect( kind( 3 * DAY, on( 7 * DAY ) ) ).toBe( 'ok' );
	} );

	it( 'gives two days when no schedule is on', () => {
		expect( kind( STALE_AFTER, off ) ).toBe( 'ok' );
		expect( kind( STALE_AFTER + 1, off ) ).toBe( 'stale' );
		expect( kind( STALE_AFTER + 1, null ) ).toBe( 'stale' );
		// An hourly schedule switched off does not shorten it.
		expect( kind( 3 * HOUR, { enabled: false, interval: HOUR } ) ).toBe(
			'ok'
		);
	} );

	it( 'falls back to two days for a schedule without an interval', () => {
		expect( kind( STALE_AFTER + 1, { enabled: true } ) ).toBe( 'late' );
		expect( kind( DAY, { enabled: true } ) ).toBe( 'ok' );
	} );

	it( 'never calls the site protected while the folder needs a look', () => {
		expect( kind( 3 * HOUR, on( DAY ), false ) ).toBe( 'folder' );
		// An old backup is the bigger news; the facts row names the folder.
		expect( kind( 3 * DAY, on( DAY ), false ) ).toBe( 'late' );
	} );
} );

describe( 'headline', () => {
	it( 'says the site is protected and where the backup is', () => {
		const text = headline( { last: last() }, NOW, false, 'en-US' );
		expect( text.title ).toBe(
			'Your site is protected: the last backup was made 3 hours ago'
		);
		expect( text.lead ).toBe(
			'Made today 08:00 AM. It was uploaded to Google Drive. A copy stays in the backups folder.'
		);
	} );

	it( 'says the network on a network', () => {
		expect(
			headline( { last: last() }, NOW, true, 'en-US' ).title
		).toMatch( /^Your network is protected/ );
	} );

	it( 'names the empty site plainly, never "0 of 0"', () => {
		const text = headline( { last: null }, NOW, false, 'en-US' );
		expect( text.kind ).toBe( 'none' );
		expect( text.title ).toBe( 'No backup of this site yet' );
		expect( text.lead ).not.toMatch( /\b0\b|NaN|undefined/ );
	} );

	it( 'says the last scheduled backup failed and still shows the good one', () => {
		const text = headline(
			{ last: last( { outcome: 'failed' } ) },
			NOW,
			false,
			'en-US'
		);
		expect( text.title ).toBe( 'The last scheduled backup failed' );
		expect( text.lead ).toBe(
			'The newest good backup was made 3 hours ago. It was uploaded to Google Drive. A copy stays in the backups folder.'
		);
	} );

	it( 'says which upload failed', () => {
		const text = headline(
			{
				last: last( {
					outcome: 'partial',
					cloud: [
						{ id: 'a', name: 'Google Drive', status: 'ok' },
						{ id: 'b', name: 'Dropbox', status: 'failed' },
						{ id: 'c', name: 'SFTP', status: 'pending' },
					],
				} ),
			},
			NOW,
			false,
			'en-US'
		);
		expect( text.title ).toBe(
			'The last backup was made 3 hours ago, but an upload failed'
		);
		expect( text.lead ).toContain( 'It was uploaded to Google Drive.' );
		expect( text.lead ).toContain( 'It is being uploaded to SFTP.' );
		expect( text.lead ).toContain( 'The upload to Dropbox failed.' );
	} );

	it( 'says a backup kept only in the cloud and one kept only here', () => {
		expect(
			headline( { last: last( { local: false } ) }, NOW, false, 'en-US' )
				.lead
		).toContain( 'Only the uploaded copies are kept.' );
		expect(
			headline( { last: last( { cloud: [] } ) }, NOW, false, 'en-US' )
				.lead
		).toContain( 'It is kept only in the backups folder on this server.' );
	} );

	it( 'does not call an old backup protection', () => {
		const text = headline(
			{ last: last( { time: NOW - 5 * 86400 } ) },
			NOW,
			false,
			'en-US'
		);
		expect( text.title ).toBe(
			'The last backup was made 5 days ago and no schedule is on'
		);
	} );

	it( 'says the scheduled backup is late', () => {
		const text = headline(
			{
				last: last( { time: NOW - 3 * 86400 } ),
				schedule: { enabled: true, interval: 86400 },
				folder: { protected: true },
			},
			NOW,
			false,
			'en-US'
		);
		expect( text.kind ).toBe( 'late' );
		expect( text.title ).toBe(
			'The scheduled backup is late: the last one was made 3 days ago'
		);
	} );

	it( 'names the backups folder instead of protection', () => {
		const text = headline(
			{ last: last(), folder: { protected: false } },
			NOW,
			false,
			'en-US'
		);
		expect( text.kind ).toBe( 'folder' );
		expect( text.title ).toBe(
			'The last backup was made 3 hours ago, but the backups folder needs a look'
		);
		expect( text.lead ).toBe(
			'Made today 08:00 AM. It was uploaded to Google Drive. A copy stays in the backups folder.'
		);
	} );
} );

describe( 'listText', () => {
	it( 'joins names the way the language lists them', () => {
		expect( listText( [ 'A', 'B', 'C' ], 'en-US' ) ).toBe( 'A, B, and C' );
		expect( listText( [ 'A', 'B' ], 'hu-HU' ) ).toBe( 'A és B' );
	} );
} );

describe( 'schedule runs', () => {
	const runs = [
		{ time: NOW - 1 * 86400, outcome: 'ok', reason: null },
		{ time: NOW - 2 * 86400, outcome: 'failed', reason: 'Disk full' },
		{ time: NOW - 3 * 86400, outcome: 'ok', reason: null },
	];

	it( 'shows the newest runs, oldest first', () => {
		const shown = lastRuns( runs, 2 );
		expect( shown.map( ( run ) => run.outcome ) ).toEqual( [
			'failed',
			'ok',
		] );
		expect( lastRuns( null ) ).toEqual( [] );
	} );

	it( 'sums them up for a screen reader', () => {
		expect( runsSummary( lastRuns( runs ) ) ).toBe(
			'The last 3 scheduled runs: 2 worked, 1 did not.'
		);
	} );

	it( 'titles a run with its time in the site zone and its reason', () => {
		expect( runTitle( runs[ 1 ], 'en-US' ) ).toBe(
			'Oct 14, 11:00 AM: failed: Disk full'
		);
	} );

	it( 'notes the newest failure and that the runs since worked', () => {
		expect( failureNote( lastRuns( runs ), 'en-US' ) ).toBe(
			'On Oct 14 the backup failed: Disk full Every run since then worked.'
		);
		expect(
			failureNote(
				[ { time: NOW, outcome: 'partial', reason: null } ],
				'en-US'
			)
		).toBe( 'On Oct 16 an upload failed.' );
		expect(
			failureNote( [ { time: NOW, outcome: 'ok', reason: null } ] )
		).toBe( '' );
	} );
} );

describe( 'linkRights', () => {
	it( 'words the rights from the side that holds them', () => {
		expect( linkRights( { direction: 'pull', incoming: false } ) ).toBe(
			'May copy that site here'
		);
		expect( linkRights( { direction: 'pull', incoming: true } ) ).toBe(
			'The other site may copy this site'
		);
		expect( linkRights( { direction: 'both', incoming: false } ) ).toBe(
			'May send this site there and copy that site here'
		);
		expect( linkRights( { direction: 'push', incoming: true } ) ).toBe(
			"The other site may replace this site's content"
		);
	} );
} );

describe( 'attentionAction', () => {
	it( 'names the place each kind is fixed', () => {
		expect( attentionAction( 'reauth' ) ).toBe( 'Sign in again' );
		expect( attentionAction( 'certificate' ) ).toBe(
			'Check the fingerprint'
		);
		expect( attentionAction( 'error' ) ).toBe( 'Open Destinations' );
		expect( attentionAction( 'failedRun' ) ).toBe( 'Open Schedules' );
	} );
} );
