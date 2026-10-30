/**
 * WordPress dependencies
 */
import { Button, Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import FilePicker from '../components/FilePicker';
import Section from '../components/Section';
import { formatBytes } from '../data/format';

/**
 * After a reload (or a failed slice) the upload needs the same file again.
 * The way out sits in the card too: the footer's Cancel is below the fold
 * behind the drop zone, where a stuck upload's user does not find it.
 *
 * @param {Object}               props
 * @param {Object}               props.expected   Job source { name, size }.
 * @param {string}               props.error      Why the last attempt stopped.
 * @param {(file: File) => void} props.onFile     Called with the chosen file.
 * @param {() => void}           props.onCancel   Ask to cancel the job.
 * @param {boolean}              props.cancelling The cancel is under way.
 */
export default function UploadPrompt( {
	expected,
	error,
	onFile,
	onCancel,
	cancelling,
} ) {
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
			<div className="lw-actions">
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					disabled={ cancelling }
					accessibleWhenDisabled
					onClick={ onCancel }
				>
					{ cancelling
						? __( 'Cancelling…', 'lw-migration' )
						: __( 'Cancel the restore', 'lw-migration' ) }
				</Button>
			</div>
		</Section>
	);
}
