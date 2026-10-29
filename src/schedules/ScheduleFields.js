/**
 * WordPress dependencies
 */
// No stable number field in core yet.
/* eslint-disable @wordpress/no-unsafe-wp-apis */
import {
	__experimentalNumberControl as NumberControl,
	SelectControl,
	TextControl,
} from '@wordpress/components';
/* eslint-enable @wordpress/no-unsafe-wp-apis */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import Segmented from '../components/Segmented';
import { ADMIN_EMAIL } from '../data/boot';
import SiteScope from '../network/SiteScope';
import { GB, bytesFrom, gbShown } from '../views/retentionSize';
import { EVERY, RETENTION_MAX } from './scheduleForm';
import { hourLabel, weekdayName } from './timing';

const range = ( from, to ) =>
	Array.from( { length: to - from + 1 }, ( _, i ) => from + i );
export const whole = ( value, min, max ) =>
	Math.min( max, Math.max( min, parseInt( value, 10 ) || 0 ) );

/**
 * Props that mark a refused control: the error replaces its help text.
 *
 * @param {string} error Error text ('' = valid).
 * @param {string} help  Normal help.
 * @return {Object} Props.
 */
export const marked = ( error, help = undefined ) =>
	error
		? { help: error, 'aria-invalid': true, className: 'is-invalid' }
		: { help };

/**
 * Network only: what each run backs up (stored as export.sites; a schedule of
 * chosen sites stays one when it is saved again).
 *
 * @param {Object}                  props
 * @param {Object}                  props.value    The schedule's export state.
 * @param {string}                  props.error    Refusal text ('' = none).
 * @param {(patch: Object) => void} props.onChange Merges a change into the export state.
 */
export function ScopeFields( { value, error, onChange } ) {
	return (
		<div
			className="lw-options__group"
			role="group"
			aria-labelledby="lw-schedule-scope"
		>
			<h4 id="lw-schedule-scope" className="lw-options__title">
				{ __( 'What to back up', 'lw-migration' ) }
			</h4>
			<SiteScope
				value={ value }
				onChange={ onChange }
				error={ error }
				tab="schedules"
				idBase="lw-schedule-picker"
			/>
		</div>
	);
}

/**
 * @param {Object}                  props
 * @param {Object}                  props.form     Form state.
 * @param {string}                  props.timezone Site time zone name.
 * @param {Object}                  props.errors   { field: text }.
 * @param {(patch: Object) => void} props.onChange Merges a change.
 */
export function TimingFields( { form, timezone, errors, onChange } ) {
	const span = EVERY[ form.frequency ];
	const byHour = [ 'hourly', 'hours' ].includes( form.frequency );
	const frequencies = {
		hourly: __( 'Every hour', 'lw-migration' ),
		hours: __( 'Every few hours', 'lw-migration' ),
		daily: __( 'Every day', 'lw-migration' ),
		days: __( 'Every few days', 'lw-migration' ),
		weekly: __( 'Every week', 'lw-migration' ),
		monthly: __( 'Every month', 'lw-migration' ),
	};

	return (
		<div
			className="lw-options__group"
			role="group"
			aria-labelledby="lw-schedule-when"
		>
			<h4 id="lw-schedule-when" className="lw-options__title">
				{ __( 'When', 'lw-migration' ) }
			</h4>
			<div
				className="lw-pills"
				role="group"
				aria-label={ __( 'How often', 'lw-migration' ) }
			>
				{ Object.entries( frequencies ).map( ( [ value, label ] ) => (
					<button
						key={ value }
						type="button"
						className="lw-pill"
						aria-pressed={ value === form.frequency }
						onClick={ () => {
							const next = EVERY[ value ];
							onChange( {
								frequency: value,
								every: next
									? whole( form.every, next[ 0 ], next[ 1 ] )
									: form.every,
							} );
						} }
					>
						{ label }
					</button>
				) ) }
			</div>
			{ span && (
				<NumberControl
					__next40pxDefaultSize
					label={
						'hours' === form.frequency
							? __( 'Hours between runs', 'lw-migration' )
							: __( 'Days between runs', 'lw-migration' )
					}
					min={ span[ 0 ] }
					max={ span[ 1 ] }
					value={ form.every }
					onChange={ ( value ) =>
						onChange( { every: parseInt( value, 10 ) || 0 } )
					}
					{ ...marked( errors.every ) }
				/>
			) }
			{ 'weekly' === form.frequency && (
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Day of the week', 'lw-migration' ) }
					value={ String( form.weekday ) }
					options={ range( 1, 7 ).map( ( day ) => ( {
						value: String( day ),
						label: weekdayName( day ),
					} ) ) }
					onChange={ ( value ) =>
						onChange( { weekday: Number( value ) } )
					}
				/>
			) }
			{ 'monthly' === form.frequency && (
				<NumberControl
					__next40pxDefaultSize
					label={ __( 'Day of the month', 'lw-migration' ) }
					min={ 1 }
					max={ 31 }
					value={ form.monthday }
					help={ __(
						'In a month without this day it runs on the last day.',
						'lw-migration'
					) }
					onChange={ ( value ) =>
						onChange( { monthday: whole( value, 1, 31 ) } )
					}
				/>
			) }
			<div className="lw-schedule-time">
				{ ! byHour && (
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Hour', 'lw-migration' ) }
						value={ String( form.hour ) }
						options={ range( 0, 23 ).map( ( hour ) => ( {
							value: String( hour ),
							label: hourLabel( hour ),
						} ) ) }
						onChange={ ( value ) =>
							onChange( { hour: Number( value ) } )
						}
					/>
				) }
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={
						byHour
							? __( 'Minute of the hour', 'lw-migration' )
							: __( 'Minute', 'lw-migration' )
					}
					value={ String( form.minute ) }
					options={ range( 0, 59 ).map( ( minute ) => ( {
						value: String( minute ),
						label: String( minute ).padStart( 2, '0' ),
					} ) ) }
					onChange={ ( value ) =>
						onChange( { minute: Number( value ) } )
					}
				/>
			</div>
			<p className="lw-schedule-zone">
				{ sprintf(
					/* translators: %s: time zone name, e.g. Europe/Budapest. */
					__(
						"Times are in the site's time zone (%s), set under Settings, General.",
						'lw-migration'
					),
					timezone
				) }
			</p>
		</div>
	);
}

