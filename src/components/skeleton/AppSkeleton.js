/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
	SkeletonBlock,
	SkeletonFields,
	SkeletonRegion,
	SkeletonSection,
	SkeletonText,
} from '.';
import { BackupsShapes } from '../../backups/BackupsSkeleton';
import { NETWORK } from '../../data/boot';
import { DestinationsShapes } from '../../destinations/DestinationsSkeleton';
import { CompositionShapes } from '../../export/CompositionBar';
import { RestoreShapes } from '../../import/RestoreSkeleton';
import { OverviewShapes } from '../../overview/OverviewSkeleton';
import { ResetShapes } from '../../reset/ResetSkeleton';

/**
 * The Backup section's opening shapes: the lead, on a network the "Which
 * sites" card (its whole-network / chosen-sites switch), the composition bar
 * and the first card of choices.
 *
 * @param {Object}  props
 * @param {boolean} props.network The page runs on a network.
 */
function BackupShapes( { network } ) {
	return (
		<div className="lw-export">
			<div className="lw-export__lead">
				<span className="lw-skel-stack">
					<SkeletonText width={ 320 } size="xl" />
					<SkeletonText width="70%" />
				</span>
			</div>
			{ network && (
				<SkeletonSection>
					<SkeletonBlock width={ 280 } height={ 40 } />
				</SkeletonSection>
			) }
			<div className="lw-composition">
				<CompositionShapes />
			</div>
			<SkeletonSection>
				<SkeletonFields count={ 4 } />
			</SkeletonSection>
		</div>
	);
}

/**
 * First paint, before the active job is known: the same sidebar + top bar as
 * the real screen and the section the page opens on (the Overview's, the
 * Backup, Restore, Backups, Destinations or Reset section's shapes there,
 * else a hero + card), so nothing jumps.
 *
 * @param {Object}  props
 * @param {string}  props.tab     The section the page opens on.
 * @param {boolean} props.network The page runs on a network (default: the boot data).
 */
export default function AppSkeleton( { tab, network = Boolean( NETWORK ) } ) {
	return (
		<SkeletonRegion
			label={ __( 'Loading…', 'lw-migration' ) }
			className="lw-admin-shell"
		>
			<aside className="lw-admin-sidebar">
				<div className="lw-admin-sidebar__head">
					<SkeletonBlock width={ 26 } height={ 26 } />
					<SkeletonText width={ 120 } size="lg" />
				</div>
				<span className="lw-skel-stack lw-skel-nav">
					{ [ 70, 64, 76, 58 ].map( ( width ) => (
						<SkeletonText key={ width } width={ `${ width }%` } />
					) ) }
				</span>
			</aside>
			<div className="lw-admin-main">
				<div className="lw-admin-topbar">
					<SkeletonText width={ 220 } size="lg" />
				</div>
				<div className="lw-admin-scroll">
					<div className="lw-admin-content">
						{ 'overview' === tab && (
							<div className="lw-overview">
								<OverviewShapes network={ network } />
							</div>
						) }
						{ 'export' === tab && (
							<BackupShapes network={ network } />
						) }
						{ 'import' === tab && <RestoreShapes /> }
						{ 'backups' === tab && <BackupsShapes /> }
						{ 'destinations' === tab && <DestinationsShapes /> }
						{ 'reset' === tab && <ResetShapes /> }
						{ ! [
							'overview',
							'export',
							'import',
							'backups',
							'destinations',
							'reset',
						].includes( tab ) && (
							<>
								<div className="lw-hero">
									<span className="lw-skel-stack">
										<SkeletonText width={ 320 } size="xl" />
										<SkeletonText width="70%" />
									</span>
									<SkeletonBlock
										width={ 160 }
										height={ 48 }
									/>
								</div>
								<SkeletonSection>
									<SkeletonBlock height={ 44 } />
								</SkeletonSection>
							</>
						) }
					</div>
				</div>
			</div>
		</SkeletonRegion>
	);
}
