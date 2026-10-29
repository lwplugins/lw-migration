/**
 * WordPress dependencies
 */
import { useEffect, useRef, useState } from '@wordpress/element';

/**
 * The cancel confirmation inside the job card (never a browser dialog):
 * opening it moves the focus to its confirm button; "Keep going", Escape
 * and the answer put the focus back on the button that opened it. Once
 * `blocked` (the job passed its point of no return), it closes the same way
 * and does not open any more.
 *
 * @param {boolean} blocked Cancelling is no longer possible.
 * @return {Object} { open, ask, close, onKeyDown, confirmRef }.
 */
export default function useInlineConfirm( blocked = false ) {
	const [ open, setOpen ] = useState( false );
	const confirmRef = useRef( null );
	const opener = useRef( null );
	// The focus goes back only after a close (not on the first render).
	const giveBack = useRef( false );

	useEffect( () => {
		if ( open ) {
			confirmRef.current?.focus();
		} else if ( giveBack.current ) {
			giveBack.current = false;
			opener.current?.focus?.();
		}
	}, [ open ] );

	useEffect( () => {
		if ( blocked && open ) {
			giveBack.current = true;
			setOpen( false );
		}
	}, [ blocked, open ] );

	/**
	 * @param {Object} event The click that asks (its button gets the focus back).
	 */
	const ask = ( event ) => {
		opener.current = event?.currentTarget ?? null;
		setOpen( true );
	};
	const close = () => {
		giveBack.current = true;
		setOpen( false );
	};
	const onKeyDown = ( event ) => {
		if ( 'Escape' === event.key ) {
			event.preventDefault();
			event.stopPropagation();
			close();
		}
	};

	return { open: open && ! blocked, ask, close, onKeyDown, confirmRef };
}
