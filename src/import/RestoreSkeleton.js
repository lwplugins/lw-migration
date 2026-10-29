/**
 * Internal dependencies
 */
import {
	SkeletonBlock,
	SkeletonSection,
	SkeletonText,
} from '../components/skeleton';

/**
 * The Restore section's opening shapes: the lead, the steps bar (a short
 * line in its text row and the bar itself, in StepRail's box, so nothing
 * moves when it appears), and the source card with its four tiles and the
 * first rows of the backups list.
 */
export function RestoreShapes() {
	return (
		<div className="lw-restore">
			<div className="lw-restore__lead">
				<span className="lw-skel-stack">
					<SkeletonText width={ 320 } size="xl" />
					<SkeletonText width="70%" />
				</span>
			</div>
			<div className="lw-steps">
				<span className="lw-steps__text">
					<SkeletonText width={ 150 } />
				</span>
				<SkeletonBlock height={ 5 } round />
			</div>
			<SkeletonSection description={ false }>
				<span className="lw-sources">
					{ [ 1, 2, 3, 4 ].map( ( key ) => (
						<SkeletonBlock key={ key } height={ 118 } />
					) ) }
				</span>
				<SkeletonBlock height={ 64 } />
				<SkeletonBlock height={ 64 } />
			</SkeletonSection>
		</div>
	);
}
