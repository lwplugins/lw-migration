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
 * The Backups section's shapes in the real classes: the lead, the storage
 * strip, the chips and search, then the day-grouped list beside the detail
 * panel. The app's first paint and the section's own load both use it.
 */
export function BackupsShapes() {
	return (
		<div className="lw-bk">
			<div className="lw-bk-lead">
				<span className="lw-skel-stack">
					<SkeletonText width={ 220 } size="xl" />
					<SkeletonText width="60%" />
				</span>
			</div>
			<div className="lw-bk-storage">
				<SkeletonText width="40%" size="lg" />
				<SkeletonBlock height={ 12 } round />
				<SkeletonText width="55%" size="sm" />
			</div>
			<div className="lw-bk-tools">
				<SkeletonBlock width={ 360 } height={ 32 } round />
				<SkeletonBlock width={ 240 } height={ 36 } />
			</div>
			<div className="lw-bk-split">
				<div className="lw-bk-list">
					<span className="lw-skel-stack is-loose lw-bk-list__skel">
						{ [ 72, 64, 70, 58, 66 ].map( ( width ) => (
							<SkeletonText
								key={ width }
								width={ `${ width }%` }
							/>
						) ) }
					</span>
				</div>
				<div className="lw-bk-detail">
					<span className="lw-skel-stack is-loose lw-bk-list__skel">
						<SkeletonText width="80%" size="lg" />
						<SkeletonBlock width={ 260 } height={ 40 } />
						<SkeletonText lines={ 4 } />
					</span>
				</div>
			</div>
		</div>
	);
}

/**
 * The section while its list loads.
 */
export default function BackupsSkeleton() {
	return (
		<SkeletonRegion label={ __( 'Loading the backups…', 'lw-migration' ) }>
			<BackupsShapes />
		</SkeletonRegion>
	);
}
