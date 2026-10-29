/**
 * The room a field focused by keyboard leaves below itself for the summary
 * dock (export.scss reads it in scroll-margin-bottom). The dock grows with an
 * error notice or a wrapped line, to about 180 px on a phone, so its height
 * is measured rather than guessed.
 */

/** The custom property the stylesheet reads (with a fallback without it). */
export const DOCK_ROOM = '--lw-dock-room';

/**
 * Keeps DOCK_ROOM on the section equal to the dock's height.
 *
 * @param {?Element}               dock     The summary dock.
 * @param {?Element}               section  The element whose fields scroll clear of it.
 * @param {?typeof ResizeObserver} Observer ResizeObserver; without one the stylesheet's fallback stays.
 * @return {() => void} Stops watching and removes the property.
 */
export function watchDockRoom(
	dock,
	section,
	Observer = globalThis.ResizeObserver
) {
	if ( ! dock || ! section || 'function' !== typeof Observer ) {
		return () => {};
	}
	const update = () =>
		section.style.setProperty(
			DOCK_ROOM,
			`${ Math.ceil( dock.offsetHeight ) }px`
		);
	const observer = new Observer( update );
	observer.observe( dock );
	update();
	return () => {
		observer.disconnect();
		section.style.removeProperty( DOCK_ROOM );
	};
}
