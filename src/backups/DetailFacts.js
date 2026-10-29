/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { NETWORK, OWN_FOLDER } from '../data/boot';
import { formatSiteTime } from '../data/format';
import DetailSection from './DetailSection';

const MADE = {
	year: 'numeric',
	month: 'short',
	day: 'numeric',
	hour: '2-digit',
	minute: '2-digit',
};

/**
 * When and where the backup was made, and its label (editable in our own
 * folder only).
 *
 * @param {Object}     props
 * @param {Object}     props.item    Backup row.
 * @param {() => void} props.onLabel Opens the label dialog.
 */
export default function DetailFacts( { item, onLabel } ) {
	const own = OWN_FOLDER === item.folder;
	return (
		<DetailSection title={ __( 'Details', 'lw-migration' ) }>
			<dl className="lw-bk-facts">
				<dt>{ __( 'Made', 'lw-migration' ) }</dt>
				<dd>{ formatSiteTime( item.mtime, MADE ) }</dd>
				<dt>{ __( 'From', 'lw-migration' ) }</dt>
				<dd>
					{ item.fromSite ||
						( NETWORK
							? __( 'This network', 'lw-migration' )
							: __( 'This site', 'lw-migration' ) ) }
				</dd>
				<dt>{ __( 'Label', 'lw-migration' ) }</dt>
				<dd>
					<span>{ item.label || '—' }</span>
					{ own && (
						<Button variant="link" onClick={ onLabel }>
							{ item.label
								? __( 'Change', 'lw-migration' )
								: __( 'Add', 'lw-migration' ) }
						</Button>
					) }
				</dd>
			</dl>
		</DetailSection>
	);
}
