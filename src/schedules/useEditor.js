/**
 * WordPress dependencies
 */
import { useRef, useState } from '@wordpress/element';

/**
 * The editor sheet's open state and where focus goes back to. The dialog
 * returns focus to what had it before opening, but a menu item (Duplicate)
 * or a starter button may be gone by then: once it closed, focus moves to
 * the button that opened it, or to the fallback (New schedule).
 *
 * @param {() => (Element|null)} fallback Returns the button to focus when the opener is gone.
 * @return {{editing: Object|null, open: (state: Object, opener: (Element|null)) => void, close: (after?: Promise) => void}} State and actions.
 */
export default function useEditor( fallback ) {
	const [ editing, setEditing ] = useState( null );
	const opener = useRef( null );

	const open = ( state, from ) => {
		opener.current = from || null;
		setEditing( state );
	};
	const close = ( after = null ) => {
		const from = opener.current;
		opener.current = null;
		setEditing( null );
		// After the dialog's own focus return (it runs as the dialog
		// unmounts), and after `after` (the reload that may remove the opener).
		const refocus = () =>
			window.setTimeout( () => {
				const target = from?.isConnected ? from : fallback();
				target?.focus();
			}, 0 );
		Promise.resolve( after ).then( refocus, refocus );
	};

	return { editing, open, close };
}
