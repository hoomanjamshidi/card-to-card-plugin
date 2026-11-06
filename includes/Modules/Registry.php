<?php
/**
 * The list of feature modules: built-in ones plus whatever `ctc_modules` adds. Boots the modules that
 * are on and sanitises their settings.
 *
 * @package CardToCard
 */

namespace CardToCard\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * Module registry.
 */
final class Registry {

	/**
	 * Modules by id.
	 *
	 * @var array<string, Module>|null
	 */
	private static ?array $modules = null;

	/**
	 * Booted module ids.
	 *
	 * @var array<string, bool>
	 */
	private static array $booted = array();

	/**
	 * All modules by id.
	 *
	 * @return array<string, Module>
	 */
	public static function all(): array {
		if ( null === self::$modules ) {
			/**
			 * Feature modules. Add an instance of a \CardToCard\Modules\Module subclass to register one.
			 *
			 * @param Module[] $modules Modules.
			 */
			$list          = (array) apply_filters( 'ctc_modules', array( new Fee(), new Card_Limits(), new Conditions(), new Fraud() ) );
			self::$modules = array();
			foreach ( $list as $module ) {
				if ( $module instanceof Module && preg_match( '/^[a-z0-9_]+$/', $module->id() ) ) {
					self::$modules[ $module->id() ] = $module;
				}
			}
		}
		return self::$modules;
	}

	/**
	 * One module.
	 *
	 * @param string $id Id.
	 * @return Module|null
	 */
	public static function get( string $id ): ?Module {
		return self::all()[ $id ] ?? null;
	}

	/**
	 * Whether a module exists and is on.
	 *
	 * @param string $id Id.
	 * @return bool
	 */
	public static function on( string $id ): bool {
		$module = self::get( $id );
		return null !== $module && $module->enabled();
	}

	/**
	 * Boot the modules that are on (init, priority 1).
	 */
	public static function boot(): void {
		foreach ( self::all() as $id => $module ) {
			if ( empty( self::$booted[ $id ] ) && $module->enabled() ) {
				self::$booted[ $id ] = true;
				$module->boot();
			}
		}
	}

	/**
	 * Default settings of every module (all off).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function defaults(): array {
		$out = array();
		foreach ( self::all() as $id => $module ) {
			$out[ $id ] = array_merge( array( 'on' => false ), $module->defaults() );
		}
		return $out;
	}

	/**
	 * Sanitise the `modules` setting; unknown modules are dropped.
	 *
	 * @param array<string, mixed> $input Raw values.
	 * @return array<string, array<string, mixed>>
	 */
	public static function sanitize( array $input ): array {
		$out = array();
		foreach ( self::all() as $id => $module ) {
			$raw        = isset( $input[ $id ] ) && is_array( $input[ $id ] ) ? $input[ $id ] : array();
			$out[ $id ] = array_merge(
				array( 'on' => filter_var( $raw['on'] ?? false, FILTER_VALIDATE_BOOLEAN ) ),
				$module->sanitize( $raw )
			);
		}
		return $out;
	}

	/**
	 * What the panel shows for each module.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function meta(): array {
		$out = array();
		foreach ( self::all() as $id => $module ) {
			$out[] = array(
				'id'    => $id,
				'label' => $module->label(),
				'desc'  => $module->description(),
				'icon'  => $module->icon(),
				'data'  => $module->data(),
			);
		}
		return $out;
	}

	/**
	 * Forget the list (tests).
	 */
	public static function reset(): void {
		self::$modules = null;
		self::$booted  = array();
	}
}
