/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
	SkeletonBlock,
	SkeletonRegion,
	SkeletonText,
} from '../components/skeleton';
import { formatBytes } from '../data/format';
import { NETWORK } from '../data/boot';

/** Legend names of the bar's segments. */
const NAMES = {
	media: __( 'Media library', 'lw-migration' ),
	database: __( 'Database', 'lw-migration' ),
	plugins: __( 'Plugins', 'lw-migration' ),
	themes: __( 'Themes', 'lw-migration' ),
	other: __( 'Other (must-use, wp-content)', 'lw-migration' ),
};

/**
 * What a legend entry says after the name.
 *
 * @param {Object} s      composition() segment.
 * @param {string} status estimateStatus().
 * @return {string} Size ("at least" while a first measuring still grows it),
 *                  "left out", "counting…" or "could not be counted".
 */
function legendValue( s, status ) {
	if ( s.off ) {
		return __( 'left out', 'lw-migration' );
	}
	if (
		'failed' === s.state ||
		( null === s.bytes && 'stopped' === status )
	) {
		return __( 'could not be counted', 'lw-migration' );
	}
	if ( null === s.bytes ) {
		return __( 'counting…', 'lw-migration' );
	}
	return 'growing' === s.state
		? sprintf(
				/* translators: %s: size counted so far, e.g. 1.2 GB. */
				__( 'at least %s', 'lw-migration' ),
				formatBytes( s.bytes )
			)
		: formatBytes( s.bytes );
}

/**
 * The total once counting has ended ("at least" when a part is missing).
 *
 * @param {Object} comp composition() result.
 * @return {string} Text.
 */
const totalText = ( comp ) =>
	comp.missing
		? sprintf(
				/* translators: %s: size counted so far, e.g. 1.2 GB. */
				__( 'at least %s', 'lw-migration' ),
				formatBytes( comp.total )
			)
		: formatBytes( comp.total );

/**
 * The line beside the total: how far the figures can be trusted.
 *
 * @param {string} status estimateStatus().
 * @param {Object} comp   composition() result.
 * @return {string} Text.
 */
function statusText( status, comp ) {
	if ( 'stopped' === status ) {
		return __(
			'Counting stopped: these are the sizes measured so far.',
			'lw-migration'
		);
	}
	if ( 'counting' === status ) {
		return __(
			'The total follows when every part is counted; the parts counted so far are below.',
			'lw-migration'
		);
	}
	return comp.failed
		? __( 'Approximate; some parts could not be counted.', 'lw-migration' )
		: __( 'Approximate', 'lw-migration' );
}

/**
 * The notes under the legend: whose figures they are on a network, and what
 * they leave out.
 *
 * @param {Object}  props
 * @param {?Object} props.estimate Estimate.
 * @param {boolean} props.byName   The Advanced section leaves something out by name.
 * @param {boolean} props.chosen   On a network, only chosen sites are backed up.
 */
function Notes( { estimate, byName, chosen } ) {
	const notes = [];
	if ( 'network' === estimate?.scope ) {
		notes.push(
			chosen
				? __(
						'The sizes are for the whole network, not only the chosen sites.',
						'lw-migration'
					)
				: __( 'The sizes are for the whole network.', 'lw-migration' )
		);
	}
	if ( byName ) {
		notes.push(
			__(
				'What the Advanced section leaves out by name is not taken off.',
				'lw-migration'
			)
		);
	}
	return notes.length ? (
		<p className="lw-composition__note">{ notes.join( ' ' ) }</p>
	) : null;
}

/**
 * The composition bar's loading shapes: total, bar, legend (the same box, so
 * nothing jumps when the sizes arrive). Also drawn on the page's first paint.
 */
export function CompositionShapes() {
	return (
		<>
			<div className="lw-composition__head">
				<SkeletonText width={ 140 } size="xl" />
			</div>
			<SkeletonBlock height={ 14 } round />
			<div className="lw-composition__keys">
				{ [ 110, 96, 104, 80, 150 ].map( ( width ) => (
					<SkeletonText key={ width } width={ width } size="sm" />
				) ) }
			</div>
		</>
	);
}

