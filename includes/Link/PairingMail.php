<?php
/**
 * The mail that tells this site's admins about a new connection.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Link;

use LightweightPlugins\Migration\Rest\Permission;

defined( 'ABSPATH' ) || exit;

/**
 * Tells this site's admins that another site was connected (ruling I7: with
 * push off by default there is no approval step, so every new connection is
 * announced). Plain text, no secret, code or path; one mail per address of a
 * user who may use the plugin. Best effort: a failure is never thrown.
 */
final class PairingMail {

	/**
	 * @param array<string, mixed> $link The confirmed incoming link (Links::find() shape).
	 * @param \Closure|null        $mail fn( string $to, string $subject, string $body ): bool (default wp_mail).
	 * @return int Mails handed to the mailer.
	 */
	public static function send( array $link, ?\Closure $mail = null ): int {
		$mail ??= static fn( string $to, string $subject, string $body ): bool => wp_mail( $to, $subject, $body );
		try {
			$to = self::recipients();
			if ( [] === $to ) {
				return 0;
			}
			/* translators: %s: this site's name */
			$subject = sprintf( __( '[%s] A site was connected to this site', 'lw-migration' ), self::plain( (string) get_bloginfo( 'name' ) ) );
			$body    = self::body( $link );
		} catch ( \Throwable $e ) {
			return 0;
		}
		$sent = 0;
		foreach ( $to as $address ) {
			try {
				$sent += $mail( $address, $subject, $body ) ? 1 : 0;
			} catch ( \Throwable $e ) {
				unset( $e ); // Counted as not sent.
			}
		}

		return $sent;
	}

	/**
	 * Sends the mails once the answer is out (final review m2): last on
	 * `shutdown`, after the response was handed back to the web server where
	 * it can be (FastCGI, LiteSpeed), so a slow mailer never makes the other
	 * site's hello time out.
	 *
	 * @param array<string, mixed> $link The confirmed incoming link.
	 * @param \Closure|null        $mail Mailer for send().
	 */
	public static function later( array $link, ?\Closure $mail = null ): void {
		add_action(
			'shutdown',
			static function () use ( $link, $mail ): void {
				if ( function_exists( 'fastcgi_finish_request' ) ) {
					fastcgi_finish_request();
				} elseif ( function_exists( 'litespeed_finish_request' ) ) {
					litespeed_finish_request();
				}
				self::send( $link, $mail );
			},
			PHP_INT_MAX
		);
	}

	/**
	 * @return array<int, string> Valid addresses of the users who may use the plugin, once each.
	 */
	private static function recipients(): array {
		$users = get_users(
			[
				'capability' => Permission::capability(),
				'fields'     => [ 'user_email' ],
			]
		);
		$to    = [];
		foreach ( $users as $user ) {
			$email = is_object( $user ) && isset( $user->user_email ) ? (string) $user->user_email : '';
			if ( '' !== $email && false !== is_email( $email ) ) {
				$to[ $email ] = $email;
			}
		}

		return array_values( $to );
	}

	/**
	 * @param array<string, mixed> $link The link.
	 */
	private static function body( array $link ): string {
		$allow = is_array( $link['allow'] ?? null ) ? $link['allow'] : [];
		$lines = [
			sprintf(
				/* translators: 1: the other site's name, 2: its address, 3: this site's name, 4: this site's address, 5: date and time */
				__( '%1$s (%2$s) was connected to %3$s (%4$s) on %5$s.', 'lw-migration' ),
				self::plain( (string) ( $link['name'] ?? '' ) ),
				self::plain( (string) ( $link['address'] ?? '' ) ),
				self::plain( (string) get_bloginfo( 'name' ) ),
				self::plain( (string) home_url() ),
				wp_date( 'Y-m-d H:i' )
			),
			/* translators: %s: an IP address */
			sprintf( __( 'It connected from: %s', 'lw-migration' ), self::plain( (string) ( $link['pairedFrom'] ?? '' ) ) ),
		];
		if ( in_array( Links::PULL, $allow, true ) ) {
			$lines[] = __( 'It may copy this site (database, files, users).', 'lw-migration' );
		}
		if ( in_array( Links::PUSH, $allow, true ) ) {
			$lines[] = __( 'It may replace this site\'s content with its own.', 'lw-migration' );
		}
		$lines[] = sprintf(
			/* translators: %s: the WP-CLI command that removes the connection */
			__( 'If you did not do this, remove the connection now: LW Plugins > Migration > Connections > Remove, or %s.', 'lw-migration' ),
			'wp lw-migration link remove ' . self::plain( (string) ( $link['id'] ?? '' ) )
		);

		return implode( "\n\n", $lines ) . "\n";
	}

	/**
	 * @param string $text Text from a site or a request.
	 */
	private static function plain( string $text ): string {
		return wp_strip_all_tags( wp_specialchars_decode( $text, ENT_QUOTES ) );
	}
}
