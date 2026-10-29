<?php
/**
 * Which archive site becomes which site of the network.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Multisite;

use LightweightPlugins\Migration\Import\ImportException;

defined( 'ABSPATH' ) || exit;

/**
 * Decision 11 (state.blogs): one mapping per archive site, in archive order:
 * {old, newId, url, proposal, created, newSiteUrl, newHomeUrl, newUploadsUrl,
 * newUploadsDir, newUploadPath, newUploadUrlPath}. A network archive keeps its
 * ids and its addresses are computed here; sites of a sites or single-site
 * archive get their id when network_sites resolves them after the
 * confirmation. Immutable: every change returns a new map. Pure; texts are
 * translated only when refusing.
 */
final class BlogMap {

	/** Characters kept of a refused address (it is only shown with its reason). */
	private const REFUSED_MAX = 200;

	/** Values a resolution may fill in. */
	private const RESOLVED_KEYS = [ 'newId', 'created', 'newSiteUrl', 'newHomeUrl', 'newUploadsUrl', 'newUploadsDir', 'newUploadPath', 'newUploadUrlPath' ];

	/**
	 * @param array<int, array<string, mixed>> $maps Normalised mappings.
	 */
	private function __construct( private array $maps ) {
	}

	/**
	 * @param string                $kind        ArchiveKind value.
	 * @param array<int, SiteEntry> $entries     Sites of the archive.
	 * @param string                $oldMainHome Home URL of the archive's main site.
	 * @param TargetNetwork         $to          Target network.
	 * @return self
	 */
	public static function plan( string $kind, array $entries, string $oldMainHome, TargetNetwork $to ): self {
		if ( ArchiveKind::SINGLE !== $kind ) {
			self::checkIds( $entries );
		}
		$maps = [];
		foreach ( array_values( $entries ) as $entry ) {
			$network = ArchiveKind::NETWORK === $kind;
			$url     = $network && $entry->isMain() ? $to->mainUrl() : UrlProposal::for( $entry, $oldMainHome, $to );
			$map     = self::normalise(
				[
					'old'      => $entry->state(),
					'url'      => $url,
					'proposal' => $url,
				]
			);
			if ( $network ) {
				$map = array_merge( $map, self::kept( $entry->isMain() ? $to->mainSiteId : (int) $entry->blogId, $entry->isMain(), $url, $to ) );
			}
			$maps[] = $map;
		}
		if ( ArchiveKind::NETWORK === $kind ) {
			self::checkKeptAddresses( $maps );
		}

		return new self( $maps );
	}

