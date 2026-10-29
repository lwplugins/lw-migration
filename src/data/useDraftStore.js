/**
 * WordPress dependencies
 */
import { useDispatch } from '@wordpress/data';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import { errorMessage, isLoggedOut } from './errors';

const same = ( a, b ) => JSON.stringify( a ) === JSON.stringify( b );

/**
 * Loaded server values + a local draft. Save sends only the keys that differ
 * from the server copy, so one tab can never overwrite another tab's fields.
 * A load error is kept as the rejection (LoadError tells a logout apart); a
 * save after a logout gets no snackbar: the view shows LoginNotice for it
 * (`saveError`), which stays until the next edit.
 *
 * @param {() => Promise<Object>}              load     Loads the server values.
 * @param {(patch: Object) => Promise<Object>} persist  Saves a patch, resolves with the new values.
 * @param {string[]}                           editable Keys the user can change.
 * @return {Object} Store.
 */
export default function useDraftStore( load, persist, editable ) {
	const [ server, setServer ] = useState( null );
	const [ draft, setDraft ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ saveError, setSaveError ] = useState( null );
	const { createSuccessNotice, createErrorNotice } =
		useDispatch( noticesStore );

	const reload = useCallback( () => {
		setError( null );
		return load().then(
			( data ) => {
				setServer( data );
				setDraft( data );
			},
			( e ) => setError( e )
		);
	}, [ load ] );

	useEffect( () => {
		reload();
	}, [ reload ] );

	const patch = draft
		? Object.fromEntries(
				editable
					.filter( ( key ) => ! same( draft[ key ], server[ key ] ) )
					.map( ( key ) => [ key, draft[ key ] ] )
			)
		: {};
	const hasEdits = Object.keys( patch ).length > 0;

	const save = async () => {
		if ( ! hasEdits ) {
			return;
		}
		setIsSaving( true );
		setSaveError( null );
		try {
			const data = await persist( patch );
			setServer( data );
			setDraft( data );
			createSuccessNotice( __( 'Settings saved.', 'lw-migration' ), {
				type: 'snackbar',
			} );
		} catch ( e ) {
			setSaveError( e );
			if ( ! isLoggedOut( e ) ) {
				createErrorNotice( errorMessage( e ), { type: 'snackbar' } );
			}
		}
		setIsSaving( false );
	};

	return {
		data: draft,
		server,
		isLoading: ! draft && ! error,
		error,
		reload,
		update: ( values ) => {
			setSaveError( null );
			setDraft( ( prev ) => ( { ...prev, ...values } ) );
		},
		replace: ( data ) => {
			setServer( data );
			setDraft( data );
		},
		hasEdits,
		isSaving,
		save,
		discard: () => {
			setSaveError( null );
			setDraft( server );
		},
		// The last save rejection (cleared by an edit), for field-level errors.
		saveError,
	};
}
