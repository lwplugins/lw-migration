/**
 * WordPress dependencies
 */
import { Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import FilePicker from '../components/FilePicker';
import Section from '../components/Section';
import { formatBytes } from '../data/format';

/**
 * After a reload (or a failed slice) the upload needs the same file again.
 *
 * @param {Object}               props
 * @param {Object}               props.expected Job source { name, size }.
 * @param {string}               props.error    Why the last attempt stopped.
 * @param {(file: File) => void} props.onFile   Called with the chosen file.
 */
export default function UploadPrompt( { expected, error, onFile } ) {
	return (
		<Section
			title={ __( 'Choose the file again', 'lw-migration' ) }
			description={ sprintf(
				/* translators: 1: file name, 2: file size. */
				__(
					'The upload of %1$s (%2$s) stopped. Choose the same file and it goes on where it stopped.',
					'lw-migration'
				),
				expected?.name || '',
				formatBytes( expected?.size || 0 )
			) }
		>
			<FilePicker onFile={ onFile } />
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
		</Section>
	);
}
