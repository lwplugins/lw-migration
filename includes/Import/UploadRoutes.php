<?php
/**
 * Where the archive's uploads go below this site's uploads folder.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import;

use LightweightPlugins\Migration\Multisite\ArchiveKind;
use LightweightPlugins\Migration\Multisite\BlogMap;
use LightweightPlugins\Migration\Multisite\SiteEntry;

defined( 'ABSPATH' ) || exit;

/**
 * Archive prefixes (uploads/sites/<id>/, blogs.dir/<id>/files/, uploads/)
 * mapped to paths relative to the uploads base. On a single site a subsite
 * archive's own folders land in the uploads root, as before. On a network
 * each archived blog lands in its new site's folder; a sites or single-site
 * archive is strict: an uploads entry no mapped blog owns is never written
 * (the network's main uploads and other sites' folders stay as they are).
 * Pure.
 */
final class UploadRoutes {

	/**
	 * @param array<int, array{0: string, 1: string}> $prefixes Ordered blog prefix => uploads-relative target ('' = the uploads base).
	 * @param string|null                             $main     Target of the remaining uploads/ entries (null: none).
	 * @param array<int, string>                      $orphans  Prefixes that only blogs own (checked before the main uploads).
	 * @param bool                                    $strict   Unmatched uploads entries belong to no blog (skip).
	 */
	private function __construct( private array $prefixes, private ?string $main, private array $orphans, private bool $strict ) {
	}

	/**
	 * Today's single-site routes: the subsite's folders (if any) and uploads/ go to the uploads root.
	 *
	 * @param int|null $subsiteId Blog id of a subsite archive, null for a single-site archive.
	 */
	public static function forSubsite( ?int $subsiteId ): self {
		$prefixes = null === $subsiteId ? [] : [ [ 'uploads/sites/' . $subsiteId . '/', '' ], [ 'blogs.dir/' . $subsiteId . '/files/', '' ] ];

		return new self( $prefixes, '', [], false );
	}

	/**
	 * @param string  $kind           ArchiveKind value.
	 * @param BlogMap $map            Resolved blog map (ruling R21).
	 * @param bool    $uploadBlogsDir The network keeps legacy blogs.dir files.
	 * @throws \LogicException When a site of the map has no new id yet.
	 */
	public static function forNetwork( string $kind, BlogMap $map, bool $uploadBlogsDir ): self {
		if ( ! $map->isResolved() ) {
			throw new \LogicException( 'Uploads are routed only once every site of the archive has its new id.' );
		}
		$network  = ArchiveKind::NETWORK === $kind;
		$prefixes = [];
		$main     = null;
		foreach ( $map->all() as $m ) {
			$old = SiteEntry::fromArray( $m['old'] );
			$new = (int) $m['newId'];
			$to  = $new > 1 ? 'sites/' . $new . '/' : '';
			if ( $old->isMain() ) {
				$main = $to;
				continue;
			}
			if ( ! ( $network && $uploadBlogsDir ) ) {
				$prefixes[] = [ 'blogs.dir/' . $old->blogId . '/files/', $to ];
				$prefixes[] = [ 'blogs.dir/' . $old->blogId . '/', $to ];
			}
			$prefixes[] = [ 'uploads/sites/' . $old->blogId . '/', $to ];
		}
		$orphans = [
			ArchiveKind::NETWORK => [],
			ArchiveKind::SINGLE  => [ 'blogs.dir/' ],
		];

		return new self( $prefixes, $main, $orphans[ $kind ] ?? [ 'uploads/sites/', 'blogs.dir/' ], ! $network );
	}

	/**
	 * @param string $entry Normalised entry name.
	 * @return array{rel: string, blog: string}|false|null rel below the uploads base, blog = its site's folder there
	 *                                                    (rel starts with it); null: not an uploads entry; false: an
	 *                                                    uploads entry no blog owns.
	 */
	public function route( string $entry ) {
		foreach ( $this->prefixes as [ $prefix, $to ] ) {
			if ( str_starts_with( $entry, $prefix ) ) {
				return self::to( $to, substr( $entry, strlen( $prefix ) ) );
			}
		}
		foreach ( $this->orphans as $prefix ) {
			if ( str_starts_with( $entry, $prefix ) ) {
				return false;
			}
		}
		if ( null !== $this->main && str_starts_with( $entry, 'uploads/' ) ) {
			return self::to( $this->main, substr( $entry, strlen( 'uploads/' ) ) );
		}

		return $this->strict && ( str_starts_with( $entry, 'uploads/' ) || str_starts_with( $entry, 'blogs.dir/' ) ) ? false : null;
	}

	/**
	 * @param string $blog The site's folder below the uploads base ('' or sites/<id>/).
	 * @param string $path Path inside it.
	 * @return array{rel: string, blog: string}
	 */
	private static function to( string $blog, string $path ): array {
		return [
			'rel'  => $blog . $path,
			'blog' => $blog,
		];
	}

	/**
	 * Every routed prefix in order, the main uploads last (a bare one is never a file).
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	public function prefixes(): array {
		return null === $this->main ? $this->prefixes : array_merge( $this->prefixes, [ [ 'uploads/', $this->main ] ] );
	}
}
