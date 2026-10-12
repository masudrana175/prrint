<?php
/**
 * Plugin Name: Prrint — Photo Print Studio for WooCommerce
 * Plugin URI:  https://github.com/masudrana175/prrint
 * Description: Turn WooCommerce products into a full photo print shop: multi-photo upload, crop/zoom/rotate editor, print sizes, paper finishes, white borders, live pricing, print-quality checks, and 300 DPI print-ready files on every order.
 * Version:     1.31.0
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

define( 'PRRINT_VERSION', '1.31.0' );
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

	require_once PRRINT_DIR . 'includes/class-prrint-icons.php';
	require_once PRRINT_DIR . 'includes/class-prrint-fonts.php';
	require_once PRRINT_DIR . 'includes/class-prrint-image.php';
	require_once PRRINT_DIR . 'includes/class-prrint-settings.php';
	require_once PRRINT_DIR . 'includes/class-prrint-product.php';
	require_once PRRINT_DIR . 'includes/class-prrint-frontend.php';
	require_once PRRINT_DIR . 'includes/class-prrint-account.php';
	require_once PRRINT_DIR . 'includes/class-prrint-ajax.php';
	require_once PRRINT_DIR . 'includes/class-prrint-cart.php';
	require_once PRRINT_DIR . 'includes/class-prrint-orders.php';
	require_once PRRINT_DIR . 'includes/class-prrint-framers.php';
	require_once PRRINT_DIR . 'includes/class-prrint-wholesale.php';
	require_once PRRINT_DIR . 'includes/class-prrint-framer-orders.php';

	Prrint_Settings::init();
	Prrint_Product::init();
	Prrint_Frontend::init();
	Prrint_Account::init();
	Prrint_Ajax::init();
	Prrint_Cart::init();
	Prrint_Orders::init();
	Prrint_Framers::init();
	Prrint_Wholesale::init();
	Prrint_Framer_Orders::init();
}

/* -------------------------------------------------------------------------
 * My Account endpoints ("My Prints" order history, "My Photos" library).
 * Registered unconditionally (harmless without WooCommerce) so activation
 * can flush rewrite rules for them.
 * ---------------------------------------------------------------------- */
add_action( 'init', 'prrint_register_endpoints', 5 );

function prrint_register_endpoints() {
	add_rewrite_endpoint( 'prrint-prints', EP_ROOT | EP_PAGES );
	add_rewrite_endpoint( 'prrint-photos', EP_ROOT | EP_PAGES );
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
	prrint_upload_dir( 'library' );

	if ( ! wp_next_scheduled( 'prrint_daily_cleanup' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'prrint_daily_cleanup' );
	}

	prrint_register_endpoints();
	flush_rewrite_rules();

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

	// A standalone design + order page — [prrint_studio] — the way
	// customers reach the studio without visiting a product page at all.
	if ( ! get_option( 'prrint_sample_page' ) ) {
		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'Create Your Print', 'prrint' ),
				'post_name'    => 'create-your-print',
				'post_content' => '[prrint_studio]',
				'post_status'  => 'draft',
				'post_type'    => 'page',
			)
		);
		if ( $page_id && ! is_wp_error( $page_id ) ) {
			update_option( 'prrint_sample_page', $page_id );
		}
	}

	set_transient( 'prrint_activation_notice', 1, MINUTE_IN_SECONDS * 5 );
}

function prrint_deactivate() {
	wp_clear_scheduled_hook( 'prrint_daily_cleanup' );
	flush_rewrite_rules();
}

