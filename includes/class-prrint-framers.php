<?php
/**
 * Framer (trade) accounts: application, admin approval, and the
 * [prrint_framers_corner] shortcode that only shows its content to
 * approved framers.
 *
 * A framer is a normal WooCommerce customer account carrying a
 * `_prrint_framer_status` user meta of pending / approved / rejected —
 * no custom role, so it never interferes with WooCommerce's own roles.
 *
 * @package prrint
 */

defined( 'ABSPATH' ) || exit;

class Prrint_Framers {

	const STATUS_META = '_prrint_framer_status';
	const NONCE       = 'prrint_framer_apply';

	/** Errors/values from a failed submission, shown on the same request. */
	protected static $form_errors = array();
	protected static $form_values = array();

	public static function init() {
		add_shortcode( 'prrint_framers_corner', array( __CLASS__, 'shortcode' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_application' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
		add_action( 'woocommerce_account_dashboard', array( __CLASS__, 'account_dashboard_notice' ) );

		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_create_page' ) );
		add_action( 'admin_post_prrint_framer_decide', array( __CLASS__, 'handle_decision' ) );
		add_action( 'show_user_profile', array( __CLASS__, 'profile_fields' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'profile_fields' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_profile_fields' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile_fields' ) );
		add_filter( 'manage_users_columns', array( __CLASS__, 'users_column' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'users_column_value' ), 10, 3 );
	}

	/* --------------------------------------------------------------------
	 * Status helpers.
	 * ------------------------------------------------------------------ */

	/** @return string '' (not a framer), 'pending', 'approved' or 'rejected'. */
	public static function status( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id ) {
			return '';
		}
		$status = (string) get_user_meta( $user_id, self::STATUS_META, true );
		return in_array( $status, array( 'pending', 'approved', 'rejected' ), true ) ? $status : '';
	}

	public static function is_approved( $user_id = 0 ) {
		return 'approved' === self::status( $user_id );
	}

	/** Business fields collected on the application: key => label. */
	public static function fields() {
		return array(
			'business' => __( 'Business name', 'prrint' ),
			'contact'  => __( 'Contact name', 'prrint' ),
			'phone'    => __( 'Phone', 'prrint' ),
			'address'  => __( 'Business address', 'prrint' ),
			'website'  => __( 'Website', 'prrint' ),
			'resale'   => __( "Seller's permit / resale number", 'prrint' ),
		);
	}

	protected static function optional_fields() {
		return array( 'website', 'resale' );
	}

	public static function get_details( $user_id ) {
		$out = array();
		foreach ( array_keys( self::fields() ) as $key ) {
			$out[ $key ] = (string) get_user_meta( $user_id, '_prrint_framer_' . $key, true );
		}
		return $out;
	}

	public static function pending_count() {
		$users = get_users( array(
			'meta_key'   => self::STATUS_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value' => 'pending', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'fields'     => 'ID',
		) );
		return count( $users );
	}

	public static function page_url() {
		$page_id = (int) get_option( 'prrint_framers_page' );
		return $page_id && 'publish' === get_post_status( $page_id ) ? get_permalink( $page_id ) : home_url( '/' );
	}

	/* --------------------------------------------------------------------
	 * Front end: the Framers Corner shortcode.
	 * ------------------------------------------------------------------ */

	public static function maybe_enqueue() {
		$post = get_post();
		if ( $post && has_shortcode( (string) $post->post_content, 'prrint_framers_corner' ) ) {
			self::enqueue();
		}
	}

	protected static function enqueue() {
		wp_enqueue_style( 'prrint-framers', PRRINT_URL . 'assets/css/framers.css', array(), PRRINT_VERSION );
	}

	/**
	 * [prrint_framers_corner]…content…[/prrint_framers_corner]
	 * Approved framers see the enclosed content; everyone else sees the
	 * login + application form, or their application's status.
	 */
	public static function shortcode( $atts, $content = '' ) {
		self::enqueue();
		$status = self::status();

		ob_start();
		echo '<div class="prrint-framers">';

		if ( 'approved' === $status ) {
			echo do_shortcode( shortcode_unautop( (string) $content ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- page content written by the site owner.
		} elseif ( 'pending' === $status ) {
			self::notice(
				__( 'Your framer application is being reviewed', 'prrint' ),
				__( "Thanks for applying! We review every framer account by hand and will email you as soon as yours is approved. You'll then see wholesale pricing and ordering here.", 'prrint' )
			);
		} elseif ( 'rejected' === $status ) {
			self::notice(
				__( 'Your framer account is not active', 'prrint' ),
				__( "We weren't able to approve a framer account for you at this time. If you think this is a mistake, please contact us.", 'prrint' ),
				'is-muted'
			);
		} elseif ( is_user_logged_in() ) {
			echo '<h2>' . esc_html__( 'Apply for a framer account', 'prrint' ) . '</h2>';
			echo '<p>' . esc_html__( 'Tell us about your business. Once we approve your account you will see wholesale pricing and ordering here.', 'prrint' ) . '</p>';
			self::application_form( false );
		} else {
			echo '<div class="prrint-framers-grid">';
			echo '<section class="prrint-framers-panel"><h2>' . esc_html__( 'Framer login', 'prrint' ) . '</h2>';
			echo '<p>' . esc_html__( 'Already have an approved framer account? Log in to see wholesale pricing.', 'prrint' ) . '</p>';
			wp_login_form( array( 'redirect' => self::current_url() ) );
			echo '</section>';
			echo '<section class="prrint-framers-panel"><h2>' . esc_html__( 'Apply for a framer account', 'prrint' ) . '</h2>';
			echo '<p>' . esc_html__( 'New to ColorPlak? Apply below — we review every application and email you once your account is approved.', 'prrint' ) . '</p>';
			self::application_form( true );
			echo '</section></div>';
		}

		echo '</div>';
		return ob_get_clean();
	}

	protected static function notice( $title, $text, $class = '' ) {
		printf(
			'<div class="prrint-framers-notice %1$s"><strong>%2$s</strong><p>%3$s</p></div>',
			esc_attr( $class ),
			esc_html( $title ),
			esc_html( $text )
		);
	}

	protected static function current_url() {
		$post = get_post();
		return $post ? get_permalink( $post ) : home_url( '/' );
	}

	protected static function application_form( $with_account ) {
		$v = self::$form_values;
		if ( isset( $_GET['prrint_applied'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( self::$form_errors ) {
			echo '<div class="prrint-framers-notice is-error"><ul>';
			foreach ( self::$form_errors as $error ) {
				echo '<li>' . esc_html( $error ) . '</li>';
			}
			echo '</ul></div>';
		}
		?>
		<form method="post" class="prrint-framers-form" novalidate>
			<?php wp_nonce_field( self::NONCE, 'prrint_framer_nonce' ); ?>
			<input type="hidden" name="prrint_framer_apply" value="1" />
			<p class="prrint-hp" aria-hidden="true"><label>Leave this empty <input type="text" name="prrint_hp" value="" tabindex="-1" autocomplete="off" /></label></p>
			<?php foreach ( self::fields() as $key => $label ) : ?>
				<p class="form-row">
					<label for="prrint_framer_<?php echo esc_attr( $key ); ?>">
						<?php echo esc_html( $label ); ?>
						<?php if ( ! in_array( $key, self::optional_fields(), true ) ) : ?><span class="required" aria-hidden="true">*</span><?php else : ?><span class="optional"><?php esc_html_e( '(optional)', 'prrint' ); ?></span><?php endif; ?>
					</label>
					<?php if ( 'address' === $key ) : ?>
						<textarea class="input-text" rows="3" id="prrint_framer_address" name="prrint_framer[address]"><?php echo esc_textarea( isset( $v['address'] ) ? $v['address'] : '' ); ?></textarea>
					<?php else : ?>
						<input class="input-text" type="<?php echo 'phone' === $key ? 'tel' : ( 'website' === $key ? 'url' : 'text' ); ?>" id="prrint_framer_<?php echo esc_attr( $key ); ?>" name="prrint_framer[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( isset( $v[ $key ] ) ? $v[ $key ] : '' ); ?>" />
					<?php endif; ?>
				</p>
			<?php endforeach; ?>
			<?php if ( $with_account ) : ?>
				<p class="form-row">
					<label for="prrint_framer_email"><?php esc_html_e( 'Email', 'prrint' ); ?> <span class="required" aria-hidden="true">*</span></label>
					<input class="input-text" type="email" id="prrint_framer_email" name="prrint_framer[email]" autocomplete="email" value="<?php echo esc_attr( isset( $v['email'] ) ? $v['email'] : '' ); ?>" />
				</p>
				<p class="form-row">
					<label for="prrint_framer_password"><?php esc_html_e( 'Password', 'prrint' ); ?> <span class="required" aria-hidden="true">*</span></label>
					<input class="input-text" type="password" id="prrint_framer_password" name="prrint_framer_password" autocomplete="new-password" minlength="8" />
				</p>
				<p class="form-row">
					<label for="prrint_framer_password2"><?php esc_html_e( 'Confirm password', 'prrint' ); ?> <span class="required" aria-hidden="true">*</span></label>
					<input class="input-text" type="password" id="prrint_framer_password2" name="prrint_framer_password2" autocomplete="new-password" />
				</p>
			<?php endif; ?>
			<p><button type="submit" class="button"><?php esc_html_e( 'Submit application', 'prrint' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * Process an application before any output, so a new account can be
	 * logged in and the page redirected (post/redirect/get).
	 */
	public static function handle_application() {
		if ( empty( $_POST['prrint_framer_apply'] ) ) {
			return;
		}
		if ( ! isset( $_POST['prrint_framer_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['prrint_framer_nonce'] ) ), self::NONCE ) ) {
			self::$form_errors[] = __( 'Your session expired. Please submit the form again.', 'prrint' );
			return;
		}
		// Honeypot: real visitors never see or fill this field.
		if ( ! empty( $_POST['prrint_hp'] ) ) {
			return;
		}

		$raw    = isset( $_POST['prrint_framer'] ) && is_array( $_POST['prrint_framer'] ) ? wp_unslash( $_POST['prrint_framer'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field below.
		$values = array();
		foreach ( array_keys( self::fields() ) as $key ) {
			$value          = isset( $raw[ $key ] ) ? (string) $raw[ $key ] : '';
			$values[ $key ] = 'address' === $key ? sanitize_textarea_field( $value ) : ( 'website' === $key ? esc_url_raw( $value ) : sanitize_text_field( $value ) );
		}
		$values['email']     = isset( $raw['email'] ) ? sanitize_email( (string) $raw['email'] ) : '';
		self::$form_values = $values;

		foreach ( self::fields() as $key => $label ) {
			if ( '' === $values[ $key ] && ! in_array( $key, self::optional_fields(), true ) ) {
				/* translators: %s: field label */
				self::$form_errors[] = sprintf( __( '%s is required.', 'prrint' ), $label );
			}
		}

		$user_id = get_current_user_id();
		if ( $user_id && '' !== self::status( $user_id ) ) {
			return; // Already applied; the shortcode shows their status.
		}

		$password = '';
		if ( ! $user_id ) {
			$password  = isset( $_POST['prrint_framer_password'] ) ? (string) wp_unslash( $_POST['prrint_framer_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passwords are not sanitized.
			$password2 = isset( $_POST['prrint_framer_password2'] ) ? (string) wp_unslash( $_POST['prrint_framer_password2'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( ! is_email( $values['email'] ) ) {
				self::$form_errors[] = __( 'Please enter a valid email address.', 'prrint' );
			} elseif ( email_exists( $values['email'] ) ) {
				self::$form_errors[] = __( 'An account with this email already exists. Please log in first, then apply.', 'prrint' );
			}
			if ( strlen( $password ) < 8 ) {
				self::$form_errors[] = __( 'Please choose a password of at least 8 characters.', 'prrint' );
			} elseif ( $password !== $password2 ) {
				self::$form_errors[] = __( 'The two passwords do not match.', 'prrint' );
			}
		}

		if ( self::$form_errors ) {
			return;
		}

		if ( ! $user_id ) {
			$user_id = wc_create_new_customer( $values['email'], '', $password );
			if ( is_wp_error( $user_id ) ) {
				self::$form_errors[] = wp_strip_all_tags( $user_id->get_error_message() );
				return;
			}
			wc_set_customer_auth_cookie( $user_id );
		}

		foreach ( array_keys( self::fields() ) as $key ) {
			update_user_meta( $user_id, '_prrint_framer_' . $key, $values[ $key ] );
		}
		update_user_meta( $user_id, '_prrint_framer_applied', time() );
		update_user_meta( $user_id, self::STATUS_META, 'pending' );

		self::email_admin_new_application( $user_id );
		self::email_framer( $user_id, 'received' );

		wp_safe_redirect( add_query_arg( 'prrint_applied', '1', self::current_url() ) );
		exit;
	}

	public static function account_dashboard_notice() {
		$status = self::status();
		if ( '' === $status ) {
			return;
		}
		$labels = array(
			'pending'  => __( 'Your framer application is being reviewed.', 'prrint' ),
			'approved' => __( 'Your framer account is approved.', 'prrint' ),
			'rejected' => __( 'Your framer account is not active.', 'prrint' ),
		);
		printf(
			'<p class="prrint-framer-status">%1$s <a href="%2$s">%3$s</a></p>',
			esc_html( $labels[ $status ] ),
			esc_url( self::page_url() ),
			esc_html__( 'Go to the Framers Corner', 'prrint' )
		);
	}

	/* --------------------------------------------------------------------
	 * Emails.
	 * ------------------------------------------------------------------ */

	protected static function notify_email() {
		$email = isset( prrint_settings()['framer_notify_email'] ) ? sanitize_email( prrint_settings()['framer_notify_email'] ) : '';
		return is_email( $email ) ? $email : get_option( 'admin_email' );
	}

	protected static function email_admin_new_application( $user_id ) {
		$user    = get_userdata( $user_id );
		$details = self::get_details( $user_id );
		$lines   = array( __( 'A new framer account application is waiting for your approval.', 'prrint' ), '' );
		foreach ( self::fields() as $key => $label ) {
			$lines[] = $label . ': ' . ( '' !== $details[ $key ] ? $details[ $key ] : '—' );
		}
		$lines[] = __( 'Email', 'prrint' ) . ': ' . ( $user ? $user->user_email : '' );
		$lines[] = '';
		$lines[] = __( 'Review it here:', 'prrint' ) . ' ' . admin_url( 'admin.php?page=prrint-framers' );

		wp_mail(
			self::notify_email(),
			/* translators: %s: business name */
			sprintf( __( 'New framer application: %s', 'prrint' ), $details['business'] ),
			implode( "\n", $lines )
		);
	}

	/**
	 * @param string $type 'received' | 'approved' | 'rejected'.
	 */
	protected static function email_framer( $user_id, $type ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$name    = self::get_details( $user_id )['contact'];
		$hello   = $name ? sprintf( /* translators: %s: name */ __( 'Hi %s,', 'prrint' ), $name ) : __( 'Hello,', 'prrint' );
		$subject = '';
		$body    = '';
		if ( 'received' === $type ) {
			/* translators: %s: site name */
			$subject = sprintf( __( 'We received your framer application — %s', 'prrint' ), $site );
			$body    = __( 'Thanks for applying for a framer account. We review every application by hand and will email you as soon as yours is approved.', 'prrint' );
		} elseif ( 'approved' === $type ) {
			/* translators: %s: site name */
			$subject = sprintf( __( 'Your framer account is approved — %s', 'prrint' ), $site );
			$body    = __( 'Good news — your framer account has been approved. Log in to the Framers Corner to see wholesale pricing and place orders:', 'prrint' ) . "\n" . self::page_url();
		} elseif ( 'rejected' === $type ) {
			/* translators: %s: site name */
			$subject = sprintf( __( 'Your framer application — %s', 'prrint' ), $site );
			$body    = __( "Thank you for your interest. We weren't able to approve a framer account for you at this time. Please reply to this email if you have any questions.", 'prrint' );
		}
		wp_mail( $user->user_email, $subject, $hello . "\n\n" . $body . "\n\n" . $site );
	}

	/* --------------------------------------------------------------------
	 * Admin: WooCommerce → Framers.
	 * ------------------------------------------------------------------ */

	public static function admin_menu() {
		$pending = self::pending_count();
		$title   = __( 'Framers', 'prrint' );
		if ( $pending ) {
			$title .= ' <span class="awaiting-mod">' . (int) $pending . '</span>';
		}
		add_submenu_page( 'woocommerce', __( 'Framers', 'prrint' ), $title, 'manage_woocommerce', 'prrint-framers', array( __CLASS__, 'render_admin_page' ) );
	}

	/**
	 * Draft "Framers Corner" page, created once — also on sites that were
	 * already running the plugin, where activation won't run again.
	 */
	/** Placeholder content shipped in 1.30, before the order form existed. */
	protected static function legacy_page_content() {
		return "[prrint_framers_corner]\n<h2>" . __( 'Welcome to the Framers Corner', 'prrint' ) . '</h2>' . "\n<p>" . __( 'Wholesale pricing, order forms and your orders will appear here.', 'prrint' ) . "</p>\n[/prrint_framers_corner]";
	}

	public static function default_page_content() {
		return "[prrint_framers_corner]\n<h2>" . __( 'Place a wholesale order', 'prrint' ) . "</h2>\n[prrint_framer_order_form]\n\n<h2>" . __( 'Wholesale price list', 'prrint' ) . "</h2>\n[prrint_framer_price_list]\n[/prrint_framers_corner]";
	}

	public static function maybe_create_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$existing = (int) get_option( 'prrint_framers_page' );
		if ( $existing ) {
			// Upgrade the untouched 1.30 placeholder to include the order form.
			$post = get_post( $existing );
			if ( $post && trim( $post->post_content ) === self::legacy_page_content() ) {
				wp_update_post( array( 'ID' => $existing, 'post_content' => self::default_page_content() ) );
			}
			return;
		}
		$page_id = wp_insert_post( array(
			'post_title'   => __( 'Framers Corner', 'prrint' ),
			'post_name'    => 'framers-corner',
			'post_content' => self::default_page_content(),
			'post_status'  => 'draft',
			'post_type'    => 'page',
		) );
		if ( $page_id && ! is_wp_error( $page_id ) ) {
			update_option( 'prrint_framers_page', $page_id );
		}
	}

	public static function render_admin_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$tabs    = array(
			'pending'  => __( 'Pending', 'prrint' ),
			'approved' => __( 'Approved', 'prrint' ),
			'rejected' => __( 'Not approved', 'prrint' ),
		);
		$requested = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current   = isset( $tabs[ $requested ] ) ? $requested : 'pending';
		$users   = get_users( array(
			'meta_key'   => self::STATUS_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value' => $current, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'orderby'    => 'registered',
			'order'      => 'DESC',
		) );
		$page_id = (int) get_option( 'prrint_framers_page' );
		?>
		<div class="wrap prrint-admin-wrap">
			<h1><?php esc_html_e( 'Framer accounts', 'prrint' ); ?></h1>
			<p>
				<?php esc_html_e( 'Framers apply on the Framers Corner page. Approve an application to give that framer access to the content inside the [prrint_framers_corner] shortcode; they are emailed automatically either way.', 'prrint' ); ?>
				<?php if ( $page_id ) : ?>
					<a href="<?php echo esc_url( get_edit_post_link( $page_id ) ); ?>"><?php esc_html_e( 'Edit the Framers Corner page', 'prrint' ); ?></a>
					<?php if ( 'publish' !== get_post_status( $page_id ) ) : ?>
						<strong>(<?php esc_html_e( 'draft — publish it when ready', 'prrint' ); ?>)</strong>
					<?php endif; ?>
				<?php endif; ?>
			</p>
			<?php if ( isset( $_GET['prrint_done'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Framer account updated and the framer was emailed.', 'prrint' ); ?></p></div>
			<?php endif; ?>
			<ul class="subsubsub">
				<?php
				$links = array();
				foreach ( $tabs as $key => $label ) {
					$count   = count( get_users( array( 'meta_key' => self::STATUS_META, 'meta_value' => $key, 'fields' => 'ID' ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
					$links[] = sprintf(
						'<li><a href="%1$s" class="%2$s">%3$s <span class="count">(%4$d)</span></a></li>',
						esc_url( admin_url( 'admin.php?page=prrint-framers&status=' . $key ) ),
						$key === $current ? 'current' : '',
						esc_html( $label ),
						(int) $count
					);
				}
				echo implode( ' | ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
				?>
			</ul>
			<table class="widefat striped prrint-framers-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Business', 'prrint' ); ?></th>
						<th><?php esc_html_e( 'Contact', 'prrint' ); ?></th>
						<th><?php esc_html_e( 'Address', 'prrint' ); ?></th>
						<th><?php esc_html_e( 'Resale #', 'prrint' ); ?></th>
						<th><?php esc_html_e( 'Applied', 'prrint' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'prrint' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $users ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No framers here yet.', 'prrint' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $users as $user ) : ?>
						<?php $d = self::get_details( $user->ID ); ?>
						<?php $applied = (int) get_user_meta( $user->ID, '_prrint_framer_applied', true ); ?>
						<tr>
							<td>
								<strong><a href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>"><?php echo esc_html( '' !== $d['business'] ? $d['business'] : $user->display_name ); ?></a></strong>
								<?php if ( '' !== $d['website'] ) : ?><br /><a href="<?php echo esc_url( $d['website'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $d['website'] ); ?></a><?php endif; ?>
							</td>
							<td>
								<?php echo esc_html( $d['contact'] ); ?><br />
								<a href="mailto:<?php echo esc_attr( $user->user_email ); ?>"><?php echo esc_html( $user->user_email ); ?></a><br />
								<?php echo esc_html( $d['phone'] ); ?>
							</td>
							<td><?php echo nl2br( esc_html( $d['address'] ) ); ?></td>
							<td><?php echo esc_html( '' !== $d['resale'] ? $d['resale'] : '—' ); ?></td>
							<td><?php echo esc_html( $applied ? wp_date( get_option( 'date_format' ), $applied ) : '—' ); ?></td>
							<td class="prrint-framer-actions">
								<?php if ( 'approved' !== $current ) : ?>
									<a class="button button-primary" href="<?php echo esc_url( self::decision_url( $user->ID, 'approve' ) ); ?>"><?php esc_html_e( 'Approve', 'prrint' ); ?></a>
								<?php endif; ?>
								<?php if ( 'rejected' !== $current ) : ?>
									<a class="button" href="<?php echo esc_url( self::decision_url( $user->ID, 'reject' ) ); ?>" onclick="return confirm('<?php echo esc_js( 'approved' === $current ? __( 'Remove this framer\'s access?', 'prrint' ) : __( 'Decline this application?', 'prrint' ) ); ?>');"><?php echo esc_html( 'approved' === $current ? __( 'Remove access', 'prrint' ) : __( 'Decline', 'prrint' ) ); ?></a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	protected static function decision_url( $user_id, $decision ) {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=prrint_framer_decide&user=' . (int) $user_id . '&decision=' . $decision ),
			'prrint_framer_decide_' . (int) $user_id
		);
	}

	public static function handle_decision() {
		$user_id = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0;
		check_admin_referer( 'prrint_framer_decide_' . $user_id );
		if ( ! current_user_can( 'manage_woocommerce' ) || ! $user_id || '' === self::status( $user_id ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'prrint' ) );
		}
		$decision = isset( $_GET['decision'] ) && 'approve' === $_GET['decision'] ? 'approved' : 'rejected';
		self::set_status( $user_id, $decision );
		wp_safe_redirect( admin_url( 'admin.php?page=prrint-framers&status=' . $decision . '&prrint_done=1' ) );
		exit;
	}

	/** Change a framer's status and email them when it actually changes. */
	public static function set_status( $user_id, $status ) {
		$old = self::status( $user_id );
		if ( $old === $status ) {
			return;
		}
		update_user_meta( $user_id, self::STATUS_META, $status );
		update_user_meta( $user_id, '_prrint_framer_decided', time() );
		if ( in_array( $status, array( 'approved', 'rejected' ), true ) ) {
			self::email_framer( $user_id, $status );
		}
	}

	/* --------------------------------------------------------------------
	 * Admin: user profile + Users list column.
	 * ------------------------------------------------------------------ */

	public static function profile_fields( $user ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$status  = self::status( $user->ID );
		$details = self::get_details( $user->ID );
		?>
		<h2><?php esc_html_e( 'Framer account', 'prrint' ); ?></h2>
		<?php wp_nonce_field( 'prrint_framer_profile', 'prrint_framer_profile_nonce' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="prrint_framer_status"><?php esc_html_e( 'Status', 'prrint' ); ?></label></th>
				<td>
					<select id="prrint_framer_status" name="prrint_framer_status">
						<option value="" <?php selected( $status, '' ); ?>><?php esc_html_e( 'Not a framer', 'prrint' ); ?></option>
						<option value="pending" <?php selected( $status, 'pending' ); ?>><?php esc_html_e( 'Pending approval', 'prrint' ); ?></option>
						<option value="approved" <?php selected( $status, 'approved' ); ?>><?php esc_html_e( 'Approved', 'prrint' ); ?></option>
						<option value="rejected" <?php selected( $status, 'rejected' ); ?>><?php esc_html_e( 'Not approved', 'prrint' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Changing to Approved or Not approved emails the framer.', 'prrint' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="prrint_framer_discount"><?php esc_html_e( 'Wholesale discount (%)', 'prrint' ); ?></label></th>
				<td>
					<input type="number" min="0" max="100" step="0.5" class="small-text" id="prrint_framer_discount" name="prrint_framer_discount" value="<?php echo esc_attr( (string) get_user_meta( $user->ID, '_prrint_framer_discount', true ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Optional extra discount for this framer, taken off the Plak total of every order.', 'prrint' ); ?></p>
				</td>
			</tr>
			<?php foreach ( self::fields() as $key => $label ) : ?>
				<tr>
					<th><label for="prrint_framer_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
					<td>
						<?php if ( 'address' === $key ) : ?>
							<textarea id="prrint_framer_address" name="prrint_framer[address]" rows="3" class="regular-text"><?php echo esc_textarea( $details['address'] ); ?></textarea>
						<?php else : ?>
							<input type="text" class="regular-text" id="prrint_framer_<?php echo esc_attr( $key ); ?>" name="prrint_framer[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $details[ $key ] ); ?>" />
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php
	}

	public static function save_profile_fields( $user_id ) {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		if ( ! isset( $_POST['prrint_framer_profile_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['prrint_framer_profile_nonce'] ) ), 'prrint_framer_profile' ) ) {
			return;
		}
		$raw = isset( $_POST['prrint_framer'] ) && is_array( $_POST['prrint_framer'] ) ? wp_unslash( $_POST['prrint_framer'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field below.
		foreach ( array_keys( self::fields() ) as $key ) {
			$value = isset( $raw[ $key ] ) ? (string) $raw[ $key ] : '';
			update_user_meta( $user_id, '_prrint_framer_' . $key, 'address' === $key ? sanitize_textarea_field( $value ) : ( 'website' === $key ? esc_url_raw( $value ) : sanitize_text_field( $value ) ) );
		}
		$discount = isset( $_POST['prrint_framer_discount'] ) ? (float) wp_unslash( $_POST['prrint_framer_discount'] ) : 0;
		update_user_meta( $user_id, '_prrint_framer_discount', max( 0, min( 100, $discount ) ) );
		$status = isset( $_POST['prrint_framer_status'] ) ? sanitize_key( wp_unslash( $_POST['prrint_framer_status'] ) ) : '';
		if ( '' === $status ) {
			delete_user_meta( $user_id, self::STATUS_META );
		} elseif ( in_array( $status, array( 'pending', 'approved', 'rejected' ), true ) ) {
			self::set_status( $user_id, $status );
		}
	}

	public static function users_column( $columns ) {
		$columns['prrint_framer'] = __( 'Framer', 'prrint' );
		return $columns;
	}

	public static function users_column_value( $value, $column, $user_id ) {
		if ( 'prrint_framer' !== $column ) {
			return $value;
		}
		$labels = array(
			'pending'  => __( 'Pending', 'prrint' ),
			'approved' => __( 'Approved', 'prrint' ),
			'rejected' => __( 'Not approved', 'prrint' ),
		);
		$status = self::status( $user_id );
		return $status ? esc_html( $labels[ $status ] ) : '—';
	}
}

/** Template helper: is the current (or given) user an approved framer? */
function prrint_is_approved_framer( $user_id = 0 ) {
	return Prrint_Framers::is_approved( $user_id );
}
