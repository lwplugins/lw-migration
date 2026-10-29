/**
 * Copying the server command, without React. Called inside the click
 * handler (the browser allows the clipboard only there); where the
 * clipboard is missing or refuses, the command's text is selected instead,
 * ready for Ctrl+C.
 */

/**
 * Selects the whole text of a node.
 *
 * @param {Element|null} node The code element.
 */
export function selectText( node ) {
	const selection = node?.ownerDocument?.defaultView?.getSelection?.();
	if ( selection ) {
		selection.selectAllChildren( node );
	}
}

/**
 * @param {string}       text      What to copy.
 * @param {Object}       clipboard navigator.clipboard (or undefined).
 * @param {Element|null} node      The element showing the text (selected on failure).
 * @return {Promise<boolean>} True when the clipboard has it, false when the text was selected.
 */
export async function copyText( text, clipboard, node ) {
	try {
		if ( ! clipboard?.writeText ) {
			throw new Error( 'no clipboard' );
		}
		await clipboard.writeText( text );
		return true;
	} catch {
		selectText( node );
		return false;
	}
}
