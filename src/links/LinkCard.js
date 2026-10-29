/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import { humanTimeDiff } from '@wordpress/date';
import { __, sprintf } from '@wordpress/i18n';
import { linkOff } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import Callout from '../components/Callout';
import StatusBadge from '../components/StatusBadge';
import { formatDayTime } from '../data/format';
import { actionsFor, allowText, neverUsed, stateText } from './links';

/**
 * The warning of every plain-http connection (user ruling U1): the site
 * travels unencrypted and can be read on the way (issue #1; the signed
 * answers refuse a changed one).
 *
 * @return {string} Text.
 */
export const httpWarning = () =>
	__(
		'This connection uses unencrypted http: the whole site travels unencrypted, and anyone on the network between the two sites can read it on the way, including its users and their password hashes. Use https on both sites where you can.',
		'lw-migration'
	);

/**
 * When the connection was made, and for an incoming one from which IP
 * (ruling I7).
 *
 * @param {Object} link Link view.
 * @return {string} Text.
 */
function connectedText( link ) {
	if ( 'in' === link.direction && link.pairedFrom ) {
		return sprintf(
			/* translators: 1: date and time, 2: IP address. */
			__( 'Connected on %1$s from %2$s', 'lw-migration' ),
			formatDayTime( link.createdAt ),
			link.pairedFrom
		);
	}
	return sprintf(
		/* translators: %s: date and time. */
		__( 'Connected on %s', 'lw-migration' ),
		formatDayTime( link.createdAt )
	);
}

/**
 * When the other site last used an incoming connection; this site does not
 * record its own use of an outgoing one.
 *
 * @param {Object} link Link view.
 * @return {string} Text, '' for an outgoing connection.
 */
function lastUseText( link ) {
	if ( 'in' !== link.direction ) {
		return '';
	}
	if ( neverUsed( link ) ) {
		return __( 'Not used since it was connected', 'lw-migration' );
	}
	return sprintf(
		/* translators: %s: time since, e.g. "2 hours ago". */
		__( 'Last used %s', 'lw-migration' ),
		humanTimeDiff( link.lastUsedAt * 1000 )
	);
}

/**
 * One connected site: who, which way, what it may do, its state, the actions.
 *
 * @param {Object}     props
 * @param {Object}     props.link     Link view.
 * @param {boolean}    props.busy     Another job runs on this site.
 * @param {boolean}    props.pending  A request for this card is on its way.
 * @param {() => void} props.onPush   Asks to send this site there.
 * @param {() => void} props.onPull   Asks to bring that site here.
 * @param {() => void} props.onCheck  Asks the other site who it is.
 * @param {() => void} props.onRemove Asks to remove the connection.
 */
export default function LinkCard( {
	link,
	busy,
	pending,
	onPush,
	onPull,
	onCheck,
	onRemove,
} ) {
	const id = useInstanceId( LinkCard, 'lw-link' );
	const about = { 'aria-describedby': `${ id }-name` };
	const actions = actionsFor( link, busy );
	const state = stateText( link );
	const lastUse = lastUseText( link );
	const allowed = allowText( link.allow, link.direction );

	return (
		<li className="lw-link">
			<div className="lw-link__head">
				<h4 id={ `${ id }-name` } className="lw-link__name">
					{ link.name || link.address }
				</h4>
				{ link.insecure && (
					<StatusBadge status="warning">
						{ __( 'http — unencrypted', 'lw-migration' ) }
					</StatusBadge>
				) }
			</div>
			<p className="lw-link__address">{ link.address }</p>
			<ul className="lw-link__facts">
				<li className="lw-link__direction">
					{ 'out' === link.direction
						? __( 'This site sends', 'lw-migration' )
						: __( 'This site accepts', 'lw-migration' ) }
				</li>
				{ allowed && <li>{ allowed }</li> }
				<li>{ connectedText( link ) }</li>
				{ lastUse && <li>{ lastUse }</li> }
			</ul>
			{ state && <Callout>{ state }</Callout> }
			{ link.insecure && (
				<Callout tone="warning">{ httpWarning() }</Callout>
			) }
			<div className="lw-actions">
				{ actions.push.shown && (
					<Button
						__next40pxDefaultSize
						variant="primary"
						disabled={ actions.push.disabled || pending }
						accessibleWhenDisabled
						onClick={ onPush }
						{ ...about }
					>
						{ __( 'Send this site there', 'lw-migration' ) }
					</Button>
				) }
				{ actions.pull.shown && (
					<Button
						__next40pxDefaultSize
						variant="secondary"
						disabled={ actions.pull.disabled || pending }
						accessibleWhenDisabled
						onClick={ onPull }
						{ ...about }
					>
						{ __( 'Bring that site here', 'lw-migration' ) }
					</Button>
				) }
				{ actions.check.shown && (
					<Button
						__next40pxDefaultSize
						variant="secondary"
						isBusy={ pending }
						disabled={ pending }
						accessibleWhenDisabled
						onClick={ onCheck }
						{ ...about }
					>
						{ __( 'Check', 'lw-migration' ) }
					</Button>
				) }
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					isDestructive
					icon={ linkOff }
					disabled={ pending }
					accessibleWhenDisabled
					onClick={ onRemove }
					{ ...about }
				>
					{ __( 'Remove', 'lw-migration' ) }
				</Button>
			</div>
		</li>
	);
}
