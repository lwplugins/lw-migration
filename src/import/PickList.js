/**
 * WordPress dependencies
 */
import { useInstanceId } from '@wordpress/compose';
import { Icon, lock } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import DestinationIcon, { presetOf } from '../components/DestinationIcon';
import { formatBytes } from '../data/format';

/**
 * One tag of a row: a destination's icon, the lock, or the text alone.
 *
 * @param {Object} props
 * @param {Object} props.tag `{ kind, text, destination? }`.
 */
function Tag( { tag } ) {
	return (
		<span className={ `lw-pick__tag is-${ tag.kind }` }>
			{ tag.destination && (
				<DestinationIcon
					type={ tag.destination.type }
					preset={ presetOf( tag.destination ) }
					size={ 14 }
				/>
			) }
			{ 'lock' === tag.kind && <Icon icon={ lock } size={ 16 } /> }
			{ tag.text }
		</span>
	);
}

/**
 * A list to choose one file from, as a radio group: each row the file name,
 * a line under it, its tags and its size.
 *
 * @param {Object}               props
 * @param {string}               props.label    Accessible name of the group.
 * @param {Object[]}             props.rows     `{ id, name, meta, tags, size }`.
 * @param {string}               props.value    Chosen row id ('' = none).
 * @param {(id: string) => void} props.onChange Receives the chosen id.
 * @param {string}               props.error    Refusal shown above the rows ('' = none).
 */
export default function PickList( {
	label,
	rows,
	value,
	onChange,
	error = '',
} ) {
	const name = useInstanceId( PickList, 'lw-pick' );
	return (
		<div className="lw-pick">
			{ error && (
				<p className="lw-restore__error" role="alert">
					{ error }
				</p>
			) }
			<div
				className="lw-pick__list"
				role="radiogroup"
				aria-label={ label }
				aria-invalid={ error ? true : undefined }
			>
				{ rows.map( ( row, index ) => (
					<label
						key={ row.id }
						htmlFor={ `${ name }-${ index }` }
						className={ `lw-pick__row ${
							value === row.id ? 'is-chosen' : ''
						}` }
					>
						<input
							id={ `${ name }-${ index }` }
							type="radio"
							name={ name }
							value={ row.id }
							checked={ value === row.id }
							onChange={ () => onChange( row.id ) }
						/>
						<span className="lw-pick__text">
							<span className="lw-pick__name">{ row.name }</span>
							{ row.meta && (
								<span className="lw-pick__meta">
									{ row.meta }
								</span>
							) }
						</span>
						<span className="lw-pick__tags">
							{ row.tags.map( ( tag ) => (
								<Tag key={ tag.key } tag={ tag } />
							) ) }
						</span>
						<span className="lw-pick__size">
							{ formatBytes( row.size ) }
						</span>
					</label>
				) ) }
			</div>
		</div>
	);
}
