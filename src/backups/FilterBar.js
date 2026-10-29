/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Icon, search } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { formatNumber } from '../data/format';
import { kindText } from './listing';

/**
 * Filter chips with their counts (chips()) and the search over names and
 * labels.
 *
 * @param {Object}                 props
 * @param {Object[]}               props.chips    `{ id, count }`.
 * @param {string}                 props.filter   The pressed chip.
 * @param {(id: string) => void}   props.onFilter Receives a chip id.
 * @param {string}                 props.query    The search text.
 * @param {(text: string) => void} props.onQuery  Receives the search text.
 */
export default function FilterBar( {
	chips,
	filter,
	onFilter,
	query,
	onQuery,
} ) {
	return (
		<div className="lw-bk-tools">
			<div
				className="lw-bk-chips"
				role="group"
				aria-label={ __( 'Show', 'lw-migration' ) }
			>
				{ chips.map( ( chip ) => (
					<button
						key={ chip.id }
						type="button"
						className="lw-bk-chip"
						aria-pressed={ chip.id === filter }
						onClick={ () => onFilter( chip.id ) }
					>
						{ 'all' === chip.id
							? __( 'All', 'lw-migration' )
							: kindText( chip.id ) }
						<span className="lw-bk-chip__count">
							{ formatNumber( chip.count ) }
						</span>
					</button>
				) ) }
			</div>
			<div className="lw-bk-search">
				<Icon icon={ search } size={ 20 } />
				<label htmlFor="lw-bk-search" className="screen-reader-text">
					{ __( 'Search the backups', 'lw-migration' ) }
				</label>
				<input
					id="lw-bk-search"
					type="search"
					value={ query }
					placeholder={ __(
						'Search by name or label',
						'lw-migration'
					) }
					onChange={ ( event ) => onQuery( event.target.value ) }
				/>
			</div>
		</div>
	);
}