/**
 * @param {Object}                      props
 * @param {Object}                      props.retention {count, bytes, days}.
 * @param {Object}                      props.errors    { field: text }.
 * @param {(retention: Object) => void} props.onChange  Replaces it.
 * @param {string}                      props.id        Heading id (unique on the page).
 * @param {string}                      props.title     Heading (default: the schedule's).
 * @param {string}                      props.help      Text under it (default: the schedule's).
 * @param {number}                      props.level     Heading level (default 4).
 */
export function RetentionFields( {
	retention,
	errors,
	onChange,
	id = 'lw-schedule-keep',
	title,
	help,
	level = 4,
} ) {
	const set = ( key, value ) => onChange( { ...retention, [ key ]: value } );
	const Heading = `h${ level }`;

	return (
		<div className="lw-options__group" role="group" aria-labelledby={ id }>
			<Heading id={ id } className="lw-options__title">
				{ title ?? __( 'Keeping its backups', 'lw-migration' ) }
			</Heading>
			<p className="lw-options__help">
				{ help ??
					__(
						'After each run, the oldest backups of this schedule go once a limit is reached; its newest always stays. Other backups are never touched. 0 means no limit.',
						'lw-migration'
					) }
			</p>
			<div className="lw-schedule-time">
				<NumberControl
					__next40pxDefaultSize
					label={ __( 'Number of backups', 'lw-migration' ) }
					min={ 0 }
					max={ RETENTION_MAX.count }
					value={ retention.count }
					onChange={ ( value ) =>
						set( 'count', whole( value, 0, RETENTION_MAX.count ) )
					}
					{ ...marked( errors.count ) }
				/>
				<NumberControl
					__next40pxDefaultSize
					label={ __( 'Total size in GB', 'lw-migration' ) }
					min={ 0 }
					max={ RETENTION_MAX.bytes / GB }
					step={ 0.01 }
					spinFactor={ 50 }
					value={ gbShown( retention.bytes ) }
					onChange={ ( value ) =>
						set(
							'bytes',
							bytesFrom(
								value,
								retention.bytes,
								RETENTION_MAX.bytes
							)
						)
					}
					{ ...marked( errors.bytes ) }
				/>
				<NumberControl
					__next40pxDefaultSize
					label={ __( 'Days to keep', 'lw-migration' ) }
					min={ 0 }
					max={ RETENTION_MAX.days }
					value={ retention.days }
					onChange={ ( value ) =>
						set( 'days', whole( value, 0, RETENTION_MAX.days ) )
					}
					{ ...marked( errors.days ) }
				/>
			</div>
		</div>
	);
}

/**
 * @param {Object}                   props
 * @param {Object}                   props.notify   {when, email}.
 * @param {Object}                   props.errors   { field: text }.
 * @param {(notify: Object) => void} props.onChange Replaces it.
 * @param {string}                   props.fallback Who gets the mail when the field is empty.
 */
export function NotifyFields( { notify, errors, onChange, fallback } ) {
	return (
		<div
			className="lw-options__group"
			role="group"
			aria-labelledby="lw-schedule-mail"
		>
			<h4 id="lw-schedule-mail" className="lw-options__title">
				{ __( 'E-mail', 'lw-migration' ) }
			</h4>
			<Segmented
				label={ __( 'When to send an e-mail', 'lw-migration' ) }
				options={ {
					off: __( 'Never', 'lw-migration' ),
					failure: __( 'On failure', 'lw-migration' ),
					success: __( 'On success', 'lw-migration' ),
					always: __( 'Always', 'lw-migration' ),
				} }
				value={ notify.when }
				onChange={ ( when ) => onChange( { ...notify, when } ) }
			/>
			{ 'off' !== notify.when && (
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Send to', 'lw-migration' ) }
					value={ notify.email }
					placeholder={ fallback || ADMIN_EMAIL }
					onChange={ ( email ) => onChange( { ...notify, email } ) }
					{ ...marked(
						errors.email,
						__(
							'Up to 5 addresses, separated by commas. Empty: the default address of the Settings, or the site admin.',
							'lw-migration'
						)
					) }
				/>
			) }
			<p className="lw-options__help">
				{ __(
					'A run you start by hand never sends an e-mail.',
					'lw-migration'
				) }
			</p>
		</div>
	);
}
