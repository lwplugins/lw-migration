/**
 * Asks GET /export/estimate until it says `complete` (each request moves the
 * measuring on by a few seconds: docs/react-admin-api.md), without React.
 */

/** Milliseconds between two requests while the estimate is still counting. */
export const POLL_MS = 2500;

/**
 * Starts asking at once, then again POLL_MS after each answer that is not
 * complete. A failed request ends the asking (the figures so far stay with
 * the caller). The returned function stops it and aborts the request in
 * flight; nothing is reported after it was called.
 *
 * @param {Object}                                   props
 * @param {(signal: AbortSignal) => Promise<Object>} props.fetcher Returns a promise of the estimate.
 * @param {(estimate: Object) => void}               props.onData  Receives each answer.
 * @param {(error: Object) => void}                  props.onError Receives a failure.
 * @param {number}                                   props.delay   Milliseconds between requests.
 * @return {() => void} Stop.
 */
export function pollEstimate( { fetcher, onData, onError, delay = POLL_MS } ) {
	let stopped = false;
	let timer = null;
	const controller = new AbortController();

	const ask = () => {
		timer = null;
		Promise.resolve()
			.then( () => fetcher( controller.signal ) )
			.then(
				( estimate ) => {
					if ( stopped ) {
						return;
					}
					onData( estimate );
					if ( ! estimate?.complete ) {
						timer = setTimeout( ask, delay );
					}
				},
				( error ) => {
					if ( ! stopped ) {
						onError( error );
					}
				}
			);
	};
	ask();

	return () => {
		stopped = true;
		if ( null !== timer ) {
			clearTimeout( timer );
		}
		controller.abort();
	};
}
