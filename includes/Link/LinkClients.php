<?php
/**
 * The signed client of one outgoing connection.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Link;

use LightweightPlugins\Migration\Job\Job;

defined( 'ABSPATH' ) || exit;

/**
 * The Client of one outgoing connection, or GONE when it no longer works
 * here (removed, not confirmed, or its secret does not open after a salt
 * change): the user is told to pair again.
 */
final class LinkClients {

	/**
	 * @param Links         $links Links.
	 * @param \Closure      $now   fn(): int.
	 * @param \Closure|null $send  Transport (default: Client's guarded transport).
	 */
	public function __construct( private Links $links, private \Closure $now, private ?\Closure $send = null ) {
	}

	public static function fromWordPress(): self {
		return new self( new Links(), static fn(): int => time() );
	}

	/**
	 * @param string $id Link id.
	 * @throws LinkException GONE.
	 */
	public function forLink( string $id ): Client {
		$link = $this->usable( $id );

		return new Client(
			[
				'id'      => $link['id'],
				'address' => $link['address'],
				'key'     => (string) $link['key'],
			],
			$this->send,
			$this->now
		);
	}

	/**
	 * The link of a push or pull job: its state names it once the job
	 * started, its options before.
	 *
	 * @param Job $job Job.
	 * @throws LinkException GONE.
	 */
	public function forJob( Job $job ): Client {
		$id = $job->state['link']['id'] ?? $job->options['location'] ?? '';

		return $this->forLink( is_string( $id ) ? $id : '' );
	}

	/**
	 * @param string $id Link id.
	 * @return array{id: string, name: string, address: string, allow: array<int, string>}
	 * @throws LinkException GONE.
	 */
	public function view( string $id ): array {
		$link = $this->usable( $id );

		return [
			'id'      => $link['id'],
			'name'    => $link['name'],
			'address' => $link['address'],
			'allow'   => $link['allow'],
		];
	}

	/**
	 * @param string $id Link id.
	 * @return array{id: string, direction: string, address: string, name: string, allow: array<int, string>, key: string|null}
	 * @throws LinkException GONE.
	 */
	private function usable( string $id ): array {
		$link = 1 === preg_match( Signer::LINK_PATTERN, $id ) ? $this->links->find( $id, (int) ( $this->now )() ) : null;
		if ( null === $link || Links::OUT !== $link['direction'] || ! $link['confirmed'] || null === $link['key'] ) {
			throw new LinkException( LinkException::GONE, __( 'This connection was removed or no longer works on this site. Pair the two sites again.', 'lw-migration' ) );
		}

		return $link;
	}
}
