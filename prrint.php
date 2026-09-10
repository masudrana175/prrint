<?php
/**
 * Plugin Name: Prrint — Photo Print Studio for WooCommerce
 * Plugin URI:  https://github.com/masudrana175/prrint
 * Description: Turn WooCommerce products into a full photo print shop: multi-photo upload, crop/zoom/rotate editor, print sizes, paper finishes, white borders, live pricing, print-quality checks, and 300 DPI print-ready files on every order.
 * Version:     1.0.0
 * Author:      Masud Rana
 * Author URI:  https://github.com/masudrana175
 * Text Domain: prrint
 * Domain Path: /languages
 * License:     GPL-2.0-or-later
 * Requires at least: 5.8
 * Requires PHP: 7.2
 * WC requires at least: 5.0
 */

defined( 'ABSPATH' ) || exit;

define( 'PRRINT_VERSION', '1.0.0' );
define( 'PRRINT_FILE', __FILE__ );
define( 'PRRINT_DIR', plugin_dir_path( __FILE__ ) );
define( 'PRRINT_URL', plugin_dir_url( __FILE__ ) );

/* -------------------------------------------------------------------------
 * WooCommerce HPOS (custom order tables) compatibility.
 * ---------------------------------------------------------------------- */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', PRRINT_FILE, true );
	}
} );

/* -------------------------------------------------------------------------
 * Bootstrap.
 * ---------------------------------------------------------------------- */
add_action( 'plugins_loaded', 'prrint_bootstrap' );

function prrint_bootstrap() {
	load_plugin_textdomain( 'prrint', false, dirname( plugin_basename( PRRINT_FILE ) ) . '/languages' );

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>Prrint</strong>: ' .
				esc_html__( 'WooCommerce must be installed and active for the Photo Print Studio to work.', 'prrint' ) .
				'</p></div>';
		} );
		return;
	}

	require_once PRRINT_DIR . 'includes/class-prrint-image.php';
	require_once PRRINT_DIR . 'includes/class-prrint-settings.php';
	require_once PRRINT_DIR . 'includes/class-prrint-product.php';
	require_once PRRINT_DIR . 'includes/class-prrint-frontend.php';
	require_once PRRINT_DIR . 'includes/class-prrint-ajax.php';
	require_once PRRINT_DIR . 'includes/class-prrint-cart.php';
	require_once PRRINT_DIR . 'includes/class-prrint-orders.php';

	Prrint_Settings::init();
	Prrint_Product::init();
	Prrint_Frontend::init();
	Prrint_Ajax::init();
	Prrint_Cart::init();
	Prrint_Orders::init();
}

/* -------------------------------------------------------------------------
 * Activation / deactivation.
 * ---------------------------------------------------------------------- */
register_activation_hook( PRRINT_FILE, 'prrint_activate' );
register_deactivation_hook( PRRINT_FILE, 'prrint_deactivate' );

function prrint_activate() {
	if ( false === get_option( 'prrint_settings', false ) ) {
		add_option( 'prrint_settings', prrint_default_settings() );
	}

	prrint_upload_dir( 'tmp' );
	prrint_upload_dir( 'orders' );
	prrint_upload_dir( 'previews' );

	if ( ! wp_next_scheduled( 'prrint_daily_cleanup' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'prrint_daily_cleanup' );
	}

	// Create a ready-to-use sample product the first time only.
	if ( ! get_option( 'prrint_sample_product' ) && class_exists( 'WC_Product_Simple' ) ) {
		$product = new WC_Product_Simple();
		$product->set_name( __( 'Photo Prints', 'prrint' ) );
		$product->set_status( 'draft' );
		$product->set_regular_price( '2.99' );
		$product->set_catalog_visibility( 'visible' );
		$product->set_description( __( 'Upload your photos, crop them to the perfect print size, choose your paper finish, and order professional prints.', 'prrint' ) );
		$product_id = $product->save();
		if ( $product_id ) {
			update_post_meta( $product_id, '_prrint_enabled', 'yes' );
			update_option( 'prrint_sample_product', $product_id );
		}
	}

	set_transient( 'prrint_activation_notice', 1, MINUTE_IN_SECONDS * 5 );
}

function prrint_deactivate() {
	wp_clear_scheduled_hook( 'prrint_daily_cleanup' );
}

add_action( 'admin_notices', function () {
	if ( ! get_transient( 'prrint_activation_notice' ) || ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	delete_transient( 'prrint_activation_notice' );
	$sample = (int) get_option( 'prrint_sample_product' );
	echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html__( 'Prrint Photo Print Studio is ready!', 'prrint' ) . '</strong> ';
	printf(
		/* translators: 1: settings link, 2: sample product link */
		esc_html__( 'Configure sizes and papers in %1$s. A sample draft product was created — %2$s to start selling.', 'prrint' ),
		'<a href="' . esc_url( admin_url( 'admin.php?page=prrint-settings' ) ) . '">' . esc_html__( 'Prrint Studio settings', 'prrint' ) . '</a>',
		$sample ? '<a href="' . esc_url( get_edit_post_link( $sample ) ) . '">' . esc_html__( 'publish it', 'prrint' ) . '</a>' : esc_html__( 'enable the Print Studio on any product', 'prrint' )
	);
	echo '</p></div>';
} );

/* -------------------------------------------------------------------------
 * Daily cleanup of expired temporary uploads.
 * ---------------------------------------------------------------------- */
add_action( 'prrint_daily_cleanup', 'prrint_cleanup_tmp' );

function prrint_cleanup_tmp() {
	$dir = prrint_upload_dir( 'tmp' );
	if ( ! is_dir( $dir['path'] ) ) {
		return;
	}
	$cutoff   = time() - ( 8 * DAY_IN_SECONDS );
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir['path'], FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::LEAVES_ONLY
	);
	foreach ( $iterator as $file ) {
		if ( $file->isFile() && 'index.html' !== $file->getFilename() && $file->getMTime() < $cutoff ) {
			@unlink( $file->getPathname() ); // phpcs:ignore
		}
	}
}

