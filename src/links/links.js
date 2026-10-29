/**
 * The Connections section's pure logic: what a typed code holds, which
 * actions a connection offers, its texts, and which jobs a connection runs
 * here for the other site (ruling I5).
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/** Seconds between two "last used" marks of a connection (Links::TOUCH_EVERY). */
export const TOUCH_EVERY = 60;

const CODE = /^lwm1\.([A-Za-z0-9_-]{4,})\.[a-f0-9]{32}$/;

/**
 * Whether a job runs here for a connected site, which drives it (ruling I5):
 * a job with a connection that is neither this site's own push nor its own
 * pull. The server decides in the end (409 lw_migration_link_job): once the
 * connection can no longer drive it, this site may.
 *
 * @param {Object|null} job Job view.
 * @return {boolean} Driven by the other site.
 */
export const drivenElsewhere = ( job ) =>
	Boolean( job?.link ) && 'push' !== job.type && 'link' !== job.source?.kind;

/**
 * @param {string} text Typed or pasted code.
 * @return {string} The code without the whitespace a copy adds.
 */
export const normalizeCode = ( text ) =>
	String( text ?? '' ).replace( /\s+/g, '' );

/**
 * @param {string} text Typed or pasted code.
 * @return {boolean} Whether it has the shape of a pairing code.
 */
export const looksLikeCode = ( text ) => CODE.test( normalizeCode( text ) );

/**
 * The address inside a code, read in the browser only to warn early about
 * plain http (the server's `insecure` answer decides).
 *
 * @param {string} text Typed or pasted code.
 * @return {string|null} Address, null for a malformed code.
 */
export function codeAddress( text ) {
	const match = CODE.exec( normalizeCode( text ) );
	if ( ! match ) {
		return null;
	}
	const b64 = match[ 1 ].replace( /-/g, '+' ).replace( /_/g, '/' );
	let address;
	try {
		address = atob( b64 + '='.repeat( ( 4 - ( b64.length % 4 ) ) % 4 ) );
	} catch {
		return null;
	}
	return /^https?:\/\/[^\s]+$/i.test( address ) ? address : null;
}

/**
 * @param {string|null} address Address.
 * @return {boolean} Whether it is plain (unencrypted) http.
 */
export const isHttp = ( address ) => /^http:\/\//i.test( address || '' );

/**
 * What the card of a connection offers. Sending and copying need an
 * outgoing, ready connection with the right, and no other job running;
 * only the site that typed the code checks; removing always works.
 *
 * @param {Object}  link Link view.
 * @param {boolean} busy Another job runs on this site.
 * @return {{push: Object, pull: Object, check: Object, remove: Object}} Each `{ shown, disabled }`.
 */
export function actionsFor( link, busy ) {
	const out = 'out' === link.direction;
	const usable = out && 'ready' === link.state;
	const can = ( right ) => ( {
		shown: usable && link.allow.includes( right ),
		disabled: busy,
	} );
	return {
		push: can( 'push' ),
		pull: can( 'pull' ),
		check: { shown: out, disabled: false },
		remove: { shown: true, disabled: false },
	};
}

/**
 * @param {string[]} allow     The connection's rights (`push`, `pull`).
 * @param {string}   direction `out` (this site acts) or `in` (the other site acts).
 * @return {string} What the rights let do, '' for none.
 */
export function allowText( allow, direction ) {
	const push = allow.includes( 'push' );
	const pull = allow.includes( 'pull' );
	if ( 'out' === direction ) {
		if ( push && pull ) {
			return __(
				'May send this site there and copy that site here',
				'lw-migration'
			);
		}
		if ( push ) {
			return __( 'May send this site there', 'lw-migration' );
		}
		return pull ? __( 'May copy that site here', 'lw-migration' ) : '';
	}
	if ( push && pull ) {
		return __(
			"The other site may copy this site and replace this site's content",
			'lw-migration'
		);
	}
	if ( push ) {
		return __(
			"The other site may replace this site's content",
			'lw-migration'
		);
	}
	return pull
		? __( 'The other site may copy this site', 'lw-migration' )
		: '';
}

/**
 * @param {Object} link Link view.
 * @return {string} The text of a state that is not ready, '' when ready.
 */
export function stateText( link ) {
	if ( 'pending' === link.state ) {
		return __( 'Waiting for the other site', 'lw-migration' );
	}
	if ( 'broken' === link.state ) {
		return __( 'No longer works here — pair again', 'lw-migration' );
	}
	return '';
}

/**
 * Whether an incoming connection was never used: the pairing's own hello
 * marks it used moments after it was connected, and a mark is written at
 * most once per TOUCH_EVERY seconds.
 *
 * @param {Object} link Link view (`createdAt`, `lastUsedAt` in Unix seconds).
 * @return {boolean} Not used since it was connected.
 */
export const neverUsed = ( link ) =>
	link.lastUsedAt <= link.createdAt + TOUCH_EVERY;

/**
 * @param {number} expiresAt Unix seconds.
 * @param {number} now       Unix seconds.
 * @return {number} Whole seconds left, 0 at the least.
 */
export const codeLeft = ( expiresAt, now ) =>
	Math.max( 0, Math.floor( expiresAt - now ) );

/**
 * @param {number} seconds Seconds.
 * @return {string} `m:ss`.
 */
export const clock = ( seconds ) =>
	`${ Math.floor( seconds / 60 ) }:${ String( seconds % 60 ).padStart(
		2,
		'0'
	) }`;

/**
 * @param {Object[]|undefined} items Link views.
 * @return {number} How many are incoming (made with a code of this site).
 */
export const incoming = ( items ) =>
	( items || [] ).filter( ( link ) => 'in' === link.direction ).length;

/**
 * How a code made here ended once the list stops showing it open: `used`
 * only when an incoming connection was added meanwhile; otherwise `closed`
 * (cancelled elsewhere, replaced by a newer code, or used but not saved).
 *
 * @param {number} before Incoming connections while the code was open.
 * @param {number} after  Incoming connections now.
 * @return {string} `used` or `closed`.
 */
export const codeOutcome = ( before, after ) =>
	after > before ? 'used' : 'closed';
