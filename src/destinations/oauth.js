/**
 * OAuth destinations without React: whether a type connects with an
 * account, whether a destination needs (re)connecting, what an edit does
 * to the connected account, and the notice the callback's redirect asks
 * for (Rest\OAuthApi: ?lwm-oauth=<code>&lwm-destination=<id>). Codes, never
 * texts, come from the server.
 */

/**
 * WordPress dependencies
 */
import { __, _x } from '@wordpress/i18n';

export const OAUTH_PARAM = 'lwm-oauth';

export const DESTINATION_PARAM = 'lwm-destination';

/** Rest\OAuthApi::CONNECTED, DENIED, EXPIRED, FAILED, REFUSED. */
export const OAUTH_CODES = [
	'connected',
	'denied',
	'expired',
	'failed',
	'refused',
];

/** Profile::ID_PATTERN. */
const PROFILE_ID = /^[a-f0-9]{8}$/;

/** OAuthDestinationType::CLIENT_ID. */
const CLIENT_ID = 'clientId';

/** OAuthDestinationType::CLIENT_SECRET. */
export const CLIENT_SECRET = 'clientSecret';

/** OneDriveType::TENANT (the server compares it lower-cased). */
export const TENANT = 'tenant';

/** OAuthMode::OWN_APP: the "Use your own app" switch. */
export const OWN_APP = 'ownApp';

const notices = () => ( {
	connected: [
		'success',
		__(
			'The account is connected. Check the connection to try a small upload.',
			'lw-migration'
		),
	],
	denied: [
		'warning',
		__(
			'The access was not allowed at the provider. Nothing changed.',
			'lw-migration'
		),
	],
	expired: [
		'error',
		__(
			'This sign-in was already used or took longer than 10 minutes. Choose Connect again.',
			'lw-migration'
		),
	],
	failed: [
		'error',
		__(
			'The account could not be connected. Choose Connect to try again.',
			'lw-migration'
		),
	],
	refused: [
		'error',
		__(
			'The Client ID or the sign-in settings changed while connecting. Choose Connect again.',
			'lw-migration'
		),
	],
} );

export const isOAuth = ( type ) => Boolean( type?.oauth );

export const isConnectMarker = ( field ) => 'oauth' === field.kind;

/**
 * @param {Object|null} item Destination view.
 * @return {boolean} Connect or Reconnect is the next step.
 */
export const needsConnect = ( item ) =>
	Boolean( item?.connection ) &&
	( ! item.connection.connected || 'reauth' === item.status );

/**
 * @param {Object|null} item Destination view.
 * @return {string} Button label.
 */
export const connectLabel = ( item ) =>
	item?.connection?.connected
		? __( 'Reconnect', 'lw-migration' )
		: _x( 'Connect', 'connect a cloud account', 'lw-migration' );

const trimmed = ( value ) => String( value ?? '' ).trim();

/**
 * Whether the settings connect through the admin's own app (the PHP twin is
 * OAuthMode::ownApp(): without the switch, a stored Client ID means the own
 * app).
 *
 * @param {Object|undefined} settings Settings.
 * @return {boolean} The own app.
 */
export function isOwnApp( settings ) {
	const own = settings?.[ OWN_APP ];
	if ( 'boolean' === typeof own ) {
		return own;
	}
	return '' !== trimmed( settings?.[ CLIENT_ID ] );
}

/**
 * Whether this site's address can be used to connect: the relay's rule for
 * the LW app, the provider's for the own app. The profile's own check wins
 * while the form keeps the profile's mode (its settings, e.g. the OneDrive
 * tenant, change the rules). A missing check (a type without the `relay`
 * block) is not eligible: the browser must never reach a sign-in the relay
 * would refuse.
 *
 * @param {Object|null} type     Schema type.
 * @param {Object|null} item     Destination view.
 * @param {Object}      settings The form's settings (default: the profile's).
 * @return {{eligible: boolean, text: string}} Result.
 */
export function eligibility( type, item, settings = item?.settings ) {
	const own = isOwnApp( settings );
	let source = own ? type?.oauth : type?.oauth?.relay;
	if ( item?.connection && isOwnApp( item.settings ) === own ) {
		source = item.connection;
	}
	return { eligible: true === source?.eligible, text: source?.text || '' };
}

/**
 * Whether saving the form also connects (Save and connect): an OAuth type,
 * this site's address usable in the chosen mode, and a destination that is
 * new, not connected yet, or loses its connection with this edit.
 *
 * @param {Object|null} type   Schema type.
 * @param {Object|null} stored The stored destination (null: a new one).
 * @param {Object}      form   Form state.
 * @return {boolean} Save and connect.
 */
