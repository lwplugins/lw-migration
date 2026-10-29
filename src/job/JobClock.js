/**
 * WordPress dependencies
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { elapsedSeconds, formatElapsed, jobTime } from './clock';

const nowSeconds = () => Date.now() / 1000;

/**
 * "Started 15:20 · 2 min 14 s": the job card's clock. It ticks once a
 * second by itself (only this element renders again), and stands still at
 * `stoppedAt` while the job does not move.
 *
 * @param {Object} props
 * @param {number} props.createdAt Unix seconds the job started.
 * @param {number} props.stoppedAt Unix seconds of its last progress while it stands still, 0 while it runs.
 */
export default function JobClock( { createdAt, stoppedAt = 0 } ) {
	const ticking = ! stoppedAt;
	const [ now, setNow ] = useState( nowSeconds );
	useEffect( () => {
		if ( ! ticking ) {
			return undefined;
		}
		setNow( nowSeconds() );
		const timer = setInterval( () => setNow( nowSeconds() ), 1000 );
		return () => clearInterval( timer );
	}, [ ticking ] );

	const elapsed = elapsedSeconds( createdAt, ticking ? now : stoppedAt );
	if ( null === elapsed ) {
		return null;
	}
	return (
		<p className="lw-job__clock">
			{ sprintf(
				/* translators: %s: when the job started, e.g. 15:20. */
				__( 'Started %s', 'lw-migration' ),
				jobTime( createdAt, now )
			) }
			<span aria-hidden="true"> · </span>
			<span className="lw-job__elapsed">
				{ formatElapsed( elapsed ) }
			</span>
		</p>
	);
}
