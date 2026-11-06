<?php
/**
 * Visual fingerprint of a receipt image, to spot the same receipt sent again as a different file
 * (re-saved, resized, recompressed by a messenger).
 *
 * Two stages, because receipts of one bank app share the same layout and differ only in a few
 * digits — a plain perceptual hash calls them all «similar»:
 *  1. dHash (16×16 = 256 bits): cheap prefilter that finds look-alikes (same layout).
 *  2. A 128×256 grey thumbnail: the share of pixels that differ strongly. A re-saved copy of the same
 *     size stays at ~0% pixel by pixel; a resized copy stays near 0% when 1-pixel shifts are forgiven.
 *     Measured limits: another receipt of the same app differs by ≥ ~0.02% (shift-forgiving), but a
 *     receipt that differs in a single digit can look as close as a heavily resized copy — so «very
 *     similar» is only ever a warning for a person to compare, never proof.
 *
 * GD reads JPEG / PNG / WEBP; HEIC needs Imagick. PDFs are not fingerprinted.
 *
 * @package CardToCard
 */

namespace CardToCard\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Receipt image fingerprint.
 */
final class Image_Hash {

	public const HASH_SIZE = 16;
	public const THUMB_W   = 128;
	public const THUMB_H   = 256;

	/**
	 * Grey levels apart for a pixel to count as «different».
	 */
	public const STRONG = 48;

	/**
	 * Fingerprint of an image file.
	 *
	 * @param string $path File.
	 * @return array{hash:string, thumb:string, ratio:int}|null Hash: 64 hex; thumb: grey bytes; ratio: width/height × 1000. Null when unreadable or blank.
	 */
	public static function analyze( string $path ): ?array {
		$img = self::load( $path );
		if ( ! $img ) {
			return null;
		}
		[ $width, $height, $grey ] = $img;
		$small                     = $grey( self::HASH_SIZE + 1, self::HASH_SIZE );
		$bits                      = '';
		for ( $y = 0; $y < self::HASH_SIZE; $y++ ) {
			for ( $x = 0; $x < self::HASH_SIZE; $x++ ) {
				$i     = $y * ( self::HASH_SIZE + 1 ) + $x;
				$bits .= $small[ $i ] > $small[ $i + 1 ] ? '1' : '0';
			}
		}
		$ones = substr_count( $bits, '1' );
		// A blank or flat image has almost no edges; it would look like every other blank image.
		if ( $ones < 12 || $ones > strlen( $bits ) - 12 ) {
			return null;
		}
		$hex = '';
		foreach ( str_split( $bits, 4 ) as $nibble ) {
			$hex .= dechex( (int) bindec( $nibble ) );
		}
		$thumb = '';
		foreach ( $grey( self::THUMB_W, self::THUMB_H ) as $level ) {
			$thumb .= chr( (int) max( 0, min( 255, round( $level ) ) ) );
		}
		return array(
			'hash'  => $hex,
			'thumb' => $thumb,
			'ratio' => (int) round( 1000 * $width / max( 1, $height ) ),
		);
	}

	/**
	 * Bits that differ between two hashes (PHP_INT_MAX when not comparable).
	 *
	 * @param string $a Hash.
	 * @param string $b Hash.
	 * @return int
	 */
	public static function distance( string $a, string $b ): int {
		if ( '' === $a || strlen( $a ) !== strlen( $b ) ) {
			return PHP_INT_MAX;
		}
		$dist = 0;
		$len  = strlen( $a );
		for ( $i = 0; $i < $len; $i += 8 ) {
			$dist += substr_count( decbin( hexdec( substr( $a, $i, 8 ) ) ^ hexdec( substr( $b, $i, 8 ) ) ), '1' );
		}
		return $dist;
	}

