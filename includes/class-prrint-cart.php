<?php
/**
 * Cart, pricing and checkout/order integration.
 *
 * @package prrint
 */

defined( 'ABSPATH' ) || exit;

class Prrint_Cart {

	public static function init() {
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'get_item_data' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_thumbnail', array( __CLASS__, 'cart_thumbnail' ), 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'set_prices' ), 20 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'order_line_item' ), 10, 4 );
	}

	public static function get_item_data( $item_data, $cart_item ) {
		if ( empty( $cart_item['prrint'] ) ) {
			return $item_data;
		}
		$v = $cart_item['prrint'];

		$item_data[] = array(
			'key'   => __( 'Print size', 'prrint' ),
			'value' => sprintf( '%s (%s" × %s")', $v['size_label'], wc_format_localized_decimal( $v['w_in'] ), wc_format_localized_decimal( $v['h_in'] ) ),
		);
		$item_data[] = array(
			'key'   => __( 'Paper', 'prrint' ),
			'value' => $v['paper_label'],
		);
		if ( ! empty( $v['border'] ) ) {
			$item_data[] = array(
				'key'   => __( 'Finish', 'prrint' ),
				'value' => __( 'White border', 'prrint' ),
			);
		}

		return $item_data;
	}

	public static function cart_thumbnail( $thumbnail, $cart_item ) {
		if ( empty( $cart_item['prrint']['preview'] ) ) {
			return $thumbnail;
		}
		return sprintf(
			'<img src="%s" alt="%s" class="prrint-cart-thumb" style="max-width:80px;height:auto;border-radius:3px;box-shadow:0 1px 3px rgba(0,0,0,.2);" />',
			esc_url( prrint_file_url( $cart_item['prrint']['preview'] ) ),
			esc_attr__( 'Your print preview', 'prrint' )
		);
	}

	public static function set_prices( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		if ( did_action( 'woocommerce_before_calculate_totals' ) >= 2 ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['prrint'] ) ) {
				continue;
			}
			$v    = $cart_item['prrint'];
			$base = $v['size_price'] > 0 ? (float) $v['size_price'] : (float) $cart_item['data']->get_price( 'edit' );
			$cart_item['data']->set_price( $base + (float) $v['surcharge'] );
		}
	}

	public static function order_line_item( $item, $cart_item_key, $values, $order ) {
		if ( empty( $values['prrint'] ) ) {
			return;
		}
		$v        = $values['prrint'];
		$settings = prrint_settings();

		$item->add_meta_data(
			__( 'Print size', 'prrint' ),
			sprintf( '%s (%s" × %s")', $v['size_label'], wc_format_localized_decimal( $v['w_in'] ), wc_format_localized_decimal( $v['h_in'] ) )
		);
		$item->add_meta_data( __( 'Paper', 'prrint' ), $v['paper_label'] );
		if ( ! empty( $v['border'] ) ) {
			$item->add_meta_data( __( 'Finish', 'prrint' ), __( 'White border', 'prrint' ) );
		}

		// Copy the customer's source photo into permanent storage — the tmp
		// area is purged by the daily cleanup.
		prrint_upload_dir( 'orders' ); // Ensure the directory exists.
		$src_abs    = prrint_file_path( $v['file'] );
		$ext        = pathinfo( $v['file'], PATHINFO_EXTENSION );
		$source_rel = 'orders/' . wp_generate_password( 24, false, false ) . '.' . $ext;
		$copied     = @copy( $src_abs, prrint_file_path( $source_rel ) ); // phpcs:ignore

		// Generate the print-ready file at the configured DPI.
		$dpi       = (int) $settings['target_dpi'];
		$target_w  = (int) round( $v['w_in'] * $dpi );
		$target_h  = (int) round( $v['h_in'] * $dpi );
		$print_rel = 'orders/' . wp_generate_password( 24, false, false ) . '.jpg';

		$design      = ! empty( $v['design'] ) ? $v['design'] : null;
		$has_border  = ! empty( $v['border'] ) || ! empty( $design['border']['enabled'] );
		$opts        = array();
		if ( $has_border ) {
			$opts = array(
				'border_in' => $settings['border_in'],
				'w_in'      => $v['w_in'],
				'h_in'      => $v['h_in'],
			);
		}

		$rendered = Prrint_Image::render(
			$src_abs,
			$v['crop'],
			$v['rotation'],
			$target_w,
			$target_h,
			prrint_file_path( $print_rel ),
			$opts,
			$design
		);

		$item->add_meta_data( '_prrint_source_file', $copied ? $source_rel : $v['file'] );
		$item->add_meta_data( '_prrint_crop', wp_json_encode( array_merge( $v['crop'], array( 'rotation' => $v['rotation'] ) ) ) );
		if ( $rendered ) {
			$item->add_meta_data( '_prrint_print_file', $print_rel );
		}
		if ( ! empty( $v['preview'] ) ) {
			$item->add_meta_data( '_prrint_preview', $v['preview'] );
		}
		if ( $design ) {
			$item->add_meta_data( '_prrint_design', wp_json_encode( $design ) );
		}

		// Full snapshot of the pricing/crop/design inputs so "My Prints" in
		// the customer's account can offer an exact one-click reorder later.
		$item->add_meta_data( '_prrint_reorder', wp_json_encode( array(
			'size_label'  => $v['size_label'],
			'size_price'  => $v['size_price'],
			'w_in'        => $v['w_in'],
			'h_in'        => $v['h_in'],
			'paper_label' => $v['paper_label'],
			'surcharge'   => $v['surcharge'],
			'orientation' => $v['orientation'],
			'border'      => $v['border'],
			'design'      => $design,
			'rotation'    => $v['rotation'],
			'crop'        => $v['crop'],
		) ) );
	}
}
