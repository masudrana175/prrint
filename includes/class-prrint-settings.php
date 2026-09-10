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

		$out['max_mb']       = max( 1, min( 200, isset( $input['max_mb'] ) ? absint( $input['max_mb'] ) : $defaults['max_mb'] ) );
		$out['jpeg_quality'] = max( 50, min( 100, isset( $input['jpeg_quality'] ) ? absint( $input['jpeg_quality'] ) : $defaults['jpeg_quality'] ) );
		$out['target_dpi']   = max( 72, min( 1200, isset( $input['target_dpi'] ) ? absint( $input['target_dpi'] ) : $defaults['target_dpi'] ) );
		$out['min_dpi']      = max( 30, min( 600, isset( $input['min_dpi'] ) ? absint( $input['min_dpi'] ) : $defaults['min_dpi'] ) );
		$out['border_in']    = max( 0.05, min( 2, isset( $input['border_in'] ) ? (float) $input['border_in'] : $defaults['border_in'] ) );

		$out['studio_product_id'] = isset( $input['studio_product_id'] ) ? absint( $input['studio_product_id'] ) : 0;

		$out['enabled_tools'] = array_values( array_intersect(
			prrint_toggleable_tools(),
			isset( $input['enabled_tools'] ) && is_array( $input['enabled_tools'] ) ? $input['enabled_tools'] : array()
		) );

		$out['text_templates_enabled'] = array_values( array_intersect(
			prrint_text_template_ids(),
			isset( $input['text_templates_enabled'] ) && is_array( $input['text_templates_enabled'] ) ? $input['text_templates_enabled'] : array()
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

	/* --------------------------------------------------------------------
	 * Rendering.
	 * ------------------------------------------------------------------ */

	public static function render_page() {
		$s        = prrint_settings();
		$currency = get_woocommerce_currency_symbol();
		?>
		<div class="wrap prrint-admin-wrap">
			<h1 class="prrint-admin-title">
				<span class="prrint-logo" aria-hidden="true">🖼️</span>
				<?php esc_html_e( 'Prrint — Photo Print Studio', 'prrint' ); ?>
			</h1>
			<p class="prrint-admin-sub">
				<?php esc_html_e( 'Global defaults for every print product. Individual products can override these from the "Print Studio" tab in the product editor.', 'prrint' ); ?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'prrint_settings_group' ); ?>

				<div class="prrint-card">
					<h2><?php esc_html_e( 'Print sizes', 'prrint' ); ?></h2>
					<p class="description"><?php esc_html_e( 'The sizes customers can order. Dimensions are in inches; the crop editor locks to each size\'s aspect ratio.', 'prrint' ); ?></p>
					<?php self::render_sizes_table( 'prrint_settings[sizes]', $s['sizes'], $currency ); ?>
				</div>

				<div class="prrint-card">
					<h2><?php esc_html_e( 'Paper & finish options', 'prrint' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Each paper can add a surcharge on top of the size price.', 'prrint' ); ?></p>
					<?php self::render_papers_table( 'prrint_settings[papers]', $s['papers'], $currency ); ?>
				</div>

				<div class="prrint-card">
					<h2><?php esc_html_e( 'Quality & uploads', 'prrint' ); ?></h2>
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
					</table>
				</div>

				<div class="prrint-card">
					<h2><?php esc_html_e( 'Standalone studio page', 'prrint' ); ?></h2>
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
					</table>
				</div>

				<div class="prrint-card">
					<h2><?php esc_html_e( 'Editor tools', 'prrint' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Turn tools off to simplify the editor for your store. Transform (crop & rotate) is always on.', 'prrint' ); ?></p>
					<?php self::render_tool_checkboxes( $s['enabled_tools'] ); ?>
				</div>

				<div class="prrint-card">
					<h2><?php esc_html_e( 'Text Design layouts', 'prrint' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Which pre-made word-art layouts show in the Text Design panel.', 'prrint' ); ?></p>
					<?php self::render_text_template_checkboxes( $s['text_templates_enabled'] ); ?>
				</div>

				<div class="prrint-card">
					<h2><?php esc_html_e( 'Color palettes', 'prrint' ); ?></h2>
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

				<?php submit_button( __( 'Save settings', 'prrint' ) ); ?>
			</form>
		</div>
		<?php
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
