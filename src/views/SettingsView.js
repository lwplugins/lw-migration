/**
 * WordPress dependencies
 */
// No stable number field in core yet.
/* eslint-disable @wordpress/no-unsafe-wp-apis */
import {
	__experimentalNumberControl as NumberControl,
	TextControl,
} from '@wordpress/components';
/* eslint-enable @wordpress/no-unsafe-wp-apis */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import LoadError from '../components/LoadError';
import LoginNotice from '../components/LoginNotice';
import Section from '../components/Section';
import SectionLead from '../components/SectionLead';
import SettingRow from '../components/SettingRow';
import { ADMIN_EMAIL } from '../data/boot';
import { invalidField, isLoggedOut } from '../data/errors';
import { refusalText } from '../data/refusal';
import { formatNumber } from '../data/format';
import { SkeletonFields, SkeletonRegion } from '../components/skeleton';
import BackupFolderSection from '../settings/BackupFolderSection';
import { GB, bytesFrom, gbShown } from './retentionSize';

// The server's maximums (Rest\SettingsApi::LIMITS); a larger value is refused.
const MAX = {
	count: 10000,
	bytes: 1125899906842624,
	days: 36500,
};
const MAX_GB = MAX.bytes / GB;

const clamp = ( value, max ) => Math.min( max, Math.max( 0, value ) );
const whole = ( value, max ) => clamp( parseInt( value, 10 ) || 0, max );

/**
 * The section's static header: the skeleton shows it too, so its height
 * matches at every width and nothing jumps when the values arrive.
 *
 * @param {Object}  props
 * @param {Element} props.children Body.
 */
const RetentionSection = ( { children } ) => (
	<Section
		title={ __( 'Keeping backups', 'lw-migration' ) }
		description={ __(
			'After each new backup, the oldest ones made here are removed once a limit is reached. The newest backup and the ones taken before a restore always stay. 0 means no limit.',
			'lw-migration'
		) }
	>
		{ children }
	</Section>
);

/**
 * The static header of the notification address (shown by the skeleton too).
 *
 * @param {Object}  props
 * @param {Element} props.children Body.
 */
const NotificationsSection = ( { children } ) => (
	<Section
		title={ __( 'E-mail notifications', 'lw-migration' ) }
		description={ __(
			'Scheduled backups send their e-mails here, unless a schedule has its own address. When a schedule mails (on failure, on success or always) is set on the schedule itself; backups started by hand send no e-mail.',
			'lw-migration'
		) }
	>
		{ children }
	</Section>
);

/**
 * Error text for the field the server refused, '' for the others.
 *
 * @param {string} refused Refused field name, or null.
 * @param {string} key     This field.
 * @param {number} max     Its maximum, in the unit the field shows.
 * @return {string} Error text.
 */
const fieldError = ( refused, key, max ) =>
	refused === key || 'retention' === refused
		? sprintf(
				/* translators: %s: largest allowed number. */
				__( 'Enter a number from 0 to %s.', 'lw-migration' ),
				formatNumber( max )
			)
		: '';

/**
 * The backups folder (M3b: its actions start a move job, outside the
 * draft). Retention (M2c): count, total size, days; 0 = no limit. The
 * default address of the schedule mails (M3). Those two are saved with the
 * top bar's Save button (store "settings").
 *
 * @param {Object}                props
 * @param {Object}                props.settings useDraftStore over /settings.
 * @param {boolean}               props.busy     Another job runs.
 * @param {(job: Object) => void} props.onStart  Receives a new move job.
 */
