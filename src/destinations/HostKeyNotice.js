/**
 * WordPress dependencies
 */
import { Button, Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { CERTIFICATE_COMMAND, hostKeyCommand } from './destinations';

/**
 * @param {string}  before      'confirmed' (a stored one) or 'shown' (the
 *                              one the check showed a moment ago).
 * @param {boolean} certificate An FTPS certificate (else an SFTP host key).
 * @return {string} Why the fingerprint needs checking.
 */
function changedText( before, certificate ) {
	if ( certificate ) {
		return 'shown' === before
			? __(
					'The server now shows another certificate than the one the check showed a moment ago. Do not trust it until you have checked it on the server.',
					'lw-migration'
				)
			: __(
					'The server shows another certificate than the one you confirmed. If the certificate was not renewed, someone may be in between: do not trust it until you have checked it on the server.',
					'lw-migration'
				);
	}
	return 'shown' === before
		? __(
				'The server now shows another key than the one the check showed a moment ago. Do not trust it until you have checked it on the server.',
				'lw-migration'
			)
		: __(
				'The server shows another key than the one you confirmed. If the server was not reinstalled, someone may be in between: do not trust it until you have checked it on the server.',
				'lw-migration'
			);
}

/**
 * @param {boolean} changed     A changed fingerprint.
 * @param {boolean} certificate An FTPS certificate (else an SFTP host key).
 * @return {string} The trust button's label.
 */
function trustLabel( changed, certificate ) {
	if ( certificate ) {
		return changed
			? __( 'Trust the new certificate', 'lw-migration' )
			: __( 'Trust this certificate', 'lw-migration' );
	}
	return changed
		? __( 'Trust the new key', 'lw-migration' )
		: __( 'Trust this key', 'lw-migration' );
}

/**
 * The fingerprint to confirm (first connection) or to check again (it
 * changed): the SFTP server's host key, or the FTPS server's certificate
 * that no certificate authority vouches for. Trusting sends the
 * fingerprint shown here; the server must still show exactly it (plan D31,
 * issue #5). It is the card's warning panel (an error one for a changed
 * fingerprint), with the server's refusal text (`state.note`) on top and
 * "Later", which closes it.
 *
 * @param {Object}     props
 * @param {string}     props.id      Panel id.
 * @param {Object}     props.state   hostKeyState() (with `note` after a refused trust).
 * @param {boolean}    props.busy    Trusting now.
 * @param {() => void} props.onTrust Trusts the fingerprint.
 * @param {() => void} props.onLater Closes the panel.
 */
export default function HostKeyNotice( { id, state, busy, onTrust, onLater } ) {
	const changed = 'changed' === state.kind;
	const certificate = 'certFingerprint' === state.field;
	let first = __(
		"First connection: check that this is your server's key, then trust it.",
		'lw-migration'
	);
	if ( certificate ) {
		first = __(
			"The server's certificate is not vouched for by a certificate authority (it may be self-signed). Check that this is your server's certificate, then trust it.",
			'lw-migration'
		);
	}

	return (
		<div
			id={ id }
			className={ `lw-dest__panel lw-host-key ${
				changed ? 'is-error' : 'is-warning'
			}` }
			role={ changed ? 'alert' : 'status' }
		>
			{ state.note && (
				<Notice status="error" isDismissible={ false }>
					{ state.note }
				</Notice>
			) }
			<p>
				{ changed ? changedText( state.before, certificate ) : first }
			</p>
			<dl className="lw-host-key__prints">
				<dt>
					{ state.keyType
						? sprintf(
								/* translators: %s: key type such as ssh-ed25519. */
								__( 'Shown now (%s)', 'lw-migration' ),
								state.keyType
							)
						: __( 'Shown now', 'lw-migration' ) }
				</dt>
				<dd>
					<code>{ state.fingerprint }</code>
				</dd>
				{ changed && state.expected && (
					<>
						<dt>
							{ 'shown' === state.before
								? __( 'Shown before', 'lw-migration' )
								: __( 'Confirmed before', 'lw-migration' ) }
						</dt>
						<dd>
							<code>{ state.expected }</code>
						</dd>
					</>
				) }
			</dl>
			<p className="lw-host-key__how">
				{ certificate
					? __(
							"Compare it on the server with this command, where cert.pem is the server's certificate file:",
							'lw-migration'
						)
					: __(
							'Compare it on the server with:',
							'lw-migration'
						) }{ ' ' }
				<code>
					{ certificate
						? CERTIFICATE_COMMAND
						: hostKeyCommand( state.keyType ) }
				</code>
			</p>
			<div className="lw-host-key__actions">
				<Button
					__next40pxDefaultSize
					variant={ changed ? 'secondary' : 'primary' }
					isDestructive={ changed }
					isBusy={ busy }
					disabled={ busy }
					accessibleWhenDisabled
					onClick={ () => onTrust() }
				>
					{ trustLabel( changed, certificate ) }
				</Button>
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					disabled={ busy }
					accessibleWhenDisabled
					onClick={ () => onLater() }
				>
					{ __( 'Later', 'lw-migration' ) }
				</Button>
			</div>
		</div>
	);
}
