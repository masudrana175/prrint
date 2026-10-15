<?php
/**
 * Wholesale price catalog for framers: the price sheet (editable by admins),
 * the "square inch, round up to the next size" pricing rule, per-line size
 * limits, and boxing/freight rules.
 *
 * Shared by the framer order form (client preview) and checkout (the
 * authoritative server-side recalculation).
 *
 * @package prrint
 */

defined( 'ABSPATH' ) || exit;

class Prrint_Wholesale {

	const OPTION = 'prrint_wholesale';

	/** "Image size + or - 1/8"" on the price sheet. */
	const TOLERANCE_IN = 0.125;

	protected static $catalog = null;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 20 );
		add_action( 'admin_post_prrint_wholesale_save', array( __CLASS__, 'handle_save' ) );
		add_shortcode( 'prrint_framer_price_list', array( __CLASS__, 'price_list_shortcode' ) );
	}

	/* --------------------------------------------------------------------
	 * Catalog.
	 * ------------------------------------------------------------------ */

	/**
	 * Product lines: label + maximum size (short side × long side, inches),
	 * per ColorPlak: Wood 48x96, Metal 40x60, Acrylic 48x96, Canvas 48x96,
	 * Print only 48x96.
	 */
	public static function lines() {
		return array(
			'wood'    => array( 'label' => __( 'Wood', 'prrint' ), 'max' => array( 48, 96 ) ),
			'metal'   => array( 'label' => __( 'Metal', 'prrint' ), 'max' => array( 40, 60 ) ),
			'acrylic' => array( 'label' => __( 'Acrylic', 'prrint' ), 'max' => array( 48, 96 ) ),
			'canvas'  => array( 'label' => __( 'Canvas', 'prrint' ), 'max' => array( 48, 96 ) ),
			'paper'   => array( 'label' => __( 'Print only', 'prrint' ), 'max' => array( 48, 96 ) ),
		);
	}

	/**
	 * Boxing & shipping from the price sheet: up to `max_sqin`, `per_box`
	 * pieces fit in one box costing `box`, plus `freight` per box (null =
	 * oversized, freight quoted separately).
	 */
	public static function default_box_rules() {
		return array(
			array( 'max_sqin' => 224, 'box' => 8.0, 'freight' => 14.0, 'per_box' => 10 ),
			array( 'max_sqin' => 600, 'box' => 17.0, 'freight' => 19.5, 'per_box' => 6 ),
			array( 'max_sqin' => 1200, 'box' => 24.0, 'freight' => 28.0, 'per_box' => 4 ),
			array( 'max_sqin' => 1728, 'box' => 34.0, 'freight' => 55.0, 'per_box' => 2 ),
			array( 'max_sqin' => 999999, 'box' => 45.0, 'freight' => null, 'per_box' => 1 ),
		);
	}

	/** Item add-ons from the paper order form. */
	public static function options() {
		return array(
			'mat_percent' => 33,   // Mat / Colored Border: +33% of the piece price.
			'recut'       => 5.0,  // per piece.
			'trim'        => 2.0,  // per piece.
			'rush_percent' => 50,  // whole order.
		);
	}

	public static function print_conditions() {
		return array(
			'new'  => __( 'New', 'prrint' ),
			'fine' => __( 'Fine', 'prrint' ),
			'vg'   => __( 'VG (Very Good)', 'prrint' ),
			'good' => __( 'Good', 'prrint' ),
			'fair' => __( 'Fair', 'prrint' ),
			'poor' => __( 'Poor', 'prrint' ),
		);
	}

	/**
	 * The price sheet with any admin edits applied.
	 *
	 * @return array { tiers: [[label, sqin]], products: {id: {line,label,edge,supplied,printed}}, leather: [], boxes: [] }
	 */
	public static function catalog() {
		if ( null !== self::$catalog ) {
			return self::$catalog;
		}
		$sheet = require PRRINT_DIR . 'includes/framer-price-sheet.php';
		// Square inches come from the size itself: the sheet's own column
		// lists 24x30 as 700 (should be 720), which would bill a standard
		// 24x30 at the next size up.
		foreach ( $sheet['tiers'] as &$tier ) {
			$dims    = array_map( 'floatval', explode( 'x', $tier[0] ) );
			$tier[1] = (int) round( $dims[0] * $dims[1] );
		}
		unset( $tier );
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$count = count( $sheet['tiers'] );

		foreach ( $sheet['products'] as $id => &$product ) {
			foreach ( array( 'supplied', 'printed' ) as $kind ) {
				if ( null !== $product[ $kind ] && isset( $saved['prices'][ $id ][ $kind ] ) && is_array( $saved['prices'][ $id ][ $kind ] ) && count( $saved['prices'][ $id ][ $kind ] ) === $count ) {
					$product[ $kind ] = $saved['prices'][ $id ][ $kind ];
				}
			}
		}
		unset( $product );
		if ( isset( $saved['leather'] ) && is_array( $saved['leather'] ) && count( $saved['leather'] ) === $count ) {
			$sheet['leather'] = $saved['leather'];
		}
		$sheet['boxes'] = isset( $saved['boxes'] ) && is_array( $saved['boxes'] ) && count( $saved['boxes'] ) === count( self::default_box_rules() ) ? $saved['boxes'] : self::default_box_rules();

		self::$catalog = $sheet;
		return $sheet;
	}

	public static function flush_cache() {
		self::$catalog = null;
	}

	/* --------------------------------------------------------------------
	 * Pricing rule.
	 * ------------------------------------------------------------------ */

	/**
	 * Price tier for a length × width: the square inches (with the sheet's
	 * ±1/8" tolerance) rounded up to the next listed size.
	 *
	 * @return int|WP_Error Tier index.
	 */
	public static function tier_for( $line, $length, $width ) {
		$length = (float) $length;
		$width  = (float) $width;
		$lines  = self::lines();
		if ( $length <= 0 || $width <= 0 ) {
			return new WP_Error( 'prrint_size', __( 'Enter a length and width.', 'prrint' ) );
		}
		$short = min( $length, $width );
		$long  = max( $length, $width );
		$max   = $lines[ $line ]['max'];
		if ( $short > $max[0] + self::TOLERANCE_IN || $long > $max[1] + self::TOLERANCE_IN ) {
			/* translators: 1: product line, 2: max short side, 3: max long side */
			return new WP_Error( 'prrint_size', sprintf( __( '%1$s products go up to %2$s" x %3$s".', 'prrint' ), $lines[ $line ]['label'], $max[0], $max[1] ) );
		}
		$tiers = self::catalog()['tiers'];
		// A listed size is always billed as itself (16x20 and 18x18 are only
		// 4 sq in apart, so the square-inch rule alone could mix them up).
		foreach ( $tiers as $i => $tier ) {
			$dims = array_map( 'floatval', explode( 'x', $tier[0] ) );
			if ( abs( $short - min( $dims ) ) <= self::TOLERANCE_IN && abs( $long - max( $dims ) ) <= self::TOLERANCE_IN ) {
				return $i;
			}
		}
		// Custom size: square inches (within 1/8") rounded up to the next size.
		$sqin = max( 0, $length - self::TOLERANCE_IN ) * max( 0, $width - self::TOLERANCE_IN );
		foreach ( $tiers as $i => $tier ) {
			if ( $sqin <= $tier[1] + 1e-9 ) {
				return $i;
			}
		}
		return new WP_Error( 'prrint_size', __( 'That size is larger than our largest size.', 'prrint' ) );
	}

	/**
	 * Full price breakdown for one order-form line. All inputs are untrusted.
	 *
	 * @param array $item { product, length, width, qty, digital, mat, recut, trim, leather }
	 * @return array|WP_Error { tier, tier_label, base, unit, line_total, digital }
	 */
	public static function quote( $item ) {
		$catalog = self::catalog();
		$id      = isset( $item['product'] ) ? (string) $item['product'] : '';
		if ( ! isset( $catalog['products'][ $id ] ) ) {
			return new WP_Error( 'prrint_product', __( 'Choose a product.', 'prrint' ) );
		}
		$product = $catalog['products'][ $id ];
		$tier    = self::tier_for( $product['line'], isset( $item['length'] ) ? $item['length'] : 0, isset( $item['width'] ) ? $item['width'] : 0 );
		if ( is_wp_error( $tier ) ) {
			return $tier;
		}

		// Only wood can be mailed in; everything else is printed by ColorPlak.
		$digital = null === $product['supplied'] ? true : ! empty( $item['digital'] );
		$base    = $product[ $digital ? 'printed' : 'supplied' ][ $tier ];
		if ( null === $base || '' === $base ) {
			/* translators: 1: product, 2: size */
			return new WP_Error( 'prrint_size', sprintf( __( '%1$s is not available at %2$s.', 'prrint' ), $product['label'], $catalog['tiers'][ $tier ][0] ) );
		}

		$opts = self::options();
		$unit = (float) $base;
		if ( ! empty( $item['leather'] ) && 'paper' === $product['line'] && null !== $catalog['leather'][ $tier ] ) {
			$unit += (float) $catalog['leather'][ $tier ];
		}
		if ( ! empty( $item['mat'] ) ) {
			$unit += $unit * $opts['mat_percent'] / 100;
		}
		if ( ! empty( $item['recut'] ) ) {
			$unit += $opts['recut'];
		}
		if ( ! empty( $item['trim'] ) ) {
			$unit += $opts['trim'];
		}
		$unit = round( $unit, 2 );
		$qty  = max( 1, min( 999, isset( $item['qty'] ) ? absint( $item['qty'] ) : 1 ) );

		return array(
			'tier'       => $tier,
			'tier_label' => $catalog['tiers'][ $tier ][0],
			'base'       => (float) $base,
			'unit'       => $unit,
			'qty'        => $qty,
			'line_total' => round( $unit * $qty, 2 ),
			'digital'    => $digital,
		);
	}

	/**
	 * Boxing + freight for a set of pieces, grouped by box size: each group
	 * needs ceil(pieces / per_box) boxes.
	 *
	 * @param array $pieces list of { tier, qty }.
	 * @return array { boxing, freight, boxes, freight_quote (bool: oversized freight to be quoted) }
	 */
	public static function shipping( $pieces ) {
		$catalog = self::catalog();
		$rules   = $catalog['boxes'];
		$counts  = array_fill( 0, count( $rules ), 0 );
		foreach ( $pieces as $piece ) {
			$sqin = $catalog['tiers'][ (int) $piece['tier'] ][1];
			foreach ( $rules as $r => $rule ) {
				if ( $sqin <= $rule['max_sqin'] ) {
					$counts[ $r ] += (int) $piece['qty'];
					break;
				}
			}
		}
		$out = array( 'boxing' => 0.0, 'freight' => 0.0, 'boxes' => 0, 'freight_quote' => false );
		foreach ( $rules as $r => $rule ) {
			if ( ! $counts[ $r ] ) {
				continue;
			}
			$boxes          = (int) ceil( $counts[ $r ] / max( 1, (int) $rule['per_box'] ) );
			$out['boxes']  += $boxes;
			$out['boxing'] += $boxes * (float) $rule['box'];
			if ( null === $rule['freight'] || '' === $rule['freight'] ) {
				$out['freight_quote'] = true;
			} else {
				$out['freight'] += $boxes * (float) $rule['freight'];
			}
		}
		$out['boxing']  = round( $out['boxing'], 2 );
		$out['freight'] = round( $out['freight'], 2 );
		return $out;
	}

	/** Catalog in the shape the order form's JS uses for its live preview. */
	public static function client_config() {
		$c = self::catalog();
		return array(
			'tiers'      => $c['tiers'],
			'products'   => $c['products'],
			'leather'    => $c['leather'],
			'boxes'      => $c['boxes'],
			'lines'      => self::lines(),
			'options'    => self::options(),
			'conditions' => self::print_conditions(),
			'tolerance'  => self::TOLERANCE_IN,
		);
	}

	/* --------------------------------------------------------------------
	 * Admin: WooCommerce → Wholesale prices.
	 * ------------------------------------------------------------------ */

	public static function admin_menu() {
		add_submenu_page( 'woocommerce', __( 'Wholesale prices', 'prrint' ), __( 'Wholesale prices', 'prrint' ), 'manage_woocommerce', 'prrint-wholesale', array( __CLASS__, 'render_admin_page' ) );
	}

	protected static function money_input( $name, $value ) {
		printf(
			'<input type="text" inputmode="decimal" class="prrint-price-input" name="%1$s" value="%2$s" />',
			esc_attr( $name ),
			esc_attr( null === $value || '' === $value ? '' : number_format( (float) $value, 2, '.', '' ) )
		);
	}

	public static function render_admin_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$catalog = self::catalog();
		$lines   = self::lines();
		$tab     = isset( $_GET['line'] ) ? sanitize_key( wp_unslash( $_GET['line'] ) ) : 'wood'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab     = isset( $lines[ $tab ] ) || 'shipping' === $tab ? $tab : 'wood';
		$products = array_filter( $catalog['products'], function ( $p ) use ( $tab ) {
			return $p['line'] === $tab;
		} );
		?>
		<div class="wrap prrint-admin-wrap prrint-wholesale-admin">
			<h1><?php esc_html_e( 'Wholesale prices (framers)', 'prrint' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Price per piece for each size. A listed size is billed at its own price; any other size is billed by square inch at the next size up (within 1/8"). Leave a cell empty if that product isn\'t offered at that size.', 'prrint' ); ?></p>
			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Prices saved.', 'prrint' ); ?></p></div>
			<?php endif; ?>
			<h2 class="nav-tab-wrapper">
				<?php foreach ( $lines as $key => $line ) : ?>
					<a class="nav-tab <?php echo $key === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=prrint-wholesale&line=' . $key ) ); ?>"><?php echo esc_html( $line['label'] ); ?></a>
				<?php endforeach; ?>
				<a class="nav-tab <?php echo 'shipping' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=prrint-wholesale&line=shipping' ) ); ?>"><?php esc_html_e( 'Boxing & freight', 'prrint' ); ?></a>
			</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="prrint_wholesale_save" />
				<input type="hidden" name="line" value="<?php echo esc_attr( $tab ); ?>" />
				<?php wp_nonce_field( 'prrint_wholesale_save' ); ?>
				<?php if ( 'shipping' === $tab ) : ?>
					<table class="widefat striped prrint-price-grid" style="max-width:720px">
						<thead><tr><th><?php esc_html_e( 'Up to (sq. in.)', 'prrint' ); ?></th><th><?php esc_html_e( 'Box ($)', 'prrint' ); ?></th><th><?php esc_html_e( 'Freight per box ($)', 'prrint' ); ?></th><th><?php esc_html_e( 'Pieces per box', 'prrint' ); ?></th></tr></thead>
						<tbody>
							<?php foreach ( $catalog['boxes'] as $i => $rule ) : ?>
								<tr>
									<td><?php echo $rule['max_sqin'] >= 999999 ? esc_html__( 'Oversized', 'prrint' ) : (int) $rule['max_sqin']; ?></td>
									<td><?php self::money_input( 'boxes[' . $i . '][box]', $rule['box'] ); ?></td>
									<td><?php self::money_input( 'boxes[' . $i . '][freight]', $rule['freight'] ); ?> <?php if ( null === $rule['freight'] ) : ?><span class="description"><?php esc_html_e( 'empty = call for quote', 'prrint' ); ?></span><?php endif; ?></td>
									<td><input type="number" min="1" max="100" class="small-text" name="boxes[<?php echo (int) $i; ?>][per_box]" value="<?php echo (int) $rule['per_box']; ?>" /></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p><?php printf( /* translators: 1: line, 2: short side, 3: long side */ esc_html__( '%1$s products: maximum size %2$s" x %3$s".', 'prrint' ), esc_html( $lines[ $tab ]['label'] ), (int) $lines[ $tab ]['max'][0], (int) $lines[ $tab ]['max'][1] ); ?></p>
					<div class="prrint-price-grid-wrap">
						<table class="widefat striped prrint-price-grid">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Size', 'prrint' ); ?></th>
									<th><?php esc_html_e( 'Sq. in.', 'prrint' ); ?></th>
									<?php foreach ( $products as $product ) : ?>
										<?php if ( null !== $product['supplied'] ) : ?>
											<th><?php echo esc_html( $product['label'] ); ?><br /><small><?php esc_html_e( 'mailed in', 'prrint' ); ?></small></th>
										<?php endif; ?>
										<th><?php echo esc_html( $product['label'] ); ?><br /><small><?php esc_html_e( 'printed by us', 'prrint' ); ?></small></th>
									<?php endforeach; ?>
									<?php if ( 'paper' === $tab ) : ?>
										<th><?php esc_html_e( 'Leather lamination upgrade', 'prrint' ); ?></th>
									<?php endif; ?>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $catalog['tiers'] as $t => $tier ) : ?>
									<tr>
										<th scope="row"><?php echo esc_html( $tier[0] ); ?></th>
										<td><?php echo (int) $tier[1]; ?></td>
										<?php foreach ( $products as $id => $product ) : ?>
											<?php if ( null !== $product['supplied'] ) : ?>
												<td><?php self::money_input( 'prices[' . $id . '][supplied][' . $t . ']', $product['supplied'][ $t ] ); ?></td>
											<?php endif; ?>
											<td><?php self::money_input( 'prices[' . $id . '][printed][' . $t . ']', $product['printed'][ $t ] ); ?></td>
										<?php endforeach; ?>
										<?php if ( 'paper' === $tab ) : ?>
											<td><?php self::money_input( 'leather[' . $t . ']', $catalog['leather'][ $t ] ); ?></td>
										<?php endif; ?>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
				<p class="submit">
					<?php submit_button( __( 'Save prices', 'prrint' ), 'primary', 'submit', false ); ?>
					<button type="submit" name="reset" value="1" class="button" onclick="return confirm('<?php echo esc_js( __( 'Reset this tab to the 2025 price sheet?', 'prrint' ) ); ?>');"><?php esc_html_e( 'Reset this tab to the 2025 price sheet', 'prrint' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	protected static function parse_money( $raw ) {
		$raw = trim( str_replace( array( '$', ',' ), '', (string) $raw ) );
		return '' === $raw || ! is_numeric( $raw ) ? null : round( max( 0, (float) $raw ), 2 );
	}

	/** Save one tab (one product line, or boxing). One tab per post keeps well under max_input_vars. */
	public static function handle_save() {
		check_admin_referer( 'prrint_wholesale_save' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'prrint' ) );
		}
		$line  = isset( $_POST['line'] ) ? sanitize_key( wp_unslash( $_POST['line'] ) ) : '';
		$reset = ! empty( $_POST['reset'] );
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$sheet = require PRRINT_DIR . 'includes/framer-price-sheet.php';
		$count = count( $sheet['tiers'] );

		if ( 'shipping' === $line ) {
			if ( $reset ) {
				unset( $saved['boxes'] );
			} else {
				$rules = self::default_box_rules();
				$raw   = isset( $_POST['boxes'] ) && is_array( $_POST['boxes'] ) ? wp_unslash( $_POST['boxes'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed per field.
				foreach ( $rules as $i => &$rule ) {
					$row = isset( $raw[ $i ] ) && is_array( $raw[ $i ] ) ? $raw[ $i ] : array();
					$box = self::parse_money( isset( $row['box'] ) ? $row['box'] : '' );
					$rule['box']     = null === $box ? 0.0 : $box;
					$rule['freight'] = self::parse_money( isset( $row['freight'] ) ? $row['freight'] : '' );
					$rule['per_box'] = max( 1, min( 100, isset( $row['per_box'] ) ? absint( $row['per_box'] ) : 1 ) );
				}
				unset( $rule );
				$saved['boxes'] = $rules;
			}
		} elseif ( isset( self::lines()[ $line ] ) ) {
			$raw = isset( $_POST['prices'] ) && is_array( $_POST['prices'] ) ? wp_unslash( $_POST['prices'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed per cell.
			foreach ( $sheet['products'] as $id => $product ) {
				if ( $product['line'] !== $line ) {
					continue;
				}
				foreach ( array( 'supplied', 'printed' ) as $kind ) {
					if ( null === $product[ $kind ] ) {
						continue;
					}
					if ( $reset ) {
						unset( $saved['prices'][ $id ][ $kind ] );
						continue;
					}
					$cells = array();
					for ( $t = 0; $t < $count; $t++ ) {
						$cells[] = self::parse_money( isset( $raw[ $id ][ $kind ][ $t ] ) ? $raw[ $id ][ $kind ][ $t ] : '' );
					}
					$saved['prices'][ $id ][ $kind ] = $cells;
				}
			}
			if ( 'paper' === $line ) {
				if ( $reset ) {
					unset( $saved['leather'] );
				} else {
					$raw_leather = isset( $_POST['leather'] ) && is_array( $_POST['leather'] ) ? wp_unslash( $_POST['leather'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$cells       = array();
					for ( $t = 0; $t < $count; $t++ ) {
						$cells[] = self::parse_money( isset( $raw_leather[ $t ] ) ? $raw_leather[ $t ] : '' );
					}
					$saved['leather'] = $cells;
				}
			}
		}

		update_option( self::OPTION, $saved, false );
		self::flush_cache();
		wp_safe_redirect( admin_url( 'admin.php?page=prrint-wholesale&line=' . rawurlencode( $line ) . '&saved=1' ) );
		exit;
	}

	/* --------------------------------------------------------------------
	 * Front end: [prrint_framer_price_list] — approved framers only.
	 * ------------------------------------------------------------------ */

	public static function price_list_shortcode() {
		if ( ! prrint_is_approved_framer() ) {
			return '';
		}
		ob_start();
		self::render_price_list();
		return Prrint_Framers::wrap( ob_get_clean() );
	}

	/**
	 * Price list as one collapsible toggle per product line (closed by
	 * default), plus boxing & freight. Wood shows both prices per product:
	 * framer mails the print in / ColorPlak prints it.
	 */
	public static function render_price_list() {
		$catalog = self::catalog();
		echo '<div class="pf-accordion">';
		foreach ( self::lines() as $key => $line ) {
			$products = array_filter( $catalog['products'], function ( $p ) use ( $key ) {
				return $p['line'] === $key;
			} );
			$min = null;
			foreach ( $products as $product ) {
				foreach ( array( 'supplied', 'printed' ) as $kind ) {
					foreach ( (array) $product[ $kind ] as $v ) {
						if ( null !== $v && ( null === $min || $v < $min ) ) {
							$min = (float) $v;
						}
					}
				}
			}
			$has_mail = (bool) array_filter( $products, function ( $p ) {
				return null !== $p['supplied'];
			} );
			?>
			<details class="pf-acc">
				<summary>
					<span class="pf-acc-title"><?php echo esc_html( $line['label'] ); ?></span>
					<span class="pf-acc-meta">
						<?php echo esc_html( implode( ' · ', array_map( function ( $p ) { return $p['label']; }, $products ) ) ); ?>
					</span>
					<span class="pf-acc-from">
						<?php
						/* translators: 1: max short side, 2: max long side */
						echo esc_html( sprintf( __( 'up to %1$s" x %2$s"', 'prrint' ), $line['max'][0], $line['max'][1] ) );
						if ( null !== $min ) {
							echo ' · ' . wp_kses_post( sprintf( /* translators: %s: lowest price */ __( 'from %s', 'prrint' ), wc_price( $min ) ) );
						}
						?>
					</span>
				</summary>
				<div class="pf-acc-body">
					<?php if ( $has_mail ) : ?>
						<p class="pf-small"><?php esc_html_e( '"Mail in" = you send us the print; "We print" = we print it from your digital file.', 'prrint' ); ?></p>
					<?php endif; ?>
					<div class="pf-table-wrap">
						<table class="pf-table pf-price-table">
							<thead>
								<tr>
									<th rowspan="<?php echo $has_mail ? 2 : 1; ?>"><?php esc_html_e( 'Size', 'prrint' ); ?></th>
									<?php foreach ( $products as $product ) : ?>
										<th colspan="<?php echo null !== $product['supplied'] ? 2 : 1; ?>" rowspan="<?php echo $has_mail && null === $product['supplied'] ? 2 : 1; ?>"><?php echo esc_html( $product['label'] ); ?></th>
									<?php endforeach; ?>
								</tr>
								<?php if ( $has_mail ) : ?>
									<tr class="pf-subhead">
										<?php foreach ( $products as $product ) : ?>
											<?php if ( null !== $product['supplied'] ) : ?>
												<th><?php esc_html_e( 'Mail in', 'prrint' ); ?></th>
												<th><?php esc_html_e( 'We print', 'prrint' ); ?></th>
											<?php endif; ?>
										<?php endforeach; ?>
									</tr>
								<?php endif; ?>
							</thead>
							<tbody>
								<?php foreach ( $catalog['tiers'] as $t => $tier ) : ?>
									<?php
									$any = false;
									foreach ( $products as $product ) {
										if ( null !== $product['printed'][ $t ] || ( null !== $product['supplied'] && null !== $product['supplied'][ $t ] ) ) {
											$any = true;
										}
									}
									if ( ! $any ) {
										continue;
									}
									?>
									<tr>
										<th scope="row"><?php echo esc_html( $tier[0] ); ?></th>
										<?php foreach ( $products as $product ) : ?>
											<?php foreach ( null !== $product['supplied'] ? array( 'supplied', 'printed' ) : array( 'printed' ) as $kind ) : ?>
												<?php $v = $product[ $kind ][ $t ]; ?>
												<td><?php echo null === $v ? '<span class="pf-na">—</span>' : wp_kses_post( wc_price( $v ) ); ?></td>
											<?php endforeach; ?>
										<?php endforeach; ?>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
			</details>
			<?php
		}
		$rules = $catalog['boxes'];
		$prev  = 0;
		?>
		<details class="pf-acc">
			<summary>
				<span class="pf-acc-title"><?php esc_html_e( 'Boxing & freight', 'prrint' ); ?></span>
				<span class="pf-acc-meta"><?php esc_html_e( 'Charged per box, added to every order', 'prrint' ); ?></span>
			</summary>
			<div class="pf-acc-body">
				<div class="pf-table-wrap">
					<table class="pf-table">
						<thead><tr>
							<th><?php esc_html_e( 'Piece size (sq. in.)', 'prrint' ); ?></th>
							<th><?php esc_html_e( 'Pieces per box', 'prrint' ); ?></th>
							<th><?php esc_html_e( 'Box', 'prrint' ); ?></th>
							<th><?php esc_html_e( 'Freight per box', 'prrint' ); ?></th>
						</tr></thead>
						<tbody>
							<?php foreach ( $rules as $rule ) : ?>
								<tr>
									<th scope="row">
										<?php
										echo esc_html( $rule['max_sqin'] >= 999999 ? sprintf( /* translators: %d: sq in */ __( 'Over %d (oversized)', 'prrint' ), $prev ) : sprintf( '%d – %d', $prev ? $prev + 1 : 1, $rule['max_sqin'] ) );
										$prev = (int) $rule['max_sqin'];
										?>
									</th>
									<td><?php echo (int) $rule['per_box']; ?></td>
									<td><?php echo wp_kses_post( wc_price( $rule['box'] ) ); ?></td>
									<td><?php echo null === $rule['freight'] ? esc_html__( 'Call for quote', 'prrint' ) : wp_kses_post( wc_price( $rule['freight'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<p class="pf-small"><?php esc_html_e( 'Rush orders: +50% of the order total. Mat / colored border: +33% of the piece price. Recut $5, trim $2 per piece.', 'prrint' ); ?></p>
			</div>
		</details>
		<?php
		echo '</div>';
	}
}
