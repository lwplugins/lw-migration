/**
 * WordPress dependencies
 */
import { Button, DropdownMenu, FormToggle } from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import { __, sprintf } from '@wordpress/i18n';
import {
	copy,
	moreHorizontal,
	pencil,
	play,
	postList,
	trash,
} from '@wordpress/icons';

/**
 * Internal dependencies
 */
import StatusBadge from '../components/StatusBadge';
import { ContentsFact, NextFact, RunsFact } from './CardFacts';
import RunProgress, { runningState } from './RunProgress';
import { describeTiming } from './timing';

/**
 * One schedule: its colour, name and state, the switch in the header
 * (never alone in a row), the timing in one line; then Next, Last runs and
 * What and where side by side; the actions at the bottom, Duplicate and
 * Delete in the ⋯ menu.
 *
 * @param {Object}                     props
 * @param {Object}                     props.item         ScheduleView item.
 * @param {number}                     props.lane         Lane colour (laneOf()).
 * @param {Object[]|null}              props.destinations Destination views (null while unknown).
 * @param {number}                     props.now          Unix seconds.
 * @param {(ts: number) => string}     props.write        runWriter().
 * @param {Object|null}                props.job          The job this schedule runs now (page-driven), or null.
 * @param {boolean}                    props.busy         Another job runs: no "Run now".
 * @param {boolean}                    props.pending      A request for this card is on its way.
 * @param {(enabled: boolean) => void} props.onToggle     Switch on/off.
 * @param {(event: Object) => void}    props.onEdit       Open the editor.
 * @param {() => void}                 props.onRun        Run now.
 * @param {() => void}                 props.onLog        Open the run log.
 * @param {() => void}                 props.onDuplicate  Open the editor with a copy.
 * @param {() => void}                 props.onDelete     Ask before deleting.
 */
export default function ScheduleCard( {
	item,
	lane,
	destinations,
	now,
	write,
	job = null,
	busy,
	pending,
	onToggle,
	onEdit,
	onRun,
	onLog,
	onDuplicate,
	onDelete,
} ) {
	const id = useInstanceId( ScheduleCard, 'lw-schedule-switch' );
	const titleId = `${ id }-title`;
	// The actions read "Run now", "Edit"…; the schedule's name describes them.
	const about = { 'aria-describedby': titleId };

	return (
		<article
			className={ `lw-sched lw-lane-${ lane } ${
				item.enabled ? '' : 'is-off'
			}` }
			aria-labelledby={ titleId }
		>
			<header className="lw-sched__head">
				<div className="lw-sched__name">
					<i className="lw-swatch" aria-hidden="true" />
					<h3 id={ titleId } className="lw-sched__title">
						{ item.title }
					</h3>
					{ item.running && (
						<StatusBadge status="info">
							{ job
								? sprintf(
										/* translators: %d: percentage done. */
										__( 'running · %d%%', 'lw-migration' ),
										runningState( job, write ).percent
									)
								: __( 'running', 'lw-migration' ) }
						</StatusBadge>
					) }
					{ ! item.enabled && (
						<StatusBadge status="idle">
							{ __( 'switched off', 'lw-migration' ) }
						</StatusBadge>
					) }
				</div>
				<span className="lw-admin-switch lw-sched__switch">
					<FormToggle
						id={ id }
						// A stable name: the state is read from the switch itself.
						aria-label={ sprintf(
							/* translators: %s: schedule name. */
							__( '%s runs by itself', 'lw-migration' ),
							item.title
						) }
						checked={ item.enabled }
						// Not disabled while a request is on its way (focus stays).
						aria-disabled={ pending }
						onChange={ ( event ) => {
							if ( ! pending ) {
								onToggle( event.target.checked );
							}
						} }
					/>
					<label
						htmlFor={ id }
						className="lw-admin-switch__state"
						aria-hidden="true"
					>
						{ item.enabled
							? __( 'Runs by itself', 'lw-migration' )
							: __( 'Switched off', 'lw-migration' ) }
					</label>
				</span>
				<p className="lw-sched__cadence">{ describeTiming( item ) }</p>
			</header>
			{ item.running && job && (
				<RunProgress job={ job } write={ write } />
			) }
			<div className="lw-sched__facts">
				<NextFact item={ item } now={ now } write={ write } />
				<RunsFact item={ item } write={ write } />
				<ContentsFact item={ item } destinations={ destinations } />
			</div>
			<footer className="lw-sched__foot">
				<Button
					__next40pxDefaultSize
					variant="secondary"
					icon={ play }
					onClick={ onRun }
					{ ...about }
					disabled={ busy || pending }
					accessibleWhenDisabled
				>
					{ __( 'Run now', 'lw-migration' ) }
				</Button>
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					icon={ pencil }
					{ ...about }
					onClick={ onEdit }
				>
					{ __( 'Edit', 'lw-migration' ) }
				</Button>
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					icon={ postList }
					{ ...about }
					onClick={ onLog }
				>
					{ __( 'Run log', 'lw-migration' ) }
				</Button>
				<span className="lw-sched__spacer" />
				<DropdownMenu
					icon={ moreHorizontal }
					label={ sprintf(
						/* translators: %s: schedule name. */
						__( 'More for %s', 'lw-migration' ),
						item.title
					) }
					toggleProps={ {
						size: 'compact',
						'data-lw-schedule': item.id,
					} }
					popoverProps={ {
						className: 'lw-row-menu',
						placement: 'bottom-end',
					} }
					controls={ [
						{
							title: __( 'Duplicate', 'lw-migration' ),
							icon: copy,
							onClick: onDuplicate,
						},
						{
							title: __( 'Delete schedule', 'lw-migration' ),
							icon: trash,
							onClick: onDelete,
						},
					] }
				/>
			</footer>
		</article>
	);
}
