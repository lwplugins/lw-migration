/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { Icon, chevronRight } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import DestinationIcon from '../components/DestinationIcon';
import { shelfGroups } from './shelf';

/** The shelf section and its heading (focus targets). */
export const SHELF_ID = 'lw-dest-add';
export const SHELF_TITLE_ID = 'lw-dest-add-title';
const FULL_ID = 'lw-dest-add-full';

/**
 * The server's cap on destinations (ProfileRepository::MAX).
 */
export const MAX_DESTINATIONS = 20;

/**
 * @param {Object} tile Shelf tile (shelfGroups()).
 * @return {string} The tile button's id.
 */
export const tileId = ( tile ) =>
	`lw-dest-pick-${ tile.typeKey }${ tile.preset ? `-${ tile.preset }` : '' }`;

/**
 * Brings the shelf into view and focuses its first tile that can be
 * picked, else (all closed, or asked for) its heading.
 *
 * @param {boolean} toTile Focus a tile when one can be picked.
 */
export function focusShelf( toTile = true ) {
	const shelf = document.getElementById( SHELF_ID );
	if ( ! shelf ) {
		return;
	}
	const tile = toTile
		? shelf.querySelector( '.lw-shelf-place:not([aria-disabled="true"])' )
		: null;
	shelf.scrollIntoView?.( { block: 'start' } );
	( tile ?? document.getElementById( SHELF_TITLE_ID ) )?.focus( {
		preventScroll: true,
	} );
}

/**
 * @return {Object<string, string[]>} Each group's title and one-line note.
 */
const groupTexts = () => ( {
	oauth: [
		__( 'With a sign-in', 'lw-migration' ),
		__( 'Nothing to type in or register.', 'lw-migration' ),
	],
	keys: [
		__( 'With an access key', 'lw-migration' ),
		__(
			'S3-compatible storage, with the key from the provider.',
			'lw-migration'
		),
	],
	servers: [
		__( 'On your own server', 'lw-migration' ),
		__( 'With a user name and a password or a key.', 'lw-migration' ),
	],
} );

/**
 * One place on the shelf, a row of its group's list: a button that opens
 * the draft below (pressed while that draft is open). A type this site cannot use, or any type once
 * the list is full, stays visible but closed, with its reason.
 *
 * @param {Object}                 props
 * @param {Object}                 props.tile    Shelf tile.
 * @param {boolean}                props.full    The list is full.
 * @param {boolean}                props.pressed Its draft is open.
 * @param {(tile: Object) => void} props.onPick  Opens its draft.
 */
function Tile( { tile, full, pressed, onPick } ) {
	const closed = full || ! tile.available;
	const reason = tile.available ? '' : tile.reason;
	return (
		<button
			type="button"
			id={ tileId( tile ) }
			className="lw-shelf-place"
			aria-pressed={ pressed }
			aria-disabled={ closed || undefined }
			aria-describedby={ full ? FULL_ID : undefined }
			onClick={ () => ! closed && onPick( tile ) }
		>
			<DestinationIcon
				type={ tile.typeKey }
				preset={ tile.preset ?? '' }
				size={ 20 }
			/>
			<span className="lw-shelf-place__label">
				{ tile.label }
				{ reason && (
					<small className="lw-shelf-place__reason">{ reason }</small>
				) }
			</span>
			<Icon
				icon={ chevronRight }
				size={ 20 }
				className="lw-shelf-place__go"
			/>
		</button>
	);
}

/**
 * "New destination": the places a backup can go, grouped by how you get
 * in (a sign-in, an access key, your own server), each group a list with
 * one place per row. Picking one opens a
 * single draft right below the groups (children); picking another one
 * replaces it. With no destination yet it asks where the backups should
 * go. A full list keeps the shelf, every tile closed with the reason.
 *
 * @param {Object}                    props
 * @param {Object[]}                  props.types     Schema types.
 * @param {boolean}                   props.empty     No destination yet.
 * @param {boolean}                   props.full      The list is full.
 * @param {(tile: Object) => boolean} props.isPressed The tile's draft is open.
 * @param {(tile: Object) => void}    props.onPick    Opens a tile's draft.
 * @param {Element}                   props.children  The open draft.
 */
export default function AddShelf( {
	types,
	empty,
	full,
	isPressed,
	onPick,
	children,
} ) {
	const texts = groupTexts();
	return (
		<section
			className="lw-add"
			id={ SHELF_ID }
			aria-labelledby={ SHELF_TITLE_ID }
		>
			<header className="lw-add__head">
				<h2
					id={ SHELF_TITLE_ID }
					tabIndex={ -1 }
					className="lw-add__title"
				>
					{ empty
						? __( 'Where should the backups go?', 'lw-migration' )
						: __( 'New destination', 'lw-migration' ) }
				</h2>
				<p className="lw-add__intro">
					{ __(
						'Choose a place. Its settings open right below, and the connection is checked as soon as you save.',
						'lw-migration'
					) }
				</p>
				{ full && (
					<p id={ FULL_ID } className="lw-add__full">
						{ sprintf(
							/* translators: %d: the most destinations a site can have. */
							__(
								'You can have at most %d destinations.',
								'lw-migration'
							),
							MAX_DESTINATIONS
						) }
					</p>
				) }
			</header>
			<div className="lw-add__groups">
				{ shelfGroups( types ).map( ( group ) => (
					<div
						key={ group.key }
						className={ `lw-add__group is-${ group.key }` }
					>
						<h3 className="lw-add__group-title">
							{ texts[ group.key ][ 0 ] }
						</h3>
						<p className="lw-add__note">
							{ texts[ group.key ][ 1 ] }
						</p>
						<ul className="lw-add__list">
							{ group.tiles.map( ( tile ) => (
								<li key={ tileId( tile ) }>
									<Tile
										tile={ tile }
										full={ full }
										pressed={ isPressed( tile ) }
										onPick={ onPick }
									/>
								</li>
							) ) }
						</ul>
					</div>
				) ) }
			</div>
			{ children }
		</section>
	);
}
