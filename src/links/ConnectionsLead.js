/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import SectionLead from '../components/SectionLead';

/**
 * The section's title and what it does (also over the loading shapes).
 */
export default function ConnectionsLead() {
	return (
		<SectionLead
			title={ __( 'Connections', 'lw-migration' ) }
			intro={ __(
				'Send this site to another site of yours, or bring another site here, directly between the two sites.',
				'lw-migration'
			) }
		/>
	);
}
