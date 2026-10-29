/**
 * WordPress dependencies
 */
import { __, _x } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { Bolt } from './icons';
import { isFinished, percentOf, phaseIndex, phasesFor } from './phases';

/**
 * State of each phase: done before the current one, the current one while
 * the job has not ended, the rest still to come.
 *
 * @param {Object} job Job view.
 * @return {Array<{id: string, label: string, state: string}>} Phases.
 */
export function trackPhases( job ) {
	const at = phaseIndex( job );
	const finished = isFinished( job );
	return phasesFor( job.type, job.steps ).map( ( phase, index ) => {
		let state = 'next';
		if ( index < at ) {
			state = 'done';
		} else if ( index === at && ! finished ) {
			state = 'current';
		}
		return { id: phase.id, label: phase.label, state };
	} );
}

/**
 * The job card's figures (decision 6): the large percentage beside what is
 * happening now, and the "power line" under them, one segment per phase. A
 * finished segment is solid with the bolt at its label, the current one
 * carries a flow with the bolt riding it and a "now" pill (still and
 * "stopped" while the job stands), the rest are the bare rail.
 *
 * @param {Object}      props
 * @param {Object}      props.job      Job view.
 * @param {number|null} props.uploaded Upload percentage while a file is being sent.
 * @param {string}      props.message  What is happening now (empty: nothing shown).
 * @param {boolean}     props.stalled  The job stands still.
 */
export default function JobTrack( {
	job,
	uploaded = null,
	message = '',
	stalled = false,
} ) {
	const percent = null !== uploaded ? uploaded : percentOf( job );
	const phases = trackPhases( job );
	// Room for the current phase's own line on a phone.
	const hasCurrent = phases.some( ( phase ) => 'current' === phase.state );
	const classes = [ 'lw-track', `is-${ job.status }` ];
	if ( stalled ) {
		classes.push( 'is-stalled' );
	}

	return (
		<div className={ classes.join( ' ' ) }>
			<div className="lw-track__now">
				<div
					className="lw-track__figure"
					role="progressbar"
					aria-valuemin={ 0 }
					aria-valuemax={ 100 }
					aria-valuenow={ percent }
					aria-label={ __( 'Progress', 'lw-migration' ) }
				>
					<span className="lw-track__percent">{ percent }</span>
					<span className="lw-track__unit" aria-hidden="true">
						%
					</span>
				</div>
				{ message && <p className="lw-job__message">{ message }</p> }
			</div>
			<ol
				className={
					hasCurrent
						? 'lw-track__phases has-current'
						: 'lw-track__phases'
				}
			>
				{ phases.map( ( { id, label, state } ) => (
					<li
						key={ id }
						className={ `is-${ state }` }
						aria-current={
							'current' === state ? 'step' : undefined
						}
					>
						<span className="lw-track__seg" aria-hidden="true">
							<span className="lw-track__flow" />
							{ 'current' === state && (
								<span className="lw-track__spark">
									<Bolt />
								</span>
							) }
						</span>
						<span className="lw-track__label">
							{ 'done' === state && (
								<Bolt className="lw-track__bolt" />
							) }
							<span className="lw-track__name">{ label }</span>
							{ 'done' === state && (
								<span className="screen-reader-text">
									{ ' ' }
									{ __( '(done)', 'lw-migration' ) }
								</span>
							) }
							{ 'current' === state && (
								<span className="lw-track__pill">
									{ stalled
										? _x(
												'stopped',
												'the job phase that stands still',
												'lw-migration'
											)
										: _x(
												'now',
												'the job phase running now',
												'lw-migration'
											) }
								</span>
							) }
						</span>
					</li>
				) ) }
			</ol>
		</div>
	);
}
