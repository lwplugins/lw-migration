<?php
/**
 * Recording stand-in for the network's site factory.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Support;

use LightweightPlugins\Migration\Multisite\SiteFactory;
use LightweightPlugins\Migration\Multisite\SiteNotCreatedException;

/**
 * Sites live in a "domain path" => id list; created sites get the next id.
 * Every call is recorded in order; a hook may run inside create() (to look
 * at what the job had saved by then, or to stop like a dying request).
 */
final class FakeSiteFactory extends SiteFactory {

	/** @var array<int, string> */
	public array $calls = [];

	/** @var array<string, int> */
	public array $sites = [];

	public int $nextId = 7;

	/** @var \Closure|null fn(): void, run inside create() after the row exists (and was reported), before it returns. */
	public ?\Closure $onCreate = null;

	/** @var array<int, int> Ids delete() refuses. */
	public array $undeletable = [];

	/** @var int|null Another request creates this id at the address first: create() is refused (site_taken). */
	public ?int $raced = null;

	public function find( string $domain, string $path, int $networkId ): ?int {
		$this->calls[] = 'find ' . $domain . $path;

		return $this->sites[ $domain . $path ] ?? null;
	}

	public function maxId(): int {
		$this->calls[] = 'maxId';

		return [] === $this->sites ? 1 : max( $this->sites );
	}

	public function create( string $domain, string $path, int $networkId, string $title, int $userId, ?\Closure $inserted = null ): int {
		$this->calls[] = 'create ' . $domain . $path . ' "' . $title . '" user ' . $userId;
		if ( null !== $this->raced ) {
			$this->sites[ $domain . $path ] = $this->raced;
			throw new SiteNotCreatedException( 'The new site could not be created: Sorry, that site already exists!' );
		}
		$id                              = $this->nextId++;
		$this->sites[ $domain . $path ] = $id;
		if ( null !== $inserted ) {
			$inserted( $id );
		}
		if ( null !== $this->onCreate ) {
			( $this->onCreate )();
		}

		return $id;
	}

	public function finishSetup( int $id, string $title, int $userId ): void {
		$this->calls[] = 'finishSetup ' . $id . ' "' . $title . '" user ' . $userId;
	}

	/**
	 * @return array{siteUrl: string, homeUrl: string, uploadsDir: string, uploadsUrl: string, uploadPath: string, uploadUrlPath: string}
	 */
	public function describe( int $id ): array {
		$this->calls[] = 'describe ' . $id;

		return [
			'siteUrl'       => 'https://new.test/s' . $id,
			'homeUrl'       => 'https://new.test/s' . $id,
			'uploadsDir'    => '/w/uploads/sites/' . $id,
			'uploadsUrl'    => 'https://new.test/wp-content/uploads/sites/' . $id . '/',
			'uploadPath'    => '',
			'uploadUrlPath' => '',
		];
	}

	public function delete( int $id ): bool {
		$this->calls[] = 'delete ' . $id;
		if ( in_array( $id, $this->undeletable, true ) ) {
			return false;
		}
		$this->sites = array_filter( $this->sites, static fn( int $site ): bool => $site !== $id );

		return true;
	}
}
