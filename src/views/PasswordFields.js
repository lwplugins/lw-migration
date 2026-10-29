/**
 * WordPress dependencies
 */
import { CheckboxControl, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { marked } from './optionControls';

/**
 * "Protect the backup with a password" and the two password fields (shown
 * while it is checked; they stay mounted, so nothing typed is lost).
 *
 * @param {Object}                  props
 * @param {boolean}                 props.encrypt    Checked.
 * @param {(on: boolean) => void}   props.onEncrypt  Receives the new state.
 * @param {string}                  props.password   Password.
 * @param {string}                  props.again      Password repeated.
 * @param {(value: string) => void} props.onPassword Setter.
 * @param {(value: string) => void} props.onAgain    Setter.
 * @param {string}                  props.error      Refusal text of the password ('' = none).
 * @param {string}                  props.help       What happens to the password.
 */
export default function PasswordFields( {
	encrypt,
	onEncrypt,
	password,
	again,
	onPassword,
	onAgain,
	error,
	help,
} ) {
	const differ = '' !== again && password !== again;
	const againError =
		error ||
		( differ ? __( 'The two passwords differ.', 'lw-migration' ) : '' );

	return (
		<>
			<CheckboxControl
				__nextHasNoMarginBottom
				label={ __(
					'Protect the backup with a password',
					'lw-migration'
				) }
				help={
					<>
						{ help }{ ' ' }
						{ __(
							'The password protects confidentiality only: use a long, random one, because a short one can be guessed offline. A changed file is not detected cryptographically, so keep backups on storage you trust.',
							'lw-migration'
						) }
					</>
				}
				checked={ encrypt }
				onChange={ onEncrypt }
			/>
			<div className="lw-options__passwords" hidden={ ! encrypt }>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					type="password"
					autoComplete="new-password"
					label={ __( 'Password', 'lw-migration' ) }
					value={ password }
					onChange={ onPassword }
					{ ...( error
						? {
								'aria-invalid': true,
								'data-refused': true,
								className: 'is-invalid',
							}
						: {} ) }
				/>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					type="password"
					autoComplete="new-password"
					label={ __( 'Password again', 'lw-migration' ) }
					value={ again }
					onChange={ onAgain }
					{ ...marked( againError, undefined, Boolean( error ) ) }
				/>
			</div>
		</>
	);
}
