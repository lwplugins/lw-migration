/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { api } from '../data/api';
import ProblemNotice, { describeProblem } from '../views/ProblemNotice';
import DetailSection from './DetailSection';
import LiveLinkList, { RevokeDialog } from './LiveLinkList';
import { liveLinksFor } from './shareLink';

/**
 * The chosen backup's live transfer links (from the one `GET
 * /backups/shares` list of the section), each revocable here; "New link"
 * opens the share dialog.
 *
 * @param {Object}     props
 * @param {Object}     props.backup    Backup row.
 * @param {Object}     props.shares    useRemote() of api.shares.
 * @param {() => void} props.onCreate  Opens the share dialog.
 * @param {() => void} props.onChanged A link was revoked (read the lists again).
 */
export default function DetailLinks( { backup, shares, onCreate, onChanged } ) {
	const [ revoking, setRevoking ] = useState( null );
	const [ problem, setProblem ] = useState( null );
	const links = liveLinksFor(
		shares.data?.items,
		backup,
		Math.floor( Date.now() / 1000 )
	);

	const revoke = async () => {
		const item = revoking;
		setRevoking( null );
		setProblem( null );
		try {
			await api.revokeShare( item.id );
		} catch ( e ) {
			setProblem( describeProblem( e ) );
		}
		onChanged();
	};

	return (
		<DetailSection
			title={ __( 'Transfer links', 'lw-migration' ) }
			action={
				<Button variant="link" onClick={ onCreate }>
					{ __( 'New link', 'lw-migration' ) }
				</Button>
			}
		>
			<ProblemNotice problem={ problem } />
			<ProblemNotice
				problem={
					shares.error ? describeProblem( shares.error ) : null
				}
			/>
			{ ! shares.error && (
				<LiveLinkList
					links={ links }
					loading={ shares.isLoading && ! shares.data }
					onRevoke={ setRevoking }
					compact
				/>
			) }
			<RevokeDialog
				isOpen={ null !== revoking }
				onConfirm={ revoke }
				onCancel={ () => setRevoking( null ) }
			/>
		</DetailSection>
	);
}
