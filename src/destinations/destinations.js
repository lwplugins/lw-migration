/**
 * A destination's form state and request, without React. The fields come
 * from the server's schema (DestinationRegistry::schema()); a field with
 * `when` shows only while another field has one of the listed values, and
 * only shown fields are sent. A secret is never sent back by the server
 * (`secrets.<key>.set` only): an empty secret field keeps the stored one.
 * A toggle without a schema default stays null until it is switched and is
 * not sent while null, so the server applies its own default (the S3
 * path-style default depends on the provider). Only keys of
 * Rest\DestinationInput::KEYS leave the browser (pinned by
 * destinations.test.js against the PHP source).
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { SITE_FOLDER } from '../data/boot';
import { CLIENT_SECRET, clientChanged, isConnectMarker } from './oauth';

/** Rest\DestinationInput::KEYS. */
export const REQUEST_KEYS = [
	'type',
	'name',
	'settings',
	'secrets',
	'retention',
];

/** A manual backup keeps everything at a destination until a limit is set. */
export const NO_RETENTION = { count: 0, bytes: 0, days: 0 };

/**
 * The presets whose path-style default is on (S3Presets::PRESETS, pinned by
 * destinations.test.js against the PHP source): what the switch shows while
 * no choice is made.
 */
export const PATH_STYLE_DEFAULT = { b2: true, r2: true, custom: true };

/** Rest\DestinationInput::SECRET_BYTES (pinned by destinations.test.js). */
export const MAX_SECRET_BYTES = 16384;

/** Sftp\HostKey::PATTERN (pinned by destinations.test.js). */
export const FINGERPRINT = /^SHA256:[A-Za-z0-9+/]{43}$/;

/** Ftp\CertificatePin::PATTERN (pinned by destinations.test.js). */
export const CERT_FINGERPRINT = /^[0-9A-F]{2}(:[0-9A-F]{2}){31}$/;

/** Ftp\CertificatePin::COMMAND (pinned by destinations.test.js; never translated). */
export const CERTIFICATE_COMMAND =
	'openssl x509 -noout -fingerprint -sha256 -in cert.pem';

/**
 * A secret is never sent back by the server; a multi-line one (an SSH
 * private key) is one too.
 *
 * @param {Object} field Schema field.
 * @return {boolean} A secret.
 */
export const isSecret = ( field ) =>
	'secret' === field.kind || 'secret_text' === field.kind;

/**
 * @param {Object} field Schema field.
 * @return {boolean} A multi-line secret (a text area, not a password input).
 */
export const isSecretText = ( field ) => 'secret_text' === field.kind;

/**
 * @param {Object} field    Schema field.
 * @param {Object} settings Current settings.
 * @return {boolean} Shown now.
 */
export const visible = ( field, settings ) =>
	Object.entries( field.when || {} ).every( ( [ key, allowed ] ) =>
		allowed.includes( settings[ key ] )
	);

/**
 * The fields that hold a value: the OAuth connect marker (kind `oauth`)
 * holds none.
 *
 * @param {Object} type Schema type.
 * @return {Object[]} Fields.
 */
const valueFields = ( type ) =>
	type.fields.filter( ( field ) => ! isConnectMarker( field ) );

/** The setting that names the folder a new destination keeps its backups in. */
const FOLDER_KEY = {
	s3: 'prefix',
	ftp: 'folder',
	sftp: 'folder',
	webdav: 'folder',
};

const initial = ( field, typeKey ) => {
	if ( null !== field.default && undefined !== field.default ) {
		return field.default;
	}
	if ( FOLDER_KEY[ typeKey ] === field.key ) {
		return SITE_FOLDER;
	}
	return 'toggle' === field.kind ? null : '';
};

/**
 * @param {Object} type Schema type.
 * @return {Object} Empty form.
 */
export function emptyForm( type ) {
	const settings = {};
	const secrets = {};
	for ( const field of valueFields( type ) ) {
		if ( isSecret( field ) ) {
			secrets[ field.key ] = '';
		} else {
			settings[ field.key ] = initial( field, type.type );
		}
	}
	return {
		type: type.type,
		name: '',
		settings,
		secrets,
		retention: { ...NO_RETENTION },
	};
}

/**
 * @param {Object} item Destination view.
 * @param {Object} type Its schema type.
 * @return {Object} Form state.
 */
