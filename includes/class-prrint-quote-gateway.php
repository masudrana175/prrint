<?php
/**
 * "Request a freight quote" — the checkout option for orders with oversized
 * prints: the order is placed without payment, and the customer pays once
 * ColorPlak has added the freight (see Prrint_Freight_Quote). Loaded on
 * woocommerce_payment_gateways.
 *
 * @package prrint
 */

defined( 'ABSPATH' ) || exit;

class Prrint_Quote_Gateway extends WC_Payment_Gateway {

	public function __construct() {
		$this->id                 = Prrint_Freight_Quote::GATEWAY;
		$this->method_title       = __( 'Freight quote request (Prrint)', 'prrint' );
		$this->method_description = __( 'Offered instead of payment when an order has oversized prints and the customer chooses "Freight — quoted after you order". The order is held as "Quote requested"; add the freight, then use the order action "Send freight quote to customer" to email a payment link.', 'prrint' );
		$this->has_fields         = false;
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable', 'prrint' ),
				'type'    => 'checkbox',
				'label'   => __( 'Let customers place oversized orders as freight quote requests', 'prrint' ),
				'default' => 'yes',
			),
			'title'       => array(
				'title'   => __( 'Title', 'prrint' ),
				'type'    => 'text',
				'default' => __( 'Request a freight quote — pay after we confirm shipping', 'prrint' ),
			),
			'description' => array(
				'title'   => __( 'Description', 'prrint' ),
				'type'    => 'textarea',
				'default' => __( 'No payment now. We\'ll email you the freight cost and a secure link to pay by card, Apple Pay, Google Pay or a payment plan.', 'prrint' ),
			),
		);
	}

	/**
	 * Only at checkout, and only when the customer chose the quoted-freight
	 * rate; never on the "pay for order" page, where the customer pays.
	 */
	public function is_available() {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
			return false;
		}
		return Prrint_Shipping::quote_rate_chosen();
	}

	/**
	 * @param int $order_id Order.
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'result' => 'failure' );
		}
		$order->update_meta_data( Prrint_Freight_Quote::META, 'requested' );
		$order->update_status( Prrint_Freight_Quote::STATUS, __( 'Waiting for a freight quote: add the freight, then use "Send freight quote to customer".', 'prrint' ) );
		WC()->cart->empty_cart();
		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	public function thankyou_page() {
		echo '<p class="prrint-quote-thanks">' . esc_html__( 'Thank you! Your order has been received as a freight quote request — no payment has been taken. We\'ll email you the freight cost and a secure link to pay shortly.', 'prrint' ) . '</p>';
	}
}
