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

/**
 * A card's shapes: name and switch, the timing, three fact columns, the
 * action row.
 */
function CardShapes() {
	return (
		<div className="lw-sched">
			<div className="lw-sched__head">
				<span className="lw-skel-stack">
					<SkeletonText width={ 200 } size="lg" />
					<SkeletonText width={ 240 } />
				</span>
				<SkeletonBlock width={ 120 } height={ 24 } round />
			</div>
			<div className="lw-sched__facts">
				{ [ 0, 1, 2 ].map( ( column ) => (
					<div key={ column } className="lw-fact">
						<span className="lw-skel-stack">
							<SkeletonText width="40%" size="sm" />
							<SkeletonText width="70%" size="lg" />
							<SkeletonText width="85%" />
						</span>
					</div>
				) ) }
			</div>
			<div className="lw-sched__foot">
				<SkeletonBlock width={ 130 } height={ 40 } />
				<SkeletonBlock width={ 90 } height={ 40 } />
				<SkeletonBlock width={ 100 } height={ 40 } />
			</div>
		</div>
	);
}

/**
 * Loading: the lead, the week ruler with two lanes, two cards — the layout
 * of the loaded section, so nothing jumps.
 *
 * @param {Object}  props
 * @param {Element} props.lead The real lead (its button is not shown yet).
 */
export default function SchedulesSkeleton( { lead } ) {
	return (
		<SkeletonRegion
			label={ __( 'Loading schedules…', 'lw-migration' ) }
			className="lw-scheds"
		>
			{ lead }
			<div className="lw-week">
				<SkeletonText width={ 180 } size="lg" />
				<SkeletonBlock height={ 18 } />
				<SkeletonBlock height={ 30 } />
				<SkeletonBlock height={ 30 } />
			</div>
			<CardShapes />
			<CardShapes />
		</SkeletonRegion>
	);
}
