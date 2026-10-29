/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import SectionLead from '../components/SectionLead';

/**
 * The section's title and what destinations are for; the shelf below adds a
 * new one, so there is no button. The skeleton shows it as it is.
 */
export default function DestinationsLead() {
	return (
		<SectionLead
			title={ __( 'Destinations', 'lw-migration' ) }
			intro={ __(
				'Backups are uploaded here: from a schedule, from the command line, or with "Upload…" in the backups list.',
				'lw-migration'
			) }
		/>
	);
}
