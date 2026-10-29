/**
 * WordPress dependencies
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { api } from '../data/api';
import { POLL_MS, readsOn } from './contents';

/**
 * The chosen backup's parts (`GET /backups/contents`), asked for only while
 * the panel shows it: an unfinished read (`partial`) is asked again every
 * POLL_MS until it ends, and stops when the panel closes or another backup
 * (or a replaced file) is chosen.
 *
 * @param {Object|null} backup Backup row.
 * @param {() => void}  onGone The backup is not there any more.
 * @return {{answer: (Object|null), error: (Object|null), retry: () => void}} The latest answer or refusal, and a way to ask again after a failure.
 */
export default function useContents( backup, onGone ) {
	const key = backup
		? `${ backup.id }|${ backup.mtime }|${ backup.size }`
		: '';
	const [ state, setState ] = useState( {
		key: '',
		answer: null,
		error: null,
	} );
	const [ attempt, setAttempt ] = useState( 0 );
	const gone = useRef( onGone );
	gone.current = onGone;
	const chosen = useRef( backup );
	chosen.current = backup;

	useEffect( () => {
		if ( ! key ) {
			return;
		}
		const controller = new window.AbortController();
		let timer = null;
		const ask = () =>
			api.backupContents( chosen.current, controller.signal ).then(
				( answer ) => {
					if ( controller.signal.aborted ) {
						return;
					}
					setState( { key, answer, error: null } );
					if ( readsOn( answer ) ) {
						timer = window.setTimeout( ask, POLL_MS );
					}
				},
				( error ) => {
					if ( controller.signal.aborted ) {
						return;
					}
					setState( { key, answer: null, error } );
					if ( 'lw_migration_backup_missing' === error?.code ) {
						gone.current();
					}
				}
			);
		ask();
		return () => {
			controller.abort();
			window.clearTimeout( timer );
		};
	}, [ key, attempt ] );

	// After a failure: forget it and ask again (the effect runs anew).
	const retry = useCallback( () => {
		setState( { key, answer: null, error: null } );
		setAttempt( ( n ) => n + 1 );
	}, [ key ] );

	// An answer for an earlier choice is never shown for this one.
	return state.key === key
		? { answer: state.answer, error: state.error, retry }
		: { answer: null, error: null, retry };
}
