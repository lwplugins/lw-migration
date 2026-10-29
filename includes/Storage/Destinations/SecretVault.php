<?php
/**
 * The sealed secrets of the cloud destinations.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations;

use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Support\SecretBox;
use LightweightPlugins\Migration\WordPress\JsonOptionRow;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.3: one option row, `lw_migration_destination_secrets` (autoload
 * off, JSON), profile id => field => sealed value, read from the row itself
 * and written by compare-and-swap (JsonOptionRow), so a request with an older
 * options cache never writes back a secret that was replaced or forgotten. Each value is sealed with SecretBox
 * (XSalsa20-Poly1305) under a key derived from wp_salt( 'auth' ) for this use
 * only, over the profile id, the field and the value (SecretPayload), so a
 * sealed value copied to another profile or field does not open. A value
 * put() with a binding (SecretBinding: the server it was saved for) opens
 * only for that binding; values stored before bindings existed have none
 * and keep opening. Like the schedule password it
 * protects a leaked database (salts in wp-config.php), not a compromised
 * server. A value that does not open (salts changed, the row moved to another
 * site) makes open() answer null: the caller marks the profile `reauth`.
 */
final class SecretVault {

	/** Binds the derived key to this one use. */
	private const KEY_CONTEXT = 'lw-migration/destination-secrets/v1';

	/** A field name is a plain identifier, so the sealed layout (SecretPayload) can be read only one way. */
	private const FIELD_PATTERN = '/^[A-Za-z0-9_]+\z/';

	/**
	 * Built on first use.
	 *
	 * @var SecretBox|null
	 */
	private ?SecretBox $box;

	/**
	 * Whether the sodium functions exist.
	 *
	 * @var bool
	 */
	private bool $sodium;

	/**
	 * The option row.
	 *
	 * @var JsonOptionRow
	 */
	private JsonOptionRow $row;

	/**
	 * @param SecretBox|null $box    Sealing key (default: derived from wp_salt( 'auth' )).
	 * @param bool|null      $sodium Whether sealing is possible (default: SecretBox::available()).
	 * @param \wpdb|null     $db     Database object; defaults to the global $wpdb.
	 */
	public function __construct( ?SecretBox $box = null, ?bool $sodium = null, ?object $db = null ) {
		$this->box    = $box;
		$this->sodium = $sodium ?? SecretBox::available();
		$this->row    = new JsonOptionRow( Options::DESTINATION_SECRETS, $db );
	}

	/**
	 * Seals and stores the given fields; an empty value keeps the stored one.
	 *
	 * @param string               $id      Profile id.
	 * @param array<string, mixed> $values  Field => plain value (anything but a non-empty string is ignored).
	 * @param string|null          $binding The server they are for (SecretBinding::of(); null: none).
	 * @throws \LogicException On a field name outside [A-Za-z0-9_] (a programming error; nothing is stored).
	 * @throws \RuntimeException When this server cannot seal, or the row cannot be written (translated).
	 */
	public function put( string $id, #[\SensitiveParameter] array $values, ?string $binding = null ): void {
		$this->write( $id, $values, static fn(): bool => true, $binding );
	}

	/**
	 * Like put(), but only into a profile that still has secrets stored (M7c
	 * preflight P-C1): a token answer that arrives after the destination was
	 * deleted never brings its secrets back.
	 *
	 * @param string               $id     Profile id.
	 * @param array<string, mixed> $values Field => plain value (anything but a non-empty string is ignored).
	 * @return bool False when the profile has no secrets (nothing was written); true when written or nothing to write.
	 * @throws \LogicException On a field name outside [A-Za-z0-9_].
	 * @throws \RuntimeException When this server cannot seal, or the row cannot be written (translated).
	 */
	public function putExisting( string $id, #[\SensitiveParameter] array $values ): bool {
		return $this->write( $id, $values, static fn( ?array $row ): bool => null !== $row );
	}

