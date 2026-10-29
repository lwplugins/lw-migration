/**
 * WordPress dependencies
 */
import { __, _x, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import DestinationIcon, { presetOf } from '../components/DestinationIcon';
import { OWN_FOLDER } from '../data/boot';

/**
 * The server's disk (the backups folder here): decorative, a name or a
 * label is always given beside it.
 *
 * @param {Object} props
 * @param {number} props.size Pixels.
 */
export function DiskIcon( { size = 16 } ) {
	return (
		<svg
			className="lw-bk-disk"
			xmlns="http://www.w3.org/2000/svg"
			viewBox="0 0 24 24"
			width={ size }
			height={ size }
			aria-hidden="true"
			focusable="false"
		>
			<rect
				x="3"
				y="13"
				width="18"
				height="7"
				rx="1.5"
				fill="none"
				stroke="currentColor"
				strokeWidth="1.7"
			/>
			<path
				fill="none"
				stroke="currentColor"
				strokeWidth="1.7"
				d="M5 13 7.5 5h9L19 13"
			/>
			<path
				stroke="currentColor"
				strokeWidth="2"
				strokeLinecap="round"
				d="M16.5 16.5h.01"
			/>
		</svg>
	);
}

/**
 * The icon of a cloud copy: its destination's, a generic cloud when the
 * destination was deleted since.
 *
 * @param {Object} props
 * @param {Object} props.badge A cloudBadges() entry.
 * @param {number} props.size  Pixels.
 */
export function CopyIcon( { badge, size = 16 } ) {
	return (
		<DestinationIcon
			type={ badge.destination?.type || '' }
			preset={ presetOf( badge.destination ) }
			size={ size }
		/>
	);
}

/**
 * @param {Object} item Backup row.
 * @return {string} Where its file is on this server.
 */
export const localText = ( item ) =>
	OWN_FOLDER === item.folder
		? __( 'In the backups folder', 'lw-migration' )
		: __( "In the other .wpress tool's folder", 'lw-migration' );

/**
 * "Where it lives" in a row: this server, then one tile per destination it
 * was uploaded to, a dot on a failed or running upload. The words are in
 * the tiles' titles and in one hidden line for screen readers.
 *
 * @param {Object}   props
 * @param {Object}   props.item   Backup row.
 * @param {Object[]} props.badges cloudBadges() of the row.
 */
export default function WhereIcons( { item, badges } ) {
	const words = [
		localText( item ),
		...badges.map( ( badge ) =>
			sprintf(
				/* translators: 1: destination name, 2: upload state, e.g. uploaded. */
				_x(
					'%1$s: %2$s',
					'destination and upload state',
					'lw-migration'
				),
				badge.name,
				badge.label
			)
		),
	];
	return (
		<span className="lw-bk-where">
			<span className="screen-reader-text">{ words.join( ', ' ) }</span>
			<span className="lw-bk-where__tile" title={ words[ 0 ] }>
				<DiskIcon size={ 14 } />
			</span>
			{ badges.map( ( badge, index ) => (
				<span
					key={ badge.id }
					className={ `lw-bk-where__tile is-${ badge.tone }` }
					title={ words[ index + 1 ] }
				>
					<CopyIcon badge={ badge } size={ 14 } />
				</span>
			) ) }
		</span>
	);
}
