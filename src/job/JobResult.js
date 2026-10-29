/**
 * WordPress dependencies
 */
import { Button, Notice } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { download } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import LoginNotice from '../components/LoginNotice';
import StatusBadge from '../components/StatusBadge';
import { downloadBackup } from '../data/api';
import { OWN_FOLDER } from '../data/boot';
import { errorMessage, isLoggedOut } from '../data/errors';
import { formatBytes } from '../data/format';
import { label } from '../network/sitePicker';
import ResetResult from '../reset/ResetResult';
import { needsReload } from '../reset/reset';
import CloudResult from './CloudResult';
import { keptByCodeMaker, keptLogin } from './keptLogin';

/**
 * The sites a restore into a network put in place, each with its link.
 *
 * @param {Object}   props
 * @param {Object[]} props.sites `{ url, created }` of the job result.
 */
function RestoredSites( { sites } ) {
	return (
		<ul
			className="lw-result__sites"
			aria-label={ __( 'Restored sites', 'lw-migration' ) }
		>
			{ sites.map( ( site ) => (
				<li key={ site.url }>
					{ /^https?:\/\//i.test( site.url ) ? (
						<a href={ site.url } target="_blank" rel="noreferrer">
							{ label( site ) }
						</a>
					) : (
						label( site )
					) }
					{ site.created && (
						<StatusBadge status="info">
							{ __( 'Created as a new site', 'lw-migration' ) }
						</StatusBadge>
					) }
				</li>
			) ) }
		</ul>
	);
}

function Warnings( { job } ) {
	if ( ! job.warningCount ) {
		return null;
	}
	return (
		<details className="lw-warnings">
			<summary>
				{ sprintf(
					/* translators: %d: number of warnings. */
					_n(
						'%d warning',
						'%d warnings',
						job.warningCount,
						'lw-migration'
					),
					job.warningCount
				) }
			</summary>
			<ul>
				{ job.warnings.map( ( warning, index ) => (
					<li key={ index }>{ warning }</li>
				) ) }
			</ul>
			{ job.warningCount > job.warnings.length && (
				<p>
					{ sprintf(
						/* translators: 1: warnings shown, 2: all warnings. */
						__(
							'The first %1$d of %2$d are shown.',
							'lw-migration'
						),
						job.warnings.length,
						job.warningCount
					) }
				</p>
			) }
		</details>
	);
}

/**
 * How a job ended, and what to do next.
 *
 * @param {Object}     props
 * @param {Object}     props.job     Finished job view.
 * @param {() => void} props.onClose Back to the form.
 */
