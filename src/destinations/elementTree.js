/**
 * Tests only: queries over the element tree a component returns when it
 * is mounted with the hook runtime (../backups/hookRuntime.js). Child
 * components are not rendered; their elements and props are found here.
 */

/**
 * @param {unknown} node A React node.
 * @return {Object[]} Every element in it, depth first.
 */
export function elements( node ) {
	if ( Array.isArray( node ) ) {
		return node.flatMap( elements );
	}
	if ( ! node || 'object' !== typeof node || ! node.props ) {
		return [];
	}
	return [ node, ...elements( node.props.children ) ];
}

/**
 * @param {unknown} node A React node.
 * @return {string} Its text (strings and numbers inside it).
 */
export function textOf( node ) {
	if ( Array.isArray( node ) ) {
		return node.map( textOf ).join( '' );
	}
	if ( 'string' === typeof node || 'number' === typeof node ) {
		return String( node );
	}
	return node?.props ? textOf( node.props.children ) : '';
}

/**
 * @param {unknown}                      node A React node.
 * @param {(element: Object) => boolean} test Which element.
 * @return {Object[]} The matching elements.
 */
export const findAll = ( node, test ) => elements( node ).filter( test );

/**
 * @param {unknown}                      node A React node.
 * @param {(element: Object) => boolean} test Which element.
 * @return {Object|null} The first matching element.
 */
export const find = ( node, test ) => findAll( node, test )[ 0 ] ?? null;

/**
 * @param {unknown}                             node A React node.
 * @param {((props: Object) => unknown)|string} type A component (or a tag name).
 * @return {Object|null} Its first element.
 */
export const byType = ( node, type ) =>
	find( node, ( element ) => element.type === type );

/**
 * @param {unknown}                    node   A React node.
 * @param {(props: Object) => unknown} Button The Button stand-in.
 * @param {string}                     text   Its text or label.
 * @return {Object|null} The button's element.
 */
export const button = ( node, Button, text ) =>
	find(
		node,
		( element ) =>
			element.type === Button &&
			( textOf( element.props.children ) === text ||
				element.props.label === text )
	);
