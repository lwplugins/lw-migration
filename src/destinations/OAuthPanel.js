/**
 * WordPress dependencies
 */
import { Button, ExternalLink, Notice } from '@wordpress/components';
import { useCopyToClipboard } from '@wordpress/compose';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { copy } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import SettingRow from '../components/SettingRow';
import { eligibility, isOwnApp } from './oauth';

/**
 * @param {Object|null} item Destination view.
 * @return {Element|null} Who is connected.
 */
function Account( { item } ) {
	const connection = item?.connection;
	if ( ! connection?.connected ) {
		return null;
	}
	return (
		<p className="lw-oauth__account">
			{ sprintf(
				/* translators: %s: account e-mail or name. */
				__( 'Connected as %s', 'lw-migration' ),
				connection.account
			) }
		</p>
	);
}

/**
 * The top of an OAuth type's form: through the LW app what Connect does,
 * whether this site's address can be used, and who is connected.
 *
 * @param {Object}      props
 * @param {Object}      props.type     Schema type (with `oauth`).
 * @param {Object|null} props.item     Destination view (null: a new one).
 * @param {Object}      props.settings The form's settings (the switch).
 */
export function OAuthIntro( { type, item, settings } ) {
	const { eligible, text } = eligibility( type, item, settings );
	return (
		<>
			{ ! isOwnApp( settings ) && (
				<p className="lw-oauth__intro">
					{ sprintf(
						/* translators: %s: provider name, e.g. Dropbox. */
						__(
							'Choose Connect and sign in to your %s account. There is nothing to register.',
							'lw-migration'
						),
						type.label
					) }
				</p>
			) }
			{ ! eligible && (
				<Notice status="error" isDismissible={ false }>
					{ text }
				</Notice>
			) }
			<Account item={ item } />
		</>
	);
}

/**
 * The own app's guide: the redirect address with a copy button, the steps
 * and the provider's console.
 *
 * @param {Object}      props
 * @param {Object}      props.type Schema type (with `oauth`).
 * @param {Object|null} props.item Destination view (null: a new one).
 */
export function OwnAppGuide( { type, item } ) {
	const { oauth } = type;
	const [ copied, setCopied ] = useState( false );
	const ref = useCopyToClipboard( oauth.redirectUri, () =>
		setCopied( true )
	);
	useEffect( () => {
		if ( ! copied ) {
			return undefined;
		}
		const timer = setTimeout( () => setCopied( false ), 3000 );
		return () => clearTimeout( timer );
	}, [ copied ] );

	return (
		<>
			<SettingRow
				title={ __( 'Redirect address', 'lw-migration' ) }
				help={ __(
					'Register exactly this address in your app at the provider.',
					'lw-migration'
				) }
				stacked
			>
				<div className="lw-oauth__uri">
					<code>{ oauth.redirectUri }</code>
					<Button
						size="compact"
						variant="secondary"
						icon={ copy }
						ref={ ref }
					>
						{ copied
							? __( 'Copied', 'lw-migration' )
							: __( 'Copy', 'lw-migration' ) }
					</Button>
				</div>
			</SettingRow>
			{ item?.connection?.redirectChanged && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						"This site's address changed since the account was connected. Uploads keep working; register the new address above before you connect again.",
						'lw-migration'
					) }
				</Notice>
			) }
			<ol className="lw-oauth__steps">
				{ oauth.steps.map( ( step ) => (
					<li key={ step }>{ step }</li>
				) ) }
			</ol>
			<p className="lw-oauth__console">
				<ExternalLink href={ oauth.consoleUrl }>
					{ __(
						"Open the provider's developer console",
						'lw-migration'
					) }
				</ExternalLink>
			</p>
		</>
	);
}

/**
 * The connect panel of an OAuth type (the "Add a destination" form):
 * through the LW app only who is connected and whether this site's address
 * can be used; with the own app also the redirect address with a copy
 * button, the steps and the provider's console. The Connect button itself
 * is in the form's action bar (Save and connect) and in the card header,
 * never alone in a row.
 *
 * @param {Object}      props
 * @param {Object}      props.type     Schema type (with `oauth`).
 * @param {Object|null} props.item     Destination view (null: a new one).
 * @param {Object}      props.settings The form's settings (the switch).
 */
export default function OAuthPanel( { type, item, settings } ) {
	return (
		<div className="lw-oauth">
			{ isOwnApp( settings ) && (
				<OwnAppGuide type={ type } item={ item } />
			) }
			<OAuthIntro type={ type } item={ item } settings={ settings } />
		</div>
	);
}
