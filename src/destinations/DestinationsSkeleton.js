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
import DestinationsLead from './DestinationsLead';

/**
 * The Destinations section's shapes in the real classes: the real lead, two
 * cards (tile, name and line, actions) and the shelf with its three lists
 * (as many rows as the places). The app's first paint and the section's own
 * load both use it.
 */
export function DestinationsShapes() {
	return (
		<div className="lw-destinations-skel">
			<DestinationsLead />
			<div className="lw-destinations">
				{ [ 0, 1 ].map( ( key ) => (
					<div key={ key } className="lw-dest">
						<div className="lw-dest__row">
							<SkeletonBlock width={ 44 } height={ 44 } />
							<span className="lw-skel-stack">
								<SkeletonText width="35%" size="lg" />
								<SkeletonText width="60%" size="sm" />
								<SkeletonText width="45%" size="sm" />
							</span>
							<SkeletonBlock width={ 220 } height={ 32 } />
						</div>
					</div>
				) ) }
			</div>
			<div className="lw-add">
				<span className="lw-skel-stack">
					<SkeletonText width={ 160 } size="lg" />
					<SkeletonText width="50%" size="sm" />
				</span>
				<div className="lw-add__groups">
					{ [
						[ 'oauth', 3 ],
						[ 'keys', 8 ],
						[ 'servers', 3 ],
					].map( ( [ key, rows ] ) => (
						<div
							key={ key }
							className={ `lw-add__group is-${ key }` }
						>
							<SkeletonText width="45%" size="sm" />
							<SkeletonText width="80%" size="sm" />
							<SkeletonBlock height={ rows * 42 } />
						</div>
					) ) }
				</div>
			</div>
		</div>
	);
}

/**
 * The section while its list loads.
 */
export default function DestinationsSkeleton() {
	return (
		<SkeletonRegion
			label={ __( 'Loading the destinations…', 'lw-migration' ) }
		>
			<DestinationsShapes />
		</SkeletonRegion>
	);
}
