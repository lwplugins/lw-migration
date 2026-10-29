/**
 * WordPress dependencies
 */
import { TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { Icon, info } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import Section from '../components/Section';
import Segmented from '../components/Segmented';
import SettingRow from '../components/SettingRow';
import ToggleRow from '../components/ToggleRow';
import { marked } from '../views/optionControls';

const COMPRESSION = {
	none: __( 'None', 'lw-migration' ),
	gzip: 'gzip',
	bzip2: 'bzip2',
};

/**
 * One line per compression choice: what it costs and gives.
 *
 * @param {string} compression 'none', 'gzip' or 'bzip2'.
 * @return {string} Text.
 */
export const compressionHelp = ( compression ) =>
	( {
		none: __( 'A faster backup, a bigger file.', 'lw-migration' ),
		gzip: __( 'A smaller file, a slightly slower backup.', 'lw-migration' ),
		bzip2: __( 'The smallest file, the slowest backup.', 'lw-migration' ),
	} )[ compression ] || '';

/**
 * "The file": compression, and the password protection with its two fields
 * and what it does and does not protect. The password lives in ExportView's
 * state only; the fields stay mounted (hidden) while protection is off.
 *
 * @param {Object}                  props
 * @param {Object}                  props.options    Form state.
 * @param {(patch: Object) => void} props.onChange   Receives a form-state patch.
 * @param {string}                  props.password   Password.
 * @param {string}                  props.again      Password repeated.
 * @param {(value: string) => void} props.onPassword Setter.
 * @param {(value: string) => void} props.onAgain    Setter.
 * @param {Object}                  props.errors     { field: text }.
 */
export default function FileOptions( {
	options,
	onChange,
	password,
	again,
	onPassword,
	onAgain,
	errors,
} ) {
	const differ = '' !== again && password !== again;
	const againError =
		errors.password ||
		( differ ? __( 'The two passwords differ.', 'lw-migration' ) : '' );

	return (
		<Section title={ __( 'The file', 'lw-migration' ) }>
			<SettingRow
				inline
				title={ __( 'Compression', 'lw-migration' ) }
				help={
					<span id="lw-file-compression-help">
						{ compressionHelp( options.compression ) }
					</span>
				}
			>
				<Segmented
					label={ __( 'Compression', 'lw-migration' ) }
					options={ COMPRESSION }
					value={ options.compression }
					onChange={ ( compression ) => onChange( { compression } ) }
					describedBy="lw-file-compression-help"
				/>
			</SettingRow>
			<div className="lw-file__protect">
				<ToggleRow
					inline
					title={ __( 'Encrypt with a password', 'lw-migration' ) }
					help={ __(
						'The contents can only be unpacked with the password.',
						'lw-migration'
					) }
					checked={ options.encrypt }
					onChange={ ( encrypt ) => onChange( { encrypt } ) }
				/>
				<div
					className="lw-file__passwords"
					hidden={ ! options.encrypt }
				>
					<div className="lw-options__passwords">
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							type="password"
							autoComplete="new-password"
							label={ __( 'Password', 'lw-migration' ) }
							value={ password }
							onChange={ onPassword }
							{ ...( errors.password
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
							{ ...marked(
								againError,
								undefined,
								Boolean( errors.password )
							) }
						/>
					</div>
					<p className="lw-file__note">
						<Icon icon={ info } size={ 18 } />
						<span>
							{ __(
								'The password protects confidentiality only: use a long, random one, because a short one can be guessed offline. A changed file is not detected cryptographically, so keep backups on storage you trust.',
								'lw-migration'
							) }{ ' ' }
							{ __(
								'Without the password the backup cannot be restored, so keep it safe. This page forgets it once the backup starts.',
								'lw-migration'
							) }
						</span>
					</p>
				</div>
			</div>
		</Section>
	);
}
