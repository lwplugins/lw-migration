/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Icon, caution } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { attentionAction } from './status';

/**
 * What needs a look (GET /overview `attention`, in the server's order): the
 * server's sentence and a link to the section that fixes it. Shown only
 * when there is something.
 *
 * @param {Object}   props
 * @param {Object[]} props.items Attention items.
 */
export default function AttentionList( { items } ) {
	return (
		<section
			className="lw-overview-attention"
			aria-label={ __( 'Needs a look', 'lw-migration' ) }
		>
			<Icon icon={ caution } size={ 24 } />
			<ul>
				{ items.map( ( item, index ) => (
					<li
						key={ `${ item.kind }-${ item.destination }-${ index }` }
					>
						<span>{ item.text }</span>
						<a href={ `#${ item.action }` }>
							{ attentionAction( item.kind ) }
						</a>
					</li>
				) ) }
			</ul>
		</section>
	);
}