export default function SettingsView( { settings, busy, onStart } ) {
	const lead = (
		<SectionLead
			title={ __( 'Settings', 'lw-migration' ) }
			intro={ __(
				'Where the backups are kept, how many of them are kept, and who gets an e-mail about them.',
				'lw-migration'
			) }
		/>
	);
	const folder = <BackupFolderSection busy={ busy } onStart={ onStart } />;
	if ( settings.error && ! settings.data ) {
		return (
			<>
				{ lead }
				{ folder }
				<LoadError
					error={ settings.error }
					tab="settings"
					onRetry={ settings.reload }
				/>
			</>
		);
	}
	if ( ! settings.data ) {
		return (
			<>
				{ lead }
				{ folder }
				<SkeletonRegion
					label={ __( 'Loading settings…', 'lw-migration' ) }
				>
					<RetentionSection>
						<SkeletonFields count={ 3 } />
					</RetentionSection>
					<NotificationsSection>
						<SkeletonFields count={ 1 } />
					</NotificationsSection>
				</SkeletonRegion>
			</>
		);
	}
	const retention = settings.data.retention;
	const refused = invalidField( settings.saveError );
	const invalid = ( key, max ) => {
		const error = fieldError( refused, key, max );
		return {
			error,
			control: error
				? {
						'aria-invalid': true,
						'aria-describedby': `lw-retention-${ key }-error`,
					}
				: {},
		};
	};
	const count = invalid( 'count', MAX.count );
	const size = invalid( 'bytes', MAX_GB );
	const days = invalid( 'days', MAX.days );
	const set = ( key, value ) =>
		settings.update( { retention: { ...retention, [ key ]: value } } );

	return (
		<>
			{ lead }
			{ /* Second in every branch: the same place keeps it mounted while the settings load. */ }
			{ folder }
			{ isLoggedOut( settings.saveError ) && (
				<LoginNotice tab="settings" className="lw-settings-notice" />
			) }
			<RetentionSection>
				<SettingRow
					title={ __( 'Number of backups', 'lw-migration' ) }
					htmlFor="lw-retention-count"
					error={ count.error }
				>
					<NumberControl
						id="lw-retention-count"
						{ ...count.control }
						__next40pxDefaultSize
						min={ 0 }
						max={ MAX.count }
						value={ retention.count }
						onChange={ ( value ) =>
							set( 'count', whole( value, MAX.count ) )
						}
					/>
				</SettingRow>
				<SettingRow
					title={ __( 'Total size in GB', 'lw-migration' ) }
					htmlFor="lw-retention-bytes"
					error={ size.error }
				>
					<NumberControl
						id="lw-retention-bytes"
						{ ...size.control }
						__next40pxDefaultSize
						min={ 0 }
						max={ MAX_GB }
						// Two decimals are kept; the arrows move by 0.5.
						step={ 0.01 }
						spinFactor={ 50 }
						value={ gbShown( retention.bytes ) }
						onChange={ ( value ) =>
							set(
								'bytes',
								bytesFrom( value, retention.bytes, MAX.bytes )
							)
						}
					/>
				</SettingRow>
				<SettingRow
					title={ __( 'Days to keep', 'lw-migration' ) }
					htmlFor="lw-retention-days"
					error={ days.error }
				>
					<NumberControl
						id="lw-retention-days"
						{ ...days.control }
						__next40pxDefaultSize
						min={ 0 }
						max={ MAX.days }
						value={ retention.days }
						onChange={ ( value ) =>
							set( 'days', whole( value, MAX.days ) )
						}
					/>
				</SettingRow>
			</RetentionSection>
			<NotificationsSection>
				<SettingRow
					title={ __( 'Default address', 'lw-migration' ) }
					htmlFor="lw-notify-email"
					stacked
					error={
						'email' === refused
							? refusalText(
									settings.saveError,
									__(
										'Enter up to 5 valid e-mail addresses, separated by commas.',
										'lw-migration'
									)
								)
							: ''
					}
				>
					<TextControl
						id="lw-notify-email"
						{ ...( 'email' === refused
							? {
									'aria-invalid': true,
									'aria-describedby':
										'lw-notify-email__help lw-notify-email-error',
								}
							: {} ) }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						value={ settings.data.notifications.email }
						placeholder={ ADMIN_EMAIL }
						help={ sprintf(
							/* translators: %s: the site admin's e-mail address. */
							__(
								'Several addresses: separate them with commas. Empty: the site admin, %s.',
								'lw-migration'
							),
							ADMIN_EMAIL
						) }
						onChange={ ( email ) =>
							settings.update( { notifications: { email } } )
						}
					/>
				</SettingRow>
			</NotificationsSection>
		</>
	);
}
