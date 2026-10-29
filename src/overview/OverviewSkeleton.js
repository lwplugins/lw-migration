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
	SkeletonSection,
	SkeletonText,
} from '../components/skeleton';
import { NETWORK } from '../data/boot';

/**
 * The Overview's shapes without the loading region: the ring and headline,
 * the action cards, the chart (as tall as the drawing, which is drawn 1:1
 * at the box's width) and the two columns, in the real classes. The
 * app's first paint (AppSkeleton) and the overview's own load both use it.
 *
 * @param {Object}  props
 * @param {boolean} props.network The page runs on a network (no Connections).
 */
export function OverviewShapes( { network = Boolean( NETWORK ) } ) {
	return (
		<>
			<div className="lw-overview-status">
				<div className="lw-overview-status__ring">
					<SkeletonBlock width="100%" height="100%" round />
				</div>
				<span className="lw-skel-stack">
					<SkeletonText width="70%" size="xl" />
					<SkeletonText width="90%" />
					<SkeletonText width="45%" size="sm" />
				</span>
			</div>
			<div
				className={ `lw-overview-actions ${
					network ? 'has-two' : ''
				}` }
			>
				{ ( network ? [ 0, 1 ] : [ 0, 1, 2 ] ).map( ( key ) => (
					<div key={ key } className="lw-overview-action">
						<span className="lw-skel-stack is-loose">
							<SkeletonText width="45%" size="lg" />
							<SkeletonText lines={ 2 } size="sm" />
							<SkeletonBlock width={ 140 } height={ 40 } />
						</span>
					</div>
				) ) }
			</div>
			<SkeletonSection>
				<div className="lw-overview-chart is-skeleton">
					<SkeletonBlock height="auto" />
				</div>
				<SkeletonText width="50%" size="sm" />
			</SkeletonSection>
			<div className="lw-overview__cols">
				<SkeletonSection>
					<SkeletonText lines={ 4 } />
				</SkeletonSection>
				<div className="lw-overview__stack">
					<SkeletonSection description={ false }>
						<SkeletonText width="40%" size="xl" />
						<SkeletonBlock height={ 14 } width="70%" />
					</SkeletonSection>
					{ ! network && (
						<SkeletonSection description={ false }>
							<SkeletonText lines={ 2 } />
						</SkeletonSection>
					) }
				</div>
			</div>
		</>
	);
}

/**
 * The Overview while GET /overview loads, so nothing jumps when the data
 * arrives.
 *
 * @param {Object}  props
 * @param {boolean} props.network The page runs on a network (no Connections).
 */
export default function OverviewSkeleton( { network = Boolean( NETWORK ) } ) {
	return (
		<SkeletonRegion
			label={ __( 'Loading the overview…', 'lw-migration' ) }
			className="lw-overview"
		>
			<OverviewShapes network={ network } />
		</SkeletonRegion>
	);
}
