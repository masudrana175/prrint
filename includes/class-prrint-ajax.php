<?php
/**
 * AJAX endpoints: photo upload and batch add-to-cart.
 *
 * @package prrint
 */

defined( 'ABSPATH' ) || exit;

class Prrint_Ajax {

	const MAX_ITEMS = 30;

	public static function init() {
		add_action( 'wp_ajax_prrint_upload', array( __CLASS__, 'upload' ) );
		add_action( 'wp_ajax_nopriv_prrint_upload', array( __CLASS__, 'upload' ) );
		add_action( 'wp_ajax_prrint_add_to_cart', array( __CLASS__, 'add_to_cart' ) );
		add_action( 'wp_ajax_nopriv_prrint_add_to_cart', array( __CLASS__, 'add_to_cart' ) );
	}

	/**
	 * Store a customer photo under uploads/prrint/tmp/ with a random name and
	 * return a token the studio passes back when adding to cart.
	 */
	public static function upload() {
		check_ajax_referer( 'prrint_studio', 'nonce' );

		if ( empty( $_FILES['file'] ) || ! isset( $_FILES['file']['tmp_name'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No file received.', 'prrint' ) ) );
		}

		$file      = $_FILES['file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$max_bytes = (int) prrint_settings()['max_mb'] * 1048576;

		if ( ! empty( $file['error'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Upload failed, please try again.', 'prrint' ) ) );
		}
		if ( $file['size'] > $max_bytes ) {
			wp_send_json_error( array(
				/* translators: %d: size limit in MB */
				'message' => sprintf( __( 'File is too large (max %d MB).', 'prrint' ), prrint_settings()['max_mb'] ),
			) );
		}

		$info    = @getimagesize( $file['tmp_name'] ); // phpcs:ignore
		$allowed = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/webp' => 'webp',
		);
		if ( ! $info || ! isset( $allowed[ $info['mime'] ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Please upload a JPEG, PNG or WebP image.', 'prrint' ) ) );
		}

		$subdir   = 'tmp/' . gmdate( 'Y/m' );
		$dir      = prrint_upload_dir( $subdir );
		$filename = wp_generate_password( 24, false, false ) . '.' . $allowed[ $info['mime'] ];
		$dest     = trailingslashit( $dir['path'] ) . $filename;

		if ( ! @move_uploaded_file( $file['tmp_name'], $dest ) ) { // phpcs:ignore
			wp_send_json_error( array( 'message' => __( 'Could not store the uploaded file.', 'prrint' ) ) );
		}
		@chmod( $dest, 0644 ); // phpcs:ignore

		Prrint_Image::normalize_orientation( $dest );

		$info = @getimagesize( $dest ); // phpcs:ignore
		if ( ! $info ) {
			@unlink( $dest ); // phpcs:ignore
			wp_send_json_error( array( 'message' => __( 'The image could not be processed.', 'prrint' ) ) );
		}

		$relative = $subdir . '/' . $filename;
		$token    = wp_generate_password( 32, false, false );

		set_transient( 'prrint_up_' . $token, array(
			'file'   => $relative,
			'width'  => (int) $info[0],
			'height' => (int) $info[1],
		), WEEK_IN_SECONDS );

		// Logged-in customers keep a permanent copy in their "My Photos"
		// library so it can be reused after the tmp upload expires.
		Prrint_Account::save_upload_to_library( $relative, (int) $info[0], (int) $info[1], isset( $file['name'] ) ? $file['name'] : '' );

		wp_send_json_success( array(
			'token'  => $token,
			'url'    => prrint_file_url( $relative ),
			'width'  => (int) $info[0],
			'height' => (int) $info[1],
		) );
	}

	/**
	 * Add a batch of customized prints to the WooCommerce cart.
	 *
	 * Payload: product_id, items = JSON array of
	 * { token, size, paper, qty, orientation, border, crop:{x,y,w,h,rotation} }.
	 */
	public static function add_to_cart() {
		check_ajax_referer( 'prrint_studio', 'nonce' );

		if ( null === WC()->cart ) {
			wp_send_json_error( array( 'message' => __( 'Cart unavailable. Please reload the page.', 'prrint' ) ) );
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$product    = wc_get_product( $product_id );

		if ( ! $product || ! prrint_is_enabled( $product ) ) {
			wp_send_json_error( array( 'message' => __( 'This product does not support photo prints.', 'prrint' ) ) );
		}
		if ( ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			wp_send_json_error( array( 'message' => __( 'This product is currently unavailable.', 'prrint' ) ) );
		}

		$items_raw = isset( $_POST['items'] ) ? json_decode( wp_unslash( $_POST['items'] ), true ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! is_array( $items_raw ) || empty( $items_raw ) || count( $items_raw ) > self::MAX_ITEMS ) {
			wp_send_json_error( array( 'message' => __( 'No prints to add.', 'prrint' ) ) );
		}

		$prepared = array();
		foreach ( $items_raw as $raw ) {
			$item = self::sanitize_item( $raw, $product_id );
			if ( is_wp_error( $item ) ) {
				wp_send_json_error( array( 'message' => $item->get_error_message() ) );
			}
			$prepared[] = $item;
		}

		$settings = prrint_settings();
		$added    = 0;

		foreach ( $prepared as $item ) {
			$qty  = $item['qty'];
			$data = $item['data'];

			// Server-rendered preview of the actual crop for cart/admin display.
			$preview_rel  = 'previews/' . wp_generate_password( 20, false, false ) . '.jpg';
			$preview_opts = array();
			if ( $data['border'] || ! empty( $data['design']['border']['enabled'] ) ) {
				$preview_opts = array(
					'border_in' => $settings['border_in'],
					'w_in'      => $data['w_in'],
					'h_in'      => $data['h_in'],
				);
			}
			$rendered = Prrint_Image::render(
				prrint_file_path( $data['file'] ),
				$data['crop'],
				$data['rotation'],
				400,
				400,
				prrint_file_path( $preview_rel ),
				$preview_opts,
				$data['design']
			);
			$data['preview'] = $rendered ? $preview_rel : '';
			$data['unique']  = md5( $data['token'] . wp_rand() . microtime( true ) );

			$key = WC()->cart->add_to_cart( $product_id, $qty, 0, array(), array( 'prrint' => $data ) );
			if ( $key ) {
				$added++;
			}
		}

		if ( 0 === $added ) {
			$notices = wc_get_notices( 'error' );
			wc_clear_notices();
			$message = ! empty( $notices ) ? wp_strip_all_tags( $notices[0]['notice'] ) : __( 'Could not add to cart.', 'prrint' );
			wp_send_json_error( array( 'message' => $message ) );
		}

		wc_clear_notices();

		wp_send_json_success( array(
			'added'      => $added,
			'cart_url'   => wc_get_cart_url(),
			'cart_count' => WC()->cart->get_cart_contents_count(),
		) );
	}

	/**
	 * Validate one studio item from the client.
	 *
	 * @return array|WP_Error { qty: int, data: array }
	 */
	protected static function sanitize_item( $raw, $product_id ) {
		if ( ! is_array( $raw ) ) {
			return new WP_Error( 'prrint', __( 'Invalid print item.', 'prrint' ) );
		}

		$token  = isset( $raw['token'] ) ? preg_replace( '/[^a-zA-Z0-9]/', '', (string) $raw['token'] ) : '';
		$upload = $token ? get_transient( 'prrint_up_' . $token ) : false;
		if ( ! $upload ) {
			return new WP_Error( 'prrint', __( 'One of your photos has expired — please re-upload it.', 'prrint' ) );
		}

		$sizes  = prrint_get_sizes( $product_id );
		$papers = prrint_get_papers( $product_id );

		$size_idx  = isset( $raw['size'] ) ? absint( $raw['size'] ) : 0;
		$paper_idx = isset( $raw['paper'] ) ? absint( $raw['paper'] ) : 0;
		if ( ! isset( $sizes[ $size_idx ] ) || ! isset( $papers[ $paper_idx ] ) ) {
			return new WP_Error( 'prrint', __( 'Invalid print options selected.', 'prrint' ) );
		}

		$qty = isset( $raw['qty'] ) ? absint( $raw['qty'] ) : 1;
		$qty = max( 1, min( 999, $qty ) );

		$orientation = ( isset( $raw['orientation'] ) && 'landscape' === $raw['orientation'] ) ? 'landscape' : 'portrait';
		$border      = ! empty( $raw['border'] );

		$crop_raw = isset( $raw['crop'] ) && is_array( $raw['crop'] ) ? $raw['crop'] : null;
		if ( ! $crop_raw ) {
			return new WP_Error( 'prrint', __( 'A crop selection is missing — please edit that photo again.', 'prrint' ) );
		}
		foreach ( array( 'x', 'y', 'w', 'h' ) as $k ) {
			if ( ! isset( $crop_raw[ $k ] ) || ! is_numeric( $crop_raw[ $k ] ) ) {
				return new WP_Error( 'prrint', __( 'A crop selection is invalid — please edit that photo again.', 'prrint' ) );
			}
		}

		$size  = $sizes[ $size_idx ];
		$paper = $papers[ $paper_idx ];

		$w_in = 'landscape' === $orientation ? max( $size['w'], $size['h'] ) : min( $size['w'], $size['h'] );
		$h_in = 'landscape' === $orientation ? min( $size['w'], $size['h'] ) : max( $size['w'], $size['h'] );

		return array(
			'qty'  => $qty,
			'data' => array(
				'token'       => $token,
				'file'        => $upload['file'],
				'size_label'  => $size['label'],
				'size_price'  => (float) $size['price'],
				'w_in'        => (float) $w_in,
				'h_in'        => (float) $h_in,
				'paper_label' => $paper['label'],
				'surcharge'   => (float) $paper['surcharge'],
				'orientation' => $orientation,
				'border'      => $border,
				'design'      => isset( $raw['design'] ) ? self::sanitize_design( $raw['design'] ) : null,
				'rotation'    => isset( $crop_raw['rotation'] ) ? absint( $crop_raw['rotation'] ) % 4 : 0,
				'crop'        => array(
					'x' => (float) $crop_raw['x'],
					'y' => (float) $crop_raw['y'],
					'w' => max( 1.0, (float) $crop_raw['w'] ),
					'h' => max( 1.0, (float) $crop_raw['h'] ),
				),
			),
		);
	}

	/**
	 * Validate an editor "design" payload (filter/adjust/border/text layers).
	 * Returns null when there is nothing in it worth keeping.
	 *
	 * @return array|null
	 */
	protected static function sanitize_design( $raw ) {
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$allowed_filters = array( 'bw', 'warm', 'cold', 'vintage', 'duotone', 'legacy', 'smooth' );
		$filter           = isset( $raw['filter'] ) && in_array( $raw['filter'], $allowed_filters, true ) ? $raw['filter'] : null;

		$adjust = array(
			'brightness' => 0.0,
			'contrast'   => 0.0,
			'saturation' => 100.0,
			'gamma'      => 0.0,
			'exposure'   => 0.0,
			'clarity'    => 0.0,
			'shadows'    => 0.0,
			'highlights' => 0.0,
		);
		$has_adjust = false;
		if ( isset( $raw['adjust'] ) && is_array( $raw['adjust'] ) ) {
			foreach ( array( 'brightness', 'contrast', 'gamma', 'exposure', 'shadows', 'highlights' ) as $key ) {
				if ( isset( $raw['adjust'][ $key ] ) ) {
					$adjust[ $key ] = max( -100, min( 100, (float) $raw['adjust'][ $key ] ) );
				}
			}
			if ( isset( $raw['adjust']['saturation'] ) ) {
				$adjust['saturation'] = max( 0, min( 100, (float) $raw['adjust']['saturation'] ) );
			}
			if ( isset( $raw['adjust']['clarity'] ) ) {
				$adjust['clarity'] = max( 0, min( 100, (float) $raw['adjust']['clarity'] ) );
			}
			$has_adjust = (
				0.0 !== $adjust['brightness'] || 0.0 !== $adjust['contrast'] || 100.0 !== $adjust['saturation'] ||
				0.0 !== $adjust['gamma'] || 0.0 !== $adjust['exposure'] || 0.0 !== $adjust['clarity'] ||
				0.0 !== $adjust['shadows'] || 0.0 !== $adjust['highlights']
			);
		}

		$border = array(
			'enabled'  => false,
			'color'    => '#ffffff',
			'width_in' => 0.25,
		);
		if ( isset( $raw['border'] ) && is_array( $raw['border'] ) ) {
			$border['enabled']  = ! empty( $raw['border']['enabled'] );
			$border['color']    = self::sanitize_hex_color( isset( $raw['border']['color'] ) ? $raw['border']['color'] : '#ffffff' );
			$border['width_in'] = isset( $raw['border']['width_in'] ) ? max( 0.05, min( 2, (float) $raw['border']['width_in'] ) ) : 0.25;
		}

		$allowed_shapes = array( 'circle', 'square', 'star', 'heart', 'arrow', 'line' );

		$layers = array();
		if ( isset( $raw['layers'] ) && is_array( $raw['layers'] ) ) {
			foreach ( array_slice( $raw['layers'], 0, 20 ) as $layer_raw ) {
				if ( ! is_array( $layer_raw ) ) {
					continue;
				}
				$type = isset( $layer_raw['type'] ) ? $layer_raw['type'] : '';

				if ( 'text' === $type ) {
					$text = isset( $layer_raw['text'] ) ? wp_strip_all_tags( (string) $layer_raw['text'] ) : '';
					$text = mb_substr( $text, 0, 500 );
					if ( '' === trim( $text ) ) {
						continue;
					}
					$align    = isset( $layer_raw['align'] ) ? $layer_raw['align'] : '';
					$layers[] = array(
						'type'        => 'text',
						'text'        => $text,
						'fontSize'    => isset( $layer_raw['fontSize'] ) ? max( 0.01, min( 0.5, (float) $layer_raw['fontSize'] ) ) : 0.06,
						'bold'        => ! empty( $layer_raw['bold'] ),
						'align'       => in_array( $align, array( 'left', 'center', 'right' ), true ) ? $align : 'center',
						'color'       => self::sanitize_hex_color( isset( $layer_raw['color'] ) ? $layer_raw['color'] : '#ffffff' ),
						'bgColor'     => empty( $layer_raw['bgColor'] ) ? '' : self::sanitize_hex_color( $layer_raw['bgColor'] ),
						'lineSpacing' => isset( $layer_raw['lineSpacing'] ) ? max( 0.8, min( 3, (float) $layer_raw['lineSpacing'] ) ) : 1.3,
						'x'           => isset( $layer_raw['x'] ) ? max( -0.5, min( 1.5, (float) $layer_raw['x'] ) ) : 0.1,
						'y'           => isset( $layer_raw['y'] ) ? max( -0.5, min( 1.5, (float) $layer_raw['y'] ) ) : 0.1,
						'w'           => isset( $layer_raw['w'] ) ? max( 0.05, min( 1.5, (float) $layer_raw['w'] ) ) : 0.8,
						'rotation'    => isset( $layer_raw['rotation'] ) ? max( -180, min( 180, (float) $layer_raw['rotation'] ) ) : 0,
					);
				} elseif ( 'shape' === $type ) {
					$shape = isset( $layer_raw['shape'] ) ? $layer_raw['shape'] : '';
					if ( ! in_array( $shape, $allowed_shapes, true ) ) {
						continue;
					}
					$layers[] = array(
						'type'     => 'shape',
						'shape'    => $shape,
						'color'    => self::sanitize_hex_color( isset( $layer_raw['color'] ) ? $layer_raw['color'] : '#000000' ),
						'x'        => isset( $layer_raw['x'] ) ? max( -0.5, min( 1.5, (float) $layer_raw['x'] ) ) : 0.3,
						'y'        => isset( $layer_raw['y'] ) ? max( -0.5, min( 1.5, (float) $layer_raw['y'] ) ) : 0.3,
						'w'        => isset( $layer_raw['w'] ) ? max( 0.02, min( 1.5, (float) $layer_raw['w'] ) ) : 0.2,
						'h'        => isset( $layer_raw['h'] ) ? max( 0.02, min( 1.5, (float) $layer_raw['h'] ) ) : 0.2,
						'rotation' => isset( $layer_raw['rotation'] ) ? max( -180, min( 180, (float) $layer_raw['rotation'] ) ) : 0,
					);
				}
			}
		}

		$drawing = self::sanitize_drawing( isset( $raw['drawing'] ) ? $raw['drawing'] : null );

		$allowed_overlays = array( 'vignette', 'glow', 'lightleak', 'grain', 'bokeh', 'scratches' );
		$overlay          = isset( $raw['overlay'] ) && in_array( $raw['overlay'], $allowed_overlays, true ) ? $raw['overlay'] : '';

		if ( ! $filter && ! $has_adjust && ! $border['enabled'] && empty( $layers ) && ! $drawing && ! $overlay ) {
			return null;
		}

		$design = array(
			'filter'  => $filter,
			'adjust'  => $adjust,
			'border'  => $border,
			'layers'  => $layers,
			'overlay' => $overlay,
		);
		if ( $drawing ) {
			$design['drawing'] = $drawing;
		}

		return $design;
	}

	/**
	 * Decode and store the editor's freehand doodle layer, sent as a
	 * `data:image/png;base64,...` string embedded in the design payload
	 * (kept out of the multipart upload endpoint to avoid a second
	 * request mid-edit). Returns { file: <relative path> } or null.
	 */
	protected static function sanitize_drawing( $raw ) {
		if ( ! is_array( $raw ) || empty( $raw['dataUrl'] ) || ! is_string( $raw['dataUrl'] ) ) {
			return null;
		}

		if ( 0 !== strpos( $raw['dataUrl'], 'data:image/png;base64,' ) ) {
			return null;
		}

		$b64 = substr( $raw['dataUrl'], strlen( 'data:image/png;base64,' ) );
		if ( strlen( $b64 ) > 4 * 1024 * 1024 ) { // ~3MB decoded ceiling.
			return null;
		}

		$bytes = base64_decode( $b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( ! $bytes ) {
			return null;
		}

		$dir      = prrint_upload_dir( 'tmp/drawings' );
		$filename = wp_generate_password( 24, false, false ) . '.png';
		$dest     = trailingslashit( $dir['path'] ) . $filename;
		if ( ! @file_put_contents( $dest, $bytes ) ) { // phpcs:ignore
			return null;
		}

		$info = @getimagesize( $dest ); // phpcs:ignore
		if ( ! $info || 'image/png' !== $info['mime'] ) {
			@unlink( $dest ); // phpcs:ignore
			return null;
		}

		return array( 'file' => 'tmp/drawings/' . $filename );
	}

	/**
	 * Validate a "#rrggbb"/"rrggbb"/"#rgb" color string, falling back to white.
	 */
	protected static function sanitize_hex_color( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		return ( 6 === strlen( $hex ) && ctype_xdigit( $hex ) ) ? '#' . strtolower( $hex ) : '#ffffff';
	}
}
