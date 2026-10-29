/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * WordPress dependencies
 */
import { getQueryArg } from '@wordpress/url';

/**
 * Internal dependencies
 */
import { loginUrlFor } from './login';

const LOGIN =
	'http://site.test/wp-login.php?redirect_to=http%3A%2F%2Fsite.test%2Fwp-admin%2Fadmin.php%3Fpage%3Dlw-migration';

describe( 'loginUrlFor', () => {
	it( 'puts the tab inside redirect_to, encoded', () => {
		const url = loginUrlFor( LOGIN, 'import' );

		expect( url ).toBe(
			'http://site.test/wp-login.php?redirect_to=http%3A%2F%2Fsite.test%2Fwp-admin%2Fadmin.php%3Fpage%3Dlw-migration%26tab%3Dimport'
		);
		expect( getQueryArg( url, 'redirect_to' ) ).toBe(
			'http://site.test/wp-admin/admin.php?page=lw-migration&tab=import'
		);
		expect( getQueryArg( url, 'tab' ) ).toBeUndefined();
	} );

	it( 'replaces a tab already in redirect_to and keeps other login args', () => {
		const url = loginUrlFor(
			'http://site.test/wp-login.php?reauth=1&redirect_to=http%3A%2F%2Fsite.test%2Fwp-admin%2Fadmin.php%3Fpage%3Dlw-migration%26tab%3Dexport',
			'import'
		);

		expect( getQueryArg( url, 'reauth' ) ).toBe( '1' );
		expect( getQueryArg( url, 'redirect_to' ) ).toBe(
			'http://site.test/wp-admin/admin.php?page=lw-migration&tab=import'
		);
	} );

	it( 'leaves a login URL without redirect_to as it is', () => {
		expect( loginUrlFor( 'http://site.test/wp-login.php', 'export' ) ).toBe(
			'http://site.test/wp-login.php'
		);
	} );

	it( 'gives nothing without a login URL', () => {
		expect( loginUrlFor( '', 'export' ) ).toBe( '' );
	} );
} );
