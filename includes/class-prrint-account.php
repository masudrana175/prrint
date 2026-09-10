<?php
/**
 * Customer "My Account" integration: print/order history and a persistent
 * saved-photos library, plus the AJAX endpoints (reorder, delete photo)
 * that back them.
 *
 * @package prrint
 */

defined( 'ABSPATH' ) || exit;

class Prrint_Account {

	const MAX_LIBRARY = 300;
	const PER_PAGE     = 10;

	public static function init() {
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu_items' ) );
		add_action( 'woocommerce_account_prrint-prints_endpoint', array( __CLASS__, 'render_prints_tab' ) );
		add_action( 'woocommerce_account_prrint-photos_endpoint', array( __CLASS__, 'render_photos_tab' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );

		add_action( 'wp_ajax_prrint_reorder', array( __CLASS__, 'reorder' ) );
		add_action( 'wp_ajax_prrint_delete_photo', array( __CLASS__, 'delete_photo' ) );
		add_action( 'wp_ajax_prrint_get_library', array( __CLASS__, 'get_library_json' ) );
		add_action( 'wp_ajax_prrint_use_library_photo', array( __CLASS__, 'use_library_photo' ) );
	}

	public static function menu_items( $items ) {
		if ( ! is_user_logged_in() ) {
			return $items;
		}

		$new = array();
		foreach ( $items as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'orders' === $key ) {
				$new['prrint-prints'] = __( 'My Prints', 'prrint' );
				$new['prrint-photos'] = __( 'My Photos', 'prrint' );
			}
		}
		// Orders tab missing (theme customized the menu) — append at the end.
		if ( ! isset( $new['prrint-prints'] ) ) {
			$new['prrint-prints'] = __( 'My Prints', 'prrint' );
			$new['prrint-photos'] = __( 'My Photos', 'prrint' );
		}

		return $new;
	}

	protected static function on_account_endpoint() {
		return function_exists( 'is_wc_endpoint_url' )
			&& ( is_wc_endpoint_url( 'prrint-prints' ) || is_wc_endpoint_url( 'prrint-photos' ) );
	}

