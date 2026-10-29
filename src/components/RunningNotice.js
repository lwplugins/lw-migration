/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { tabOf } from '../job/phases';
import Callout from './Callout';

const TEXTS = {
	export: __( 'A backup is in progress.', 'lw-migration' ),
	settings: __( 'The backups are being moved.', 'lw-migration' ),
	import: __( 'A restore is in progress.', 'lw-migration' ),
};

/**
 * Shown on the other sections while a job runs or waits.
 *
 * @param {Object} props
 * @param {Object} props.job Job view.
 */
export default function RunningNotice( { job } ) {
	const tab = tabOf( job );
	return (
		<Callout>
			{ TEXTS[ tab ] }{ ' ' }
			<a href={ `#${ tab }` }>{ __( 'Open it', 'lw-migration' ) }</a>
		</Callout>
	);
}
