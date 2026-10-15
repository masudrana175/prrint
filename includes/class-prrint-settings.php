<?php
/**
 * Global settings page: WooCommerce → Prrint Studio.
 *
 * @package prrint
 */

defined( 'ABSPATH' ) || exit;

class Prrint_Settings {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PRRINT_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=prrint-settings' ) ) . '">' . esc_html__( 'Settings', 'prrint' ) . '</a>'
		);
		return $links;
	}

	public static function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Prrint Studio', 'prrint' ),
			__( 'Prrint Studio', 'prrint' ),
			'manage_woocommerce',
			'prrint-settings',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register() {
		register_setting( 'prrint_settings_group', 'prrint_settings', array(
			'type'              => 'array',
			'sanitize_callback' => array( __CLASS__, 'sanitize' ),
		) );
	}

	public static function enqueue( $hook ) {
		$screen      = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_settings = 'woocommerce_page_prrint-settings' === $hook;
		$is_product  = in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && $screen && 'product' === $screen->post_type;

		if ( ! $is_settings && ! $is_product ) {
			return;
		}

		wp_enqueue_style( 'prrint-admin', PRRINT_URL . 'assets/css/admin.css', array(), PRRINT_VERSION );
		wp_enqueue_script( 'prrint-admin', PRRINT_URL . 'assets/js/admin.js', array(), PRRINT_VERSION, true );
	}

	/**
	 * Sanitize the whole settings array from the form.
	 */
	public static function sanitize( $input ) {
		$defaults = prrint_default_settings();
		$out      = array();

		$out['sizes']  = self::sanitize_sizes( isset( $input['sizes'] ) ? $input['sizes'] : array() );
		$out['papers'] = self::sanitize_papers( isset( $input['papers'] ) ? $input['papers'] : array() );

		if ( empty( $out['sizes'] ) ) {
			$out['sizes'] = $defaults['sizes'];
		}
		if ( empty( $out['papers'] ) ) {
			$out['papers'] = $defaults['papers'];
		}

		// Empty is allowed for both: no rows = the dropdown isn't shown.
		$out['styles']      = self::sanitize_styles( isset( $input['styles'] ) ? $input['styles'] : array() );
		$out['edge_colors'] = self::sanitize_edge_colors( isset( $input['edge_colors'] ) ? $input['edge_colors'] : array() );

		foreach ( prrint_default_theme_colors() as $key => $default ) {
			$color       = isset( $input[ $key ] ) ? sanitize_hex_color( $input[ $key ] ) : '';
			$out[ $key ] = $color ? $color : $default;
		}

		$out['max_mb']       = max( 1, min( 200, isset( $input['max_mb'] ) ? absint( $input['max_mb'] ) : $defaults['max_mb'] ) );
		$out['jpeg_quality'] = max( 50, min( 100, isset( $input['jpeg_quality'] ) ? absint( $input['jpeg_quality'] ) : $defaults['jpeg_quality'] ) );
		$out['target_dpi']   = max( 72, min( 1200, isset( $input['target_dpi'] ) ? absint( $input['target_dpi'] ) : $defaults['target_dpi'] ) );
		$out['min_dpi']      = max( 30, min( 600, isset( $input['min_dpi'] ) ? absint( $input['min_dpi'] ) : $defaults['min_dpi'] ) );
		$out['border_in']    = max( 0.05, min( 2, isset( $input['border_in'] ) ? (float) $input['border_in'] : $defaults['border_in'] ) );
		$out['upload_retention_days'] = max( 1, min( 365, isset( $input['upload_retention_days'] ) ? absint( $input['upload_retention_days'] ) : $defaults['upload_retention_days'] ) );
		$out['guest_library_retention_days'] = max( 1, min( 365, isset( $input['guest_library_retention_days'] ) ? absint( $input['guest_library_retention_days'] ) : $defaults['guest_library_retention_days'] ) );

		$notify                       = isset( $input['framer_notify_email'] ) ? sanitize_email( $input['framer_notify_email'] ) : '';
		$out['framer_notify_email'] = is_email( $notify ) ? $notify : '';
		$out['framer_mailin_address'] = isset( $input['framer_mailin_address'] ) ? sanitize_textarea_field( $input['framer_mailin_address'] ) : $defaults['framer_mailin_address'];

		$out['studio_product_id'] = isset( $input['studio_product_id'] ) ? absint( $input['studio_product_id'] ) : 0;

		$out['enabled_tools'] = array_values( array_intersect(
			prrint_toggleable_tools(),
			isset( $input['enabled_tools'] ) && is_array( $input['enabled_tools'] ) ? $input['enabled_tools'] : array()
		) );

		$out['text_templates_enabled'] = array_values( array_intersect(
			prrint_text_template_ids(),
			isset( $input['text_templates_enabled'] ) && is_array( $input['text_templates_enabled'] ) ? $input['text_templates_enabled'] : array()
		) );

		$out['enabled_filters'] = array_values( array_intersect(
			prrint_toggleable_filters(),
			isset( $input['enabled_filters'] ) && is_array( $input['enabled_filters'] ) ? $input['enabled_filters'] : array()
		) );

		$out['enabled_overlays'] = array_values( array_intersect(
			prrint_toggleable_overlays(),
			isset( $input['enabled_overlays'] ) && is_array( $input['enabled_overlays'] ) ? $input['enabled_overlays'] : array()
		) );

		$out['enabled_shapes'] = array_values( array_intersect(
			prrint_toggleable_shapes(),
			isset( $input['enabled_shapes'] ) && is_array( $input['enabled_shapes'] ) ? $input['enabled_shapes'] : array()
		) );

		foreach ( array( 'text_colors', 'text_bg_colors', 'border_colors', 'shape_colors', 'draw_colors' ) as $key ) {
			$out[ $key ] = self::sanitize_color_list( isset( $input[ $key ] ) ? $input[ $key ] : '', $defaults[ $key ] );
		}

		return $out;
	}

	/**
	 * A comma-separated list of hex colors from a single text field, e.g.
	 * "#ffffff, #000000, transparent" — "transparent"/"none"/blank entries
	 * become '' (only meaningful for backgrounds, where '' means "no fill").
	 */
	protected static function sanitize_color_list( $raw, $fallback ) {
		$out = array();
		foreach ( explode( ',', (string) $raw ) as $part ) {
			$part = trim( $part );
			if ( '' === $part || in_array( strtolower( $part ), array( 'none', 'transparent' ), true ) ) {
				$out[] = '';
				continue;
			}
			if ( preg_match( '/^#([0-9a-f]{6}|[0-9a-f]{3})$/i', $part ) ) {
				$out[] = '#' . ltrim( $part, '#' );
			}
		}
		$out = array_slice( $out, 0, 16 );
		return empty( $out ) ? $fallback : $out;
	}

	/**
	 * Sanitize a posted sizes table (shared with the product tab).
	 */
	public static function sanitize_sizes( $rows ) {
		$sizes = array();
		if ( ! is_array( $rows ) ) {
			return $sizes;
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label = isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '';
			$w     = isset( $row['w'] ) ? (float) $row['w'] : 0;
			$h     = isset( $row['h'] ) ? (float) $row['h'] : 0;
			$price = isset( $row['price'] ) ? (float) $row['price'] : 0;
			if ( '' === $label || $w <= 0 || $h <= 0 ) {
				continue;
			}
			$sizes[] = array(
				'label' => $label,
				'w'     => $w,
				'h'     => $h,
				'price' => (float) max( 0, $price ),
			);
		}
		return array_slice( $sizes, 0, 30 );
	}

	/**
	 * Sanitize a posted papers table (shared with the product tab).
	 */
	public static function sanitize_papers( $rows ) {
		$papers = array();
		if ( ! is_array( $rows ) ) {
			return $papers;
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label = isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '';
			if ( '' === $label ) {
				continue;
			}
			$papers[] = array(
				'label'     => $label,
				'surcharge' => (float) max( 0, isset( $row['surcharge'] ) ? (float) $row['surcharge'] : 0 ),
			);
		}
		return array_slice( $papers, 0, 20 );
	}

	public static function sanitize_styles( $rows ) {
		$styles = array();
		if ( ! is_array( $rows ) ) {
			return $styles;
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label = isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '';
			if ( '' === $label ) {
				continue;
			}
			$styles[] = array(
				'label'     => $label,
				'surcharge' => (float) max( 0, isset( $row['surcharge'] ) ? (float) $row['surcharge'] : 0 ),
				'edge'        => ! empty( $row['edge'] ),
				'description' => isset( $row['description'] ) ? sanitize_text_field( $row['description'] ) : '',
			);
		}
		return array_slice( $styles, 0, 40 );
	}

	public static function sanitize_edge_colors( $rows ) {
		$colors = array();
		if ( ! is_array( $rows ) ) {
			return $colors;
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label = isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '';
			$color = isset( $row['color'] ) ? sanitize_hex_color( $row['color'] ) : '';
			if ( '' === $label || ! $color ) {
				continue;
			}
			$colors[] = array(
				'code'    => isset( $row['code'] ) ? substr( preg_replace( '/[^A-Za-z0-9]/', '', (string) $row['code'] ), 0, 6 ) : '',
				'label'   => $label,
				'color'   => $color,
				'exclude' => isset( $row['exclude'] ) ? sanitize_text_field( $row['exclude'] ) : '',
			);
		}
		return array_slice( $colors, 0, 100 );
	}

	/* --------------------------------------------------------------------
	 * Rendering.
	 * ------------------------------------------------------------------ */

	public static function render_page() {
		$s        = prrint_settings();
		$currency = get_woocommerce_currency_symbol();
		?>
		<div class="wrap prrint-admin-wrap">
			<div class="prrint-admin-header">
				<h1 class="prrint-admin-title">
					<span class="prrint-logo" aria-hidden="true"><?php echo Prrint_Icons::get( 'photo', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<?php esc_html_e( 'Prrint — Photo Print Studio', 'prrint' ); ?>
				</h1>
				<p class="prrint-admin-sub">
					<?php esc_html_e( 'Global defaults for every print product. Individual products can override these from the "Print Studio" tab in the product editor.', 'prrint' ); ?>
				</p>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'prrint_settings_group' ); ?>

				<?php
				// Every tab lives in this one form, so "Save" always submits
				// every setting (sanitize() rebuilds the whole option from the
				// posted fields). Tabs only show/hide cards; without JS all
				// cards stay visible.
				$tabs = array(
					'products'   => array( 'layers', __( 'Products', 'prrint' ) ),
					'editor'     => array( 'toolbox', __( 'Editor', 'prrint' ) ),
					'appearance' => array( 'sun', __( 'Colors', 'prrint' ) ),
					'uploads'    => array( 'sliders', __( 'Quality & uploads', 'prrint' ) ),
					'studio'     => array( 'link', __( 'Studio page', 'prrint' ) ),
					'framers'    => array( 'bookmark', __( 'Framers', 'prrint' ) ),
				);
				?>
				<div class="prrint-tabbar">
					<nav class="prrint-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Settings sections', 'prrint' ); ?>">
						<?php foreach ( $tabs as $key => $tab ) : ?>
							<button type="button" role="tab" class="prrint-tab" data-prrint-tab-target="<?php echo esc_attr( $key ); ?>" aria-selected="false">
								<?php echo Prrint_Icons::get( $tab[0], 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<span><?php echo esc_html( $tab[1] ); ?></span>
							</button>
						<?php endforeach; ?>
					</nav>
					<?php submit_button( __( 'Save settings', 'prrint' ), 'primary prrint-tabbar-save', 'submit', false ); ?>
				</div>

				<div class="prrint-card" data-prrint-tab="products">
					<h2><span class="prrint-card-icon" aria-hidden="true"><?php echo Prrint_Icons::get( 'ruler', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span> <?php esc_html_e( 'Print sizes', 'prrint' ); ?></h2>
					<p class="description"><?php esc_html_e( 'The sizes customers can order. Dimensions are in inches; the crop editor locks to each size\'s aspect ratio.', 'prrint' ); ?></p>
					<?php self::render_sizes_table( 'prrint_settings[sizes]', $s['sizes'], $currency ); ?>
				</div>

				<div class="prrint-card" data-prrint-tab="products">
					<h2><span class="prrint-card-icon" aria-hidden="true"><?php echo Prrint_Icons::get( 'paper', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span> <?php esc_html_e( 'Paper & finish options', 'prrint' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Each paper can add a surcharge on top of the size price.', 'prrint' ); ?></p>
					<?php self::render_papers_table( 'prrint_settings[papers]', $s['papers'], $currency ); ?>
				</div>

				<div class="prrint-card" data-prrint-tab="products">
					<h2><span class="prrint-card-icon" aria-hidden="true"><?php echo Prrint_Icons::get( 'layers', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span> <?php esc_html_e( 'Product styles', 'prrint' ); ?></h2>
					<p class="description"><?php esc_html_e( 'What the print is made into — e.g. a plain paper print, ColorLite or Color Box. Shown to customers as a "Style" dropdown under Paper, with an optional surcharge. Tick "Colored edge" for styles that have an edge, so customers can pick an Edge Color for them. Remove every row to hide the Style dropdown.', 'prrint' ); ?></p>
					<?php self::render_styles_table( 'prrint_settings[styles]', $s['styles'], $currency ); ?>
				</div>

				<div class="prrint-card" data-prrint-tab="products">
					<h2><span class="prrint-card-icon" aria-hidden="true"><?php echo Prrint_Icons::get( 'square', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span> <?php esc_html_e( 'Edge colors', 'prrint' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Colors offered in the "Edge Color" dropdown for styles with a colored edge. The selected color is drawn around the customer\'s photo preview so they can see it before ordering. The code is shown with the name (e.g. "A - Matte Black") and recorded on the order. Use "Not available for" to hide a color for specific styles.', 'prrint' ); ?></p>
					<?php self::render_edge_colors_table( 'prrint_settings[edge_colors]', $s['edge_colors'] ); ?>
				</div>

				<div class="prrint-card" data-prrint-tab="uploads">
					<h2><span class="prrint-card-icon" aria-hidden="true"><?php echo Prrint_Icons::get( 'sliders', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span> <?php esc_html_e( 'Quality & uploads', 'prrint' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="prrint_max_mb"><?php esc_html_e( 'Max upload size (MB)', 'prrint' ); ?></label></th>
							<td><input type="number" id="prrint_max_mb" name="prrint_settings[max_mb]" value="<?php echo esc_attr( $s['max_mb'] ); ?>" min="1" max="200" class="small-text" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="prrint_target_dpi"><?php esc_html_e( 'Print resolution (DPI)', 'prrint' ); ?></label></th>
							<td>
								<input type="number" id="prrint_target_dpi" name="prrint_settings[target_dpi]" value="<?php echo esc_attr( $s['target_dpi'] ); ?>" min="72" max="1200" class="small-text" />
								<p class="description"><?php esc_html_e( 'Resolution of the generated print-ready files (capped at the source photo resolution).', 'prrint' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="prrint_min_dpi"><?php esc_html_e( 'Low quality warning below (DPI)', 'prrint' ); ?></label></th>
							<td><input type="number" id="prrint_min_dpi" name="prrint_settings[min_dpi]" value="<?php echo esc_attr( $s['min_dpi'] ); ?>" min="30" max="600" class="small-text" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="prrint_jpeg_quality"><?php esc_html_e( 'JPEG quality', 'prrint' ); ?></label></th>
							<td><input type="number" id="prrint_jpeg_quality" name="prrint_settings[jpeg_quality]" value="<?php echo esc_attr( $s['jpeg_quality'] ); ?>" min="50" max="100" class="small-text" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="prrint_border_in"><?php esc_html_e( 'White border width (inches)', 'prrint' ); ?></label></th>
							<td>
								<input type="number" id="prrint_border_in" name="prrint_settings[border_in]" value="<?php echo esc_attr( $s['border_in'] ); ?>" min="0.05" max="2" step="0.05" class="small-text" />
								<p class="description"><?php esc_html_e( 'Used when the customer selects the optional white border.', 'prrint' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="prrint_upload_retention_days"><?php esc_html_e( 'Keep uploaded photos for (days)', 'prrint' ); ?></label></th>
							<td>
								<input type="number" id="prrint_upload_retention_days" name="prrint_settings[upload_retention_days]" value="<?php echo esc_attr( $s['upload_retention_days'] ); ?>" min="1" max="365" class="small-text" />
								<p class="description"><?php esc_html_e( 'How long an uploaded photo is kept before automatic deletion — applies to every upload, signed in or not. A signed-in customer\'s permanent "My Photos" library is separate and never auto-deleted.', 'prrint' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="prrint_guest_library_retention_days"><?php esc_html_e( 'Keep guest (not signed in) photo libraries for (days)', 'prrint' ); ?></label></th>
							<td>
								<input type="number" id="prrint_guest_library_retention_days" name="prrint_settings[guest_library_retention_days]" value="<?php echo esc_attr( $s['guest_library_retention_days'] ); ?>" min="1" max="365" class="small-text" />
								<p class="description"><?php esc_html_e( 'Customers who upload without signing in still get a "Your Photos" section on the studio page, remembered via a cookie so they can come back and reuse a photo without re-uploading. Unlike a signed-in customer\'s permanent library, it expires after this many days of inactivity (any new upload resets the clock).', 'prrint' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="prrint-card" data-prrint-tab="studio">
					<h2><span class="prrint-card-icon" aria-hidden="true"><?php echo Prrint_Icons::get( 'link', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span> <?php esc_html_e( 'Standalone studio page', 'prrint' ); ?></h2>
					<p class="description">
						<?php
						printf(
							/* translators: %s: [prrint_studio] shortcode, shown as code */
							esc_html__( 'The %s shortcode puts the studio on its own WordPress Page. Pick which product it designs against for pricing and checkout — customers never need to visit that product\'s own page.', 'prrint' ),
							'<code>[prrint_studio]</code>'
						);
						?>
					</p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="prrint_studio_product_id"><?php esc_html_e( 'Product', 'prrint' ); ?></label></th>
							<td><?php self::render_studio_product_select( (int) $s['studio_product_id'] ); ?></td>
						</tr>
						<?php $preview_url = self::studio_preview_url(); ?>
						<?php if ( $preview_url ) : ?>
							<tr>
								<th scope="row"><?php esc_html_e( 'Preview', 'prrint' ); ?></th>
								<td><a href="<?php echo esc_url( $preview_url ); ?>" class="button" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open the studio page ↗', 'prrint' ); ?></a></td>
							</tr>
						<?php endif; ?>
					</table>
				</div>

				<div class="prrint-card" data-prrint-tab="framers">
					<h2><span class="prrint-card-icon" aria-hidden="true"><?php echo Prrint_Icons::get( 'bookmark', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span> <?php esc_html_e( 'Framer accounts', 'prrint' ); ?></h2>
					<p class="description">
						<?php
						printf(
							/* translators: %s: link to the Framers admin screen */
							esc_html__( 'Framers apply on the Framers Corner page and you approve them under %s. Only approved framers see what is inside the [prrint_framers_corner] shortcode.', 'prrint' ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=prrint-framers' ) ) . '">' . esc_html__( 'WooCommerce → Framers', 'prrint' ) . '</a>'
						);
						?>
					</p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="prrint_framer_notify_email"><?php esc_html_e( 'Send new applications to', 'prrint' ); ?></label></th>
							<td>
								<input type="email" class="regular-text" id="prrint_framer_notify_email" name="prrint_settings[framer_notify_email]" value="<?php echo esc_attr( $s['framer_notify_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
								<p class="description"><?php esc_html_e( 'Leave empty to use the site admin email.', 'prrint' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="prrint_framer_mailin_address"><?php esc_html_e( 'Mail-in address', 'prrint' ); ?></label></th>
							<td>
								<textarea class="regular-text" rows="3" id="prrint_framer_mailin_address" name="prrint_settings[framer_mailin_address]"><?php echo esc_textarea( $s['framer_mailin_address'] ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Where framers send prints they mail in. Shown on the order form.', 'prrint' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Prices', 'prrint' ); ?></th>
							<td><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=prrint-wholesale' ) ); ?>"><?php esc_html_e( 'Edit wholesale prices', 'prrint' ); ?></a></td>
						</tr>
					</table>
				</div>

				<div class="prrint-card" data-prrint-tab="editor">
					<h2><span class="prrint-card-icon" aria-hidden="true"><?php echo Prrint_Icons::get( 'toolbox', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span> <?php esc_html_e( 'Editor tools', 'prrint' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Turn tools off to simplify the editor for your store. Transform (crop & rotate) is always on.', 'prrint' ); ?></p>
					<?php self::render_tool_checkboxes( $s['enabled_tools'] ); ?>
				</div>

				<div class="prrint-card" data-prrint-tab="editor">
					<h2><span class="prrint-card-icon" aria-hidden="true"><?php echo Prrint_Icons::get( 'brush', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span> <?php esc_html_e( 'Filters, Overlays & Shapes', 'prrint' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Pick which presets show inside each tool\'s panel — turn off ones your store doesn\'t want to offer. "None" is always available for Filters and Overlays so a customer can clear one they applied.', 'prrint' ); ?></p>
					<h3><?php esc_html_e( 'Filters', 'prrint' ); ?></h3>
					<?php self::render_filter_checkboxes( $s['enabled_filters'] ); ?>
					<h3><?php esc_html_e( 'Overlays', 'prrint' ); ?></h3>
					<?php self::render_overlay_checkboxes( $s['enabled_overlays'] ); ?>
					<h3><?php esc_html_e( 'Elements (shapes)', 'prrint' ); ?></h3>
					<?php self::render_shape_checkboxes( $s['enabled_shapes'] ); ?>
				</div>

				<div class="prrint-card" data-prrint-tab="editor">
					<h2><span class="prrint-card-icon" aria-hidden="true"><?php echo Prrint_Icons::get( 'bookmark', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span> <?php esc_html_e( 'Text Design layouts', 'prrint' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Which pre-made word-art layouts show in the Text Design panel.', 'prrint' ); ?></p>
					<?php self::render_text_template_checkboxes( $s['text_templates_enabled'] ); ?>
				</div>

				<div class="prrint-card" data-prrint-tab="editor">
					<h2><span class="prrint-card-icon" aria-hidden="true"><?php echo Prrint_Icons::get( 'palette', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span> <?php esc_html_e( 'Color palettes', 'prrint' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Comma-separated hex colors offered to customers in each panel. Use "transparent" for a swatch with no fill (only meaningful for backgrounds).', 'prrint' ); ?></p>
					<table class="form-table" role="presentation">
						<?php
						self::render_color_list_field( 'text_colors', __( 'Text color', 'prrint' ), $s['text_colors'] );
						self::render_color_list_field( 'text_bg_colors', __( 'Text background color', 'prrint' ), $s['text_bg_colors'] );
						self::render_color_list_field( 'border_colors', __( 'Border color', 'prrint' ), $s['border_colors'] );
						self::render_color_list_field( 'shape_colors', __( 'Elements (shape) color', 'prrint' ), $s['shape_colors'] );
						self::render_color_list_field( 'draw_colors', __( 'Draw brush color', 'prrint' ), $s['draw_colors'] );
						?>
					</table>
				</div>

				<div class="prrint-card" data-prrint-tab="appearance">
					<h2><span class="prrint-card-icon" aria-hidden="true"><?php echo Prrint_Icons::get( 'sun', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span> <?php esc_html_e( 'Studio colors', 'prrint' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Match the studio to your brand. These apply to the upload area, photo cards, buttons and the editor, and override your theme\'s own button colors inside the studio.', 'prrint' ); ?></p>
					<table class="form-table" role="presentation">
						<?php
						self::render_color_field( 'color_primary', __( 'Primary (buttons & highlights)', 'prrint' ), $s['color_primary'] );
						self::render_color_field( 'color_primary_text', __( 'Text on primary buttons', 'prrint' ), $s['color_primary_text'] );
						self::render_color_field( 'color_text', __( 'Text', 'prrint' ), $s['color_text'] );
						self::render_color_field( 'color_card_bg', __( 'Photo card background', 'prrint' ), $s['color_card_bg'] );
						self::render_color_field( 'color_summary_bg', __( 'Total / Add to cart bar', 'prrint' ), $s['color_summary_bg'] );
						?>
					</table>
				</div>

				<div class="prrint-save-bar">
					<?php submit_button( __( 'Save settings', 'prrint' ), 'primary', 'submit_bottom', false ); ?>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * URL of the auto-created "Create Your Print" page, if it's been
	 * published — used for the settings page's "Preview" button.
	 */
	protected static function studio_preview_url() {
		$page_id = (int) get_option( 'prrint_sample_page' );
		if ( ! $page_id || 'publish' !== get_post_status( $page_id ) ) {
			return '';
		}
		return get_permalink( $page_id );
	}

	/**
	 * <select> of Print-Studio-enabled products for the studio page setting.
	 */
	protected static function render_studio_product_select( $current ) {
		$products = wc_get_products( array(
			'limit'      => -1,
			'status'     => array( 'publish', 'draft', 'private' ),
			'meta_key'   => '_prrint_enabled', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value' => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		) );

		if ( empty( $products ) ) {
			echo '<p>' . esc_html__( 'No products have Print Studio enabled yet. Enable it from the "Print Studio" tab in a product editor first.', 'prrint' ) . '</p>';
			return;
		}
		?>
		<select id="prrint_studio_product_id" name="prrint_settings[studio_product_id]">
			<option value="0"><?php esc_html_e( '— Use the first enabled product —', 'prrint' ); ?></option>
			<?php foreach ( $products as $product ) : ?>
				<option value="<?php echo (int) $product->get_id(); ?>" <?php selected( $current, $product->get_id() ); ?>>
					<?php echo esc_html( $product->get_name() . ' (#' . $product->get_id() . ( 'publish' === $product->get_status() ? '' : ', ' . $product->get_status() ) . ')' ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	protected static function render_tool_checkboxes( $enabled ) {
		$labels = array(
			'filters'    => __( 'Filters', 'prrint' ),
			'adjust'     => __( 'Adjust', 'prrint' ),
			'focus'      => __( 'Focus', 'prrint' ),
			'text'       => __( 'Text', 'prrint' ),
			'textdesign' => __( 'Text Design', 'prrint' ),
			'elements'   => __( 'Elements', 'prrint' ),
			'draw'       => __( 'Draw', 'prrint' ),
			'overlays'   => __( 'Overlays', 'prrint' ),
			'border'     => __( 'Border', 'prrint' ),
		);
		echo '<p class="prrint-checkbox-grid">';
		foreach ( $labels as $id => $label ) {
			printf(
				'<label><input type="checkbox" name="prrint_settings[enabled_tools][]" value="%1$s" %2$s /> %3$s</label>',
				esc_attr( $id ),
				checked( in_array( $id, $enabled, true ), true, false ),
				esc_html( $label )
			);
		}
		echo '</p>';
	}

	protected static function render_filter_checkboxes( $enabled ) {
		$labels = array(
			'bw'      => __( 'B&W', 'prrint' ),
			'warm'    => __( 'Warm', 'prrint' ),
			'cold'    => __( 'Cold', 'prrint' ),
			'vintage' => __( 'Vintage', 'prrint' ),
			'duotone' => __( 'DuoTone', 'prrint' ),
			'legacy'  => __( 'Legacy', 'prrint' ),
			'smooth'  => __( 'Smooth', 'prrint' ),
		);
		echo '<p class="prrint-checkbox-grid">';
		foreach ( $labels as $id => $label ) {
			printf(
				'<label><input type="checkbox" name="prrint_settings[enabled_filters][]" value="%1$s" %2$s /> %3$s</label>',
				esc_attr( $id ),
				checked( in_array( $id, $enabled, true ), true, false ),
				esc_html( $label )
			);
		}
		echo '</p>';
	}

	protected static function render_overlay_checkboxes( $enabled ) {
		$labels = array(
			'vignette'  => __( 'Vignette', 'prrint' ),
			'glow'      => __( 'Glow', 'prrint' ),
			'lightleak' => __( 'Light Leak', 'prrint' ),
			'grain'     => __( 'Grain', 'prrint' ),
			'bokeh'     => __( 'Bokeh', 'prrint' ),
			'scratches' => __( 'Scratches', 'prrint' ),
		);
		echo '<p class="prrint-checkbox-grid">';
		foreach ( $labels as $id => $label ) {
			printf(
				'<label><input type="checkbox" name="prrint_settings[enabled_overlays][]" value="%1$s" %2$s /> %3$s</label>',
				esc_attr( $id ),
				checked( in_array( $id, $enabled, true ), true, false ),
				esc_html( $label )
			);
		}
		echo '</p>';
	}

	protected static function render_shape_checkboxes( $enabled ) {
		$labels = array(
			'circle'   => __( 'Circle', 'prrint' ),
			'square'   => __( 'Square', 'prrint' ),
			'triangle' => __( 'Triangle', 'prrint' ),
			'diamond'  => __( 'Diamond', 'prrint' ),
			'pentagon' => __( 'Pentagon', 'prrint' ),
			'hexagon'  => __( 'Hexagon', 'prrint' ),
			'star'     => __( 'Star', 'prrint' ),
			'heart'    => __( 'Heart', 'prrint' ),
			'arrow'    => __( 'Arrow', 'prrint' ),
			'cross'    => __( 'Cross', 'prrint' ),
			'line'     => __( 'Line', 'prrint' ),
		);
		echo '<p class="prrint-checkbox-grid">';
		foreach ( $labels as $id => $label ) {
			printf(
				'<label><input type="checkbox" name="prrint_settings[enabled_shapes][]" value="%1$s" %2$s /> %3$s</label>',
				esc_attr( $id ),
				checked( in_array( $id, $enabled, true ), true, false ),
				esc_html( $label )
			);
		}
		echo '</p>';
	}

	protected static function render_text_template_checkboxes( $enabled ) {
		$labels = array(
			'banner'    => __( 'Banner', 'prrint' ),
			'stacked'   => __( 'Stacked', 'prrint' ),
			'quote'     => __( 'Quote', 'prrint' ),
			'corner'    => __( 'Corner Tag', 'prrint' ),
			'stamp'     => __( 'Stamp', 'prrint' ),
			'sidestrip' => __( 'Side Strip', 'prrint' ),
		);
		echo '<p class="prrint-checkbox-grid">';
		foreach ( $labels as $id => $label ) {
			printf(
				'<label><input type="checkbox" name="prrint_settings[text_templates_enabled][]" value="%1$s" %2$s /> %3$s</label>',
				esc_attr( $id ),
				checked( in_array( $id, $enabled, true ), true, false ),
				esc_html( $label )
			);
		}
		echo '</p>';
	}

	protected static function render_color_list_field( $key, $label, $colors ) {
		$value = implode( ', ', array_map( function ( $c ) {
			return '' === $c ? 'transparent' : $c;
		}, $colors ) );
		?>
		<tr>
			<th scope="row"><label for="prrint_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input type="text" id="prrint_<?php echo esc_attr( $key ); ?>" name="prrint_settings[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>" class="large-text prrint-color-list-input" />
				<p class="prrint-swatch-preview" data-prrint-swatch-preview></p>
			</td>
		</tr>
		<?php
	}

	protected static function render_color_field( $key, $label, $value ) {
		$default = prrint_default_theme_colors()[ $key ];
		?>
		<tr>
			<th scope="row"><label for="prrint_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input type="color" id="prrint_<?php echo esc_attr( $key ); ?>" name="prrint_settings[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>" class="prrint-color-input" />
				<code class="prrint-color-value"><?php echo esc_html( $value ); ?></code>
				<button type="button" class="button-link prrint-color-reset" data-default="<?php echo esc_attr( $default ); ?>"><?php esc_html_e( 'Reset', 'prrint' ); ?></button>
			</td>
		</tr>
		<?php
	}

	public static function render_styles_table( $name, $styles, $currency ) {
		$row = function ( $i, $style ) use ( $name ) {
			$n = esc_attr( $name ) . '[' . $i . ']';
			?>
			<tr>
				<td><input type="text" name="<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[label]" value="<?php echo esc_attr( $style['label'] ); ?>" /></td>
				<td><input type="number" step="0.01" min="0" name="<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[surcharge]" value="<?php echo esc_attr( $style['surcharge'] ); ?>" /></td>
				<td><label><input type="checkbox" name="<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[edge]" value="1" <?php checked( ! empty( $style['edge'] ) ); ?> /> <?php esc_html_e( 'Yes', 'prrint' ); ?></label></td>
				<td><input type="text" class="prrint-wide-input" name="<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[description]" value="<?php echo esc_attr( isset( $style['description'] ) ? $style['description'] : '' ); ?>" /></td>
				<td class="prrint-col-actions"><button type="button" class="button-link-delete prrint-remove-row" aria-label="<?php esc_attr_e( 'Remove row', 'prrint' ); ?>">✕</button></td>
			</tr>
			<?php
		};
		?>
		<table class="widefat striped prrint-table" data-prrint-table="styles">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Style', 'prrint' ); ?></th>
					<th><?php printf( /* translators: %s currency symbol */ esc_html__( 'Surcharge (%s)', 'prrint' ), esc_html( $currency ) ); ?></th>
					<th><?php esc_html_e( 'Colored edge', 'prrint' ); ?></th>
					<th><?php esc_html_e( 'Description (shown to customers)', 'prrint' ); ?></th>
					<th class="prrint-col-actions"></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( array_values( $styles ) as $i => $style ) {
					$row( (int) $i, $style );
				}
				?>
			</tbody>
		</table>
		<template class="prrint-row-template">
			<?php $row( '__i__', array( 'label' => '', 'surcharge' => '', 'edge' => false, 'description' => '' ) ); ?>
		</template>
		<p><button type="button" class="button prrint-add-row"><?php esc_html_e( '+ Add style', 'prrint' ); ?></button></p>
		<?php
	}

	public static function render_edge_colors_table( $name, $colors ) {
		$row = function ( $i, $edge ) use ( $name ) {
			$n = esc_attr( $name ) . '[' . $i . ']';
			?>
			<tr>
				<td><input type="text" class="prrint-code-input" maxlength="6" name="<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[code]" value="<?php echo esc_attr( isset( $edge['code'] ) ? $edge['code'] : '' ); ?>" /></td>
				<td><input type="text" name="<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[label]" value="<?php echo esc_attr( $edge['label'] ); ?>" /></td>
				<td><input type="color" name="<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[color]" value="<?php echo esc_attr( $edge['color'] ); ?>" class="prrint-color-input" /></td>
				<td><input type="text" placeholder="<?php esc_attr_e( 'e.g. ColorBox', 'prrint' ); ?>" name="<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[exclude]" value="<?php echo esc_attr( isset( $edge['exclude'] ) ? $edge['exclude'] : '' ); ?>" /></td>
				<td class="prrint-col-actions"><button type="button" class="button-link-delete prrint-remove-row" aria-label="<?php esc_attr_e( 'Remove row', 'prrint' ); ?>">✕</button></td>
			</tr>
			<?php
		};
		?>
		<div class="prrint-table-scroll">
		<table class="widefat striped prrint-table" data-prrint-table="edge_colors">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Code', 'prrint' ); ?></th>
					<th><?php esc_html_e( 'Name', 'prrint' ); ?></th>
					<th><?php esc_html_e( 'Color', 'prrint' ); ?></th>
					<th><?php esc_html_e( 'Not available for (styles, comma-separated)', 'prrint' ); ?></th>
					<th class="prrint-col-actions"></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( array_values( $colors ) as $i => $edge ) {
					$row( (int) $i, $edge );
				}
				?>
			</tbody>
		</table>
		</div>
		<template class="prrint-row-template">
			<?php $row( '__i__', array( 'code' => '', 'label' => '', 'color' => '#111111', 'exclude' => '' ) ); ?>
		</template>
		<p><button type="button" class="button prrint-add-row"><?php esc_html_e( '+ Add edge color', 'prrint' ); ?></button></p>
		<?php
	}

	/**
	 * Repeatable sizes table (also used by the product tab).
	 *
	 * @param string $name     Input name prefix, e.g. "prrint_settings[sizes]".
	 * @param array  $sizes    Rows to prefill.
	 * @param string $currency Currency symbol for column headers.
	 */
	public static function render_sizes_table( $name, $sizes, $currency ) {
		?>
		<table class="widefat striped prrint-table" data-prrint-table="sizes">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Label', 'prrint' ); ?></th>
					<th><?php esc_html_e( 'Width (in)', 'prrint' ); ?></th>
					<th><?php esc_html_e( 'Height (in)', 'prrint' ); ?></th>
					<th><?php printf( /* translators: %s currency symbol */ esc_html__( 'Price (%s)', 'prrint' ), esc_html( $currency ) ); ?></th>
					<th class="prrint-col-actions"></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( array_values( $sizes ) as $i => $size ) : ?>
					<tr>
						<td><input type="text" name="<?php echo esc_attr( $name ); ?>[<?php echo (int) $i; ?>][label]" value="<?php echo esc_attr( $size['label'] ); ?>" /></td>
						<td><input type="number" step="0.1" min="0.5" name="<?php echo esc_attr( $name ); ?>[<?php echo (int) $i; ?>][w]" value="<?php echo esc_attr( $size['w'] ); ?>" /></td>
						<td><input type="number" step="0.1" min="0.5" name="<?php echo esc_attr( $name ); ?>[<?php echo (int) $i; ?>][h]" value="<?php echo esc_attr( $size['h'] ); ?>" /></td>
						<td><input type="number" step="0.01" min="0" name="<?php echo esc_attr( $name ); ?>[<?php echo (int) $i; ?>][price]" value="<?php echo esc_attr( $size['price'] ); ?>" /></td>
						<td class="prrint-col-actions"><button type="button" class="button-link-delete prrint-remove-row" aria-label="<?php esc_attr_e( 'Remove row', 'prrint' ); ?>">✕</button></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<template class="prrint-row-template">
			<tr>
				<td><input type="text" name="<?php echo esc_attr( $name ); ?>[__i__][label]" value="" /></td>
				<td><input type="number" step="0.1" min="0.5" name="<?php echo esc_attr( $name ); ?>[__i__][w]" value="" /></td>
				<td><input type="number" step="0.1" min="0.5" name="<?php echo esc_attr( $name ); ?>[__i__][h]" value="" /></td>
				<td><input type="number" step="0.01" min="0" name="<?php echo esc_attr( $name ); ?>[__i__][price]" value="" /></td>
				<td class="prrint-col-actions"><button type="button" class="button-link-delete prrint-remove-row" aria-label="<?php esc_attr_e( 'Remove row', 'prrint' ); ?>">✕</button></td>
			</tr>
		</template>
		<p><button type="button" class="button prrint-add-row"><?php esc_html_e( '+ Add size', 'prrint' ); ?></button></p>
		<?php
	}

	/**
	 * Repeatable papers table (also used by the product tab).
	 */
	public static function render_papers_table( $name, $papers, $currency ) {
		?>
		<table class="widefat striped prrint-table" data-prrint-table="papers">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Paper / finish', 'prrint' ); ?></th>
					<th><?php printf( /* translators: %s currency symbol */ esc_html__( 'Surcharge (%s)', 'prrint' ), esc_html( $currency ) ); ?></th>
					<th class="prrint-col-actions"></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( array_values( $papers ) as $i => $paper ) : ?>
					<tr>
						<td><input type="text" name="<?php echo esc_attr( $name ); ?>[<?php echo (int) $i; ?>][label]" value="<?php echo esc_attr( $paper['label'] ); ?>" /></td>
						<td><input type="number" step="0.01" min="0" name="<?php echo esc_attr( $name ); ?>[<?php echo (int) $i; ?>][surcharge]" value="<?php echo esc_attr( $paper['surcharge'] ); ?>" /></td>
						<td class="prrint-col-actions"><button type="button" class="button-link-delete prrint-remove-row" aria-label="<?php esc_attr_e( 'Remove row', 'prrint' ); ?>">✕</button></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<template class="prrint-row-template">
			<tr>
				<td><input type="text" name="<?php echo esc_attr( $name ); ?>[__i__][label]" value="" /></td>
				<td><input type="number" step="0.01" min="0" name="<?php echo esc_attr( $name ); ?>[__i__][surcharge]" value="" /></td>
				<td class="prrint-col-actions"><button type="button" class="button-link-delete prrint-remove-row" aria-label="<?php esc_attr_e( 'Remove row', 'prrint' ); ?>">✕</button></td>
			</tr>
		</template>
		<p><button type="button" class="button prrint-add-row"><?php esc_html_e( '+ Add paper', 'prrint' ); ?></button></p>
		<?php
	}
}
