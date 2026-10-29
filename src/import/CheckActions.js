/**
 * WordPress dependencies
 */
import { Button, TextControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { matchesHost } from './check';

/**
 * The check view's last row: Back (or Cancel), the typed confirmation, and
 * the red "Restore now", which stays off until the target's host is typed.
 * The typing is a gate in this page only; the server's own confirmation
 * input is the same as before. Without a known host there is nothing to
 * type, and the button is on.
 *
 * @param {Object}     props
 * @param {string}     props.host      The host to type (check.js confirmHost()).
 * @param {boolean}    props.back      Back to the source (else Cancel).
 * @param {boolean}    props.there     A push: the restore runs on the connected site.
 * @param {boolean}    props.busy      An answer is on its way.
 * @param {() => void} props.onBack    Back / Cancel.
 * @param {() => void} props.onRestore Restore now.
 */
export default function CheckActions( {
	host,
	back,
	there,
	busy,
	onBack,
	onRestore,
} ) {
	const [ typed, setTyped ] = useState( '' );
	const confirmed = ! host || matchesHost( typed, host );
	return (
		<div className="lw-check-actions">
			<Button
				__next40pxDefaultSize
				variant="secondary"
				disabled={ busy }
				accessibleWhenDisabled
				onClick={ onBack }
			>
				{ back
					? __( 'Back', 'lw-migration' )
					: __( 'Cancel', 'lw-migration' ) }
			</Button>
			<span className="lw-check-actions__spacer" />
			{ host && (
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					className="lw-check-actions__confirm"
					autoComplete="off"
					spellCheck={ false }
					label={ sprintf(
						/* translators: %s: host of the site that is replaced, e.g. example.com. */
						__( 'Type %s to confirm', 'lw-migration' ),
						host
					) }
					placeholder={ host }
					value={ typed }
					onChange={ setTyped }
				/>
			) }
			<Button
				__next40pxDefaultSize
				variant="primary"
				isDestructive
				isBusy={ busy }
				disabled={ busy || ! confirmed }
				accessibleWhenDisabled
				onClick={ onRestore }
			>
				{ there
					? __( 'Restore there', 'lw-migration' )
					: __( 'Restore now', 'lw-migration' ) }
			</Button>
		</div>
	);
}
