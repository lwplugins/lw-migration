/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { DestinationLabel } from '../components/DestinationIcon';
import StatusBadge from '../components/StatusBadge';
import { cloudBadges } from './cloudStatus';
import DetailSection from './DetailSection';
import { CopyIcon, DiskIcon, localText } from './WhereIcons';

/**
 * Where the backup lives: its file here, then each destination it was
 * uploaded to with the state of that upload.
 *
 * @param {Object}   props
 * @param {Object}   props.item         Backup row.
 * @param {Object[]} props.destinations Destination views.
 */
export default function DetailCopies( { item, destinations } ) {
	return (
		<DetailSection title={ __( 'Where it lives', 'lw-migration' ) }>
			<ul className="lw-bk-copies">
				<li>
					<span className="lw-dest-label">
						<DiskIcon size={ 14 } />
						{ localText( item ) }
					</span>
					<StatusBadge status="ok">
						{ __( 'present', 'lw-migration' ) }
					</StatusBadge>
				</li>
				{ cloudBadges( item, destinations ).map( ( badge ) => (
					<li key={ badge.id }>
						{ badge.destination ? (
							<DestinationLabel item={ badge.destination } />
						) : (
							<span className="lw-dest-label">
								<CopyIcon badge={ badge } size={ 14 } />
								{ badge.name }
							</span>
						) }
						<StatusBadge status={ badge.tone }>
							{ badge.label }
						</StatusBadge>
					</li>
				) ) }
			</ul>
		</DetailSection>
	);
}
