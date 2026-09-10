<?php
/**
 * Admin order tools: per-item download buttons, order metabox, ZIP export.
 *
 * @package prrint
 */

defined( 'ABSPATH' ) || exit;

class Prrint_Orders {

	public static function init() {
		add_action( 'woocommerce_after_order_itemmeta', array( __CLASS__, 'item_buttons' ), 10, 2 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'metabox' ), 30 );
		add_action( 'admin_post_prrint_zip', array( __CLASS__, 'download_zip' ) );
	}

	/**
	 * Collect prrint items on an order.
	 *
	 * @param WC_Order $order Order.
	 * @return array[] Each: item (WC_Order_Item_Product), print, source, preview (relative paths).
	 */
	protected static function collect_items( $order ) {
		$found = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$print = $item->get_meta( '_prrint_print_file' );
			$src   = $item->get_meta( '_prrint_source_file' );
			if ( ! $print && ! $src ) {
				continue;
			}
			$found[] = array(
				'item'    => $item,
				'print'   => $print,
				'source'  => $src,
				'preview' => $item->get_meta( '_prrint_preview' ),
			);
		}
		return $found;
	}

	/**
	 * Download buttons under each line item on the admin order screen.
	 */
	public static function item_buttons( $item_id, $item ) {
		if ( ! is_admin() || ! current_user_can( 'manage_woocommerce' ) || ! $item instanceof WC_Order_Item_Product ) {
			return;
		}

		$print   = $item->get_meta( '_prrint_print_file' );
		$source  = $item->get_meta( '_prrint_source_file' );
		$preview = $item->get_meta( '_prrint_preview' );

		if ( ! $print && ! $source ) {
			return;
		}

		echo '<div class="prrint-admin-files" style="margin-top:6px;">';
		if ( $preview ) {
			printf(
				'<img src="%s" alt="" style="max-width:56px;height:auto;vertical-align:middle;margin-right:8px;border-radius:3px;" />',
				esc_url( prrint_file_url( $preview ) )
			);
		}
		if ( $print ) {
			printf(
				'<a href="%s" target="_blank" rel="noopener" class="button button-small">%s</a> ',
				esc_url( prrint_file_url( $print ) ),
				esc_html__( 'Print-ready file', 'prrint' )
			);
		}
		if ( $source ) {
			printf(
				'<a href="%s" target="_blank" rel="noopener" class="button button-small">%s</a>',
				esc_url( prrint_file_url( $source ) ),
				esc_html__( 'Original upload', 'prrint' )
			);
		}
		echo '</div>';
	}

	/**
	 * "Prrint files" metabox on the order edit screen (classic and HPOS).
	 */
	public static function metabox() {
		$screens = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}

		foreach ( array_unique( $screens ) as $screen ) {
			add_meta_box(
				'prrint-order-files',
				__( 'Prrint — Print files', 'prrint' ),
				array( __CLASS__, 'render_metabox' ),
				$screen,
				'side',
				'default'
			);
		}
	}

	public static function render_metabox( $object ) {
		$order = $object instanceof WC_Order ? $object : wc_get_order( is_object( $object ) ? $object->ID : $object );
		if ( ! $order ) {
			return;
		}

		$items = self::collect_items( $order );
		if ( empty( $items ) ) {
			echo '<p>' . esc_html__( 'No photo prints on this order.', 'prrint' ) . '</p>';
			return;
		}

		echo '<ul style="margin:0;">';
		foreach ( $items as $i => $row ) {
			echo '<li style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">';
			if ( $row['preview'] ) {
				printf( '<img src="%s" alt="" style="width:40px;height:auto;border-radius:3px;" />', esc_url( prrint_file_url( $row['preview'] ) ) );
			}
			printf(
				'<span style="flex:1;">%s ×%d</span>',
				esc_html( $row['item']->get_meta( __( 'Print size', 'prrint' ) ) ?: $row['item']->get_name() ),
				(int) $row['item']->get_quantity()
			);
			if ( $row['print'] ) {
				printf(
					'<a href="%s" target="_blank" rel="noopener" class="button button-small">%s</a>',
					esc_url( prrint_file_url( $row['print'] ) ),
					esc_html__( 'File', 'prrint' )
				);
			}
			echo '</li>';
		}
		echo '</ul>';

		if ( class_exists( 'ZipArchive' ) ) {
			$url = wp_nonce_url(
				admin_url( 'admin-post.php?action=prrint_zip&order_id=' . $order->get_id() ),
				'prrint_zip_' . $order->get_id()
			);
			printf(
				'<p style="margin-bottom:0;"><a href="%s" class="button button-primary" style="width:100%%;text-align:center;">%s</a></p>',
				esc_url( $url ),
				esc_html__( 'Download all as ZIP', 'prrint' )
			);
		}
	}

	/**
	 * Stream a ZIP of all print-ready files for one order.
	 */
	public static function download_zip() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'prrint' ) );
		}

		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		check_admin_referer( 'prrint_zip_' . $order_id );

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'prrint' ) );
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( esc_html__( 'ZipArchive is not available on this server.', 'prrint' ) );
		}

		$items = self::collect_items( $order );
		if ( empty( $items ) ) {
			wp_die( esc_html__( 'No print files on this order.', 'prrint' ) );
		}

		$tmp = wp_tempnam( 'prrint-order-' . $order_id );
		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::OVERWRITE ) ) {
			wp_die( esc_html__( 'Could not create the ZIP file.', 'prrint' ) );
		}

		$n = 0;
		foreach ( $items as $row ) {
			if ( ! $row['print'] ) {
				continue;
			}
			$abs = prrint_file_path( $row['print'] );
			if ( ! file_exists( $abs ) ) {
				continue;
			}
			$n++;
			$size = preg_replace( '/[^0-9a-zA-Z.x×-]/u', '', (string) $row['item']->get_meta( __( 'Print size', 'prrint' ) ) );
			$name = sprintf( 'item-%02d-%s-x%d.jpg', $n, $size ?: 'print', (int) $row['item']->get_quantity() );
			$zip->addFile( $abs, $name );
		}
		$zip->close();

		if ( 0 === $n ) {
			@unlink( $tmp ); // phpcs:ignore
			wp_die( esc_html__( 'No print files on this order.', 'prrint' ) );
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="order-' . $order_id . '-prints.zip"' );
		header( 'Content-Length: ' . filesize( $tmp ) );
		readfile( $tmp ); // phpcs:ignore
		@unlink( $tmp ); // phpcs:ignore
		exit;
	}
}
