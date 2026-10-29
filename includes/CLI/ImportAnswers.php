<?php
/**
 * Answers an import job's questions on the command line.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\CLI;

use LightweightPlugins\Migration\Import\ImportState;
use LightweightPlugins\Migration\Import\ImportSummary;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Multisite\ArchiveKind;
use LightweightPlugins\Migration\Multisite\NetworkSummary;

defined( 'ABSPATH' ) || exit;

/**
 * Password: the one given with --password (a wrong one stops the import), or a
 * hidden prompt (the third wrong answer stops it). Confirmation: with --yes the
 * summary is only shown, otherwise it is shown with a yes/no question.
 *
 * A sites or single-site archive restored into a network sends each site's
 * new address with the first confirmation (`sites`: index => address): the
 * --site-url values in archive order, then (interactive) the typed address,
 * the proposal when nothing is typed; with --yes a site without a given
 * address keeps its proposal. When the summary carries refused addresses,
 * the import stops with the reasons with --yes (or when no new address can
 * be had); interactively the addresses are asked again.
 *
 * An exception thrown here leaves the drive; the command then fails the job
 * with its message (Drive::stop()).
 */
final class ImportAnswers {

	/** Wrong answers to the password prompt before the import is stopped. */
	public const PASSWORD_TRIES = 3;

	/**
	 * Wrong passwords typed at the prompt so far.
	 *
	 * @var int
	 */
	private int $wrong = 0;

	/**
	 * Whether addresses were sent once (--site-url applies to the first answer only).
	 *
	 * @var bool
	 */
	private bool $sent = false;

	/**
	 * @param string|null        $givenPassword --password value, null when not given.
	 * @param bool               $yes           --yes.
	 * @param \Closure           $askPassword   fn(): ?string, null at end of input.
	 * @param \Closure           $confirm       fn( array<int, string> $lines ): bool, shows the summary and asks.
	 * @param \Closure           $show          fn( array<int, string> $lines ): void, shows the summary (--yes).
	 * @param \Closure           $warn          fn( string $warning ): void.
	 * @param array<int, string> $siteUrls      --site-url values, in archive order.
	 * @param \Closure|null      $askAddress    fn( string $from, string $proposal ): ?string, null at end of input.
	 */
	public function __construct( private ?string $givenPassword, private bool $yes, private \Closure $askPassword, private \Closure $confirm, private \Closure $show, private \Closure $warn, private array $siteUrls = [], private ?\Closure $askAddress = null ) {
	}

	/**
	 * @param Job $job Waiting job.
	 * @return array<string, mixed>|null Input, or null to cancel the job.
	 * @throws \RuntimeException When the password cannot be had (wrong on the command line, wrong too often, or not entered), or site addresses are refused.
	 */
	public function answer( Job $job ): ?array {
		$kind = (string) ( $job->input['kind'] ?? '' );
		$data = (array) ( $job->input['data'] ?? [] );
		if ( 'password' === $kind ) {
			return [ 'password' => $this->password( 'wrong' === ( $data['reason'] ?? '' ) ) ];
		}
		if ( 'confirm' === $kind ) {
			$lines   = ImportSummary::lines( $data );
			$network = is_array( $data['network'] ?? null ) ? $data['network'] : null;
			if ( null !== $network && ArchiveKind::NETWORK !== ( $network['kind'] ?? '' ) ) {
				return $this->confirmSites( $data, $network );
			}
			if ( null !== $network && [] !== $this->siteUrls ) {
				throw new \RuntimeException( __( 'This archive holds a whole network: its sites keep their addresses. Leave out --site-url.', 'lw-migration' ) );
			}
			$this->stopOnRefusals( $lines, null === $network ? [] : NetworkSummary::refusals( $network ) );
			if ( $this->yes ) {
				( $this->show )( $lines );
				return [ 'confirm' => true ];
			}

			return ( $this->confirm )( $lines ) ? [ 'confirm' => true ] : null;
		}

		return null;
	}

	/**
	 * The confirmation of a sites or single-site archive restored into a network.
	 *
	 * @param array<string, mixed> $data    The summary data.
	 * @param array<string, mixed> $network Its network part.
	 * @return array<string, mixed>|null Input, or null to cancel the job.
	 * @throws \RuntimeException For more addresses than sites, refused addresses with --yes, or no address typed.
	 */
	private function confirmSites( array $data, array $network ): ?array {
		$sites = array_values( array_filter( (array) ( $network['sites'] ?? [] ), 'is_array' ) );
		if ( count( $this->siteUrls ) > count( $sites ) ) {
			/* translators: 1: number of sites in the archive, 2: number of addresses given */
			throw new \RuntimeException( sprintf( _n( 'The archive has %1$d site but %2$d addresses were given.', 'The archive has %1$d sites but %2$d addresses were given.', count( $sites ), 'lw-migration' ), count( $sites ), count( $this->siteUrls ) ) );
		}
		$given = $this->sent ? [] : $this->siteUrls;
		if ( $this->yes || null === $this->askAddress ) {
			$this->stopOnRefusals( ImportSummary::lines( $data ), [] === $given ? NetworkSummary::refusals( $network ) : [] );
			if ( $this->yes ) {
				( $this->show )( self::lines( $data, $sites, $given ) );
			} elseif ( ! ( $this->confirm )( self::lines( $data, $sites, $given ) ) ) {
				return null;
			}
			$this->sent = true;

			return [] === $given ? [ 'confirm' => true ] : [
				'confirm' => true,
				'sites'   => $given,
			];
		}
		( $this->show )( self::lines( $data, $sites, $given ) );
		$addresses = [];
		foreach ( $sites as $site ) {
			$i               = (int) ( $site['index'] ?? 0 );
			$addresses[ $i ] = $given[ $i ] ?? $this->askAddress( (string) ( $site['from']['url'] ?? '' ), self::defaultAddress( $site ) );
		}
		if ( ! ( $this->confirm )( self::chosen( $sites, $addresses ) ) ) {
			return null;
		}
		$this->sent = true;

		return [
			'confirm' => true,
			'sites'   => $addresses,
		];
	}

