/**
 * The text shown at a control the server refused: the server's own text when
 * it names the exact problem; our hint when the server marks its text as the
 * generic "not accepted" line (`data.generic`, a type or size limit) or sent
 * none. Only the flag is read: a text produced in another locale is never
 * compared.
 *
 * @param {Object|null} error Rejection (`message`, `data.generic`).
 * @param {string}      hint  Our text for this control ('' = none).
 * @return {string} Text.
 */
export function refusalText( error, hint ) {
	const said = error?.message || '';
	if ( ! said || true === error?.data?.generic ) {
		return hint;
	}
	return said;
}
