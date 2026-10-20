<?php
/**
 * Styles linked to shop products.
 *
 * Each studio style (ColorLite, Metal Float…) can take its sizes and prices
 * from the store's WooCommerce product for that style — matched by name
 * ("COLOR LITE" ↔ "ColorLite") or picked in the settings — so a ColorLite
 * 8x10 costs the same in the studio as on the ColorLite product page. Those
 * product pages then get a "Customize your image" button in place of Add to
 * cart, which opens the studio with the chosen style, size and orientation.
 *
 * @package prrint
 */

defined( 'ABSPATH' ) || exit;

class Prrint_Style_Products {

	/** Bumped whenever a product changes; part of every cache key below. */
	const VERSION_OPTION = 'prrint_style_products_ver';

	/** Request-level cache of parsed product sizes. */
	protected static $sizes = array();

	public static function init() {
		foreach ( array(
			'woocommerce_update_product',
			'woocommerce_new_product',
			'woocommerce_update_product_variation',
			'woocommerce_new_product_variation',
			'woocommerce_delete_product_variation',
			'woocommerce_product_set_stock_status',
			'woocommerce_variation_set_stock_status',
			'trashed_post',
			'untrashed_post',
			'deleted_post',
		) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush' ) );
		}

		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'woocommerce_after_add_to_cart_button', array( __CLASS__, 'render_customize_button' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_product_page' ) );
	}

	public static function flush() {
		self::$sizes = array();
		update_option( self::VERSION_OPTION, (int) get_option( self::VERSION_OPTION, 0 ) + 1, false );
	}

	protected static function cache_key( $what ) {
		return 'prrint_sp_' . (int) get_option( self::VERSION_OPTION, 0 ) . '_' . $what;
	}

	/**
	 * Name used for matching: lowercase letters/digits only, "&" and "and"
	 * dropped, so "COLOR LITE", "Color-Lite" and "ColorLite" all match, as
	 * do "Lam & Mount" and "Lam and Mount".
	 */
	public static function norm( $name ) {
		$name = strtolower( html_entity_decode( (string) $name, ENT_QUOTES, 'UTF-8' ) );
		$name = preg_replace( '/&|\band\b/', '', $name );
		return preg_replace( '/[^a-z0-9]/', '', $name );
	}

	/**
	 * Published variable products by normalized name.
	 *
	 * @return array norm name => product id
	 */
	public static function name_map() {
		$key = self::cache_key( 'names' );
		$map = get_transient( $key );
		if ( is_array( $map ) ) {
			return $map;
		}
		$map = array();
		$ids = wc_get_products( array(
			'type'    => 'variable',
			'status'  => 'publish',
			'limit'   => -1,
			'orderby' => 'ID',
			'order'   => 'ASC',
			'return'  => 'ids',
		) );
		foreach ( (array) $ids as $id ) {
			$norm = self::norm( get_the_title( $id ) );
			if ( '' !== $norm && ! isset( $map[ $norm ] ) ) {
				$map[ $norm ] = (int) $id;
			}
		}
		set_transient( $key, $map, 12 * HOUR_IN_SECONDS );
		return $map;
	}

	/**
	 * The product a style takes its sizes from: 0 = none. A style's
	 * "product" setting is 0 for automatic (match by name), -1 for none
	 * (use the global sizes), or a product id.
	 */
	public static function product_for_style( $style ) {
		$setting = isset( $style['product'] ) ? (int) $style['product'] : 0;
		if ( $setting < 0 ) {
			return 0;
		}
		if ( $setting > 0 ) {
			return 'publish' === get_post_status( $setting ) ? $setting : 0;
		}
		$map  = self::name_map();
		$norm = self::norm( isset( $style['label'] ) ? $style['label'] : '' );
		return ( '' !== $norm && isset( $map[ $norm ] ) ) ? $map[ $norm ] : 0;
	}

	/**
	 * Width and height in inches from a size like "8x10", "8 x 10", '8" x 10"',
	 * "8-x-10" (a term slug) or "4-5x6" (4.5 x 6). Null when there's none.
	 */
	public static function parse_dims( $text ) {
		$text = strtolower( html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' ) );
		$num  = '(\d+(?:[.,-]\d+)?)';
		if ( ! preg_match( '/' . $num . '\s*(?:"|”|in\.?|inch(?:es)?)?\s*-?\s*(?:x|×|by)\s*-?\s*' . $num . '/u', $text, $m ) ) {
			return null;
		}
		$w = (float) strtr( $m[1], ',-', '..' );
		$h = (float) strtr( $m[2], ',-', '..' );
		return ( $w > 0 && $h > 0 ) ? array( $w, $h ) : null;
	}

	/** landscape / portrait / square from an attribute value, '' when it's not one. */
	public static function parse_orientation( $text ) {
		$text = strtolower( (string) $text );
		foreach ( array( 'landscape', 'portrait', 'square' ) as $o ) {
			if ( false !== strpos( $text, $o ) ) {
				return $o;
			}
		}
		if ( false !== strpos( $text, 'horizontal' ) ) {
			return 'landscape';
		}
		if ( false !== strpos( $text, 'vertical' ) ) {
			return 'portrait';
		}
		return '';
	}

	/** Display name of a variation attribute value (term name for taxonomies). */
	protected static function attribute_text( $attribute, $value ) {
		if ( taxonomy_exists( $attribute ) ) {
			$term = get_term_by( 'slug', $value, $attribute );
			if ( $term && ! is_wp_error( $term ) ) {
				return $term->name;
			}
		}
		return $value;
	}

	/**
	 * Sizes sold by a variable product, read from its variations. Each row
	 * is a size (short side first) with its price per orientation:
	 *   label, w, h, price (lowest), prices { landscape|portrait|square }, variations { same keys => id }
	 * A variation without an orientation attribute prices every orientation.
	 *
	 * @return array[]
	 */
	public static function product_sizes( $product_id ) {
		$product_id = (int) $product_id;
		if ( isset( self::$sizes[ $product_id ] ) ) {
			return self::$sizes[ $product_id ];
		}
		$key  = self::cache_key( 'sizes_' . $product_id );
		$rows = get_transient( $key );
		if ( ! is_array( $rows ) ) {
			$rows = self::read_product_sizes( $product_id );
			set_transient( $key, $rows, 12 * HOUR_IN_SECONDS );
		}
		self::$sizes[ $product_id ] = $rows;
		return $rows;
	}

	protected static function read_product_sizes( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return array();
		}
		$by_dims = array();
		foreach ( $product->get_children() as $vid ) {
			$variation = wc_get_product( $vid );
			if ( ! $variation || 'publish' !== $variation->get_status() || ! $variation->is_in_stock() || '' === $variation->get_price() ) {
				continue;
			}
			$dims        = null;
			$orientation = '';
			foreach ( $variation->get_attributes() as $attribute => $value ) {
				if ( '' === (string) $value ) {
					continue;
				}
				$text = self::attribute_text( $attribute, $value );
				if ( ! $dims ) {
					$dims = self::parse_dims( $text );
					if ( ! $dims && $text !== $value ) {
						$dims = self::parse_dims( $value );
					}
				}
				if ( '' === $orientation ) {
					$orientation = self::parse_orientation( $text );
				}
			}
			if ( ! $dims ) {
				continue;
			}
			$lo = min( $dims );
			$hi = max( $dims );
			if ( '' === $orientation && $lo === $hi ) {
				$orientation = 'square';
			}
			$k = $lo . 'x' . $hi;
			if ( ! isset( $by_dims[ $k ] ) ) {
				$by_dims[ $k ] = array(
					'label'      => self::fmt( $lo ) . '×' . self::fmt( $hi ),
					'w'          => $lo,
					'h'          => $hi,
					'price'      => null,
					'prices'     => array(),
					'variations' => array(),
				);
			}
			$price   = (float) $variation->get_price();
			$targets = '' === $orientation ? ( $lo === $hi ? array( 'square' ) : array( 'landscape', 'portrait' ) ) : array( $orientation );
			foreach ( $targets as $o ) {
				// An orientation-specific variation wins over an "any orientation" one.
				if ( '' === $orientation && isset( $by_dims[ $k ]['prices'][ $o ] ) ) {
					continue;
				}
				$by_dims[ $k ]['prices'][ $o ]     = $price;
				$by_dims[ $k ]['variations'][ $o ] = (int) $vid;
			}
			$by_dims[ $k ]['price'] = null === $by_dims[ $k ]['price'] ? $price : min( $by_dims[ $k ]['price'], $price );
		}
		$rows = array_values( $by_dims );
		usort( $rows, function ( $a, $b ) {
			return ( $a['w'] * $a['h'] <=> $b['w'] * $b['h'] ) ?: ( $a['w'] <=> $b['w'] );
		} );
		return $rows;
	}

	protected static function fmt( $n ) {
		return rtrim( rtrim( number_format( (float) $n, 2, '.', '' ), '0' ), '.' );
	}

	/**
	 * Price of a size row for an orientation: that orientation's variation
	 * price, else the row's price (the global sizes have only that).
	 */
	public static function size_price( $size, $orientation ) {
		$w   = isset( $size['w'] ) ? (float) $size['w'] : 0;
		$h   = isset( $size['h'] ) ? (float) $size['h'] : 0;
		$key = $w === $h ? 'square' : ( 'landscape' === $orientation ? 'landscape' : 'portrait' );
		if ( isset( $size['prices'][ $key ] ) ) {
			return (float) $size['prices'][ $key ];
		}
		return isset( $size['price'] ) ? (float) $size['price'] : 0.0;
	}

	/**
	 * Studio styles with their linked product and sizes: adds "linked"
	 * (product id, 0 = not linked) and, when linked, "sizes".
	 */
	public static function styles_with_sizes( $styles ) {
		$out = array();
		foreach ( array_values( (array) $styles ) as $style ) {
			$pid   = self::product_for_style( $style );
			$sizes = $pid ? self::product_sizes( $pid ) : array();
			$style['linked'] = $sizes ? $pid : 0;
			if ( $sizes ) {
				$style['sizes'] = $sizes;
			}
			$out[] = $style;
		}
		return $out;
	}

	/**
	 * Sizes for an order line: the style's product sizes when it's linked,
	 * else the studio product's own sizes.
	 */
	public static function sizes_for( $style, $studio_product_id ) {
		$pid = $style ? self::product_for_style( $style ) : 0;
		if ( $pid ) {
			$sizes = self::product_sizes( $pid );
			if ( $sizes ) {
				return $sizes;
			}
		}
		return prrint_get_sizes( $studio_product_id );
	}

	/** Index of the style linked to a product, -1 when none is. */
	public static function style_index_for_product( $product_id ) {
		foreach ( array_values( prrint_settings()['styles'] ) as $i => $style ) {
			if ( (int) $product_id === self::product_for_style( $style ) ) {
				return $i;
			}
		}
		return -1;
	}

	/**
	 * Where "Customize your image" goes: the chosen studio page, else the
	 * auto-created "Create Your Print" page when it's published, else the
	 * first published page holding [prrint_studio].
	 */
	public static function studio_url() {
		$page_id = (int) prrint_settings()['studio_page_id'];
		if ( ! $page_id || 'publish' !== get_post_status( $page_id ) ) {
			$page_id = (int) get_option( 'prrint_sample_page' );
		}
		if ( ! $page_id || 'publish' !== get_post_status( $page_id ) ) {
			$page_id = self::find_studio_page();
		}
		return $page_id ? get_permalink( $page_id ) : '';
	}

	protected static function find_studio_page() {
		$key   = self::cache_key( 'page' );
		$found = get_transient( $key );
		if ( false !== $found ) {
			return (int) $found;
		}
		$pages = get_posts( array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			's'              => '[prrint_studio',
			'posts_per_page' => 5,
			'fields'         => 'ids',
		) );
		$found = 0;
		foreach ( $pages as $id ) {
			if ( has_shortcode( get_post_field( 'post_content', $id ), 'prrint_studio' ) ) {
				$found = (int) $id;
				break;
			}
		}
		set_transient( $key, $found, HOUR_IN_SECONDS );
		return $found;
	}

