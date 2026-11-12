<?php
/**
 * Retail box shipping ("Colorplak Box Shipping").
 *
 * Shipping is priced from the size and number of prints: every print falls
 * in a box tier by square inches (W × H), and each box costs its box price
 * plus freight. The tier table is one filterable array (prrint_shipping_tiers).
 *
 * Two ways to box an order (chosen per method instance):
 *  - "upgrade" (default): the whole order ships in the boxes of its largest
 *    print: boxes = ceil( all prints / that tier's prints per box ).
 *  - "separate": each tier is boxed and charged on its own, then summed.
 *
 * Oversized prints (a tier whose freight is a quote) either block checkout
 * with a "contact us" notice, or are charged the box price only.
 *
 * The rate itself is added by Prrint_Shipping_Method (a WooCommerce shipping
 * method for shipping zones); everything it decides lives here so the cart
 * check, the cart estimate and the tests share one implementation.
 *
 * @package prrint
 */

defined( 'ABSPATH' ) || exit;

class Prrint_Shipping {

	const METHOD_ID  = 'prrint_box_shipping';
	const LOG_SOURCE = 'prrint-shipping';

	/** Product ids already logged as "no size" this request. */
	protected static $logged = array();

	public static function init() {
		add_action( 'woocommerce_shipping_init', array( __CLASS__, 'load_method' ) );
		add_filter( 'woocommerce_shipping_methods', array( __CLASS__, 'register_method' ) );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'check_cart_items' ) );
		add_action( 'woocommerce_cart_totals_before_order_total', array( __CLASS__, 'cart_estimate_row' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			WP_CLI::add_command( 'prrint shipping-test', array( __CLASS__, 'cli_test' ) );
		}
	}

	public static function load_method() {
		require_once PRRINT_DIR . 'includes/class-prrint-shipping-method.php';
	}

	public static function register_method( $methods ) {
		$methods[ self::METHOD_ID ] = 'Prrint_Shipping_Method';
		return $methods;
	}

	/* ---------------------------------------------------------------- tiers */

	/**
	 * Box tiers by square inches (inclusive upper bound; null = no limit),
	 * smallest first. freight null = freight is quoted (oversized).
	 *
	 * @return array[] list of { max_sqin, box, freight, per_box }
	 */
	public static function tiers() {
		$tiers = array(
			array( 'max_sqin' => 224,  'box' => 8.0,  'freight' => 14.0, 'per_box' => 10 ),
			array( 'max_sqin' => 600,  'box' => 17.0, 'freight' => 19.5, 'per_box' => 6 ),
			array( 'max_sqin' => 1200, 'box' => 24.0, 'freight' => 28.0, 'per_box' => 4 ),
			array( 'max_sqin' => 1728, 'box' => 34.0, 'freight' => 55.0, 'per_box' => 2 ),
			array( 'max_sqin' => null, 'box' => 45.0, 'freight' => null, 'per_box' => 1 ),
		);
		$tiers = apply_filters( 'prrint_shipping_tiers', $tiers );
		return array_values( is_array( $tiers ) && $tiers ? $tiers : array() );
	}

	/** Tier number (1 = smallest) for a print of $sqin square inches. */
	public static function tier_for_sqin( $sqin ) {
		$tiers = self::tiers();
		foreach ( $tiers as $i => $tier ) {
			if ( null === $tier['max_sqin'] || (float) $sqin <= (float) $tier['max_sqin'] ) {
				return $i + 1;
			}
		}
		return count( $tiers );
	}

	/** Whether a tier's freight has to be quoted (oversized). */
	public static function is_oversized_tier( $tier ) {
		$tiers = self::tiers();
		return isset( $tiers[ $tier - 1 ] ) && null === $tiers[ $tier - 1 ]['freight'];
	}

	/* ---------------------------------------------------------- cart items */

	/**
	 * Print size of a cart (or package) item in inches.
	 *
	 * @return array|string|null [ w, h ]; 'skip' for items that don't ship in
	 *                           boxes (virtual/downloadable, framer wholesale
	 *                           lines, sizeless "Custom Design"); null when no
	 *                           size could be found.
	 */
	public static function item_size( $cart_item ) {
		// Framer wholesale lines are boxed and freighted by their own fees.
		if ( ! empty( $cart_item['prrint_framer'] ) ) {
			return 'skip';
		}
		// "Customize your image" items: the print size (custom sizes included).
		if ( ! empty( $cart_item['prrint']['w_in'] ) && ! empty( $cart_item['prrint']['h_in'] ) ) {
			return array( (float) $cart_item['prrint']['w_in'], (float) $cart_item['prrint']['h_in'] );
		}

		$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
		if ( ! $product instanceof WC_Product || $product->is_virtual() || $product->is_downloadable() ) {
			return 'skip';
		}

		// Variation attributes chosen in the cart ("attribute_pa_size" => "8x12"…).
		$variation = isset( $cart_item['variation'] ) && is_array( $cart_item['variation'] ) ? $cart_item['variation'] : array();
		foreach ( $variation as $key => $value ) {
			if ( false === stripos( (string) $key, 'size' ) || '' === (string) $value ) {
				continue;
			}
			$dims = self::parse_attribute( substr( (string) $key, strlen( 'attribute_' ) ), (string) $value );
			if ( $dims ) {
				return $dims;
			}
		}
		// The product's own Size attribute (simple products, "any size" variations).
		foreach ( array( 'pa_size', 'size' ) as $attribute ) {
			$value = $product->get_attribute( $attribute );
			$dims  = '' !== $value ? Prrint_Style_Products::parse_dims( $value ) : null;
			if ( $dims ) {
				return $dims;
			}
		}

		if ( self::is_sizeless_product( $product ) ) {
			return 'skip';
		}
		return null;
	}

	/** Dimensions from one attribute value (term name for taxonomies, else the value). */
	protected static function parse_attribute( $attribute, $value ) {
		if ( taxonomy_exists( $attribute ) ) {
			$term = get_term_by( 'slug', $value, $attribute );
			if ( $term && ! is_wp_error( $term ) ) {
				$dims = Prrint_Style_Products::parse_dims( $term->name );
				if ( $dims ) {
					return $dims;
				}
			}
		}
		return Prrint_Style_Products::parse_dims( $value );
	}

	/**
	 * Products that never carry a size and aren't boxed by this method: the
	 * "Custom Design" service and the framer wholesale order product.
	 */
	protected static function is_sizeless_product( $product ) {
		$id   = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		$name = Prrint_Style_Products::norm( $product->get_name() );
		$skip = (array) apply_filters( 'prrint_shipping_sizeless_products', array( 'Custom Design', 'Framer wholesale order' ) );
		foreach ( $skip as $label ) {
			if ( '' !== $name && Prrint_Style_Products::norm( $label ) === $name ) {
				return true;
			}
		}
		return $id && (int) get_option( 'prrint_framer_product' ) === (int) $id;
	}

	/**
	 * Boxable lines of a cart/package: { tier, qty, sqin }. Items without a
	 * size ship as Tier 1, logged as a warning so the product can be fixed.
	 */
	public static function lines( $contents ) {
		$lines = array();
		foreach ( (array) $contents as $item ) {
			$qty = isset( $item['quantity'] ) ? absint( $item['quantity'] ) : 0;
			if ( ! $qty ) {
				continue;
			}
			$size = self::item_size( $item );
			if ( 'skip' === $size ) {
				continue;
			}
			if ( null === $size ) {
				self::log_missing_size( $item );
				$lines[] = array( 'tier' => 1, 'qty' => $qty, 'sqin' => 0.0 );
				continue;
			}
			$sqin    = round( $size[0] * $size[1], 4 );
			$lines[] = array( 'tier' => self::tier_for_sqin( $sqin ), 'qty' => $qty, 'sqin' => $sqin );
		}
		return $lines;
	}

	protected static function log_missing_size( $item ) {
		$product = isset( $item['data'] ) && $item['data'] instanceof WC_Product ? $item['data'] : null;
		$id      = $product ? $product->get_id() : 0;
		if ( isset( self::$logged[ $id ] ) || ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		self::$logged[ $id ] = true;
		wc_get_logger()->warning(
			sprintf( 'No print size found for product #%d (%s); shipping it as Tier 1.', $id, $product ? $product->get_name() : '?' ),
			array( 'source' => self::LOG_SOURCE )
		);
	}

	/* ---------------------------------------------------------- the quote */

	/**
	 * Boxes and cost for a set of lines.
	 *
	 * @param array  $lines list of { tier, qty }.
	 * @param string $mode  'upgrade' or 'separate'.
	 * @return array { cost, boxes, qty, tier (largest), oversized (bool) }
	 */
	public static function quote( $lines, $mode = 'upgrade' ) {
		$tiers  = self::tiers();
		$out    = array( 'cost' => 0.0, 'boxes' => 0, 'qty' => 0, 'tier' => 0, 'oversized' => false );
		$groups = array();
		foreach ( $lines as $line ) {
			$tier        = max( 1, min( count( $tiers ), (int) $line['tier'] ) );
			$out['qty'] += (int) $line['qty'];
			$out['tier'] = max( $out['tier'], $tier );
			$groups[ $tier ] = ( isset( $groups[ $tier ] ) ? $groups[ $tier ] : 0 ) + (int) $line['qty'];
			if ( self::is_oversized_tier( $tier ) ) {
				$out['oversized'] = true;
			}
		}
		if ( ! $out['qty'] ) {
			return $out;
		}
		if ( 'separate' !== $mode ) {
			$groups = array( $out['tier'] => $out['qty'] );
		}
		foreach ( $groups as $tier => $qty ) {
			$row           = $tiers[ $tier - 1 ];
			$boxes         = (int) ceil( $qty / max( 1, (int) $row['per_box'] ) );
			$out['boxes'] += $boxes;
			// A quoted (oversized) freight is charged as the box price only.
			$out['cost'] += $boxes * ( (float) $row['box'] + (float) $row['freight'] );
		}
		$out['cost'] = round( $out['cost'], 2 );
		return $out;
	}

	/** Settings a method instance can have, with their defaults. */
	public static function default_settings() {
		return array(
			'title'     => __( 'Standard Shipping', 'prrint' ),
			'mode'      => 'upgrade',
			'oversized' => 'block',
			'free_min'  => '',
		);
	}

	/** Settings with unknown/invalid values replaced by the defaults. */
	public static function clean_settings( $settings ) {
		$s = array_merge( self::default_settings(), array_filter( (array) $settings, 'is_scalar' ) );
		$s['title']     = '' !== trim( (string) $s['title'] ) ? sanitize_text_field( $s['title'] ) : self::default_settings()['title'];
		$s['mode']      = 'separate' === $s['mode'] ? 'separate' : 'upgrade';
		$s['oversized'] = 'charge_box' === $s['oversized'] ? 'charge_box' : 'block';
		$s['free_min']  = is_numeric( $s['free_min'] ) && (float) $s['free_min'] > 0 ? (float) $s['free_min'] : '';
		return $s;
	}

	/**
	 * What a method instance offers for some cart contents.
	 *
	 * @param array $contents Cart/package items.
	 * @param array $settings Method settings (see default_settings()).
	 * @param float $subtotal Contents cost, for the free shipping threshold.
	 * @return array|null null = nothing to ship; else { blocked (bool),
	 *                    cost, boxes, label, quote }.
	 */
	public static function rate_for( $contents, $settings, $subtotal = 0.0 ) {
		$s     = self::clean_settings( $settings );
		$quote = self::quote( self::lines( $contents ), $s['mode'] );
		if ( ! $quote['qty'] ) {
			return null;
		}
		if ( $quote['oversized'] && 'block' === $s['oversized'] ) {
			return array( 'blocked' => true, 'cost' => 0.0, 'boxes' => $quote['boxes'], 'label' => $s['title'], 'quote' => $quote );
		}
		$free = '' !== $s['free_min'] && (float) $subtotal >= $s['free_min'];
		return array(
			'blocked' => false,
			'cost'    => $free ? 0.0 : $quote['cost'],
			'boxes'   => $quote['boxes'],
			/* translators: 1: method title, 2: number of boxes */
			'label'   => sprintf( _n( '%1$s (%2$d box)', '%1$s (%2$d boxes)', $quote['boxes'], 'prrint' ), $s['title'], $quote['boxes'] ),
			'quote'   => $quote,
		);
	}

	public static function oversized_notice() {
		return apply_filters(
			'prrint_shipping_oversized_notice',
			__( 'Oversized items require a freight quote. Please contact us at 951-302-4600 or Printing@colorplak.com.', 'prrint' )
		);
	}

	/* ---------------------------------------------- cart: check + estimate */

	/**
	 * Settings of the Colorplak Box Shipping instance that applies to the
	 * customer's cart: the one in the zone matching their address, else the
	 * first enabled one in any zone (address not entered yet); null when the
	 * store doesn't use this method.
	 */
	public static function active_settings() {
		if ( ! class_exists( 'WC_Shipping_Zones' ) || ! WC()->cart ) {
			return null;
		}
		$packages = WC()->cart->get_shipping_packages();
		$zones    = array();
		if ( ! empty( $packages[0] ) ) {
			$zones[] = WC_Shipping_Zones::get_zone_matching_package( $packages[0] );
		}
		foreach ( WC_Shipping_Zones::get_zones() as $zone ) {
			$zones[] = WC_Shipping_Zones::get_zone( $zone['id'] );
		}
		foreach ( $zones as $zone ) {
			if ( ! $zone ) {
				continue;
			}
			foreach ( $zone->get_shipping_methods( true ) as $method ) {
				if ( self::METHOD_ID === $method->id ) {
					return self::clean_settings( $method->instance_settings );
				}
			}
		}
		return null;
	}

	/** Oversized prints in the cart block checkout (cart + checkout notice). */
	public static function check_cart_items() {
		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			return;
		}
		$settings = self::active_settings();
		if ( ! $settings || 'block' !== $settings['oversized'] ) {
			return;
		}
		$quote = self::quote( self::lines( WC()->cart->get_cart() ), $settings['mode'] );
		if ( $quote['oversized'] ) {
			wc_add_notice( esc_html( self::oversized_notice() ), 'error' );
		}
	}

	/**
	 * "Shipping estimate" row in the cart totals, so the shipping cost shows
	 * before checkout even when WooCommerce is still waiting for an address.
	 * Skipped when the cart's chosen rate is already this method's (its own
	 * Shipping row shows the same figure).
	 */
	public static function cart_estimate_row() {
		if ( ! function_exists( 'is_cart' ) || ! is_cart() || ! WC()->cart || ! WC()->cart->needs_shipping() ) {
			return;
		}
		$chosen = WC()->session ? (array) WC()->session->get( 'chosen_shipping_methods', array() ) : array();
		foreach ( $chosen as $rate_id ) {
			if ( 0 === strpos( (string) $rate_id, self::METHOD_ID ) ) {
				return;
			}
		}
		$settings = self::active_settings();
		if ( ! $settings ) {
			return;
		}
		$rate = self::rate_for( WC()->cart->get_cart(), $settings, (float) WC()->cart->get_displayed_subtotal() );
		if ( ! $rate ) {
			return;
		}
		if ( $rate['blocked'] ) {
			$value = '<span class="prrint-shipping-oversized">' . esc_html( self::oversized_notice() ) . '</span>';
		} else {
			/* translators: %d: number of boxes */
			$boxes = sprintf( _n( '%d box', '%d boxes', $rate['boxes'], 'prrint' ), $rate['boxes'] );
			$value = wp_kses_post( wc_price( $rate['cost'] ) ) . ' <small>(' . esc_html( $boxes ) . ')</small>';
		}
		printf(
			'<tr class="prrint-shipping-estimate"><th>%1$s</th><td data-title="%2$s">%3$s</td></tr>',
			esc_html__( 'Shipping estimate', 'prrint' ),
			esc_attr__( 'Shipping estimate', 'prrint' ),
			$value // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		);
	}

	/* ------------------------------------------------------------- WP-CLI */

	/**
	 * Runs the shipping test cases against the live tier table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp prrint shipping-test
	 */
	public static function cli_test() {
		$results = self::self_test();
		$failed  = 0;
		foreach ( $results as $row ) {
			if ( $row['pass'] ) {
				WP_CLI::log( 'PASS  ' . $row['name'] . ' — ' . $row['got'] );
			} else {
				++$failed;
				WP_CLI::warning( 'FAIL  ' . $row['name'] . ' — expected ' . $row['want'] . ', got ' . $row['got'] );
			}
		}
		if ( $failed ) {
			WP_CLI::error( sprintf( '%d of %d shipping checks failed.', $failed, count( $results ) ) );
		}
		WP_CLI::success( sprintf( 'All %d shipping checks passed.', count( $results ) ) );
	}

	/**
	 * The client's shipping cases, built from in-memory (unsaved) products.
	 *
	 * @return array[] list of { name, want, got, pass }
	 */
	public static function self_test() {
		$item = function ( $size, $qty ) {
			$product = new WC_Product_Simple();
			$product->set_name( 'Print ' . $size );
			return array( 'data' => $product, 'quantity' => $qty, 'variation' => array( 'attribute_size' => $size ) );
		};
		$up    = array( 'mode' => 'upgrade' );
		$sep   = array( 'mode' => 'separate' );
		$fmt   = function ( $rate ) {
			if ( ! $rate ) {
				return 'no rate';
			}
			return $rate['blocked'] ? 'blocked' : sprintf( '$%.2f, %d box(es)', $rate['cost'], $rate['boxes'] );
		};
		$cases = array(
			array( '1. 5 × 8x12', array( $item( '8x12', 5 ) ), $up, '$22.00, 1 box(es)' ),
			array( '2. 11 × 8x10', array( $item( '8x10', 11 ) ), $up, '$44.00, 2 box(es)' ),
			array( '3a. 5 × 8x12 + 1 × 16x24 (upgrade)', array( $item( '8x12', 5 ), $item( '16x24', 1 ) ), $up, '$36.50, 1 box(es)' ),
			array( '3b. 5 × 8x12 + 1 × 16x24 (separate)', array( $item( '8x12', 5 ), $item( '16x24', 1 ) ), $sep, '$58.50, 2 box(es)' ),
			array( '4. 3 × 24x30', array( $item( '24x30', 3 ) ), $up, '$52.00, 1 box(es)' ),
			array( '5. 3 × 36x36', array( $item( '36x36', 3 ) ), $up, '$178.00, 2 box(es)' ),
			array( '6. 1 × 40x48 (oversized)', array( $item( '40x48', 1 ) ), $up, 'blocked' ),
		);
		$out = array();
		foreach ( $cases as $case ) {
			$got   = $fmt( self::rate_for( $case[1], $case[2] ) );
			$out[] = array( 'name' => $case[0], 'want' => $case[3], 'got' => $got, 'pass' => $got === $case[3] );
		}
		$custom = self::lines( array( array( 'quantity' => 1, 'prrint' => array( 'w_in' => 12.0, 'h_in' => 18.0 ) ) ) );
		$out[]  = array( 'name' => '7. Customizer item 12x18', 'want' => 'Tier 1', 'got' => 'Tier ' . $custom[0]['tier'], 'pass' => 1 === $custom[0]['tier'] );
		$b      = array( self::tier_for_sqin( 14 * 16 ), self::tier_for_sqin( 16 * 16 ) );
		$out[]  = array( 'name' => '8. 14x16 (224) / 16x16 (256)', 'want' => 'Tier 1 / Tier 2', 'got' => 'Tier ' . $b[0] . ' / Tier ' . $b[1], 'pass' => array( 1, 2 ) === $b );
		return $out;
	}
}
