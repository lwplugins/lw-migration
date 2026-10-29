/**
 * The Restore section's three steps (Source → Check → Restore) and where a
 * restore job is on them.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

export const STEPS = [
	__( 'Source', 'lw-migration' ),
	__( 'Check', 'lw-migration' ),
	__( 'Restore', 'lw-migration' ),
];

/**
 * The current step of a restore job: Check (1) until its confirmation is
 * answered — receiving the archive, its password and the check itself —
 * Restore (2) after it, and 3 (every step done) once it finished well.
 *
 * @param {Object} job Job view.
 * @return {number} 1, 2 or 3.
 */
export function restoreStep( job ) {
	if ( 'done' === job.status ) {
		return 3;
	}
	const confirm = ( job.steps || [] ).indexOf( 'confirm' );
	return confirm >= 0 && job.stepIndex > confirm ? 2 : 1;
}
