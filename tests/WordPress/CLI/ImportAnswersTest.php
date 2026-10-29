<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\CLI;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\CLI\ImportAnswers;
use LightweightPlugins\Migration\Import\ImportSummary;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class ImportAnswersTest extends MonkeyTestCase {

	/**
	 * @param string               $kind Input kind.
	 * @param array<string, mixed> $data Input data.
	 */
	private static function waiting( string $kind, array $data = [] ): Job {
		$job        = new Job( '0123456789abcdef', 'import', [], 0, [], [], [], Job::NEEDS_INPUT );
		$job->input = [
			'kind' => $kind,
			'data' => $data,
		];

		return $job;
	}

	/**
	 * @param string|null   $given   --password value.
	 * @param bool          $yes     --yes.
	 * @param \Closure|null $ask     Password prompt.
	 * @param \Closure|null $confirm Confirmation.
	 * @param \Closure|null $show    Summary printer.
	 * @param \Closure|null $warn    Warning printer.
	 */
	private function answers( ?string $given, bool $yes = false, ?\Closure $ask = null, ?\Closure $confirm = null, ?\Closure $show = null, ?\Closure $warn = null ): ImportAnswers {
		$never = function (): void {
			$this->fail( 'Not expected to be called.' );
		};

		return new ImportAnswers( $given, $yes, $ask ?? $never, $confirm ?? $never, $show ?? $never, $warn ?? static function (): void {} );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function summary( bool $lowDisk ): array {
		return [
			'from'         => 'https://old.test',
			'to'           => 'https://new.test',
			'wordpress'    => [
				'archive' => '6.8',
				'site'    => '7.1',
			],
			'php'          => [
				'archive' => '8.1',
				'site'    => '8.3',
			],
			'overwrites'   => [ 'database' => true ],
			'safetyBackup' => true,
			'diskNeeded'   => 2000,
			'diskFree'     => 1000,
			'lowDiskSpace' => $lowDisk,
		];
	}

	public function test_a_password_given_on_the_command_line_is_used(): void {
		$answer = $this->answers( 'given' )->answer( self::waiting( 'password', [ 'reason' => 'encrypted' ] ) );

		$this->assertSame( [ 'password' => 'given' ], $answer );
	}

	public function test_a_wrong_password_given_on_the_command_line_stops_the_import(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'The password is wrong.' );

		$this->answers( 'given' )->answer( self::waiting( 'password', [ 'reason' => 'wrong' ] ) );
	}

	public function test_without_a_given_password_the_prompt_asks(): void {
		$answer = $this->answers( null, false, static fn(): ?string => 'typed' )->answer( self::waiting( 'password', [ 'reason' => 'encrypted' ] ) );

		$this->assertSame( [ 'password' => 'typed' ], $answer );
	}

	public function test_the_third_wrong_password_at_the_prompt_stops_the_import(): void {
		$asked   = 0;
		$answers = $this->answers(
			null,
			false,
			static function () use ( &$asked ): ?string {
				++$asked;
				return 'typed';
			}
		);
		$answers->answer( self::waiting( 'password', [ 'reason' => 'encrypted' ] ) );
		$answers->answer( self::waiting( 'password', [ 'reason' => 'wrong' ] ) );
		$answers->answer( self::waiting( 'password', [ 'reason' => 'wrong' ] ) );

		try {
			$answers->answer( self::waiting( 'password', [ 'reason' => 'wrong' ] ) );
			$this->fail( 'Expected the import to stop.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'Wrong password or damaged archive.', $e->getMessage() );
		}
		$this->assertSame( 3, $asked, 'Three passwords were typed, none after the third was rejected.' );
	}

	public function test_a_wrong_prompted_password_is_reported_before_asking_again(): void {
		$events  = [];
		$answers = $this->answers(
			null,
			false,
			static function () use ( &$events ): ?string {
				$events[] = 'ask';
				return 'typed';
			},
			null,
			null,
			static function ( string $warning ) use ( &$events ): void {
				$events[] = $warning;
			}
		);

		$answers->answer( self::waiting( 'password', [ 'reason' => 'encrypted' ] ) );
		$answers->answer( self::waiting( 'password', [ 'reason' => 'wrong' ] ) );

		$this->assertSame( [ 'ask', 'The password is wrong. Try again.', 'ask' ], $events );
	}

	public function test_no_password_entered_stops_the_import(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'No password entered.' );

		$this->answers( null, false, static fn(): ?string => null )->answer( self::waiting( 'password', [ 'reason' => 'encrypted' ] ) );
	}

	public function test_confirmation_shows_the_summary_and_follows_the_answer(): void {
		Functions\when( 'size_format' )->alias( static fn( $b ) => (string) $b );
		$shown = [];
		$yes   = static function ( array $lines ) use ( &$shown ): bool {
			$shown = $lines;
			return true;
		};

		$this->assertSame( [ 'confirm' => true ], $this->answers( null, false, null, $yes )->answer( self::waiting( 'confirm', self::summary( false ) ) ) );
		$this->assertStringContainsString( 'https://old.test -> https://new.test', implode( "\n", $shown ) );
		$this->assertNull( $this->answers( null, false, null, static fn( array $l ): bool => false )->answer( self::waiting( 'confirm', self::summary( false ) ) ) );
	}

	public function test_yes_confirms_and_still_shows_the_low_disk_space_warning(): void {
		Functions\when( 'size_format' )->alias( static fn( $b ) => (string) $b );
		$shown = [];
		$show  = static function ( array $lines ) use ( &$shown ): void {
			$shown = $lines;
		};

		$answer = $this->answers( null, true, null, null, $show )->answer( self::waiting( 'confirm', self::summary( true ) ) );

		$this->assertSame( [ 'confirm' => true ], $answer );
		$this->assertStringContainsString( 'may need about 2000 of disk space, but only 1000 is free', implode( "\n", $shown ) );
	}

	/**
	 * With --yes a refused address would only be asked about again: the import stops with the reasons (review I1).
	 */
	public function test_refused_site_addresses_stop_the_import_with_the_reasons(): void {
		$summary            = self::summary( false );
		$summary['network'] = [
			'kind'     => 'single',
			'sites'    => [
				[
					'index'    => 0,
					'from'     => [ 'blogId' => null, 'url' => 'https://blog.example' ],
					'proposal' => 'https://new.test/blog',
					'address'  => 'https://new.test/blog',
					'existing' => null,
					'error'    => 'WordPress reserves the name "blog". Choose another one.',
				],
			],
			'unlisted' => [],
		];
		$shown = [];
		$show  = static function ( array $lines ) use ( &$shown ): void {
			$shown = $lines;
		};

		try {
			$this->answers( null, true, null, null, $show )->answer( self::waiting( 'confirm', $summary ) );
			$this->fail( 'A refused address was confirmed.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Site https://blog.example -> https://new.test/blog is refused: WordPress reserves the name "blog". Choose another one.', $e->getMessage() );
		}
		$this->assertNotSame( [], $shown, 'The summary is shown first.' );
	}

	public function test_a_network_part_without_refusals_confirms(): void {
		$summary            = self::summary( false );
		$summary['network'] = [
			'kind'     => 'sites',
			'sites'    => [
				[
					'index'    => 0,
					'from'     => [ 'blogId' => 2, 'url' => 'http://old.test/b2' ],
					'proposal' => 'https://new.test/b2',
					'address'  => 'https://new.test/b2',
					'existing' => null,
					'error'    => null,
				],
			],
			'unlisted' => [],
		];

		$this->assertSame( [ 'confirm' => true ], $this->answers( null, true, null, null, static function (): void {} )->answer( self::waiting( 'confirm', $summary ) ) );
	}

	public function test_an_unknown_question_cancels(): void {
		$this->assertNull( $this->answers( 'given', true )->answer( self::waiting( 'unknown' ) ) );
	}

	/**
	 * The confirm data of a sites archive restore into a network.
	 *
	 * @param array<int, string|null> $errors Index => refusal of each site's current address (null: none).
	 * @param string                  $kind   ArchiveKind value.
	 * @return array<string, mixed>
	 */
	private static function networkSummary( array $errors, string $kind = 'sites' ): array {
		$summary            = self::summary( false );
		$summary['network'] = [
			'kind'     => $kind,
			'sites'    => [],
			'unlisted' => [],
		];
		foreach ( $errors as $i => $error ) {
			$summary['network']['sites'][] = [
				'index'    => $i,
				'from'     => [ 'blogId' => $i + 2, 'url' => 'http://old.test/b' . ( $i + 2 ) ],
				'proposal' => 'https://new.test/b' . ( $i + 2 ),
				'address'  => 'https://new.test/b' . ( $i + 2 ),
				'existing' => null,
				'error'    => $error,
			];
		}

		return $summary;
	}

	/**
	 * @param array<int, string> $urls       --site-url values.
	 * @param bool               $yes        --yes.
	 * @param \Closure|null      $askAddress Address prompt.
	 * @param \Closure|null      $confirm    Confirmation.
	 */
	private function networkAnswers( array $urls, bool $yes, ?\Closure $askAddress = null, ?\Closure $confirm = null ): ImportAnswers {
		$never = function (): void {
			$this->fail( 'Not expected to be called.' );
		};

		return new ImportAnswers( null, $yes, $never, $confirm ?? $never, static function (): void {}, static function (): void {}, $urls, $askAddress ?? $never );
	}

	public function test_given_site_urls_are_sent_by_index(): void {
		$answer = $this->networkAnswers( [ 'https://new.test/shop', 'https://new.test/blog2' ], true )->answer( self::waiting( 'confirm', self::networkSummary( [ null, null ] ) ) );

		$this->assertSame(
			[
				'confirm' => true,
				'sites'   => [
					0 => 'https://new.test/shop',
					1 => 'https://new.test/blog2',
				],
			],
			$answer
		);
	}

	public function test_the_summary_names_a_given_address_instead_of_the_proposal(): void {
		$shown   = [];
		$show    = static function ( array $lines ) use ( &$shown ): void {
			$shown = $lines;
		};
		$answers = new ImportAnswers( null, true, static fn() => null, static fn() => false, $show, static function (): void {}, [ 'https://new.test/shop' ] );

		$answers->answer( self::waiting( 'confirm', self::networkSummary( [ null, null ] ) ) );

		$text = implode( "\n", $shown );
		$this->assertStringContainsString( 'Site http://old.test/b2 -> https://new.test/shop', $text );
		$this->assertStringNotContainsString( 'https://new.test/b2', $text );
		$this->assertStringContainsString( 'Site http://old.test/b3 -> https://new.test/b3 (new site)', $text );
	}

	public function test_a_missing_site_url_with_yes_keeps_the_proposal(): void {
		$answer = $this->networkAnswers( [ 'https://new.test/shop' ], true )->answer( self::waiting( 'confirm', self::networkSummary( [ null, null ] ) ) );

		$this->assertSame(
			[
				'confirm' => true,
				'sites'   => [ 0 => 'https://new.test/shop' ],
			],
			$answer
		);
	}

	public function test_a_site_url_is_given_once_and_a_refusal_after_it_stops_the_import(): void {
		$answers = $this->networkAnswers( [ 'https://new.test/' ], true );
		$answers->answer( self::waiting( 'confirm', self::networkSummary( [ 'The proposal is the main site.' ] ) ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'The import stopped because site addresses are refused: Site http://old.test/b2 -> https://new.test/b2 is refused: The main site cannot be replaced.' );

		$answers->answer( self::waiting( 'confirm', self::networkSummary( [ 'The main site cannot be replaced.' ] ) ) );
	}

	public function test_interactive_asks_each_address_with_the_proposal_as_the_default(): void {
		$asked   = [];
		$typed   = [ '', 'https://new.test/mine' ];
		$ask     = static function ( string $from, string $proposal ) use ( &$asked, &$typed ): ?string {
			$asked[] = $from . ' ' . $proposal;
			return array_shift( $typed );
		};
		$shown   = [];
		$confirm = static function ( array $lines ) use ( &$shown ): bool {
			$shown = $lines;
			return true;
		};

		$answer = $this->networkAnswers( [], false, $ask, $confirm )->answer( self::waiting( 'confirm', self::networkSummary( [ null, null ] ) ) );

		$this->assertSame( [ 'http://old.test/b2 https://new.test/b2', 'http://old.test/b3 https://new.test/b3' ], $asked );
		$this->assertSame(
			[
				'confirm' => true,
				'sites'   => [
					0 => 'https://new.test/b2',
					1 => 'https://new.test/mine',
				],
			],
			$answer
		);
		$this->assertSame( [ 'Site http://old.test/b2 -> https://new.test/b2', 'Site http://old.test/b3 -> https://new.test/mine' ], $shown, 'The chosen addresses are confirmed.' );
	}

	public function test_interactive_uses_given_site_urls_and_asks_again_after_a_refusal(): void {
		$asked   = [];
		$ask     = static function ( string $from, string $proposal ) use ( &$asked ): ?string {
			$asked[] = $from;
			return 'https://new.test/other';
		};
		$answers = $this->networkAnswers( [ 'https://new.test/' ], false, $ask, static fn( array $lines ): bool => true );

		$first  = $answers->answer( self::waiting( 'confirm', self::networkSummary( [ null ] ) ) );
		$second = $answers->answer( self::waiting( 'confirm', self::networkSummary( [ 'The main site cannot be replaced.' ] ) ) );

		$this->assertSame( [ 0 => 'https://new.test/' ], $first['sites'], 'The given address is not asked about.' );
		$this->assertSame( [ 0 => 'https://new.test/other' ], $second['sites'] );
		$this->assertSame( [ 'http://old.test/b2' ], $asked, 'Asked once, after the refusal.' );
	}

	public function test_asked_again_an_accepted_address_is_the_default_and_a_refused_one_falls_back_to_the_proposal(): void {
		$defaults = [];
		$ask      = static function ( string $from, string $default ) use ( &$defaults ): ?string {
			$defaults[] = $default;
			return '';
		};
		$summary  = self::networkSummary( [ null, 'Refused.' ] );
		$summary['network']['sites'][0]['address'] = 'https://new.test/kept';
		$summary['network']['sites'][1]['address'] = 'https://new.test/bad';

		$answer = $this->networkAnswers( [], false, $ask, static fn( array $lines ): bool => true )->answer( self::waiting( 'confirm', $summary ) );

		$this->assertSame( [ 'https://new.test/kept', 'https://new.test/b3' ], $defaults );
		$this->assertSame( [ 0 => 'https://new.test/kept', 1 => 'https://new.test/b3' ], $answer['sites'] );
	}

	public function test_interactive_no_answer_cancels_and_no_address_stops(): void {
		$this->assertNull( $this->networkAnswers( [], false, static fn(): ?string => '', static fn( array $lines ): bool => false )->answer( self::waiting( 'confirm', self::networkSummary( [ null ] ) ) ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'No address entered.' );
		$this->networkAnswers( [], false, static fn(): ?string => null )->answer( self::waiting( 'confirm', self::networkSummary( [ null ] ) ) );
	}

	public function test_more_site_urls_than_archive_sites_are_refused(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'The archive has 1 site but 2 addresses were given.' );

		$this->networkAnswers( [ 'https://new.test/a', 'https://new.test/b' ], true )->answer( self::waiting( 'confirm', self::networkSummary( [ null ] ) ) );
	}

	public function test_site_urls_for_a_network_archive_are_refused(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'This archive holds a whole network: its sites keep their addresses. Leave out --site-url.' );

		$this->networkAnswers( [ 'https://new.test/a' ], true )->answer( self::waiting( 'confirm', self::networkSummary( [ null ], 'network' ) ) );
	}

	public function test_a_network_archive_is_confirmed_without_address_questions(): void {
		$answer = $this->networkAnswers( [], false, null, static fn( array $lines ): bool => true )->answer( self::waiting( 'confirm', self::networkSummary( [ null, null ], 'network' ) ) );

		$this->assertSame( [ 'confirm' => true ], $answer );
	}

	public function test_without_yes_and_without_an_address_prompt_the_confirmation_is_still_asked(): void {
		$asked   = 0;
		$confirm = static function ( array $lines ) use ( &$asked ): bool {
			++$asked;
			return false;
		};
		$answers = new ImportAnswers( null, false, static fn() => null, $confirm, static function (): void {}, static function (): void {}, [ 'https://new.test/shop' ] );

		$this->assertNull( $answers->answer( self::waiting( 'confirm', self::networkSummary( [ null ] ) ) ), 'A "no" cancels.' );
		$this->assertSame( 1, $asked );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function successes(): array {
		return [
			'single site' => [ '', 'The archive was imported into this site.' ],
			'network'     => [ 'network', 'The network archive was restored into this network.' ],
			'sites'       => [ 'sites', 'The archive\'s sites were restored into this network.' ],
			'single'      => [ 'single', 'The archive was restored as a site of this network.' ],
		];
	}

	/**
	 * @dataProvider successes
	 */
	public function test_the_success_line_names_what_was_restored( string $kind, string $expected ): void {
		$job = new Job( '0123456789abcdef', 'import', [] );
		if ( '' !== $kind ) {
			$job->state = [
				'target' => [ 'domain' => 'new.test' ],
				'kind'   => $kind,
			];
		}

		$this->assertSame( $expected, ImportAnswers::success( $job ) );
	}

	public function test_the_address_line_is_shown_without_control_characters(): void {
		Functions\when( 'size_format' )->alias( static fn( $b ) => (string) $b );
		$summary         = self::summary( false );
		$summary['from'] = "https://old.test\x1b[2J\u{85}";

		$this->assertSame( 'Address: https://old.test[2J -> https://new.test', ImportSummary::lines( $summary )[0] );
	}
}
