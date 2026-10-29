/**
 * WordPress dependencies
 */
import { Notice } from '@wordpress/components';

/**
 * Internal dependencies
 */
import LoginNotice from '../components/LoginNotice';
import { errorMessage } from '../data/errors';
import { backupProblem } from './backupList';

/**
 * What to show for a refused backup action.
 *
 * @param {Object} error Rejection of request().
 * @param {string} hint  Our text for the refused control ('' = none).
 * @return {{message: string, loggedOut: boolean, gone: boolean}} Problem.
 */
export function describeProblem( error, hint = '' ) {
	const problem = backupProblem( error, hint );
	return {
		message:
			( ! problem.loggedOut && problem.text ) || errorMessage( error ),
		loggedOut: problem.loggedOut,
		gone: problem.gone,
	};
}

/**
 * The refusal of a backup action; a logout is the shared LoginNotice.
 *
 * @param {Object}                                     props
 * @param {{message: string, loggedOut: boolean}|null} props.problem   From describeProblem().
 * @param {string}                                     props.className Extra class.
 */
export default function ProblemNotice( { problem, className } ) {
	if ( ! problem ) {
		return null;
	}
	if ( problem.loggedOut ) {
		return <LoginNotice tab="backups" className={ className } />;
	}
	return (
		<Notice className={ className } status="error" isDismissible={ false }>
			<p>{ problem.message }</p>
		</Notice>
	);
}
