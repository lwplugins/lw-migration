/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Icon, caution } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { listText } from '../overview/status';
import WeekLane from './WeekLane';
import { dayLabels, runWriter } from './when';
import { firstClash, laneOf, position, weekStarts } from './week';

/**
 * The section's own element: the next 7 days with one lane per schedule,
 * every run of the server's `week` a mark on it, and a line at "now". A
 * mark shows its time on hover, and below the ruler when focused or
 * tapped (one tab stop per lane, arrow keys between its marks). Two
 * schedules due in the same minute get a note: they run one after the other.
 *
 * @param {Object}   props
 * @param {Object[]} props.items    Schedule items.
 * @param {string}   props.timezone The site's time zone.
 * @param {number}   props.now      Unix seconds.
 */
export default function WeekRuler( { items, timezone, now } ) {
	// The readout shows the last mark focused or tapped; only a tap is
	// announced (a focused mark has already read its own label).
	const [ picked, setPicked ] = useState( { label: '', announce: false } );
	const starts = weekStarts( now, timezone );
	const days = dayLabels( starts, timezone );
	const write = runWriter( now, timezone );
	const nowAt = position( now, starts ) ?? 0;
	const clash = firstClash( items );

	return (
		<section className="lw-week" aria-labelledby="lw-week-title">
			<header className="lw-week__head">
				<h3 id="lw-week-title" className="lw-week__title">
					{ __( 'The next 7 days', 'lw-migration' ) }
				</h3>
				<p className="lw-week__hint">
					{ __(
						'Every mark is a run. Point at one, tap it, or reach it with Tab and the arrow keys, for its time.',
						'lw-migration'
					) }
				</p>
			</header>
			<div className="lw-week__scroll">
				<div className="lw-week__ruler">
					<div className="lw-week__days" aria-hidden="true">
						{ days.map( ( label, index ) => (
							<span
								key={ starts[ index ] }
								className={ 0 === index ? 'is-today' : '' }
							>
								{ label }
							</span>
						) ) }
					</div>
					{ items.map( ( item, index ) => (
						<div
							key={ item.id }
							className={ `lw-week__row lw-lane-${ laneOf(
								index
							) } ${ item.enabled ? '' : 'is-off' }` }
						>
							<span className="lw-week__name">
								<i className="lw-swatch" aria-hidden="true" />
								<span>{ item.title }</span>
							</span>
							{ item.enabled ? (
								<WeekLane
									item={ item }
									starts={ starts }
									nowAt={ nowAt }
									write={ write }
									onPick={ ( label, announce ) =>
										setPicked( { label, announce } )
									}
								/>
							) : (
								<div className="lw-week__lane is-off">
									{ __(
										'switched off, runs only when you start it',
										'lw-migration'
									) }
								</div>
							) }
						</div>
					) ) }
					<div className="lw-week__nowlabel" aria-hidden="true">
						<span style={ { left: `${ nowAt }%` } }>
							{ __( 'now', 'lw-migration' ) }
						</span>
					</div>
				</div>
			</div>
			<p className="lw-week__picked" aria-hidden="true">
				{ picked.label }
			</p>
			<span className="screen-reader-text" aria-live="polite">
				{ picked.announce ? picked.label : '' }
			</span>
			{ clash && (
				<p className="lw-week__clash">
					<Icon icon={ caution } size={ 20 } />
					<span>
						{ sprintf(
							/* translators: 1: when, e.g. tomorrow 03:00; 2: list of schedule names. */
							__(
								'%2$s are due in the same minute, %1$s. They do not run at once: the second waits until the first is done.',
								'lw-migration'
							),
							write( clash.at ),
							listText( clash.names )
						) }
					</span>
				</p>
			) }
		</section>
	);
}
