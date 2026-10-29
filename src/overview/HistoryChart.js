/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import Section from '../components/Section';
import Segmented from '../components/Segmented';
import { formatBytes } from '../data/format';
import { BOX, lastDays, layout, totals } from './chart';

/** Narrower than this the drawing scrolls inside its box. */
const MIN_WIDTH = 520;

/**
 * The width of an element in CSS pixels, followed as it changes.
 *
 * @param {Object} ref A ref to the element.
 * @return {number} Its width (BOX.width until measured).
 */
function useWidth( ref ) {
	const [ width, setWidth ] = useState( BOX.width );
	useEffect( () => {
		const node = ref.current;
		if ( ! node ) {
			return undefined;
		}
		const measure = () => {
			const next = Math.round( node.clientWidth );
			if ( next > 0 ) {
				setWidth( next );
			}
		};
		measure();
		if ( 'undefined' === typeof window.ResizeObserver ) {
			return undefined;
		}
		const observer = new window.ResizeObserver( measure );
		observer.observe( node );
		return () => observer.disconnect();
	}, [ ref ] );
	return width;
}

/**
 * The marks of one day: bars (cloud / local) and crosses (failed runs),
 * each with its own accessible title.
 *
 * @param {Object} props
 * @param {Object} props.mark A layout() mark.
 * @param {number} props.base Baseline y.
 */
function Mark( { mark, base } ) {
	if ( 'failed' === mark.kind ) {
		const half = mark.size / 2;
		const cy = base - half - 3;
		return (
			<g className="lw-overview-chart__failed" role="img">
				<title>{ mark.title }</title>
				<path
					d={ `M${ mark.x - half } ${ cy - half } l${ mark.size } ${
						mark.size
					} m0 ${ -mark.size } l${ -mark.size } ${ mark.size }` }
				/>
			</g>
		);
	}
	return (
		<rect
			className={ `lw-overview-chart__bar is-${ mark.kind }` }
			role="img"
			x={ mark.x }
			y={ mark.y }
			width={ mark.width }
			height={ mark.height }
			rx="2"
		>
			<title>{ mark.title }</title>
		</rect>
	);
}

/**
 * The backups of the last 14 or 30 days (site days, from the server): bars
 * to scale with a byte axis, cloud / local / failed, a title on each.
 *
 * @param {Object}   props
 * @param {Object[]} props.history GET /overview `history`.
 */
export default function HistoryChart( { history } ) {
	const [ days, setDays ] = useState( '14' );
	const box = useRef( null );
	const width = Math.max( MIN_WIDTH, useWidth( box ) );
	const entries = lastDays( history, Number( days ) );
	const sum = totals( entries );
	const drawing = layout( entries, undefined, width );
	const empty = 0 === sum.count && 0 === sum.failed;

	const sub = sprintf(
		/* translators: 1: number of days; 2: number of backups; 3: their size, e.g. 22.7 GB. */
		_n(
			'Last %1$d days · %2$d backup · %3$s',
			'Last %1$d days · %2$d backups · %3$s',
			sum.count,
			'lw-migration'
		),
		Number( days ),
		sum.count,
		formatBytes( sum.bytes )
	);

	return (
		<Section
			title={ __( 'Backups', 'lw-migration' ) }
			description={ sub }
			className="lw-overview-history"
			actions={
				<div className="lw-overview-history__range">
					<Segmented
						label={ __( 'Period', 'lw-migration' ) }
						options={ {
							14: __( '14 days', 'lw-migration' ),
							30: __( '30 days', 'lw-migration' ),
						} }
						value={ days }
						onChange={ ( value ) => setDays( String( value ) ) }
					/>
				</div>
			}
		>
			{ empty ? (
				<div className="lw-overview-empty">
					<p>
						{ __(
							'No backup in these days. A schedule backs the site up on its own, every day if you like.',
							'lw-migration'
						) }
					</p>
					<Button
						__next40pxDefaultSize
						variant="secondary"
						href="#schedules"
					>
						{ __( 'Set up a schedule', 'lw-migration' ) }
					</Button>
				</div>
			) : (
				<>
					<div className="lw-overview-chart" ref={ box }>
						<svg
							width={ width }
							height={ BOX.height }
							viewBox={ `0 0 ${ width } ${ BOX.height }` }
							role="group"
							aria-label={ __(
								'Backup sizes per day',
								'lw-migration'
							) }
						>
							{ drawing.grid.map( ( line ) => (
								<g key={ line.label } aria-hidden="true">
									<line
										className="lw-overview-chart__grid"
										x1={ BOX.left }
										x2={ width - BOX.right }
										y1={ line.y }
										y2={ line.y }
									/>
									<text
										className="lw-overview-chart__axis"
										x={ BOX.left - 8 }
										y={ line.y + 4 }
										textAnchor="end"
									>
										{ line.label }
									</text>
								</g>
							) ) }
							{ drawing.days.map( ( day ) => (
								<g key={ day.day }>
									{ day.marks.map( ( mark, index ) => (
										<Mark
											key={ index }
											mark={ mark }
											base={ drawing.base }
										/>
									) ) }
									{ day.label && (
										<text
											className="lw-overview-chart__day"
											x={ day.x }
											y={ BOX.height - 10 }
											textAnchor="middle"
											aria-hidden="true"
										>
											{ day.label }
										</text>
									) }
								</g>
							) ) }
							<line
								className="lw-overview-chart__base"
								x1={ BOX.left }
								x2={ width - BOX.right }
								y1={ drawing.base }
								y2={ drawing.base }
								aria-hidden="true"
							/>
						</svg>
					</div>
					<ul className="lw-overview-legend">
						<li>
							<i className="is-cloud" aria-hidden="true" />
							{ __(
								'Uploaded to the cloud too',
								'lw-migration'
							) }
						</li>
						<li>
							<i className="is-local" aria-hidden="true" />
							{ __( 'Only on this server', 'lw-migration' ) }
						</li>
						<li>
							<i className="is-failed" aria-hidden="true" />
							{ __( 'Failed', 'lw-migration' ) }
						</li>
					</ul>
				</>
			) }
		</Section>
	);
}
