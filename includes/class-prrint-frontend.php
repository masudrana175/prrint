<?php
/**
 * Front-end: the Print Studio UI on single product pages.
 *
 * @package prrint
 */

defined( 'ABSPATH' ) || exit;

class Prrint_Frontend {

	/**
	 * Guards against enqueuing prrintData twice (e.g. the [prrint_studio]
	 * shortcode used on the product page itself, or called more than once).
	 */
	protected static $enqueued = false;

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'woocommerce_before_add_to_cart_button', array( __CLASS__, 'render_studio' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_shortcode( 'prrint_studio', array( __CLASS__, 'shortcode' ) );
	}

	protected static function current_enabled_product() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return null;
		}
		$product = wc_get_product( get_queried_object_id() );
		return ( $product && prrint_is_enabled( $product ) ) ? $product : null;
	}

	/**
	 * If the currently-viewed singular post/page contains [prrint_studio],
	 * resolve the product it'll design against — used to enqueue assets at
	 * the correct time (the wp_enqueue_scripts hook, during wp_head()), not
	 * from inside the shortcode callback itself. A style enqueued that late
	 * is too late: wp_head() has already printed <link> tags in virtually
	 * every theme by the time shortcodes in the content run, so a style
	 * enqueued only from the callback silently never reaches the page —
	 * the studio's markup renders, unstyled and effectively broken.
	 */
	protected static function current_page_shortcode_product() {
		if ( ! is_singular() ) {
			return null;
		}
		$post = get_post();
		if ( ! $post || ! has_shortcode( $post->post_content, 'prrint_studio' ) ) {
			return null;
		}
		$atts = array();
		if ( preg_match( '/\[prrint_studio\b([^\]]*)\]/', $post->post_content, $m ) ) {
			$atts = shortcode_parse_atts( $m[1] );
		}
		return self::shortcode_product( is_array( $atts ) ? $atts : array() );
	}

	/**
	 * The product a [prrint_studio] shortcode should design for: an explicit
	 * id="" attribute, else the store's configured/auto-created default —
	 * the studio's design UI lives on its own page via this shortcode, but
	 * still needs one regular WooCommerce product behind it to actually
	 * process the order (pricing, cart, checkout, order management).
	 */
	protected static function shortcode_product( $atts ) {
		$configured = (int) prrint_settings()['studio_product_id'];
		$product_id = ! empty( $atts['id'] ) ? absint( $atts['id'] ) : ( $configured ? $configured : (int) get_option( 'prrint_sample_product' ) );
		if ( ! $product_id ) {
			return null;
		}
		$product = wc_get_product( $product_id );
		return ( $product && prrint_is_enabled( $product ) ) ? $product : null;
	}

	/**
	 * [prrint_studio] / [prrint_studio id="123"] — the standalone design +
	 * order page: upload, editor, size/paper/qty and Add to cart, usable on
	 * any WordPress Page, independent of the WooCommerce single-product
	 * template. The referenced product is only used to process the order
	 * (price, cart line, checkout) — its own product page doesn't need to
	 * be visited at all.
	 */
	public static function shortcode( $atts ) {
		$atts    = shortcode_atts( array( 'id' => '' ), $atts, 'prrint_studio' );
		$product = self::shortcode_product( $atts );
		if ( ! $product ) {
			if ( current_user_can( 'manage_woocommerce' ) ) {
				return '<p>' . esc_html__( 'Print Studio: no product is configured for this page yet. Set one with [prrint_studio id="123"], or enable Print Studio on a product under WooCommerce → Prrint Studio.', 'prrint' ) . '</p>';
			}
			return '';
		}

		self::enqueue_for_product( $product );

		ob_start();
		self::render_studio_markup( $product );
		return ob_get_clean();
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
			$product = self::current_page_shortcode_product();
		}
		if ( ! $product ) {
			return;
		}
		self::enqueue_for_product( $product );
	}

	protected static function enqueue_for_product( $product ) {
		if ( self::$enqueued ) {
			return;
		}
		self::$enqueued = true;

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
			'scaleUnit'      => $settings['scale_unit'],
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
			'overlays'       => array(
				array( 'id' => '',          'label' => __( 'None', 'prrint' ), 'url' => '' ),
				array( 'id' => 'vignette',  'label' => __( 'Vignette', 'prrint' ), 'url' => PRRINT_URL . 'assets/overlays/vignette.png' ),
				array( 'id' => 'glow',      'label' => __( 'Glow', 'prrint' ), 'url' => PRRINT_URL . 'assets/overlays/glow.png' ),
				array( 'id' => 'lightleak', 'label' => __( 'Light Leak', 'prrint' ), 'url' => PRRINT_URL . 'assets/overlays/lightleak.png' ),
				array( 'id' => 'grain',     'label' => __( 'Grain', 'prrint' ), 'url' => PRRINT_URL . 'assets/overlays/grain.png' ),
				array( 'id' => 'bokeh',     'label' => __( 'Bokeh', 'prrint' ), 'url' => PRRINT_URL . 'assets/overlays/bokeh.png' ),
				array( 'id' => 'scratches', 'label' => __( 'Scratches', 'prrint' ), 'url' => PRRINT_URL . 'assets/overlays/scratches.png' ),
			),
			'textColors'     => array_values( $settings['text_colors'] ),
			'textBgColors'   => array_values( $settings['text_bg_colors'] ),
			'borderColors'   => array_values( $settings['border_colors'] ),
			'shapes'         => array(
				array( 'id' => 'circle',   'label' => '●' ),
				array( 'id' => 'square',   'label' => '■' ),
				array( 'id' => 'triangle', 'label' => '▲' ),
				array( 'id' => 'diamond',  'label' => '◆' ),
				array( 'id' => 'pentagon', 'label' => '⬠' ),
				array( 'id' => 'hexagon',  'label' => '⬡' ),
				array( 'id' => 'star',     'label' => '★' ),
				array( 'id' => 'heart',    'label' => '♥' ),
				array( 'id' => 'arrow',    'label' => '➤' ),
				array( 'id' => 'cross',    'label' => '✚' ),
				array( 'id' => 'line',     'label' => '—' ),
			),
			'shapeColors'    => array_values( $settings['shape_colors'] ),
			'textTemplates'  => self::enabled_text_templates( $settings['text_templates_enabled'] ),
			'drawColors'     => array_values( $settings['draw_colors'] ),
			'enabledTools'   => array_values( $settings['enabled_tools'] ),
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
				'toolOverlays'  => __( 'Overlays', 'prrint' ),
				'noShapeLayer'  => __( 'Add a shape first.', 'prrint' ),
				'addSize'       => __( 'Add size', 'prrint' ),
				'addAnotherSize' => __( 'Order this same photo in another size', 'prrint' ),
				'toolTextDesign' => __( 'Text Design', 'prrint' ),
				'shuffleLayout' => __( 'Shuffle Layout', 'prrint' ),
				'invertColors'  => __( 'Invert', 'prrint' ),
				'noTextDesign'  => __( 'Choose a layout first.', 'prrint' ),
				'download'      => __( 'Download', 'prrint' ),
				'usePhoto'      => __( 'Use this photo', 'prrint' ),
				'confirmDeletePhoto' => __( 'Remove this photo from your saved photos? This cannot be undone.', 'prrint' ),
				'photoDeleted'  => __( 'Photo deleted.', 'prrint' ),
				'libraryEmpty'  => __( "You haven't uploaded any photos yet — they'll show up here once you do.", 'prrint' ),
				'refreshing'    => __( 'Refreshing…', 'prrint' ),
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

	/**
	 * The Text Design template id/label list, filtered to the ones the
	 * store has left enabled (default: all of them).
	 */
	protected static function enabled_text_templates( $enabled_ids ) {
		$all = array(
			array( 'id' => 'banner',    'label' => __( 'Banner', 'prrint' ) ),
			array( 'id' => 'stacked',   'label' => __( 'Stacked', 'prrint' ) ),
			array( 'id' => 'quote',     'label' => __( 'Quote', 'prrint' ) ),
			array( 'id' => 'corner',    'label' => __( 'Corner Tag', 'prrint' ) ),
			array( 'id' => 'stamp',     'label' => __( 'Stamp', 'prrint' ) ),
			array( 'id' => 'sidestrip', 'label' => __( 'Side Strip', 'prrint' ) ),
		);
		return array_values( array_filter( $all, function ( $t ) use ( $enabled_ids ) {
			return in_array( $t['id'], $enabled_ids, true );
		} ) );
	}

	public static function render_studio() {
		global $product;

		if ( ! $product || ! prrint_is_enabled( $product ) ) {
			return;
		}
		self::render_studio_markup( $product );
	}

	protected static function render_studio_markup( $product ) {
		$enabled_tools = prrint_settings()['enabled_tools'];
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

			<?php if ( is_user_logged_in() ) : ?>
				<?php $library_rows = Prrint_Account::get_library_rows( get_current_user_id() ); ?>
				<div class="prrint-library" id="prrint-library">
					<div class="prrint-library-header">
						<h3><?php esc_html_e( 'Your uploaded photos', 'prrint' ); ?></h3>
						<button type="button" class="prrint-btn-secondary" id="prrint-library-refresh">
							↻ <?php esc_html_e( 'Refresh', 'prrint' ); ?>
						</button>
					</div>
					<div class="prrint-library-grid" id="prrint-library-grid" <?php echo empty( $library_rows ) ? 'hidden' : ''; ?>>
						<?php foreach ( $library_rows as $row ) : ?>
							<?php if ( empty( $row['file'] ) || ! file_exists( prrint_file_path( $row['file'] ) ) ) { continue; } ?>
							<div class="prrint-library-tile" data-id="<?php echo esc_attr( $row['id'] ); ?>">
								<img src="<?php echo esc_url( prrint_file_url( ! empty( $row['preview'] ) ? $row['preview'] : $row['file'] ) ); ?>" alt="" loading="lazy" />
								<button type="button" class="prrint-library-use" data-id="<?php echo esc_attr( $row['id'] ); ?>">
									<?php esc_html_e( 'Use this photo', 'prrint' ); ?>
								</button>
								<div class="prrint-library-tile-actions">
									<a href="<?php echo esc_url( prrint_file_url( $row['file'] ) ); ?>" class="prrint-library-download" download title="<?php esc_attr_e( 'Download', 'prrint' ); ?>">⬇</a>
									<button type="button" class="prrint-library-delete" data-id="<?php echo esc_attr( $row['id'] ); ?>" title="<?php esc_attr_e( 'Delete', 'prrint' ); ?>">🗑</button>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
					<p class="prrint-library-empty" id="prrint-library-empty" <?php echo empty( $library_rows ) ? '' : 'hidden'; ?>>
						<?php esc_html_e( "You haven't uploaded any photos yet — they'll show up here once you do.", 'prrint' ); ?>
					</p>
				</div>
			<?php endif; ?>

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
					<div class="prrint-editor-topbar">
						<div class="prrint-editor-topbar-left">
							<button type="button" class="prrint-topbar-btn" id="prrint-undo" disabled><?php esc_html_e( 'Undo', 'prrint' ); ?></button>
							<button type="button" class="prrint-topbar-btn" id="prrint-redo" disabled><?php esc_html_e( 'Redo', 'prrint' ); ?></button>
						</div>
						<div class="prrint-zoom-control" title="<?php esc_attr_e( 'Drag to reposition · scroll or slide to zoom', 'prrint' ); ?>">
							<button type="button" class="prrint-topbar-btn" id="prrint-zoom-out" aria-label="<?php esc_attr_e( 'Zoom out', 'prrint' ); ?>">−</button>
							<span id="prrint-zoom-pct">0%</span>
							<button type="button" class="prrint-topbar-btn" id="prrint-zoom-in" aria-label="<?php esc_attr_e( 'Zoom in', 'prrint' ); ?>">+</button>
						</div>
						<div class="prrint-editor-topbar-right">
							<button type="button" class="prrint-btn-secondary" id="prrint-editor-cancel"><?php esc_html_e( 'Close', 'prrint' ); ?></button>
							<button type="button" class="prrint-cta" id="prrint-editor-done"><?php esc_html_e( 'Save', 'prrint' ); ?></button>
						</div>
					</div>

					<div class="prrint-editor-body">
						<div class="prrint-tool-rail" role="tablist" aria-label="<?php esc_attr_e( 'Editor tools', 'prrint' ); ?>">
							<button type="button" class="prrint-tool-btn is-active" data-tool="transform" title="<?php esc_attr_e( 'Crop & rotate', 'prrint' ); ?>">⤢</button>
							<?php
							$rail_tools = array(
								'filters'    => array( '◐', __( 'Filters', 'prrint' ) ),
								'adjust'     => array( '☼', __( 'Adjust', 'prrint' ) ),
								'focus'      => array( '◎', __( 'Focus', 'prrint' ) ),
								'text'       => array( 'A', __( 'Text', 'prrint' ) ),
								'textdesign' => array( '🔖', __( 'Text Design', 'prrint' ) ),
								'elements'   => array( '★', __( 'Elements', 'prrint' ) ),
								'draw'       => array( '✎', __( 'Draw', 'prrint' ) ),
								'overlays'   => array( '▨', __( 'Overlays', 'prrint' ) ),
								'border'     => array( '▢', __( 'Border', 'prrint' ) ),
							);
							foreach ( $rail_tools as $tool_id => $tool ) :
								if ( ! in_array( $tool_id, $enabled_tools, true ) ) {
									continue;
								}
								?>
								<button type="button" class="prrint-tool-btn" data-tool="<?php echo esc_attr( $tool_id ); ?>" title="<?php echo esc_attr( $tool[1] ); ?>"><?php echo esc_html( $tool[0] ); ?></button>
							<?php endforeach; ?>
						</div>

						<div class="prrint-tool-panels">
							<div class="prrint-tool-panel" data-panel="transform">
								<input type="range" id="prrint-zoom" min="0" max="100" value="0" aria-label="<?php esc_attr_e( 'Zoom', 'prrint' ); ?>" />
								<label class="prrint-zoom-input-row">
									<span><?php esc_html_e( 'Zoom', 'prrint' ); ?></span>
									<span class="prrint-zoom-input-wrap">
										<input type="number" id="prrint-zoom-input" min="0" max="100" step="1" value="0" />
										<span>%</span>
									</span>
								</label>
								<div class="prrint-panel-row">
									<button type="button" class="prrint-tool" id="prrint-rotate" title="<?php esc_attr_e( 'Rotate 90°', 'prrint' ); ?>">⟳ <?php esc_html_e( 'Rotate', 'prrint' ); ?></button>
									<button type="button" class="prrint-tool" id="prrint-orient" title="<?php esc_attr_e( 'Portrait / landscape', 'prrint' ); ?>">▭ <?php esc_html_e( 'Orientation', 'prrint' ); ?></button>
								</div>
								<div class="prrint-panel-row">
									<button type="button" class="prrint-tool" id="prrint-flip-h" title="<?php esc_attr_e( 'Flip horizontal', 'prrint' ); ?>">⇋ <?php esc_html_e( 'Flip H', 'prrint' ); ?></button>
									<button type="button" class="prrint-tool" id="prrint-flip-v" title="<?php esc_attr_e( 'Flip vertical', 'prrint' ); ?>">⇵ <?php esc_html_e( 'Flip V', 'prrint' ); ?></button>
								</div>
								<label class="prrint-border-label prrint-keep-res-label"><input type="checkbox" id="prrint-keep-resolution" /> <?php esc_html_e( 'Keep Resolution', 'prrint' ); ?></label>
								<p class="prrint-field-label"><?php esc_html_e( 'Crop Size', 'prrint' ); ?></p>
								<div class="prrint-panel-row prrint-crop-size-row">
									<label class="prrint-crop-size-field">
										<span><?php esc_html_e( 'W', 'prrint' ); ?></span>
										<span class="prrint-crop-size-wrap">
											<input type="number" id="prrint-crop-w" min="1" step="1" />
											<span><?php esc_html_e( 'px', 'prrint' ); ?></span>
										</span>
									</label>
									<label class="prrint-crop-size-field">
										<span><?php esc_html_e( 'H', 'prrint' ); ?></span>
										<span class="prrint-crop-size-wrap">
											<input type="number" id="prrint-crop-h" min="1" step="1" />
											<span><?php esc_html_e( 'px', 'prrint' ); ?></span>
										</span>
									</label>
								</div>
								<button type="button" class="prrint-btn-secondary prrint-adj-reset" id="prrint-transform-reset"><?php esc_html_e( 'Reset to Default', 'prrint' ); ?></button>
							</div>

							<div class="prrint-tool-panel" data-panel="filters" hidden>
								<div class="prrint-filter-grid" id="prrint-filter-grid"></div>
							</div>
							<div class="prrint-tool-panel" data-panel="adjust" hidden>
								<p class="prrint-field-label"><?php esc_html_e( 'Basic', 'prrint' ); ?></p>
								<label class="prrint-slider-row"><span><?php esc_html_e( 'Brightness', 'prrint' ); ?> <output id="prrint-adj-brightness-out">0</output></span>
									<input type="range" id="prrint-adj-brightness" min="-100" max="100" value="0" /></label>
								<label class="prrint-slider-row"><span><?php esc_html_e( 'Contrast', 'prrint' ); ?> <output id="prrint-adj-contrast-out">0</output></span>
									<input type="range" id="prrint-adj-contrast" min="-100" max="100" value="0" /></label>
								<label class="prrint-slider-row"><span><?php esc_html_e( 'Saturation', 'prrint' ); ?> <output id="prrint-adj-saturation-out">100</output></span>
									<input type="range" id="prrint-adj-saturation" min="0" max="100" value="100" /></label>
								<label class="prrint-slider-row"><span><?php esc_html_e( 'Gamma', 'prrint' ); ?> <output id="prrint-adj-gamma-out">0</output></span>
									<input type="range" id="prrint-adj-gamma" min="-100" max="100" value="0" /></label>

								<p class="prrint-field-label"><?php esc_html_e( 'Refinements', 'prrint' ); ?></p>
								<label class="prrint-slider-row"><span><?php esc_html_e( 'Clarity', 'prrint' ); ?> <output id="prrint-adj-clarity-out">0</output></span>
									<input type="range" id="prrint-adj-clarity" min="0" max="100" value="0" /></label>
								<label class="prrint-slider-row"><span><?php esc_html_e( 'Shadows', 'prrint' ); ?> <output id="prrint-adj-shadows-out">0</output></span>
									<input type="range" id="prrint-adj-shadows" min="-100" max="100" value="0" /></label>
								<label class="prrint-slider-row"><span><?php esc_html_e( 'Highlights', 'prrint' ); ?> <output id="prrint-adj-highlights-out">0</output></span>
									<input type="range" id="prrint-adj-highlights" min="-100" max="100" value="0" /></label>
								<label class="prrint-slider-row"><span><?php esc_html_e( 'Exposure', 'prrint' ); ?> <output id="prrint-adj-exposure-out">0</output></span>
									<input type="range" id="prrint-adj-exposure" min="-100" max="100" value="0" /></label>

								<button type="button" class="prrint-btn-secondary prrint-adj-reset" id="prrint-adj-reset"><?php esc_html_e( 'Reset', 'prrint' ); ?></button>
							</div>
							<div class="prrint-tool-panel" data-panel="focus" hidden>
								<p class="prrint-field-label"><?php esc_html_e( 'Shape', 'prrint' ); ?></p>
								<div id="prrint-focus-shapes">
									<div class="prrint-panel-row">
										<button type="button" class="prrint-tool" data-shape="radial" title="<?php esc_attr_e( 'Radial', 'prrint' ); ?>">◎ <?php esc_html_e( 'Radial', 'prrint' ); ?></button>
										<button type="button" class="prrint-tool" data-shape="linear" title="<?php esc_attr_e( 'Linear', 'prrint' ); ?>">▤ <?php esc_html_e( 'Linear', 'prrint' ); ?></button>
									</div>
									<div class="prrint-panel-row">
										<button type="button" class="prrint-tool" data-shape="mirrored" title="<?php esc_attr_e( 'Mirrored', 'prrint' ); ?>">▥ <?php esc_html_e( 'Mirrored', 'prrint' ); ?></button>
										<button type="button" class="prrint-tool" data-shape="gaussian" title="<?php esc_attr_e( 'Gaussian', 'prrint' ); ?>">◍ <?php esc_html_e( 'Gaussian', 'prrint' ); ?></button>
									</div>
								</div>

								<div id="prrint-focus-fields" hidden>
									<label class="prrint-slider-row"><span><?php esc_html_e( 'Blur amount', 'prrint' ); ?> <output id="prrint-focus-amount-out">0</output></span>
										<input type="range" id="prrint-focus-amount" min="0" max="100" value="60" /></label>

									<div id="prrint-focus-position-fields">
										<label class="prrint-slider-row" id="prrint-focus-x-row"><span><?php esc_html_e( 'Center X', 'prrint' ); ?></span>
											<input type="range" id="prrint-focus-x" min="0" max="100" value="50" /></label>
										<label class="prrint-slider-row" id="prrint-focus-y-row"><span><?php esc_html_e( 'Center Y', 'prrint' ); ?></span>
											<input type="range" id="prrint-focus-y" min="0" max="100" value="50" /></label>
										<label class="prrint-slider-row" id="prrint-focus-pos-row"><span><?php esc_html_e( 'Position', 'prrint' ); ?></span>
											<input type="range" id="prrint-focus-pos" min="0" max="100" value="50" /></label>
										<label class="prrint-slider-row" id="prrint-focus-orient-row">
											<span><?php esc_html_e( 'Band direction', 'prrint' ); ?></span>
											<span class="prrint-panel-row">
												<button type="button" class="prrint-tool" id="prrint-focus-orient" title="<?php esc_attr_e( 'Toggle horizontal / vertical', 'prrint' ); ?>">⇄ <?php esc_html_e( 'Horizontal', 'prrint' ); ?></button>
											</span>
										</label>
										<label class="prrint-slider-row" id="prrint-focus-radius-row"><span><?php esc_html_e( 'Sharp area size', 'prrint' ); ?></span>
											<input type="range" id="prrint-focus-radius" min="2" max="100" value="30" /></label>
										<label class="prrint-slider-row" id="prrint-focus-width-row"><span><?php esc_html_e( 'Band width', 'prrint' ); ?></span>
											<input type="range" id="prrint-focus-width" min="2" max="100" value="15" /></label>
										<label class="prrint-slider-row" id="prrint-focus-feather-row"><span><?php esc_html_e( 'Feather', 'prrint' ); ?></span>
											<input type="range" id="prrint-focus-feather" min="2" max="100" value="25" /></label>
									</div>
								</div>
							</div>
							<div class="prrint-tool-panel" data-panel="text" hidden>
								<button type="button" class="prrint-cta prrint-cta-block" id="prrint-text-add"><?php esc_html_e( 'New Text', 'prrint' ); ?></button>
								<div id="prrint-text-fields" hidden>
									<textarea id="prrint-text-content" rows="3" placeholder="<?php esc_attr_e( 'Your text here', 'prrint' ); ?>"></textarea>

									<p class="prrint-field-label"><?php esc_html_e( 'Font Family', 'prrint' ); ?></p>
									<div class="prrint-font-family-row">
										<input type="text" id="prrint-text-font" class="prrint-font-family-input" list="prrint-gfont-list" autocomplete="off" placeholder="<?php esc_attr_e( 'Default (Liberation Sans)', 'prrint' ); ?>" />
										<button type="button" class="prrint-tool" id="prrint-text-bold" title="<?php esc_attr_e( 'Bold', 'prrint' ); ?>"><strong>B</strong></button>
									</div>
									<datalist id="prrint-gfont-list">
										<?php foreach ( Prrint_Fonts::popular_families() as $gfont ) : ?>
											<option value="<?php echo esc_attr( $gfont ); ?>"></option>
										<?php endforeach; ?>
									</datalist>
									<p class="prrint-editor-hint"><?php esc_html_e( 'Type any Google Fonts name — start typing for suggestions.', 'prrint' ); ?></p>

									<div class="prrint-two-col">
										<div>
											<p class="prrint-field-label"><?php esc_html_e( 'Font Size', 'prrint' ); ?></p>
											<input type="number" class="prrint-number-input" id="prrint-text-size" min="2" max="50" step="1" value="8" />
										</div>
										<div>
											<p class="prrint-field-label"><?php esc_html_e( 'Alignment', 'prrint' ); ?></p>
											<div class="prrint-panel-row">
												<button type="button" class="prrint-tool" data-align="left" title="<?php esc_attr_e( 'Align left', 'prrint' ); ?>">⯇</button>
												<button type="button" class="prrint-tool" data-align="center" title="<?php esc_attr_e( 'Align center', 'prrint' ); ?>">≡</button>
												<button type="button" class="prrint-tool" data-align="right" title="<?php esc_attr_e( 'Align right', 'prrint' ); ?>">⯈</button>
											</div>
										</div>
									</div>

									<p class="prrint-swatch-label"><?php esc_html_e( 'Font Color', 'prrint' ); ?></p>
									<div class="prrint-swatch-row" id="prrint-text-color-swatches" data-target="color"></div>
									<p class="prrint-swatch-label"><?php esc_html_e( 'Background Color', 'prrint' ); ?></p>
									<div class="prrint-swatch-row" id="prrint-text-bg-swatches" data-target="bgColor"></div>

									<label class="prrint-slider-row"><span><?php esc_html_e( 'Line Spacing', 'prrint' ); ?> <output id="prrint-text-spacing-out">1.3</output></span>
										<input type="range" id="prrint-text-spacing" min="8" max="30" value="13" /></label>
									<label class="prrint-slider-row"><span><?php esc_html_e( 'Box width', 'prrint' ); ?></span>
										<input type="range" id="prrint-text-width" min="20" max="100" value="80" /></label>
									<label class="prrint-slider-row"><span><?php esc_html_e( 'Rotation', 'prrint' ); ?></span>
										<input type="range" id="prrint-text-rotation" min="-45" max="45" value="0" /></label>

									<div class="prrint-panel-row">
										<button type="button" class="prrint-btn-secondary" id="prrint-text-duplicate"><?php esc_html_e( 'Duplicate', 'prrint' ); ?></button>
										<button type="button" class="prrint-btn-secondary" id="prrint-text-delete"><?php esc_html_e( 'Delete', 'prrint' ); ?></button>
									</div>
								</div>
							</div>
							<div class="prrint-tool-panel" data-panel="textdesign" hidden>
								<div class="prrint-filter-grid" id="prrint-textdesign-grid"></div>
								<div class="prrint-panel-row">
									<button type="button" class="prrint-btn-secondary" id="prrint-textdesign-shuffle"><?php esc_html_e( 'Shuffle Layout', 'prrint' ); ?></button>
									<button type="button" class="prrint-btn-secondary" id="prrint-textdesign-invert"><?php esc_html_e( 'Invert', 'prrint' ); ?></button>
								</div>
							</div>
							<div class="prrint-tool-panel" data-panel="overlays" hidden>
								<div class="prrint-filter-grid" id="prrint-overlay-grid"></div>
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

						<div class="prrint-layer-toolbar" id="prrint-layer-toolbar" hidden>
							<button type="button" id="prrint-layer-edit"><?php esc_html_e( 'Edit', 'prrint' ); ?></button>
							<button type="button" id="prrint-layer-front"><?php esc_html_e( 'Move To Front', 'prrint' ); ?></button>
							<button type="button" id="prrint-layer-duplicate"><?php esc_html_e( 'Duplicate', 'prrint' ); ?></button>
							<button type="button" id="prrint-layer-delete"><?php esc_html_e( 'Delete', 'prrint' ); ?></button>
						</div>
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