	/**
	 * The address an empty answer keeps: the current one, or the proposal when the current one is refused.
	 *
	 * @param array<string, mixed> $site One site of the network part.
	 */
	private static function defaultAddress( array $site ): string {
		$address = (string) ( $site['address'] ?? '' );

		return null === ( $site['error'] ?? null ) && '' !== $address ? $address : (string) ( $site['proposal'] ?? '' );
	}

	/**
	 * The summary; a site with a given address is named with that address
	 * (the summary only knows its proposal), after the others.
	 *
	 * @param array<string, mixed>             $data  The summary data.
	 * @param array<int, array<string, mixed>> $sites Its network part's sites.
	 * @param array<int, string>               $given Index => given address.
	 * @return array<int, string>
	 */
	private static function lines( array $data, array $sites, array $given ): array {
		if ( [] === $given ) {
			return ImportSummary::lines( $data );
		}
		$data['network']['sites'] = array_values( array_filter( $sites, static fn( array $site ): bool => ! isset( $given[ (int) ( $site['index'] ?? 0 ) ] ) ) );
		$named                    = array_filter( $sites, static fn( array $site ): bool => isset( $given[ (int) ( $site['index'] ?? 0 ) ] ) );

		return array_merge( ImportSummary::lines( $data ), self::chosen( $named, $given ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $sites     Sites of the network part.
	 * @param array<int, string>               $addresses Index => new address.
	 * @return array<int, string>
	 */
	private static function chosen( array $sites, array $addresses ): array {
		$lines = [];
		foreach ( $sites as $site ) {
			/* translators: 1: old address, 2: new address */
			$lines[] = sprintf( __( 'Site %1$s -> %2$s', 'lw-migration' ), (string) ( $site['from']['url'] ?? '' ), $addresses[ (int) ( $site['index'] ?? 0 ) ] ?? '' );
		}

		return $lines;
	}

	/**
	 * @param string $from     The site's old address.
	 * @param string $proposal The address an empty answer keeps.
	 * @throws \RuntimeException When no address is entered (end of input).
	 */
	private function askAddress( string $from, string $proposal ): string {
		$typed = null === $this->askAddress ? null : ( $this->askAddress )( $from, $proposal );
		if ( null === $typed ) {
			throw new \RuntimeException( __( 'No address entered.', 'lw-migration' ) );
		}
		$typed = trim( (string) $typed );

		return '' === $typed ? $proposal : $typed;
	}

	/**
	 * Confirming refused addresses would only bring the same question back.
	 *
	 * @param array<int, string> $lines   The summary.
	 * @param array<int, string> $refused NetworkSummary::refusals() lines.
	 * @throws \RuntimeException When any address is refused.
	 */
	private function stopOnRefusals( array $lines, array $refused ): void {
		if ( [] === $refused ) {
			return;
		}
		( $this->show )( $lines );
		/* translators: %s: the refused site addresses with their reasons */
		throw new \RuntimeException( sprintf( __( 'The import stopped because site addresses are refused: %s', 'lw-migration' ), implode( ' ', $refused ) ) );
	}

	/**
	 * The command's closing line: what was restored where.
	 *
	 * @param Job $job Finished import job.
	 */
	public static function success( Job $job ): string {
		if ( ! ImportState::onNetwork( $job ) ) {
			return __( 'The archive was imported into this site.', 'lw-migration' );
		}
		switch ( ImportState::kind( $job ) ) {
			case ArchiveKind::NETWORK:
				return __( 'The network archive was restored into this network.', 'lw-migration' );
			case ArchiveKind::SITES:
				return __( 'The archive\'s sites were restored into this network.', 'lw-migration' );
		}

		return __( 'The archive was restored as a site of this network.', 'lw-migration' );
	}

	/**
	 * @param bool $wrong Whether the previous password was rejected.
	 * @throws \RuntimeException When there is no usable password.
	 */
	private function password( bool $wrong ): string {
		if ( null !== $this->givenPassword ) {
			if ( $wrong ) {
				throw new \RuntimeException( __( 'The password is wrong.', 'lw-migration' ) );
			}
			return $this->givenPassword;
		}
		if ( $wrong ) {
			if ( ++$this->wrong >= self::PASSWORD_TRIES ) {
				throw new \RuntimeException( __( 'Wrong password or damaged archive.', 'lw-migration' ) );
			}
			( $this->warn )( __( 'The password is wrong. Try again.', 'lw-migration' ) );
		}
		$typed = ( $this->askPassword )();
		if ( null === $typed || '' === $typed ) {
			throw new \RuntimeException( __( 'No password entered.', 'lw-migration' ) );
		}

		return (string) $typed;
	}
}