	/**
	 * The product page being viewed, when a style takes its sizes from it
	 * and there's a studio page to send customers to.
	 */
	protected static function current_customize_product() {
		static $resolved = null;
		if ( null !== $resolved ) {
			return $resolved ? $resolved : null;
		}
		$resolved = 0;
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return null;
		}
		$product = wc_get_product( get_queried_object_id() );
		if ( ! $product || ! $product->is_type( 'variable' ) || prrint_is_enabled( $product ) ) {
			return null;
		}
		if ( self::style_index_for_product( $product->get_id() ) < 0 || '' === self::studio_url() ) {
			return null;
		}
		$resolved = $product;
		return $product;
	}

	public static function body_class( $classes ) {
		if ( self::current_customize_product() ) {
			$classes[] = 'prrint-customize-product';
		}
		return $classes;
	}

	public static function render_customize_button() {
		$product = self::current_customize_product();
		if ( ! $product ) {
			return;
		}
		$url = add_query_arg( 'customize', $product->get_id(), self::studio_url() );
		printf(
			'<a href="%1$s" class="button alt prrint-customize-btn disabled" data-base="%1$s" aria-disabled="true">%2$s</a>',
			esc_url( $url ),
			esc_html__( 'Customize your image', 'prrint' )
		);
		echo '<p class="prrint-customize-hint">' . esc_html__( 'Upload your photo, choose an edge color and see a preview before adding it to your cart.', 'prrint' ) . '</p>';
	}

	/**
	 * Hides the product's own quantity + Add to cart, and enables the
	 * Customize button once WooCommerce has matched a variation (its
	 * found_variation / reset_data events), carrying that variation along.
	 */
	public static function enqueue_product_page() {
		if ( ! self::current_customize_product() ) {
			return;
		}
		wp_register_style( 'prrint-customize', false, array(), PRRINT_VERSION );
		wp_enqueue_style( 'prrint-customize' );
		wp_add_inline_style( 'prrint-customize',
			'.prrint-customize-product form.variations_form .single_add_to_cart_button,' .
			'.prrint-customize-product form.variations_form .quantity{display:none!important}' .
			'.prrint-customize-product .prrint-customize-btn{display:inline-block}' .
			'.prrint-customize-product .prrint-customize-btn.disabled{opacity:.5;cursor:not-allowed}' .
			'.prrint-customize-hint{margin:.6em 0 0;font-size:.9em;opacity:.8}'
		);
		wp_enqueue_script( 'wc-add-to-cart-variation' );
		wp_add_inline_script( 'wc-add-to-cart-variation', self::product_page_js() );
	}

	protected static function product_page_js() {
		$msg = wp_json_encode( __( 'Please select some product options before customizing your image.', 'prrint' ) );
		return <<<JS
jQuery(function ($) {
	var form = $('form.variations_form');
	var btn = form.find('.prrint-customize-btn');
	if (!btn.length) { return; }
	function set(vid) {
		var url = btn.data('base');
		if (vid) { url += (url.indexOf('?') === -1 ? '?' : '&') + 'variation=' + encodeURIComponent(vid); }
		btn.attr('href', url).toggleClass('disabled', !vid).attr('aria-disabled', vid ? 'false' : 'true');
	}
	form.on('found_variation', function (e, v) { set(v && v.variation_id); });
	form.on('reset_data hide_variation', function () { set(0); });
	var current = form.find('input[name="variation_id"]').val();
	set(current && current !== '0' ? current : 0);
	btn.on('click', function (e) {
		if ($(this).hasClass('disabled')) { e.preventDefault(); window.alert($msg); }
	});
});
JS;
	}

	/**
	 * Studio preselection from ?customize=<product>&variation=<id>:
	 * { style, size, orientation } with -1 / '' for anything not found.
	 */
	public static function preselect_from_request( $styles ) {
		$out = array( 'style' => -1, 'size' => -1, 'orientation' => '' );
		$pid = isset( $_GET['customize'] ) ? absint( $_GET['customize'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$vid = isset( $_GET['variation'] ) ? absint( $_GET['variation'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $pid ) {
			return $out;
		}
		foreach ( array_values( $styles ) as $i => $style ) {
			if ( empty( $style['linked'] ) || (int) $style['linked'] !== $pid ) {
				continue;
			}
			$out['style'] = $i;
			foreach ( $style['sizes'] as $j => $size ) {
				foreach ( $size['variations'] as $orientation => $id ) {
					if ( $vid && (int) $id === $vid ) {
						$out['size']        = $j;
						$out['orientation'] = 'square' === $orientation ? '' : $orientation;
						return $out;
					}
				}
			}
			return $out;
		}
		return $out;
	}
}