export const connectsOnSave = ( type, stored, form ) =>
	isOAuth( type ) &&
	eligibility( type, stored, form.settings ).eligible &&
	( ! stored || needsConnect( stored ) || forgetsConnection( form, stored ) );

/**
 * A setting as the server compares it (OneDriveType::tenant(): the tenant
 * lower-cased).
 *
 * @param {string}  key   Setting key.
 * @param {unknown} value Setting value.
 * @return {string} Comparable value.
 */
const comparable = ( key, value ) =>
	TENANT === key ? trimmed( value ).toLowerCase() : trimmed( value );

/**
 * An edit of a stored destination to another Client ID: the server forgets
 * the stored client secret with the old app and wants the new app's one
 * (400 field clientSecret otherwise).
 *
 * @param {Object}      form Form state.
 * @param {Object|null} item The stored destination (null: a new one).
 * @return {boolean} The Client ID changed.
 */
export const clientChanged = ( form, item ) =>
	Boolean( item ) &&
	trimmed( form.settings[ CLIENT_ID ] ) !==
		trimmed( item.settings?.[ CLIENT_ID ] );

/**
 * An OAuth type's settings are its Client ID and the settings that choose
 * the sign-in (OneDrive's tenant and permission): changing any of them
 * forgets the connected account (Rest\DestinationInput), so it must be
 * connected again after saving.
 *
 * @param {Object}      form Form state.
 * @param {Object|null} item The stored destination (null: a new one).
 * @return {boolean} Saving this form disconnects the account.
 */
export const forgetsConnection = ( form, item ) =>
	Boolean( item?.connection?.connected ) &&
	Object.keys( item.settings || {} ).some(
		( key ) =>
			comparable( key, form.settings[ key ] ) !==
			comparable( key, item.settings[ key ] )
	);

/**
 * Where the browser may be sent to sign in: an https address only (the
 * server builds it from the provider's fixed authorize address).
 *
 * @param {unknown} url Address from POST …/connect.
 * @return {boolean} Safe to open.
 */
export function isProviderAddress( url ) {
	if ( 'string' !== typeof url ) {
		return false;
	}
	try {
		return 'https:' === new URL( url ).protocol;
	} catch {
		return false;
	}
}

/**
 * Sends the browser to the provider's sign-in, or throws a rejection in the
 * shape of request()'s (shown like any other failure).
 *
 * @param {Object} answer `{ url }` of api.connectDestination().
 */
export function goToProvider( answer ) {
	if ( ! isProviderAddress( answer?.url ) ) {
		throw {
			code: 'lw_migration_invalid_address',
			message: __(
				'The sign-in address of the provider was not accepted. Reload the page and try again.',
				'lw-migration'
			),
		};
	}
	window.location.assign( answer.url );
}

/**
 * Saves a destination (updates the stored one, else creates it) and, when
 * it is an OAuth destination that needs connecting and the provider accepts
 * this site's address, sends the browser to the provider's sign-in.
 *
 * @param {Object}                 api      The REST client (data/api.js).
 * @param {Object|null}            stored   The stored destination (null: a new one).
 * @param {Object}                 body     Request body (toRequest()).
 * @param {Object}                 type     Schema type.
 * @param {(item: Object) => void} onStored Receives the saved view at once (a failed connect then edits it).
 * @return {Promise<Object|null>} The saved view, or null when the browser leaves for the sign-in.
 */
export async function saveDestination( api, stored, body, type, onStored ) {
	const saved = await ( stored
		? api.updateDestination( stored.id, body )
		: api.createDestination( body ) );
	onStored( saved );
	if (
		isOAuth( type ) &&
		needsConnect( saved ) &&
		false !== saved.connection?.eligible
	) {
		goToProvider( await api.connectDestination( saved.id ) );
		return null;
	}
	return saved;
}

/**
 * @param {string} search location.search.
 * @return {Object|null} `{ code, status, text, id }` or null.
 */
export function readOAuthNotice( search ) {
	const params = new URLSearchParams( search );
	const code = params.get( OAUTH_PARAM );
	const all = notices();
	const known = Object.prototype.hasOwnProperty.call( all, code ?? '' )
		? all[ code ]
		: null;
	if ( ! known ) {
		return null;
	}
	const id = params.get( DESTINATION_PARAM ) || '';
	return {
		code,
		status: known[ 0 ],
		text: known[ 1 ],
		id: PROFILE_ID.test( id ) ? id : '',
	};
}

/**
 * @param {string} href location.href.
 * @return {string} The same address without the two callback parameters.
 */
export function withoutOAuthParams( href ) {
	const url = new URL( href );
	url.searchParams.delete( OAUTH_PARAM );
	url.searchParams.delete( DESTINATION_PARAM );
	return url.toString();
}
