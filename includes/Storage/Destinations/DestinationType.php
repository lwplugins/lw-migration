<?php
/**
 * One kind of cloud destination.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations;

defined( 'ABSPATH' ) || exit;

/**
 * What the registry needs of a type (spec 21.2 "típuskulcs → osztály,
 * űrlap-séma, elérhetőség"): S3 now, WebDAV/FTP/SFTP (M7b) and the OAuth
 * types (M7c) later.
 */
interface DestinationType {

	/** Lower-case key stored in the profile (`s3`). */
	public function key(): string;

	/** Translated name for lists. */
	public function label(): string;

	/** Why this server cannot use the type (translated), or null when it can. */
	public function unavailable(): ?string;

	/**
	 * @return array<int, Field>
	 */
	public function fields(): array;

	/**
	 * The non-secret settings to store, checked and normalised.
	 *
	 * @param array<string, mixed> $raw Submitted settings (secret fields are not in it).
	 * @return array<string, mixed>
	 * @throws FieldException On a value the type refuses.
	 */
	public function settings( array $raw ): array;

	/**
	 * @param Profile               $profile The profile.
	 * @param array<string, string> $secrets Its opened secrets.
	 * @throws PermanentFailure When a required secret is missing (REAUTH) or the settings no longer hold (INVALID).
	 */
	public function make( Profile $profile, #[\SensitiveParameter] array $secrets ): DestinationInterface;

	/**
	 * Short "where" for lists and logs (bucket and folder at a host), never a secret.
	 *
	 * @param Profile $profile The profile.
	 */
	public function describe( Profile $profile ): string;

	/**
	 * Whether the profile talks plain http (the views flag it).
	 *
	 * @param Profile $profile The profile.
	 */
	public function insecure( Profile $profile ): bool;
}
