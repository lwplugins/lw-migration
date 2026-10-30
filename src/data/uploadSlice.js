/**
 * How one upload slice is sent (Rest\UploadSlice reads it): as the file part
 * `chunk` of a multipart/form-data request, because hosting firewalls
 * (ModSecurity rule sets) refuse a raw application/octet-stream body, often
 * with a 406. Only where the server takes no file uploads
 * (lw_migration_upload_off) does it go as the raw body, for the rest of the
 * page's life.
 */

/** The server's answer when PHP keeps no file uploads (file_uploads off). */
export const UPLOAD_OFF = 'lw_migration_upload_off';

/**
 * @param {(options: Object) => Promise<Object>} request request() of the API.
 * @return {(url: string, blob: Blob, signal: ?AbortSignal) => Promise<Object>} Sends one slice.
 */
export function createSliceSender( request ) {
	let raw = false;
	const send = ( url, blob, signal ) => {
		if ( raw ) {
			return request( {
				path: url,
				method: 'POST',
				body: blob,
				headers: { 'Content-Type': 'application/octet-stream' },
				signal,
			} );
		}
		const form = new FormData();
		form.append( 'chunk', blob, 'chunk' );
		// No Content-Type: the browser sets it with the multipart boundary.
		return request( { path: url, method: 'POST', body: form, signal } );
	};

	return async ( url, blob, signal ) => {
		try {
			return await send( url, blob, signal );
		} catch ( e ) {
			if ( raw || UPLOAD_OFF !== e?.code ) {
				throw e;
			}
			raw = true;
			return send( url, blob, signal );
		}
	};
}