export default function JobResult( { job, onClose } ) {
	const done = 'done' === job.status;
	// By type: a connection's jobs all live on Connections (tabOf).
	// A connected site's copy of this site (a pull from there) is no backup here.
	const isCopy = 'export' === job.type && Boolean( job.link );
	const isExport = 'export' === job.type && ! isCopy;
	const isMove = 'move' === job.type;
	const isPush = 'push' === job.type;
	const isImport = 'import' === job.type;
	const isReset = 'reset' === job.type;
	const there = job.link?.name || job.link?.address || '';
	const backup = job.result?.backup;
	const safety = job.result?.safetyBackup;
	const sites = job.result?.sites;
	const [ fetching, setFetching ] = useState( false );
	// { text, loggedOut } of the last failed download, or null.
	const [ failure, setFailure ] = useState( null );

	// A Button, never a link (ruling P1): the token link is fetched and
	// checked first, so a dead link never replaces this page.
	const startDownload = async () => {
		if ( fetching ) {
			return;
		}
		setFetching( true );
		setFailure( null );
		try {
			await downloadBackup( { folder: OWN_FOLDER, name: backup.name } );
		} catch ( e ) {
			setFailure( {
				text: errorMessage( e ),
				loggedOut: isLoggedOut( e ),
			} );
		}
		setFetching( false );
	};

	return (
		<div className={ `lw-result is-${ job.status }` }>
			{ done && (
				<p className="lw-result__title">
					{ isExport && __( 'The backup is ready.', 'lw-migration' ) }
					{ isCopy &&
						sprintf(
							/* translators: %s: name of the connected site. */
							__( '%s copied this site.', 'lw-migration' ),
							there
						) }
					{ isPush &&
						sprintf(
							/* translators: %s: the other site's name */
							__(
								'%s now shows the content of this site.',
								'lw-migration'
							),
							there
						) }
					{ isImport &&
						! sites &&
						__( 'The site was restored.', 'lw-migration' ) }
					{ 'cloud' === job.type &&
						'upload' === job.result?.action &&
						__( 'The upload finished.', 'lw-migration' ) }
					{ isMove &&
						sprintf(
							/* translators: 1: number of backups, 2: size */
							_n(
								'%1$d backup moved (%2$s). New backups are saved there from now on.',
								'%1$d backups moved (%2$s). New backups are saved there from now on.',
								job.result?.moved ?? 0,
								'lw-migration'
							),
							job.result?.moved ?? 0,
							formatBytes( job.result?.bytes ?? 0 )
						) }
					{ isImport &&
						sites &&
						sprintf(
							/* translators: %d: number of restored sites. */
							_n(
								'%d site was restored.',
								'%d sites were restored.',
								sites.length,
								'lw-migration'
							),
							sites.length
						) }
				</p>
			) }
			{ done && sites?.length > 0 && <RestoredSites sites={ sites } /> }
			{ isReset && <ResetResult job={ job } onClose={ onClose } /> }
			<CloudResult job={ job } />
			{ done && isExport && backup && (
				<p className="lw-result__detail">
					{ sprintf(
						/* translators: 1: backup file name, 2: size. */
						__( '%1$s, %2$s', 'lw-migration' ),
						backup.name,
						formatBytes( backup.size )
					) }
				</p>
			) }
			{ done && isImport && (
				<p className="lw-result__detail">
					{ sites
						? __(
								'Look around the sites, and empty their caches if they have one.',
								'lw-migration'
							)
						: __(
								'Look around the site, and empty its cache if it has one.',
								'lw-migration'
							) }
					{ keptLogin( job ) &&
						' ' +
							__(
								'You log in with the same username and password as before the restore.',
								'lw-migration'
							) }
					{ keptByCodeMaker( job ) &&
						' ' +
							__(
								'The admin who made the pairing code logs in as before; every other login is now the one of the site that was sent.',
								'lw-migration'
							) }
				</p>
			) }
			{ 'failed' === job.status && (
				<Notice status="error" isDismissible={ false }>
					{ job.error || __( 'The job stopped.', 'lw-migration' ) }
				</Notice>
			) }
			{ 'cancelled' === job.status && (
				<p className="lw-result__title">
					{ __( 'The job was cancelled.', 'lw-migration' ) }
				</p>
			) }
			{ isPush && safety && (
				<p className="lw-result__detail">
					{ sprintf(
						/* translators: %s: backup file name on the connected site. */
						__( 'Safety backup there: %s', 'lw-migration' ),
						safety
					) }
				</p>
			) }
			{ isImport && safety && (
				<p className="lw-result__detail">
					{ sprintf(
						/* translators: %s: backup file name. */
						__(
							'The database from before the restore is saved as %s (under Backups).',
							'lw-migration'
						),
						safety
					) }
				</p>
			) }
			<Warnings job={ job } />
			{ failure?.loggedOut && <LoginNotice tab="export" /> }
			{ failure && ! failure.loggedOut && (
				<Notice status="error" isDismissible={ false }>
					{ failure.text }
				</Notice>
			) }
			<div className="lw-actions">
				{ done && isExport && backup && false !== backup.local && (
					<Button
						__next40pxDefaultSize
						variant="primary"
						icon={ download }
						isBusy={ fetching }
						onClick={ startDownload }
					>
						{ __( 'Download', 'lw-migration' ) }
					</Button>
				) }
				{ isExport && (
					<Button
						__next40pxDefaultSize
						variant="secondary"
						href="#backups"
						onClick={ onClose }
					>
						{ __( 'Open backups', 'lw-migration' ) }
					</Button>
				) }
				<Button
					__next40pxDefaultSize
					variant={
						done &&
						( isExport || ( isReset && needsReload( job.result ) ) )
							? 'tertiary'
							: 'primary'
					}
					onClick={ onClose }
				>
					{ __( 'Done', 'lw-migration' ) }
				</Button>
			</div>
		</div>
	);
}
