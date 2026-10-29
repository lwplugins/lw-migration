<?php
/**
 * What an archive holds, for a restore into a network.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Multisite;

use LightweightPlugins\Migration\Import\ImportException;
use LightweightPlugins\Migration\Package\MultisiteConfig;

defined( 'ABSPATH' ) || exit;

/**
 * Decision 2: a whole network, chosen sites of a network, or a single site
 * (no multisite.json).
 */
final class ArchiveKind {

	public const NETWORK = 'network';

	public const SITES = 'sites';

	public const SINGLE = 'single';

	/**
	 * @param MultisiteConfig|null $m The archive's multisite.json, if any.
	 * @throws ImportException When multisite.json lists no site.
	 */
	public static function of( ?MultisiteConfig $m ): string {
		if ( null === $m ) {
			return self::SINGLE;
		}
		if ( [] === $m->sites() ) {
			throw new ImportException( __( 'The archive\'s multisite.json lists no site, so it cannot be restored. The archive may be damaged.', 'lw-migration' ) );
		}

		return $m->isNetwork() ? self::NETWORK : self::SITES;
	}
}
