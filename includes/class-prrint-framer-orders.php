<?php
/**
 * The online framer order form ([prrint_framer_order_form]) — the web
 * version of ColorPlak's paper "Order Form": one row per piece with size,
 * quantity, product (style), edge color, print condition and add-ons;
 * rush, PO number, date needed and blind shipping for the whole order.
 *
 * Submitting re-prices every row on the server (Prrint_Wholesale::quote),
 * puts the rows in the WooCommerce cart as items of one hidden product,
 * and adds discount / rush / boxing / freight as cart fees, so the framer
 * pays online through whatever gateways the store has (Stripe, PayPal…).
 *
 * @package prrint
 */

defined( 'ABSPATH' ) || exit;

class Prrint_Framer_Orders {

	const NONCE     = 'prrint_framer_order';
	const CART_KEY  = 'prrint_framer';
	const SESSION   = 'prrint_framer_order';
	const MAX_ROWS  = 60;

	public static function init() {
		add_shortcode( 'prrint_framer_order_form', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_ajax_prrint_framer_submit', array( __CLASS__, 'ajax_submit' ) );
		add_action( 'wp_ajax_prrint_framer_save_draft', array( __CLASS__, 'ajax_save_draft' ) );

		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'cart_item_data' ), 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'set_prices' ), 30 );
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'add_fees' ) );
		add_filter( 'woocommerce_package_rates', array( __CLASS__, 'shipping_rates' ), 100, 2 );
		add_action( 'woocommerce_review_order_before_payment', array( __CLASS__, 'checkout_notes' ) );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'order_line_item' ), 10, 3 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'create_order' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'clear_session' ) );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( __CLASS__, 'admin_order_box' ) );
		add_filter( 'woocommerce_cart_item_thumbnail', array( __CLASS__, 'cart_thumbnail' ), 20, 2 );
	}

	/* --------------------------------------------------------------------
	 * Helpers.
	 * ------------------------------------------------------------------ */

	/** The hidden product every framer order line is booked against. */
	public static function product_id() {
		$id      = (int) get_option( 'prrint_framer_product' );
		$product = $id ? wc_get_product( $id ) : null;
		if ( $product && 'trash' !== $product->get_status() ) {
			return $id;
		}
		$product = new WC_Product_Simple();
		$product->set_name( __( 'Framer wholesale order', 'prrint' ) );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_regular_price( '0' );
		$product->set_sold_individually( false );
		$product->set_reviews_allowed( false );
		$product->set_description( __( 'Used by the Framers Corner order form. Prices are set per order line — do not delete.', 'prrint' ) );
		$id = $product->save();
		update_option( 'prrint_framer_product', $id );
		return $id;
	}

	public static function discount_percent( $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		return max( 0.0, min( 100.0, (float) get_user_meta( $user_id, '_prrint_framer_discount', true ) ) );
	}

	/** Edge colors that may be ordered with a product (respects "Not available for"). */
	protected static function edge_colors_for( $product_label ) {
		$out = array();
		foreach ( array_values( prrint_settings()['edge_colors'] ) as $i => $edge ) {
			if ( prrint_edge_available( $edge, $product_label ) ) {
				$out[ $i ] = $edge;
			}
		}
		return $out;
	}

	public static function mailin_address() {
		$a = isset( prrint_settings()['framer_mailin_address'] ) ? trim( (string) prrint_settings()['framer_mailin_address'] ) : '';
		return '' !== $a ? $a : "Custom Art Services - Colorplak\n37110 Mesa Road\nTemecula, CA 92592";
	}

	/* --------------------------------------------------------------------
	 * Shortcode.
	 * ------------------------------------------------------------------ */

	public static function shortcode() {
		if ( ! prrint_is_approved_framer() ) {
			return '';
		}
		wp_enqueue_style( 'prrint-framers', PRRINT_URL . 'assets/css/framers.css', array(), PRRINT_VERSION );
		wp_enqueue_script( 'prrint-framer-order', PRRINT_URL . 'assets/js/framer-order.js', array(), PRRINT_VERSION, true );

		$settings = prrint_settings();
		$draft    = json_decode( (string) get_user_meta( get_current_user_id(), '_prrint_framer_draft', true ), true );

		wp_localize_script( 'prrint-framer-order', 'prrintFramer', array(
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( self::NONCE ),
			'uploadNonce'  => wp_create_nonce( 'prrint_studio' ),
			'maxMb'        => (int) $settings['max_mb'],
			'catalog'      => Prrint_Wholesale::client_config(),
			'edgeColors'   => array_values( $settings['edge_colors'] ),
			'discount'     => self::discount_percent(),
			'draft'        => is_array( $draft ) ? $draft : null,
			'mailin'       => self::mailin_address(),
			'currency'     => array(
				'symbol'      => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
				'decimals'    => wc_get_price_decimals(),
				'decimalSep'  => wc_get_price_decimal_separator(),
				'thousandSep' => wc_get_price_thousand_separator(),
				'position'    => get_option( 'woocommerce_currency_pos', 'left' ),
			),
			'i18n'         => array(
				'item'          => __( 'Item', 'prrint' ),
				'title'         => __( 'Title or description', 'prrint' ),
				'product'       => __( 'Product / style', 'prrint' ),
				'choose'        => __( 'Choose…', 'prrint' ),
				'length'        => __( 'Length (in)', 'prrint' ),
				'width'         => __( 'Width (in)', 'prrint' ),
				'qty'           => __( 'Qty', 'prrint' ),
				'edge'          => __( 'Edge color', 'prrint' ),
				'image'         => __( 'Image', 'prrint' ),
				'mailIn'        => __( "I'll mail in my print", 'prrint' ),
				'digital'       => __( 'Digital print — print it from my file', 'prrint' ),
				'digitalOnly'   => __( 'Printed by us from your digital file', 'prrint' ),
				'condition'     => __( 'Print condition', 'prrint' ),
				'file'          => __( 'Image file', 'prrint' ),
				'fileHint'      => __( 'JPEG, PNG or WebP. You can also email the file after ordering — mention your order number.', 'prrint' ),
				'uploading'     => __( 'Uploading…', 'prrint' ),
				'uploaded'      => __( 'Uploaded', 'prrint' ),
				'uploadFailed'  => __( 'Upload failed — try again.', 'prrint' ),
				'remove'        => __( 'Remove', 'prrint' ),
				'duplicate'     => __( 'Duplicate', 'prrint' ),
				'mat'           => __( 'Mat / colored border (+33%)', 'prrint' ),
				'recut'         => __( 'Recut ($5)', 'prrint' ),
				'trim'          => __( 'Trim ($2)', 'prrint' ),
				'leather'       => __( 'Leather lamination upgrade', 'prrint' ),
				'instructions'  => __( 'Special instructions', 'prrint' ),
				'billedAs'      => __( 'Priced as %s', 'prrint' ),
				'each'          => __( 'each', 'prrint' ),
				'missing'       => __( 'Complete the highlighted fields first.', 'prrint' ),
				'submitting'    => __( 'Preparing checkout…', 'prrint' ),
				'saved'         => __( 'Draft saved — it will be here next time you visit.', 'prrint' ),
				'saveFailed'    => __( 'Could not save the draft.', 'prrint' ),
				'error'         => __( 'Something went wrong. Please try again.', 'prrint' ),
				'quote'         => __( 'quoted after ordering', 'prrint' ),
				'boxes'         => __( '%d box(es)', 'prrint' ),
				'boxing'        => __( 'Boxing', 'prrint' ),
				/* translators: %s: percent */
				'discount'      => __( 'Your discount (%s%%)', 'prrint' ),
				'needTitle'     => __( 'Add a title or description.', 'prrint' ),
				'needEdge'      => __( 'Choose an edge color.', 'prrint' ),
				'needCondition' => __( 'Choose the print condition.', 'prrint' ),
				'notOffered'    => __( '%1$s is not available at %2$s.', 'prrint' ),
				'tooBig'        => __( '%1$s products go up to %2$s" x %3$s".', 'prrint' ),
				'enterSize'     => __( 'Enter a length and width.', 'prrint' ),
				'chooseProduct' => __( 'Choose a product.', 'prrint' ),
			),
		) );

		ob_start();
		?>
		<div class="prrint-framer-order" id="prrint-framer-order">
			<div class="prrint-fo-head">
				<label><?php esc_html_e( 'P.O. number', 'prrint' ); ?><input type="text" id="prrint-fo-po" maxlength="60" /></label>
				<label><?php esc_html_e( 'Date needed by', 'prrint' ); ?><input type="date" id="prrint-fo-needed" /></label>
				<label class="prrint-fo-check"><input type="checkbox" id="prrint-fo-rush" /> <span><strong><?php esc_html_e( 'Rush', 'prrint' ); ?></strong> <?php esc_html_e( '(+50%)', 'prrint' ); ?></span></label>
				<label class="prrint-fo-check"><input type="checkbox" id="prrint-fo-blind" /> <span><strong><?php esc_html_e( 'Blind ship', 'prrint' ); ?></strong> <?php esc_html_e( '— ship to my customer without ColorPlak branding or prices', 'prrint' ); ?></span></label>
			</div>

			<div class="prrint-fo-items" id="prrint-fo-items"></div>
			<p><button type="button" class="button prrint-fo-add" id="prrint-fo-add"><?php esc_html_e( '+ Add another item', 'prrint' ); ?></button></p>

			<div class="prrint-fo-mailin" id="prrint-fo-mailin" hidden>
				<strong><?php esc_html_e( 'Mailing in prints?', 'prrint' ); ?></strong>
				<p><?php esc_html_e( 'After you check out, send your prints with a copy of your order confirmation to:', 'prrint' ); ?></p>
				<address><?php echo nl2br( esc_html( self::mailin_address() ) ); ?></address>
				<p class="prrint-fo-small"><?php esc_html_e( 'Please do not send any image that is irreplaceable.', 'prrint' ); ?></p>
			</div>

			<div class="prrint-fo-summary" id="prrint-fo-summary">
				<dl>
					<div><dt><?php esc_html_e( 'Plak total', 'prrint' ); ?></dt><dd id="prrint-fo-plak">—</dd></div>
					<div id="prrint-fo-discount-row" hidden><dt id="prrint-fo-discount-label"></dt><dd id="prrint-fo-discount">—</dd></div>
					<div id="prrint-fo-rush-row" hidden><dt><?php esc_html_e( 'Rush (50%)', 'prrint' ); ?></dt><dd id="prrint-fo-rushamt">—</dd></div>
					<div><dt id="prrint-fo-boxing-label"><?php esc_html_e( 'Boxing', 'prrint' ); ?></dt><dd id="prrint-fo-boxing">—</dd></div>
					<div><dt><?php esc_html_e( 'Freight', 'prrint' ); ?></dt><dd id="prrint-fo-freight">—</dd></div>
					<div class="prrint-fo-grand"><dt><?php esc_html_e( 'Estimated total', 'prrint' ); ?></dt><dd id="prrint-fo-total">—</dd></div>
				</dl>
				<p class="prrint-fo-small"><?php esc_html_e( 'Sizes are priced by square inch at the next size up (within 1/8"). Taxes, if any, are added at checkout.', 'prrint' ); ?></p>
				<div class="prrint-fo-actions">
					<button type="button" class="button prrint-fo-save" id="prrint-fo-save"><?php esc_html_e( 'Save draft', 'prrint' ); ?></button>
					<button type="button" class="button alt prrint-fo-submit" id="prrint-fo-submit"><?php esc_html_e( 'Continue to payment', 'prrint' ); ?></button>
				</div>
				<p class="prrint-fo-message" id="prrint-fo-message" role="status" aria-live="polite"></p>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/* --------------------------------------------------------------------
	 * Validation (server is authoritative).
	 * ------------------------------------------------------------------ */

	/**
	 * Validate + price one row from the browser.
	 *
	 * @return array|WP_Error Cart-ready row.
	 */
	public static function validate_row( $raw, $n ) {
		/* translators: %d: item number */
		$prefix = sprintf( __( 'Item %d:', 'prrint' ), $n ) . ' ';
		if ( ! is_array( $raw ) ) {
			return new WP_Error( 'prrint', $prefix . __( 'invalid item.', 'prrint' ) );
		}
		$catalog = Prrint_Wholesale::catalog();
		$item    = array(
			'product' => isset( $raw['product'] ) ? sanitize_key( $raw['product'] ) : '',
			'length'  => isset( $raw['length'] ) ? round( (float) $raw['length'], 3 ) : 0,
			'width'   => isset( $raw['width'] ) ? round( (float) $raw['width'], 3 ) : 0,
			'qty'     => isset( $raw['qty'] ) ? absint( $raw['qty'] ) : 1,
			'digital' => ! empty( $raw['digital'] ),
			'mat'     => ! empty( $raw['mat'] ),
			'recut'   => ! empty( $raw['recut'] ),
			'trim'    => ! empty( $raw['trim'] ),
			'leather' => ! empty( $raw['leather'] ),
		);
		$quote = Prrint_Wholesale::quote( $item );
		if ( is_wp_error( $quote ) ) {
			return new WP_Error( 'prrint', $prefix . $quote->get_error_message() );
		}
		$product = $catalog['products'][ $item['product'] ];

		$title = isset( $raw['title'] ) ? sanitize_text_field( $raw['title'] ) : '';
		if ( '' === $title ) {
			return new WP_Error( 'prrint', $prefix . __( 'add a title or description.', 'prrint' ) );
		}

		$edge = null;
		if ( $product['edge'] ) {
			$allowed = self::edge_colors_for( $product['label'] );
			$idx     = isset( $raw['edge'] ) && '' !== $raw['edge'] ? (int) $raw['edge'] : -1;
			if ( ! isset( $allowed[ $idx ] ) ) {
				return new WP_Error( 'prrint', $prefix . __( 'choose an edge color.', 'prrint' ) );
			}
			$edge = $allowed[ $idx ];
		}

		$condition = '';
		if ( ! $quote['digital'] ) {
			$conditions = Prrint_Wholesale::print_conditions();
			$condition  = isset( $raw['condition'] ) ? sanitize_key( $raw['condition'] ) : '';
			if ( ! isset( $conditions[ $condition ] ) ) {
				return new WP_Error( 'prrint', $prefix . __( 'choose the print condition.', 'prrint' ) );
			}
		}

		$file = '';
		if ( $quote['digital'] && ! empty( $raw['token'] ) ) {
			$token  = preg_replace( '/[^a-zA-Z0-9]/', '', (string) $raw['token'] );
			$upload = $token ? get_transient( 'prrint_up_' . $token ) : false;
			if ( ! $upload ) {
				return new WP_Error( 'prrint', $prefix . __( 'the uploaded file has expired — please upload it again.', 'prrint' ) );
			}
			$file = $upload['file'];
		}

		$fmt = function ( $v ) {
			return rtrim( rtrim( number_format( (float) $v, 3, '.', '' ), '0' ), '.' );
		};

		return array(
			'title'        => $title,
			'product'      => $item['product'],
			'product_label' => $product['label'],
			'length'       => $item['length'],
			'width'        => $item['width'],
			'size_label'   => $fmt( $item['length'] ) . '" x ' . $fmt( $item['width'] ) . '"',
			'tier'         => $quote['tier'],
			'tier_label'   => $quote['tier_label'],
			'qty'          => $quote['qty'],
			'unit'         => $quote['unit'],
			'base'         => $quote['base'],
			'digital'      => $quote['digital'],
			'edge_label'   => $edge ? prrint_edge_display_label( $edge ) : '',
			'edge_color'   => $edge ? $edge['color'] : '',
			'condition'    => $condition,
			'mat'          => $item['mat'],
			'recut'        => $item['recut'],
			'trim'         => $item['trim'],
			'leather'      => $item['leather'] && 'paper' === $product['line'],
			'instructions' => isset( $raw['instructions'] ) ? sanitize_textarea_field( $raw['instructions'] ) : '',
			'file'         => $file,
		);
	}

	public static function validate_header( $raw ) {
		$needed = isset( $raw['needed_by'] ) ? sanitize_text_field( $raw['needed_by'] ) : '';
		if ( '' !== $needed && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $needed ) ) {
			$needed = '';
		}
		return array(
			'po'         => isset( $raw['po'] ) ? substr( sanitize_text_field( $raw['po'] ), 0, 60 ) : '',
			'needed_by'  => $needed,
			'rush'       => ! empty( $raw['rush'] ),
			'blind_ship' => ! empty( $raw['blind_ship'] ),
		);
	}

	/* --------------------------------------------------------------------
	 * AJAX.
	 * ------------------------------------------------------------------ */

	protected static function guard() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! prrint_is_approved_framer() ) {
			wp_send_json_error( array( 'message' => __( 'Only approved framer accounts can place wholesale orders.', 'prrint' ) ) );
		}
	}

	public static function ajax_submit() {
		self::guard();
		if ( null === WC()->cart ) {
			wp_send_json_error( array( 'message' => __( 'Cart unavailable. Please reload the page.', 'prrint' ) ) );
		}
		$payload = isset( $_POST['order'] ) ? json_decode( wp_unslash( $_POST['order'] ), true ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated per field.
		$rows    = is_array( $payload ) && isset( $payload['items'] ) && is_array( $payload['items'] ) ? $payload['items'] : array();
		if ( ! $rows || count( $rows ) > self::MAX_ROWS ) {
			wp_send_json_error( array( 'message' => __( 'Add at least one item.', 'prrint' ) ) );
		}

		$clean = array();
		foreach ( array_values( $rows ) as $i => $raw ) {
			$row = self::validate_row( $raw, $i + 1 );
			if ( is_wp_error( $row ) ) {
				wp_send_json_error( array( 'message' => $row->get_error_message(), 'item' => $i ) );
			}
			$clean[] = $row;
		}

		// A wholesale order is checked out on its own, never mixed with retail items.
		WC()->cart->empty_cart();
		$product_id = self::product_id();
		foreach ( $clean as $row ) {
			$row['unique'] = md5( wp_json_encode( $row ) . wp_rand() );
			if ( ! WC()->cart->add_to_cart( $product_id, $row['qty'], 0, array(), array( self::CART_KEY => $row ) ) ) {
				$notices = wc_get_notices( 'error' );
				wc_clear_notices();
				wp_send_json_error( array( 'message' => $notices ? wp_strip_all_tags( $notices[0]['notice'] ) : __( 'Could not add the order to the cart.', 'prrint' ) ) );
			}
		}
		WC()->session->set( self::SESSION, self::validate_header( is_array( $payload ) ? $payload : array() ) );
		delete_user_meta( get_current_user_id(), '_prrint_framer_draft' );
		wc_clear_notices();

		wp_send_json_success( array( 'redirect' => wc_get_checkout_url() ) );
	}

	public static function ajax_save_draft() {
		self::guard();
		$raw = isset( $_POST['draft'] ) ? (string) wp_unslash( $_POST['draft'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- re-encoded below.
		if ( strlen( $raw ) > 200000 ) {
			wp_send_json_error();
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			wp_send_json_error();
		}
		// Re-encode so only plain JSON data is stored; it's re-validated on submit.
		update_user_meta( get_current_user_id(), '_prrint_framer_draft', wp_json_encode( $data ) );
		wp_send_json_success();
	}

	/* --------------------------------------------------------------------
	 * Cart + checkout.
	 * ------------------------------------------------------------------ */

	protected static function cart_has_framer_items( $cart = null ) {
		$cart = $cart ? $cart : WC()->cart;
		if ( ! $cart ) {
			return false;
		}
		foreach ( $cart->get_cart() as $item ) {
			if ( ! empty( $item[ self::CART_KEY ] ) ) {
				return true;
			}
		}
		return false;
	}

	public static function set_prices( $cart ) {
		foreach ( $cart->get_cart() as $item ) {
			if ( ! empty( $item[ self::CART_KEY ] ) ) {
				$item['data']->set_price( (float) $item[ self::CART_KEY ]['unit'] );
				$item['data']->set_name( $item[ self::CART_KEY ]['title'] );
			}
		}
	}

	/** Human-readable details for one row, used in the cart and on the order. */
	public static function row_details( $row ) {
		$conditions = Prrint_Wholesale::print_conditions();
		$details    = array(
			__( 'Product', 'prrint' ) => $row['product_label'],
			__( 'Size', 'prrint' )    => $row['size_label'] . ' — ' . sprintf( /* translators: %s: size tier */ __( 'priced as %s', 'prrint' ), $row['tier_label'] ),
		);
		if ( '' !== $row['edge_label'] ) {
			$details[ __( 'Edge color', 'prrint' ) ] = $row['edge_label'];
		}
		$details[ __( 'Image', 'prrint' ) ] = $row['digital'] ? ( $row['file'] ? __( 'Digital print (file uploaded)', 'prrint' ) : __( 'Digital print (file to follow)', 'prrint' ) ) : __( 'Mailed in by framer', 'prrint' );
		if ( '' !== $row['condition'] ) {
			$details[ __( 'Print condition', 'prrint' ) ] = isset( $conditions[ $row['condition'] ] ) ? $conditions[ $row['condition'] ] : $row['condition'];
		}
		$opts = array();
		if ( $row['mat'] ) {
			$opts[] = __( 'Mat/colored border (+33%)', 'prrint' );
		}
		if ( $row['recut'] ) {
			$opts[] = __( 'Recut', 'prrint' );
		}
		if ( $row['trim'] ) {
			$opts[] = __( 'Trim', 'prrint' );
		}
		if ( ! empty( $row['leather'] ) ) {
			$opts[] = __( 'Leather lamination', 'prrint' );
		}
		if ( $opts ) {
			$details[ __( 'Options', 'prrint' ) ] = implode( ', ', $opts );
		}
		if ( '' !== $row['instructions'] ) {
			$details[ __( 'Special instructions', 'prrint' ) ] = $row['instructions'];
		}
		return $details;
	}

	public static function cart_item_data( $data, $cart_item ) {
		if ( empty( $cart_item[ self::CART_KEY ] ) ) {
			return $data;
		}
		foreach ( self::row_details( $cart_item[ self::CART_KEY ] ) as $key => $value ) {
			$data[] = array( 'key' => $key, 'value' => $value );
		}
		return $data;
	}

	public static function cart_thumbnail( $thumbnail, $cart_item ) {
		if ( empty( $cart_item[ self::CART_KEY ]['file'] ) ) {
			return $thumbnail;
		}
		return sprintf( '<img src="%s" alt="" style="max-width:80px;height:auto;border-radius:3px;" />', esc_url( prrint_file_url( $cart_item[ self::CART_KEY ]['file'] ) ) );
	}

	/** Totals for the framer rows in a cart (also used by the checkout notes). */
	public static function totals( $cart ) {
		$plak   = 0.0;
		$pieces = array();
		foreach ( $cart->get_cart() as $item ) {
			if ( empty( $item[ self::CART_KEY ] ) ) {
				continue;
			}
			$row      = $item[ self::CART_KEY ];
			$qty      = (int) $item['quantity'];
			$plak    += (float) $row['unit'] * $qty;
			$pieces[] = array( 'tier' => $row['tier'], 'qty' => $qty );
		}
		$header   = WC()->session ? (array) WC()->session->get( self::SESSION, array() ) : array();
		$percent  = self::discount_percent();
		$discount = round( $plak * $percent / 100, 2 );
		$rush     = ! empty( $header['rush'] ) ? round( ( $plak - $discount ) * Prrint_Wholesale::options()['rush_percent'] / 100, 2 ) : 0.0;
		$ship     = Prrint_Wholesale::shipping( $pieces );
		return array(
			'plak'     => round( $plak, 2 ),
			'percent'  => $percent,
			'discount' => $discount,
			'rush'     => $rush,
			'ship'     => $ship,
			'header'   => $header,
		);
	}

	public static function add_fees( $cart ) {
		if ( ! self::cart_has_framer_items( $cart ) ) {
			return;
		}
		$t = self::totals( $cart );
		if ( $t['discount'] > 0 ) {
			/* translators: %s: discount percent */
			$cart->add_fee( sprintf( __( 'Framer discount (%s%%)', 'prrint' ), wc_format_localized_decimal( $t['percent'] ) ), -$t['discount'], false );
		}
		if ( $t['rush'] > 0 ) {
			$cart->add_fee( __( 'Rush (50%)', 'prrint' ), $t['rush'], true );
		}
		if ( $t['ship']['boxing'] > 0 ) {
			/* translators: %d: number of boxes */
			$cart->add_fee( sprintf( _n( 'Boxing (%d box)', 'Boxing (%d boxes)', $t['ship']['boxes'], 'prrint' ), $t['ship']['boxes'] ), $t['ship']['boxing'], true );
		}
		if ( $t['ship']['freight'] > 0 ) {
			$cart->add_fee( __( 'Freight', 'prrint' ), $t['ship']['freight'], true );
		}
	}

	/**
	 * Freight for a framer order comes from the price sheet (added as a
	 * fee above), so the store's own shipping rates are replaced by one
	 * zero-cost rate instead of charging twice.
	 */
	public static function shipping_rates( $rates, $package ) {
		if ( empty( $package['contents'] ) ) {
			return $rates;
		}
		foreach ( $package['contents'] as $item ) {
			if ( empty( $item[ self::CART_KEY ] ) ) {
				return $rates;
			}
		}
		$rate = new WC_Shipping_Rate( 'prrint_framer_freight', __( 'Freight — calculated from your order (see fees)', 'prrint' ), 0, array(), 'prrint_framer_freight' );
		return array( $rate->get_id() => $rate );
	}

	public static function checkout_notes() {
		if ( ! WC()->cart || ! self::cart_has_framer_items() ) {
			return;
		}
		$t = self::totals( WC()->cart );
		echo '<div class="prrint-framer-checkout-notes">';
		if ( $t['ship']['freight_quote'] ) {
			echo '<p><strong>' . esc_html__( 'Oversized pieces:', 'prrint' ) . '</strong> ' . esc_html__( 'freight for pieces over 36" x 48" is quoted separately after you order.', 'prrint' ) . '</p>';
		}
		if ( ! empty( $t['header']['blind_ship'] ) ) {
			echo '<p><strong>' . esc_html__( 'Blind ship:', 'prrint' ) . '</strong> ' . esc_html__( 'enter your customer\'s address under "Ship to a different address". The package will not include ColorPlak branding or prices.', 'prrint' ) . '</p>';
		}
		echo '</div>';
	}

	public static function order_line_item( $item, $cart_item_key, $values ) {
		if ( empty( $values[ self::CART_KEY ] ) ) {
			return;
		}
		$row = $values[ self::CART_KEY ];
		foreach ( self::row_details( $row ) as $key => $value ) {
			$item->add_meta_data( $key, $value );
		}
		// Copy the uploaded file out of tmp/ (purged daily) into permanent storage.
		if ( ! empty( $row['file'] ) && file_exists( prrint_file_path( $row['file'] ) ) ) {
			prrint_upload_dir( 'orders' );
			$ext = pathinfo( $row['file'], PATHINFO_EXTENSION );
			$rel = 'orders/' . wp_generate_password( 24, false, false ) . '.' . $ext;
			if ( @copy( prrint_file_path( $row['file'] ), prrint_file_path( $rel ) ) ) { // phpcs:ignore
				$item->add_meta_data( '_prrint_source_file', $rel );
			}
		}
		$item->add_meta_data( '_prrint_framer_row', wp_json_encode( $row ) );
	}

	public static function create_order( $order, $data ) {
		if ( ! self::cart_has_framer_items() ) {
			return;
		}
		$header  = (array) WC()->session->get( self::SESSION, array() );
		$details = Prrint_Framers::get_details( get_current_user_id() );
		$order->update_meta_data( '_prrint_framer_order', 'yes' );
		$order->update_meta_data( '_prrint_framer_business', $details['business'] );
		$order->update_meta_data( '_prrint_framer_po', isset( $header['po'] ) ? $header['po'] : '' );
		$order->update_meta_data( '_prrint_framer_needed_by', isset( $header['needed_by'] ) ? $header['needed_by'] : '' );
		$order->update_meta_data( '_prrint_framer_rush', ! empty( $header['rush'] ) ? 'yes' : 'no' );
		$order->update_meta_data( '_prrint_framer_blind_ship', ! empty( $header['blind_ship'] ) ? 'yes' : 'no' );
		$t = self::totals( WC()->cart );
		if ( $t['ship']['freight_quote'] ) {
			$order->add_order_note( __( 'Oversized pieces in this order: freight still needs to be quoted to the framer.', 'prrint' ) );
		}
		if ( ! empty( $header['blind_ship'] ) ) {
			$order->add_order_note( __( 'BLIND SHIP: do not include ColorPlak branding or prices in the package.', 'prrint' ) );
		}
	}

	public static function clear_session() {
		if ( WC()->session ) {
			WC()->session->set( self::SESSION, null );
		}
	}

	public static function admin_order_box( $order ) {
		if ( 'yes' !== $order->get_meta( '_prrint_framer_order' ) ) {
			return;
		}
		$needed = $order->get_meta( '_prrint_framer_needed_by' );
		echo '<div class="form-field form-field-wide prrint-framer-order-box" style="margin-top:12px;padding:10px 12px;border-left:4px solid #2271b1;background:#f0f6fc;">';
		echo '<h3 style="margin-top:0">' . esc_html__( 'Framer wholesale order', 'prrint' ) . '</h3>';
		echo '<p><strong>' . esc_html__( 'Framer:', 'prrint' ) . '</strong> ' . esc_html( $order->get_meta( '_prrint_framer_business' ) ) . '<br />';
		echo '<strong>' . esc_html__( 'P.O. number:', 'prrint' ) . '</strong> ' . esc_html( $order->get_meta( '_prrint_framer_po' ) ? $order->get_meta( '_prrint_framer_po' ) : '—' ) . '<br />';
		echo '<strong>' . esc_html__( 'Needed by:', 'prrint' ) . '</strong> ' . esc_html( $needed ? $needed : '—' ) . '<br />';
		echo '<strong>' . esc_html__( 'Rush:', 'prrint' ) . '</strong> ' . esc_html( 'yes' === $order->get_meta( '_prrint_framer_rush' ) ? __( 'Yes', 'prrint' ) : __( 'No', 'prrint' ) ) . '</p>';
		if ( 'yes' === $order->get_meta( '_prrint_framer_blind_ship' ) ) {
			echo '<p style="font-size:14px;color:#b32d2e"><strong>' . esc_html__( 'BLIND SHIP — no ColorPlak branding or prices in the package.', 'prrint' ) . '</strong></p>';
		}
		echo '</div>';
	}
}
