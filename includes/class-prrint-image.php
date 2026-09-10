<?php
/**
 * GD-based image pipeline: EXIF normalization and crop/border rendering.
 *
 * @package prrint
 */

defined( 'ABSPATH' ) || exit;

class Prrint_Image {

	/**
	 * Load an image file into a GD resource.
	 *
	 * @param string $path Absolute file path.
	 * @return resource|GdImage|false
	 */
	public static function load( $path ) {
		$info = @getimagesize( $path ); // phpcs:ignore
		if ( ! $info ) {
			return false;
		}

		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}

		switch ( $info['mime'] ) {
			case 'image/jpeg':
				return @imagecreatefromjpeg( $path ); // phpcs:ignore
			case 'image/png':
				return @imagecreatefrompng( $path ); // phpcs:ignore
			case 'image/webp':
				return function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $path ) : false; // phpcs:ignore
		}

		return false;
	}

	/**
	 * Bake EXIF orientation into the pixels of a JPEG so the browser preview
	 * and the GD renderer agree on the same pixel grid.
	 *
	 * @param string $path Absolute file path.
	 * @return bool
	 */
	public static function normalize_orientation( $path ) {
		$info = @getimagesize( $path ); // phpcs:ignore
		if ( ! $info || 'image/jpeg' !== $info['mime'] || ! function_exists( 'exif_read_data' ) ) {
			return true;
		}

		$exif        = @exif_read_data( $path ); // phpcs:ignore
		$orientation = isset( $exif['Orientation'] ) ? (int) $exif['Orientation'] : 1;
		if ( $orientation <= 1 ) {
			return true;
		}

		$im = self::load( $path );
		if ( ! $im ) {
			return false;
		}

		switch ( $orientation ) {
			case 2:
				imageflip( $im, IMG_FLIP_HORIZONTAL );
				break;
			case 3:
				$im = imagerotate( $im, 180, 0 );
				break;
			case 4:
				imageflip( $im, IMG_FLIP_VERTICAL );
				break;
			case 5:
				imageflip( $im, IMG_FLIP_HORIZONTAL );
				$im = imagerotate( $im, 90, 0 );
				break;
			case 6:
				$im = imagerotate( $im, -90, 0 );
				break;
			case 7:
				imageflip( $im, IMG_FLIP_HORIZONTAL );
				$im = imagerotate( $im, -90, 0 );
				break;
			case 8:
				$im = imagerotate( $im, 90, 0 );
				break;
		}

		$quality = (int) prrint_settings()['jpeg_quality'];
		$ok      = $im && imagejpeg( $im, $path, $quality );
		if ( $im ) {
			imagedestroy( $im );
		}

		return (bool) $ok;
	}

	/**
	 * Render a cropped, rotated, optionally bordered JPEG from a source image.
	 *
	 * The crop rectangle is expressed in the coordinate space of the source
	 * image AFTER applying $rotation quarter-turns clockwise — exactly the
	 * space the front-end editor works in.
	 *
	 * @param string $src  Absolute path of the source image.
	 * @param array  $crop Crop rect: x, y, w, h (floats, rotated space).
	 * @param int    $rotation Quarter turns clockwise (0–3).
	 * @param int    $max_w Target max width in px (never upscales).
	 * @param int    $max_h Target max height in px.
	 * @param string $dest  Absolute destination path (.jpg).
	 * @param array  $opts  Optional: border_in (inches), w_in, h_in (print
	 *                      dimensions in inches; required to compute border).
	 * @return bool
	 */
	public static function render( $src, $crop, $rotation, $max_w, $max_h, $dest, $opts = array() ) {
		$im = self::load( $src );
		if ( ! $im ) {
			return false;
		}

		$rotation = ( (int) $rotation ) % 4;
		if ( $rotation ) {
			$rotated = imagerotate( $im, -90 * $rotation, 0 );
			imagedestroy( $im );
			if ( ! $rotated ) {
				return false;
			}
			$im = $rotated;
		}

		$iw = imagesx( $im );
		$ih = imagesy( $im );

		$x = max( 0, (int) round( $crop['x'] ) );
		$y = max( 0, (int) round( $crop['y'] ) );
		$w = (int) round( $crop['w'] );
		$h = (int) round( $crop['h'] );
		$w = max( 1, min( $w, $iw - $x ) );
		$h = max( 1, min( $h, $ih - $y ) );

		$cropped = imagecrop( $im, array( 'x' => $x, 'y' => $y, 'width' => $w, 'height' => $h ) );
		imagedestroy( $im );
		if ( ! $cropped ) {
			return false;
		}

		// Never upscale past the cropped pixels.
		$scale = min( 1, min( $max_w / $w, $max_h / $h ) );
		$tw    = max( 1, (int) round( $w * $scale ) );
		$th    = max( 1, (int) round( $h * $scale ) );

		$out   = imagecreatetruecolor( $tw, $th );
		$white = imagecolorallocate( $out, 255, 255, 255 );
		imagefill( $out, 0, 0, $white );

		// Optional white border: image is inset by border_in on every side.
		$bx = 0;
		$by = 0;
		if ( ! empty( $opts['border_in'] ) && ! empty( $opts['w_in'] ) && ! empty( $opts['h_in'] ) ) {
			$bx = (int) round( $tw * (float) $opts['border_in'] / (float) $opts['w_in'] );
			$by = (int) round( $th * (float) $opts['border_in'] / (float) $opts['h_in'] );
			$bx = min( $bx, (int) floor( ( $tw - 2 ) / 2 ) );
			$by = min( $by, (int) floor( ( $th - 2 ) / 2 ) );
		}

		imagecopyresampled( $out, $cropped, $bx, $by, 0, 0, $tw - 2 * $bx, $th - 2 * $by, $w, $h );
		imagedestroy( $cropped );

		$dir = dirname( $dest );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$quality = isset( $opts['quality'] ) ? (int) $opts['quality'] : (int) prrint_settings()['jpeg_quality'];
		$ok      = imagejpeg( $out, $dest, $quality );
		imagedestroy( $out );

		return (bool) $ok;
	}
}
