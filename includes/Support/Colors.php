<?php
/**
 * Colour helpers: readable text on a colour, and the active theme's colours.
 *
 * Theme colours come from theme.json (block themes and classic themes that ship one: the
 * --wp--preset--color--* variables) and from the customizer settings of popular classic themes
 * (Storefront, Astra, Flatsome, Kadence, GeneratePress, Blocksy, Woodmart, OceanWP, …).
 *
 * @package CardToCard
 */

namespace CardToCard\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Colours.
 */
final class Colors {

	/**
	 * «#abc» / «#aabbcc» / «rgb(…)» → «#aabbcc» ('' when not a colour).
	 *
	 * @param string $color Colour.
	 * @return string
	 */
	public static function hex( string $color ): string {
		$color = strtolower( trim( $color ) );
		if ( preg_match( '/^#([0-9a-f]{3})$/', $color, $m ) ) {
			return '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
		}
		if ( preg_match( '/^#([0-9a-f]{6})([0-9a-f]{2})?$/', $color, $m ) ) {
			return '#' . $m[1];
		}
		if ( preg_match( '/^rgba?\(\s*(\d{1,3})[\s,]+(\d{1,3})[\s,]+(\d{1,3})/', $color, $m ) ) {
			return sprintf( '#%02x%02x%02x', min( 255, (int) $m[1] ), min( 255, (int) $m[2] ), min( 255, (int) $m[3] ) );
		}
		return '';
	}

	/**
	 * Relative luminance (WCAG) of a hex colour.
	 *
	 * @param string $hex Colour.
	 * @return float 0 (black) – 1 (white).
	 */
	public static function luminance( string $hex ): float {
		$hex = self::hex( $hex );
		if ( '' === $hex ) {
			return 0.0;
		}
		$lin = static function ( int $c ): float {
			$c /= 255;
			return $c <= 0.03928 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4;
		};
		return 0.2126 * $lin( (int) hexdec( substr( $hex, 1, 2 ) ) ) + 0.7152 * $lin( (int) hexdec( substr( $hex, 3, 2 ) ) ) + 0.0722 * $lin( (int) hexdec( substr( $hex, 5, 2 ) ) );
	}

	/**
	 * Text colour that reads on a background: white, or near-black on light colours.
	 *
	 * @param string $background Background colour(s); the lightest one decides.
	 * @param string ...$more    More background colours (gradient stops).
	 * @return string
	 */
	public static function ink( string $background, string ...$more ): string {
		$lum = max( array_map( array( self::class, 'luminance' ), array_merge( array( $background ), $more ) ) );
		// Contrast with white ≥ contrast with near-black ⇔ luminance below ~0.4 (tuned toward white text).
		return $lum > 0.45 ? '#111827' : '#ffffff';
	}

