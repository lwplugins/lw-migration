/**
 * WordPress dependencies
 */
import { FormToggle } from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';

/**
 * Internal dependencies
 */
import SettingRow from './SettingRow';

/**
 * SettingRow with a switch on the right and a live state line next to it.
 *
 * @param {Object}                     props
 * @param {string}                     props.title    Setting name (labels the switch).
 * @param {Element}                    props.help     Description.
 * @param {boolean}                    props.checked  Value.
 * @param {(checked: boolean) => void} props.onChange Receives the new boolean.
 * @param {string}                     props.onText   State text when on.
 * @param {string}                     props.offText  State text when off.
 * @param {boolean}                    props.disabled Disabled.
 * @param {string}                     props.id       Switch id (default: a generated one).
 * @param {string}                     props.error    Refusal text under the switch ('' = valid).
 * @param {boolean}                    props.inline   Switch at the row's right edge (SettingRow).
 */
export default function ToggleRow( {
	title,
	help,
	checked,
	onChange,
	onText,
	offText,
	disabled = false,
	id: givenId = '',
	error = '',
	inline = false,
} ) {
	const generated = useInstanceId( ToggleRow, 'lw-admin-toggle' );
	const id = givenId || generated;

	return (
		<SettingRow
			title={ title }
			help={ help }
			htmlFor={ id }
			error={ error }
			inline={ inline }
		>
			<span className="lw-admin-switch">
				<FormToggle
					id={ id }
					checked={ checked }
					disabled={ disabled }
					aria-invalid={ error ? true : undefined }
					aria-describedby={ error ? `${ id }-error` : undefined }
					onChange={ ( event ) => onChange( event.target.checked ) }
				/>
				{ ( onText || offText ) && (
					<span className="lw-admin-switch__state">
						{ checked ? onText : offText }
					</span>
				) }
			</span>
		</SettingRow>
	);
}
