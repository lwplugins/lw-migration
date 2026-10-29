/**
 * WordPress dependencies
 */
import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { errorMessage, isLoggedOut } from '../data/errors';
import LoginNotice from './LoginNotice';

/**
 * Load failure with a retry button; a logout shows the login link instead.
 *
 * @param {Object}     props
 * @param {Object}     props.error   Rejection of the load.
 * @param {string}     props.tab     Tab to land on after logging in.
 * @param {() => void} props.onRetry Retry.
 */
export default function LoadError( { error, tab, onRetry } ) {
	if ( isLoggedOut( error ) ) {
		return <LoginNotice tab={ tab } />;
	}
	return (
		<Notice
			status="error"
			isDismissible={ false }
			actions={ [
				{ label: __( 'Try again', 'lw-migration' ), onClick: onRetry },
			] }
		>
			{ errorMessage( error ) }
		</Notice>
	);
}
