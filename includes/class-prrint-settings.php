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

		return $out;
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

				<?php submit_button( __( 'Save settings', 'prrint' ) ); ?>
			</form>
		</div>
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