add_action( 'admin_notices', function () {
	if ( ! get_transient( 'prrint_activation_notice' ) || ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	delete_transient( 'prrint_activation_notice' );
	$sample = (int) get_option( 'prrint_sample_product' );
	$page   = (int) get_option( 'prrint_sample_page' );
	echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html__( 'Prrint Photo Print Studio is ready!', 'prrint' ) . '</strong> ';
	printf(
		/* translators: 1: settings link, 2: sample product link, 3: sample page link */
		esc_html__( 'Configure sizes and papers in %1$s. A sample draft product was created — %2$s to start selling — and a "Create Your Print" page with the design studio was drafted — %3$s when you\'re ready.', 'prrint' ),
		'<a href="' . esc_url( admin_url( 'admin.php?page=prrint-settings' ) ) . '">' . esc_html__( 'Prrint Studio settings', 'prrint' ) . '</a>',
		$sample ? '<a href="' . esc_url( get_edit_post_link( $sample ) ) . '">' . esc_html__( 'publish it', 'prrint' ) . '</a>' : esc_html__( 'enable the Print Studio on any product', 'prrint' ),
		$page ? '<a href="' . esc_url( get_edit_post_link( $page ) ) . '">' . esc_html__( 'publish it', 'prrint' ) . '</a>' : esc_html__( 'add the [prrint_studio] shortcode to a page', 'prrint' )
	);
	echo '</p></div>';
} );

/* -------------------------------------------------------------------------
 * Daily cleanup of expired temporary uploads.
 * ---------------------------------------------------------------------- */
add_action( 'prrint_daily_cleanup', 'prrint_cleanup_tmp' );
add_action( 'prrint_daily_cleanup', 'prrint_cleanup_guest_library' );

function prrint_cleanup_tmp() {
	$dir = prrint_upload_dir( 'tmp' );
	if ( ! is_dir( $dir['path'] ) ) {
		return;
	}
	$days     = max( 1, (int) prrint_settings()['upload_retention_days'] );
	$cutoff   = time() - ( $days * DAY_IN_SECONDS );
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

/**
 * Purge guest (non-logged-in) photo libraries whose retention window has
 * elapsed. Unlike a logged-in customer's library (permanent — tied to a
 * real account), a guest's is only identified by an unguessable cookie
 * anyone can regenerate at will by clearing cookies, so it's kept for a
 * bounded, admin-configurable window instead of forever. Their transient
 * index (`prrint_guest_lib_*`) expires the same way any other transient
 * does, but the files themselves need this explicit sweep — mirrors
 * prrint_cleanup_tmp()'s mtime-cutoff approach, scoped to `library/guest-*`
 * only so logged-in customers' permanent `library/<user_id>/` folders are
 * never touched.
 */
function prrint_cleanup_guest_library() {
	$base = prrint_upload_dir( 'library' );
	if ( ! is_dir( $base['path'] ) ) {
		return;
	}
	$days   = max( 1, (int) prrint_settings()['guest_library_retention_days'] );
	$cutoff = time() - ( $days * DAY_IN_SECONDS );

	foreach ( glob( trailingslashit( $base['path'] ) . 'guest-*', GLOB_ONLYDIR ) as $guest_dir ) {
		$newest = 0;
		foreach ( glob( trailingslashit( $guest_dir ) . '*' ) as $file ) {
			if ( is_file( $file ) ) {
				$newest = max( $newest, filemtime( $file ) );
			}
		}
		// An untouched-since-cutoff folder (or an empty one) gets removed
		// entirely; the matching transient is left to expire on its own.
		if ( 0 === $newest || $newest < $cutoff ) {
			foreach ( glob( trailingslashit( $guest_dir ) . '*' ) as $file ) {
				if ( is_file( $file ) ) {
					@unlink( $file ); // phpcs:ignore
				}
			}
			@rmdir( $guest_dir ); // phpcs:ignore
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

/**
 * Product styles — what the print is made into. "edge" marks styles with a
 * colored (foil) edge, which is what makes the Edge Color dropdown appear;
 * "description" is shown to the customer under the Style dropdown.
 * Descriptions come from ColorPlak's own style sheet.
 */
function prrint_default_styles() {
	return array(
		array( 'label' => 'Paper print', 'surcharge' => 0, 'edge' => false, 'description' => 'A print on photo paper only — no mounting.' ),
		array( 'label' => 'ColorLite',   'surcharge' => 0, 'edge' => true,  'description' => '3/8" board with beveled edge. Our most popular style. Recommended for sizes up to 32" x 40".' ),
		array( 'label' => 'ColorFloat',  'surcharge' => 0, 'edge' => false, 'description' => 'Image mounted on 1/4" board. A 1" box frame is recessed 2" from the edge to make the frame "float". Available in sizes up to 4 feet x 8 feet.' ),
		array( 'label' => 'ColorBox',    'surcharge' => 0, 'edge' => true,  'description' => 'Image mounted on 1/4" board with beveled edge which is laid on top of a 1" back frame. Available in sizes up to 4 feet x 8 feet.' ),
		array( 'label' => 'Hi-Lite',     'surcharge' => 0, 'edge' => false, 'description' => 'Image is mounted on a 1/4" board and then onto a 3/8" base creating a 2" border.' ),
		array( 'label' => 'Canvas',      'surcharge' => 0, 'edge' => false, 'description' => 'Image is transferred to an artist\'s canvas and stretched onto a wood frame.' ),
	);
}

/**
 * ColorPlak's edge color chart ("Edge Colors new one"). Hex values are the
 * average color of each band sampled from the chart itself; marble, wood and
 * textured finishes are represented by their average tone. "exclude" lists
 * styles (comma-separated) a color isn't available for.
 */
function prrint_default_edge_colors() {
	return array(
		array( 'code' => 'A',  'label' => 'Matte Black',         'color' => '#000000', 'exclude' => '' ),
		array( 'code' => 'B',  'label' => 'Matte White',         'color' => '#ffffff', 'exclude' => '' ),
		array( 'code' => 'C',  'label' => 'Black Marble',        'color' => '#131313', 'exclude' => '' ),
		array( 'code' => 'D',  'label' => 'Bright Blue Marble',  'color' => '#004891', 'exclude' => '' ),
		array( 'code' => 'E',  'label' => 'Electric Blue',       'color' => '#014189', 'exclude' => '' ),
		array( 'code' => 'G',  'label' => 'Kelly Green',         'color' => '#006800', 'exclude' => '' ),
		array( 'code' => 'I',  'label' => 'Honey Oak',           'color' => '#b17d4e', 'exclude' => '' ),
		array( 'code' => 'J',  'label' => 'Navy Blue',           'color' => '#002140', 'exclude' => 'ColorBox' ),
		array( 'code' => 'K',  'label' => 'Gloss Black',         'color' => '#000000', 'exclude' => '' ),
		array( 'code' => 'N',  'label' => 'Matte Gold',          'color' => '#b6883a', 'exclude' => '' ),
		array( 'code' => 'S',  'label' => 'Satin Cream',         'color' => '#efe8ce', 'exclude' => '' ),
		array( 'code' => 'T',  'label' => 'Ice Blue Marble',     'color' => '#375f76', 'exclude' => '' ),
		array( 'code' => 'U',  'label' => 'Teal Green Marble',   'color' => '#21574a', 'exclude' => '' ),
		array( 'code' => 'X',  'label' => 'Dark Walnut',         'color' => '#33190b', 'exclude' => '' ),
		array( 'code' => 'CB', 'label' => 'Coffee Brown',        'color' => '#492914', 'exclude' => '' ),
		array( 'code' => 'MS', 'label' => 'Matte Silver',        'color' => '#aaaaaa', 'exclude' => '' ),
		array( 'code' => 'OR', 'label' => 'Orange Red',          'color' => '#c70003', 'exclude' => '' ),
		array( 'code' => 'CF', 'label' => 'Carbon Fiber',        'color' => '#191f1e', 'exclude' => '' ),
		array( 'code' => 'BP', 'label' => 'Barbie Pink',         'color' => '#ffa2ad', 'exclude' => '' ),
		array( 'code' => 'SB', 'label' => 'Sky Blue',            'color' => '#7491b5', 'exclude' => '' ),
		array( 'code' => 'HC', 'label' => 'Hammer Copper',       'color' => '#5f230e', 'exclude' => 'ColorBox' ),
		array( 'code' => 'LM', 'label' => 'Light Maple',         'color' => '#e4c08b', 'exclude' => '' ),
		array( 'code' => 'PO', 'label' => 'Pumpkin Orange',      'color' => '#ff3100', 'exclude' => '' ),
		array( 'code' => 'AP', 'label' => 'Black Pearl',         'color' => '#201d15', 'exclude' => '' ),
		array( 'code' => 'BW', 'label' => 'White Wood',          'color' => '#f4e5cf', 'exclude' => '' ),
		array( 'code' => 'FG', 'label' => 'Forest Green Wood',   'color' => '#1b3823', 'exclude' => '' ),
		array( 'code' => 'IP', 'label' => 'Ivory Pine',          'color' => '#d7c09d', 'exclude' => '' ),
		array( 'code' => 'SW', 'label' => 'Sea Washed',          'color' => '#b4bab1', 'exclude' => '' ),
		array( 'code' => 'MC', 'label' => 'Milk Chocolate',      'color' => '#764f28', 'exclude' => '' ),
		array( 'code' => 'VP', 'label' => 'Vintage Pewter',      'color' => '#615c60', 'exclude' => '' ),
		array( 'code' => 'AG', 'label' => 'Black Gold',          'color' => '#251409', 'exclude' => '' ),
		array( 'code' => 'NS', 'label' => 'Natural Suede',       'color' => '#7b6b59', 'exclude' => '' ),
		array( 'code' => 'SR', 'label' => 'Silver Rose Marble',  'color' => '#a2595c', 'exclude' => '' ),
	);
}

/**
 * Whether an edge color may be ordered with a style. Style names in the
 * "exclude" list are compared ignoring case, spaces and punctuation, so
 * "ColorBox" also matches a style called "Color Box".
 */
function prrint_edge_available( $edge, $style_label ) {
	if ( empty( $edge['exclude'] ) ) {
		return true;
	}
	$norm = function ( $s ) {
		return strtolower( preg_replace( '/[^a-z0-9]/i', '', (string) $s ) );
	};
	foreach ( explode( ',', $edge['exclude'] ) as $name ) {
		if ( '' !== $norm( $name ) && $norm( $name ) === $norm( $style_label ) ) {
			return false;
		}
	}
	return true;
}

/** "A - Matte Black", or just the name when no code is set. */
function prrint_edge_display_label( $edge ) {
	return ! empty( $edge['code'] ) ? $edge['code'] . ' - ' . $edge['label'] : $edge['label'];
}

/**
 * The placeholder lists shipped in 1.26–1.28, before the real ColorPlak
 * lists. A store still holding exactly these (saved untouched) gets the
 * real defaults instead; anything an admin edited is left alone.
 */
function prrint_legacy_placeholder_lists() {
	return array(
		'styles'      => array(
			array( 'label' => 'Paper print', 'surcharge' => 0.0, 'edge' => false ),
			array( 'label' => 'ColorLite',   'surcharge' => 0.0, 'edge' => true ),
			array( 'label' => 'Color Box',   'surcharge' => 0.0, 'edge' => true ),
		),
		'edge_colors' => array(
			array( 'label' => 'Black', 'color' => '#111111' ),
			array( 'label' => 'White', 'color' => '#ffffff' ),
			array( 'label' => 'Gray',  'color' => '#9ca3af' ),
			array( 'label' => 'Red',   'color' => '#dc2626' ),
			array( 'label' => 'Blue',  'color' => '#2563eb' ),
		),
	);
}

/**
 * Studio color scheme, editable under WooCommerce → Prrint Studio → Colors.
 */
function prrint_default_theme_colors() {
	return array(
		'color_primary'      => '#4f46e5',
		'color_primary_text' => '#ffffff',
		'color_text'         => '#1f2430',
		'color_card_bg'      => '#ffffff',
		'color_summary_bg'   => '#111827',
	);
}

/**
 * Editor tools that can be turned off per store. Transform (crop/rotate)
 * isn't in this list — it's core to the product (it decides what gets
 * printed) and always stays on.
 */
function prrint_toggleable_tools() {
	return array( 'filters', 'adjust', 'focus', 'text', 'textdesign', 'elements', 'draw', 'overlays', 'border' );
}

/**
 * Text Design word-art layout ids — must match the keys of TEXT_TEMPLATES
 * in assets/js/frontend.js.
 */
function prrint_text_template_ids() {
	return array( 'banner', 'stacked', 'quote', 'corner', 'stamp', 'sidestrip' );
}

/**
 * Filter presets that can be turned off per store. "None" isn't in this
 * list — it's always offered so a customer can clear a filter they applied.
 */
function prrint_toggleable_filters() {
	return array( 'bw', 'warm', 'cold', 'vintage', 'duotone', 'legacy', 'smooth' );
}

/**
 * Overlay textures that can be turned off per store. "None" is always
 * offered, same reasoning as filters.
 */
function prrint_toggleable_overlays() {
	return array( 'vignette', 'glow', 'lightleak', 'grain', 'bokeh', 'scratches' );
}

/**
 * Sticker shapes that can be turned off per store.
 */
function prrint_toggleable_shapes() {
	return array( 'circle', 'square', 'triangle', 'diamond', 'pentagon', 'hexagon', 'star', 'heart', 'arrow', 'cross', 'line' );
}

function prrint_default_settings() {
	return array_merge( array(
		'sizes'                         => prrint_default_sizes(),
		'papers'                        => prrint_default_papers(),
		'styles'                        => prrint_default_styles(),
		'edge_colors'                   => prrint_default_edge_colors(),
		'max_mb'                        => 40,
		'jpeg_quality'                  => 92,
		'target_dpi'                    => 300,
		'min_dpi'                       => 150,
		'border_in'                     => 0.25,
		'upload_retention_days'         => 14,
		'guest_library_retention_days'  => 20,
		'framer_notify_email'           => '', // '' = the site admin email.
		'framer_mailin_address'         => "Custom Art Services - Colorplak\n37110 Mesa Road\nTemecula, CA 92592",
		'studio_product_id'             => 0, // 0 = use the auto-created sample product.
		'enabled_tools'                 => prrint_toggleable_tools(),
		'text_templates_enabled'        => prrint_text_template_ids(),
		'enabled_filters'               => prrint_toggleable_filters(),
		'enabled_overlays'              => prrint_toggleable_overlays(),
		'enabled_shapes'                => prrint_toggleable_shapes(),
		'text_colors'                   => array( '#ffffff', '#000000', '#f43f5e', '#f59e0b', '#22c55e', '#3b82f6', '#a855f7' ),
		'text_bg_colors'                => array( '', '#ffffff', '#000000', '#f43f5e', '#f59e0b', '#22c55e', '#3b82f6', '#a855f7' ),
		'border_colors'                 => array( '#ffffff', '#000000', '#9ca3af', '#f43f5e', '#f59e0b', '#3b82f6' ),
		'shape_colors'                  => array( '#000000', '#ffffff', '#f43f5e', '#f59e0b', '#22c55e', '#3b82f6', '#a855f7', '#eab308' ),
		'draw_colors'                   => array( '#000000', '#ffffff', '#f43f5e', '#f59e0b', '#22c55e', '#3b82f6' ),
	), prrint_default_theme_colors() );
}

/**
 * Current plugin settings merged over defaults.
 *
 * @return array
 */
/**
 * Rows dropped from the defaults since they were shipped, at ColorPlak's
 * request: Classic, Deco and Empire are no longer offered, and V (Electric
 * Purple) is discontinued.
 */
function prrint_retired_default_rows() {
	return array(
		'styles'      => array( 'label', array( 'Classic', 'Deco', 'Empire' ) ),
		'edge_colors' => array( 'code', array( 'V' ) ),
	);
}

/**
 * Whether a saved styles/edge-colors list is an earlier default nobody has
 * edited: the 1.26 placeholders, or today's defaults plus rows retired
 * since. Those are swapped for the current defaults; edited lists are kept.
 */
function prrint_is_untouched_old_default( $key, $saved ) {
	$placeholders = prrint_legacy_placeholder_lists();
	if ( prrint_list_matches( $saved, $placeholders[ $key ] ) ) {
		return true;
	}
	if ( ! is_array( $saved ) ) {
		return false;
	}
	list( $field, $retired ) = prrint_retired_default_rows()[ $key ];
	$kept = array_values( array_filter( $saved, function ( $row ) use ( $field, $retired ) {
		return ! ( is_array( $row ) && isset( $row[ $field ] ) && in_array( $row[ $field ], $retired, true ) );
	} ) );
	$current = 'styles' === $key ? prrint_default_styles() : prrint_default_edge_colors();
	return count( $kept ) < count( $saved ) && prrint_list_matches( $kept, $current );
}

/**
 * Loose equality for saved settings rows (numbers may come back as strings).
 */
function prrint_list_matches( $saved, $placeholder ) {
	if ( ! is_array( $saved ) || count( $saved ) !== count( $placeholder ) ) {
		return false;
	}
	foreach ( array_values( $saved ) as $i => $row ) {
		foreach ( $placeholder[ $i ] as $k => $v ) {
			if ( ! isset( $row[ $k ] ) || (string) $row[ $k ] !== (string) $v ) {
				return false;
			}
		}
	}
	return true;
}

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
	foreach ( array( 'styles', 'edge_colors' ) as $key ) {
		if ( isset( $saved[ $key ] ) && prrint_is_untouched_old_default( $key, $saved[ $key ] ) ) {
			$settings[ $key ] = 'styles' === $key ? prrint_default_styles() : prrint_default_edge_colors();
		}
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
