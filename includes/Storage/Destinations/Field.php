<?php
/**
 * One field of a destination type's form.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations;

defined( 'ABSPATH' ) || exit;

/**
 * Data for the admin form and the CLI (spec 21.6: "típus-űrlap a
 * registry sémájából"). A SECRET field is stored in the vault and never sent
 * back; `when` shows a field only while another field has one of the listed
 * values (S3: the account id only for Cloudflare R2). Labels are translated
 * by the type; the browser never compares them. A SECRET_TEXT field is a
 * multi-line secret (an SSH private key); `when` also decides whether a
 * required field is required.
 */
final class Field {

	public const TEXT = 'text';

	public const SECRET = 'secret';

	public const SECRET_TEXT = 'secret_text';

	public const SELECT = 'select';

	public const TOGGLE = 'toggle';

	public const NUMBER = 'number';

	/** M7c: the connect panel of an OAuth type; no value, never stored, never sent (REST, CLI and the form skip it). */
	public const OAUTH = 'oauth';

	/**
	 * @param string                           $key      Settings key (camelCase; CLI flag: kebab-case).
	 * @param string                           $kind     One of the constants.
	 * @param string                           $label    Translated label.
	 * @param bool                             $required Must be filled while visible() (plan D33); a secret also on an update, unless the vault already holds it.
	 * @param string                           $help     Translated help text.
	 * @param array<string, string>            $options  SELECT: value => translated label.
	 * @param mixed                            $default  Default value (null = none).
	 * @param array<string, array<int, mixed>> $when     Other field => values that show this one.
	 */
	public function __construct(
		public string $key,
		public string $kind,
		public string $label,
		public bool $required = false,
		public string $help = '',
		public array $options = [],
		public $default = null,
		public array $when = []
	) {
	}

	public function isSecret(): bool {
		return self::SECRET === $this->kind || self::SECRET_TEXT === $this->kind;
	}

	/**
	 * Whether the field is shown — and, when required, required — with these
	 * settings: every `when` key holds one of its listed values (strictly, as
	 * the browser compares). The PHP twin of visible() in src/destinations/destinations.js.
	 *
	 * @param array<string, mixed> $settings Settings (with defaults filled in).
	 */
	public function visible( array $settings ): bool {
		foreach ( $this->when as $key => $allowed ) {
			if ( ! in_array( $settings[ $key ] ?? null, $allowed, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		$options = [];
		foreach ( $this->options as $value => $label ) {
			$options[] = [
				'value' => (string) $value,
				'label' => $label,
			];
		}

		return [
			'key'      => $this->key,
			'kind'     => $this->kind,
			'label'    => $this->label,
			'required' => $this->required,
			'help'     => $this->help,
			'options'  => $options,
			'default'  => $this->isSecret() ? null : $this->default, // A secret never has a value to show.
			'when'     => $this->when,
		];
	}
}
