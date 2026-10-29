/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { NETWORK } from '../data/boot';
import StepRail from './StepRail';

/**
 * The Restore section's head on every step: the title, what the section
 * does, and the step rail.
 *
 * @param {Object} props
 * @param {number} props.step The rail's current step (StepRail).
 */
export default function RestoreLead( { step } ) {
	return (
		<>
			<section className="lw-restore__lead">
				<h2 className="lw-restore__title">
					{ NETWORK
						? __( 'Restore or move sites', 'lw-migration' )
						: __( 'Restore or move a site', 'lw-migration' ) }
				</h2>
				<p className="lw-restore__intro">
					{ NETWORK
						? __(
								'Restore this network from a .wpress backup, or bring sites of another WordPress here. Before anything changes you see what is replaced, and by default a safety backup is made first.',
								'lw-migration'
							)
						: __(
								'Restore this site from a .wpress backup, or move another WordPress here. Before anything changes you see what is replaced, and by default a safety backup is made first.',
								'lw-migration'
							) }
				</p>
			</section>
			<StepRail current={ step } />
		</>
	);
}
