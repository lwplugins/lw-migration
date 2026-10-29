/**
 * The destination card and its inline editor, without React: the panels
 * of a card (one open at a time), where the focus goes after a delete,
 * the editor's save button and its note about a changed location.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { isOAuth } from './oauth';

/**
 * @param {string} id Destination id.
 * @return {string} The id of the card's heading (the focus target after a delete).
 */
export const headingId = ( id ) => `lw-dest-${ id }-title`;

/**
 * The panel a toggle leaves open: the same one again closes it, another
 * one replaces it.
 *
 * @param {?string} open      The open panel (null: none).
 * @param {string}  requested The toggled panel.
 * @return {?string} The panel open now.
 */
export const togglePanel = ( open, requested ) =>
	open === requested ? null : requested;

/**
 * Where the focus goes once a destination is deleted: the next card, else
 * the one before it; null when it was the only one.
 *
 * @param {Object[]} items The list before the delete.
 * @param {string}   id    The deleted destination.
 * @return {?string} The id of the card to focus, or null.
 */
export function afterDelete( items, id ) {
	const index = items.findIndex( ( item ) => item.id === id );
	if ( index < 0 ) {
		return null;
	}
	const next = items[ index + 1 ] ?? items[ index - 1 ];
	return next ? next.id : null;
}

/**
 * The editor's save button: a server or a storage saves and then checks the
 * connection; a cloud account saves, and connects when it needs to. The
 * big button of a new account names the provider.
 *
 * @param {Object}  type     Schema type.
 * @param {boolean} connects Saving also connects (connectsOnSave()).
 * @param {boolean} big      The big button of a new cloud account.
 * @return {string} Label.
 */
export function saveLabel( type, connects, big = false ) {
	if ( ! isOAuth( type ) ) {
		return __( 'Save and check', 'lw-migration' );
	}
	if ( ! connects ) {
		return __( 'Save', 'lw-migration' );
	}
	return big
		? sprintf(
				/* translators: %s: provider name, e.g. Dropbox. */
				__( 'Save and connect: %s', 'lw-migration' ),
				type.label
			)
		: __( 'Save and connect', 'lw-migration' );
}

/**
 * Whether an edit moves the destination: the live line differs from the
 * stored one (a stored account's live line is the stored one, so it never
 * moves here: its folder is pinned at connect).
 *
 * @param {string}      preview previewWhere() of the form ('' = unknown).
 * @param {Object|null} item    The stored destination (null: a new one).
 * @return {boolean} The backups already there stay at the old place.
 */
export const movesLocation = ( preview, item ) =>
	Boolean( item ) && '' !== preview && preview !== ( item.where ?? '' );
