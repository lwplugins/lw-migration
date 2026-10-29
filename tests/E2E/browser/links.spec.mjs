import { expect, test } from '@playwright/test';
import {
	ROUTE,
	SECOND,
	SITE,
	URL_A,
	URL_B,
	archives,
	login,
	screen,
	spinnersSeen,
	typeTarget,
	watchSpinners,
	wp,
} from './helpers.mjs';

// Connections between the two UI sites: B makes the code, A types it, then A
// sends itself to B (push) and brings B back (pull) from the job screen.
test.describe.configure( { mode: 'serial' } );

const S = '(?:%2F|/)';
const INPUT = new RegExp( `jobs${ S }[a-f0-9]{16}${ S }input` );
const LINKS = new RegExp( `${ S }links(?:&|$)` );
const HTTP_WARNING = 'This connection uses unencrypted http';

/** Shared across the serial tests: both admin tabs, the names, the code. */
const shared = {
	ctxA: null,
	ctxB: null,
	pageA: null,
	pageB: null,
	nameA: '',
	nameB: '',
	code: '',
	// Requests B's page sent that would drive a job (ruling I5).
	drivenFromB: [],
};

/**
 * The admin screen on a tab, always loaded anew (a goto that only changes
 * the hash would not reload the page).
 *
 * @param {string} tab Tab id.
 * @return {string} Path.
 */
const fresh = ( tab ) =>
	screen( tab ).replace( '#', `&lwm-e2e=${ Date.now() }#` );

const snackbar = ( page, text ) =>
	page.locator( '.components-snackbar' ).filter( { hasText: text } );

const card = ( page, name ) =>
	page.locator( '.lw-link' ).filter( { hasText: name } );

const titles = ( site ) =>
	JSON.parse(
		wp(
			site,
			'post',
			'list',
			'--post_type=post',
			'--post_status=publish',
			'--fields=post_title',
			'--format=json'
		)
	).map( ( row ) => row.post_title );

const archivesOf = ( site ) => {
	try {
		return archives( site );
	} catch {
		return []; // No backups folder yet.
	}
};

/**
 * Opens a fresh admin tab of one site with its own cookies.
 *
 * @param {import('@playwright/test').Browser} browser Browser.
 * @param {string}                             base    Site URL.
 */
async function adminTab( browser, base ) {
	const context = await browser.newContext( {
		baseURL: base,
		viewport: { width: 1280, height: 900 },
		permissions: [ 'clipboard-read', 'clipboard-write' ],
	} );
	const page = await context.newPage();
	await watchSpinners( page );
	await login( page, base );
	return { context, page };
}

test.beforeAll( async ( { browser } ) => {
	test.skip( ! URL_B, 'Needs both UI sites.' );
	shared.nameA = wp( SITE, 'option', 'get', 'blogname' );
	shared.nameB = wp( SECOND, 'option', 'get', 'blogname' );
	wp( SECOND, 'eval', 'delete_option( "lwm_e2e_mails" );' );
	const a = await adminTab( browser, URL_A );
	const b = await adminTab( browser, URL_B );
	Object.assign( shared, {
		ctxA: a.context,
		pageA: a.page,
		ctxB: b.context,
		pageB: b.page,
	} );
	b.page.on( 'request', ( request ) => {
		const url = request.url();
		if (
			ROUTE.tick.test( url ) ||
			ROUTE.upload.test( url ) ||
			INPUT.test( url )
		) {
			shared.drivenFromB.push( url );
		}
	} );
} );

test.afterAll( async () => {
	await shared.ctxA?.close();
	await shared.ctxB?.close();
} );

