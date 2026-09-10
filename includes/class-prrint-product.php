<?php
/**
 * Product editor integration: "Print Studio" product data tab.
 *
 * @package prrint
 */

defined( 'ABSPATH' ) || exit;

class Prrint_Product {

	public static function init() {
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'add_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'render_panel' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save' ) );
	}

	public static function add_tab( $tabs ) {
		$tabs['prrint'] = array(
			'label'    => __( 'Print Studio', 'prrint' ),
			'target'   => 'prrint_product_data',
			'class'    => array(),
			'priority' => 75,
		);
		return $tabs;
	}

	public static function render_panel() {
		global $post;

		$enabled  = get_post_meta( $post->ID, '_prrint_enabled', true );
		$override = get_post_meta( $post->ID, '_prrint_override', true );
		$sizes    = prrint_get_sizes( $post->ID );
		$papers   = prrint_get_papers( $post->ID );
		$currency = get_woocommerce_currency_symbol();
		?>
		<div id="prrint_product_data" class="panel woocommerce_options_panel hidden">
			<div class="options_group">
				<?php
				woocommerce_wp_checkbox( array(
					'id'          => '_prrint_enabled',
					'value'       => $enabled,
					'label'       => __( 'Enable Print Studio', 'prrint' ),
					'description' => __( 'Show the photo upload & print designer on this product page.', 'prrint' ),
				) );
				woocommerce_wp_checkbox( array(
					'id'          => '_prrint_override',
					'value'       => $override,
					'label'       => __( 'Override global options', 'prrint' ),
					'description' => __( 'Use the sizes and papers below instead of the global Prrint Studio settings.', 'prrint' ),
				) );
				?>
			</div>
			<div class="options_group prrint-product-tables" style="padding:12px;">
				<h4 style="margin:0.4em 0;"><?php esc_html_e( 'Print sizes', 'prrint' ); ?></h4>
				<?php Prrint_Settings::render_sizes_table( 'prrint_product_sizes', $sizes, $currency ); ?>

				<h4 style="margin:1em 0 0.4em;"><?php esc_html_e( 'Paper & finish options', 'prrint' ); ?></h4>
				<?php Prrint_Settings::render_papers_table( 'prrint_product_papers', $papers, $currency ); ?>

				<p class="description">
					<?php
					printf(
						/* translators: %s: settings page link */
						esc_html__( 'These tables only take effect when "Override global options" is checked. Global defaults live in %s.', 'prrint' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=prrint-settings' ) ) . '">' . esc_html__( 'Prrint Studio settings', 'prrint' ) . '</a>'
					);
					?>
				</p>
			</div>
		</div>
		<?php
	}

	public static function save( $post_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies its own nonce before firing this hook.
		update_post_meta( $post_id, '_prrint_enabled', isset( $_POST['_prrint_enabled'] ) ? 'yes' : 'no' );
		update_post_meta( $post_id, '_prrint_override', isset( $_POST['_prrint_override'] ) ? 'yes' : 'no' );

		if ( isset( $_POST['prrint_product_sizes'] ) && is_array( $_POST['prrint_product_sizes'] ) ) {
			$sizes = Prrint_Settings::sanitize_sizes( wp_unslash( $_POST['prrint_product_sizes'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			update_post_meta( $post_id, '_prrint_sizes', $sizes );
		}
		if ( isset( $_POST['prrint_product_papers'] ) && is_array( $_POST['prrint_product_papers'] ) ) {
			$papers = Prrint_Settings::sanitize_papers( wp_unslash( $_POST['prrint_product_papers'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			update_post_meta( $post_id, '_prrint_papers', $papers );
		}
		// phpcs:enable
	}
}
