/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Icon, brush, media, plugins, table } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import StatusBadge from '../components/StatusBadge';
import { partLine, partStates } from './parts';

const PARTS = {
	database: { icon: table, name: __( 'Database', 'lw-migration' ) },
	media: { icon: media, name: __( 'Media', 'lw-migration' ) },
	plugins: { icon: plugins, name: __( 'Plugins', 'lw-migration' ) },
	themes: { icon: brush, name: __( 'Themes', 'lw-migration' ) },
};

const BADGES = {
	replaced: { status: 'warning', text: __( 'replaced', 'lw-migration' ) },
	absent: {
		status: 'ok',
		text: __( 'not in the backup', 'lw-migration' ),
	},
	shared: { status: 'ok', text: __( 'kept', 'lw-migration' ) },
	unknown: { status: 'idle', text: __( 'unknown', 'lw-migration' ) },
};

/**
 * "What gets replaced": one tile per part with its state (parts.js) and the
 * line under it.
 *
 * @param {Object} props
 * @param {Object} props.summary Confirm summary.
 */
export default function ReplaceGrid( { summary } ) {
	return (
		<div className="lw-replace">
			<h4 className="lw-replace__title">
				{ __( 'What gets replaced', 'lw-migration' ) }
			</h4>
			<ul className="lw-replace__grid">
				{ partStates( summary ).map( ( part ) => (
					<li
						key={ part.id }
						className={ `lw-replace__part is-${ part.state }` }
					>
						<span className="lw-replace__head">
							<Icon icon={ PARTS[ part.id ].icon } size={ 20 } />
							<span className="lw-replace__name">
								{ PARTS[ part.id ].name }
							</span>
							<StatusBadge status={ BADGES[ part.state ].status }>
								{ BADGES[ part.state ].text }
							</StatusBadge>
						</span>
						<span className="lw-replace__line">
							{ partLine( part, summary.keepsThisPlugin ) }
						</span>
					</li>
				) ) }
			</ul>
		</div>
	);
}
