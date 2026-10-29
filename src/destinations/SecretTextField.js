/**
 * WordPress dependencies
 */
import { FormFileUpload, TextareaControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import SettingRow from '../components/SettingRow';
import { MAX_SECRET_BYTES } from './destinations';

/**
 * A multi-line secret (an SSH private key): pasted, or read from a file in
 * the browser (it goes nowhere but the save request). The stored value is
 * never shown and never filled in; empty keeps it.
 *
 * @param {Object}                  props
 * @param {string}                  props.id       Input id.
 * @param {Object}                  props.field    Schema field.
 * @param {string}                  props.value    Typed value.
 * @param {boolean}                 props.stored   A value is stored.
 * @param {string}                  props.error    Refusal text.
 * @param {(value: string) => void} props.onChange Sets the value.
 */
export default function SecretTextField( {
	id,
	field,
	value,
	stored,
	error,
	onChange,
} ) {
	const [ unread, setUnread ] = useState( '' );

	const readFile = async ( event ) => {
		const file = event.target.files?.[ 0 ];
		event.target.value = '';
		if ( ! file ) {
			return;
		}
		if ( file.size > MAX_SECRET_BYTES ) {
			setUnread(
				__(
					'This file is larger than 16 KB, so it is not a private key.',
					'lw-migration'
				)
			);
			return;
		}
		try {
			const text = ( await file.text() ).trim();
			setUnread( '' );
			onChange( text );
		} catch {
			setUnread( __( 'This file could not be read.', 'lw-migration' ) );
		}
	};
	const shownError = error || unread;

	return (
		<SettingRow
			title={ field.label }
			help={ field.help }
			htmlFor={ id }
			error={ shownError }
			stacked
		>
			<TextareaControl
				__nextHasNoMarginBottom
				id={ id }
				className="lw-secret-text"
				rows={ 6 }
				spellCheck={ false }
				autoComplete="off"
				autoCapitalize="off"
				autoCorrect="off"
				value={ value }
				placeholder={
					stored
						? __(
								'Stored — leave empty to keep it',
								'lw-migration'
							)
						: '-----BEGIN OPENSSH PRIVATE KEY-----'
				}
				onChange={ ( next ) => {
					setUnread( '' );
					onChange( next );
				} }
				aria-invalid={ shownError ? true : undefined }
				aria-describedby={ shownError ? `${ id }-error` : undefined }
			/>
			<FormFileUpload
				className="lw-secret-text__file"
				variant="secondary"
				onChange={ readFile }
			>
				{ __( 'Read from a file…', 'lw-migration' ) }
			</FormFileUpload>
		</SettingRow>
	);
}
