/**
 * WordPress dependencies
 */
import { addQueryArgs, getQueryArg, removeQueryArgs } from '@wordpress/url';

/**
 * The login link that brings the user back to a tab of this screen (ruling
 * P7): the tab goes into the `redirect_to` value (encoded with it), never
 * onto the login URL itself.
 *
 * @param {string} loginUrl Boot `loginUrl` (wp_login_url() of this screen).
 * @param {string} tab      Tab id to land on.
 * @return {string} Login URL ('' without one).
 */
export function loginUrlFor( loginUrl, tab ) {
	if ( ! loginUrl ) {
		return '';
	}
	const back = getQueryArg( loginUrl, 'redirect_to' );
	if ( ! back ) {
		return loginUrl;
	}
	return addQueryArgs( removeQueryArgs( loginUrl, 'redirect_to' ), {
		redirect_to: addQueryArgs( back, { tab } ),
	} );
}
