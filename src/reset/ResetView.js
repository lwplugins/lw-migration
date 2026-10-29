/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import Callout from '../components/Callout';
import ResetLead from './ResetLead';
import LoadError from '../components/LoadError';
import { api } from '../data/api';
import useRemote from '../data/useRemote';
import ResetChoices from './ResetChoices';
import ResetLayers from './ResetLayers';
import ResetSkeleton from './ResetSkeleton';
import ResetSteps from './ResetSteps';
import './reset.scss';

const PICK_TITLE_ID = 'lw-reset-pick-title';
const LAYERS_TITLE_ID = 'lw-reset-layers-title';
const STEPS_TITLE_ID = 'lw-reset-steps-title';

/**
 * Reset (spec 20): a warning lead, the five resets as choices, then one
 * panel: what goes and what stays on the layers of the site, and the steps
 * before the start (backup, password, the site address) with the one
 * destructive button. Nothing is chosen at first, and nothing opens a
 * dialog.
 *
 * @param {Object}                props
 * @param {boolean}               props.busy    Another job runs on this site.
 * @param {(job: Object) => void} props.onStart Receives the started reset.
 */
export default function ResetView( { busy, onStart } ) {
	const remote = useRemote( api.resetSummary );
	const [ type, setType ] = useState( null );
	// While a start is on its way the choice holds, so its answer (a
	// refusal) is shown on the choice it was sent for.
	const [ starting, setStarting ] = useState( false );
	const choose = ( next ) => ! starting && setType( next );

	if ( remote.error && ! remote.data ) {
		return (
			<LoadError
				error={ remote.error }
				tab="reset"
				onRetry={ remote.reload }
			/>
		);
	}
	if ( ! remote.data ) {
		return <ResetSkeleton />;
	}
	const summary = remote.data;

	return (
		<div className="lw-reset">
			<ResetLead />
			<div className="lw-reset-lead">
				<Callout tone="warning">
					{ summary.network
						? __(
								'These tools delete. Each one backs up the whole network first unless you switch that off, and asks for your password.',
								'lw-migration'
							)
						: __(
								'These tools delete. Each one backs up the whole site first unless you switch that off, and asks for your password.',
								'lw-migration'
							) }{ ' ' }
					{ __(
						'Before that, you see here what goes and what stays.',
						'lw-migration'
					) }
				</Callout>
				{ summary.network && (
					<Callout>
						{ __(
							'On a network the database cannot be reset in this version; plugins, themes and media are reset for every site.',
							'lw-migration'
						) }
					</Callout>
				) }
			</div>
			<section
				className="lw-reset-pick"
				aria-labelledby={ PICK_TITLE_ID }
			>
				<h2 id={ PICK_TITLE_ID } className="lw-reset-pick__title">
					{ __( 'What do you want to reset?', 'lw-migration' ) }
				</h2>
				<ResetChoices
					summary={ summary }
					value={ type }
					onChange={ choose }
					labelledBy={ PICK_TITLE_ID }
					disabled={ starting }
				/>
			</section>
			<div className="lw-reset-plan">
				{ type ? (
					<>
						<section
							className="lw-reset-plan__part"
							aria-labelledby={ LAYERS_TITLE_ID }
						>
							<h3
								id={ LAYERS_TITLE_ID }
								className="lw-reset-plan__title"
							>
								{ __(
									'What goes and what stays',
									'lw-migration'
								) }
							</h3>
							<p className="lw-reset-plan__sub">
								{ __(
									'From the site as it is now.',
									'lw-migration'
								) }
							</p>
							<ResetLayers type={ type } summary={ summary } />
						</section>
						<section
							className="lw-reset-plan__part"
							aria-labelledby={ STEPS_TITLE_ID }
						>
							<h3
								id={ STEPS_TITLE_ID }
								className="lw-reset-plan__title"
							>
								{ __( 'Before you start', 'lw-migration' ) }
							</h3>
							<ResetSteps
								type={ type }
								summary={ summary }
								busy={ busy }
								onStart={ onStart }
								onStarting={ setStarting }
							/>
						</section>
					</>
				) : (
					<p className="lw-reset-plan__idle">
						{ __(
							'Choose a tool above: here you see on the layers of the site what goes and what stays, and only then do we ask for your password.',
							'lw-migration'
						) }
					</p>
				) }
			</div>
		</div>
	);
}