	/**
	 * Share (0–1) of thumbnail pixels that differ strongly; 1 when not comparable. With a tolerance of
	 * 1, a pixel counts only when no pixel one step around it in the other image is close — the 1-pixel
	 * shifts of resampling (a resized copy) are forgiven. Checked both ways.
	 *
	 * @param string $a         Thumbnail bytes.
	 * @param string $b         Thumbnail bytes.
	 * @param int    $tolerance 0 = pixel by pixel, 1 = forgive 1-pixel shifts.
	 * @return float
	 */
	public static function difference( string $a, string $b, int $tolerance = 0 ): float {
		$len = strlen( $a );
		if ( self::THUMB_W * self::THUMB_H !== $len || strlen( $b ) !== $len ) {
			return 1.0;
		}
		return max( self::one_way( $a, $b, $tolerance ), self::one_way( $b, $a, $tolerance ) );
	}

	/**
	 * Share of pixels of $a with no close pixel near the same place in $b.
	 *
	 * @param string $a         Thumbnail bytes.
	 * @param string $b         Thumbnail bytes.
	 * @param int    $tolerance Neighbourhood radius.
	 * @return float
	 */
	private static function one_way( string $a, string $b, int $tolerance ): float {
		$w      = self::THUMB_W;
		$h      = self::THUMB_H;
		$strong = 0;
		for ( $y = 0; $y < $h; $y++ ) {
			for ( $x = 0; $x < $w; $x++ ) {
				$va = ord( $a[ $y * $w + $x ] );
				if ( abs( $va - ord( $b[ $y * $w + $x ] ) ) <= self::STRONG ) {
					continue;
				}
				if ( 0 === $tolerance ) {
					++$strong;
					continue;
				}
				$near = false;
				for ( $dy = -$tolerance; $dy <= $tolerance && ! $near; $dy++ ) {
					$ny = $y + $dy;
					if ( $ny < 0 || $ny >= $h ) {
						continue;
					}
					for ( $dx = -$tolerance; $dx <= $tolerance; $dx++ ) {
						$nx = $x + $dx;
						if ( $nx >= 0 && $nx < $w && abs( $va - ord( $b[ $ny * $w + $nx ] ) ) <= self::STRONG ) {
							$near = true;
							break;
						}
					}
				}
				if ( ! $near ) {
					++$strong;
				}
			}
		}
		return $strong / ( $w * $h );
	}

	/**
	 * Open an image: [ width, height, fn( w, h ): float[] grey levels row by row ].
	 *
	 * @param string $path File.
	 * @return array{0:int, 1:int, 2:callable(int, int): array<int, float>}|null
	 */
	private static function load( string $path ): ?array {
		if ( ! is_readable( $path ) ) {
			return null;
		}
		if ( function_exists( 'imagecreatefromstring' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local private file.
			$data = (string) file_get_contents( $path );
			$src  = '' !== $data ? @imagecreatefromstring( $data ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable images return false.
			if ( $src ) {
				$grey = static function ( int $w, int $h ) use ( $src ): array {
					$small = imagecreatetruecolor( $w, $h );
					imagecopyresampled( $small, $src, 0, 0, 0, 0, $w, $h, imagesx( $src ), imagesy( $src ) );
					$out = array();
					for ( $y = 0; $y < $h; $y++ ) {
						for ( $x = 0; $x < $w; $x++ ) {
							$rgb   = imagecolorat( $small, $x, $y );
							$out[] = 0.299 * ( ( $rgb >> 16 ) & 255 ) + 0.587 * ( ( $rgb >> 8 ) & 255 ) + 0.114 * ( $rgb & 255 );
						}
					}
					return $out;
				};
				return array( imagesx( $src ), imagesy( $src ), $grey );
			}
		}
		if ( class_exists( '\Imagick' ) ) {
			try {
				$img = new \Imagick( $path );
				$img->setIteratorIndex( 0 );
				$img->transformImageColorspace( \Imagick::COLORSPACE_GRAY );
				$grey = static function ( int $w, int $h ) use ( $img ): array {
					$copy = clone $img;
					$copy->resizeImage( $w, $h, \Imagick::FILTER_TRIANGLE, 1, false );
					return array_map( 'floatval', $copy->exportImagePixels( 0, 0, $w, $h, 'I', \Imagick::PIXEL_CHAR ) );
				};
				return array( $img->getImageWidth(), $img->getImageHeight(), $grey );
			} catch ( \Exception $e ) {
				return null;
			}
		}
		return null;
	}
}
