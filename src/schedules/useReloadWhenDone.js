/**
 * WordPress dependencies
 */
import { useEffect, useRef } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { isFinished } from '../job/phases';

/**
 * Reads the schedules list again once the job the page drives ends (done,
 * failed, cancelled, or gone), so a card that showed it running gets its
 * new state and history without leaving the section. Once per job.
 *
 * @param {Object|null} job    The job the page drives (App), or null.
 * @param {() => void}  reload Reads the list again.
 */
export default function useReloadWhenDone( job, reload ) {
	const watched = useRef( null );
	const id = job?.id ?? null;
	const working = Boolean( job ) && ! isFinished( job );

	useEffect( () => {
		if ( watched.current && ( ! working || id !== watched.current ) ) {
			watched.current = null;
			reload();
		}
		if ( working ) {
			watched.current = id;
		}
	}, [ id, working, reload ] );
}
