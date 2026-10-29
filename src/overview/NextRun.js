/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import Section from '../components/Section';
import { formatDatetime } from '../data/format';
import {
	failureNote,
	lastRuns,
	listText,
	runTitle,
	runsSummary,
} from './status';

/**
 * The schedule's line: its name, where it uploads, how long it keeps.
 *
 * @param {Object} schedule GET /overview `schedule`.
 * @return {string} Text.
 */
export function scheduleMeta( schedule ) {
	const parts = [
		schedule.destinations.length
			? `${ schedule.name } → ${ listText( schedule.destinations ) }`
			: schedule.name,
	];
	if ( schedule.keep ) {
		parts.push(
			sprintf(
				/* translators: %s: what is kept, e.g. 7 days / the last 7 backups. */
				__( 'Kept: %s', 'lw-migration' ),
				schedule.keep
			)
		);
	}
	return parts.join( ' · ' );
}

/**
 * The next scheduled backup and the last 14 runs as dots; no schedule: what
 * one does and the button that sets it up.
 *
 * @param {Object}      props
 * @param {Object|null} props.schedule GET /overview `schedule`.
 */
export default function NextRun( { schedule } ) {
	if ( ! schedule ) {
		return (
			<Section
				title={ __( 'Next backup', 'lw-migration' ) }
				className="lw-overview-panel"
			>
				<div className="lw-overview-empty">
					<p>
						{ __(
							'No scheduled backup yet. A schedule backs up on its own and keeps only as many backups as you want.',
							'lw-migration'
						) }
					</p>
					<Button
						__next40pxDefaultSize
						variant="secondary"
						href="#schedules"
					>
						{ __( 'Set up a schedule', 'lw-migration' ) }
					</Button>
				</div>
			</Section>
		);
	}
	const runs = lastRuns( schedule.runs );
	const note = failureNote( runs );
	let when;
	if ( schedule.next ) {
		when = formatDatetime( schedule.next );
	} else if ( schedule.enabled ) {
		// On, but WP-Cron has no event for it (the contract allows it).
		when = __( 'Switched on, but no run is planned', 'lw-migration' );
	} else {
		when = __( 'Switched off', 'lw-migration' );
	}
	return (
		<Section
			title={ __( 'Next backup', 'lw-migration' ) }
			className="lw-overview-panel"
			actions={
				<Button variant="link" href="#schedules">
					{ __( 'Open Schedules', 'lw-migration' ) }
				</Button>
			}
		>
			<div className="lw-overview-next">
				<span className="lw-overview-next__when">{ when }</span>
				<span className="lw-overview-list__meta">
					{ scheduleMeta( schedule ) }
				</span>
				{ runs.length > 0 ? (
					<ul
						className="lw-overview-dots"
						aria-label={ runsSummary( runs ) }
					>
						{ runs.map( ( run, index ) => (
							<li
								key={ `${ run.time }-${ index }` }
								className={ `is-${ run.outcome }` }
								title={ runTitle( run ) }
							>
								<span className="screen-reader-text">
									{ runTitle( run ) }
								</span>
							</li>
						) ) }
					</ul>
				) : (
					<span className="lw-overview-list__meta">
						{ __( 'It has not run yet.', 'lw-migration' ) }
					</span>
				) }
				{ note && <p className="lw-overview__foot">{ note }</p> }
			</div>
		</Section>
	);
}
