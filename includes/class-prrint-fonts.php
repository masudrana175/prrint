<?php
/**
 * Google Fonts support for the text tool: family-name validation, and
 * downloading + caching a TTF file so Prrint_Image can render with it via
 * GD's imagettftext(), which only accepts local TTF/OTF files.
 *
 * Falls back to the bundled Liberation Sans whenever a family isn't set,
 * fails validation, or can't be downloaded (offline site, blocked outbound
 * requests, unknown font name) — a print always renders, never breaks.
 *
 * @package prrint
 */

defined( 'ABSPATH' ) || exit;

class Prrint_Fonts {

	const CACHE_SUBDIR = 'fonts/gfonts/';

	/**
	 * A curated list of well-known Google Fonts family names, offered as
	 * autocomplete suggestions in the editor. Typing any other family name
	 * still works — this list doesn't restrict input, it only helps the
	 * customer find one quickly ("unlimited" fonts, not a fixed picklist).
	 *
	 * @return string[]
	 */
	public static function popular_families() {
		return array(
			'Roboto', 'Open Sans', 'Lato', 'Montserrat', 'Oswald', 'Source Sans Pro',
			'Raleway', 'PT Sans', 'Merriweather', 'Nunito', 'Playfair Display', 'Poppins',
			'Ubuntu', 'Noto Sans', 'Rubik', 'Work Sans', 'Inter', 'Quicksand',
			'Dancing Script', 'Pacifico', 'Lobster', 'Caveat', 'Bebas Neue', 'Anton',
			'Abril Fatface', 'Comfortaa', 'Josefin Sans', 'Crimson Text', 'Libre Baskerville',
			'EB Garamond', 'Cormorant Garamond', 'Archivo', 'Barlow', 'Karla', 'Mukta',
			'Fira Sans', 'DM Sans', 'Space Grotesk', 'Playfair Display SC', 'Great Vibes',
			'Sacramento', 'Satisfy', 'Cookie', 'Shadows Into Light', 'Indie Flower',
			'Amatic SC', 'Permanent Marker', 'Bangers', 'Righteous', 'Alfa Slab One',
			'Cinzel', 'Cormorant', 'Marcellus', 'Julius Sans One', 'Yeseva One',
			'Old Standard TT', 'Vollkorn', 'Domine', 'Bitter', 'Arvo', 'Slabo 27px',
			'PT Serif', 'Noto Serif', 'Zilla Slab', 'Archivo Black', 'Teko',
			'Fjalla One', 'Bree Serif', 'Exo 2', 'Orbitron', 'Kanit', 'Manrope',
			'Nunito Sans', 'Hind', 'Mulish', 'Heebo', 'Jost', 'Outfit',
		);
	}

	/**
	 * Reject anything that isn't a plausible font family name before it ever
	 * reaches a server-side HTTP request or a client-side CSS value — the
	 * one validation boundary both the AJAX sanitizer and the downloader
	 * share.
	 *
	 * @param string $family Raw family name.
	 * @return string Sanitized family name, or '' (meaning "use the default
	 *                bundled font") if it doesn't look like a real one.
	 */
	public static function sanitize_family( $family ) {
		$family = trim( wp_strip_all_tags( (string) $family ) );
		if ( '' === $family || strlen( $family ) > 60 || ! preg_match( '/^[A-Za-z0-9 ]+$/', $family ) ) {
			return '';
		}
		return $family;
	}

	/**
	 * Absolute path to a local TTF for the given family/weight, downloading
	 * and caching it from Google Fonts on first use. Always returns a path
	 * to an existing file (the bundled font on any failure).
	 *
	 * @param string $family Google Fonts family name (already customer input,
	 *                        re-sanitized here regardless of caller).
	 * @param bool   $bold   Whether to fetch the bold (700) weight.
	 * @return string Absolute path to a .ttf file.
	 */
	public static function get_ttf_path( $family, $bold = false ) {
		$family = self::sanitize_family( $family );
		if ( '' === $family ) {
			return self::bundled_path( $bold );
		}

		$cache_path = self::cache_path( $family, $bold );
		if ( file_exists( $cache_path ) && filesize( $cache_path ) > 1000 ) {
			return $cache_path;
		}

		if ( self::download( $family, $bold, $cache_path ) ) {
			return $cache_path;
		}

		return self::bundled_path( $bold );
	}

	/**
	 * @param bool $bold
	 * @return string
	 */
	protected static function bundled_path( $bold ) {
		return PRRINT_DIR . 'assets/fonts/LiberationSans-' . ( $bold ? 'Bold' : 'Regular' ) . '.ttf';
	}

	/**
	 * @return string
	 */
	protected static function cache_dir() {
		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . 'prrint/' . self::CACHE_SUBDIR;
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	/**
	 * @param string $family Sanitized family name.
	 * @param bool   $bold
	 * @return string
	 */
	protected static function cache_path( $family, $bold ) {
		$slug = sanitize_title( $family );
		return self::cache_dir() . $slug . ( $bold ? '-700' : '-400' ) . '.ttf';
	}

	/**
	 * Download a TTF for the given family/weight from Google Fonts.
	 *
	 * Google's modern CSS API only serves woff2 (not usable by GD), but its
	 * legacy `css?family=` endpoint still serves plain .ttf files to
	 * browsers it can't identify as modern — so the request is made with an
	 * old/unrecognized user agent to get that response, matching the same
	 * technique several PDF-generation libraries use for this exact reason.
	 *
	 * @param string $family Sanitized family name.
	 * @param bool   $bold
	 * @param string $dest   Absolute path to write the TTF to.
	 * @return bool
	 */
	protected static function download( $family, $bold, $dest ) {
		$weight  = $bold ? '700' : '400';
		$css_url = 'https://fonts.googleapis.com/css?family=' . rawurlencode( $family ) . ':' . $weight;

		$css_resp = wp_remote_get(
			$css_url,
			array(
				'timeout'    => 8,
				'user-agent' => 'Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1)',
			)
		);
		if ( is_wp_error( $css_resp ) || 200 !== (int) wp_remote_retrieve_response_code( $css_resp ) ) {
			return false;
		}

		$css = wp_remote_retrieve_body( $css_resp );
		if ( ! preg_match( '/url\(([^)]+\.ttf)\)/i', $css, $m ) ) {
			return false;
		}
		$ttf_url = trim( $m[1], '\'"' );
		if ( ! wp_http_validate_url( $ttf_url ) ) {
			return false;
		}

		$font_resp = wp_remote_get( $ttf_url, array( 'timeout' => 10 ) );
		if ( is_wp_error( $font_resp ) || 200 !== (int) wp_remote_retrieve_response_code( $font_resp ) ) {
			return false;
		}

		$body = wp_remote_retrieve_body( $font_resp );
		if ( strlen( $body ) < 1000 ) {
			return false;
		}

		return false !== @file_put_contents( $dest, $body ); // phpcs:ignore
	}
}