export function fromItem( item, type ) {
	const form = emptyForm( type );
	return {
		...form,
		name: item.name,
		settings: { ...form.settings, ...item.settings },
		retention: { ...NO_RETENTION, ...item.retention },
	};
}

/**
 * One setting changed; a new provider forgets the path-style choice (the
 * server applies the new provider's default unless it is switched again).
 *
 * @param {Object}         form  Form state.
 * @param {string}         key   Setting key.
 * @param {boolean|string} value New value.
 * @return {Object} Form state.
 */
export function withSetting( form, key, value ) {
	const settings = { ...form.settings, [ key ]: value };
	if (
		'preset' === key &&
		value !== form.settings.preset &&
		'pathStyle' in settings
	) {
		settings.pathStyle = null;
	}
	return { ...form, settings };
}

/**
 * What a switch shows: the choice made, else the provider's path-style
 * default for that switch, else off.
 *
 * @param {Object} field    Schema field (a toggle).
 * @param {Object} settings Current settings.
 * @return {boolean} On.
 */
export function toggleChecked( field, settings ) {
	const value = settings[ field.key ];
	if ( 'boolean' === typeof value ) {
		return value;
	}
	return (
		'pathStyle' === field.key &&
		true === PATH_STYLE_DEFAULT[ settings.preset ]
	);
}

/**
 * @param {Object}  form  Form state.
 * @param {Object}  type  Schema type.
 * @param {boolean} isNew A new destination (sends its type).
 * @return {Object} Request body.
 */
export function toRequest( form, type, isNew ) {
	const settings = {};
	const secrets = {};
	for ( const field of valueFields( type ) ) {
		const value = form.settings[ field.key ];
		if ( isSecret( field ) ) {
			// Only the secrets of what is shown: a password typed before
			// switching to a key login stays in the browser.
			if (
				visible( field, form.settings ) &&
				'' !== form.secrets[ field.key ]
			) {
				secrets[ field.key ] = form.secrets[ field.key ];
			}
		} else if ( ! visible( field, form.settings ) ) {
			continue;
		} else if ( 'toggle' === field.kind ) {
			if ( null !== value && undefined !== value ) {
				settings[ field.key ] = Boolean( value );
			}
		} else {
			settings[ field.key ] = String( value ?? '' ).trim();
		}
	}
	return {
		...( isNew ? { type: form.type } : {} ),
		name: form.name.trim(),
		settings,
		secrets,
		retention: { ...form.retention },
	};
}

/**
 * What the server would refuse for an empty value, checked before sending
 * (also the client secret an OAuth destination needs with a new Client ID).
 *
 * @param {Object}      form Form state.
 * @param {Object}      type Schema type.
 * @param {Object|null} item The stored destination (null: a new one).
 * @return {Object|null} `{ field, reason }` or null.
 */
export function checkForm( form, type, item = null ) {
	if ( '' === form.name.trim() ) {
		return { field: 'name', reason: 'empty' };
	}
	for ( const field of valueFields( type ) ) {
		if ( ! field.required || ! visible( field, form.settings ) ) {
			continue;
		}
		const empty = isSecret( field )
			? ! item?.secrets?.[ field.key ]?.set &&
				'' === form.secrets[ field.key ]
			: '' === String( form.settings[ field.key ] ?? '' ).trim();
		if ( empty ) {
			return { field: field.key, reason: 'empty' };
		}
	}
	// A new Client ID is another app: its own client secret is needed.
	if (
		type.oauth &&
		clientChanged( form, item ) &&
		'' === ( form.secrets[ CLIENT_SECRET ] ?? '' )
	) {
		return { field: CLIENT_SECRET, reason: 'newClient' };
	}
	return null;
}

/**
 * @param {string} status new | ok | error | reauth.
 * @return {string} StatusBadge status.
 */
export const statusTone = ( status ) =>
	( { ok: 'ok', error: 'critical', reauth: 'warning' } )[ status ] || 'idle';

/**
 * @param {string} status new | ok | error | reauth.
 * @return {string} Label.
 */
export const statusLabel = ( status ) =>
	( {
		ok: __( 'Working', 'lw-migration' ),
		error: __( 'Failing', 'lw-migration' ),
		reauth: __( 'Credentials needed', 'lw-migration' ),
	} )[ status ] || __( 'Not tested yet', 'lw-migration' );

