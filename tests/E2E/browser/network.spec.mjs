import { expect, test } from '@playwright/test';
import {
	ROUTE,
	archives,
	backupAction,
	login,
	spinnersSeen,
	typeTarget,
	watchSpinners,
	wp,
} from './helpers.mjs';

// The subdirectory network n of bin/test-wp.sh, served by bin/test-ui.sh on its
// own port (LWM_E2E_NETWORK=1). Logins happen on the main site only; a subsite
// is reached through its front page or REST, never its static files (php -S
// has no rewrites).
const NETWORK = process.env.LWM_E2E_N;
const URL_N = process.env.LWM_UI_URL_N;
const HOST = URL_N ? new URL( URL_N ).host : '';

test.describe.configure( { mode: 'serial' } );
test.skip(
	! URL_N || ! NETWORK,
	'Run LWM_E2E_NETWORK=1 npm run test:ui to execute the network tests.'
);

const pause = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );

const networkScreen = ( tab ) =>
	`${ URL_N }/wp-admin/network/settings.php?page=lw-migration#${ tab }`;

/** What the serial tests hand on: the backup of site b2. */
const made = { name: '' };

/**
 * The schedules as the server keeps them (the page's own REST client).
 *
 * @param {import('@playwright/test').Page} page Page on the network screen.
 * @return {Promise<Object[]>} ScheduleView items.
 */
const schedules = ( page ) =>
	page.evaluate( async () => {
		const answer = await window.wp.apiFetch( {
			path: '/lw-migration/v1/schedules',
		} );
		return answer.items;
	} );

/**
 * Chooses sites in a picker by searching their address.
 *
 * @param {import('@playwright/test').Locator} scope The picker element.
 * @param {string}                             slug  Path of the site (b2, b3).
 */
async function chooseSite( scope, slug ) {
	await scope.getByRole( 'radio', { name: 'Chosen sites' } ).click();
	await scope.getByLabel( 'Search by address' ).fill( slug );
	const box = scope.getByRole( 'checkbox', { name: `${ HOST }/${ slug }` } );
	await expect( box ).toBeVisible();
	await expect(
		scope.locator( '.lw-picker__row' ),
		'The search keeps only the matching address.'
	).toHaveCount( 1 );
	await box.check();
	await expect(
		scope.locator( '.lw-chip' ).filter( { hasText: `${ HOST }/${ slug }` } )
	).toBeVisible();
	await expect( scope.getByText( '1 chosen' ) ).toBeVisible();
}

test.beforeEach( async ( { page } ) => {
	await watchSpinners( page );
	await login( page, URL_N );
} );

test.afterEach( async ( { page } ) => {
	expect( await spinnersSeen( page ) ).toBe( 0 );
} );

test( 'the network page loads with skeletons, then the network backup', async ( {
	page,
} ) => {
	await page.route( ROUTE.active, async ( route ) => {
		await pause( 1500 );
		await route.continue();
	} );
	await page.goto( networkScreen( 'export' ) );
	await expect( page.locator( '.lw-skel-region' ).first() ).toBeVisible();
	await expect(
		page.getByRole( 'heading', { name: 'Back up this network', level: 2 } )
	).toBeVisible();
	await expect( page.locator( '.lw-skel-region' ) ).toHaveCount( 0 );
	// Connections join two single sites only (spec 19).
	await expect(
		page
			.getByRole( 'navigation', { name: 'LW Migration sections' } )
			.getByRole( 'link', { name: /Connections/ } )
	).toHaveCount( 0 );
	await expect(
		page.getByRole( 'radio', { name: 'Whole network' } )
	).toBeChecked();
	await expect(
		page.getByText( 'This network has 3 sites.', { exact: true } )
	).toBeVisible();

	// The site list shows skeleton rows while a page loads.
	await page.route( /network(?:%2F|\/)sites/, async ( route ) => {
		await pause( 1200 );
		await route.continue();
	} );
	await page.getByRole( 'radio', { name: 'Chosen sites' } ).click();
	await expect( page.locator( '.lw-picker .lw-skel-region' ) ).toBeVisible();
	await expect( page.locator( '.lw-picker__row' ) ).toHaveCount( 3 );
	await expect(
		page.getByRole( 'button', { name: 'Start backup' } )
	).toBeDisabled();
	await expect( page.getByText( 'Choose at least one site.' ) ).toBeVisible();
} );

