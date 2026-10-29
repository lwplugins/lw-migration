/**
 * WordPress dependencies
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { api, errorMessage } from '../data/api';
import { CHUNK_BYTES } from '../data/boot';
import { formatBytes } from '../data/format';
import { DONE, FAILED, LOGGED_OUT, REREAD, sendFile } from './upload';

/**
 * Sends the chosen file while the job waits for its upload (upload.js does
 * the slicing, 413 shrinking and offset handling). The job is created
 * `pending` and asks for its file only after its first tick (ruling P6): a
 * job started in this tab is ticked at once by the driver, so the file goes
 * out as soon as `input.kind === 'upload'` appears; after a reload the file
 * must be chosen again, and a file of another size is refused here.
 *
 * @param {Object}    driver      useJobDriver() result.
 * @param {File|null} initialFile File chosen when the job started.
 * @return {Object} { needsFile, chooseFile, error, percent }.
 */
export default function useUpload( driver, initialFile ) {
	const { job } = driver;
	const [ file, setFile ] = useState( initialFile );
	const [ error, setError ] = useState( '' );
	const [ percent, setPercent ] = useState( null );
	const sending = useRef( false );
	const mounted = useRef( true );
	// One controller for the upload loop: unmounting aborts the slice in flight.
	const controller = useRef( null );
	const waiting =
		'needs_input' === job.status && 'upload' === job.input?.kind;

	useEffect( () => {
		mounted.current = true;
		return () => {
			mounted.current = false;
			controller.current?.abort();
		};
	}, [] );

	useEffect( () => {
		if ( ! waiting || ! file || sending.current ) {
			return;
		}
		if ( file.size !== job.input.size ) {
			setError(
				sprintf(
					/* translators: 1: size of the chosen file, 2: size of the file the upload started with. */
					__(
						'This file is %1$s, but the upload started with a file of %2$s. Choose the same file.',
						'lw-migration'
					),
					formatBytes( file.size ),
					formatBytes( job.input.size )
				)
			);
			setFile( null );
			return;
		}
		sending.current = true;
		const own = new AbortController();
		controller.current = own;
		const { signal } = own;
		sendFile( {
			job,
			file,
			fallbackChunk: CHUNK_BYTES,
			send: ( id, offset, blob ) =>
				api.upload( id, offset, blob, signal ),
			read: ( id ) => api.job( id, signal ),
			onView: driver.update,
			onProgress: ( value ) => mounted.current && setPercent( value ),
			stopped: () => signal.aborted || ! mounted.current,
		} ).then( ( result ) => {
			sending.current = false;
			if ( signal.aborted || ! mounted.current ) {
				return;
			}
			setPercent( null );
			if ( DONE === result.outcome || REREAD === result.outcome ) {
				driver.resume();
			} else if ( LOGGED_OUT === result.outcome ) {
				driver.stop( result.error );
			} else if ( FAILED === result.outcome ) {
				setError( errorMessage( result.error ) );
				setFile( null );
				driver.refresh();
			}
		} );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ waiting, file ] );

	return {
		needsFile: waiting && ! file,
		chooseFile: ( chosen ) => {
			setError( '' );
			setFile( chosen );
		},
		error,
		percent,
	};
}
