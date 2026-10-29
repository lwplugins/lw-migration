/**
 * WordPress dependencies
 */
import { SelectControl, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import SettingRow from '../components/SettingRow';
import ToggleRow from '../components/ToggleRow';
import OAuthPanel from './OAuthPanel';
import SecretTextField from './SecretTextField';
import { isSecret, isSecretText, toggleChecked } from './destinations';
import { isConnectMarker } from './oauth';

/**
 * @param {Object} field Schema field (not a select or a toggle).
 * @return {string} Input type: a port is typed as a number (still sent as text).
 */
const inputType = ( field ) => {
	if ( isSecret( field ) ) {
		return 'password';
	}
	return 'number' === field.kind ? 'number' : 'text';
};

/**
 * One schema field.
 *
 * @param {Object}                          props
 * @param {Object}                          props.field     Schema field.
 * @param {Object}                          props.form      Form state.
 * @param {Object}                          props.type      Schema type.
 * @param {Object|null}                     props.item      The stored destination (null: a new one).
 * @param {boolean}                         props.stored    A secret is stored (and still of use).
 * @param {string}                          props.error     Refusal text.
 * @param {(value: boolean|string) => void} props.onSetting Sets a setting.
 * @param {(value: string) => void}         props.onSecret  Sets a secret.
 * @param {string}                          props.idPrefix  Ids are `<idPrefix>-<key>` (default: lw-destination).
 */
export default function Field( {
	field,
	form,
	type,
	item,
	stored,
	error,
	onSetting,
	onSecret,
	idPrefix = 'lw-destination',
} ) {
	if ( isConnectMarker( field ) ) {
		return (
			<OAuthPanel
				type={ type }
				item={ item }
				settings={ form.settings }
			/>
		);
	}
	const id = `${ idPrefix }-${ field.key }`;
	if ( isSecretText( field ) ) {
		return (
			<SecretTextField
				id={ id }
				field={ field }
				value={ form.secrets[ field.key ] }
				stored={ stored }
				error={ error }
				onChange={ onSecret }
			/>
		);
	}
	if ( 'toggle' === field.kind ) {
		return (
			<ToggleRow
				id={ id }
				title={ field.label }
				help={ field.help }
				checked={ toggleChecked( field, form.settings ) }
				onChange={ onSetting }
				error={ error }
			/>
		);
	}
	const secret = isSecret( field );
	const control =
		'select' === field.kind ? (
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				id={ id }
				value={ form.settings[ field.key ] }
				options={ field.options }
				onChange={ onSetting }
				aria-invalid={ error ? true : undefined }
				aria-describedby={ error ? `${ id }-error` : undefined }
			/>
		) : (
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				id={ id }
				type={ inputType( field ) }
				autoComplete={ secret ? 'new-password' : 'off' }
				spellCheck={ false }
				placeholder={
					secret && stored
						? __(
								'Stored — leave empty to keep it',
								'lw-migration'
							)
						: undefined
				}
				value={
					secret
						? form.secrets[ field.key ]
						: ( form.settings[ field.key ] ?? '' )
				}
				onChange={ secret ? onSecret : onSetting }
				aria-invalid={ error ? true : undefined }
				aria-describedby={ error ? `${ id }-error` : undefined }
			/>
		);

	return (
		<SettingRow
			title={ field.label }
			help={ field.help }
			htmlFor={ id }
			error={ error }
			stacked
		>
			{ control }
		</SettingRow>
	);
}