test( 'B makes a pairing code: pull only by default, push only when ticked', async () => {
	const page = shared.pageB;
	// Hold the list a moment, so the loading state is seen.
	await page.route( LINKS, async ( route ) => {
		await new Promise( ( resolve ) => setTimeout( resolve, 600 ) );
		await route.continue();
	} );
	await page.goto( fresh( 'connections' ) );
	await expect(
		page.locator( '.lw-skel-region[role="status"]' )
	).toBeVisible();
	await expect( page.getByText( 'No connections yet.' ) ).toBeVisible();
	await page.unroute( LINKS );
	expect( await spinnersSeen( page ) ).toBe( 0 );

	const pull = page.getByLabel( 'The other site may copy this site' );
	const push = page.getByLabel(
		"The other site may replace this site's content"
	);
	const make = page.getByRole( 'button', { name: 'Make a pairing code' } );
	await expect( pull ).toBeChecked();
	await expect( push ).not.toBeChecked();
	await pull.uncheck();
	await expect( make ).toBeDisabled();
	await pull.check();
	await push.check();
	await expect(
		page.getByText( 'Only tick this for a site you will restore from' )
	).toBeVisible();

	await make.click();
	const field = page.locator( '.lw-pairing__code input[readonly]' );
	await expect( field ).toHaveValue( /^lwm1\.[A-Za-z0-9_-]+\.[a-f0-9]{32}$/ );
	shared.code = await field.inputValue();
	await expect( page.getByText( /^Expires in 1[45]:\d\d$/ ) ).toBeVisible();
	// The rights belong to the open code.
	await expect( pull ).toBeDisabled();
	await expect( push ).toBeDisabled();
	// The ticking countdown is not a live region (said only when it ends).
	await expect( page.locator( '.lw-pairing__left[aria-live]' ) ).toHaveCount(
		0
	);
	await expect(
		page.locator( '.lw-pairing__part' ).first().getByText( HTTP_WARNING )
	).toBeVisible();
	await expect(
		page.getByText( "This site's admins get an e-mail" )
	).toBeVisible();

	await page.getByRole( 'button', { name: 'Copy', exact: true } ).click();
	await expect( snackbar( page, 'Copied' ) ).toBeVisible();
	expect(
		await page.evaluate( () => window.navigator.clipboard.readText() )
	).toBe( shared.code );
} );

test( 'A refuses a mangled code, connects with the real one and checks it', async () => {
	const page = shared.pageA;
	await page.goto( fresh( 'connections' ) );
	await expect( page.getByText( 'No connections yet.' ) ).toBeVisible();
	const input = page.getByLabel( 'Pairing code', { exact: true } );
	const connect = page.getByRole( 'button', { name: 'Connect' } );
	await expect( connect ).toBeDisabled();

	// Same shape, another nonce: B does not know it.
	const last = shared.code.slice( -1 );
	await input.fill(
		shared.code.slice( 0, -1 ) + ( '0' === last ? '1' : '0' )
	);
	await expect(
		page.locator( '.lw-pairing__part' ).nth( 1 ).getByText( HTTP_WARNING )
	).toBeVisible();
	await connect.click();
	await expect( input ).toHaveAttribute( 'aria-invalid', 'true' );
	await expect( input ).toBeFocused();
	await expect( page.locator( '.lw-pairing__code.is-invalid' ) ).toHaveCount(
		1
	);
	await expect(
		page.getByText( 'The other site did not accept this code' )
	).toBeVisible();

	await input.fill( shared.code );
	await connect.click();
	await expect(
		snackbar( page, `Connected with ${ shared.nameB }.` )
	).toBeVisible();
	const link = card( page, shared.nameB );
	await expect( link ).toContainText( 'This site sends' );
	await expect( link ).toContainText( 'http — unencrypted' );
	await expect(
		link.getByRole( 'button', { name: 'Send this site there' } )
	).toBeVisible();
	await expect(
		link.getByRole( 'button', { name: 'Bring that site here' } )
	).toBeVisible();

	await link.getByRole( 'button', { name: 'Check' } ).click();
	await expect(
		snackbar( page, `${ shared.nameB } answers (WordPress ` )
	).toBeVisible();
} );

test( 'B lists the connection with its date and IP, and its admins got a mail', async () => {
	const page = shared.pageB;
	// B's open page reads the list again and sees its code used.
	await expect( page.locator( '.lw-pairing__left' ) ).toHaveText(
		'This code was used: a site connected.',
		{ timeout: 30000 }
	);
	await expect(
		page.getByLabel( 'The other site may copy this site' )
	).toBeEnabled();
	await page.goto( fresh( 'connections' ) );
	const link = card( page, shared.nameA );
	await expect( link ).toContainText( 'This site accepts' );
	await expect( link ).toContainText( /Connected on .+ from 127\.0\.0\.1/ );
	await expect( link.getByRole( 'button' ) ).toHaveText( [ 'Remove' ] );

	const mails = JSON.parse(
		wp( SECOND, 'option', 'get', 'lwm_e2e_mails', '--format=json' )
	);
	expect( mails.map( ( mail ) => mail.subject ) ).toContain(
		`[${ shared.nameB }] A site was connected to this site`
	);
} );

