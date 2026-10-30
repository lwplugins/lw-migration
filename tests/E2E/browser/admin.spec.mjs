import { readdirSync, realpathSync, statSync } from 'node:fs';
import { basename, dirname, join } from 'node:path';
import { expect, test } from '@playwright/test';
import {
	ROUTE,
	SECOND,
	SITE,
	URL_A,
	URL_B,
	addSlowFixture,
	archives,
	backupAction,
	backupsDir,
	isCancel,
	lockState,
	login,
	pendingExport,
	removeSlowFixture,
	screen,
	setTickSeconds,
	sha1,
	spinnersSeen,
	typeTarget,
	watchSpinners,
	wp,
} from './helpers.mjs';

test.describe.configure( { mode: 'serial' } );

const pause = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );

/** What the serial tests hand on: the backup made and downloaded in the browser. */
const made = { name: '', file: '' };

/**
 * A snackbar notice (its text is also spoken through a hidden live region).
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string}                          text Text in it.
 * @return {import('@playwright/test').Locator} Snackbar.
 */
const snackbar = ( page, text ) =>
	page.locator( '.components-snackbar' ).filter( { hasText: text } );

/**
 * Starts a backup from the Backup section without the media library.
 *
 * @param {import('@playwright/test').Page} page        Page.
 * @param {string}                          compression '' (none) or a method name.
 */
async function startBackup( page, compression = '' ) {
	await page.goto( screen( 'export' ) );
	// The switch's name carries the size beside the title.
	await page.getByRole( 'checkbox', { name: /^Media library/ } ).uncheck();
	if ( compression ) {
		await page.getByRole( 'radio', { name: compression } ).click();
	}
	await page.getByRole( 'button', { name: 'Start backup' } ).click();
	await expect( page.locator( '.lw-track' ) ).toBeVisible();
}

/**
 * Opens the "Restore or move" summary for a backup of the Backups list.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string}                          name Backup file name.
 */
