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
			if ( $data['border'] ) {
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
				$preview_opts
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
}
