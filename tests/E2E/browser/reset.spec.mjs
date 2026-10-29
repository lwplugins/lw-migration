import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { expect, test } from '@playwright/test';
import {
	SITE_R,
	URL_R,
	login,
	screen,
	spinnersSeen,
	watchSpinners,
	wp,
} from './helpers.mjs';

// The Reset section on site r only: that site is thrown away by these tests
// (never site A or B). Serial: a plugins reset, a database reset, the lockout.
test.describe.configure( { mode: 'serial' } );

const S = '(?:%2F|/)';
const SUMMARY = new RegExp( `${ S }reset(?:&|$)` );

/**
 * The Reset section, always loaded anew (a hash change alone does not reload).
 *
 * @param {string} tab Tab id.
 * @return {string} URL.
 */
const fresh = ( tab ) =>
	URL_R + screen( tab ).replace( '#', `&lwm-e2e=${ Date.now() }#` );

// The tools are a radio group of tiles; the chosen one's layers and steps
// open in the plan panel below them, never in a dialog.
const tile = ( page, title ) =>
	page.getByRole( 'radio', { name: title, exact: true } );

const panel = ( page ) => page.locator( '.lw-reset-plan' );

const choose = async ( page, title ) => {
	await tile( page, title ).click();
	await expect( tile( page, title ) ).toHaveAttribute(
		'aria-checked',
		'true'
	);
};

const confirmButton = ( page, title ) =>
	panel( page ).getByRole( 'button', { name: title, exact: true } );

test.skip( ! SITE_R || ! URL_R, 'Run through bin/test-ui.sh (site r).' );

test.beforeAll( () => {
	// Only one other plugin, so the done text is exact.
	wp( SITE_R, 'plugin', 'delete', 'akismet', 'hello' );
	const folder = join( SITE_R, 'wp-content', 'plugins', 'lwm-dummy' );
	mkdirSync( folder, { recursive: true } );
	writeFileSync(
		join( folder, 'lwm-dummy.php' ),
		'<?php\n/**\n * Plugin Name: LWM Dummy\n */\n'
	);
	wp( SITE_R, 'plugin', 'activate', 'lwm-dummy' );
} );

test( 'Reset loads with a skeleton, then five tiles and no choice, never a spinner', async ( {
	page,
} ) => {
	await watchSpinners( page );
	await login( page, URL_R );
	let release;
	const held = new Promise( ( resolve ) => ( release = resolve ) );
	await page.route( SUMMARY, async ( route ) => {
		await held;
		await route.continue();
	} );
	await page.goto( fresh( 'reset' ) );
	await expect(
		page.locator( '[role="status"][aria-busy="true"]' )
	).toBeVisible();
	release();
	await expect( page.getByRole( 'radio' ) ).toHaveCount( 5 );
	for ( const title of [
		'Delete plugins',
		'Reset themes',
		'Clean up media',
		'Reset the database',
		'Reset everything',
	] ) {
		await expect( tile( page, title ) ).toHaveAttribute(
			'aria-checked',
			'false'
		);
	}
	await expect( panel( page ).getByLabel( 'Your password' ) ).toHaveCount(
		0
	);
	await choose( page, 'Delete plugins' );
	await expect( panel( page ) ).toContainText( 'LWM Dummy' );
	await expect( page.getByRole( 'dialog' ) ).toHaveCount( 0 );
	await page.unroute( SUMMARY );
	expect( await spinnersSeen( page ) ).toBe( 0 );
} );

test( 'Delete plugins asks for the password, refuses a wrong one and backs up first', async ( {
	page,
} ) => {
	await login( page, URL_R );
	await page.goto( fresh( 'reset' ) );
	await choose( page, 'Delete plugins' );
	const confirm = confirmButton( page, 'Delete plugins' );
	await expect( confirm ).toBeDisabled();
	await expect(
		panel( page ).getByText(
			'The backup is kept until you delete it (resets never remove backups).'
		)
	).toBeVisible();

	const password = panel( page ).getByLabel( 'Your password' );
	await password.fill( 'wrong-pass' );
	await expect( confirm ).toBeEnabled();
	await confirm.click();
	await expect(
		panel( page ).getByText( 'This is not your password.' )
	).toBeVisible();
	await expect( password ).toHaveAttribute( 'aria-invalid', 'true' );
	await expect( password ).toHaveValue( '' );
	await expect( password ).toBeFocused();

	await password.fill( 'admin-pass' );
	await confirm.click();
	await expect(
		page.locator( '.lw-track__phases .lw-track__name' )
	).toHaveText( [ 'Backup', 'Plugins' ] );
	await expect(
		page.getByRole( 'heading', { name: 'Reset finished' } )
	).toBeVisible( { timeout: 180000 } );
	await expect( page.getByText( '1 plugin deleted.' ) ).toBeVisible();
	const backupLine = page.getByText( /Backup before the reset: pre-reset-/ );
	await expect( backupLine ).toBeVisible();

	await backupLine.getByRole( 'link' ).click();
	const row = page
		.locator( '.lw-bk-row' )
		.filter( { hasText: /pre-reset-/ } );
	await expect( row ).toBeVisible();
	await expect( row ).toContainText( 'Before a reset' );
	await expect( row ).toContainText( 'Before reset — plugins' );
	expect( wp( SITE_R, 'plugin', 'list', '--field=name' ) ).not.toContain(
		'lwm-dummy'
	);
} );