	/**
	 * @param array<mixed> $a toArray() data.
	 * @return self
	 */
	public static function fromArray( array $a ): self {
		return new self( array_map( [ self::class, 'normalise' ], array_values( array_filter( $a, 'is_array' ) ) ) );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function toArray(): array {
		return $this->maps;
	}

	/**
	 * @return array<int, array<string, mixed>> The mappings, in archive order.
	 */
	public function all(): array {
		return $this->maps;
	}

	public function count(): int {
		return count( $this->maps );
	}

	/**
	 * @param int $i Index in archive order.
	 * @return array<string, mixed>
	 * @throws \OutOfRangeException For an index the archive does not have.
	 */
	public function get( int $i ): array {
		if ( ! isset( $this->maps[ $i ] ) ) {
			throw new \OutOfRangeException( sprintf( 'No site %d in the blog map.', $i ) );
		}

		return $this->maps[ $i ];
	}

	/**
	 * Given addresses replace the proposals: a valid one in its checked form,
	 * a refused one as given, without control characters and clipped
	 * (problems() names the reason, the summary shows it).
	 *
	 * @param array<mixed>  $byIndex Index => address, from the confirmation.
	 * @param TargetNetwork $to      Target network.
	 * @return self
	 * @throws \InvalidArgumentException For an index the archive does not have (translated).
	 */
	public function withAddresses( array $byIndex, TargetNetwork $to ): self {
		$maps = $this->maps;
		foreach ( $byIndex as $i => $url ) {
			if ( ! is_int( $i ) || ! isset( $maps[ $i ] ) ) {
				/* translators: %s: index of a site in the archive, as given in the answer */
				throw new \InvalidArgumentException( sprintf( __( 'The archive has no site number %s.', 'lw-migration' ), (string) $i ) );
			}
			$url = is_string( $url ) ? $url : '';
			try {
				$maps[ $i ]['url'] = SiteAddress::check( $url, $to )['url'];
			} catch ( \InvalidArgumentException $e ) {
				$maps[ $i ]['url'] = self::shown( $url );
			}
		}

		return new self( $maps );
	}

	/**
	 * @param string $url A refused address as given.
	 */
	private static function shown( string $url ): string {
		return mb_substr( NetworkSummary::printable( $url ), 0, self::REFUSED_MAX, 'UTF-8' );
	}

	/**
	 * Why each current address cannot be used (decision 12): the SiteAddress
	 * reason, or, for two sites at one address, the same reason at both.
	 *
	 * @param TargetNetwork $to Target network.
	 * @return array<int, string> Index => translated reason; empty when every address is fine.
	 */
	public function problems( TargetNetwork $to ): array {
		$problems = [];
		$at       = [];
		foreach ( $this->maps as $i => $map ) {
			try {
				$address      = SiteAddress::check( (string) $map['url'], $to );
				$key          = $address['domain'] . $address['path'];
				$at[ $key ][] = $i;
			} catch ( \InvalidArgumentException $e ) {
				$problems[ $i ] = $e->getMessage();
			}
		}
		foreach ( $at as $indexes ) {
			foreach ( count( $indexes ) > 1 ? $indexes : [] as $i ) {
				$problems[ $i ] = __( 'Two sites of the archive cannot get the same address.', 'lw-migration' );
			}
		}
		ksort( $problems );

		return $problems;
	}

	/**
	 * @param int                  $i   Index in archive order.
	 * @param array<string, mixed> $new newId, created and new* values (other keys are ignored).
	 * @return self
	 */
	public function resolved( int $i, array $new ): self {
		$maps       = $this->maps;
		$maps[ $i ] = self::normalise( array_merge( $this->get( $i ), array_intersect_key( $new, array_flip( self::RESOLVED_KEYS ) ) ) );

		return new self( $maps );
	}

	public function isResolved(): bool {
		foreach ( $this->maps as $map ) {
			if ( null === $map['newId'] ) {
				return false;
			}
		}

		return [] !== $this->maps;
	}

	/**
	 * @return array<int, int|null> Blog ids of the archive (null for a single-site archive).
	 */
	public function oldIds(): array {
		return array_map( static fn( array $map ): ?int => self::oldId( $map ), $this->maps );
	}

	/**
	 * @param int|null $oldId Blog id in the archive (null: the single-site archive's one site).
	 */
	public function newIdOf( ?int $oldId ): ?int {
		foreach ( $this->maps as $map ) {
			if ( self::oldId( $map ) === $oldId ) {
				return $map['newId'];
			}
		}

		return null;
	}

	/**
	 * Network and sites archives name every site by a whole-number id, each once.
	 *
	 * @param array<int, SiteEntry> $entries Sites of the archive.
	 * @throws ImportException For a missing, broken or repeated id.
	 */
	private static function checkIds( array $entries ): void {
		$ids = array_map( static fn( SiteEntry $e ): ?int => $e->blogId, $entries );
		if ( in_array( null, $ids, true ) || min( array_merge( $ids, [ 1 ] ) ) < 1 || count( $ids ) !== count( array_unique( $ids ) ) ) {
			throw new ImportException( __( 'The archive\'s multisite.json lists a site without a valid ID, or one ID twice, so it cannot be restored. The archive may be damaged.', 'lw-migration' ) );
		}
	}

	/**
	 * A network archive keeps its sites' computed addresses: two at one address cannot both be restored.
	 *
	 * @param array<int, array<string, mixed>> $maps Planned mappings.
	 * @throws ImportException When two sites would share an address.
	 */
	private static function checkKeptAddresses( array $maps ): void {
		$seen = [];
		foreach ( $maps as $map ) {
			$url = strtolower( (string) $map['url'] );
			if ( isset( $seen[ $url ] ) ) {
				/* translators: %s: the address two sites of the archive would share */
				throw new ImportException( sprintf( __( 'Two sites of the archive would get the same address on this network (%s), so it cannot be restored here.', 'lw-migration' ), $url ) );
			}
			$seen[ $url ] = true;
		}
	}

	/**
	 * Decision 11 for a kept id: the site's addresses and uploads on the target.
	 *
	 * @param int           $id   Blog id (kept).
	 * @param bool          $main The main site.
	 * @param string        $url  The site's address.
	 * @param TargetNetwork $to   Target network.
	 * @return array<string, mixed>
	 */
	private static function kept( int $id, bool $main, string $url, TargetNetwork $to ): array {
		$blogsDir = $to->uploadBlogsDir && ! $main;
		$sites    = $main ? '' : '/sites/' . $id;

		return [
			'newId'            => $id,
			'newSiteUrl'       => $url,
			'newHomeUrl'       => $url,
			'newUploadsUrl'    => $blogsDir ? $url . '/files/' : $url . '/' . $to->uploadsRel() . $sites . '/',
			'newUploadsDir'    => $blogsDir ? dirname( $to->uploadsDir ) . '/blogs.dir/' . $id . '/files' : $to->uploadsDir . $sites,
			'newUploadPath'    => $blogsDir ? 'wp-content/blogs.dir/' . $id . '/files' : '',
			'newUploadUrlPath' => '',
		];
	}

	/**
	 * @param array<mixed> $m A mapping as stored.
	 * @return array<string, mixed>
	 */
	private static function normalise( array $m ): array {
		$text = static fn( string $key ): string => is_string( $m[ $key ] ?? null ) ? $m[ $key ] : '';

		return [
			'old'              => is_array( $m['old'] ?? null ) ? $m['old'] : [],
			'newId'            => is_int( $m['newId'] ?? null ) && $m['newId'] > 0 ? $m['newId'] : null,
			'url'              => $text( 'url' ),
			'proposal'         => $text( 'proposal' ),
			'created'          => true === ( $m['created'] ?? null ),
			'newSiteUrl'       => $text( 'newSiteUrl' ),
			'newHomeUrl'       => $text( 'newHomeUrl' ),
			'newUploadsUrl'    => $text( 'newUploadsUrl' ),
			'newUploadsDir'    => $text( 'newUploadsDir' ),
			'newUploadPath'    => $text( 'newUploadPath' ),
			'newUploadUrlPath' => $text( 'newUploadUrlPath' ),
		];
	}

	/**
	 * @param array<string, mixed> $map A mapping.
	 */
	private static function oldId( array $map ): ?int {
		$id = $map['old']['BlogID'] ?? null;

		return is_int( $id ) ? $id : null;
	}
}
