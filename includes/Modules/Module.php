<?php
/**
 * Base of an optional feature module («افزونه» in the panel).
 *
 * A module is switched on / off in the panel and keeps its own settings under
 * `ctc_settings['modules'][ <id> ]`. Its hooks are added only while it is on, so a module that is off
 * costs nothing. Third parties add modules with the `ctc_modules` filter:
 *
 *     add_filter( 'ctc_modules', fn( $modules ) => [ ...$modules, new My_Module() ] );
 *
 * @package CardToCard
 */

namespace CardToCard\Modules;

use CardToCard\Settings;
use CardToCard\Support\Persian;

defined( 'ABSPATH' ) || exit;

/**
 * Feature module.
 */
abstract class Module {

	/**
	 * Unique id (a–z, 0–9, _).
	 *
	 * @return string
	 */
	abstract public function id(): string;

	/**
	 * Name in the panel.
	 *
	 * @return string
	 */
	abstract public function label(): string;

	/**
	 * One-line description in the panel.
	 *
	 * @return string
	 */
	abstract public function description(): string;

	/**
	 * Add the module's hooks (called on `init` only while it is on).
	 */
	abstract public function boot(): void;

	/**
	 * Icon name for the panel (fee, limit, filter, shield, puzzle).
	 *
	 * @return string
	 */
	public function icon(): string {
		return 'puzzle';
	}

	/**
	 * Default settings (without `on`); also the schema: types come from here.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return array();
	}

	/**
	 * Allowed values of string settings.
	 *
	 * @return array<string, string[]>
	 */
	protected function choices(): array {
		return array();
	}

	/**
	 * Extra data the panel needs (lists, usage numbers).
	 *
	 * @return array<string, mixed>
	 */
	public function data(): array {
		return array();
	}

	/**
	 * Sanitise this module's settings against defaults(). Override for nested values and call parent.
	 *
	 * @param array<string, mixed> $input Raw values.
	 * @return array<string, mixed>
	 */
	public function sanitize( array $input ): array {
		$out     = array();
		$choices = $this->choices();
		foreach ( $this->defaults() as $key => $default ) {
			$value = $input[ $key ] ?? $default;
			if ( isset( $choices[ $key ] ) ) {
				$out[ $key ] = in_array( (string) $value, $choices[ $key ], true ) ? (string) $value : $default;
			} elseif ( is_bool( $default ) ) {
				$out[ $key ] = filter_var( $value, FILTER_VALIDATE_BOOLEAN );
			} elseif ( is_int( $default ) ) {
				$out[ $key ] = max( 0, (int) Persian::latin( (string) $value ) );
			} elseif ( is_float( $default ) ) {
				$out[ $key ] = max( 0.0, (float) str_replace( array( '/', '٫' ), '.', Persian::latin( (string) $value ) ) );
			} elseif ( is_array( $default ) ) {
				$out[ $key ] = is_array( $value ) ? $value : $default;
			} else {
				$out[ $key ] = sanitize_text_field( (string) $value );
			}
		}
		return $out;
	}

	/**
	 * Saved settings of this module merged over the defaults (with `on`).
	 *
	 * @return array<string, mixed>
	 */
	public function config(): array {
		$all  = (array) Settings::get( 'modules', array() );
		$mine = isset( $all[ $this->id() ] ) && is_array( $all[ $this->id() ] ) ? $all[ $this->id() ] : array();
		return array_merge( array( 'on' => false ), $this->defaults(), $mine );
	}

	/**
	 * One setting.
	 *
	 * @param string $key      Key.
	 * @param mixed  $fallback Value when unknown.
	 * @return mixed
	 */
	public function conf( string $key, $fallback = null ) {
		$config = $this->config();
		return array_key_exists( $key, $config ) ? $config[ $key ] : $fallback;
	}

	/**
	 * Whether the module is on.
	 *
	 * @return bool
	 */
	public function enabled(): bool {
		return (bool) $this->conf( 'on', false );
	}
}