test( 'Enter with a wrong site address sends nothing', async ( { page } ) => {
	await login( page, URL_R );
	await page.goto( fresh( 'reset' ) );
	const posts = [];
	page.on( 'request', ( request ) => {
		if ( SUMMARY.test( request.url() ) && 'POST' === request.method() ) {
			posts.push( request.url() );
		}
	} );
	await choose( page, 'Reset the database' );
	await panel( page ).getByLabel( 'Your password' ).fill( 'admin-pass' );
	const phrase = panel( page ).getByLabel( /to confirm/ );
	await phrase.fill( 'not-this-site.test' );
	await phrase.press( 'Enter' );
	await page.waitForTimeout( 500 );
	expect( posts ).toEqual( [] );
	// Another choice clears the address and keeps the password.
	await choose( page, 'Reset everything' );
	await expect( panel( page ).getByLabel( /to confirm/ ) ).toHaveValue( '' );
	await expect( panel( page ).getByLabel( 'Your password' ) ).toHaveValue(
		'admin-pass'
	);
	// Back on the database, the address is not brought back.
	await choose( page, 'Reset the database' );
	await expect( panel( page ).getByLabel( /to confirm/ ) ).toHaveValue( '' );
	await expect( confirmButton( page, 'Reset the database' ) ).toBeDisabled();
	expect( posts ).toEqual( [] );
} );

test( 'another choice turns the backup back on', async ( { page } ) => {
	await login( page, URL_R );
	await page.goto( fresh( 'reset' ) );
	await choose( page, 'Clean up media' );
	const backup = () =>
		panel( page )
			.getByRole( 'switch', { name: 'Back up the whole site first' } )
			.or(
				panel( page ).getByRole( 'checkbox', {
					name: 'Back up the whole site first',
				} )
			);
	await backup().click();
	await expect( backup() ).not.toBeChecked();
	await choose( page, 'Reset themes' );
	await expect( backup() ).toBeChecked();
} );

test( 'a database reset shows plugins and media as staying on disk', async ( {
	page,
} ) => {
	await login( page, URL_R );
	await page.goto( fresh( 'reset' ) );
	await choose( page, 'Reset the database' );
	await expect( panel( page ).locator( '.lw-reset-tag.is-disk' ) ).toHaveText(
		[ 'Stays on disk', 'Stays on disk' ]
	);
	await expect(
		panel( page ).getByText( 'Only LW Migration stays active' )
	).toBeVisible();
	await expect(
		panel( page ).getByText( 'You (admin)', { exact: true } )
	).toBeVisible();
} );

test( 'a backups folder that cannot take a backup blocks the reset until the backup is off', async ( {
	page,
} ) => {
	await login( page, URL_R );
	await page.route( SUMMARY, async ( route ) => {
		if ( 'GET' !== route.request().method() ) {
			return route.continue();
		}
		const response = await route.fetch();
		const json = await response.json();
		await route.fulfill( {
			response,
			json: { ...json, backupReady: false },
		} );
	} );
	await page.goto( fresh( 'reset' ) );
	await choose( page, 'Clean up media' );
	await panel( page ).getByLabel( 'Your password' ).fill( 'x' );
	const confirm = confirmButton( page, 'Clean up media' );
	await expect( confirm ).toBeDisabled();
	const warning = panel( page ).getByText(
		'or switch the backup off to reset without one'
	);
	await expect( warning ).toBeVisible();
	const describedBy = await confirm.getAttribute( 'aria-describedby' );
	expect( describedBy ).toBeTruthy();
	await expect( page.locator( `[id="${ describedBy }"]` ) ).toContainText(
		'cannot take a backup right now'
	);
	// Said once, in the step; the note under the button stays general.
	await expect(
		panel( page ).getByText( /cannot take a backup right now/ )
	).toHaveCount( 1 );
	await expect(
		panel( page ).getByText( 'Fill in the steps and the button turns on.' )
	).toBeVisible();
	await panel( page )
		.getByRole( 'switch', { name: 'Back up the whole site first' } )
		.or(
			panel( page ).getByRole( 'checkbox', {
				name: 'Back up the whole site first',
			} )
		)
		.click();
	await expect( confirm ).toBeEnabled();
	await page.unroute( SUMMARY );
} );

