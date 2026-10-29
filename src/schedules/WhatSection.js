/**
 * WordPress dependencies
 */
import { CheckboxControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import Callout from '../components/Callout';
import ExportOptions from '../views/ExportOptions';
import PasswordFields from '../views/PasswordFields';
import { parts } from './what';

/**
 * The editor's "What it backs up": the four parts as checks, the password,
 * and the folded Advanced settings for everything else. The checks set the
 * same request flags as the Backup section (a part unchecked = its no*
 * flag on).
 *
 * @param {Object}                  props
 * @param {Object}                  props.form       Form state.
 * @param {Object}                  props.errors     { field: text }.
 * @param {(patch: Object) => void} props.onExport   Merges into the export state.
 * @param {string}                  props.password   Typed password.
 * @param {string}                  props.again      Typed again.
 * @param {(value: string) => void} props.onPassword Setter.
 * @param {(value: string) => void} props.onAgain    Setter.
 * @param {boolean}                 props.open       Advanced settings unfolded.
 * @param {() => void}              props.onToggle   Folds or unfolds them.
 */
export default function WhatSection( {
	form,
	errors,
	onExport,
	password,
	again,
	onPassword,
	onAgain,
	open,
	onToggle,
} ) {
	const options = form.export;
	return (
		<div
			className="lw-options__group"
			role="group"
			aria-labelledby="lw-schedule-what"
		>
			<h4 id="lw-schedule-what" className="lw-options__title">
				{ __( 'What it backs up', 'lw-migration' ) }
			</h4>
			<div className="lw-sheet-checks">
				{ parts().map( ( part ) => (
					<CheckboxControl
						key={ part.key }
						__nextHasNoMarginBottom
						label={ part.label }
						checked={ ! options[ part.flag ] }
						onChange={ ( on ) =>
							onExport( { [ part.flag ]: ! on } )
						}
					/>
				) ) }
			</div>
			<PasswordFields
				encrypt={ options.encrypt }
				onEncrypt={ ( encrypt ) => onExport( { encrypt } ) }
				password={ password }
				again={ again }
				onPassword={ onPassword }
				onAgain={ onAgain }
				error={ errors.password || '' }
				help={ __(
					'Without the password these backups cannot be restored, so keep a copy. The schedule keeps it encrypted on this server for its runs and never shows it again.',
					'lw-migration'
				) }
			/>
			{ options.encrypt && form.hasPassword && (
				<Callout>
					{ __(
						'A password is stored for this schedule. Leave the password fields empty to keep it, or type a new one.',
						'lw-migration'
					) }
				</Callout>
			) }
			<ExportOptions
				open={ open }
				onToggle={ onToggle }
				options={ options }
				onChange={ onExport }
				errors={ errors }
			/>
		</div>
	);
}