async function restoreFromBackup( page, name ) {
	await page.goto( screen( 'backups' ) );
	await backupAction( page, name, 'Restore' );
	await expect( page ).toHaveURL( /#import$/ );
	await page.getByRole( 'button', { name: 'Continue to the check' } ).click();
	await expect( page.getByText( 'Check before restoring' ) ).toBeVisible( {
		timeout: 120000,
	} );
}

test.beforeEach( async ( { page } ) => {
	await watchSpinners( page );
	await login( page );
} );

test.afterEach( async ( { page } ) => {
	expect( await spinnersSeen( page ) ).toBe( 0 );
} );

test( 'the screen loads with skeletons, never a spinner', async ( {
	page,
} ) => {
	await page.route( ROUTE.active, async ( route ) => {
		await pause( 1500 );
		await route.continue();
	} );
	await page.goto( screen( 'export' ) );
	await expect( page.locator( '.lw-skel-region' ).first() ).toBeVisible();
	await expect(
		page.getByRole( 'heading', { name: 'Back up this site', level: 2 } )
	).toBeVisible();
	await expect( page.locator( '.lw-skel-region' ) ).toHaveCount( 0 );
	await expect( page.locator( '.wrap #lw-migration-root' ) ).toHaveCount( 0 );
	await expect(
		page.getByRole( 'navigation', { name: 'LW Migration sections' } )
	).toBeVisible();
} );

test( 'retention settings are saved', async ( { page } ) => {
	await page.goto( screen( 'settings' ) );
	await page.getByLabel( 'Number of backups' ).fill( '20' );
	await page.getByRole( 'button', { name: /Save changes/ } ).click();
	await expect( snackbar( page, 'Settings saved.' ) ).toBeVisible();
	expect(
		wp(
			SITE,
			'eval',
			'echo get_option( "lw_migration_retention" )["count"];'
		)
	).toBe( '20' );
} );

test( 'a backup runs in the browser and downloads byte for byte', async ( {
	page,
}, testInfo ) => {
	await startBackup( page );
	await expect( page.getByText( 'The backup is ready.' ) ).toBeVisible( {
		timeout: 200000,
	} );

	const downloading = page.waitForEvent( 'download' );
	await page.getByRole( 'button', { name: 'Download', exact: true } ).click();
	const download = await downloading;
	const name = download.suggestedFilename();
	expect( name ).toMatch( /\.wpress$/ );
	expect( archives( SITE ) ).toContain( name );

	const file = testInfo.outputPath( name );
	await download.saveAs( file );
	const stored = join( backupsDir( SITE ), name );
	expect( statSync( file ).size ).toBe( statSync( stored ).size );
	expect( sha1( file ) ).toBe( sha1( stored ) );
	expect( lockState() ).toBe( 'free' );
	made.name = name;
	made.file = file;
} );

test( 'the downloaded backup moves to the second site through an upload', async ( {
	browser,
} ) => {
	const context = await browser.newContext( { baseURL: URL_B } );
	const page = await context.newPage();
	await watchSpinners( page );
	await login( page, URL_B );
	const title = wp( SITE, 'option', 'get', 'blogname' );
	const before = archives( SECOND );
	expect( wp( SECOND, 'option', 'get', 'blogname' ) ).not.toBe( title );

	// Slow slices, so the upload phase can be seen (ruling P6).
	await page.route( ROUTE.upload, async ( route ) => {
		await pause( 1500 );
		await route.continue();
	} );
	await page.goto( screen( 'import' ) );
	await page.getByRole( 'button', { name: /^From a file/ } ).click();
	await page.locator( 'input[type="file"]' ).setInputFiles( made.file );
	await page.getByRole( 'button', { name: 'Continue to the check' } ).click();
	// The visible status line (the hidden live region repeats it once).
	await expect( page.locator( '.lw-job__message' ) ).toHaveText(
		'Uploading the archive…'
	);
	await expect(
		page.locator(
			'.lw-track__phases [aria-current="step"] .lw-track__name'
		)
	).toHaveText( 'Receiving' );

	await expect( page.getByText( 'Check before restoring' ) ).toBeVisible( {
		timeout: 150000,
	} );
	const address = page.locator( 'tr[data-fact="address"] td' );
	await expect( address.first() ).toHaveText( URL_A );
	await expect( address.nth( 2 ) ).toHaveText( URL_B );
	// Nothing is written before "Restore now".
	expect( wp( SECOND, 'option', 'get', 'blogname' ) ).not.toBe( title );
	await page.getByLabel( 'Safety backup first' ).uncheck();
	await typeTarget( page );
	await page.getByRole( 'button', { name: 'Restore now' } ).click();
	await expect( page.getByText( 'The site was restored.' ) ).toBeVisible( {
		timeout: 200000,
	} );
	await expect(
		page.getByText( /The database from before the restore is saved/ )
	).toHaveCount( 0 );

	expect( wp( SECOND, 'option', 'get', 'blogname' ) ).toBe( title );
	expect( wp( SECOND, 'option', 'get', 'home' ) ).toBe( URL_B );
	// The switch reached the server: no safety backup was made.
	expect( archives( SECOND ) ).toEqual( before );
	await page.reload();
	await expect(
		page.getByRole( 'heading', {
			name: 'Restore or move a site',
			level: 2,
		} )
	).toBeVisible();
	expect( lockState( SECOND ) ).toBe( 'free' );
	expect( await spinnersSeen( page ) ).toBe( 0 );
	await context.close();
} );

test( 'a restore from an existing backup makes a safety backup first', async ( {
	page,
} ) => {
	const title = wp( SITE, 'option', 'get', 'blogname' );
	wp( SITE, 'option', 'update', 'blogname', 'Changed after the backup' );
	const before = archives( SITE );

	await restoreFromBackup( page, made.name );
	await expect(
		page.locator( 'tr[data-fact="address"] td' ).first()
	).toHaveText( URL_A );
	await expect( page.getByLabel( 'Safety backup first' ) ).toBeChecked();
	expect( wp( SITE, 'option', 'get', 'blogname' ) ).toBe(
		'Changed after the backup'
	);
	await typeTarget( page );
	await page.getByRole( 'button', { name: 'Restore now' } ).click();
	await expect( page.getByText( 'The site was restored.' ) ).toBeVisible( {
		timeout: 200000,
	} );

	const saved = page.getByText(
		/The database from before the restore is saved as/
	);
	await expect( saved ).toBeVisible();
	const safety = /saved as (\S+\.wpress)/.exec(
		await saved.textContent()
	)[ 1 ];
	expect( archives( SITE ) ).toEqual( [ ...before, safety ].sort() );
	expect( wp( SITE, 'option', 'get', 'blogname' ) ).toBe( title );
	expect( wp( SITE, 'option', 'get', 'home' ) ).toBe( URL_A );

	await page.goto( screen( 'backups' ) );
	await expect(
		page.locator( '.lw-bk-row' ).filter( { hasText: safety } )
	).toContainText( 'Before a restore' );
	expect( lockState() ).toBe( 'free' );
} );

test( 'a declined restore changes nothing', async ( { page } ) => {
	const title = wp( SITE, 'option', 'get', 'blogname' );
	const before = archives( SITE );

	await restoreFromBackup( page, made.name );
	await page.getByRole( 'button', { name: 'Back', exact: true } ).click();
	// Back at the source, with the same backup chosen.
	await expect(
		page.getByRole( 'heading', { name: 'Where to restore from?' } )
	).toBeVisible();
	await expect( page.locator( '.lw-pick__row.is-chosen' ) ).toContainText(
		made.name
	);

	expect( wp( SITE, 'option', 'get', 'blogname' ) ).toBe( title );
	expect( archives( SITE ) ).toEqual( before );
	expect( lockState() ).toBe( 'free' );
} );

test( 'backups can be labelled, browsed and deleted, and focus comes back', async ( {
	page,
} ) => {
	await page.goto( screen( 'backups' ) );
	const rows = page.locator( '.lw-bk-row' ).filter( { hasText: '.wpress' } );
	const count = archives( SITE ).length;
	await expect( rows ).toHaveCount( count );
	const row = rows.filter( { hasText: made.name } );
	const more = row.getByRole( 'button', { name: `More for ${ made.name }` } );

	await more.click();
	await page.getByRole( 'menuitem', { name: 'Label' } ).click();
	await page.getByLabel( /Label of/ ).fill( 'Before the redesign' );
	await page.getByRole( 'button', { name: 'Save' } ).click();
	await expect( row ).toContainText( 'Before the redesign' );
	await expect( page.getByRole( 'dialog' ) ).toHaveCount( 0 );
	await expect( more ).toBeFocused();

	await more.click();
	await page.getByRole( 'menuitem', { name: 'Files' } ).click();
	await expect(
		page.getByRole( 'dialog' ).getByText( 'package.json', { exact: true } )
	).toBeVisible();
	await page.keyboard.press( 'Escape' );
	await expect( page.getByRole( 'dialog' ) ).toHaveCount( 0 );
	await expect( more ).toBeFocused();

	await more.click();
	await page.getByRole( 'menuitem', { name: 'Delete' } ).click();
	await page
		.getByRole( 'dialog' )
		.getByRole( 'button', { name: 'Delete' } )
		.click();
	await expect( rows ).toHaveCount( count - 1 );
	await expect( page.getByRole( 'dialog' ) ).toHaveCount( 0 );
	await expect(
		page.locator( '.lw-bk-search input[type="search"]' )
	).toBeFocused();
	expect( archives( SITE ) ).not.toContain( made.name );
} );

test( 'an interrupted job is offered again, never resumed by itself, and finishes', async ( {
	page,
} ) => {
	pendingExport();
	let ticks = 0;
	page.on( 'request', ( request ) => {
		ticks += ROUTE.tick.test( request.url() ) ? 1 : 0;
	} );
	await page.goto( screen( 'backups' ) );
	await page.getByRole( 'link', { name: 'Open it' } ).click();
	// Saved moments ago: it may be driven from another window, so the page
	// says so first, and once the job stays quiet, that it stopped.
	await expect(
		page.getByText( 'it may still be running in another window or tab' )
	).toBeVisible();
	await expect(
		page.getByText( 'This job stopped when its page was closed' )
	).toBeVisible( { timeout: 30000 } );
	await pause( 2000 );
	expect( ticks ).toBe( 0 );
	expect( lockState() ).toBe( 'held' );
	await page.getByRole( 'button', { name: 'Continue', exact: true } ).click();
	await expect( page.getByText( 'The backup is ready.' ) ).toBeVisible( {
		timeout: 200000,
	} );
	expect( ticks ).toBeGreaterThan( 0 );
	expect( lockState() ).toBe( 'free' );
} );

test( 'a busy job is ticked again without an error', async ( { page } ) => {
	let ticks = 0;
	await page.route( ROUTE.tick, async ( route ) => {
		ticks++;
		if ( 1 === ticks ) {
			await route.fulfill( {
				status: 409,
				json: {
					code: 'lw_migration_job_busy',
					message: 'The job is busy.',
					data: { status: 409 },
				},
			} );
			return;
		}
		await route.continue();
	} );
	await startBackup( page );
	await expect( page.getByText( 'The backup is ready.' ) ).toBeVisible( {
		timeout: 200000,
	} );
	expect( ticks ).toBeGreaterThan( 1 );
	await expect(
		page.locator( '.lw-job .components-notice.is-error' )
	).toHaveCount( 0 );
	await expect( page.getByText( 'The job is busy.' ) ).toHaveCount( 0 );
	expect( lockState() ).toBe( 'free' );
} );

test( 'a logout during a job asks to log in again and the job can be cancelled after', async ( {
	page,
} ) => {
	await page.route( ROUTE.tick, ( route ) =>
		route.fulfill( {
			status: 401,
			json: {
				code: 'rest_not_logged_in',
				message: 'You are not currently logged in.',
				data: { status: 401 },
			},
		} )
	);
	await startBackup( page );
	await expect(
		page
			.locator( '.lw-job' )
			.getByText(
				'You were logged out. Log in again, then continue the job on this page.'
			)
	).toBeVisible();
	const href = await page
		.getByRole( 'link', { name: 'Log in again' } )
		.getAttribute( 'href' );
	const back = new URL( href, URL_A ).searchParams.get( 'redirect_to' );
	expect( new URL( href, URL_A ).pathname ).toBe( '/wp-login.php' );
	expect( new URL( back, URL_A ).searchParams.get( 'page' ) ).toBe(
		'lw-migration'
	);
	expect( new URL( back, URL_A ).searchParams.get( 'tab' ) ).toBe( 'export' );
	await expect(
		page.getByRole( 'button', { name: 'Try again' } )
	).toHaveCount( 0 );
	expect( lockState() ).toBe( 'held' );

	// Back on the page later: the job waits for a decision.
	await page.unroute( ROUTE.tick );
	await page.reload();
	await expect( page.locator( '.lw-job__stalled' ) ).toBeVisible();
	await page.getByRole( 'button', { name: 'Cancel', exact: true } ).click();
	await page.getByRole( 'button', { name: 'Cancel the job' } ).click();
	await expect(
		page.getByRole( 'heading', { name: 'Backup cancelled' } )
	).toBeVisible();
	expect( lockState() ).toBe( 'free' );
} );

test( 'a start refused with a logout shows the login link', async ( {
	page,
} ) => {
	await page.route(
		/rest_route=(?:%2F|\/)lw-migration(?:%2F|\/)v1(?:%2F|\/)jobs(?:&|$)/,
		( route ) =>
			'POST' === route.request().method()
				? route.fulfill( {
						status: 401,
						json: {
							code: 'rest_not_logged_in',
							message: 'You are not currently logged in.',
							data: { status: 401 },
						},
					} )
				: route.continue()
	);
	await page.goto( screen( 'export' ) );
	await page.getByRole( 'button', { name: 'Start backup' } ).click();
	const notice = page.locator( '.lw-export-notice' );
	await expect( notice ).toContainText(
		'You were logged out. Log in again, then try once more.'
	);
	const href = await notice
		.getByRole( 'link', { name: 'Log in again' } )
		.getAttribute( 'href' );
	const back = new URL( href, URL_A ).searchParams.get( 'redirect_to' );
	expect( new URL( href, URL_A ).pathname ).toBe( '/wp-login.php' );
	expect( new URL( back, URL_A ).searchParams.get( 'tab' ) ).toBe( 'export' );
	await expect(
		page.getByText( 'You are not currently logged in.' )
	).toHaveCount( 0 );
	await expect( page.locator( '.lw-track' ) ).toHaveCount( 0 );
	expect( lockState() ).toBe( 'free' );
} );

test( 'an upload refused by the web server with a non-JSON 413 goes on in smaller slices', async ( {
	page,
} ) => {
	const slices = [];
	await page.route( ROUTE.upload, async ( route ) => {
		const url = new URL( route.request().url() );
		slices.push( {
			offset: Number( url.searchParams.get( 'offset' ) ),
			bytes: route.request().postDataBuffer()?.length || 0,
		} );
		if ( 1 === slices.length ) {
			await route.fulfill( {
				status: 413,
				contentType: 'text/html',
				body: '<html><head><title>413 Request Entity Too Large</title></head><body><h1>413</h1></body></html>',
			} );
			return;
		}
		await route.continue();
	} );
	await page.goto( screen( 'import' ) );
	await page.getByRole( 'button', { name: /^From a file/ } ).click();
	await page.locator( 'input[type="file"]' ).setInputFiles( made.file );
	await page.getByRole( 'button', { name: 'Continue to the check' } ).click();
	await expect( page.getByText( 'Check before restoring' ) ).toBeVisible( {
		timeout: 150000,
	} );
	await expect(
		page.locator( '.lw-job .components-notice.is-error' )
	).toHaveCount( 0 );

	const total = statSync( made.file ).size;
	expect( slices.length ).toBeGreaterThan( 1 );
	expect( slices[ 1 ].offset ).toBe( 0 );
	expect( slices[ 1 ].bytes ).toBeLessThan( slices[ 0 ].bytes );
	expect( slices[ 1 ].bytes ).toBeLessThanOrEqual(
		Math.max( 262144, Math.floor( slices[ 0 ].bytes / 2 ) )
	);
	expect(
		slices.slice( 1 ).reduce( ( sum, slice ) => sum + slice.bytes, 0 )
	).toBe( total );

	await page.getByRole( 'button', { name: 'Back', exact: true } ).click();
	await expect(
		page.getByRole( 'heading', { name: 'Where to restore from?' } )
	).toBeVisible();
	expect( lockState() ).toBe( 'free' );
} );

test.describe( 'cancelling a running backup', () => {
	test.beforeAll( () => {
		addSlowFixture( 200 );
	} );

	test.afterAll( () => {
		removeSlowFixture();
		setTickSeconds( 0 );
	} );

	/**
	 * Records tick and cancel requests with their start and end times.
	 *
	 * @param {import('@playwright/test').Page} page Page.
	 * @return {Object[]} Growing list of `{ kind, start, end, status }`.
	 */
	function recordRequests( page ) {
		const log = [];
		const entries = new Map();
		page.on( 'request', ( request ) => {
			let kind = '';
			if ( ROUTE.tick.test( request.url() ) ) {
				kind = 'tick';
			} else if ( isCancel( request ) ) {
				kind = 'cancel';
			}
			if ( kind ) {
				const entry = { kind, start: Date.now(), end: 0, status: 0 };
				entries.set( request, entry );
				log.push( entry );
			}
		} );
		const finish = async ( request ) => {
			const entry = entries.get( request );
			if ( entry ) {
				entry.end = Date.now();
				entry.status = ( await request.response() )?.status() || 0;
			}
		};
		page.on( 'requestfinished', finish );
		page.on( 'requestfailed', finish );
		return log;
	}

	test( 'a cancel waits for a long tick and then stops the backup', async ( {
		page,
	} ) => {
		// Longer than the busy retries of a cancel last (5 × 1.5 s).
		setTickSeconds( 10 );
		const before = archives( SITE );
		const log = recordRequests( page );
		// Listening before the start: the first tick goes out at once.
		const firstTick = page.waitForRequest( ROUTE.tick );
		await startBackup( page, 'bzip2' );
		await firstTick;
		// Cancel while a tick is still running on the server.
		await expect
			.poll( () =>
				log.some( ( entry ) => 'tick' === entry.kind && ! entry.end )
			)
			.toBe( true );
		await page
			.getByRole( 'button', { name: 'Cancel', exact: true } )
			.click();
		await page.getByRole( 'button', { name: 'Cancel the job' } ).click();
		// Until the tick lets go, the cancel says it is on its way.
		const pending = page.getByRole( 'button', { name: 'Cancelling…' } );
		await expect( pending ).toBeVisible();
		await expect( pending ).toBeDisabled();
		await expect(
			page.getByRole( 'heading', { name: 'Backup cancelled' } )
		).toBeVisible( { timeout: 60000 } );

		const cancels = log.filter( ( entry ) => 'cancel' === entry.kind );
		const ticks = log.filter( ( entry ) => 'tick' === entry.kind );
		expect( cancels ).toHaveLength( 1 );
		expect( cancels[ 0 ].status ).toBe( 200 );
		const lastTick = ticks[ ticks.length - 1 ];
		expect( lastTick.end - lastTick.start ).toBeGreaterThan( 7500 );
		expect( cancels[ 0 ].start ).toBeGreaterThanOrEqual( lastTick.end );
		expect(
			ticks.every( ( tick ) => tick.start < cancels[ 0 ].start )
		).toBe( true );
		expect( archives( SITE ) ).toEqual( before );
		expect( lockState() ).toBe( 'free' );
	} );

	test( 'a cancel answered as deferred drives the job on to its end', async ( {
		page,
	} ) => {
		setTickSeconds( 2 );
		const log = recordRequests( page );
		await page.route( ROUTE.job, async ( route ) => {
			if ( ! isCancel( route.request() ) ) {
				await route.fallback();
				return;
			}
			const headers = { ...route.request().headers() };
			delete headers[ 'x-http-method-override' ];
			const view = await (
				await route.fetch( { method: 'GET', headers } )
			).json();
			await route.fulfill( {
				json: { job: view, cancelDeferred: true },
			} );
		} );
		const firstTick = page.waitForResponse( ROUTE.tick );
		await startBackup( page, 'bzip2' );
		await firstTick;
		await page
			.getByRole( 'button', { name: 'Cancel', exact: true } )
			.click();
		await page.getByRole( 'button', { name: 'Cancel the job' } ).click();
		await expect(
			snackbar( page, 'cannot be cancelled any more' )
		).toBeVisible();
		await expect( page.getByText( 'The backup is ready.' ) ).toBeVisible( {
			timeout: 200000,
		} );

		const cancel = log.find( ( entry ) => 'cancel' === entry.kind );
		expect(
			log.filter(
				( entry ) => 'tick' === entry.kind && entry.start > cancel.end
			).length
		).toBeGreaterThan( 0 );
		expect( lockState() ).toBe( 'free' );
	} );

	test( 'a failed cancel shows its error and the backup goes on', async ( {
		page,
	} ) => {
		setTickSeconds( 2 );
		const log = recordRequests( page );
		await page.route( ROUTE.job, async ( route ) => {
			if ( ! isCancel( route.request() ) ) {
				await route.fallback();
				return;
			}
			await route.fulfill( {
				status: 500,
				json: {
					code: 'lw_migration_storage',
					message: 'The backups folder cannot be written.',
					data: { status: 500 },
				},
			} );
		} );
		const firstTick = page.waitForResponse( ROUTE.tick );
		await startBackup( page, 'bzip2' );
		await firstTick;
		await page
			.getByRole( 'button', { name: 'Cancel', exact: true } )
			.click();
		await page.getByRole( 'button', { name: 'Cancel the job' } ).click();
		await expect(
			snackbar( page, 'The backups folder cannot be written.' )
		).toBeVisible();
		await expect( page.getByText( 'The backup is ready.' ) ).toBeVisible( {
			timeout: 200000,
		} );

		const cancels = log.filter( ( entry ) => 'cancel' === entry.kind );
		expect( cancels ).toHaveLength( 1 );
		expect(
			log.filter(
				( entry ) =>
					'tick' === entry.kind && entry.start > cancels[ 0 ].end
			).length
		).toBeGreaterThan( 0 );
		expect( lockState() ).toBe( 'free' );
	} );
} );

/** WP-Cron events of the schedules on site A. */
const scheduleEvents = () =>
	wp(
		SITE,
		'eval',
		'$n = 0; foreach ( (array) _get_cron_array() as $t ) { $n += count( $t["lw_migration_schedule"] ?? [] ); } echo $n;'
	);

test.describe( 'schedules', () => {
	// Each test builds on the one before (the schedule "UI weekly").
	test.describe.configure( { mode: 'serial' } );

	test( 'a schedule is created, run now and logged', async ( { page } ) => {
		await page.goto( screen( 'schedules' ) );
		await page.getByRole( 'button', { name: 'New schedule' } ).click();
		const dialog = page.getByRole( 'dialog', { name: 'New schedule' } );
		await dialog.getByLabel( 'Name', { exact: true } ).fill( 'UI weekly' );
		await dialog.getByRole( 'button', { name: 'Every week' } ).click();
		await dialog.getByLabel( 'Day of the week' ).selectOption( '7' );
		await dialog.getByLabel( 'Hour', { exact: true } ).selectOption( '22' );
		for ( const label of [ 'Media', 'Plugins', 'Themes' ] ) {
			await dialog
				.getByRole( 'checkbox', { name: label, exact: true } )
				.uncheck();
		}
		await dialog.getByRole( 'button', { name: 'Save schedule' } ).click();
		await expect( snackbar( page, 'Schedule saved.' ) ).toBeVisible();

		const card = page
			.locator( '.lw-sched' )
			.filter( { hasText: 'UI weekly' } );
		await expect(
			// The page is en-US: the time is written the way the language does.
			card.getByText( /^Weekly on Sunday at 10:00\sPM$/ )
		).toBeVisible();
		await expect( card.locator( '.lw-fact__next' ) ).toBeVisible();
		await expect( card.locator( '.lw-fact__after li' ) ).toHaveCount( 3 );
		expect( scheduleEvents() ).toBe( '1' );

		// The editor shows what was saved, and an unrelated edit keeps it.
		await card.getByRole( 'button', { name: 'Edit' } ).click();
		const editor = page.getByRole( 'dialog', { name: 'Edit schedule' } );
		await expect(
			editor.getByRole( 'button', { name: 'Every week' } )
		).toHaveAttribute( 'aria-pressed', 'true' );
		await editor.getByRole( 'button', { name: 'Cancel' } ).click();
		await expect( editor ).toHaveCount( 0 );

		await card.getByRole( 'button', { name: 'Run now' } ).click();
		await expect( page ).toHaveURL( /#export$/ );
		await expect( page.getByText( 'The backup is ready.' ) ).toBeVisible( {
			timeout: 200000,
		} );
		expect(
			archives( SITE ).some( ( name ) =>
				/-[a-f0-9]{8}\.wpress$/.test( name )
			)
		).toBe( true );

		await page.goto( screen( 'schedules' ) );
		await card.getByRole( 'button', { name: 'Run log' } ).click();
		const log = page.getByRole( 'dialog', { name: /Run log/ } );
		await expect( log.getByText( 'By hand' ) ).toBeVisible();
		await expect( log.getByText( 'Ready', { exact: true } ) ).toBeVisible();
		await expect( log.getByText( 'Sent', { exact: true } ) ).toHaveCount(
			0
		);
	} );

	test( 'switching a schedule off drops its WP-Cron event, on plans it again', async ( {
		page,
	} ) => {
		await page.goto( screen( 'schedules' ) );
		const card = page
			.locator( '.lw-sched' )
			.filter( { hasText: 'UI weekly' } );
		const toggle = card.getByRole( 'checkbox' );
		await toggle.click();
		await expect(
			card.getByText( 'It runs only when you start it.' )
		).toBeVisible();
		await expect( toggle ).not.toBeChecked();
		expect( scheduleEvents() ).toBe( '0' );
		await toggle.click();
		await expect( card.locator( '.lw-fact__next' ) ).toBeVisible();
		await expect( toggle ).toBeChecked();
		expect( scheduleEvents() ).toBe( '1' );
	} );

	test( 'late WP-Cron shows what the server should run', async ( {
		page,
	} ) => {
		// R5: WP-Cron must not really start the overdue run in the background,
		// so the cron lock is held (spawn_cron() refuses while it is fresh).
		wp(
			SITE,
			'eval',
			'set_transient( "doing_cron", sprintf( "%.22F", microtime( true ) + 300 ) );'
		);
		try {
			wp(
				SITE,
				'eval',
				'foreach ( (array) _get_cron_array() as $t => $hooks ) { foreach ( $hooks["lw_migration_schedule"] ?? [] as $e ) { wp_unschedule_event( $t, "lw_migration_schedule", $e["args"] ); wp_schedule_single_event( time() - 3600, "lw_migration_schedule", $e["args"] ); } }'
			);
			await page.goto( screen( 'schedules' ) );
			await expect(
				page
					.getByRole( 'main' )
					.getByText( 'Scheduled backups are late' )
			).toBeVisible();
			await expect(
				page
					.getByRole( 'main' )
					.getByText( 'wp lw-migration schedule run --due' )
			).toBeVisible();
		} finally {
			wp(
				SITE,
				'eval',
				'wp_unschedule_hook( "lw_migration_schedule" ); delete_transient( "doing_cron" );'
			);
		}
		expect( lockState() ).toBe( 'free' );
	} );

	test( 'the default notification address is saved in Settings', async ( {
		page,
	} ) => {
		await page.goto( screen( 'settings' ) );
		const row = page
			.locator( '.lw-admin-row' )
			.filter( { hasText: 'Default address' } );
		await page.getByLabel( 'Default address' ).fill( 'nope' );
		await page.getByRole( 'button', { name: /Save changes/ } ).click();
		await expect(
			row.getByText( 'Enter up to 5 valid e-mail addresses' )
		).toBeVisible();
		await page
			.getByLabel( 'Default address' )
			.fill( 'ops@site-a.test, it@site-a.test' );
		await page.getByRole( 'button', { name: /Save changes/ } ).click();
		await expect( snackbar( page, 'Settings saved.' ) ).toBeVisible();
		expect(
			wp(
				SITE,
				'eval',
				'echo get_option( "lw_migration_notifications" )["email"];'
			)
		).toBe( 'ops@site-a.test, it@site-a.test' );
		wp( SITE, 'option', 'delete', 'lw_migration_notifications' );
	} );
} );

test.describe( 'backups folder', () => {
	const DEFAULT_TEXT =
		'Default: wp-content/lw-migration-…/backups (a protected folder whose name ends in random characters)';
	const outside = () => realpathSync( process.env.LWM_UI_OUTSIDE );
	// The plugin's own storage folder (random name): never in a page or a REST body.
	const storageName = () => basename( dirname( backupsDir( SITE ) ) );
	// The setting row ({ path, moving, marker }; no row = the default folder).
	const stored = () =>
		JSON.parse(
			wp(
				SITE,
				'eval',
				'echo get_option( "lw_migration_backup_dir" ) ?: "{\\"path\\":\\"\\"}";'
			)
		);
	const folderSection = ( page ) =>
		page.locator( '.lw-admin-section' ).filter( {
			has: page.getByRole( 'heading', { name: 'Backups folder' } ),
		} );
	const field = ( page ) => page.getByLabel( 'New folder' );

	/**
	 * Every REST body the page reads, to check what it never contains.
	 *
	 * @param {import('@playwright/test').Page} page Page.
	 * @return {string[]} Bodies (filled while the page runs).
	 */
	const restBodies = ( page ) => {
		const bodies = [];
		page.on( 'response', async ( response ) => {
			if ( /rest_route=|wp-json/.test( response.url() ) ) {
				bodies.push( await response.text().catch( () => '' ) );
			}
		} );
		return bodies;
	};

	/**
	 * Types a folder, confirms, and waits for the move to end.
	 *
	 * @param {import('@playwright/test').Page} page   Page.
	 * @param {string}                          folder Folder ('' = back to the default one).
	 */
	async function move( page, folder ) {
		if ( '' === folder ) {
			await page
				.getByRole( 'button', { name: 'Back to the default folder' } )
				.click();
		} else {
			await field( page ).fill( folder );
			await page
				.getByRole( 'button', { name: 'Move the backups here' } )
				.click();
		}
		const dialog = page.getByRole( 'dialog' );
		await expect( dialog ).toContainText(
			'' === folder
				? 'Move every backup to the default folder?'
				: 'Move every backup to this folder?'
		);
		await dialog
			.getByRole( 'button', { name: 'Move', exact: true } )
			.click();
		await expect(
			page.getByRole( 'heading', { name: 'Backups moved', level: 2 } )
		).toBeVisible( { timeout: 120000 } );
		await expect( page ).toHaveURL( /#settings$/ );
		expect( lockState() ).toBe( 'free' );
	}

	test( 'Settings shows the default folder after a skeleton, never its path', async ( {
		page,
	} ) => {
		const bodies = restBodies( page );
		await page.route( /backup-dir/, async ( route ) => {
			await pause( 1500 );
			await route.continue();
		} );
		await page.goto( screen( 'settings' ) );
		await expect(
			folderSection( page ).locator( '.lw-skel-region' )
		).toBeVisible();
		await expect( folderSection( page ) ).toContainText( DEFAULT_TEXT );
		await expect( page.locator( '.lw-skel-region' ) ).toHaveCount( 0 );
		expect( await page.content() ).not.toContain( storageName() );
		expect( bodies.length ).toBeGreaterThan( 0 );
		for ( const body of bodies ) {
			expect( body ).not.toContain( storageName() );
		}
	} );

	test( 'a site folder is refused under the field; wp-content gets a random folder that is never shown', async ( {
		page,
	} ) => {
		const bodies = restBodies( page );
		const content = join( SITE, 'wp-content' );
		await page.goto( screen( 'settings' ) );
		await field( page ).fill( join( content, 'plugins' ) );
		await page
			.getByRole( 'button', { name: 'Move the backups here' } )
			.click();
		await page
			.getByRole( 'dialog' )
			.getByRole( 'button', { name: 'Move', exact: true } )
			.click();
		const error = page.locator( '#lw-backup-dir-path-error' );
		await expect( error ).toContainText(
			"Backups cannot be kept in the site's own folders"
		);
		await expect( field( page ) ).toHaveAttribute( 'aria-invalid', 'true' );
		// Marked like the other refused fields: the error colour on its border.
		const colours = await page.evaluate( () => [
			window.getComputedStyle(
				document.getElementById( 'lw-backup-dir-path' )
			).borderTopColor,
			window.getComputedStyle(
				document.getElementById( 'lw-backup-dir-path-error' )
			).color,
		] );
		expect( colours[ 0 ] ).toBe( colours[ 1 ] );
		await expect( field( page ) ).toHaveAttribute(
			'aria-describedby',
			/lw-backup-dir-path-error/
		);
		expect( stored().path ).toBe( '' );

		await move( page, content );
		const folder = stored().path;
		expect( folder ).toMatch( /\/lw-migration-backups-[0-9a-f]{12}$/ );
		const random = basename( folder ).slice( -12 );
		await page.getByRole( 'button', { name: 'Done', exact: true } ).click();
		await expect( folderSection( page ).locator( 'code' ) ).toHaveText(
			`${ dirname( folder ) }/lw-migration-backups-…`
		);
		await expect( folderSection( page ) ).toContainText(
			'a folder with a random name, made by the plugin'
		);
		expect( await page.content() ).not.toContain( random );
		for ( const body of bodies ) {
			expect( body ).not.toContain( random );
			expect( body ).not.toContain( storageName() );
		}
	} );

	test( 'a folder outside the website takes every backup, then the default folder again', async ( {
		page,
	} ) => {
		const target = outside();
		const names = readdirSync( stored().path )
			.filter( ( name ) => name.endsWith( '.wpress' ) )
			.sort();
		expect( names.length ).toBeGreaterThan( 0 );

		// The first tick waits, so the running job can be seen.
		let held = false;
		await page.route( ROUTE.tick, async ( route ) => {
			if ( ! held ) {
				held = true;
				await pause( 2000 );
			}
			await route.continue();
		} );
		await page.goto( screen( 'settings' ) );
		await field( page ).fill( target );
		await page
			.getByRole( 'button', { name: 'Move the backups here' } )
			.click();
		await page
			.getByRole( 'dialog' )
			.getByRole( 'button', { name: 'Move', exact: true } )
			.click();
		await expect(
			page.getByRole( 'heading', {
				name: 'Moving the backups',
				level: 2,
			} )
		).toBeVisible();
		await expect(
			page
				.getByRole( 'navigation', { name: 'LW Migration sections' } )
				.getByRole( 'link', { name: /Settings/ } )
		).toContainText( 'running' );
		await expect(
			page.getByRole( 'heading', { name: 'Backups moved', level: 2 } )
		).toBeVisible( { timeout: 120000 } );
		await expect( page.locator( '.lw-result__title' ) ).toContainText(
			/backups? moved \(.+\)\. New backups are saved there from now on\./
		);
		expect( stored().path ).toBe( target );
		expect(
			readdirSync( target )
				.filter( ( name ) => name.endsWith( '.wpress' ) )
				.sort()
		).toEqual( names );
		await page.getByRole( 'button', { name: 'Done', exact: true } ).click();
		await expect( folderSection( page ).locator( 'code' ) ).toHaveText(
			target
		);

		await page.goto( screen( 'backups' ) );
		const rows = page
			.locator( '.lw-bk-row' )
			.filter( { hasText: '.wpress' } );
		await expect( rows ).toHaveCount( names.length );
		for ( const name of names ) {
			await expect( rows.filter( { hasText: name } ) ).toHaveCount( 1 );
		}

		await page.goto( screen( 'settings' ) );
		await move( page, '' );
		await page.getByRole( 'button', { name: 'Done', exact: true } ).click();
		await expect( folderSection( page ) ).toContainText( DEFAULT_TEXT );
		expect( stored().path ).toBe( '' );
		expect( archives( SITE ) ).toEqual( names );
	} );

	test( 'on a phone the section fits and its field stays 16 px', async ( {
		page,
	} ) => {
		await page.setViewportSize( { width: 390, height: 844 } );
		await page.goto( screen( 'settings' ) );
		await expect( field( page ) ).toBeVisible();
		const overflow = await page.evaluate(
			() =>
				document.documentElement.scrollWidth -
				document.documentElement.clientWidth
		);
		expect( overflow ).toBeLessThanOrEqual( 0 );
		const size = await field( page ).evaluate(
			( input ) => window.getComputedStyle( input ).fontSize
		);
		expect( size ).toBe( '16px' );
	} );
} );

test.describe( 'transfer links', () => {
	/** @type {{name: string, size: number}} The backup the tests share. */
	const shared = { name: '', size: 0 };

	test.beforeAll( () => {
		const out = wp(
			SITE,
			'lw-migration',
			'export',
			'--exclude-media',
			'--exclude-plugins',
			'--exclude-themes'
		);
		shared.name = out.match( /Backup file: (\S+\.wpress)/ )[ 1 ];
		shared.size = statSync(
			out.match( /Backup location: (.+\.wpress)/ )[ 1 ].trim()
		).size;
	} );

	test.afterAll( () => {
		wp( SITE, 'option', 'delete', 'lw_migration_shares' );
		wp( SITE, 'lw-migration', 'delete', shared.name, '--yes' );
	} );

	/**
	 * Opens the Transfer link dialog of the shared backup.
	 *
	 * @param {import('@playwright/test').Page} page Page.
	 * @return {Promise<import('@playwright/test').Locator>} The dialog.
	 */
	async function openShare( page ) {
		await page.goto( screen( 'backups' ) );
		await backupAction( page, shared.name, 'Transfer link' );
		const dialog = page.getByRole( 'dialog', { name: 'Transfer link' } );
		await expect( dialog ).toBeVisible();
		return dialog;
	}

	test( 'a link is created, copied, works without login and stops after Revoke', async ( {
		page,
		context,
	} ) => {
		await context.grantPermissions(
			[ 'clipboard-read', 'clipboard-write' ],
			{
				origin: URL_A,
			}
		);
		const dialog = await openShare( page );
		await expect(
			dialog.getByText( 'This backup has no live link.' )
		).toBeVisible();
		await dialog.getByRole( 'radio', { name: '1 hour' } ).click();
		await dialog.getByRole( 'button', { name: 'Create link' } ).click();
		const field = dialog.getByLabel( 'Transfer link' );
		await expect( field ).toHaveValue( /[?&]share=[a-f0-9]{32}$/ );
		const url = await field.inputValue();
		await expect(
			dialog.getByText( 'Copy it now: it is shown only once.' )
		).toBeVisible();

		await dialog.getByRole( 'button', { name: 'Copy' } ).click();
		await expect( snackbar( page, 'Copied' ) ).toBeVisible();
		expect(
			await page.evaluate( () => navigator.clipboard.readText() )
		).toBe( url );

		const anonymous = await fetch( url );
		expect( anonymous.status ).toBe( 200 );
		expect( ( await anonymous.arrayBuffer() ).byteLength ).toBe(
			shared.size
		);

		const live = dialog.locator( '.lw-share-links li' );
		await expect( live ).toHaveCount( 1 );
		await expect( live ).toContainText( 'Expires:' );
		await page.keyboard.press( 'Escape' );
		await expect( page.getByRole( 'dialog' ) ).toHaveCount( 0 );

		const again = await openShare( page );
		await expect( again.getByLabel( 'Transfer link' ) ).toHaveCount( 0 );
		const row = again.locator( '.lw-share-links li' );
		await expect( row ).toContainText( 'used 1 time, last' );
		await row.getByRole( 'button', { name: 'Revoke' } ).click();
		const confirm = page
			.getByRole( 'dialog' )
			.filter( { hasText: 'Revoke this link?' } );
		await confirm.getByRole( 'button', { name: 'Revoke' } ).click();
		await expect(
			again.getByText( 'This backup has no live link.' )
		).toBeVisible();

		const refused = await fetch( url );
		expect( refused.status ).toBe( 404 );
		expect( refused.headers.get( 'content-disposition' ) ).toBeNull();
	} );

	test( 'on a phone the dialog fits and its link field stays 16 px', async ( {
		page,
	} ) => {
		await page.setViewportSize( { width: 390, height: 844 } );
		const dialog = await openShare( page );
		await dialog.getByRole( 'button', { name: 'Create link' } ).click();
		const field = dialog.getByLabel( 'Transfer link' );
		await expect( field ).toBeVisible();
		const overflow = await page.evaluate(
			() =>
				document.documentElement.scrollWidth -
				document.documentElement.clientWidth
		);
		expect( overflow ).toBeLessThanOrEqual( 0 );
		expect(
			await field.evaluate(
				( input ) => window.getComputedStyle( input ).fontSize
			)
		).toBe( '16px' );
	} );
} );
