/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
	SkeletonBlock,
	SkeletonRegion,
	SkeletonText,
} from '../components/skeleton';
import ResetLead from './ResetLead';
import { TYPES } from './reset';

/**
 * The Reset section's shapes in the real classes: the real lead, the warning, the
 * heading with the five choice tiles, and the two-column plan panel. The
 * app's first paint and the section's own load both use it.
 */
export function ResetShapes() {
	return (
		<div className="lw-reset">
			<ResetLead />
			<div className="lw-reset-lead">
				<SkeletonBlock height={ 64 } />
			</div>
			<div className="lw-reset-pick">
				<SkeletonText width={ 220 } size="lg" />
				<div className="lw-reset-choices">
					{ TYPES.map( ( type ) => (
						<span
							key={ type }
							className="lw-reset-choice is-loading"
						>
							<SkeletonBlock width={ 24 } height={ 24 } />
							<SkeletonText width="80%" />
							<SkeletonText width="50%" size="sm" />
						</span>
					) ) }
				</div>
			</div>
			<div className="lw-reset-plan">
				{ [ 0, 1 ].map( ( key ) => (
					<div key={ key } className="lw-reset-plan__part">
						<SkeletonText width="45%" size="lg" />
						{ [ 0, 1, 2, 3 ].map( ( row ) => (
							<SkeletonBlock key={ row } height={ 52 } />
						) ) }
					</div>
				) ) }
			</div>
		</div>
	);
}

/**
 * The section while its summary loads.
 */
export default function ResetSkeleton() {
	return (
		<SkeletonRegion
			label={ __( 'Loading what each reset removes…', 'lw-migration' ) }
		>
			<ResetShapes />
		</SkeletonRegion>
	);
}
