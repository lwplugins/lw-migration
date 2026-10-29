/**
 * WordPress dependencies
 */
import { Button, Notice } from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import LoginNotice from '../components/LoginNotice';
import { errorMessage, isLoggedOut } from '../data/errors';

/**
 * Asks before something is deleted, in place (never a dialog): the
 * destructive button takes the focus when it opens; Cancel and Escape call
 * onCancel (the caller puts the focus back on what opened it); a refusal
 * stays here.
 *
 * @param {Object}              props
 * @param {string}              props.tag          Element (e.g. li in a list).
 * @param {string}              props.className    Extra class.
 * @param {string}              props.title        Heading (optional).
 * @param {Element}             props.lead         What is deleted, before the text (optional).
 * @param {string}              props.text         What happens.
 * @param {string}              props.confirmLabel Destructive button.
 * @param {() => Promise<void>} props.onConfirm    Does it (rejects like request()).
 * @param {() => void}          props.onCancel     Closes it.
 */
export default function InlineConfirm( {
	tag: Tag = 'div',
	className,
	title,
	lead,
	text,
	confirmLabel,
	onConfirm,
	onCancel,
} ) {
	const id = useInstanceId( InlineConfirm, 'lw-confirm' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const confirmRef = useRef( null );

	useEffect( () => {
		confirmRef.current?.focus();
	}, [] );

	const go = async () => {
		setBusy( true );
		setError( null );
		try {
			await onConfirm();
		} catch ( e ) {
			setError( e );
			setBusy( false );
		}
	};
	const onKeyDown = ( event ) => {
		if ( 'Escape' === event.key && ! busy ) {
			event.preventDefault();
			event.stopPropagation();
			onCancel();
		}
	};
	const described = [ lead && `${ id }-lead`, `${ id }-text` ]
		.filter( Boolean )
		.join( ' ' );

	return (
		// Escape inside it is its Cancel.
		<Tag
			className={ [ 'lw-confirm', className ]
				.filter( Boolean )
				.join( ' ' ) }
			role={ title ? 'alertdialog' : undefined }
			aria-labelledby={ title ? `${ id }-title` : undefined }
			aria-describedby={ title ? `${ id }-text` : undefined }
			onKeyDown={ onKeyDown }
		>
			{ title && (
				<h4 id={ `${ id }-title` } className="lw-confirm__title">
					{ title }
				</h4>
			) }
			{ lead && (
				<span id={ `${ id }-lead` } className="lw-confirm__lead">
					{ lead }
				</span>
			) }
			<p id={ `${ id }-text` } className="lw-confirm__text">
				{ text }
			</p>
			{ error && isLoggedOut( error ) && (
				<LoginNotice tab="destinations" />
			) }
			{ error && ! isLoggedOut( error ) && (
				<Notice status="error" isDismissible={ false }>
					{ errorMessage( error ) }
				</Notice>
			) }
			<div className="lw-confirm__actions">
				<Button
					ref={ confirmRef }
					__next40pxDefaultSize
					variant="primary"
					isDestructive
					isBusy={ busy }
					disabled={ busy }
					accessibleWhenDisabled
					aria-describedby={ described }
					onClick={ go }
				>
					{ confirmLabel }
				</Button>
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					onClick={ onCancel }
				>
					{ __( 'Cancel', 'lw-migration' ) }
				</Button>
			</div>
		</Tag>
	);
}
