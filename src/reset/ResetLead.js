/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import SectionLead from '../components/SectionLead';

/**
 * The section's title and what it does, above the warning (also over the
 * loading shapes).
 */
export default function ResetLead() {
	return (
		<SectionLead
			title={ __( 'Reset', 'lw-migration' ) }
			intro={ __(
				'Put plugins, themes, media or the database back to how a fresh WordPress starts, or all of them at once.',
				'lw-migration'
			) }
		/>
	);
}
