<?php
/**
 * "Colorplak Box Shipping" — the WooCommerce shipping method. Loaded on
 * woocommerce_shipping_init; the pricing lives in Prrint_Shipping.
 *
 * @package prrint
 */

defined( 'ABSPATH' ) || exit;

class Prrint_Shipping_Method extends WC_Shipping_Method {

	/**
	 * @param int $instance_id Shipping zone method instance.
	 */
	public function __construct( $instance_id = 0 ) {
		$this->id                 = Prrint_Shipping::METHOD_ID;
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'Colorplak Box Shipping', 'prrint' );
		$this->method_description = __( 'Box + freight priced from each print\'s size (square inches) and the number of prints, using the Colorplak box chart.', 'prrint' );
		$this->supports           = array( 'shipping-zones', 'instance-settings', 'instance-settings-modal' );
		$this->init();
	}

	public function init() {
		$defaults = Prrint_Shipping::default_settings();

		$this->instance_form_fields = array(
			'title'     => array(
				'title'       => __( 'Title', 'prrint' ),
				'type'        => 'text',
				'description' => __( 'Shown to customers, followed by the number of boxes, e.g. "Standard Shipping (2 boxes)".', 'prrint' ),
				'default'     => $defaults['title'],
				'desc_tip'    => true,
			),
			'mode'      => array(
				'title'       => __( 'Calculation', 'prrint' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'default'     => $defaults['mode'],
				'options'     => array(
					'upgrade'  => __( 'Upgrade whole order to the largest print\'s box', 'prrint' ),
					'separate' => __( 'Separate boxes per size tier', 'prrint' ),
				),
				'description' => __( 'Upgrade: every print ships in the box tier of the largest print. Separate: each tier is boxed and charged on its own, then added up.', 'prrint' ),
				'desc_tip'    => true,
			),
			'oversized' => array(
				'title'       => __( 'Oversized prints', 'prrint' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'default'     => $defaults['oversized'],
				'options'     => array(
					'block'      => __( 'Block checkout — customer contacts us for a freight quote', 'prrint' ),
					'charge_box' => __( 'Charge the oversized box price only (freight quoted later)', 'prrint' ),
				),
				'description' => __( 'For prints over the largest boxed size (1728 sq in, e.g. larger than 36" x 48").', 'prrint' ),
				'desc_tip'    => true,
			),
			'free_min'  => array(
				'title'       => __( 'Free shipping over', 'prrint' ),
				'type'        => 'price',
				'placeholder' => __( 'Off', 'prrint' ),
				'default'     => $defaults['free_min'],
				'description' => __( 'Order subtotal at which shipping is free. Leave empty to turn off.', 'prrint' ),
				'desc_tip'    => true,
			),
		);

		$this->init_instance_settings();
		$this->title = $this->get_option( 'title', $defaults['title'] );

		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * @param array $package Shipping package.
	 */
	public function calculate_shipping( $package = array() ) {
		$contents = isset( $package['contents'] ) ? $package['contents'] : array();
		$subtotal = isset( $package['contents_cost'] ) ? (float) $package['contents_cost'] : 0.0;
		$rate     = Prrint_Shipping::rate_for( $contents, $this->instance_settings, $subtotal );

		// Nothing boxable, or oversized prints waiting for a freight quote
		// (checkout is blocked with a notice by Prrint_Shipping).
		if ( ! $rate || $rate['blocked'] ) {
			return;
		}

		$this->add_rate( array(
			'id'        => $this->get_rate_id(),
			'label'     => $rate['label'],
			'cost'      => $rate['cost'],
			'package'   => $package,
			'meta_data' => array( __( 'Boxes', 'prrint' ) => $rate['boxes'] ),
		) );
	}
}
