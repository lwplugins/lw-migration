/**
 * WordPress dependencies
 */
import { Button, DropZone, FormFileUpload } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Icon, upload } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { formatBytes } from '../data/format';

/**
 * Drag & drop or file dialog for one .wpress file.
 *
 * @param {Object}               props
 * @param {File|null}            props.file        The chosen file.
 * @param {(file: File) => void} props.onFile      Called with the chosen file.
 * @param {string}               props.describedBy Id of a refusal shown outside (optional).
 */
export default function FilePicker( { file = null, onFile, describedBy } ) {
	const [ error, setError ] = useState( '' );

	const take = ( files ) => {
		const chosen = files?.[ 0 ];
		if ( ! chosen ) {
			return;
		}
		if ( ! /\.wpress$/i.test( chosen.name ) ) {
			setError( __( 'Choose a .wpress file.', 'lw-migration' ) );
			return;
		}
		setError( '' );
		onFile( chosen );
	};

	return (
		<div className={ `lw-drop ${ file ? 'has-file' : '' }` }>
			<DropZone
				onFilesDrop={ take }
				label={ __( 'Drop the .wpress file here', 'lw-migration' ) }
			/>
			<Icon icon={ upload } size={ 36 } />
			<p className="lw-drop__text">
				{ file
					? `${ file.name } · ${ formatBytes( file.size ) }`
					: __(
							'Drag a .wpress file here, or choose one.',
							'lw-migration'
						) }
			</p>
			<FormFileUpload
				accept=".wpress"
				onChange={ ( event ) => take( event.target.files ) }
				render={ ( { openFileDialog } ) => (
					<Button
						__next40pxDefaultSize
						variant="secondary"
						// The file input itself is hidden; this button is the control.
						aria-describedby={ describedBy }
						onClick={ openFileDialog }
					>
						{ file
							? __( 'Choose another file', 'lw-migration' )
							: __( 'Choose a file', 'lw-migration' ) }
					</Button>
				) }
			/>
			{ error && (
				<p className="lw-drop__error" role="alert">
					{ error }
				</p>
			) }
		</div>
	);
}
