/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { percentOf, phaseIndex, phasesFor } from '../job/phases';

/**
 * What a running schedule's card says about its job: the percentage, when
 * it started and the phase it is in. The site runs one job at a time, so a
 * schedule marked `running` while the page drives a backup job owns it.
 *
 * @param {Object}                 job   Job view (an unfinished export).
 * @param {(ts: number) => string} write runWriter().
 * @return {{percent: number, line: string}} Figures.
 */
export function runningState( job, write ) {
	const phases = phasesFor( job.type, job.steps );
	const phase = phases[ phaseIndex( job ) ]?.label || '';
	const started = sprintf(
		/* translators: %s: when the run started, e.g. today 15:12. */
		__( 'Started %s', 'lw-migration' ),
		write( job.createdAt )
	);
	return {
		percent: percentOf( job ),
		line: phase ? `${ started } · ${ phase }` : started,
	};
}

/**
 * The bar of a running schedule.
 *
 * @param {Object}                 props
 * @param {Object}                 props.job   Job view.
 * @param {(ts: number) => string} props.write runWriter().
 */
export default function RunProgress( { job, write } ) {
	const { percent, line } = runningState( job, write );
	return (
		<div className="lw-sched__progress">
			<div
				className="lw-sched__bar"
				role="progressbar"
				aria-valuemin={ 0 }
				aria-valuemax={ 100 }
				aria-valuenow={ percent }
				aria-label={ __( 'Progress', 'lw-migration' ) }
			>
				<span style={ { width: `${ percent }%` } } />
			</div>
			<p className="lw-fact__sub">{ line }</p>
		</div>
	);
}
