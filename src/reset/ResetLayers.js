/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import {
	Icon,
	caution,
	commentAuthorAvatar,
	shield,
	wordpress,
} from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { ICONS } from './ResetChoices';
import { resetLayers } from './layers';

const BASE_ICONS = { lwm: shield, core: wordpress, you: commentAuthorAvatar };
const BEDROCK_ID = 'lw-reset-bedrock';

/**
 * @return {Object<string, string>} Each layer's name.
 */
const layerNames = () => ( {
	plugins: __( 'Plugins', 'lw-migration' ),
	themes: __( 'Themes', 'lw-migration' ),
	media: __( 'Media library', 'lw-migration' ),
	database: __( 'Database', 'lw-migration' ),
} );

/**
 * @param {Object} row A layer or base row.
 * @return {string} Its fate: `goes`, `disk` (stays on disk only) or `stays`.
 */
const fateOf = ( row ) => {
	if ( row.goes ) {
		return 'goes';
	}
	return row.disk ? 'disk' : 'stays';
};

/**
 * The tag of a row: its fate in words, never by colour alone.
 *
 * @param {Object} props
 * @param {string} props.fate `goes`, `disk` or `stays`.
 */
function Tag( { fate } ) {
	const words = {
		goes: __( 'Goes', 'lw-migration' ),
		disk: __( 'Stays on disk', 'lw-migration' ),
		stays: __( 'Stays', 'lw-migration' ),
	};
	return (
		<span className={ `lw-reset-tag is-${ fate }` }>{ words[ fate ] }</span>
	);
}

/**
 * One row: icon, a bold title, the lines under it, and the tag.
 *
 * @param {Object}   props
 * @param {Object}   props.icon      Icon.
 * @param {string}   props.title     Bold title.
 * @param {string[]} props.lines     The lines under it.
 * @param {string}   props.fate      `goes`, `disk` or `stays`.
 * @param {string}   props.className Row modifier.
 */
function Row( { icon, title, lines, fate, className } ) {
	return (
		<li className={ `lw-reset-layer ${ className }` }>
			<Icon icon={ icon } size={ 20 } />
			<span className="lw-reset-layer__what">
				<b className="lw-reset-layer__name">{ title }</b>
				{ lines.map( ( line ) => (
					<span key={ line } className="lw-reset-layer__line">
						{ line }
					</span>
				) ) }
			</span>
			<Tag fate={ fate } />
		</li>
	);
}

/**
 * What goes and what stays, as the layers of the site: plugins, themes,
 * media and the database, each struck through when the chosen reset
 * removes it (a database reset leaves plugins and media on disk only,
 * tagged so); under a rule, what no reset touches (LW Migration, the
 * WordPress core and, when the database goes, you), each under its title.
 * The drop-in notice closes it when plugins go.
 *
 * @param {Object} props
 * @param {string} props.type    Chosen reset type.
 * @param {Object} props.summary `GET /reset`.
 */
export default function ResetLayers( { type, summary } ) {
	const { layers, base, note } = resetLayers( type, summary );
	const names = layerNames();

	return (
		<>
			<ul
				className="lw-reset-layers"
				aria-label={ __( 'The layers of the site', 'lw-migration' ) }
			>
				{ layers.map( ( layer ) => (
					<Row
						key={ layer.key }
						icon={ ICONS[ layer.key ] }
						title={ names[ layer.key ] }
						lines={ layer.lines }
						fate={ fateOf( layer ) }
						className={ `is-${ fateOf( layer ) }` }
					/>
				) ) }
			</ul>
			<p id={ BEDROCK_ID } className="lw-reset-bedrock">
				{ __( 'Nothing here is touched by any reset', 'lw-migration' ) }
			</p>
			<ul className="lw-reset-layers" aria-labelledby={ BEDROCK_ID }>
				{ base.map( ( row ) => (
					<Row
						key={ row.key }
						icon={ BASE_ICONS[ row.key ] }
						title={ row.title }
						lines={ row.lines }
						fate="stays"
						className="is-base"
					/>
				) ) }
			</ul>
			{ note && (
				<p className="lw-reset-warnline">
					<Icon icon={ caution } size={ 18 } />
					<span>{ note }</span>
				</p>
			) }
		</>
	);
}
