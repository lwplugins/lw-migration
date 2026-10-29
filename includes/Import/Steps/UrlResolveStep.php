<?php
/**
 * Import step: checks an address before downloading from it.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import\Steps;

use LightweightPlugins\Migration\Archive\Format;
use LightweightPlugins\Migration\Archive\Header;
use LightweightPlugins\Migration\Archive\InvalidArchiveException;
use LightweightPlugins\Migration\Http\AddressRedactor;
use LightweightPlugins\Migration\Http\DownloadException;
use LightweightPlugins\Migration\Http\RangeDownloader;
use LightweightPlugins\Migration\Http\ShareLinkResolver;
use LightweightPlugins\Migration\Import\ImportException;
use LightweightPlugins\Migration\Import\ImportOptions;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Support\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * Share link → direct link, one probe request (the first header block only),
 * and the first entry must be package.json before a single chunk is
 * downloaded. A download confirmation page is followed only to Google hosts.
 */
final class UrlResolveStep implements StepInterface {

	private const PAGE_BYTES = 262144;

	public function __construct( private RangeDownloader $http ) {
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		$url = ShareLinkResolver::resolve( ImportOptions::fromArray( $job->options )->location );
		try {
			$probe = $this->http->probe( $url );
			if ( str_contains( $probe['contentType'], 'text/html' ) ) {
				$confirm = ShareLinkResolver::confirmUrl( $this->http->page( $url, self::PAGE_BYTES ) );
				if ( null === $confirm || ! ShareLinkResolver::isGoogleHost( $confirm ) ) {
					/* translators: %s: address without its secret parts */
					throw new ImportException( sprintf( __( 'The address leads to a web page, not to a .wpress file: %s. Use a direct download link.', 'lw-migration' ), AddressRedactor::display( $url ) ) );
				}
				$url   = $confirm;
				$probe = $this->http->probe( $url );
			}
		} catch ( DownloadException $e ) {
			/* translators: 1: address without its secret parts, 2: technical reason */
			throw new ImportException( sprintf( __( 'The archive cannot be downloaded from %1$s: %2$s', 'lw-migration' ), AddressRedactor::display( $url ), self::reason( $e ) ) );
		}
		self::assertArchiveHead( $probe['head'], $url );
		$ranges = $probe['ranges'] && null !== $probe['size'];
		if ( ! $ranges ) {
			$job->addWarning( __( 'The server does not support resumable downloads, so the archive is downloaded in one request. In the browser this may hit the time limit; WP-CLI is not affected.', 'lw-migration' ) );
		}
		$job->state['download'] = [
			'url'    => $url,
			'size'   => $probe['size'],
			'ranges' => $ranges,
		];

		return StepResult::done( __( 'Archive address checked.', 'lw-migration' ) );
	}

	/**
	 * The technical reason, explained when the safe API refused the address.
	 *
	 * @param DownloadException $e Download failure.
	 */
	public static function reason( DownloadException $e ): string {
		if ( ! $e->isAddressRefused() ) {
			return $e->getMessage();
		}

		return $e->getMessage() . ' ' . __( 'Local or internal addresses are refused for safety; use a public address.', 'lw-migration' );
	}

	/**
	 * The first header block must describe package.json.
	 *
	 * @param string $head First Format::HEADER_SIZE bytes.
	 * @param string $url  Address (shown without its secret parts).
	 * @throws ImportException When it does not.
	 */
	private static function assertArchiveHead( string $head, string $url ): void {
		try {
			$entry = Header::unpack( $head, 0 );
		} catch ( InvalidArchiveException $e ) {
			$entry = null;
		}
		if ( null === $entry || Format::PACKAGE !== $entry->name ) {
			/* translators: %s: address without its secret parts */
			throw new ImportException( sprintf( __( 'The file at this address is not a .wpress archive: %s', 'lw-migration' ), AddressRedactor::display( $url ) ) );
		}
	}
}