	public static function enqueue() {
		if ( ! self::on_account_endpoint() ) {
			return;
		}

		wp_enqueue_style( 'prrint-account', PRRINT_URL . 'assets/css/account.css', array(), PRRINT_VERSION );
		wp_enqueue_script( 'prrint-account', PRRINT_URL . 'assets/js/account.js', array(), PRRINT_VERSION, true );

		wp_localize_script( 'prrint-account', 'prrintAccount', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'prrint_studio' ),
			'i18n'    => array(
				'confirmDelete' => __( 'Remove this photo from your saved photos? This cannot be undone.', 'prrint' ),
				'reordering'    => __( 'Adding to cart…', 'prrint' ),
				'reorder'       => __( 'Reorder', 'prrint' ),
				'genericError'  => __( 'Something went wrong. Please try again.', 'prrint' ),
			),
		) );
	}

	/* ---------------------------------------------------- photo library */

	/**
	 * Copy a freshly uploaded photo into the logged-in customer's permanent
	 * library so it survives past the temp-upload cleanup and can be reused
	 * on a future order without re-uploading.
	 *
	 * @param string $relative  Path of the tmp upload, relative to uploads/prrint/.
	 * @param int    $width     Image width in px.
	 * @param int    $height    Image height in px.
	 * @param string $orig_name Original client-side filename (display only).
	 */
	public static function save_upload_to_library( $relative, $width, $height, $orig_name = '' ) {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id = get_current_user_id();
		$library = get_user_meta( $user_id, 'prrint_photo_library', true );
		if ( ! is_array( $library ) ) {
			$library = array();
		}
		if ( count( $library ) >= self::MAX_LIBRARY ) {
			return;
		}

		$src_abs = prrint_file_path( $relative );
		$ext     = strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) );

		prrint_upload_dir( 'library/' . $user_id );
		$dest_rel = 'library/' . $user_id . '/' . wp_generate_password( 24, false, false ) . '.' . $ext;
		if ( ! @copy( $src_abs, prrint_file_path( $dest_rel ) ) ) { // phpcs:ignore
			return;
		}

		$preview_rel = 'library/' . $user_id . '/' . wp_generate_password( 20, false, false ) . '-thumb.jpg';
		Prrint_Image::render(
			$src_abs,
			array(
				'x' => 0,
				'y' => 0,
				'w' => $width,
				'h' => $height,
			),
			0,
			320,
			320,
			prrint_file_path( $preview_rel )
		);

		$library[] = array(
			'id'      => wp_generate_password( 12, false, false ),
			'file'    => $dest_rel,
			'preview' => $preview_rel,
			'width'   => (int) $width,
			'height'  => (int) $height,
			'name'    => sanitize_file_name( $orig_name ),
			'added'   => time(),
		);

		update_user_meta( $user_id, 'prrint_photo_library', $library );
	}

	public static function delete_photo() {
		check_ajax_referer( 'prrint_studio', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Please log in.', 'prrint' ) ) );
		}

		$id      = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$user_id = get_current_user_id();
		$library = get_user_meta( $user_id, 'prrint_photo_library', true );
		if ( ! is_array( $library ) ) {
			$library = array();
		}

		$kept    = array();
		$deleted = false;
		foreach ( $library as $row ) {
			if ( isset( $row['id'] ) && $row['id'] === $id ) {
				if ( ! empty( $row['file'] ) ) {
					@unlink( prrint_file_path( $row['file'] ) ); // phpcs:ignore
				}
				if ( ! empty( $row['preview'] ) ) {
					@unlink( prrint_file_path( $row['preview'] ) ); // phpcs:ignore
				}
				$deleted = true;
				continue;
			}
			$kept[] = $row;
		}

		if ( ! $deleted ) {
			wp_send_json_error( array( 'message' => __( 'Photo not found.', 'prrint' ) ) );
		}

		update_user_meta( $user_id, 'prrint_photo_library', $kept );
		wp_send_json_success();
	}

	/**
	 * A user's saved-photo library, newest first. Shared by the My Account
	 * tab and the studio page's "Your Photos" section.
	 *
	 * @return array[] Rows of { id, file, preview, width, height, name, added }.
	 */
	public static function get_library_rows( $user_id ) {
		$library = get_user_meta( $user_id, 'prrint_photo_library', true );
		if ( ! is_array( $library ) ) {
			$library = array();
		}
		return array_reverse( $library );
	}

	/**
	 * AJAX: re-fetch the current user's library as JSON — backs the studio
	 * page's Refresh button (e.g. after uploading from another tab/device).
	 */
	public static function get_library_json() {
		check_ajax_referer( 'prrint_studio', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Please log in.', 'prrint' ) ) );
		}

		$rows = array_map( array( __CLASS__, 'library_row_for_js' ), self::get_library_rows( get_current_user_id() ) );
		wp_send_json_success( array( 'photos' => array_values( $rows ) ) );
	}

	protected static function library_row_for_js( $row ) {
		return array(
			'id'      => $row['id'],
			'preview' => prrint_file_url( ! empty( $row['preview'] ) ? $row['preview'] : $row['file'] ),
			'file'    => prrint_file_url( $row['file'] ),
			'added'   => (int) $row['added'],
		);
	}

	/**
	 * AJAX: turn a saved library photo into a fresh upload token, so the
	 * studio can build a new item card from it exactly like a new upload —
	 * no file copy needed, the token just points at the library's own
	 * permanent copy instead of a tmp/ upload.
	 */
	public static function use_library_photo() {
		check_ajax_referer( 'prrint_studio', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Please log in.', 'prrint' ) ) );
		}

		$id   = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$rows = self::get_library_rows( get_current_user_id() );
		$row  = null;
		foreach ( $rows as $candidate ) {
			if ( isset( $candidate['id'] ) && $candidate['id'] === $id ) {
				$row = $candidate;
				break;
			}
		}

		if ( ! $row || empty( $row['file'] ) || ! file_exists( prrint_file_path( $row['file'] ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Photo not found.', 'prrint' ) ) );
		}

		$token = wp_generate_password( 32, false, false );
		set_transient( 'prrint_up_' . $token, array(
			'file'   => $row['file'],
			'width'  => (int) $row['width'],
			'height' => (int) $row['height'],
		), WEEK_IN_SECONDS );

		wp_send_json_success( array(
			'token'  => $token,
			'url'    => prrint_file_url( $row['file'] ),
			'width'  => (int) $row['width'],
			'height' => (int) $row['height'],
		) );
	}

	public static function render_photos_tab() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$library = self::get_library_rows( get_current_user_id() );

		echo '<p>' . esc_html__( 'Every photo you upload while signed in is saved here automatically, so you can reuse it on a future order without re-uploading.', 'prrint' ) . '</p>';

		if ( empty( $library ) ) {
			echo '<p>' . esc_html__( "You haven't saved any photos yet — upload one on any Print Studio product page.", 'prrint' ) . '</p>';
			return;
		}

		echo '<div class="prrint-photo-grid">';
		foreach ( $library as $row ) {
			if ( empty( $row['file'] ) || ! file_exists( prrint_file_path( $row['file'] ) ) ) {
				continue;
			}
			$preview = ! empty( $row['preview'] ) && file_exists( prrint_file_path( $row['preview'] ) ) ? $row['preview'] : $row['file'];
			printf(
				'<div class="prrint-photo-tile"><img src="%1$s" alt="" loading="lazy" />' .
				'<div class="prrint-photo-meta">%2$s</div>' .
				'<div class="prrint-photo-tile-actions">' .
				'<a href="%3$s" class="prrint-photo-download" download title="%4$s">⬇</a>' .
				'<button type="button" class="prrint-photo-delete button" data-id="%5$s">%6$s</button>' .
				'</div></div>',
				esc_url( prrint_file_url( $preview ) ),
				esc_html( gmdate( get_option( 'date_format' ), (int) $row['added'] ) ),
				esc_url( prrint_file_url( $row['file'] ) ),
				esc_attr__( 'Download', 'prrint' ),
				esc_attr( $row['id'] ),
				esc_html__( 'Delete', 'prrint' )
			);
		}
		echo '</div>';
	}

	/* -------------------------------------------------------- print history */

	public static function render_prints_tab( $current_page = 1 ) {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$current_page = empty( $current_page ) ? 1 : absint( $current_page );

		$result = wc_get_orders( array(
			'customer_id' => get_current_user_id(),
			'limit'       => self::PER_PAGE,
			'page'        => $current_page,
			'paginate'    => true,
			'orderby'     => 'date',
			'order'       => 'DESC',
		) );
		$orders = is_object( $result ) && isset( $result->orders ) ? $result->orders : array();

		$rows = array();
		foreach ( $orders as $order ) {
			foreach ( $order->get_items() as $item_id => $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) {
					continue;
				}
				$print  = $item->get_meta( '_prrint_print_file' );
				$source = $item->get_meta( '_prrint_source_file' );
				if ( ! $print && ! $source ) {
					continue;
				}
				$rows[] = array(
					'order'   => $order,
					'item'    => $item,
					'item_id' => $item_id,
					'print'   => $print,
					'source'  => $source,
					'preview' => $item->get_meta( '_prrint_preview' ),
				);
			}
		}

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( "You haven't ordered any prints yet.", 'prrint' ) . '</p>';
			return;
		}

		echo '<table class="woocommerce-orders-table prrint-prints-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Photo', 'prrint' ) . '</th>';
		echo '<th>' . esc_html__( 'Order', 'prrint' ) . '</th>';
		echo '<th>' . esc_html__( 'Details', 'prrint' ) . '</th>';
		echo '<th>' . esc_html__( 'Files', 'prrint' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$order = $row['order'];
			$item  = $row['item'];

			echo '<tr>';

			echo '<td>';
			if ( $row['preview'] ) {
				printf( '<img class="prrint-history-thumb" src="%s" alt="" />', esc_url( prrint_file_url( $row['preview'] ) ) );
			}
			echo '</td>';

			$date = $order->get_date_created();
			echo '<td>';
			printf(
				'<a href="%s">#%s</a><br /><small>%s</small><br /><small>%s</small>',
				esc_url( $order->get_view_order_url() ),
				esc_html( $order->get_order_number() ),
				esc_html( wc_get_order_status_name( $order->get_status() ) ),
				esc_html( $date ? wc_format_datetime( $date ) : '' )
			);
			echo '</td>';

			echo '<td>';
			printf(
				'%s<br />%s ×%d',
				esc_html( $item->get_meta( __( 'Print size', 'prrint' ) ) ),
				esc_html( $item->get_meta( __( 'Paper', 'prrint' ) ) ),
				(int) $item->get_quantity()
			);
			echo '</td>';

			echo '<td>';
			if ( $row['print'] ) {
				printf(
					'<a href="%s" class="button" target="_blank" rel="noopener">%s</a> ',
					esc_url( prrint_file_url( $row['print'] ) ),
					esc_html__( 'Print file', 'prrint' )
				);
			}
			if ( $row['source'] ) {
				printf(
					'<a href="%s" class="button" target="_blank" rel="noopener">%s</a>',
					esc_url( prrint_file_url( $row['source'] ) ),
					esc_html__( 'My photo', 'prrint' )
				);
			}
			echo '</td>';

			echo '<td>';
			if ( $item->get_meta( '_prrint_reorder' ) ) {
				printf(
					'<button type="button" class="button alt prrint-reorder-btn" data-order-id="%d" data-item-id="%d">%s</button>',
					(int) $order->get_id(),
					(int) $row['item_id'],
					esc_html__( 'Reorder', 'prrint' )
				);
			}
			echo '</td>';

			echo '</tr>';
		}

		echo '</tbody></table>';

		$max_pages = is_object( $result ) && isset( $result->max_num_pages ) ? (int) $result->max_num_pages : 1;
		if ( $max_pages > 1 ) {
			echo '<div class="woocommerce-pagination prrint-pagination">';
			if ( $current_page > 1 ) {
				printf(
					'<a class="button" href="%s">%s</a> ',
					esc_url( wc_get_endpoint_url( 'prrint-prints', $current_page - 1, wc_get_page_permalink( 'myaccount' ) ) ),
					esc_html__( 'Previous', 'prrint' )
				);
			}
			if ( $current_page < $max_pages ) {
				printf(
					'<a class="button" href="%s">%s</a>',
					esc_url( wc_get_endpoint_url( 'prrint-prints', $current_page + 1, wc_get_page_permalink( 'myaccount' ) ) ),
					esc_html__( 'Next', 'prrint' )
				);
			}
			echo '</div>';
		}
	}

	/**
	 * AJAX: re-add a past print exactly as it was ordered.
	 */
	public static function reorder() {
		check_ajax_referer( 'prrint_studio', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Please log in to reorder.', 'prrint' ) ) );
		}
		if ( null === WC()->cart ) {
			wp_send_json_error( array( 'message' => __( 'Cart unavailable. Please reload the page.', 'prrint' ) ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$item_id  = isset( $_POST['item_id'] ) ? absint( $_POST['item_id'] ) : 0;

		$order = wc_get_order( $order_id );
		if ( ! $order || (int) $order->get_customer_id() !== get_current_user_id() ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'prrint' ) ) );
		}

		$item = $order->get_item( $item_id );
		if ( ! $item instanceof WC_Order_Item_Product ) {
			wp_send_json_error( array( 'message' => __( 'Print not found.', 'prrint' ) ) );
		}

		$source   = $item->get_meta( '_prrint_source_file' );
		$snapshot = json_decode( (string) $item->get_meta( '_prrint_reorder' ), true );
		if ( ! $source || ! $snapshot || ! file_exists( prrint_file_path( $source ) ) ) {
			wp_send_json_error( array( 'message' => __( 'This print can no longer be reordered — please start a new one.', 'prrint' ) ) );
		}

		$product_id = $item->get_product_id();
		$product    = wc_get_product( $product_id );
		if ( ! $product || ! prrint_is_enabled( $product ) || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			wp_send_json_error( array( 'message' => __( 'This product is no longer available for prints.', 'prrint' ) ) );
		}

		foreach ( array( 'size_label', 'size_price', 'w_in', 'h_in', 'paper_label', 'surcharge', 'orientation', 'rotation', 'crop' ) as $k ) {
			if ( ! isset( $snapshot[ $k ] ) ) {
				wp_send_json_error( array( 'message' => __( 'This print can no longer be reordered — please start a new one.', 'prrint' ) ) );
			}
		}

		$settings = prrint_settings();
		$data     = array(
			'token'       => '',
			'file'        => $source,
			'size_label'  => (string) $snapshot['size_label'],
			'size_price'  => (float) $snapshot['size_price'],
			'w_in'        => (float) $snapshot['w_in'],
			'h_in'        => (float) $snapshot['h_in'],
			'paper_label' => (string) $snapshot['paper_label'],
			'surcharge'   => (float) $snapshot['surcharge'],
			'orientation' => 'landscape' === $snapshot['orientation'] ? 'landscape' : 'portrait',
			'border'      => ! empty( $snapshot['border'] ),
			'design'      => isset( $snapshot['design'] ) && is_array( $snapshot['design'] ) ? $snapshot['design'] : null,
			'rotation'    => ( (int) $snapshot['rotation'] ) % 4,
			'crop'        => array(
				'x' => (float) $snapshot['crop']['x'],
				'y' => (float) $snapshot['crop']['y'],
				'w' => max( 1.0, (float) $snapshot['crop']['w'] ),
				'h' => max( 1.0, (float) $snapshot['crop']['h'] ),
			),
		);

		$preview_rel  = 'previews/' . wp_generate_password( 20, false, false ) . '.jpg';
		$preview_opts = array();
		if ( $data['border'] || ! empty( $data['design']['border']['enabled'] ) ) {
			$preview_opts = array(
				'border_in' => $settings['border_in'],
				'w_in'      => $data['w_in'],
				'h_in'      => $data['h_in'],
			);
		}
		$rendered        = Prrint_Image::render(
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
		$data['unique']  = md5( $order_id . '-' . $item_id . wp_rand() . microtime( true ) );

		$qty = max( 1, (int) $item->get_quantity() );
		$key = WC()->cart->add_to_cart( $product_id, $qty, 0, array(), array( 'prrint' => $data ) );

		if ( ! $key ) {
			$notices = wc_get_notices( 'error' );
			wc_clear_notices();
			$message = ! empty( $notices ) ? wp_strip_all_tags( $notices[0]['notice'] ) : __( 'Could not add to cart.', 'prrint' );
			wp_send_json_error( array( 'message' => $message ) );
		}

		wc_clear_notices();
		wp_send_json_success( array( 'cart_url' => wc_get_cart_url() ) );
	}
}