	/**
	 * Like putExisting(), but only while the stored $field of the profile
	 * opens to $expected; checked on the row as it is when written (M7c
	 * review rounds 1–2: an OAuth token write lands only in the connection it
	 * belongs to — the field is its grant). An empty expectation, an absent
	 * field and a field that does not open match nothing.
	 *
	 * @param string               $id       Profile id.
	 * @param string               $field    The field compared.
	 * @param string               $expected Its expected plain value ('' never matches).
	 * @param array<string, mixed> $values   Field => plain value (anything but a non-empty string is ignored).
	 * @return bool False when nothing was written (no secrets, or the field differs or does not open).
	 * @throws \LogicException On a field name outside [A-Za-z0-9_].
	 * @throws \RuntimeException When this server cannot seal, or the row cannot be written (translated).
	 */
	public function putIfMatches( string $id, string $field, #[\SensitiveParameter] string $expected, #[\SensitiveParameter] array $values ): bool {
		return $this->write(
			$id,
			$values,
			function ( ?array $row ) use ( $id, $field, $expected ): bool {
				$stored = '' === $expected || ! isset( $row[ $field ] ) ? null : $this->plain( $id, $field, $row[ $field ] );

				return null !== $stored && hash_equals( $stored, $expected );
			}
		);
	}

	/**
	 * Whether this server can seal and open (the sodium functions exist).
	 */
	public function available(): bool {
		return $this->sodium;
	}

	/**
	 * @param string $id Profile id.
	 * @return array<int, string> Fields with a stored value.
	 */
	public function fields( string $id ): array {
		return array_map( 'strval', array_keys( self::stored( $this->row->get() )[ $id ] ?? [] ) );
	}

	/**
	 * A caller that sends the secrets to the server its settings name passes
	 * their binding (Destinations::open()); null skips that check (the OAuth
	 * types: their provider is fixed).
	 *
	 * @param string      $id      Profile id.
	 * @param string|null $binding The settings' SecretBinding (null: not checked).
	 * @return array<string, string>|null Field => plain value; null when a stored value does not open, or was stored for another binding.
	 */
	public function open( string $id, ?string $binding = null ): ?array {
		$row = self::stored( $this->row->get() )[ $id ] ?? [];
		if ( [] === $row ) {
			return [];
		}
		if ( ! $this->sodium ) {
			return null;
		}
		$plain = [];
		foreach ( $row as $field => $sealed ) {
			$opened = $this->plain( $id, (string) $field, $sealed, $binding );
			if ( null === $opened ) {
				return null;
			}
			$plain[ (string) $field ] = $opened;
		}

		return $plain;
	}

	/**
	 * Whether the profile's secrets open, but not for this binding: they were
	 * stored for another server (an edit moved the profile), which differs
	 * from secrets that no longer open at all (salts changed: `reauth`).
	 *
	 * @param string $id      Profile id.
	 * @param string $binding The binding the caller holds.
	 */
	public function boundElsewhere( string $id, string $binding ): bool {
		return [] !== ( $this->open( $id ) ?? [] ) && null === $this->open( $id, $binding );
	}

	/**
	 * @param string $id Profile id.
	 * @throws \RuntimeException When the row cannot be written (the secrets stay; translated).
	 */
	public function forget( string $id ): void {
		$stored = $this->row->change(
			static function ( $current ) use ( $id ): ?array {
				$all = self::stored( $current );
				if ( ! isset( $all[ $id ] ) ) {
					return null;
				}
				unset( $all[ $id ] );

				return $all;
			}
		);
		if ( ! $stored ) {
			throw new \RuntimeException( __( 'The credentials of this destination could not be removed. Try again.', 'lw-migration' ) );
		}
	}