test( 'a backup of a chosen site sends only that site', async ( { page } ) => {
	const before = archives( NETWORK );
	await page.goto( networkScreen( 'export' ) );
	const picker = page.locator( '.lw-picker' );
	await chooseSite( picker, 'b2' );

	// A chip is removable, and the choice survives other searches.
	await picker.getByLabel( 'Search by address' ).fill( 'b3' );
	await expect( picker.locator( '.lw-picker__row' ) ).toHaveCount( 1 );
	await expect( picker.locator( '.lw-chip' ) ).toHaveCount( 1 );

	// The switch's name carries the size beside the title.
	await page.getByRole( 'checkbox', { name: /^Media library/ } ).uncheck();
	const start = page.waitForRequest(
		( request ) =>
			'POST' === request.method() &&
			/v1(?:%2F|\/)jobs(?:[?&]|$)/.test( request.url() )
	);
	await page.getByRole( 'button', { name: 'Start backup' } ).click();
	const body = ( await start ).postDataJSON();
	expect( body.type ).toBe( 'export' );
	expect( body.options.sites ).toEqual( [ 2 ] );

	await expect( page.getByText( 'The backup is ready.' ) ).toBeVisible( {
		timeout: 200000,
	} );
	const after = archives( NETWORK );
	expect( after ).toHaveLength( before.length + 1 );
	made.name = after.find( ( name ) => ! before.includes( name ) );
} );

