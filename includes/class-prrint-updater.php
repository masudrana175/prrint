<?php
/**
 * Updates from GitHub.
 *
 * Makes new Prrint versions show up in Dashboard → Updates (and on the
 * Plugins screen) like any wordpress.org plugin, with "Update now" and the
 * auto-update toggle. The latest version is read from the Version header of
 * prrint.php on the repository's main branch; the package is that branch's
 * zip. GitHub names the zip's folder "prrint-main", so after unpacking it is
 * renamed to the folder the plugin is installed in.
 *
 * For a private repository, define PRRINT_GITHUB_TOKEN (a read-only token)
 * in wp-config.php; it is sent to GitHub only.
 *
 * @package prrint
 */

defined( 'ABSPATH' ) || exit;

class Prrint_Updater {

	const CACHE  = 'prrint_update_info';
	const ACTION = 'prrint_check_updates';

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder' ), 10, 4 );
		add_filter( 'http_request_args', array( __CLASS__, 'auth_header' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ) );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_check' ) );
		add_action( 'admin_notices', array( __CLASS__, 'checked_notice' ) );
	}

	/** "owner/name" of the GitHub repository. */
	public static function repo() {
		return (string) apply_filters( 'prrint_update_repo', 'masudrana175/prrint' );
	}

	public static function branch() {
		return (string) apply_filters( 'prrint_update_branch', 'main' );
	}

	public static function basename() {
		return plugin_basename( PRRINT_FILE );
	}

	protected static function token() {
		return defined( 'PRRINT_GITHUB_TOKEN' ) ? (string) PRRINT_GITHUB_TOKEN : '';
	}

	/** Where the package is downloaded from. */
	public static function package_url() {
		if ( self::token() ) {
			return 'https://api.github.com/repos/' . self::repo() . '/zipball/' . rawurlencode( self::branch() );
		}
		return 'https://github.com/' . self::repo() . '/archive/refs/heads/' . rawurlencode( self::branch() ) . '.zip';
	}

	/**
	 * Raw contents of a file on the branch, or '' on failure.
	 */
	protected static function fetch_file( $path ) {
		if ( self::token() ) {
			$url  = 'https://api.github.com/repos/' . self::repo() . '/contents/' . $path . '?ref=' . rawurlencode( self::branch() );
			$args = array( 'headers' => array( 'Accept' => 'application/vnd.github.raw' ) );
		} else {
			$url  = 'https://raw.githubusercontent.com/' . self::repo() . '/' . rawurlencode( self::branch() ) . '/' . $path;
			$args = array();
		}
		$response = wp_remote_get( $url, array_merge( array( 'timeout' => 10 ), $args ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}
		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * Latest release info, cached for 6 hours (a failed lookup for 1 hour so
	 * GitHub isn't asked on every admin page while it's unreachable).
	 *
	 * @return array|null { version, requires_php, requires, tested, changelog }
	 */
	public static function remote_info( $force = false ) {
		$cached = $force ? false : get_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return empty( $cached['version'] ) ? null : $cached;
		}
		$info = self::parse_headers( self::fetch_file( 'prrint.php' ) );
		if ( $info ) {
			$info['changelog'] = self::parse_changelog( self::fetch_file( 'readme.txt' ) );
		}
		set_transient( self::CACHE, $info ? $info : array( 'version' => '' ), $info ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $info;
	}

	/** Version and requirements from a plugin file's header comment. */
	public static function parse_headers( $php ) {
		if ( ! preg_match( '/^[ \t\/*#@]*Version:\s*([0-9][0-9a-zA-Z.\-]*)/m', $php, $m ) ) {
			return null;
		}
		$info = array( 'version' => $m[1], 'requires_php' => '', 'requires' => '', 'tested' => '' );
		foreach ( array( 'requires_php' => 'Requires PHP', 'requires' => 'Requires at least', 'tested' => 'Tested up to' ) as $key => $label ) {
			if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $label, '/' ) . ':\s*(\S+)/m', $php, $hm ) ) {
				$info[ $key ] = $hm[1];
			}
		}
		return $info;
	}

	/**
	 * The latest few "= x.y.z =" entries of readme.txt's == Changelog ==,
	 * as simple HTML for the "View details" popup.
	 */
	public static function parse_changelog( $readme, $entries = 5 ) {
		$pos = stripos( $readme, '== Changelog ==' );
		if ( false === $pos ) {
			return '';
		}
		$text  = substr( $readme, $pos + strlen( '== Changelog ==' ) );
		$end   = strpos( $text, "\n== " );
		$text  = false === $end ? $text : substr( $text, 0, $end );
		$parts = preg_split( '/^=\s*([^=\n]+?)\s*=\s*$/m', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
		$html  = '';
		for ( $i = 1; $i + 1 < count( $parts ) && $i < $entries * 2; $i += 2 ) {
			$items = preg_split( '/^\*\s+/m', trim( $parts[ $i + 1 ] ), -1, PREG_SPLIT_NO_EMPTY );
			$html .= '<h4>' . esc_html( $parts[ $i ] ) . '</h4><ul>';
			foreach ( $items as $item ) {
				$html .= '<li>' . esc_html( preg_replace( '/\s+/', ' ', trim( $item ) ) ) . '</li>';
			}
			$html .= '</ul>';
		}
		return $html;
	}

	/** The entry WordPress expects in the update_plugins transient. */
	protected static function update_entry( $info ) {
		return (object) array(
			'id'           => 'github.com/' . self::repo(),
			'slug'         => dirname( self::basename() ),
			'plugin'       => self::basename(),
			'new_version'  => $info['version'],
			'url'          => 'https://github.com/' . self::repo(),
			'package'      => self::package_url(),
			'requires_php' => $info['requires_php'],
			'requires'     => $info['requires'],
			'tested'       => $info['tested'],
			'icons'        => array(),
			'banners'      => array(),
		);
	}

	/**
	 * Adds Prrint to WordPress's update check: in "response" when GitHub has
	 * a newer version, else in "no_update" (which keeps the auto-update
	 * toggle available on the Plugins screen).
	 */
	public static function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$info = self::remote_info();
		if ( ! $info ) {
			return $transient;
		}
		$entry = self::update_entry( $info );
		if ( version_compare( $info['version'], PRRINT_VERSION, '>' ) ) {
			$transient->response[ self::basename() ] = $entry;
			unset( $transient->no_update[ self::basename() ] );
		} else {
			$entry->new_version = PRRINT_VERSION;
			$transient->no_update[ self::basename() ] = $entry;
			unset( $transient->response[ self::basename() ] );
		}
		return $transient;
	}

	/** "View details" popup for Prrint. */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || dirname( self::basename() ) !== $args->slug ) {
			return $result;
		}
		$info = self::remote_info();
		if ( ! $info ) {
			return $result;
		}
		return (object) array(
			'name'          => 'Prrint — Photo Print Studio for WooCommerce',
			'slug'          => $args->slug,
			'version'       => $info['version'],
			'author'        => '<a href="https://github.com/masudrana175">Masud Rana</a>',
			'homepage'      => 'https://github.com/' . self::repo(),
			'requires'      => $info['requires'],
			'requires_php'  => $info['requires_php'],
			'tested'        => $info['tested'],
			'download_link' => self::package_url(),
			'sections'      => array(
				'changelog' => $info['changelog'] ? $info['changelog'] : esc_html__( 'See the repository for changes.', 'prrint' ),
			),
		);
	}

	/**
	 * GitHub's zip unpacks to "prrint-main" (or "owner-prrint-<sha>" from the
	 * API); move it to the folder Prrint is installed in, so the update
	 * replaces the plugin instead of installing a second copy.
	 */
	public static function fix_folder( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;
		if ( empty( $hook_extra['plugin'] ) || self::basename() !== $hook_extra['plugin'] || ! $wp_filesystem ) {
			return $source;
		}
		$wanted = trailingslashit( $remote_source ) . dirname( self::basename() ) . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $wanted ) ) {
			return $source;
		}
		if ( ! $wp_filesystem->exists( trailingslashit( $source ) . basename( self::basename() ) ) ) {
			return new WP_Error( 'prrint_update_package', __( 'The downloaded update does not contain Prrint.', 'prrint' ) );
		}
		if ( $wp_filesystem->exists( $wanted ) ) {
			$wp_filesystem->delete( $wanted, true );
		}
		return $wp_filesystem->move( $source, $wanted ) ? $wanted : new WP_Error( 'prrint_update_folder', __( 'Could not prepare the Prrint update folder.', 'prrint' ) );
	}

	/** Sends the optional token, to GitHub only. */
	public static function auth_header( $args, $url ) {
		if ( ! self::token() ) {
			return $args;
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( in_array( $host, array( 'api.github.com', 'codeload.github.com', 'github.com' ), true ) && false !== strpos( $url, self::repo() ) ) {
			$args['headers'] = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();
			$args['headers']['Authorization'] = 'Bearer ' . self::token();
		}
		return $args;
	}

	public static function flush() {
		delete_transient( self::CACHE );
	}

	/** "Check for updates" link under Prrint on the Plugins screen. */
	public static function row_meta( $links, $file ) {
		if ( self::basename() === $file && current_user_can( 'update_plugins' ) ) {
			$url     = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION ), self::ACTION );
			$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Check for updates', 'prrint' ) . '</a>';
		}
		return $links;
	}

	/** Asks GitHub now (skipping the cache) and refreshes WordPress's update list. */
	public static function handle_check() {
		check_admin_referer( self::ACTION );
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'prrint' ) );
		}
		$info = self::remote_info( true );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		$state = ! $info ? 'error' : ( version_compare( $info['version'], PRRINT_VERSION, '>' ) ? 'available' : 'current' );
		wp_safe_redirect( add_query_arg( array( 'prrint_update' => $state ), admin_url( 'plugins.php' ) ) );
		exit;
	}

	public static function checked_notice() {
		$state = isset( $_GET['prrint_update'] ) ? sanitize_key( wp_unslash( $_GET['prrint_update'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( ! $state || ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		$info = 'error' === $state ? null : self::remote_info();
		if ( 'available' === $state && $info ) {
			/* translators: %s: version number */
			$text  = sprintf( __( 'Prrint %s is available — use "update now" below.', 'prrint' ), $info['version'] );
			$class = 'notice-warning';
		} elseif ( 'current' === $state ) {
			/* translators: %s: version number */
			$text  = sprintf( __( 'Prrint is up to date (version %s).', 'prrint' ), PRRINT_VERSION );
			$class = 'notice-success';
		} else {
			$text  = __( 'Could not reach GitHub to check for Prrint updates. Please try again later.', 'prrint' );
			$class = 'notice-error';
		}
		printf( '<div class="notice %1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $text ) );
	}
}