/**
 * The composition bar while the first sizes are measured.
 */
function CompositionSkeleton() {
	return (
		<SkeletonRegion
			label={ __( 'Measuring the backup…', 'lw-migration' ) }
			className="lw-composition"
		>
			<CompositionShapes />
		</SkeletonRegion>
	);
}

/**
 * What goes into the backup, to scale: the total, a bar of the five parts
 * (those left out stay as a faint sliver) and a legend with each size. It
 * follows the choices below it at once; the sizes come from the estimate,
 * which may still be counting.
 *
 * @param {Object}  props
 * @param {string}  props.status      estimateStatus().
 * @param {?Object} props.estimate    Estimate.
 * @param {Object}  props.comp        composition() result.
 * @param {string}  props.compression 'none', 'gzip' or 'bzip2'.
 * @param {boolean} props.byName      The Advanced section leaves something out by name.
 * @param {boolean} props.chosen      On a network, only chosen sites are backed up.
 */
export default function CompositionBar( {
	status,
	estimate,
	comp,
	compression,
	byName,
	chosen,
} ) {
	if ( 'loading' === status || ( 'counting' === status && ! comp.known ) ) {
		return <CompositionSkeleton />;
	}
	if ( ! comp.known ) {
		return (
			<section className="lw-composition is-unavailable">
				<p className="lw-composition__note">
					{ __(
						'The sizes could not be measured now. The backup can still be started.',
						'lw-migration'
					) }
				</p>
			</section>
		);
	}

	const largest = Math.max( 1, ...comp.segments.map( ( s ) => s.full || 0 ) );
	let lead = __(
		'goes into the backup, without compression',
		'lw-migration'
	);
	if ( 'none' !== compression ) {
		lead = sprintf(
			/* translators: %s: compression method, gzip or bzip2. */
			__(
				'goes into the backup before compression; the %s file will be smaller',
				'lw-migration'
			),
			compression
		);
	}

	return (
		<section
			className="lw-composition"
			aria-labelledby="lw-composition-head"
			aria-busy={ 'counting' === status ? true : undefined }
		>
			<div className="lw-composition__head" id="lw-composition-head">
				{ /* No total while counting: a figure that changes a few seconds later reads as a wrong one. */ }
				<span className="lw-composition__total">
					{ 'counting' === status
						? __( 'Calculating…', 'lw-migration' )
						: totalText( comp ) }
				</span>
				{ 'counting' !== status && (
					<span className="lw-composition__lead">{ lead }</span>
				) }
				<span className="lw-composition__status">
					{ statusText( status, comp ) }
				</span>
			</div>
			<div className="lw-composition__bar" aria-hidden="true">
				{ comp.segments
					.filter( ( s ) => null !== s.full )
					.map( ( s ) => (
						<span
							key={ s.key }
							className={ `lw-seg lw-seg--${ s.key } ${
								s.off ? 'is-off' : ''
							}` }
							// A left-out part keeps a sliver, so the bar still shows it.
							style={ {
								flexGrow: s.off
									? ( s.full || largest ) * 0.02
									: s.bytes,
							} }
						/>
					) ) }
			</div>
			<ul className="lw-composition__keys">
				{ comp.segments.map( ( s ) => (
					<li key={ s.key } className={ s.off ? 'is-off' : '' }>
						<i
							className={ `lw-seg lw-seg--${ s.key }` }
							aria-hidden="true"
						/>
						{ `${ NAMES[ s.key ] } ${ legendValue( s, status ) }` }
					</li>
				) ) }
			</ul>
			<Notes
				estimate={ estimate }
				byName={ byName }
				chosen={ Boolean( NETWORK ) && chosen }
			/>
		</section>
	);
}