test( 'restoring the site backup asks for its address and refuses one on its row', async ( {
	page,
	request,
} ) => {
	expect( made.name ).not.toBe( '' );
	await page.goto( networkScreen( 'backups' ) );
	await backupAction( page, made.name, 'Restore' );
	await expect( page ).toHaveURL( /#import$/ );
	await page.getByRole( 'button', { name: 'Continue to the check' } ).click();
	await expect( page.getByText( 'Check before restoring' ) ).toBeVisible( {
		timeout: 120000,
	} );

	const row = page.locator( '.lw-address' );
	await expect( row ).toHaveCount( 1 );
	const address = row.getByLabel( 'New address' );
	await expect( address ).toHaveValue( `${ URL_N }/b2` );
	await expect( row.getByText( 'Replaces an existing site' ) ).toBeVisible();

	// An address outside the network: the server's reason on that row.
	await address.fill( 'http://elsewhere.test/shop' );
	await expect(
		row.getByText( 'Replaces an existing site' ),
		'An unchecked address has no note yet.'
	).toHaveCount( 0 );
	await typeTarget( page );
	await page.getByRole( 'button', { name: 'Restore now' } ).click();
	await expect( row.locator( '.lw-address__error' ) ).toBeVisible( {
		timeout: 60000,
	} );
	await expect( row ).toHaveClass( /is-invalid/ );
	await expect( address ).toHaveAttribute( 'aria-invalid', 'true' );
	await expect( page.getByText( 'Check before restoring' ) ).toBeVisible();
	expect( wp( NETWORK, 'site', 'list', '--field=url' ) ).not.toContain(
		'elsewhere'
	);

	await address.fill( `${ URL_N }/shop` );
	await typeTarget( page );
	await page.getByRole( 'button', { name: 'Restore now' } ).click();
	await expect( page.getByText( '1 site was restored.' ) ).toBeVisible( {
		timeout: 200000,
	} );
	const link = page
		.locator( '.lw-result__sites' )
		.getByRole( 'link', { name: `${ HOST }/shop` } );
	await expect( link ).toHaveAttribute( 'href', `${ URL_N }/shop` );
	await expect(
		page.locator( '.lw-result__sites' ).getByText( 'Created as a new site' )
	).toBeVisible();

	expect( wp( NETWORK, 'site', 'list', '--field=url' ) ).toContain(
		`${ URL_N }/shop/`
	);
	expect( ( await request.get( `${ URL_N }/shop/` ) ).status() ).toBe( 200 );
} );

test( 'a schedule of chosen sites keeps its sites when it is saved again', async ( {
	page,
} ) => {
	await page.goto( networkScreen( 'schedules' ) );
	await page.getByRole( 'button', { name: 'New schedule' } ).click();
	const dialog = page.getByRole( 'dialog', { name: 'New schedule' } );
	await dialog.getByLabel( 'Name', { exact: true } ).fill( 'UI b3 only' );
	await chooseSite( dialog.locator( '.lw-picker' ), 'b3' );
	await dialog.getByRole( 'button', { name: 'Save schedule' } ).click();
	await expect( dialog ).toHaveCount( 0 );

	const saved = ( await schedules( page ) ).find(
		( item ) => 'UI b3 only' === item.title
	);
	expect( saved.export.sites ).toEqual( [ 3 ] );

	// The editor shows the scope, and an unrelated edit keeps it.
	const card = page
		.locator( '.lw-sched' )
		.filter( { hasText: 'UI b3 only' } );
	await card.getByRole( 'button', { name: 'Edit' } ).click();
	const editor = page.getByRole( 'dialog', { name: 'Edit schedule' } );
	await expect(
		editor.getByRole( 'radio', { name: 'Chosen sites' } )
	).toBeChecked();
	await expect( editor.getByText( '1 chosen' ) ).toBeVisible();
	await expect(
		editor.getByRole( 'checkbox', { name: `${ HOST }/b3` } )
	).toBeChecked();
	await editor.getByLabel( 'Name', { exact: true } ).fill( 'UI b3 renamed' );
	await editor.getByRole( 'button', { name: 'Save schedule' } ).click();
	await expect( editor ).toHaveCount( 0 );

	const renamed = ( await schedules( page ) ).find(
		( item ) => saved.id === item.id
	);
	expect( renamed.title ).toBe( 'UI b3 renamed' );
	expect( renamed.export.sites ).toEqual( [ 3 ] );
} );

test( 'a whole-network backup keeps its sites, and a refused answer shows above them', async ( {
	page,
} ) => {
	const before = archives( NETWORK );
	await page.goto( networkScreen( 'export' ) );
	// The switch's name carries the size beside the title.
	await page.getByRole( 'checkbox', { name: /^Media library/ } ).uncheck();
	await page.getByRole( 'button', { name: 'Start backup' } ).click();
	await expect( page.getByText( 'The backup is ready.' ) ).toBeVisible( {
		timeout: 200000,
	} );
	const name = archives( NETWORK ).find(
		( item ) => ! before.includes( item )
	);

	await page.goto( networkScreen( 'backups' ) );
	await backupAction( page, name, 'Restore' );
	await page.getByRole( 'button', { name: 'Continue to the check' } ).click();
	await expect( page.getByText( 'Check before restoring' ) ).toBeVisible( {
		timeout: 120000,
	} );
	const rows = page.locator( '.lw-address' );
	await expect( rows.first() ).toBeVisible();
	await expect( page.getByLabel( 'New address' ) ).toHaveCount( 0 );
	await expect( page.getByText( 'Keeps its ID (1)' ) ).toBeVisible();
	await expect( page.getByText( 'Keeps its ID (2)' ) ).toBeVisible();

	// The page never sends addresses for a network archive; a crafted answer
	// that does is refused as a whole, and the reason stands above the rows.
	const input = /v1(?:%2F|\/)jobs(?:%2F|\/)[a-f0-9]{16}(?:%2F|\/)input/;
	await page.route( input, ( route ) =>
		route.continue( {
			postData: JSON.stringify( {
				...route.request().postDataJSON(),
				sites: { 0: `${ URL_N }/elsewhere` },
			} ),
		} )
	);
	await typeTarget( page );
	await page.getByRole( 'button', { name: 'Restore now' } ).click();
	const refusal = page
		.locator( '.lw-addresses .components-notice.is-error' )
		.first();
	await expect( refusal ).toBeVisible( { timeout: 60000 } );
	await expect( page.locator( '.lw-address__error' ) ).toHaveCount( 0 );
	await page.unroute( input );

	await page.getByRole( 'button', { name: 'Back', exact: true } ).click();
	await expect(
		page.getByRole( 'heading', { name: 'Where to restore from?' } )
	).toBeVisible();
	expect( wp( NETWORK, 'site', 'list', '--field=url' ) ).not.toContain(
		'elsewhere'
	);
} );

test( 'Reset on a network offers files only and says so', async ( {
	page,
} ) => {
	await page.goto( networkScreen( 'reset' ) );
	await expect( page.getByRole( 'radio' ) ).toHaveCount( 5 );
	await expect(
		page.getByText( 'Each one backs up the whole network first' )
	).toBeVisible();
	await expect(
		page.getByText(
			'On a network the database cannot be reset in this version'
		)
	).toBeVisible();
	for ( const title of [ 'Reset the database', 'Reset everything' ] ) {
		const tile = page.getByRole( 'radio', { name: title, exact: true } );
		await expect( tile ).toHaveAttribute( 'aria-disabled', 'true' );
		await expect( tile ).toContainText(
			'On a network only plugins, themes and media can be reset in this version.'
		);
		await tile.click( { force: true } );
		await expect( tile ).toHaveAttribute( 'aria-checked', 'false' );
	}
	await page
		.getByRole( 'radio', { name: 'Clean up media', exact: true } )
		.click();
	await expect(
		page
			.locator( '.lw-reset-plan' )
			.getByText(
				'Every media item of every site and every file in the uploads folder'
			)
	).toBeVisible();
} );

test( "the main site's own admin points to the network page", async ( {
	page,
} ) => {
	await page.goto( `${ URL_N }/wp-admin/admin.php?page=lw-migration` );
	await expect(
		page.getByText(
			'Migrations of this network are under Network Admin > Settings > Migration.'
		)
	).toBeVisible();
	await expect(
		page.getByRole( 'link', { name: 'Open the network page' } )
	).toHaveAttribute(
		'href',
		`${ URL_N }/wp-admin/network/settings.php?page=lw-migration`
	);
	await expect( page.locator( '#lw-migration-root' ) ).toHaveCount( 0 );
} );

test( 'on a phone the picker fits and its fields stay 16 px', async ( {
	page,
} ) => {
	await page.setViewportSize( { width: 390, height: 844 } );
	await page.goto( networkScreen( 'export' ) );
	await page.getByRole( 'radio', { name: 'Chosen sites' } ).click();
	await expect( page.locator( '.lw-picker__row' ).first() ).toBeVisible();
	await page
		.locator( '.lw-picker__row' )
		.first()
		.getByRole( 'checkbox' )
		.check();
	await expect( page.locator( '.lw-chip' ) ).toHaveCount( 1 );

	const overflow = await page.evaluate(
		() =>
			document.documentElement.scrollWidth -
			document.documentElement.clientWidth
	);
	expect( overflow ).toBeLessThanOrEqual( 0 );
	const size = await page
		.getByLabel( 'Search by address' )
		.evaluate( ( field ) => window.getComputedStyle( field ).fontSize );
	expect( size ).toBe( '16px' );
	const small = await page.evaluate( () =>
		[ ...document.querySelectorAll( '.lw-picker *' ) ]
			.filter(
				( element ) =>
					element.offsetParent &&
					// Elements that draw text themselves (a direct text node).
					[ ...element.childNodes ].some(
						( node ) =>
							3 === node.nodeType && node.textContent.trim()
					) &&
					! element.closest(
						'.screen-reader-text, .components-visually-hidden'
					) &&
					parseFloat( window.getComputedStyle( element ).fontSize ) <
						14
			)
			.map(
				( element ) =>
					`${ element.tagName }.${ element.className }: ${ element.textContent.trim() }`
			)
	);
	expect( small, 'Text under 14 px.' ).toEqual( [] );
} );
