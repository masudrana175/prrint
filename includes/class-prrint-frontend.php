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
			'filters'        => array(
				array( 'id' => '',        'label' => __( 'None', 'prrint' ) ),
				array( 'id' => 'bw',      'label' => __( 'B&W', 'prrint' ) ),
				array( 'id' => 'warm',    'label' => __( 'Warm', 'prrint' ) ),
				array( 'id' => 'cold',    'label' => __( 'Cold', 'prrint' ) ),
				array( 'id' => 'vintage', 'label' => __( 'Vintage', 'prrint' ) ),
				array( 'id' => 'duotone', 'label' => __( 'DuoTone', 'prrint' ) ),
				array( 'id' => 'legacy',  'label' => __( 'Legacy', 'prrint' ) ),
				array( 'id' => 'smooth',  'label' => __( 'Smooth', 'prrint' ) ),
			),
			'textColors'     => array( '#ffffff', '#000000', '#f43f5e', '#f59e0b', '#22c55e', '#3b82f6', '#a855f7' ),
			'textBgColors'   => array( '', '#ffffff', '#000000', '#f43f5e', '#f59e0b', '#22c55e', '#3b82f6', '#a855f7' ),
			'borderColors'   => array( '#ffffff', '#000000', '#9ca3af', '#f43f5e', '#f59e0b', '#3b82f6' ),
			'shapes'         => array(
				array( 'id' => 'circle', 'label' => '●' ),
				array( 'id' => 'square', 'label' => '■' ),
				array( 'id' => 'star',   'label' => '★' ),
				array( 'id' => 'heart',  'label' => '♥' ),
				array( 'id' => 'arrow',  'label' => '➤' ),
				array( 'id' => 'line',   'label' => '—' ),
			),
			'shapeColors'    => array( '#000000', '#ffffff', '#f43f5e', '#f59e0b', '#22c55e', '#3b82f6', '#a855f7', '#eab308' ),
			'drawColors'     => array( '#000000', '#ffffff', '#f43f5e', '#f59e0b', '#22c55e', '#3b82f6' ),
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
				'toolTransform' => __( 'Crop & rotate', 'prrint' ),
				'toolFilters'   => __( 'Filters', 'prrint' ),
				'toolAdjust'    => __( 'Adjust', 'prrint' ),
				'toolText'      => __( 'Text', 'prrint' ),
				'toolBorder'    => __( 'Border', 'prrint' ),
				'newTextDefault' => __( 'Your text here', 'prrint' ),
				'noTextLayer'   => __( 'Add a text layer first.', 'prrint' ),
				'transparent'   => __( 'None', 'prrint' ),
				'toolElements'  => __( 'Elements', 'prrint' ),
				'toolDraw'      => __( 'Draw', 'prrint' ),
				'noShapeLayer'  => __( 'Add a shape first.', 'prrint' ),
				'addSize'       => __( 'Add size', 'prrint' ),
				'addAnotherSize' => __( 'Order this same photo in another size', 'prrint' ),
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

					<div class="prrint-editor-body">
						<div class="prrint-tool-rail" role="tablist" aria-label="<?php esc_attr_e( 'Editor tools', 'prrint' ); ?>">
							<button type="button" class="prrint-tool-btn is-active" data-tool="transform" title="<?php esc_attr_e( 'Crop & rotate', 'prrint' ); ?>">⤢</button>
							<button type="button" class="prrint-tool-btn" data-tool="filters" title="<?php esc_attr_e( 'Filters', 'prrint' ); ?>">◐</button>
							<button type="button" class="prrint-tool-btn" data-tool="adjust" title="<?php esc_attr_e( 'Adjust', 'prrint' ); ?>">☼</button>
							<button type="button" class="prrint-tool-btn" data-tool="text" title="<?php esc_attr_e( 'Text', 'prrint' ); ?>">A</button>
							<button type="button" class="prrint-tool-btn" data-tool="elements" title="<?php esc_attr_e( 'Elements', 'prrint' ); ?>">★</button>
							<button type="button" class="prrint-tool-btn" data-tool="draw" title="<?php esc_attr_e( 'Draw', 'prrint' ); ?>">✎</button>
							<button type="button" class="prrint-tool-btn" data-tool="border" title="<?php esc_attr_e( 'Border', 'prrint' ); ?>">▢</button>
						</div>

						<div class="prrint-tool-panels">
							<div class="prrint-tool-panel" data-panel="transform">
								<input type="range" id="prrint-zoom" min="0" max="100" value="0" aria-label="<?php esc_attr_e( 'Zoom', 'prrint' ); ?>" />
								<div class="prrint-panel-row">
									<button type="button" class="prrint-tool" id="prrint-rotate" title="<?php esc_attr_e( 'Rotate 90°', 'prrint' ); ?>">⟳ <?php esc_html_e( 'Rotate', 'prrint' ); ?></button>
									<button type="button" class="prrint-tool" id="prrint-orient" title="<?php esc_attr_e( 'Portrait / landscape', 'prrint' ); ?>">▭ <?php esc_html_e( 'Orientation', 'prrint' ); ?></button>
								</div>
							</div>

							<div class="prrint-tool-panel" data-panel="filters" hidden>
								<div class="prrint-filter-grid" id="prrint-filter-grid"></div>
							</div>

							<div class="prrint-tool-panel" data-panel="adjust" hidden>
								<label class="prrint-slider-row"><span><?php esc_html_e( 'Brightness', 'prrint' ); ?></span>
									<input type="range" id="prrint-adj-brightness" min="-100" max="100" value="0" /></label>
								<label class="prrint-slider-row"><span><?php esc_html_e( 'Contrast', 'prrint' ); ?></span>
									<input type="range" id="prrint-adj-contrast" min="-100" max="100" value="0" /></label>
								<label class="prrint-slider-row"><span><?php esc_html_e( 'Saturation', 'prrint' ); ?></span>
									<input type="range" id="prrint-adj-saturation" min="0" max="100" value="100" /></label>
								<button type="button" class="prrint-btn-secondary prrint-adj-reset" id="prrint-adj-reset"><?php esc_html_e( 'Reset', 'prrint' ); ?></button>
							</div>

							<div class="prrint-tool-panel" data-panel="text" hidden>
								<button type="button" class="prrint-cta" id="prrint-text-add">+ <?php esc_html_e( 'New Text', 'prrint' ); ?></button>
								<div id="prrint-text-fields" hidden>
									<textarea id="prrint-text-content" rows="3" placeholder="<?php esc_attr_e( 'Your text here', 'prrint' ); ?>"></textarea>
									<label class="prrint-slider-row"><span><?php esc_html_e( 'Size', 'prrint' ); ?></span>
										<input type="range" id="prrint-text-size" min="2" max="20" value="6" /></label>
									<label class="prrint-slider-row"><span><?php esc_html_e( 'Line spacing', 'prrint' ); ?></span>
										<input type="range" id="prrint-text-spacing" min="8" max="30" value="13" /></label>
									<label class="prrint-slider-row"><span><?php esc_html_e( 'Box width', 'prrint' ); ?></span>
										<input type="range" id="prrint-text-width" min="20" max="100" value="80" /></label>
									<label class="prrint-slider-row"><span><?php esc_html_e( 'Rotation', 'prrint' ); ?></span>
										<input type="range" id="prrint-text-rotation" min="-45" max="45" value="0" /></label>
									<div class="prrint-panel-row">
										<button type="button" class="prrint-tool" id="prrint-text-bold" title="<?php esc_attr_e( 'Bold', 'prrint' ); ?>"><strong>B</strong></button>
										<button type="button" class="prrint-tool" data-align="left" title="<?php esc_attr_e( 'Align left', 'prrint' ); ?>">⯇</button>
										<button type="button" class="prrint-tool" data-align="center" title="<?php esc_attr_e( 'Align center', 'prrint' ); ?>">≡</button>
										<button type="button" class="prrint-tool" data-align="right" title="<?php esc_attr_e( 'Align right', 'prrint' ); ?>">⯈</button>
									</div>
									<p class="prrint-swatch-label"><?php esc_html_e( 'Text color', 'prrint' ); ?></p>
									<div class="prrint-swatch-row" id="prrint-text-color-swatches" data-target="color"></div>
									<p class="prrint-swatch-label"><?php esc_html_e( 'Background', 'prrint' ); ?></p>
									<div class="prrint-swatch-row" id="prrint-text-bg-swatches" data-target="bgColor"></div>
									<div class="prrint-panel-row">
										<button type="button" class="prrint-btn-secondary" id="prrint-text-duplicate"><?php esc_html_e( 'Duplicate', 'prrint' ); ?></button>
										<button type="button" class="prrint-btn-secondary" id="prrint-text-delete"><?php esc_html_e( 'Delete', 'prrint' ); ?></button>
									</div>
								</div>
							</div>

							<div class="prrint-tool-panel" data-panel="border" hidden>
								<label class="prrint-border-label"><input type="checkbox" id="prrint-border-enable" /> <?php esc_html_e( 'Add a border', 'prrint' ); ?></label>
								<div id="prrint-border-fields" hidden>
									<p class="prrint-swatch-label"><?php esc_html_e( 'Color', 'prrint' ); ?></p>
									<div class="prrint-swatch-row" id="prrint-border-swatches"></div>
									<label class="prrint-slider-row"><span><?php esc_html_e( 'Width', 'prrint' ); ?></span>
										<input type="range" id="prrint-border-width" min="5" max="100" value="25" /></label>
								</div>
							</div>

							<div class="prrint-tool-panel" data-panel="elements" hidden>
								<div class="prrint-shape-grid" id="prrint-shape-grid"></div>
								<div id="prrint-shape-fields" hidden>
									<p class="prrint-swatch-label"><?php esc_html_e( 'Color', 'prrint' ); ?></p>
									<div class="prrint-swatch-row" id="prrint-shape-color-swatches"></div>
									<label class="prrint-slider-row"><span><?php esc_html_e( 'Size', 'prrint' ); ?></span>
										<input type="range" id="prrint-shape-size" min="5" max="100" value="20" /></label>
									<label class="prrint-slider-row"><span><?php esc_html_e( 'Rotation', 'prrint' ); ?></span>
										<input type="range" id="prrint-shape-rotation" min="-180" max="180" value="0" /></label>
									<div class="prrint-panel-row">
										<button type="button" class="prrint-btn-secondary" id="prrint-shape-duplicate"><?php esc_html_e( 'Duplicate', 'prrint' ); ?></button>
										<button type="button" class="prrint-btn-secondary" id="prrint-shape-delete"><?php esc_html_e( 'Delete', 'prrint' ); ?></button>
									</div>
								</div>
							</div>

							<div class="prrint-tool-panel" data-panel="draw" hidden>
								<p class="prrint-swatch-label"><?php esc_html_e( 'Brush color', 'prrint' ); ?></p>
								<div class="prrint-swatch-row" id="prrint-draw-color-swatches"></div>
								<label class="prrint-slider-row"><span><?php esc_html_e( 'Brush size', 'prrint' ); ?></span>
									<input type="range" id="prrint-draw-size" min="1" max="10" value="4" /></label>
								<button type="button" class="prrint-btn-secondary" id="prrint-draw-clear"><?php esc_html_e( 'Clear drawing', 'prrint' ); ?></button>
								<p class="prrint-editor-hint"><?php esc_html_e( 'Draw directly on the photo above.', 'prrint' ); ?></p>
							</div>
						</div>

						<div class="prrint-editor-canvas-wrap">
							<canvas id="prrint-canvas"></canvas>
							<span class="prrint-dpi" id="prrint-dpi" hidden></span>
						</div>
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
