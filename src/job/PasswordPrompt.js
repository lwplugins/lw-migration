/**
 * WordPress dependencies
 */
import { Button, Notice, TextControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import Section from '../components/Section';
import { errorMessage, isLoggedOut } from '../data/errors';

/**
 * @param {Object}                                props
 * @param {string}                                props.reason     encrypted|wrong.
 * @param {(password: string) => Promise<Object>} props.onSubmit   Sends the password.
 * @param {() => void}                            props.onCancel   Ask to cancel the job.
 * @param {boolean}                               props.cancelling The cancel is on its way.
 */
export default function PasswordPrompt( {
	reason,
	onSubmit,
	onCancel,
	cancelling = false,
} ) {
	const [ value, setValue ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const wrong = 'wrong' === reason && '' === value;

	const submit = async ( event ) => {
		event.preventDefault();
		setBusy( true );
		setError( '' );
		try {
			await onSubmit( value );
		} catch ( e ) {
			// A logout is shown once, by the job screen's login notice.
			setError( isLoggedOut( e ) ? '' : errorMessage( e ) );
			setBusy( false );
		}
	};

	return (
		<Section
			title={ __( 'This archive is encrypted', 'lw-migration' ) }
			description={
				'wrong' === reason
					? __(
							'That password did not open the archive. Try again.',
							'lw-migration'
						)
					: __(
							'Enter the password that was set when the backup was made.',
							'lw-migration'
						)
			}
		>
			<form className="lw-inline-form" onSubmit={ submit }>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					type="password"
					autoComplete="off"
					label={ __( 'Archive password', 'lw-migration' ) }
					value={ value }
					onChange={ setValue }
					// A wrong password stays here, with the field marked (the description says why).
					{ ...( wrong
						? { className: 'is-invalid', 'aria-invalid': true }
						: {} ) }
				/>
				<Button
					__next40pxDefaultSize
					type="submit"
					variant="primary"
					isBusy={ busy }
					disabled={ busy || '' === value }
				>
					{ __( 'Continue', 'lw-migration' ) }
				</Button>
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
			</form>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
		</Section>
	);
}
