<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Http;

use LightweightPlugins\Migration\Http\AddressRedactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AddressRedactorTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function addresses(): array {
		$onedrive = 'https://api.onedrive.com/v1.0/shares/u!' . rtrim( strtr( base64_encode( 'https://1drv.ms/u/s!SECRETSHARE?e=abc' ), '+/', '-_' ), '=' ) . '/root/content';

		return [
			'onedrive'          => [ $onedrive, 'https://api.onedrive.com/…/content' ],
			'dropbox'           => [ 'https://www.dropbox.com/s/SECRETKEY123/site.wpress?dl=1', 'https://www.dropbox.com/…/site.wpress' ],
			'drive'             => [ 'https://drive.google.com/file/d/1AbC_SECRET/view?usp=sharing', 'https://drive.google.com/…/view' ],
			'credentials'       => [ 'https://admin:s3cret@files.test/site.wpress?token=TOPSECRET#frag', 'https://files.test/site.wpress' ],
			'port, no path'     => [ 'HTTP://files.test:8080', 'http://files.test:8080' ],
			'trailing slash'    => [ 'https://files.test/KEY/dir/', 'https://files.test/…/' ],
			'not an address'    => [ '/relative/path', '(address)' ],
			'token last'        => [ 'https://files.test/download/SECRETTOKEN123456', 'https://files.test/…' ],
			'share token last'  => [ 'https://files.test/s/aB3_dE6-gH9iJ2kL5mN8', 'https://files.test/…' ],
			'token at the root' => [ 'https://files.test/aB3_dE6-gH9iJ2kL5mN8?x=1', 'https://files.test/…' ],
			'short last'        => [ 'https://files.test/s/abc123', 'https://files.test/…/abc123' ],
			'long file name'    => [ 'https://files.test/x/site-backup-2026-10-07.wpress', 'https://files.test/…/site-backup-2026-10-07.wpress' ],
		];
	}

	#[DataProvider( 'addresses' )]
	public function test_display_keeps_only_the_host_and_the_last_path_segment( string $url, string $expected ): void {
		$this->assertSame( $expected, AddressRedactor::display( $url ) );
	}

	public function test_redact_reduces_the_request_address_and_any_other_address(): void {
		$url     = 'https://admin:s3cret@files.test/site.wpress?token=TOPSECRET';
		$message = AddressRedactor::redact( 'cURL error 28: timed out for ' . $url . ' (redirected from https://files.test/s/KEY/x.wpress?sig=SIGNATURE)', $url );

		$this->assertSame( 'cURL error 28: timed out for https://files.test/site.wpress (redirected from https://files.test/…/x.wpress', $message );
	}

	public function test_redact_handles_an_address_with_spaces(): void {
		$url     = 'https://example.com/a b/SHAREKEY123/site.wpress?token=TOPSECRET&x="q"';
		$message = AddressRedactor::redact( 'User has blocked requests through HTTP to the URL: ' . $url . '.', $url );

		$this->assertSame( 'User has blocked requests through HTTP to the URL: https://example.com/…/site.wpress.', $message );
	}

	public function test_redact_handles_the_decoded_form_of_the_address(): void {
		$url     = 'https://files.test/my%20dir/SHAREKEY123/site.wpress?t=TOPSECRET';
		$message = AddressRedactor::redact( 'Failed: https://files.test/my dir/SHAREKEY123/site.wpress?t=TOPSECRET', $url );

		$this->assertSame( 'Failed: https://files.test/…/site.wpress', $message );
	}

	public function test_redact_removes_pieces_of_the_address_wherever_they_appear(): void {
		$url     = 'https://admin:s3cretpw@files.test/s/SHAREKEY123/site.wpress?token=TOPSECRET&dl=1#FRAGMENTX';
		$message = AddressRedactor::redact( 'Login admin with s3cretpw refused; key SHAREKEY123; query token=TOPSECRET&dl=1; value TOPSECRET; anchor FRAGMENTX', $url );

		foreach ( [ 'admin', 's3cretpw', 'SHAREKEY123', 'TOPSECRET', 'FRAGMENTX' ] as $secret ) {
			$this->assertStringNotContainsString( $secret, $message );
		}
		$this->assertStringContainsString( '***', $message );
	}

	public function test_redact_leaves_short_path_segments_alone(): void {
		$url = 'https://www.dropbox.com/s/SECRETKEY123/site.wpress';

		$this->assertSame( 'Operation timed out; key ***', AddressRedactor::redact( 'Operation timed out; key SECRETKEY123', $url ) );
	}

	public function test_reduce_shows_every_address_in_a_text_in_display_form(): void {
		$this->assertSame(
			'Got https://files.test/…/site.wpress and http://cdn.test:8080/…/b.wpress then gave up.',
			AddressRedactor::reduce( 'Got https://u:p4ss@files.test/share/abc/site.wpress?token=tok3n and http://cdn.test:8080/x/y/b.wpress#frag then gave up.' )
		);
		$this->assertSame( 'No address here.', AddressRedactor::reduce( 'No address here.' ) );
	}

	public function test_reduce_keeps_trailing_punctuation_outside_the_address(): void {
		$cases = [
			'See https://files.test/a/b.wpress?t=SECRET, then retry.' => 'See https://files.test/…/b.wpress, then retry.',
			'Failed: https://files.test/a/b.wpress?t=SECRET.'         => 'Failed: https://files.test/…/b.wpress.',
			'Got https://files.test/a/b.wpress?t=SECRET; stop'        => 'Got https://files.test/…/b.wpress; stop',
			'Is it https://files.test/a/b.wpress?t=SECRET?!'          => 'Is it https://files.test/…/b.wpress?!',
			'From https://files.test/a/b.wpress?t=SECRET: no'         => 'From https://files.test/…/b.wpress: no',
			'„https://files.test/a/b.wpress?t=SECRET”'                => '„https://files.test/…/b.wpress”',
			'«https://files.test/a/b.wpress?t=SECRET»'                => '«https://files.test/…/b.wpress»',
		];
		foreach ( $cases as $message => $expected ) {
			$this->assertSame( $expected, AddressRedactor::reduce( $message ), $message );
		}
	}

	public function test_a_token_like_last_segment_is_masked_wherever_it_appears(): void {
		$url = 'https://files.test/download/SECRETTOKEN123456';

		$this->assertSame(
			'Download https://files.test/… failed (file *** gone)',
			AddressRedactor::redact( 'Download ' . $url . ' failed (file SECRETTOKEN123456 gone)', $url )
		);
	}
}
