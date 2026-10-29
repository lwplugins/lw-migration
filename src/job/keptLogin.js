/**
 * Internal dependencies
 */
import { drivenElsewhere } from '../links/links';

/**
 * Whether the server says a finished restore kept the importing admin's
 * login (`result.loginKept`, set by the import's PreserveStep). Missing or
 * anything but `true` = no.
 *
 * @param {Object} job Job view.
 * @return {boolean} Kept.
 */
const kept = ( job ) =>
	'import' === job?.type &&
	'done' === job.status &&
	true === job.result?.loginKept;

/**
 * Whether the result may tell the viewer that their login stayed the same:
 * a restore this site ran for itself (not one a connected site pushed here,
 * where only the code-maker's login is kept).
 *
 * @param {Object} job Job view.
 * @return {boolean} Show the kept-login line.
 */
export const keptLogin = ( job ) => kept( job ) && ! drivenElsewhere( job );

/**
 * Whether a restore a connected site pushed here kept the login of the
 * admin who made the pairing code (the only one it keeps).
 *
 * @param {Object} job Job view.
 * @return {boolean} Show the code-maker line.
 */
export const keptByCodeMaker = ( job ) => kept( job ) && drivenElsewhere( job );
