<?php
/**
 * The site list of the network, for the site picker.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Multisite\NetworkReader;

defined( 'ABSPATH' ) || exit;

/**
 * One page of the network's sites, ordered by id. The search matches the
 * sites' addresses (domain and path, WordPress' own site search), never their
 * titles. Query values are read strictly: a page is a whole number from 1
 * (read as at most 1,000,000), a page size is clamped to 1–100, a search is a
 * short single line of text.
 */
final class NetworkApi {

	public const PER_PAGE = 20;

	public const MAX_PER_PAGE = 100;

	/** Highest page read: keeps the query's offset a small whole number. */
	public const MAX_PAGE = 1000000;

	public const MAX_SEARCH = 100;

	/**
	 * @param NetworkReader $reader Reads the network.
	 */
	public function __construct( private NetworkReader $reader ) {
	}

	/**
	 * Reads the query values, then the page.
	 *
	 * @param mixed $page    ?page= (default 1).
	 * @param mixed $perPage ?perPage= (default 20).
	 * @param mixed $search  ?search= (default '').
	 * @return array{items: array<int, array{id: int, url: string, title: string}>, total: int, pages: int}
	 * @throws RestException On a malformed value.
	 */
	public function find( $page, $perPage, $search ): array {
		$page    = null === $page ? 1 : JobInput::wholeNumber( $page, 'page' );
		$perPage = null === $perPage ? self::PER_PAGE : JobInput::wholeNumber( $perPage, 'perPage' );
		$search  = $search ?? '';
		if ( $page < 1 ) {
			throw RestException::invalidField( 'page' );
		}
		if ( ! is_string( $search ) || mb_strlen( $search, 'UTF-8' ) > self::MAX_SEARCH || 1 === preg_match( '/[\x00-\x1F\x7F]/', $search ) ) {
			throw RestException::invalidField( 'search' );
		}

		return $this->sites( min( self::MAX_PAGE, $page ), min( self::MAX_PER_PAGE, max( 1, $perPage ) ), $search );
	}

	/**
	 * @param int    $page    1-based page.
	 * @param int    $perPage Sites per page (1–100).
	 * @param string $search  Part of a site's address ('' for all).
	 * @return array{items: array<int, array{id: int, url: string, title: string}>, total: int, pages: int}
	 */
	public function sites( int $page, int $perPage, string $search ): array {
		$found = $this->reader->page( $page, $perPage, $search );

		return [
			'items' => $found['items'],
			'total' => $found['total'],
			'pages' => (int) ceil( $found['total'] / max( 1, $perPage ) ),
		];
	}
}