	/**
	 * Colours of the active theme.
	 *
	 * @return array<int, array{slug:string, name:string, color:string, css:string}> `css`: a CSS value
	 *         that follows the theme (a preset variable with the hex as fallback) or the hex.
	 */
	public static function theme(): array {
		$out  = array();
		$seen = array();
		$add  = static function ( string $slug, string $name, string $color, string $css = '' ) use ( &$out, &$seen ): void {
			$hex = self::hex( $color );
			if ( '' === $hex || '' === $slug || isset( $seen[ $slug ] ) ) {
				return;
			}
			$seen[ $slug ] = true;
			$out[]         = array(
				'slug'  => $slug,
				'name'  => '' !== $name ? $name : $slug,
				'color' => $hex,
				'css'   => '' !== $css ? $css : $hex,
			);
		};

		// theme.json palette (theme first, then the user's custom colours from the Site Editor).
		if ( function_exists( 'wp_get_global_settings' ) && function_exists( 'wp_theme_has_theme_json' ) && wp_theme_has_theme_json() ) {
			$palette = wp_get_global_settings( array( 'color', 'palette' ) );
			foreach ( array( 'theme', 'custom' ) as $origin ) {
				foreach ( (array) ( $palette[ $origin ] ?? array() ) as $entry ) {
					$slug = sanitize_key( (string) ( $entry['slug'] ?? '' ) );
					$add( 'wp:' . $slug, (string) ( $entry['name'] ?? $slug ), (string) ( $entry['color'] ?? '' ), 'var(--wp--preset--color--' . $slug . ', ' . self::hex( (string) ( $entry['color'] ?? '' ) ) . ')' );
				}
			}
		}

		// Classic themes: their customizer colours.
		$mods = array(
			'storefront_accent_color'                => __( 'رنگ تأکید قالب', 'card-to-card' ),
			'storefront_button_background_color'     => __( 'رنگ دکمه قالب', 'card-to-card' ),
			'storefront_button_alt_background_color' => __( 'رنگ دکمه ویژه قالب', 'card-to-card' ),
			'color_primary'                          => __( 'رنگ اصلی قالب', 'card-to-card' ), // Flatsome.
			'color_secondary'                        => __( 'رنگ دوم قالب', 'card-to-card' ),
			'primary_color'                          => __( 'رنگ اصلی قالب', 'card-to-card' ),
			'accent_color'                           => __( 'رنگ تأکید قالب', 'card-to-card' ),
			'ocean_primary_color'                    => __( 'رنگ اصلی قالب', 'card-to-card' ), // OceanWP.
			'link_color'                             => __( 'رنگ پیوند قالب', 'card-to-card' ),
		);
		foreach ( $mods as $mod => $name ) {
			$value = get_theme_mod( $mod );
			if ( is_string( $value ) ) {
				$add( 'mod:' . $mod, $name, $value );
			}
		}
		// Astra: global palette.
		$astra = get_option( 'astra-settings' );
		if ( is_array( $astra ) && isset( $astra['global-color-palette']['palette'] ) && is_array( $astra['global-color-palette']['palette'] ) ) {
			foreach ( array_slice( $astra['global-color-palette']['palette'], 0, 9 ) as $i => $color ) {
				/* translators: %d: palette number */
				$add( 'astra:' . $i, sprintf( __( 'آسترا — رنگ %d', 'card-to-card' ), $i + 1 ), (string) $color, 'var(--ast-global-color-' . $i . ', ' . self::hex( (string) $color ) . ')' );
			}
		}
		// Kadence: global palette (JSON in a theme mod / option).
		$kadence = get_option( 'kadence_global_palette' );
		$kadence = is_string( $kadence ) ? json_decode( $kadence, true ) : null;
		if ( is_array( $kadence ) ) {
			$active = (string) ( $kadence['active'] ?? 'palette' );
			foreach ( array_slice( (array) ( $kadence[ $active ] ?? array() ), 0, 9 ) as $i => $entry ) {
				/* translators: %d: palette number */
				$add( 'kadence:' . $i, sprintf( __( 'کادنس — رنگ %d', 'card-to-card' ), $i + 1 ), (string) ( $entry['color'] ?? '' ), 'var(--global-palette' . ( $i + 1 ) . ', ' . self::hex( (string) ( $entry['color'] ?? '' ) ) . ')' );
			}
		}
		// GeneratePress: global colours.
		$gp = get_option( 'generate_settings' );
		if ( is_array( $gp ) && isset( $gp['global_colors'] ) && is_array( $gp['global_colors'] ) ) {
			foreach ( $gp['global_colors'] as $entry ) {
				$slug = sanitize_key( (string) ( $entry['slug'] ?? '' ) );
				$add( 'gp:' . $slug, (string) ( $entry['name'] ?? $slug ), (string) ( $entry['color'] ?? '' ), 'var(--' . $slug . ', ' . self::hex( (string) ( $entry['color'] ?? '' ) ) . ')' );
			}
		}
		// Blocksy: colour palette.
		$blocksy = get_theme_mod( 'colorPalette' );
		if ( is_array( $blocksy ) ) {
			$i = 0;
			foreach ( $blocksy as $key => $entry ) {
				if ( is_array( $entry ) && isset( $entry['color'] ) ) {
					++$i;
					/* translators: %d: palette number */
					$add( 'blocksy:' . sanitize_key( (string) $key ), sprintf( __( 'بلاکسی — رنگ %d', 'card-to-card' ), $i ), (string) $entry['color'], 'var(--theme-palette-color-' . $i . ', ' . self::hex( (string) $entry['color'] ) . ')' );
				}
			}
		}
		// Woodmart: primary colour.
		$woodmart = get_option( 'xts-woodmart-options' );
		if ( is_array( $woodmart ) && isset( $woodmart['primary-color']['idle'] ) ) {
			$add( 'woodmart:primary', __( 'وودمارت — رنگ اصلی', 'card-to-card' ), (string) $woodmart['primary-color']['idle'] );
		}

		/**
		 * Colours offered from the active theme.
		 *
		 * @param array<int, array{slug:string, name:string, color:string, css:string}> $out Colours.
		 */
		return (array) apply_filters( 'ctc_theme_colors', $out );
	}

	/**
	 * One theme colour by slug (null when the theme no longer has it).
	 *
	 * @param string $slug Slug.
	 * @return array{slug:string, name:string, color:string, css:string}|null
	 */
	public static function theme_color( string $slug ): ?array {
		foreach ( self::theme() as $color ) {
			if ( $color['slug'] === $slug ) {
				return $color;
			}
		}
		return null;
	}
}