test( 'a session that ended shows the login notice in the panel', async ( {
	page,
} ) => {
	await login( page, URL_R );
	await page.route( SUMMARY, ( route ) =>
		'POST' === route.request().method()
			? route.fulfill( {
					status: 401,
					contentType: 'application/json',
					body: JSON.stringify( {
						code: 'rest_not_logged_in',
						message: 'You are not currently logged in.',
						data: { status: 401 },
					} ),
				} )
			: route.continue()
	);
	await page.goto( fresh( 'reset' ) );
	await choose( page, 'Clean up media' );
	const password = panel( page ).getByLabel( 'Your password' );
	await password.fill( 'admin-pass' );
	await confirmButton( page, 'Clean up media' ).click();
	await expect(
		panel( page ).getByText( 'You were logged out.' )
	).toBeVisible();
	// The focus moves to the notice, as for any other refusal.
	await expect( panel( page ).locator( ':focus' ) ).toContainText(
		'You were logged out.'
	);
	const link = panel( page ).getByRole( 'link', { name: /Log in/ } );
	await expect( link ).toHaveAttribute( 'href', /tab%3Dreset|tab=reset/ );
	await expect( password ).toHaveValue( '' );
	await page.unroute( SUMMARY );
} );

test( 'Reset the database needs the site address, keeps the admin logged in and asks for a reload', async ( {
	page,
} ) => {
	await login( page, URL_R );
	await page.goto( fresh( 'reset' ) );
	await choose( page, 'Reset the database' );
	const confirm = confirmButton( page, 'Reset the database' );
	await panel( page )
		.getByRole( 'switch', { name: 'Back up the whole site first' } )
		.or(
			panel( page ).getByRole( 'checkbox', {
				name: 'Back up the whole site first',
			} )
		)
		.click();
	await expect(
		panel( page ).getByText( 'Without a backup this cannot be undone.' )
	).toBeVisible();
	await panel( page ).getByLabel( 'Your password' ).fill( 'admin-pass' );
	await expect( confirm ).toBeDisabled();
	const phrase = await panel( page ).locator( 'label code' ).innerText();
	expect( phrase ).toBe( '127.0.0.1:' + new URL( URL_R ).port );
	await panel( page )
		.getByLabel( /to confirm/ )
		.fill( ` ${ phrase } ` );
	await expect( confirm ).toBeEnabled();
	await confirm.click();

	await expect(
		page.getByRole( 'heading', { name: 'Reset finished' } )
	).toBeVisible( { timeout: 180000 } );
	await expect(
		page.getByText( 'You are still logged in as admin' )
	).toBeVisible();
	const reload = page.getByRole( 'button', { name: 'Reload', exact: true } );
	await expect( reload ).toBeVisible();
	await reload.click();
	await page.waitForLoadState();
	await page.goto( fresh( 'reset' ) );
	await expect( page ).toHaveURL( /page=lw-migration/ );
	await expect( page.getByRole( 'radio' ) ).toHaveCount( 5 );
	expect(
		wp( SITE_R, 'plugin', 'list', '--status=active', '--field=name' )
	).toBe( 'lw-migration' );
} );

test( 'five wrong passwords lock the reset for minutes', async ( { page } ) => {
	await login( page, URL_R );
	await page.goto( fresh( 'reset' ) );
	await choose( page, 'Clean up media' );
	const password = panel( page ).getByLabel( 'Your password' );
	const confirm = confirmButton( page, 'Clean up media' );
	const lock = panel( page ).locator( '.lw-reset-locked' );
	const wrong = panel( page ).getByText( 'This is not your password.' );
	for ( let i = 0; i < 5 && 0 === ( await lock.count() ); i++ ) {
		await password.fill( `wrong-${ i }` );
		await confirm.click();
		// Either the field is marked (and emptied) or the lock took over.
		await expect( lock.or( wrong ) ).toBeVisible();
	}
	await expect( lock ).toContainText(
		'Too many wrong passwords. Try again in 15 minutes.'
	);
	await expect( lock ).toBeFocused();
	// The lock replaces the steps and the start, whatever is chosen.
	await expect( panel( page ).getByLabel( 'Your password' ) ).toHaveCount(
		0
	);
	await choose( page, 'Delete plugins' );
	await expect( lock ).toBeVisible();
	wp( SITE_R, 'option', 'delete', 'lw_migration_reset_tries' );
} );

test( 'on a phone Reset fits, its fields are 16 px and the panel stacks', async ( {
	page,
} ) => {
	await login( page, URL_R );
	await page.setViewportSize( { width: 390, height: 844 } );
	await page.goto( fresh( 'reset' ) );
	await expect( page.getByRole( 'radio' ) ).toHaveCount( 5 );
	const overflow = () =>
		page.evaluate(
			() =>
				document.documentElement.scrollWidth -
				document.documentElement.clientWidth
		);
	expect( await overflow() ).toBeLessThanOrEqual( 0 );

	await choose( page, 'Reset everything' );
	const parts = await panel( page )
		.locator( '.lw-reset-plan__part' )
		.evaluateAll( ( nodes ) =>
			nodes.map( ( node ) => node.getBoundingClientRect().left )
		);
	expect( parts ).toHaveLength( 2 );
	expect( new Set( parts ).size ).toBe( 1 );
	for ( const field of [
		panel( page ).getByLabel( 'Your password' ),
		panel( page ).getByLabel( /to confirm/ ),
	] ) {
		expect(
			await field.evaluate(
				( node ) => window.getComputedStyle( node ).fontSize
			)
		).toBe( '16px' );
	}
	expect( await overflow() ).toBeLessThanOrEqual( 0 );
	await page.setViewportSize( { width: 1280, height: 900 } );
} );
