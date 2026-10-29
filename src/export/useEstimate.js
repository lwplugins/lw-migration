/**
 * WordPress dependencies
 */
import { useEffect, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { api } from '../data/api';
import { pollEstimate } from './pollEstimate';

/**
 * The size estimate of the Backup section: asked on mount, asked again while
 * it is still counting, never after the section unmounts.
 *
 * @return {{estimate: ?Object, error: ?Object}} The last answer and the last failure.
 */
export default function useEstimate() {
	const [ state, setState ] = useState( { estimate: null, error: null } );

	useEffect(
		() =>
			pollEstimate( {
				fetcher: api.exportEstimate,
				onData: ( estimate ) => setState( { estimate, error: null } ),
				onError: ( error ) =>
					setState( ( prev ) => ( { ...prev, error } ) ),
			} ),
		[]
	);

	return state;
}
