/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { Icon, archive, cloud, file, globe } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { formatNumber } from '../data/format';

/**
 * @param {number|null} count Backups in the list (null while it loads).
 * @return {Object[]} The four sources in page order.
 */
const sources = ( count ) => [
	{
		id: 'backup',
		icon: archive,
		title: __( 'From a backup', 'lw-migration' ),
		text:
			null === count
				? __( 'One of the backups on this site.', 'lw-migration' )
				: sprintf(
						/* translators: %s: number of backups on this site. */
						_n(
							'%s backup on this site.',
							'%s backups on this site.',
							count,
							'lw-migration'
						),
						formatNumber( count )
					),
	},
	{
		id: 'upload',
		icon: file,
		title: __( 'From a file', 'lw-migration' ),
		text: __(
			'Upload a .wpress file from your computer, in parts.',
			'lw-migration'
		),
	},
	{
		id: 'url',
		icon: globe,
		title: __( 'From an address', 'lw-migration' ),
		text: __(
			'A download link, for example a share link.',
			'lw-migration'
		),
	},
	{
		id: 'cloud',
		icon: cloud,
		title: __( 'From the cloud', 'lw-migration' ),
		text: __( 'One of the backups at your destinations.', 'lw-migration' ),
	},
];

/**
 * Where to restore from: four tiles, one pressed.
 *
 * @param {Object}                   props
 * @param {string}                   props.value    Chosen source.
 * @param {number|null}              props.count    Backups in the list (null while it loads).
 * @param {(source: string) => void} props.onChange Receives a source.
 */
export default function SourceTiles( { value, count, onChange } ) {
	return (
		<div
			className="lw-sources"
			role="group"
			aria-label={ __( 'Source', 'lw-migration' ) }
		>
			{ sources( count ).map( ( source ) => (
				<button
					key={ source.id }
					type="button"
					className="lw-source"
					aria-pressed={ value === source.id }
					onClick={ () => onChange( source.id ) }
				>
					<span className="lw-source__icon" aria-hidden="true">
						<Icon icon={ source.icon } size={ 22 } />
					</span>
					<span className="lw-source__title">{ source.title }</span>
					<span className="lw-source__text">{ source.text }</span>
				</button>
			) ) }
		</div>
	);
}
