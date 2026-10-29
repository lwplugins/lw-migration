/**
 * WordPress dependencies
 */
import { useMemo, useRef } from '@wordpress/element';

/**
 * The backup actions every row and the panel receive, made once for the
 * section's life: each call goes to the newest handler, so a parent that
 * re-renders (App does on every job poll) never changes them, and the
 * memoized rows stay as they are.
 *
 * @param {Object}                         handlers
 * @param {(backup: Object) => void}       handlers.onRestore  Hands a backup to Restore.
 * @param {(backup: Object) => void}       handlers.download   Starts a download.
 * @param {(open: Object|null) => void}    handlers.setOpen    Opens a dialog `{ what, backup, from }`.
 * @param {(problem: Object|null) => void} handlers.setProblem Sets the section's problem.
 * @return {Object} `{ restore( backup ), download( backup ), open( what, backup, from ) }`.
 */
export default function useBackupActions( handlers ) {
	const latest = useRef( handlers );
	latest.current = handlers;
	return useMemo(
		() => ( {
			restore: ( backup ) => {
				latest.current.setProblem( null );
				latest.current.onRestore( backup );
			},
			download: ( backup ) => latest.current.download( backup ),
			open: ( what, backup, from ) =>
				latest.current.setOpen( { what, backup, from } ),
		} ),
		[]
	);
}
