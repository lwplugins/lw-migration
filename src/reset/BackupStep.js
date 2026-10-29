/**
 * WordPress dependencies
 */
import { ToggleControl } from '@wordpress/components';
import { createInterpolateElement } from '@wordpress/element';
import { __, _x, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import Step from './Step';

/**
 * @return {Element} The backup's file name pattern and how long it is kept.
 */
export function BackupFile() {
	return (
		<>
			{ createInterpolateElement(
				sprintf(
					/* translators: 1: the backup's file name pattern, 2: its label in Backups. */
					__(
						'Named %1$s, marked “%2$s” in Backups.',
						'lw-migration'
					),
					'<file />',
					__( 'Before a reset', 'lw-migration' )
				),
				{
					file: (
						<code className="lw-reset-file">
							{ `pre-reset-<${ _x(
								'time',
								'placeholder in a backup file name',
								'lw-migration'
							) }>.wpress` }
						</code>
					),
				}
			) }{ ' ' }
			{ __(
				'The backup is kept until you delete it (resets never remove backups).',
				'lw-migration'
			) }
		</>
	);
}

/**
 * The first step: the backup switch (on by default) and what it means: the
 * backup's name and how long it is kept, that the backups folder cannot
 * take one now, or that without one the reset cannot be undone. Done only
 * while a backup will be taken.
 *
 * @param {Object}                    props
 * @param {boolean}                   props.backup   The switch.
 * @param {boolean}                   props.ready    Nothing blocks the start on the backup's side (stepsReady().backup).
 * @param {string}                    props.errorId  Id of the text saying the backups folder blocks the start.
 * @param {(backup: boolean) => void} props.onChange Receives the switch.
 */
export default function BackupStep( { backup, ready, errorId, onChange } ) {
	return (
		<Step number={ 1 } done={ backup && ready }>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Back up the whole site first', 'lw-migration' ) }
				checked={ backup }
				onChange={ onChange }
			/>
			{ backup && ready && (
				<p className="lw-reset-step__help">
					<BackupFile />
				</p>
			) }
			{ ! ready && (
				<p id={ errorId } className="lw-reset-step__error">
					{ __(
						'The backups folder cannot take a backup right now — check Settings, or switch the backup off to reset without one.',
						'lw-migration'
					) }
				</p>
			) }
			{ ! backup && (
				<p className="lw-reset-step__error">
					{ __(
						'Without a backup this cannot be undone.',
						'lw-migration'
					) }
				</p>
			) }
		</Step>
	);
}
