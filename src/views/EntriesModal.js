/**
 * WordPress dependencies
 */
import { Button, Modal } from '@wordpress/components';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import Callout from '../components/Callout';
import { SkeletonRegion, SkeletonText } from '../components/skeleton';
import { api } from '../data/api';
import { formatBytes, formatNumber } from '../data/format';
import ProblemNotice, { describeProblem } from './ProblemNotice';
import { NO_ENTRIES, appendEntries, hasMoreEntries } from './backupList';

/**
 * Entry rows while a page loads, shaped like the real ones.
 *
 * @param {Object} props
 * @param {number} props.count Rows.
 */
function EntriesSkeleton( { count } ) {
	return (
		<SkeletonRegion label={ __( 'Loading the list…', 'lw-migration' ) }>
			<ul className="lw-entries" aria-hidden="true">
				{ Array.from( { length: count }, ( _, index ) => (
					<li key={ index }>
						<SkeletonText
							width={ `${ 30 + ( ( index * 13 ) % 35 ) }%` }
						/>
						<SkeletonText width="56px" />
					</li>
				) ) }
			</ul>
		</SkeletonRegion>
	);
}

/**
 * The entries of an archive, page by page (names and stored sizes only; no
 * data is read). "Show more" asks for the page after the server's `next`
 * header offset.
 *
 * @param {Object}     props
 * @param {Object}     props.backup  Backup row.
 * @param {() => void} props.onClose Close.
 * @param {() => void} props.onGone  The backup is not there any more.
 */
export default function EntriesModal( { backup, onClose, onGone } ) {
	const [ list, setList ] = useState( NO_ENTRIES );
	const [ loading, setLoading ] = useState( true );
	const [ problem, setProblem ] = useState( null );
	// The button stays (busy) while the page it asked for loads, so focus
	// is not dropped out of the dialog.
	const [ pressed, setPressed ] = useState( false );
	const mounted = useRef( true );

	useEffect(
		() => () => {
			mounted.current = false;
		},
		[]
	);

	const load = useCallback(
		( after ) => {
			setLoading( true );
			setProblem( null );
			api.entries( backup, after ).then(
				( page ) => {
					if ( mounted.current ) {
						setList( ( prev ) => appendEntries( prev, page ) );
						setLoading( false );
					}
				},
				( e ) => {
					if ( ! mounted.current ) {
						return;
					}
					const next = describeProblem( e );
					setProblem( next );
					setLoading( false );
					if ( next.gone ) {
						onGone();
					}
				}
			);
		},
		[ backup, onGone ]
	);

	useEffect( () => {
		load( 0 );
		// Once per opened backup; a new onGone identity must not reload.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ backup ] );

	const more = hasMoreEntries( list );

	return (
		<Modal
			title={ backup.name }
			onRequestClose={ onClose }
			size="large"
			className="lw-modal"
		>
			<div className="lw-modal-form">
				{ list.encrypted && (
					<Callout>
						{ __(
							'This archive is encrypted: the names are visible, the contents need the password.',
							'lw-migration'
						) }
					</Callout>
				) }
				{ list.entries.length > 0 && (
					<p className="lw-modal__text">
						{ more
							? sprintf(
									/* translators: %s: number of files listed so far. */
									_n(
										'The first %s file. The archive holds more.',
										'The first %s files. The archive holds more.',
										list.entries.length,
										'lw-migration'
									),
									formatNumber( list.entries.length )
								)
							: sprintf(
									/* translators: %s: number of files in the archive. */
									_n(
										'%s file in this archive.',
										'%s files in this archive.',
										list.entries.length,
										'lw-migration'
									),
									formatNumber( list.entries.length )
								) }
					</p>
				) }
				{ list.entries.length > 0 && (
					<ul className="lw-entries">
						{ list.entries.map( ( entry, index ) => (
							<li key={ index }>
								<code>{ entry.name }</code>
								<span>{ formatBytes( entry.size ) }</span>
							</li>
						) ) }
					</ul>
				) }
				{ loading && (
					<EntriesSkeleton count={ list.entries.length ? 3 : 6 } />
				) }
				<ProblemNotice problem={ problem } />
				{ ! loading && ! problem && ! list.entries.length && (
					<p className="lw-modal__text">
						{ __( 'This archive is empty.', 'lw-migration' ) }
					</p>
				) }
				{ ( more || problem || ( loading && pressed ) ) &&
					! problem?.gone && (
						<div className="lw-actions">
							<Button
								__next40pxDefaultSize
								variant="secondary"
								isBusy={ loading }
								disabled={ loading }
								accessibleWhenDisabled
								onClick={ () => {
									setPressed( true );
									load( more ? list.next : 0 );
								} }
							>
								{ ! list.entries.length
									? __( 'Try again', 'lw-migration' )
									: __( 'Show more', 'lw-migration' ) }
							</Button>
						</div>
					) }
			</div>
		</Modal>
	);
}