test( 'A sends itself to B; B only follows the job', async () => {
	const page = shared.pageA;
	const marker = `Pushed marker ${ Date.now() }`;
	wp(
		SITE,
		'post',
		'create',
		`--post_title=${ marker }`,
		'--post_status=publish'
	);

	await page.goto( fresh( 'connections' ) );
	await card( page, shared.nameB )
		.getByRole( 'button', { name: 'Send this site there' } )
		.click();
	const dialog = page.getByRole( 'dialog' );
	await expect( dialog ).toContainText(
		`Send this site to ${ shared.nameB } (${ URL_B })?`
	);
	await expect( dialog.getByText( HTTP_WARNING ) ).toBeVisible();
	await expect(
		dialog.getByLabel( "Back up that site's database first" )
	).toBeChecked();
	await dialog
		.getByRole( 'button', { name: 'Send this site there' } )
		.click();

	await expect(
		page.getByRole( 'heading', { name: 'Sending this site', level: 2 } )
	).toBeVisible();
	await expect( page ).toHaveURL( /#connections$/ );
	await expect(
		page
			.getByRole( 'navigation', { name: 'LW Migration sections' } )
			.getByRole( 'link', { name: /Connections/ } )
	).toContainText( 'running' );

	// B's admin opens Connections while the push runs: only followed.
	const pageB = shared.pageB;
	await expect( async () => {
		await pageB.goto( fresh( 'connections' ) );
		await expect(
			pageB.getByText( `Driven by ${ shared.nameA }.` )
		).toBeVisible( { timeout: 4000 } );
	} ).toPass( { timeout: 240000 } );

	await expect(
		page.getByRole( 'heading', {
			name: `Restore this site on ${ shared.nameB }?`,
		} )
	).toBeVisible( { timeout: 240000 } );
	// B waits for the confirmation too, without a prompt of its own.
	await expect(
		pageB.getByText( `Driven by ${ shared.nameA }.` )
	).toBeVisible();
	await expect(
		pageB.getByRole( 'button', { name: /Restore (now|there)/ } )
	).toHaveCount( 0 );
	await expect( pageB.locator( 'input[type="file"]' ) ).toHaveCount( 0 );
	await expect(
		pageB.getByRole( 'button', { name: 'Cancel', exact: true } )
	).toBeVisible();

	await typeTarget( page );
	await page.getByRole( 'button', { name: 'Restore there' } ).click();
	await expect(
		page.getByRole( 'heading', { name: 'Site sent', level: 2 } )
	).toBeVisible( { timeout: 300000 } );
	await expect( page.locator( '.lw-result__title' ) ).toHaveText(
		`${ shared.nameB } now shows the content of this site.`
	);
	await expect( page.getByText( /^Safety backup there: / ) ).toBeVisible();

	expect( titles( SECOND ) ).toContain( marker );
	expect( shared.drivenFromB ).toEqual( [] );
	await page.getByRole( 'button', { name: 'Done', exact: true } ).click();
} );

test( 'A brings B here; B stores no backup for it', async () => {
	const page = shared.pageA;
	const marker = `Pulled marker ${ Date.now() }`;
	wp(
		SECOND,
		'post',
		'create',
		`--post_title=${ marker }`,
		'--post_status=publish'
	);
	const before = archivesOf( SECOND );

	await page.goto( fresh( 'connections' ) );
	await card( page, shared.nameB )
		.getByRole( 'button', { name: 'Bring that site here' } )
		.click();
	const dialog = page.getByRole( 'dialog' );
	await expect( dialog ).toContainText(
		`Bring ${ shared.nameB } (${ URL_B }) here?`
	);
	await dialog
		.getByRole( 'button', { name: 'Bring that site here' } )
		.click();

	await expect(
		page.getByRole( 'heading', { name: 'Restoring', level: 2 } )
	).toBeVisible();
	await expect(
		page.getByText( `From the connected site ${ shared.nameB }` )
	).toBeVisible();
	await expect(
		page.locator(
			'.lw-track__phases [aria-current="step"] .lw-track__name'
		)
	).toHaveText( 'Receiving' );

	await expect(
		page.getByRole( 'heading', { name: 'Check before restoring' } )
	).toBeVisible( { timeout: 240000 } );
	await typeTarget( page );
	await page.getByRole( 'button', { name: 'Restore now' } ).click();
	await expect(
		page.getByRole( 'heading', { name: 'Restore finished', level: 2 } )
	).toBeVisible( { timeout: 300000 } );

	expect( titles( SITE ) ).toContain( marker );
	expect( archivesOf( SECOND ) ).toEqual( before );
	await page.getByRole( 'button', { name: 'Done', exact: true } ).click();
} );

test( 'on a phone Connections fits, its code fields are 16 px and the actions wrap', async () => {
	const page = shared.pageA;
	await login( page, URL_A ); // The pull replaced this site's sessions.
	await page.setViewportSize( { width: 390, height: 844 } );
	await page.goto( fresh( 'connections' ) );
	const link = card( page, shared.nameB );
	await expect( link ).toBeVisible();
	await page.getByRole( 'button', { name: 'Make a pairing code' } ).click();
	await expect(
		page.locator( '.lw-pairing__code input[readonly]' )
	).toHaveValue( /^lwm1\./ );

	const overflow = await page.evaluate(
		() =>
			document.documentElement.scrollWidth -
			document.documentElement.clientWidth
	);
	expect( overflow ).toBeLessThanOrEqual( 0 );
	for ( const input of await page
		.locator( '.lw-pairing__code input' )
		.all() ) {
		expect(
			await input.evaluate(
				( node ) => window.getComputedStyle( node ).fontSize
			)
		).toBe( '16px' );
	}
	const row = link.locator( '.lw-actions' );
	expect(
		await row.evaluate(
			( node ) => window.getComputedStyle( node ).flexWrap
		)
	).toBe( 'wrap' );
	const box = await link.boundingBox();
	for ( const button of await row.getByRole( 'button' ).all() ) {
		const own = await button.boundingBox();
		expect( own.x + own.width ).toBeLessThanOrEqual( box.x + box.width );
	}

	await page.getByRole( 'button', { name: 'Cancel the code' } ).click();
	await expect(
		page.locator( '.lw-pairing__code input[readonly]' )
	).toHaveCount( 0 );
	await page.setViewportSize( { width: 1280, height: 900 } );
} );

test( 'removing on A removes the connection on B too', async () => {
	const page = shared.pageA;
	await page.goto( fresh( 'connections' ) );
	await card( page, shared.nameB )
		.getByRole( 'button', { name: 'Remove' } )
		.click();
	const dialog = page.getByRole( 'dialog' );
	await expect( dialog ).toContainText(
		`Remove the connection with ${ shared.nameB }?`
	);
	await dialog.getByRole( 'button', { name: 'Remove' } ).click();
	await expect(
		snackbar( page, 'Removed. The other site was told.' )
	).toBeVisible();
	await expect( page.getByText( 'No connections yet.' ) ).toBeVisible();

	const pageB = shared.pageB;
	await login( pageB, URL_B ); // The push replaced B's sessions.
	await pageB.goto( fresh( 'connections' ) );
	await expect( pageB.getByText( 'No connections yet.' ) ).toBeVisible();
} );

/**
 * Pairs the two sites again from WP-CLI (B makes the code, A types it).
 *
 * @return {string} The id of B's incoming connection.
 */
function pairByCli() {
	const made = wp(
		SECOND,
		'lw-migration',
		'link',
		'create-code',
		'--user=admin',
		'--allow=pull,push'
	);
	const code = made.match( /lwm1\.[A-Za-z0-9_-]+\.[a-f0-9]{32}/ )[ 0 ];
	wp( SITE, 'lw-migration', 'link', 'add', code, '--user=admin' );
	return JSON.parse(
		wp( SECOND, 'lw-migration', 'link', 'list', '--format=json' )
	)[ 0 ].id;
}

/**
 * Starts on B the restore a push of A would start (as the signed route
 * does), then nobody drives it: a quiet job of the connection.
 *
 * @param {string} id B's incoming connection.
 * @return {string} Job id.
 */
const quietPush = ( id ) =>
	wp(
		SECOND,
		'eval',
		`$s = \\LightweightPlugins\\Migration\\Services::get();
		$link = ( new \\LightweightPlugins\\Migration\\Link\\Links() )->find( '${ id }', time() );
		echo \\LightweightPlugins\\Migration\\Link\\PeerJobs::fromServices( $s )->startPush( $link, [
			'ref' => bin2hex( random_bytes( 8 ) ),
			'name' => 'site-20261010-120000-123.wpress',
			'size' => 1048576,
			'safetyBackup' => false,
		] )->id;`
	);

test.describe( 'a job a connection runs on B', () => {
	test.describe.configure( { timeout: 300000 } );

	test( 'B cannot take it over while the connection may drive it, and can cancel it', async () => {
		const id = pairByCli();
		quietPush( id );
		const page = shared.pageB;
		await page.goto( fresh( 'connections' ) );
		await expect(
			page.getByText( `Driven by ${ shared.nameA }.` )
		).toBeVisible();
		const take = page.getByRole( 'button', { name: 'Continue here' } );
		// Offered only once the job has been quiet for a while.
		await expect( take ).toHaveCount( 0 );
		await expect( take ).toBeVisible( { timeout: 60000 } );
		await take.click();
		await expect(
			page
				.locator( '.lw-job .components-notice' )
				.getByText(
					`This job is driven by the connected site ${ shared.nameA }. Follow it here, or cancel it.`
				)
		).toBeVisible();
		await expect(
			page.getByText( `Driven by ${ shared.nameA }.` )
		).toBeVisible();
		await expect( page.locator( 'input[type="file"]' ) ).toHaveCount( 0 );

		await page
			.getByRole( 'button', { name: 'Cancel', exact: true } )
			.click();
		await page.getByRole( 'button', { name: 'Cancel the job' } ).click();
		await expect(
			page.getByRole( 'heading', { name: 'Restore cancelled', level: 2 } )
		).toBeVisible();
		await page.getByRole( 'button', { name: 'Done', exact: true } ).click();
	} );

	test( 'once the connection is gone here, B takes the job over and ends it', async () => {
		const id = JSON.parse(
			wp( SECOND, 'lw-migration', 'link', 'list', '--format=json' )
		)[ 0 ].id;
		quietPush( id );
		// The row goes without the usual cancel (as when it can no longer drive).
		wp(
			SECOND,
			'eval',
			`( new \\LightweightPlugins\\Migration\\Link\\Links() )->remove( '${ id }' );`
		);
		const page = shared.pageB;
		await page.goto( fresh( 'connections' ) );
		await expect(
			page.getByText( `Driven by ${ shared.nameA }.` )
		).toBeVisible();
		const take = page.getByRole( 'button', { name: 'Continue here' } );
		await expect( take ).toBeVisible( { timeout: 60000 } );
		await take.click();

		// This site drives it now: it asks for the archive like any upload.
		await expect(
			page.getByText( `Driven by ${ shared.nameA }.` )
		).toHaveCount( 0 );
		await expect(
			page.getByRole( 'heading', { name: 'Restoring', level: 2 } )
		).toBeVisible();
		await expect( page.locator( '.lw-job__message' ) ).toHaveText(
			'Waiting for the file.'
		);
		await page
			.getByRole( 'button', { name: 'Cancel', exact: true } )
			.click();
		await page.getByRole( 'button', { name: 'Cancel the job' } ).click();
		await expect(
			page.getByRole( 'heading', { name: 'Restore cancelled', level: 2 } )
		).toBeVisible();
		await page.getByRole( 'button', { name: 'Done', exact: true } ).click();
	} );
} );

test( 'a logout during Connections shows the login notice', async () => {
	const page = shared.pageA;
	await page.goto( fresh( 'connections' ) );
	await expect( page.getByText( 'New connection' ) ).toBeVisible();
	await shared.ctxA.clearCookies();

	await page.getByRole( 'button', { name: 'Make a pairing code' } ).click();
	await expect(
		page.locator( '.lw-pairing__part' ).first().getByRole( 'link', {
			name: 'Log in again',
		} )
	).toBeVisible();

	// Paired again from WP-CLI: B's name is A's since the push.
	const link = page.locator( '.lw-link' ).first();
	await link.getByRole( 'button', { name: 'Check' } ).click();
	await expect(
		page
			.locator( '.lw-admin-content > .components-notice' )
			.getByRole( 'link', { name: 'Log in again' } )
	).toBeVisible();
	await login( page, URL_A );
} );
