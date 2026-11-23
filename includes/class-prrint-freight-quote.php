<?php
/**
 * Oversized orders as freight quote requests ("order now, pay later").
 *
 * When a cart has prints too big for the box chart and Colorplak Box
 * Shipping is set to "Take the order as a quote request", the customer picks
 * "Freight — quoted after you order" ($0) and the only payment option is
 * "Request a freight quote". The order is saved as "Quote requested" without
 * payment; ColorPlak gets the new-order email and the customer a note. Staff
 * then add the freight to the order and use the order action "Send freight
 * quote to customer", which emails WooCommerce's invoice with a "Pay for
 * this order" link (any normal gateway: card, Apple Pay, payment plans).
 *
 * @package prrint
 */

defined( 'ABSPATH' ) || exit;

class Prrint_Freight_Quote {

	const STATUS  = 'prrint-quote';          // Stored as wc-prrint-quote.
	const GATEWAY = 'prrint_freight_quote';
	const META    = '_prrint_freight_quote'; // requested | sent
	const ACTION  = 'prrint_send_freight_quote';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_status' ) );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'add_status' ) );
		add_filter( 'woocommerce_valid_order_statuses_for_payment', array( __CLASS__, 'payable_statuses' ) );
		add_filter( 'wc_order_is_editable', array( __CLASS__, 'is_editable' ), 10, 2 );
		add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'register_gateway' ) );
		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'filter_gateways' ) );
		add_action( 'woocommerce_order_status_' . self::STATUS, array( __CLASS__, 'notify' ), 10, 2 );
		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'order_actions' ), 10, 2 );
		add_action( 'woocommerce_order_action_' . self::ACTION, array( __CLASS__, 'send_quote' ) );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( __CLASS__, 'admin_hint' ) );
		add_action( 'woocommerce_before_cart', array( __CLASS__, 'cart_notice' ) );
		add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'cart_notice' ), 5 );
		add_action( 'admin_init', array( __CLASS__, 'migrate_block_setting' ) );
	}

	public static function register_status() {
		register_post_status( 'wc-' . self::STATUS, array(
			'label'                     => _x( 'Quote requested', 'Order status', 'prrint' ),
			'public'                    => false,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: number of orders */
			'label_count'               => _n_noop( 'Quote requested <span class="count">(%s)</span>', 'Quote requested <span class="count">(%s)</span>', 'prrint' ),
		) );
	}

	/** Lists "Quote requested" after "Pending payment". */
	public static function add_status( $statuses ) {
		$out = array();
		foreach ( $statuses as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'wc-pending' === $key ) {
				$out[ 'wc-' . self::STATUS ] = _x( 'Quote requested', 'Order status', 'prrint' );
			}
		}
		if ( ! isset( $out[ 'wc-' . self::STATUS ] ) ) {
			$out[ 'wc-' . self::STATUS ] = _x( 'Quote requested', 'Order status', 'prrint' );
		}
		return $out;
	}

	/** The customer can pay a quoted order from the invoice link. */
	public static function payable_statuses( $statuses ) {
		$statuses[] = self::STATUS;
		return array_unique( $statuses );
	}

	/** Staff can edit the order (add the freight) while it waits for a quote. */
	public static function is_editable( $editable, $order ) {
		return $editable || ( $order && $order->has_status( self::STATUS ) );
	}

	public static function register_gateway( $gateways ) {
		require_once PRRINT_DIR . 'includes/class-prrint-quote-gateway.php';
		$gateways[] = 'Prrint_Quote_Gateway';
		return $gateways;
	}

	/**
	 * With the quoted-freight rate chosen, the quote request is the only way
	 * to check out (nothing can be charged before the freight is known);
	 * otherwise it isn't offered at all.
	 */
	public static function filter_gateways( $gateways ) {
		if ( ! is_array( $gateways ) || ( is_admin() && ! wp_doing_ajax() ) ) {
			return $gateways;
		}
		$is_pay_page = function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' );
		if ( ! $is_pay_page && isset( $gateways[ self::GATEWAY ] ) && Prrint_Shipping::quote_rate_chosen() ) {
			return array( self::GATEWAY => $gateways[ self::GATEWAY ] );
		}
		unset( $gateways[ self::GATEWAY ] );
		return $gateways;
	}

	/** New quote request: email the store, and tell the customer what happens next. */
	public static function notify( $order_id, $order = null ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$emails = WC()->mailer()->get_emails();
		if ( isset( $emails['WC_Email_New_Order'] ) ) {
			$emails['WC_Email_New_Order']->trigger( $order->get_id(), $order );
		}
		// A customer note is emailed to the customer by WooCommerce.
		$order->add_order_note( __( 'Thank you for your order! It includes an oversized piece, so we\'re working out the freight cost. We\'ll email you the total and a secure link to pay shortly. No payment has been taken yet.', 'prrint' ), 1 );
	}

	public static function order_actions( $actions, $order = null ) {
		if ( $order instanceof WC_Order && $order->has_status( self::STATUS ) ) {
			$actions[ self::ACTION ] = __( 'Send freight quote to customer (payment link)', 'prrint' );
		}
		return $actions;
	}

	/** Emails WooCommerce's invoice, which includes the "Pay for this order" link. */
	public static function send_quote( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$order->update_meta_data( self::META, 'sent' );
		$order->save();
		WC()->mailer()->customer_invoice( $order );
		/* translators: %s: order total */
		$order->add_order_note( sprintf( __( 'Freight quote sent to the customer with a payment link (order total %s).', 'prrint' ), wp_strip_all_tags( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ) ) );
	}

	/** Steps for staff on a "Quote requested" order. */
	public static function admin_hint( $order ) {
		if ( ! $order instanceof WC_Order || ! $order->has_status( self::STATUS ) ) {
			return;
		}
		$sent = 'sent' === $order->get_meta( self::META );
		?>
		<div class="form-field form-field-wide prrint-quote-hint" style="margin-top:12px;padding:10px 12px;background:#fcf9e8;border-left:4px solid #dba617">
			<strong><?php echo esc_html( $sent ? __( 'Freight quote sent — waiting for the customer to pay.', 'prrint' ) : __( 'Freight quote needed', 'prrint' ) ); ?></strong>
			<ol style="margin:6px 0 0 18px">
				<li><?php esc_html_e( 'In the items box below, edit the shipping line "Freight — quoted after you order" and enter the freight cost (or "Add item(s) → Add shipping").', 'prrint' ); ?></li>
				<li><?php esc_html_e( 'Click "Recalculate" and save the order.', 'prrint' ); ?></li>
				<li><?php esc_html_e( 'Choose Order actions → "Send freight quote to customer (payment link)" and click Update.', 'prrint' ); ?></li>
			</ol>
			<p style="margin:6px 0 0"><?php esc_html_e( 'The order moves to Processing automatically when the customer pays.', 'prrint' ); ?></p>
		</div>
		<?php
	}

	/** Cart/checkout notice explaining the quote request. */
	public static function cart_notice() {
		if ( Prrint_Shipping::cart_needs_quote() ) {
			wc_print_notice( esc_html__( 'Your order includes an oversized piece. Choose "Freight — quoted after you order" for shipping and place your order — no payment is taken now. We\'ll email you the freight cost and a secure link to pay. (Local pickup in Temecula can be paid right away.)', 'prrint' ), 'notice' );
		}
	}

	/**
	 * One-time switch for stores set up before quote requests existed:
	 * Colorplak Box Shipping methods left at "Block checkout" (the old
	 * default) become "Take the order as a quote request", as ColorPlak
	 * chose. Methods set to "Charge the box" are left alone.
	 */
	public static function migrate_block_setting() {
		if ( get_option( 'prrint_freight_quote_migrated' ) || ! class_exists( 'WC_Shipping_Zones' ) ) {
			return;
		}
		update_option( 'prrint_freight_quote_migrated', 1, false );
		foreach ( WC_Shipping_Zones::get_zones() as $row ) {
			$zone = WC_Shipping_Zones::get_zone( $row['id'] );
			if ( ! $zone ) {
				continue;
			}
			foreach ( $zone->get_shipping_methods() as $method ) {
				if ( Prrint_Shipping::METHOD_ID !== $method->id ) {
					continue;
				}
				$key      = 'woocommerce_' . Prrint_Shipping::METHOD_ID . '_' . (int) $method->instance_id . '_settings';
				$settings = get_option( $key );
				if ( is_array( $settings ) && isset( $settings['oversized'] ) && 'block' === $settings['oversized'] ) {
					$settings['oversized'] = 'quote';
					update_option( $key, $settings );
				}
			}
		}
	}
}
