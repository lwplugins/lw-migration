/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { formatBytes } from '../data/format';
import { keepText, storageSums } from './storage';

/**
 * The strip above the list: what the backups take here (ours and the other
 * .wpress tool's folder) next to the free space, and the rule that deletes
 * backups by themselves.
 *
 * @param {Object} props
 * @param {Object} props.storage `storage` of `GET /backups`.
 * @param {number} props.count   Backups in the list.
 */
export default function StorageStrip( { storage, count } ) {
	const sums = storageSums( storage );
	const parts = [
		{
			key: 'own',
			bytes: sums.own,
			text: __( 'Own backups', 'lw-migration' ),
		},
		sums.other > 0 && {
			key: 'other',
			bytes: sums.other,
			text: __( "The other .wpress tool's folder", 'lw-migration' ),
		},
		null !== sums.free && {
			key: 'free',
			bytes: sums.free,
			text: __( 'Free', 'lw-migration' ),
		},
	].filter( Boolean );
	const legend = parts.map(
		( part ) => `${ part.text } ${ formatBytes( part.bytes ) }`
	);

	return (
		<section
			className="lw-bk-storage"
			aria-label={ __( 'Storage', 'lw-migration' ) }
		>
			<p className="lw-bk-storage__total">
				<strong>{ formatBytes( sums.used ) }</strong>{ ' ' }
				{ sprintf(
					/* translators: %d: number of backups. */
					_n(
						'in %d backup',
						'in %d backups',
						count,
						'lw-migration'
					),
					count
				) }
			</p>
			<p className="lw-bk-storage__keep">{ keepText( storage?.keep ) }</p>
			<div
				className="lw-bk-storage__bar"
				role="img"
				aria-label={ legend.join( ', ' ) }
			>
				{ parts.map( ( part ) => (
					<span
						key={ part.key }
						className={ `is-${ part.key }` }
						style={ { flexGrow: part.bytes } }
					/>
				) ) }
			</div>
			<ul className="lw-bk-storage__legend" aria-hidden="true">
				{ parts.map( ( part, index ) => (
					<li key={ part.key }>
						<i className={ `is-${ part.key }` } />
						{ legend[ index ] }
					</li>
				) ) }
			</ul>
		</section>
	);
}
