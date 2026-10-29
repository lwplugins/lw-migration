/**
 * WordPress dependencies
 */
import { humanTimeDiff } from '@wordpress/date';
import { memo } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Icon, link, lockSmall } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { formatBytes } from '../data/format';
import { cloudBadges } from './cloudStatus';
import { kindText, whenText } from './listing';
import RowMenu from './RowMenu';
import WhereIcons from './WhereIcons';

/**
 * The tags under a backup's name: its label, its kind, the site it came
 * from, encrypted, live transfer links.
 *
 * @param {Object} props
 * @param {Object} props.item Backup row.
 */
export function RowTags( { item } ) {
	return (
		<span className="lw-bk-tags">
			{ item.label && (
				<span className="lw-bk-tag is-label">{ item.label }</span>
			) }
			<span className={ `lw-bk-tag is-kind is-${ item.kind }` }>
				{ kindText( item.kind ) }
			</span>
			{ item.fromSite && (
				<span className="lw-bk-tag is-other">
					{ sprintf(
						/* translators: %s: address of the site the backup was made on. */
						__( 'from %s', 'lw-migration' ),
						item.fromSite
					) }
				</span>
			) }
			{ true === item.encrypted && (
				<span className="lw-bk-tag is-icon is-lock">
					<Icon icon={ lockSmall } size={ 18 } />
					<span className="screen-reader-text">
						{ __( 'Encrypted', 'lw-migration' ) }
					</span>
				</span>
			) }
			{ item.links > 0 && (
				<span className="lw-bk-tag is-icon">
					<Icon icon={ link } size={ 18 } />
					<span className="screen-reader-text">
						{ sprintf(
							/* translators: %d: number of live transfer links. */
							_n(
								'%d live transfer link',
								'%d live transfer links',
								item.links,
								'lw-migration'
							),
							item.links
						) }
					</span>
				</span>
			) }
		</span>
	);
}

/**
 * One backup in the list: a button that chooses it (the whole row is its
 * click target), when it was made, its size, where it lives, and ⋯.
 *
 * @param {Object}               props
 * @param {Object}               props.item         Backup row.
 * @param {boolean}              props.recent       In a day group of its own (clock time) or Older (day).
 * @param {boolean}              props.chosen       Shown in the detail panel.
 * @param {(id: string) => void} props.onChoose     Receives the backup id.
 * @param {Object[]}             props.destinations Destination views.
 * @param {Object}               props.actions      The view's backup actions.
 * @param {boolean}              props.busy         A job runs.
 * @param {boolean}              props.preparing    This backup's download is being prepared (a boolean per row keeps the memo).
 */
function BackupRow( {
	item,
	recent,
	chosen,
	onChoose,
	destinations,
	actions,
	busy,
	preparing,
} ) {
	return (
		<li className={ `lw-bk-row ${ chosen ? 'is-chosen' : '' }` }>
			<button
				type="button"
				className="lw-bk-row__pick"
				aria-current={ chosen ? 'true' : undefined }
				onClick={ () => onChoose( item.id ) }
			>
				<span className="lw-bk-row__name">{ item.name }</span>
				<RowTags item={ item } />
			</button>
			<span className="lw-bk-row__when">
				{ whenText( item.mtime, recent ) }
				<small>{ humanTimeDiff( item.mtime * 1000 ) }</small>
			</span>
			<span className="lw-bk-row__size">
				{ formatBytes( item.size ) }
			</span>
			<WhereIcons
				item={ item }
				badges={ cloudBadges( item, destinations ) }
			/>
			<RowMenu
				item={ item }
				actions={ actions }
				busy={ busy }
				withUpload={ destinations.length > 0 }
				preparing={ preparing }
			/>
		</li>
	);
}

export default memo( BackupRow );
