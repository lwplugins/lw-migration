/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { SkeletonRegion, SkeletonText } from '../components/skeleton';
import { formatBytes } from '../data/format';
import ProblemNotice, { describeProblem } from '../views/ProblemNotice';
import { contentRows, countText, partTitle, readsOn } from './contents';
import DetailSection from './DetailSection';

/**
 * Part rows while the archive is read: the real list's shape.
 *
 * @param {Object}  props
 * @param {boolean} props.reading A read is under way (an unfinished answer came).
 */
function ContentsSkeleton( { reading } ) {
	return (
		<SkeletonRegion label={ __( 'Reading the archive…', 'lw-migration' ) }>
			<ul className="lw-bk-parts" aria-hidden="true">
				{ [ 70, 55, 45 ].map( ( width ) => (
					<li key={ width }>
						<SkeletonText width={ `${ width }%` } />
						<SkeletonText width="56px" />
					</li>
				) ) }
			</ul>
			{ reading && (
				<p className="lw-bk-detail__note" aria-hidden="true">
					{ __( 'Still reading the archive…', 'lw-migration' ) }
				</p>
			) }
		</SkeletonRegion>
	);
}

/**
 * What the chosen backup holds, each part to scale; "Files" opens the full
 * list of entries.
 *
 * @param {Object}      props
 * @param {Object|null} props.answer   Body of `GET /backups/contents`.
 * @param {Object|null} props.error    Its refusal.
 * @param {() => void}  props.onBrowse Opens the entries.
 * @param {() => void}  props.onRetry  Asks for the contents again after a failure.
 */
export default function DetailContents( { answer, error, onBrowse, onRetry } ) {
	let body;
	if ( error ) {
		body = (
			<>
				<ProblemNotice problem={ describeProblem( error ) } />
				<Button variant="link" onClick={ onRetry }>
					{ __( 'Try again', 'lw-migration' ) }
				</Button>
			</>
		);
	} else if ( ! answer || readsOn( answer ) ) {
		body = <ContentsSkeleton reading={ Boolean( answer ) } />;
	} else {
		const { rows, absent } = contentRows( answer );
		body = (
			<>
				<ul className="lw-bk-parts">
					{ rows.map( ( row ) => (
						<li key={ row.id }>
							<span>
								{ [
									partTitle( row.id ),
									countText( row.id, row.count ),
								]
									.filter( Boolean )
									.join( ' · ' ) }
							</span>
							<span className="lw-bk-parts__size">
								{ null === row.bytes
									? __( 'unknown', 'lw-migration' )
									: formatBytes( row.bytes ) }
							</span>
							<span
								className="lw-bk-parts__bar"
								aria-hidden="true"
							>
								<span
									style={ {
										width: `${ Math.round( row.share * 100 ) }%`,
									} }
								/>
							</span>
						</li>
					) ) }
				</ul>
				{ absent.length > 0 && (
					<p className="lw-bk-detail__note">
						{ sprintf(
							/* translators: %s: list of parts, e.g. "Themes, Must-use plugins". */
							__( 'Not in this backup: %s', 'lw-migration' ),
							absent.map( partTitle ).join( ', ' )
						) }
					</p>
				) }
				{ null === ( answer.expandedSize ?? null ) && (
					<p className="lw-bk-detail__note">
						{ __(
							'Sizes as stored in the archive.',
							'lw-migration'
						) }
					</p>
				) }
			</>
		);
	}
	return (
		<DetailSection
			title={ __( 'Contents', 'lw-migration' ) }
			action={
				<Button variant="link" onClick={ onBrowse }>
					{ __( 'Files', 'lw-migration' ) }
				</Button>
			}
		>
			{ body }
		</DetailSection>
	);
}