/* -------------------------------------------------------------------------
 * Settings and option helpers.
 * ---------------------------------------------------------------------- */

function prrint_default_sizes() {
	return array(
		array( 'label' => '4×6',   'w' => 4,  'h' => 6,  'price' => 2.99 ),
		array( 'label' => '5×7',   'w' => 5,  'h' => 7,  'price' => 4.99 ),
		array( 'label' => '8×10',  'w' => 8,  'h' => 10, 'price' => 9.99 ),
		array( 'label' => '11×14', 'w' => 11, 'h' => 14, 'price' => 19.99 ),
		array( 'label' => '16×20', 'w' => 16, 'h' => 20, 'price' => 34.99 ),
		array( 'label' => '20×30', 'w' => 20, 'h' => 30, 'price' => 59.99 ),
	);
}

function prrint_default_papers() {
	return array(
		array( 'label' => 'Glossy',   'surcharge' => 0 ),
		array( 'label' => 'Luster',   'surcharge' => 0 ),
		array( 'label' => 'Pearl',    'surcharge' => 1.50 ),
		array( 'label' => 'Metallic', 'surcharge' => 3.00 ),
		array( 'label' => 'Fine Art', 'surcharge' => 4.00 ),
	);
}

function prrint_default_settings() {
	return array(
		'sizes'        => prrint_default_sizes(),
		'papers'       => prrint_default_papers(),
		'max_mb'       => 40,
		'jpeg_quality' => 92,
		'target_dpi'   => 300,
		'min_dpi'      => 150,
		'border_in'    => 0.25,
	);
}

/**
 * Current plugin settings merged over defaults.
 *
 * @return array
 */
function prrint_settings() {
	$saved = get_option( 'prrint_settings', array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	$settings = array_merge( prrint_default_settings(), $saved );
	if ( empty( $settings['sizes'] ) || ! is_array( $settings['sizes'] ) ) {
		$settings['sizes'] = prrint_default_sizes();
	}
	if ( empty( $settings['papers'] ) || ! is_array( $settings['papers'] ) ) {
		$settings['papers'] = prrint_default_papers();
	}
	return $settings;
}

/**
 * Whether the print studio is enabled for a product.
 *
 * @param int|WC_Product $product Product or ID.
 * @return bool
 */
function prrint_is_enabled( $product ) {
	$product = is_numeric( $product ) ? wc_get_product( $product ) : $product;
	return $product instanceof WC_Product && 'yes' === $product->get_meta( '_prrint_enabled' );
}

/**
 * Effective print sizes for a product (product override or global).
 *
 * @param int $product_id Product ID.
 * @return array[]
 */
function prrint_get_sizes( $product_id ) {
	if ( 'yes' === get_post_meta( $product_id, '_prrint_override', true ) ) {
		$sizes = get_post_meta( $product_id, '_prrint_sizes', true );
		if ( is_array( $sizes ) && ! empty( $sizes ) ) {
			return array_values( $sizes );
		}
	}
	$settings = prrint_settings();
	return array_values( $settings['sizes'] );
}

/**
 * Effective paper options for a product (product override or global).
 *
 * @param int $product_id Product ID.
 * @return array[]
 */
function prrint_get_papers( $product_id ) {
	if ( 'yes' === get_post_meta( $product_id, '_prrint_override', true ) ) {
		$papers = get_post_meta( $product_id, '_prrint_papers', true );
		if ( is_array( $papers ) && ! empty( $papers ) ) {
			return array_values( $papers );
		}
	}
	$settings = prrint_settings();
	return array_values( $settings['papers'] );
}

/* -------------------------------------------------------------------------
 * Upload directory helpers. Everything lives in uploads/prrint/.
 * ---------------------------------------------------------------------- */

/**
 * @param string $subdir Sub-directory such as 'tmp/2026/09', 'orders', 'previews'.
 * @return array { path: string, url: string }
 */
function prrint_upload_dir( $subdir = '' ) {
	$uploads = wp_upload_dir();
	$path    = trailingslashit( $uploads['basedir'] ) . 'prrint';
	$url     = trailingslashit( $uploads['baseurl'] ) . 'prrint';

	if ( $subdir ) {
		$path .= '/' . ltrim( $subdir, '/' );
		$url  .= '/' . ltrim( $subdir, '/' );
	}

	if ( ! is_dir( $path ) ) {
		wp_mkdir_p( $path );
	}

	$index = trailingslashit( $path ) . 'index.html';
	if ( ! file_exists( $index ) ) {
		@file_put_contents( $index, '' ); // phpcs:ignore
	}

	return array(
		'path' => $path,
		'url'  => $url,
	);
}

/**
 * Absolute path for a file stored relative to uploads/prrint/.
 */
function prrint_file_path( $relative ) {
	$base = prrint_upload_dir();
	return trailingslashit( $base['path'] ) . ltrim( $relative, '/' );
}

/**
 * Public URL for a file stored relative to uploads/prrint/.
 */
function prrint_file_url( $relative ) {
	$base = prrint_upload_dir();
	return trailingslashit( $base['url'] ) . ltrim( $relative, '/' );
}
