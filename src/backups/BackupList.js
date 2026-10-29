/**
 * WordPress dependencies
 */
import { useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import BackupRow from './BackupRow';
import { dayGroups } from './listing';
import './list.scss';

/**
 * The shown backups grouped by the site day they were made (Today,
 * Yesterday, the days of the last week, Older), or why nothing is shown.
 *
 * @param {Object}               props
 * @param {Object[]}             props.items        Shown rows, the newest first.
 * @param {boolean}              props.narrowed     A chip or a search hides some backups.
 * @param {string|null}          props.chosenId     Id of the backup in the detail panel.
 * @param {(id: string) => void} props.onChoose     Receives a backup id.
 * @param {Object[]}             props.destinations Destination views.
 * @param {Object}               props.actions      The view's backup actions.
 * @param {boolean}              props.busy         A job runs.
 * @param {string|null}          props.downloading  Id of the backup whose download is being prepared.
 */
export default function BackupList( {
	items,
	narrowed,
	chosenId,
	onChoose,
	destinations,
	actions,
	busy,
	downloading = null,
} ) {
	// Grouped once per list, not on every render of the section.
	const groups = useMemo(
		() => dayGroups( items, Math.floor( Date.now() / 1000 ) ),
		[ items ]
	);
	if ( ! items.length ) {
		return (
			<p className="lw-bk-list lw-bk-list__empty">
				{ narrowed
					? __( 'No backup matches this filter.', 'lw-migration' )
					: __(
							'No backups yet. Make the first one under Backup.',
							'lw-migration'
						) }
			</p>
		);
	}
	return (
		<div className="lw-bk-list">
			{ groups.map( ( group ) => (
				<section
					key={ group.key }
					className="lw-bk-day"
					aria-labelledby={ `lw-bk-${ group.key }` }
				>
					<h3
						className="lw-bk-day__title"
						id={ `lw-bk-${ group.key }` }
					>
						{ group.title }
					</h3>
					<ul className="lw-bk-day__rows">
						{ group.items.map( ( item ) => (
							<BackupRow
								key={ item.id }
								item={ item }
								recent={ group.recent }
								chosen={ item.id === chosenId }
								onChoose={ onChoose }
								destinations={ destinations }
								actions={ actions }
								busy={ busy }
								preparing={ item.id === downloading }
							/>
						) ) }
					</ul>
				</section>
			) ) }
		</div>
	);
}