	/**
	 * Removes some fields of one profile (M7c: the tokens when the Client ID
	 * changes); the others and every other profile stay. A profile left
	 * without fields is removed.
	 *
	 * @param string             $id     Profile id.
	 * @param array<int, string> $fields Field names.
	 * @return bool False only when the row could not be written.
	 */
	public function drop( string $id, array $fields ): bool {
		return [] === $fields || $this->row->change(
			static function ( $current ) use ( $id, $fields ): ?array {
				$all  = self::stored( $current );
				$kept = array_diff_key( $all[ $id ] ?? [], array_flip( $fields ) );
				if ( ! isset( $all[ $id ] ) || count( $kept ) === count( $all[ $id ] ) ) {
					return null;
				}
				if ( [] === $kept ) {
					unset( $all[ $id ] );
				} else {
					$all[ $id ] = $kept;
				}

				return $all;
			}
		);
	}

	/**
	 * @param string               $id      Profile id.
	 * @param array<string, mixed> $values  Field => plain value.
	 * @param \Closure             $may     fn( ?array $row ): bool — the profile's sealed fields as stored now (null: none); false writes nothing.
	 * @param string|null          $binding SecretBinding sealed with each value (null: none).
	 * @return bool Whether it was written (or there was nothing to write).
	 * @throws \LogicException On a malformed field name.
	 * @throws \RuntimeException When this server cannot seal, or the row cannot be written.
	 */
	private function write( string $id, #[\SensitiveParameter] array $values, \Closure $may, ?string $binding = null ): bool {
		foreach ( array_keys( $values ) as $field ) {
			if ( 1 !== preg_match( self::FIELD_PATTERN, (string) $field ) ) {
				throw new \LogicException( 'A destination secret field name has letters, digits and underscores only.' );
			}
		}
		$values = array_filter( $values, static fn( $value ): bool => is_string( $value ) && '' !== $value );
		if ( [] === $values ) {
			return true;
		}
		$sealed = [];
		foreach ( $values as $field => $plain ) {
			$sealed[ (string) $field ] = $this->box()->seal( SecretPayload::wrap( $id, (string) $field, $binding, $plain ) );
		}
		$found  = true;
		$stored = $this->row->change(
			static function ( $current ) use ( $id, $sealed, $may, &$found ): ?array {
				$all   = self::stored( $current );
				$found = (bool) $may( $all[ $id ] ?? null );
				if ( ! $found ) {
					return null;
				}
				$all[ $id ] = array_merge( $all[ $id ] ?? [], $sealed );

				return $all;
			}
		);
		if ( ! $stored ) {
			throw new \RuntimeException( __( 'The credentials of this destination could not be saved. Try again.', 'lw-migration' ) );
		}

		return $found;
	}

	/**
	 * @param mixed $stored Decoded row.
	 * @return array<string, array<string, mixed>>
	 */
	private static function stored( $stored ): array {
		$clean = [];
		foreach ( is_array( $stored ) ? $stored : [] as $id => $row ) {
			if ( is_array( $row ) && 1 === preg_match( Profile::ID_PATTERN, (string) $id ) ) {
				$clean[ (string) $id ] = $row;
			}
		}

		return $clean;
	}

	/**
	 * @param string      $id      Profile id.
	 * @param string      $field   Field.
	 * @param mixed       $sealed  Stored value.
	 * @param string|null $binding SecretBinding the caller holds (null: not checked).
	 * @return string|null The plain value; null when it does not open for this profile, field and binding.
	 */
	private function plain( string $id, string $field, $sealed, ?string $binding = null ): ?string {
		if ( ! $this->sodium ) {
			return null;
		}
		$opened = is_string( $sealed ) ? $this->box()->open( $sealed ) : null;

		return null === $opened ? null : SecretPayload::unwrap( $id, $field, $binding, $opened );
	}

	/**
	 * @throws \RuntimeException When the sodium functions are missing.
	 */
	private function box(): SecretBox {
		if ( null === $this->box ) {
			if ( ! $this->sodium ) {
				throw new \RuntimeException( __( 'This server cannot protect a stored secret (the sodium functions are missing), so it cannot keep a cloud destination.', 'lw-migration' ) );
			}
			$this->box = new SecretBox( hash_hmac( 'sha256', self::KEY_CONTEXT, wp_salt( 'auth' ), true ) );
		}

		return $this->box;
	}
}
