/**
 * WordPress dependencies
 */
import { useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { nextMark, position } from './week';

/**
 * What a lane says to a screen reader: the schedule and the runs it draws
 * (the server's `week` reaches now + 7 days, the ruler ends at midnight).
 *
 * @param {Object}                 item  Schedule item.
 * @param {number[]}               runs  The runs drawn on the ruler.
 * @param {(ts: number) => string} write runWriter().
 * @return {string} Text.
 */
export function laneSummary( item, runs, write ) {
	if ( ! runs.length ) {
		return sprintf(
			/* translators: %s: schedule name. */
			__( '%s: no run in the next 7 days.', 'lw-migration' ),
			item.title
		);
	}
	return sprintf(
		/* translators: 1: schedule name; 2: number of runs; 3: the first, e.g. tomorrow 03:00. */
		_n(
			'%1$s: %2$d run in the next 7 days, the first %3$s.',
			'%1$s: %2$d runs in the next 7 days, the first %3$s.',
			runs.length,
			'lw-migration'
		),
		item.title,
		runs.length,
		write( runs[ 0 ] )
	);
}

/**
 * One switched-on schedule's lane: a mark per run, one tab stop per lane
 * (roving focus: the arrow keys, Home and End move between the marks).
 * Focusing or tapping a mark reports "Name: when" to the ruler: a tap asks
 * for it to be announced, focus does not (the mark's own label is read).
 *
 * @param {Object}                                     props
 * @param {Object}                                     props.item   Schedule item.
 * @param {number[]}                                   props.starts weekStarts().
 * @param {number}                                     props.nowAt  Position of "now" (percent).
 * @param {(ts: number) => string}                     props.write  runWriter().
 * @param {(label: string, announce: boolean) => void} props.onPick Shows a mark's time.
 */
export default function WeekLane( { item, starts, nowAt, write, onPick } ) {
	const [ current, setCurrent ] = useState( 0 );
	const lane = useRef();
	const marks = ( item.week || [] )
		.map( ( ts ) => ( { ts, at: position( ts, starts ) } ) )
		.filter( ( mark ) => null !== mark.at );
	const active = Math.min( current, Math.max( 0, marks.length - 1 ) );
	const label = ( ts ) => `${ item.title }: ${ write( ts ) }`;

	const onKeyDown = ( event ) => {
		const next = nextMark( event.key, active, marks.length );
		if ( null === next ) {
			return;
		}
		event.preventDefault();
		setCurrent( next );
		lane.current?.querySelectorAll( '.lw-week__tick' )[ next ]?.focus();
	};

	return (
		<div
			ref={ lane }
			className="lw-week__lane"
			role="group"
			aria-label={ laneSummary(
				item,
				marks.map( ( mark ) => mark.ts ),
				write
			) }
		>
			{ marks.map( ( { ts, at }, index ) => (
				<button
					key={ ts }
					type="button"
					tabIndex={ index === active ? 0 : -1 }
					className="lw-week__tick"
					style={ { left: `${ at }%` } }
					title={ label( ts ) }
					aria-label={ label( ts ) }
					onFocus={ () => {
						setCurrent( index );
						onPick( label( ts ), false );
					} }
					onClick={ () => onPick( label( ts ), true ) }
					onKeyDown={ onKeyDown }
				/>
			) ) }
			<i
				className="lw-week__now"
				style={ { left: `${ nowAt }%` } }
				aria-hidden="true"
			/>
		</div>
	);
}
