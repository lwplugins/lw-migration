/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { watchDockRoom } from './dockRoom';
import { routeText, sizeLead } from './summary';

/**
 * The bar that stays at the bottom of the Backup section while it scrolls:
 * how big the backup is, where it goes and how the file is made, and Start.
 * It sits in the page flow after the last card (sticky, not fixed), so at the
 * end of the page it never covers a field and it comes last in the tab order.
 * Its measured height is the room a focused field keeps above it (dockRoom.js).
 *
 * @param {Object}     props
 * @param {Object}     props.comp         composition() result.
 * @param {Object}     props.options      Form state.
 * @param {boolean}    props.wholeNetwork The size is the whole network's, not the chosen sites'.
 * @param {Object[]}   props.chosen       The ticked destination views.
 * @param {boolean}    props.starting     The start request is under way.
 * @param {boolean}    props.disabled     Start cannot be pressed now.
 * @param {string}     props.describedBy  Id of what explains a disabled Start.
 * @param {() => void} props.onStart      Starts the backup.
 * @param {Element}    props.children     Why the last Start failed (notices), shown
 *                                        above the line, so it is seen where
 *                                        Start was pressed.
 */
export default function SummaryDock( {
	comp,
	options,
	wholeNetwork,
	chosen,
	starting,
	disabled,
	describedBy,
	onStart,
	children,
} ) {
	const ref = useRef( null );
	useEffect(
		() =>
			watchDockRoom(
				ref.current,
				ref.current?.closest( '.lw-export' ) ?? null
			),
		[]
	);
	const size = sizeLead( comp, options.compression, wholeNetwork );
	const route = routeText( {
		chosen,
		keepLocal: false !== options.keepLocal,
		compression: options.compression,
		encrypt: options.encrypt,
	} );

	return (
		<div
			ref={ ref }
			className="lw-dock"
			role="region"
			aria-label={ __( 'Summary', 'lw-migration' ) }
		>
			{ children }
			<p className="lw-dock__sum">
				<strong>{ size || __( 'The backup', 'lw-migration' ) }</strong>
				{ ` \u2192 ${ route }` }
			</p>
			<Button
				__next40pxDefaultSize
				variant="primary"
				className="lw-dock__start"
				isBusy={ starting }
				disabled={ disabled }
				accessibleWhenDisabled
				aria-describedby={ describedBy }
				onClick={ onStart }
			>
				{ __( 'Start backup', 'lw-migration' ) }
			</Button>
		</div>
	);
}
