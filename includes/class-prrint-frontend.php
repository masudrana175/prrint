<?php
/**
 * Front-end: the Print Studio UI on single product pages.
 *
 * @package prrint
 */

defined( 'ABSPATH' ) || exit;

class Prrint_Frontend {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'woocommerce_before_add_to_cart_button', array( __CLASS__, 'render_studio' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
	}

	protected static function current_enabled_product() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return null;
		}
		$product = wc_get_product( get_queried_object_id() );
		return ( $product && prrint_is_enabled( $product ) ) ? $product : null;
	}

	public static function body_class( $classes ) {
		if ( self::current_enabled_product() ) {
			$classes[] = 'prrint-active';
		}
		return $classes;
	}

	public static function enqueue() {
		$product = self::current_enabled_product();
		if ( ! $product ) {
			return;
		}

		wp_enqueue_style( 'prrint-frontend', PRRINT_URL . 'assets/css/frontend.css', array(), PRRINT_VERSION );
		wp_enqueue_script( 'prrint-frontend', PRRINT_URL . 'assets/js/frontend.js', array(), PRRINT_VERSION, true );

		$product_id = $product->get_id();
		$settings   = prrint_settings();
		$sizes      = prrint_get_sizes( $product_id );
		$papers     = prrint_get_papers( $product_id );

		// Optional URL preselects, e.g. ?paper=luster or ?size=8x10.
		$preselect_paper = self::match_index( $papers, isset( $_GET['paper'] ) ? sanitize_title( wp_unslash( $_GET['paper'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$preselect_size  = self::match_index( $sizes, isset( $_GET['size'] ) ? sanitize_title( wp_unslash( $_GET['size'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		wp_localize_script( 'prrint-frontend', 'prrintData', array(
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'nonce'          => wp_create_nonce( 'prrint_studio' ),
			'productId'      => $product_id,
			'sizes'          => array_values( $sizes ),
			'papers'         => array_values( $papers ),
			'basePrice'      => (float) $product->get_price(),
			'maxMb'          => (int) $settings['max_mb'],
			'minDpi'         => (int) $settings['min_dpi'],
			'targetDpi'      => (int) $settings['target_dpi'],
			'borderIn'       => (float) $settings['border_in'],
			'preselectSize'  => $preselect_size,
			'preselectPaper' => $preselect_paper,
			'cartUrl'        => wc_get_cart_url(),
			'currency'       => array(
				'symbol'      => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
				'decimals'    => wc_get_price_decimals(),
				'decimalSep'  => wc_get_price_decimal_separator(),
				'thousandSep' => wc_get_price_thousand_separator(),
				'position'    => get_option( 'woocommerce_currency_pos', 'left' ),
			),
			'i18n'           => array(
				'uploading'    => __( 'Uploading…', 'prrint' ),
				'uploadError'  => __( 'Upload failed. Please try again.', 'prrint' ),
				'tooLarge'     => __( 'That file is too large.', 'prrint' ),
				'badType'      => __( 'Please choose a JPEG, PNG or WebP image.', 'prrint' ),
				'edit'         => __( 'Edit crop', 'prrint' ),
				'remove'       => __( 'Remove', 'prrint' ),
				'whiteBorder'  => __( 'White border', 'prrint' ),
				'qty'          => __( 'Qty', 'prrint' ),
				'size'         => __( 'Size', 'prrint' ),
				'paper'        => __( 'Paper', 'prrint' ),
				'each'         => __( 'each', 'prrint' ),
				'dpiGood'      => __( 'Excellent quality', 'prrint' ),
				'dpiOk'        => __( 'Good quality', 'prrint' ),
				'dpiLow'       => __( 'Low resolution — may print blurry', 'prrint' ),
				/* translators: %d: number of prints */
				'printCount'   => __( '%d print(s)', 'prrint' ),
				'total'        => __( 'Total', 'prrint' ),
				'addToCart'    => __( 'Add to cart', 'prrint' ),
				'adding'       => __( 'Adding…', 'prrint' ),
				'added'        => __( 'Added to cart! Redirecting…', 'prrint' ),
				'addError'     => __( 'Could not add to cart. Please try again.', 'prrint' ),
				'noItems'      => __( 'Upload at least one photo first.', 'prrint' ),
				'done'         => __( 'Done', 'prrint' ),
				'cancel'       => __( 'Cancel', 'prrint' ),
				'editorTitle'  => __( 'Adjust your photo', 'prrint' ),
				'editorHint'   => __( 'Drag to reposition · scroll or slide to zoom', 'prrint' ),
			),
		) );
	}

	protected static function match_index( $rows, $slug ) {
		if ( '' === $slug ) {
			return -1;
		}
		foreach ( array_values( $rows ) as $i => $row ) {
			if ( sanitize_title( $row['label'] ) === $slug ) {
				return $i;
			}
		}
		return -1;
	}

	public static function render_studio() {
		global $product;

		if ( ! $product || ! prrint_is_enabled( $product ) ) {
			return;
		}
		?>
		<div id="prrint-studio" class="prrint-studio" data-prrint>
			<div class="prrint-dropzone" id="prrint-dropzone" role="button" tabindex="0"
				aria-label="<?php esc_attr_e( 'Upload photos', 'prrint' ); ?>">
				<svg class="prrint-dz-icon" viewBox="0 0 24 24" width="44" height="44" aria-hidden="true">
					<path fill="currentColor" d="M19 13v6H5v-6H3v6a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-6h-2zM11 5.83 8.41 8.41 7 7l5-5 5 5-1.41 1.41L13 5.83V16h-2V5.83z"/>
				</svg>
				<p class="prrint-dz-title"><?php esc_html_e( 'Upload your photos', 'prrint' ); ?></p>
				<p class="prrint-dz-sub"><?php esc_html_e( 'Drag & drop or click to browse — JPEG, PNG, WebP', 'prrint' ); ?></p>
				<input type="file" id="prrint-file-input" accept="image/jpeg,image/png,image/webp" multiple hidden />
			</div>

			<div class="prrint-items" id="prrint-items"></div>

			<div class="prrint-summary" id="prrint-summary" hidden>
				<div class="prrint-summary-info">
					<span class="prrint-summary-count" id="prrint-count"></span>
					<span class="prrint-summary-total" id="prrint-total"></span>
				</div>
				<button type="button" class="prrint-cta" id="prrint-add-to-cart">
					<?php esc_html_e( 'Add to cart', 'prrint' ); ?>
				</button>
			</div>

			<div class="prrint-editor-overlay" id="prrint-editor" hidden>
				<div class="prrint-editor-panel" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Adjust your photo', 'prrint' ); ?>">
					<div class="prrint-editor-head">
						<strong><?php esc_html_e( 'Adjust your photo', 'prrint' ); ?></strong>
						<span class="prrint-editor-hint"><?php esc_html_e( 'Drag to reposition · scroll or slide to zoom', 'prrint' ); ?></span>
					</div>
					<div class="prrint-editor-canvas-wrap">
						<canvas id="prrint-canvas"></canvas>
						<span class="prrint-dpi" id="prrint-dpi" hidden></span>
					</div>
					<div class="prrint-editor-toolbar">
						<input type="range" id="prrint-zoom" min="0" max="100" value="0" aria-label="<?php esc_attr_e( 'Zoom', 'prrint' ); ?>" />
						<button type="button" class="prrint-tool" id="prrint-rotate" title="<?php esc_attr_e( 'Rotate 90°', 'prrint' ); ?>">⟳</button>
						<button type="button" class="prrint-tool" id="prrint-orient" title="<?php esc_attr_e( 'Portrait / landscape', 'prrint' ); ?>">▭</button>
					</div>
					<div class="prrint-editor-actions">
						<button type="button" class="prrint-btn-secondary" id="prrint-editor-cancel"><?php esc_html_e( 'Cancel', 'prrint' ); ?></button>
						<button type="button" class="prrint-cta" id="prrint-editor-done"><?php esc_html_e( 'Done', 'prrint' ); ?></button>
					</div>
				</div>
			</div>

			<div class="prrint-toast" id="prrint-toast" hidden></div>

			<noscript>
				<style>
					body.prrint-active form.cart div.quantity,
					body.prrint-active form.cart .single_add_to_cart_button { display: inline-block !important; }
					.prrint-studio { display: none !important; }
				</style>
				<p><?php esc_html_e( 'The photo print designer requires JavaScript. Please enable JavaScript in your browser.', 'prrint' ); ?></p>
			</noscript>
		</div>
		<?php
	}
}
