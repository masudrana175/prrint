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
	 * @param string     $src   Absolute path of the source image.
	 * @param array      $crop  Crop rect: x, y, w, h (floats, rotated space).
	 * @param int        $rotation Quarter turns clockwise (0–3).
	 * @param int        $max_w Target max width in px (never upscales).
	 * @param int        $max_h Target max height in px.
	 * @param string     $dest  Absolute destination path (.jpg).
	 * @param array      $opts  Optional: border_in (inches), w_in, h_in (print
	 *                          dimensions in inches; required whenever a
	 *                          border — legacy or design — is requested).
	 * @param array|null $design Optional editor design: filter, adjust,
	 *                           border {enabled,color,width_in}, layers[].
	 *                           Applied to the already cropped/rotated output
	 *                           canvas, after which text layers are drawn in
	 *                           full-canvas fractional coordinates — the same
	 *                           space the front-end editor previews in.
	 * @return bool
	 */
	public static function render( $src, $crop, $rotation, $max_w, $max_h, $dest, $opts = array(), $design = null ) {
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

		// Flip mirrors the cropped rectangle in place — it doesn't change
		// which pixels were selected, so the crop math above stays untouched.
		if ( ! empty( $design['flipH'] ) ) {
			imageflip( $cropped, IMG_FLIP_HORIZONTAL );
		}
		if ( ! empty( $design['flipV'] ) ) {
			imageflip( $cropped, IMG_FLIP_VERTICAL );
		}

		// Filter/tone adjustments only ever touch the photo pixels, never
		// the border fill — applied before the photo is composited in.
		if ( ! empty( $design['filter'] ) ) {
			self::apply_filter( $cropped, $design['filter'] );
		}
		if ( ! empty( $design['adjust'] ) ) {
			self::apply_adjust( $cropped, $design['adjust'] );
		}
		if ( ! empty( $design['overlay'] ) ) {
			$overlay_path = self::overlay_texture_path( $design['overlay'] );
			if ( $overlay_path ) {
				self::draw_image_overlay( $cropped, $overlay_path, imagesx( $cropped ), imagesy( $cropped ) );
			}
		}

		// Never upscale past the cropped pixels.
		$scale = min( 1, min( $max_w / $w, $max_h / $h ) );
		$tw    = max( 1, (int) round( $w * $scale ) );
		$th    = max( 1, (int) round( $h * $scale ) );

		// Resolve the effective border: an explicit design border overrides
		// the legacy white border, which stays the default when a caller
		// only ever passed $opts (backward compatible with pre-design carts).
		$border_width_in = 0.0;
		$border_hex       = 'ffffff';
		if ( ! empty( $design['border']['enabled'] ) ) {
			$border_width_in = (float) ( $design['border']['width_in'] ?? 0 );
			$border_hex       = isset( $design['border']['color'] ) ? $design['border']['color'] : 'ffffff';
		} elseif ( ! empty( $opts['border_in'] ) ) {
			$border_width_in = (float) $opts['border_in'];
		}

		$out = imagecreatetruecolor( $tw, $th );
		imagefill( $out, 0, 0, self::hex_to_gd_color( $out, $border_hex ) );

		$bx = 0;
		$by = 0;
		if ( $border_width_in > 0 && ! empty( $opts['w_in'] ) && ! empty( $opts['h_in'] ) ) {
			$bx = (int) round( $tw * $border_width_in / (float) $opts['w_in'] );
			$by = (int) round( $th * $border_width_in / (float) $opts['h_in'] );
			$bx = min( $bx, (int) floor( ( $tw - 2 ) / 2 ) );
			$by = min( $by, (int) floor( ( $th - 2 ) / 2 ) );
		}

		imagecopyresampled( $out, $cropped, $bx, $by, 0, 0, $tw - 2 * $bx, $th - 2 * $by, $w, $h );
		imagedestroy( $cropped );

		// Freehand doodle: a single full-canvas overlay, under text/shapes.
		if ( ! empty( $design['drawing']['file'] ) ) {
			self::draw_image_overlay( $out, prrint_file_path( $design['drawing']['file'] ), $tw, $th );
		}

		if ( ! empty( $design['layers'] ) && is_array( $design['layers'] ) ) {
			foreach ( $design['layers'] as $layer ) {
				if ( ! is_array( $layer ) ) {
					continue;
				}
				if ( 'text' === ( $layer['type'] ?? '' ) ) {
					self::draw_text_layer( $out, $layer, $tw, $th );
				} elseif ( 'shape' === ( $layer['type'] ?? '' ) ) {
					self::draw_shape_layer( $out, $layer, $tw, $th );
				}
			}
		}

		$dir = dirname( $dest );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$quality = isset( $opts['quality'] ) ? (int) $opts['quality'] : (int) prrint_settings()['jpeg_quality'];
		$ok      = imagejpeg( $out, $dest, $quality );
		imagedestroy( $out );

		return (bool) $ok;
	}

	/**
	 * One-click tone presets, built from GD's built-in imagefilter() passes
	 * (fast, single-pass).
	 *
	 * @param resource|GdImage $im     GD image, modified in place.
	 * @param string           $filter One of bw|warm|cold|vintage|duotone|legacy|smooth.
	 */
	public static function apply_filter( $im, $filter ) {
		switch ( $filter ) {
			case 'bw':
				imagefilter( $im, IMG_FILTER_GRAYSCALE );
				break;
			case 'warm':
				imagefilter( $im, IMG_FILTER_COLORIZE, 45, 18, -30 );
				break;
			case 'cold':
				imagefilter( $im, IMG_FILTER_COLORIZE, -25, -8, 40 );
				break;
			case 'vintage':
				imagefilter( $im, IMG_FILTER_GRAYSCALE );
				imagefilter( $im, IMG_FILTER_COLORIZE, 65, 40, 10 );
				imagefilter( $im, IMG_FILTER_CONTRAST, 15 ); // GD's scale is inverted: positive = less contrast (faded).
				break;
			case 'duotone':
				self::apply_duotone( $im, '#1b1035', '#ffb997' );
				break;
			case 'legacy':
				imagefilter( $im, IMG_FILTER_GRAYSCALE );
				imagefilter( $im, IMG_FILTER_COLORIZE, 45, 25, 5 );
				imagefilter( $im, IMG_FILTER_CONTRAST, 25 );
				imagefilter( $im, IMG_FILTER_BRIGHTNESS, -10 );
				break;
			case 'smooth':
				imagefilter( $im, IMG_FILTER_SMOOTH, 8 );
				break;
		}
	}

	/**
	 * A true 2-stop duotone gradient (shadow -> highlight), done in O(256)
	 * rather than a per-pixel PHP loop: grayscale first, reduce to an
	 * indexed palette (at most 256 distinct gray levels, so this is
	 * lossless), then recolor the palette's 256 entries instead of every
	 * pixel — every pixel referencing that index picks up the new color
	 * for free. ~250ms even at full 300 DPI print resolution.
	 *
	 * @param resource|GdImage $im           GD image, converted to palette mode in place.
	 * @param string           $shadow_hex   Color for the darkest tones.
	 * @param string           $highlight_hex Color for the lightest tones.
	 */
	protected static function apply_duotone( $im, $shadow_hex, $highlight_hex ) {
		imagefilter( $im, IMG_FILTER_GRAYSCALE );
		imagetruecolortopalette( $im, false, 256 );

		$shadow    = self::hex_to_rgb( $shadow_hex );
		$highlight = self::hex_to_rgb( $highlight_hex );

		$total = imagecolorstotal( $im );
		for ( $i = 0; $i < $total; $i++ ) {
			$c = imagecolorsforindex( $im, $i );
			$t = $c['red'] / 255; // grayscale, so r = g = b already.
			imagecolorset(
				$im,
				$i,
				(int) round( $shadow['r'] + ( $highlight['r'] - $shadow['r'] ) * $t ),
				(int) round( $shadow['g'] + ( $highlight['g'] - $shadow['g'] ) * $t ),
				(int) round( $shadow['b'] + ( $highlight['b'] - $shadow['b'] ) * $t )
			);
		}
	}

	/**
	 * Parse a "#rrggbb" string into an { r, g, b } array (0-255 each).
	 */
	protected static function hex_to_rgb( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		return array(
			'r' => hexdec( substr( $hex, 0, 2 ) ),
			'g' => hexdec( substr( $hex, 2, 2 ) ),
			'b' => hexdec( substr( $hex, 4, 2 ) ),
		);
	}

	/**
	 * Brightness / contrast / saturation sliders.
	 *
	 * @param resource|GdImage $im     GD image, modified in place.
	 * @param array            $adjust { brightness: -100..100, contrast: -100..100, saturation: 0..100 }.
	 *                                 0/0/100 is a no-op on every field.
	 */
	public static function apply_adjust( $im, $adjust ) {
		$brightness = isset( $adjust['brightness'] ) ? max( -100, min( 100, (float) $adjust['brightness'] ) ) : 0.0;
		$contrast   = isset( $adjust['contrast'] ) ? max( -100, min( 100, (float) $adjust['contrast'] ) ) : 0.0;
		$saturation = isset( $adjust['saturation'] ) ? max( 0, min( 100, (float) $adjust['saturation'] ) ) : 100.0;
		$gamma      = isset( $adjust['gamma'] ) ? max( -100, min( 100, (float) $adjust['gamma'] ) ) : 0.0;
		$exposure   = isset( $adjust['exposure'] ) ? max( -100, min( 100, (float) $adjust['exposure'] ) ) : 0.0;
		$clarity    = isset( $adjust['clarity'] ) ? max( 0, min( 100, (float) $adjust['clarity'] ) ) : 0.0;
		$shadows    = isset( $adjust['shadows'] ) ? max( -100, min( 100, (float) $adjust['shadows'] ) ) : 0.0;
		$highlights = isset( $adjust['highlights'] ) ? max( -100, min( 100, (float) $adjust['highlights'] ) ) : 0.0;

		if ( 0.0 !== $brightness ) {
			imagefilter( $im, IMG_FILTER_BRIGHTNESS, (int) round( $brightness * 2.55 ) );
		}
		if ( 0.0 !== $contrast ) {
			// GD's contrast scale is inverted relative to the slider: negate it.
			imagefilter( $im, IMG_FILTER_CONTRAST, (int) round( -$contrast ) );
		}
		if ( $saturation < 100 ) {
			$w    = imagesx( $im );
			$h    = imagesy( $im );
			$gray = imagecreatetruecolor( $w, $h );
			imagecopy( $gray, $im, 0, 0, 0, 0, $w, $h );
			imagefilter( $gray, IMG_FILTER_GRAYSCALE );
			imagecopymerge( $im, $gray, 0, 0, 0, 0, $w, $h, (int) round( 100 - $saturation ) );
			imagedestroy( $gray );
		}
		if ( 0.0 !== $gamma ) {
			// Subtle midtone curve: output_gamma in ~0.62..1.6.
			imagegammacorrect( $im, 1.0, pow( 1.6, $gamma / 100 ) );
		}
		if ( 0.0 !== $exposure ) {
			// True stops-based exposure (-2..+2 EV) expressed as a gamma
			// correction — mathematically the same curve a stop change
			// produces on a display-referred image, and GD-native/fast.
			imagegammacorrect( $im, 1.0, pow( 2, $exposure / 100 * 2 ) );
		}
		if ( $clarity > 0 ) {
			self::apply_clarity( $im, $clarity / 100 );
		}
		// Shadows/Highlights: GD has no fast way to mask by luminance without
		// a per-pixel PHP loop (too slow at print resolution), so these are
		// global brightness/contrast nudges biased toward each end — a
		// deliberate approximation, not true selective tone mapping.
		if ( 0.0 !== $shadows ) {
			imagefilter( $im, IMG_FILTER_BRIGHTNESS, (int) round( $shadows / 100 * 45 ) );
		}
		if ( 0.0 !== $highlights ) {
			imagefilter( $im, IMG_FILTER_BRIGHTNESS, (int) round( $highlights / 100 * -30 ) );
			imagefilter( $im, IMG_FILTER_CONTRAST, (int) round( $highlights / 100 * -15 ) );
		}
	}

	/**
	 * Local-contrast / sharpen boost ("Clarity") via GD's native C-level
	 * imageconvolution() — fast at full print resolution, unlike a per-pixel
	 * PHP unsharp-mask loop would be.
	 *
	 * @param resource|GdImage $im     GD image, modified in place.
	 * @param float            $amount 0..1.
	 */
	protected static function apply_clarity( $im, $amount ) {
		$amount = max( 0.0, min( 1.0, $amount ) ) * 0.6; // cap to avoid harsh halos
		$edge   = -$amount;
		$center = 1 + 4 * $amount;
		imageconvolution(
			$im,
			array(
				array( 0, $edge, 0 ),
				array( $edge, $center, $edge ),
				array( 0, $edge, 0 ),
			),
			1,
			0
		);
	}

	/**
	 * Draw one text layer onto the (already composited) output canvas.
	 *
	 * Layer geometry (x, y, w, fontSize) is expressed as a fraction of the
	 * canvas — resolution independent, so the same layer renders correctly
	 * on a small cart-preview canvas and a full 300 DPI print canvas alike.
	 *
	 * @param resource|GdImage $im        Output GD image, modified in place.
	 * @param array            $layer     { text, fontSize, bold, align, color, bgColor, lineSpacing, x, y, w, rotation }.
	 * @param int              $canvas_w  Output canvas width in px.
	 * @param int              $canvas_h  Output canvas height in px.
	 */
	public static function draw_text_layer( $im, $layer, $canvas_w, $canvas_h ) {
		$text = isset( $layer['text'] ) ? (string) $layer['text'] : '';
		if ( '' === trim( $text ) ) {
			return;
		}

		$font = ! empty( $layer['bold'] )
			? PRRINT_DIR . 'assets/fonts/LiberationSans-Bold.ttf'
			: PRRINT_DIR . 'assets/fonts/LiberationSans-Regular.ttf';
		if ( ! file_exists( $font ) ) {
			return;
		}

		$size_frac = isset( $layer['fontSize'] ) ? max( 0.01, min( 0.5, (float) $layer['fontSize'] ) ) : 0.06;
		$font_px   = max( 6, (int) round( $size_frac * $canvas_h ) );
		$align     = in_array( $layer['align'] ?? '', array( 'left', 'center', 'right' ), true ) ? $layer['align'] : 'center';
		$spacing   = isset( $layer['lineSpacing'] ) ? max( 0.8, min( 3, (float) $layer['lineSpacing'] ) ) : 1.3;
		$angle     = isset( $layer['rotation'] ) ? ( (float) $layer['rotation'] * -1 ) : 0.0; // GD's angle runs counter-clockwise.

		$x = ( isset( $layer['x'] ) ? (float) $layer['x'] : 0.1 ) * $canvas_w;
		$y = ( isset( $layer['y'] ) ? (float) $layer['y'] : 0.1 ) * $canvas_h;
		$w = ( isset( $layer['w'] ) ? (float) $layer['w'] : 0.8 ) * $canvas_w;
		$w = max( (float) $font_px, $w );

		$color = self::hex_to_gd_color( $im, isset( $layer['color'] ) ? $layer['color'] : '#ffffff' );

		$wrapped = array();
		foreach ( explode( "\n", $text ) as $source_line ) {
			$wrapped = array_merge( $wrapped, self::wrap_line( $font, $font_px, $source_line, $w ) );
		}
		$wrapped = array_slice( $wrapped, 0, 40 ); // sanity cap

		$line_h  = $font_px * $spacing;
		$block_h = $line_h * count( $wrapped );

		if ( ! empty( $layer['bgColor'] ) && 'transparent' !== $layer['bgColor'] ) {
			$bg  = self::hex_to_gd_color( $im, $layer['bgColor'] );
			$pad = $font_px * 0.4;
			imagefilledrectangle(
				$im,
				(int) round( $x - $pad ),
				(int) round( $y - $pad ),
				(int) round( $x + $w + $pad ),
				(int) round( $y + $block_h + $pad ),
				$bg
			);
		}

		foreach ( $wrapped as $i => $line ) {
			if ( '' === $line ) {
				continue;
			}
			$bbox   = imagettfbbox( $font_px, 0, $font, $line );
			$line_w = abs( $bbox[2] - $bbox[0] );
			$lx     = $x;
			if ( 'center' === $align ) {
				$lx = $x + ( $w - $line_w ) / 2;
			} elseif ( 'right' === $align ) {
				$lx = $x + $w - $line_w;
			}
			$ly = $y + $font_px + $i * $line_h; // imagettftext y is the text baseline.
			imagettftext( $im, $font_px, $angle, (int) round( $lx ), (int) round( $ly ), $color, $font, $line );
		}
	}

	/**
	 * Normalized (-0.5..0.5 on each axis) point set for one sticker shape.
	 * The exact same math lives in frontend.js's SHAPE_POINTS so the
	 * editor preview and the print render draw the identical silhouette.
	 *
	 * @return float[][] [[x,y], ...]
	 */
	protected static function shape_points( $shape ) {
		switch ( $shape ) {
			case 'square':
				return array( array( -0.5, -0.5 ), array( 0.5, -0.5 ), array( 0.5, 0.5 ), array( -0.5, 0.5 ) );

			case 'circle':
				$pts = array();
				for ( $i = 0; $i < 40; $i++ ) {
					$t     = ( $i / 40 ) * 2 * M_PI;
					$pts[] = array( 0.5 * cos( $t ), 0.5 * sin( $t ) );
				}
				return $pts;

			case 'star':
				$pts   = array();
				$outer = 0.5;
				$inner = 0.5 * 0.382;
				for ( $i = 0; $i < 10; $i++ ) {
					$r     = ( 0 === $i % 2 ) ? $outer : $inner;
					$t     = ( $i / 10 ) * 2 * M_PI - M_PI / 2;
					$pts[] = array( $r * cos( $t ), $r * sin( $t ) );
				}
				return $pts;

			case 'heart':
				$raw   = array();
				$n     = 40;
				$min_x = PHP_INT_MAX;
				$max_x = -PHP_INT_MAX;
				$min_y = PHP_INT_MAX;
				$max_y = -PHP_INT_MAX;
				for ( $i = 0; $i <= $n; $i++ ) {
					$t     = ( $i / $n ) * 2 * M_PI;
					$x     = 16 * pow( sin( $t ), 3 );
					$y     = -( 13 * cos( $t ) - 5 * cos( 2 * $t ) - 2 * cos( 3 * $t ) - cos( 4 * $t ) );
					$raw[] = array( $x, $y );
					$min_x = min( $min_x, $x );
					$max_x = max( $max_x, $x );
					$min_y = min( $min_y, $y );
					$max_y = max( $max_y, $y );
				}
				$sx  = $max_x - $min_x;
				$sy  = $max_y - $min_y;
				$pts = array();
				foreach ( $raw as $p ) {
					$pts[] = array( ( $p[0] - $min_x ) / $sx - 0.5, ( $p[1] - $min_y ) / $sy - 0.5 );
				}
				return $pts;

			case 'arrow':
				return array(
					array( -0.5, -0.15 ),
					array( 0.15, -0.15 ),
					array( 0.15, -0.35 ),
					array( 0.5, 0 ),
					array( 0.15, 0.35 ),
					array( 0.15, 0.15 ),
					array( -0.5, 0.15 ),
				);

			case 'line':
				return array( array( -0.5, -0.06 ), array( 0.5, -0.06 ), array( 0.5, 0.06 ), array( -0.5, 0.06 ) );
		}

		return array();
	}

	/**
	 * Draw one filled, rotated sticker shape onto the output canvas.
	 *
	 * @param resource|GdImage $im       Output GD image, modified in place.
	 * @param array            $layer    { shape, color, x, y, w, h, rotation }. x/y/w/h are
	 *                                   fractions of the canvas (x,y = bounding-box top-left).
	 * @param int              $canvas_w Output canvas width in px.
	 * @param int              $canvas_h Output canvas height in px.
	 */
	public static function draw_shape_layer( $im, $layer, $canvas_w, $canvas_h ) {
		$points = self::shape_points( isset( $layer['shape'] ) ? $layer['shape'] : '' );
		if ( empty( $points ) ) {
			return;
		}

		$lx    = isset( $layer['x'] ) ? (float) $layer['x'] : 0.3;
		$ly    = isset( $layer['y'] ) ? (float) $layer['y'] : 0.3;
		$lw    = isset( $layer['w'] ) ? (float) $layer['w'] : 0.2;
		$lh    = isset( $layer['h'] ) ? (float) $layer['h'] : 0.2;
		$w     = $lw * $canvas_w;
		$h     = $lh * $canvas_h;
		$cx    = ( $lx + $lw / 2 ) * $canvas_w;
		$cy    = ( $ly + $lh / 2 ) * $canvas_h;
		$angle = isset( $layer['rotation'] ) ? deg2rad( (float) $layer['rotation'] ) : 0.0;
		$color = self::hex_to_gd_color( $im, isset( $layer['color'] ) ? $layer['color'] : '#000000' );

		$poly = array();
		foreach ( $points as $p ) {
			$px = $p[0] * $w;
			$py = $p[1] * $h;
			// Rotation matrix in the same y-down pixel space the JS canvas
			// preview uses, so positive angles turn clockwise on both ends.
			$rx     = $px * cos( $angle ) - $py * sin( $angle );
			$ry     = $px * sin( $angle ) + $py * cos( $angle );
			$poly[] = $cx + $rx;
			$poly[] = $cy + $ry;
		}

		imagefilledpolygon( $im, $poly, count( $points ), $color );
	}

	/**
	 * Composite a transparent PNG (the freehand doodle layer) to fill the
	 * whole output canvas.
	 *
	 * @param resource|GdImage $im       Output GD image, modified in place.
	 * @param string           $abs_path Absolute path to the overlay PNG.
	 * @param int              $canvas_w Output canvas width in px.
	 * @param int              $canvas_h Output canvas height in px.
	 */
	public static function draw_image_overlay( $im, $abs_path, $canvas_w, $canvas_h ) {
		if ( ! file_exists( $abs_path ) ) {
			return;
		}
		$overlay = @imagecreatefrompng( $abs_path ); // phpcs:ignore
		if ( ! $overlay ) {
			return;
		}

		imagealphablending( $overlay, false );
		imagesavealpha( $overlay, true );

		$resized = imagecreatetruecolor( $canvas_w, $canvas_h );
		imagealphablending( $resized, false );
		imagesavealpha( $resized, true );
		$transparent = imagecolorallocatealpha( $resized, 0, 0, 0, 127 );
		imagefill( $resized, 0, 0, $transparent );
		imagecopyresampled( $resized, $overlay, 0, 0, 0, 0, $canvas_w, $canvas_h, imagesx( $overlay ), imagesy( $overlay ) );
		imagedestroy( $overlay );

		imagealphablending( $im, true );
		imagecopy( $im, $resized, 0, 0, 0, 0, $canvas_w, $canvas_h );
		imagedestroy( $resized );
	}

	/**
	 * Absolute path of a bundled Overlays texture, or false for an unknown id.
	 * Whitelisted — never resolves outside assets/overlays/.
	 */
	public static function overlay_texture_path( $id ) {
		$allowed = array( 'vignette', 'glow', 'lightleak', 'grain', 'bokeh', 'scratches' );
		if ( ! in_array( $id, $allowed, true ) ) {
			return false;
		}
		$path = PRRINT_DIR . 'assets/overlays/' . $id . '.png';
		return file_exists( $path ) ? $path : false;
	}

	/**
	 * Greedy word-wrap one line of text to fit $max_w, measured with the
	 * same font/size the caller will render with.
	 *
	 * @return string[]
	 */
	protected static function wrap_line( $font, $font_px, $line, $max_w ) {
		$words = preg_split( '/\s+/', trim( $line ) );
		if ( empty( $words ) || ( 1 === count( $words ) && '' === $words[0] ) ) {
			return array( '' );
		}

		$out = array();
		$cur = '';
		foreach ( $words as $word ) {
			$try  = '' === $cur ? $word : $cur . ' ' . $word;
			$bbox = imagettfbbox( $font_px, 0, $font, $try );
			$w    = abs( $bbox[2] - $bbox[0] );
			if ( $w > $max_w && '' !== $cur ) {
				$out[] = $cur;
				$cur   = $word;
			} else {
				$cur = $try;
			}
		}
		if ( '' !== $cur ) {
			$out[] = $cur;
		}

		return $out ? $out : array( '' );
	}

	/**
	 * Allocate a GD color from a "#rrggbb"/"rrggbb"/"#rgb" string, falling
	 * back to white for anything invalid.
	 */
	protected static function hex_to_gd_color( $im, $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			$hex = 'ffffff';
		}
		return imagecolorallocate( $im, hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
	}
}
