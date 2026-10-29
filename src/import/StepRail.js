/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { STEPS } from './steps';

/**
 * Source → Check → Restore as one thin bar: the current step's name and
 * where it is ("Step 2 of 3"), and a segment per step, filled up to the
 * current one. 3 = every step done: the last name with "Done", all filled.
 * The text carries the meaning; the segments are only its picture.
 *
 * @param {Object} props
 * @param {number} props.current 0 (Source), 1 (Check), 2 (Restore) or 3.
 */
export default function StepRail( { current } ) {
	const finished = current >= STEPS.length;
	const shown = finished ? STEPS.length - 1 : current;
	return (
		<div
			className="lw-steps"
			role="group"
			aria-label={ __( 'Restore steps', 'lw-migration' ) }
		>
			<p className="lw-steps__text">
				<strong
					className="lw-steps__name"
					aria-current={ finished ? undefined : 'step' }
				>
					{ STEPS[ shown ] }
				</strong>
				<span className="lw-steps__count">
					{ finished
						? __( 'Done', 'lw-migration' )
						: sprintf(
								/* translators: 1: number of the current step, 2: number of steps. */
								__( 'Step %1$d of %2$d', 'lw-migration' ),
								current + 1,
								STEPS.length
							) }
				</span>
			</p>
			<span className="lw-steps__bar" aria-hidden="true">
				{ STEPS.map( ( label, index ) => (
					<span
						key={ label }
						className={
							index <= current
								? 'lw-steps__segment is-filled'
								: 'lw-steps__segment'
						}
					/>
				) ) }
			</span>
		</div>
	);
}
