<?php
/**
 * The old and new addresses of one site of a network restore.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Multisite;

defined( 'ABSPATH' ) || exit;

/**
 * Plain value consumed by Database\UrlPairs::forNetwork() (decision 16): the
 * archive site's addresses and uploads URL, the resolved site's ones, and how
 * the network stores uploads. Pure.
 */
final class BlogUrlMove {

	/**
	 * @param int|null $oldBlogId          Blog id in the archive (null: a single-site archive).
	 * @param int      $newBlogId          Blog id on the network.
	 * @param string   $oldSiteUrl         Old site URL.
	 * @param string   $oldInternalSiteUrl Old internal site URL ('' when unknown).
	 * @param string   $oldHomeUrl         Old home URL.
	 * @param string   $oldInternalHomeUrl Old internal home URL ('' when unknown).
	 * @param string   $oldUploadsUrl      Old uploads URL (WordPress.UploadsURL of the entry).
	 * @param string   $newSiteUrl         New site URL.
	 * @param string   $newHomeUrl         New home URL.
	 * @param string   $newUploadsUrl      New uploads URL.
	 * @param string   $uploadsRel         The network's uploads folder relative to its main address (wp-content/uploads).
	 * @param bool     $uploadBlogsDir     The network keeps subsite uploads in blogs.dir (legacy files rewriting).
	 */
	public function __construct(
		public ?int $oldBlogId,
		public int $newBlogId,
		public string $oldSiteUrl,
		public string $oldInternalSiteUrl,
		public string $oldHomeUrl,
		public string $oldInternalHomeUrl,
		public string $oldUploadsUrl,
		public string $newSiteUrl,
		public string $newHomeUrl,
		public string $newUploadsUrl,
		public string $uploadsRel,
		public bool $uploadBlogsDir
	) {
	}

	/**
	 * @param array<string, mixed> $m          A resolved BlogMap mapping.
	 * @param TargetNetwork        $to         Target network.
	 * @param string               $uploadsRel The network's uploads folder relative to its main address.
	 * @return self
	 * @throws \LogicException When the mapping has no new site yet.
	 */
	public static function fromMapping( array $m, TargetNetwork $to, string $uploadsRel ): self {
		$newId = $m['newId'] ?? null;
		if ( ! is_int( $newId ) || $newId < 1 ) {
			throw new \LogicException( 'A site of the archive has no new site yet.' );
		}
		$old  = SiteEntry::fromArray( is_array( $m['old'] ?? null ) ? $m['old'] : [] );
		$text = static fn( string $key ): string => is_string( $m[ $key ] ?? null ) ? $m[ $key ] : '';

		return new self(
			$old->blogId,
			$newId,
			$old->siteUrl,
			$old->internalSiteUrl,
			$old->homeUrl,
			$old->internalHomeUrl,
			$old->wpUploadsUrl,
			$text( 'newSiteUrl' ),
			$text( 'newHomeUrl' ),
			$text( 'newUploadsUrl' ),
			$uploadsRel,
			$to->uploadBlogsDir
		);
	}
}