/**
 * The failure reasons of a fingerprint to confirm: its kind and the field
 * (the setting and the trust body key) it is shown and stored under.
 */
const CONFIRM_REASONS = {
	host_key_unknown: [ 'unknown', 'hostKey' ],
	host_key_changed: [ 'changed', 'hostKey' ],
	cert_unknown: [ 'unknown', 'certFingerprint' ],
	cert_changed: [ 'changed', 'certFingerprint' ],
};

/** The shape of each field's fingerprint. */
const PATTERNS = { hostKey: FINGERPRINT, certFingerprint: CERT_FINGERPRINT };

/**
 * The fingerprint a connection check stopped at, read from the failure's
 * codes only, never from its text: an SFTP host key (plan D31) or an FTPS
 * certificate no certificate authority vouches for (issue #5). A changed
 * one is shown only next to the confirmed one (`before: 'confirmed'`).
 *
 * @param {Object|null} error A rejection of api.testDestination() or api.trustDestination().
 * @return {{kind: string, field: string, fingerprint: string, keyType: string, expected: string, before: string}|null} The fingerprint to check, or null.
 */
export function hostKeyState( error ) {
	const data = error?.data || {};
	const [ kind, field ] = CONFIRM_REASONS[ data.reason ] || [];
	if (
		'lw_migration_destination_failed' !== error?.code ||
		! kind ||
		! PATTERNS[ field ].test( String( data[ field ] ?? '' ) )
	) {
		return null;
	}
	const changed = 'changed' === kind;
	if (
		changed &&
		! PATTERNS[ field ].test( String( data.expected ?? '' ) )
	) {
		return null;
	}
	return {
		kind,
		field,
		fingerprint: data[ field ],
		keyType: String( data.hostKeyType ?? '' ),
		expected: changed ? data.expected : '',
		before: changed ? 'confirmed' : '',
	};
}

/**
 * A trust refused because the server shows yet another fingerprint than
 * the one on the card (409 lw_migration_host_key_differs): that one is a
 * changed one, checked against the one shown before (`before: 'shown'`),
 * never a silent swap under a one-click trust.
 *
 * @param {Object|null} current The fingerprint state on the card.
 * @param {Object|null} error   A rejection of api.trustDestination().
 * @return {Object|null} The changed fingerprint to check, or null.
 */
export function hostKeyDiffers( current, error ) {
	const shown = String( error?.data?.[ current?.field ] ?? '' );
	if (
		'lw_migration_host_key_differs' !== error?.code ||
		! current ||
		! PATTERNS[ current.field ]?.test( shown ) ||
		shown === current.fingerprint
	) {
		return null;
	}
	return {
		kind: 'changed',
		field: current.field,
		fingerprint: shown,
		keyType: String( error.data.hostKeyType ?? '' ),
		expected: current.fingerprint,
		before: 'shown',
	};
}

/**
 * The command that shows the fingerprint of the server's host key of a key
 * type (ssh-ed25519, ecdsa-sha2-…, ssh-rsa) in OpenSSH's default place.
 *
 * @param {string} keyType Key type ('' = unknown: the Ed25519 key).
 * @return {string} Command (never translated).
 */
export function hostKeyCommand( keyType ) {
	let file = 'ed25519';
	if ( keyType.startsWith( 'ecdsa' ) ) {
		file = 'ecdsa';
	} else if ( keyType.includes( 'rsa' ) ) {
		file = 'rsa';
	}
	return `ssh-keygen -lf /etc/ssh/ssh_host_${ file }_key.pub`;
}

/**
 * What deleting a destination does; a connected account also loses this
 * site's access at the provider where the provider allows it (issue #6).
 *
 * @param {Object} item Destination view.
 * @return {string} The confirmation text.
 */
export const deleteText = ( item ) => {
	const text = sprintf(
		/* translators: %s: destination name. */
		__(
			'%s and its stored credentials are deleted from this site. The backups already there stay there. Schedules that use it skip it from now on.',
			'lw-migration'
		),
		item.name
	);
	if ( ! item.connection?.connected ) {
		return text;
	}

	return (
		text +
		' ' +
		__(
			"This site also withdraws its access to the account where the provider allows it; if it cannot, you are told where to remove the app's access in your account. Uninstalling the plugin does not withdraw anything: remove the destinations first, or remove the app's access in your account.",
			'lw-migration'
		)
	);
};
