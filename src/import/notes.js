/**
 * The check view's two notes without React: what happens to the logins
 * (PreserveStep keeps the importing admin's login, a site restore into a
 * network merges the users, a pushed restore keeps the login of the admin
 * who made the connection code) and the address that is replaced.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { hostOf } from './check';
import { included } from './parts';

const sitesIntoNetwork = ( summary ) =>
	Boolean( summary.network ) && 'network' !== summary.network.kind;

/**
 * @param {Object}  summary Confirm summary.
 * @param {boolean} there   A push: the restore runs on the connected site.
 * @return {string} The warning about the logins ('' when the archive brings no database).
 */
export function loginText( summary, there ) {
	if ( false === included( summary, 'database' ) ) {
		return '';
	}
	if ( there ) {
		return __(
			'After the restore that site has the users of this site. The admin there who made the connection code keeps their login, and its connections, destinations and schedules stay.',
			'lw-migration'
		);
	}
	if ( sitesIntoNetwork( summary ) ) {
		return __(
			"The archive's users are added to this network. The network's own users stay, and so does your login.",
			'lw-migration'
		);
	}
	return summary.network
		? __(
				'After the restore the users come from the backup. You keep your own username and password; if the restore logs you out, log in again and it goes on by itself. Connections, destinations and schedules stay on this network.',
				'lw-migration'
			)
		: __(
				'After the restore the users come from the backup. You keep your own username and password; if the restore logs you out, log in again and it goes on by itself. Connections, destinations and schedules stay on this site.',
				'lw-migration'
			);
}

/**
 * The hosts of the address replacement, when the restore replaces the
 * address (a site restore into a network shows each site's own row instead).
 *
 * @param {Object} summary Confirm summary.
 * @return {{from: string, to: string}|null} Hosts, or null for no note.
 */
export function addressNote( summary ) {
	if ( true !== summary.replacesAddress || sitesIntoNetwork( summary ) ) {
		return null;
	}
	const from = hostOf( summary.from );
	const to = hostOf( summary.to );
	if ( from && from === to ) {
		// Same host (http to https, or a folder change): the host alone
		// would name it twice, so show the whole addresses.
		return {
			from: fullAddress( summary.from ),
			to: fullAddress( summary.to ),
		};
	}
	return { from, to };
}

/**
 * @param {string} url Address.
 * @return {string} Scheme, host and path, without a closing slash.
 */
function fullAddress( url ) {
	return String( url || '' )
		.trim()
		.replace( /[?#].*$/, '' )
		.replace( /\/+$/, '' );
}
