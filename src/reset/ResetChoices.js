/**
 * WordPress dependencies
 */
import { Icon, brush, media, plugins, table, trash } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { choiceScope } from './layers';
import { TYPES, titleOf, unavailableText } from './reset';

/** The icon of each reset (and of each layer of the site). */
export const ICONS = {
	plugins,
	themes: brush,
	media,
	database: table,
	full: trash,
};

/**
 * @param {string} type Reset type.
 * @return {string} The choice tile's id.
 */
export const choiceId = ( type ) => `lw-reset-choice-${ type }`;

const MOVES = {
	ArrowRight: 1,
	ArrowDown: 1,
	ArrowLeft: -1,
	ArrowUp: -1,
};

/**
 * The tile an arrow key moves to: the next open one that way, round the end.
 *
 * @param {Object[]} tiles `{ type, open }` in order.
 * @param {string}   from  The focused tile's type.
 * @param {number}   step  1 or -1.
 * @return {string} The type to choose ('' when none is open).
 */
function nextOpen( tiles, from, step ) {
	const count = tiles.length;
	const at = tiles.findIndex( ( tile ) => tile.type === from );
	for ( let i = 1; i <= count; i++ ) {
		const tile = tiles[ ( at + step * i + count * count ) % count ];
		if ( tile.open ) {
			return tile.type;
		}
	}
	return '';
}

/**
 * "What do you want to reset?": the five resets as one radio group of tiles,
 * each with its icon, title and what it would remove. A reset this site
 * cannot run stays visible, closed, with its reason in place of the count.
 * Nothing is chosen at first; the arrow keys move the choice (and focus)
 * between the open tiles. While a start is on its way the choice holds:
 * every tile is closed, so the answer lands on the choice it was sent for.
 *
 * @param {Object}                 props
 * @param {Object}                 props.summary    `GET /reset`.
 * @param {string|null}            props.value      The chosen type (null: none yet).
 * @param {(type: string) => void} props.onChange   Receives the chosen type.
 * @param {string}                 props.labelledBy Id of the group's heading.
 * @param {boolean}                props.disabled   A start is on its way.
 */
export default function ResetChoices( {
	summary,
	value,
	onChange,
	labelledBy,
	disabled = false,
} ) {
	const tiles = TYPES.map( ( type ) => {
		const part = summary.types?.[ type ] || {};
		const open = false !== part.available;
		return {
			type,
			open,
			line: open ? choiceScope( type, summary ) : unavailableText( part ),
		};
	} );
	const chosen = tiles.find( ( tile ) => tile.type === value && tile.open );
	const tabStop = chosen?.type ?? tiles.find( ( tile ) => tile.open )?.type;

	const move = ( event, from ) => {
		const step = MOVES[ event.key ];
		if ( ! step ) {
			return;
		}
		event.preventDefault();
		const next = nextOpen( tiles, from, step );
		if ( ! next || disabled ) {
			return;
		}
		onChange( next );
		document.getElementById( choiceId( next ) )?.focus();
	};

	return (
		<div
			className="lw-reset-choices"
			role="radiogroup"
			aria-labelledby={ labelledBy }
		>
			{ tiles.map( ( tile ) => (
				<button
					key={ tile.type }
					type="button"
					role="radio"
					id={ choiceId( tile.type ) }
					className="lw-reset-choice"
					aria-checked={ tile.type === value }
					aria-disabled={ tile.open && ! disabled ? undefined : true }
					aria-labelledby={ `${ choiceId( tile.type ) }-title` }
					aria-describedby={ `${ choiceId( tile.type ) }-line` }
					tabIndex={ tile.type === tabStop ? 0 : -1 }
					onClick={ () =>
						tile.open && ! disabled && onChange( tile.type )
					}
					onKeyDown={ ( event ) => move( event, tile.type ) }
				>
					<Icon icon={ ICONS[ tile.type ] } size={ 24 } />
					<strong
						id={ `${ choiceId( tile.type ) }-title` }
						className="lw-reset-choice__title"
					>
						{ titleOf( tile.type ) }
					</strong>
					<small
						id={ `${ choiceId( tile.type ) }-line` }
						className="lw-reset-choice__line"
					>
						{ tile.line }
					</small>
				</button>
			) ) }
		</div>
	);
}
