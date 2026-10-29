/**
 * WordPress dependencies
 */
import { Button, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { LOGIN_URL } from '../data/boot';
import { errorMessage } from '../data/errors';
import { loginUrlFor } from '../data/login';

const LABEL = () => __( 'Log in again', 'lw-migration' );

/**
 * The "Log in again" button (for places that take an action element).
 *
 * @param {Object} props
 * @param {string} props.tab Tab to land on after logging in.
 */
export function LoginButton( { tab } ) {
	const url = loginUrlFor( LOGIN_URL, tab );
	if ( ! url ) {
		return null;
	}
	return (
		<Button __next40pxDefaultSize variant="primary" href={ url }>
			{ LABEL() }
		</Button>
	);
}

/**
 * The one way a logout is shown (a warning with the login link), in every
 * section, the job screen and the load errors.
 *
 * @param {Object}  props
 * @param {string}  props.tab       Tab to land on after logging in.
 * @param {string}  props.className Extra class.
 * @param {Element} props.children  Text (default: the general logout text).
 */
export default function LoginNotice( { tab, className, children } ) {
	return (
		<Notice
			className={ className }
			status="warning"
			isDismissible={ false }
		>
			<p>
				{ children ||
					errorMessage( {
						status: 401,
						code: 'rest_not_logged_in',
					} ) }
			</p>
			<LoginButton tab={ tab } />
		</Notice>
	);
}
