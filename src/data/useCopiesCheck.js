/**
 * WordPress dependencies
 */
import { useEffect, useRef } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { awaitsConnect } from '../backups/cloudStatus';
import { api } from './api';

/**
 * One listing per destination per page load, shared by every list that
 * shows copies: id => { promise, changed } (changed null while the call
 * runs; false after a failure).
 *
 * @type {Map<string, {promise: Promise<boolean>, changed: (boolean|null)}>}
 */
const asked = new Map();

/** Forgets every listing asked for (tests only). */
export const resetCopiesCheck = () => asked.clear();

/**
 * The destinations to list for a backups list: every known one but one
 * waiting for its account (a listing also records the backups it holds byte
 * for byte, so one added again over its old folder gets its chips back).
 * With the destinations unknown (their request failed), the ones an
 * uploaded copy names. Nothing for an empty list.
 *
 * @param {Object[]}      items        Items of `GET /backups`.
 * @param {Object[]|null} destinations Destination views, null when unknown.
 * @return {string[]} Destination ids.
 */
export function copiesToCheck( items, destinations ) {
	if ( 0 === items.length ) {
		return [];
	}
	if ( null !== destinations ) {
		return destinations
			.filter( ( destination ) => ! awaitsConnect( destination ) )
			.map( ( destination ) => destination.id );
	}
	return [
		...new Set(
			items.flatMap( ( item ) =>
				( item.cloud || [] )
					.filter( ( copy ) => 'up' === copy.status )
					.map( ( copy ) => copy.id )
			)
		),
	];
}

/**
 * @param {string} id Destination id.
 * @return {{promise: Promise<boolean>, changed: (boolean|null)}} Its listing, asked once.
 */
function listing( id ) {
	if ( ! asked.has( id ) ) {
		const entry = { changed: null };
		entry.promise = api.remoteFiles( id ).then(
			( answer ) => ( entry.changed = true === answer?.copiesChanged ),
			() => ( entry.changed = false )
		);
		asked.set( id, entry );
	}
	return asked.get( id );
}

/**
 * The copy records can say a backup is at a destination after someone
 * removed it there by hand, or miss one a destination added again holds; a
 * destination's listing corrects them on the server. Once the backups list
 * is loaded, this lists each destination (once per page load) and loads the
 * backups list again when a listing changed the records, so the "Also at"
 * chips follow. A failure stays silent: the chips stay.
 *
 * @param {Object} list  useRemote( api.backups ).
 * @param {Object} cloud useRemote( api.destinations ).
 */
export default function useCopiesCheck( list, cloud ) {
	const { data, reload } = list;
	const reloaded = useRef( false );
	const live = useRef( true );
	const known = cloud.error ? null : cloud.data?.items;
	const waiting = cloud.isLoading || undefined === known;

	useEffect( () => {
		live.current = true;
		return () => {
			live.current = false;
		};
	}, [] );

	useEffect( () => {
		if ( ! data || waiting || reloaded.current ) {
			return;
		}
		const running = copiesToCheck( data.items || [], known || null )
			.map( listing )
			.filter( ( entry ) => null === entry.changed );
		if ( 0 === running.length ) {
			return;
		}
		// Not cancelled when the list or the destinations change meanwhile:
		// the answer still tells whether the shown copies were corrected.
		Promise.all( running.map( ( entry ) => entry.promise ) ).then(
			( answers ) => {
				if (
					live.current &&
					! reloaded.current &&
					answers.includes( true )
				) {
					reloaded.current = true;
					reload();
				}
			}
		);
	}, [ data, waiting, known, reload ] );
}
