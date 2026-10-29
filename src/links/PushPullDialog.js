/**
 * WordPress dependencies
 */
// ConfirmDialog is experimental in core; the shared LW admin pattern uses it.
/* eslint-disable @wordpress/no-unsafe-wp-apis */
import { __experimentalConfirmDialog as ConfirmDialog } from '@wordpress/components';
/* eslint-enable @wordpress/no-unsafe-wp-apis */
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import Callout from '../components/Callout';
import ToggleRow from '../components/ToggleRow';
import { httpWarning } from './LinkCard';

/**
 * Asks before a push (`push`) or a pull (`pull`) over one connection, with
 * the safety backup of the site whose content is replaced (on by default).
 *
 * @param {Object}                          props
 * @param {Object}                          props.link      Link view.
 * @param {string}                          props.kind      `push` or `pull`.
 * @param {(safetyBackup: boolean) => void} props.onConfirm Starts it.
 * @param {() => void}                      props.onCancel  Closes the dialog.
 */
export default function PushPullDialog( { link, kind, onConfirm, onCancel } ) {
	const [ safety, setSafety ] = useState( true );
	const name = link.name || link.address;
	const address = link.address;
	const push = 'push' === kind;

	return (
		<ConfirmDialog
			className="lw-modal"
			isOpen
			onConfirm={ () => onConfirm( safety ) }
			onCancel={ onCancel }
			confirmButtonText={
				push
					? __( 'Send this site there', 'lw-migration' )
					: __( 'Bring that site here', 'lw-migration' )
			}
			cancelButtonText={ __( 'Cancel', 'lw-migration' ) }
		>
			<p>
				{ push
					? sprintf(
							/* translators: 1: name of the connected site, 2: its address. */
							__(
								"Send this site to %1$s (%2$s)? A backup of this site is made, sent there and restored, replacing that site's content. You confirm its summary before anything is replaced.",
								'lw-migration'
							),
							name,
							address
						)
					: sprintf(
							/* translators: 1: name of the connected site, 2: its address. */
							__(
								"Bring %1$s (%2$s) here? That site makes a backup, this site downloads and restores it, replacing this site's content. You confirm the summary first.",
								'lw-migration'
							),
							name,
							address
						) }
			</p>
			{ link.insecure && (
				<Callout tone="warning">{ httpWarning() }</Callout>
			) }
			<ToggleRow
				title={
					push
						? __(
								"Back up that site's database first",
								'lw-migration'
							)
						: __(
								"Back up this site's database first",
								'lw-migration'
							)
				}
				checked={ safety }
				onChange={ setSafety }
			/>
		</ConfirmDialog>
	);
}
