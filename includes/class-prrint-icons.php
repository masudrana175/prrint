<?php
/**
 * A small set of hand-authored inline SVG icons — no icon font, no CDN,
 * no external dependency (matches the plugin's existing "no jQuery, no
 * external libraries" stance in README.md). Every path below is original,
 * simple line-icon artwork drawn for this plugin, output as raw markup
 * since it's fixed, trusted content (not user input) — the same trust
 * level the dropzone's own hand-written inline SVG already relies on.
 *
 * @package prrint
 */

defined( 'ABSPATH' ) || exit;

class Prrint_Icons {

	/**
	 * name => inner <svg> markup (no outer <svg> tag — get() adds that).
	 */
	protected static $paths = array(
		'photo'       => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="1.3" fill="currentColor" stroke="none"/><path d="M21 17l-5.6-5.6a1 1 0 0 0-1.4 0L7 18"/>',
		'ruler'       => '<path d="M3 16.5L16.5 3l4.5 4.5L7.5 21z"/><path d="M14 5.5l1.5 1.5M11 8.5L12.5 10M8 11.5L9.5 13M5 14.5L6.5 16"/>',
		'paper'       => '<rect x="6" y="3" width="14" height="14" rx="1.5"/><path d="M4 7v13a1 1 0 0 0 1 1h13"/>',
		'sliders'     => '<line x1="4" y1="6" x2="20" y2="6"/><circle cx="9" cy="6" r="2" fill="currentColor" stroke="none"/><line x1="4" y1="12" x2="20" y2="12"/><circle cx="15" cy="12" r="2" fill="currentColor" stroke="none"/><line x1="4" y1="18" x2="20" y2="18"/><circle cx="11" cy="18" r="2" fill="currentColor" stroke="none"/>',
		'link'        => '<path d="M9 15l6-6"/><path d="M11 6l1-1a4 4 0 0 1 5.5 5.5l-1.5 1.5"/><path d="M13 18l-1 1a4 4 0 0 1-5.5-5.5l1.5-1.5"/>',
		'toolbox'     => '<rect x="3" y="9" width="18" height="10" rx="1.5"/><path d="M8 9V6a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v3"/><line x1="3" y1="13" x2="21" y2="13"/><line x1="11" y1="12" x2="13" y2="12"/>',
		'brush'       => '<path d="M19 3l2 2-9 9-2-2z"/><path d="M12 10l-1.5 5.5L5 17c-1 2 .5 4 2.5 3l1.5-5.5"/>',
		'bookmark'    => '<path d="M6 3h12a1 1 0 0 1 1 1v16l-7-4-7 4V4a1 1 0 0 1 1-1z"/>',
		'palette'     => '<path d="M12 3a9 9 0 1 0 0 18c1 0 1.6-.5 1.6-1.4 0-.5-.2-.9-.5-1.2-.3-.3-.5-.7-.5-1.2 0-1 .8-1.7 1.8-1.7H16a4 4 0 0 0 4-4c0-4.4-3.6-8.5-8-8.5z"/><circle cx="7.5" cy="10.5" r="1.1" fill="currentColor" stroke="none"/><circle cx="10.5" cy="7" r="1.1" fill="currentColor" stroke="none"/><circle cx="15" cy="7.5" r="1.1" fill="currentColor" stroke="none"/><circle cx="17" cy="11" r="1.1" fill="currentColor" stroke="none"/>',
		'crop'        => '<path d="M6 2v14a2 2 0 0 0 2 2h14"/><path d="M18 22V8a2 2 0 0 0-2-2H2"/>',
		'filter'      => '<path d="M4 5h16l-6.2 7.4V19l-3.6 2v-8.6z"/>',
		'sun'         => '<circle cx="12" cy="12" r="4"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>',
		'target'      => '<circle cx="12" cy="12" r="7"/><circle cx="12" cy="12" r="1.4" fill="currentColor" stroke="none"/><path d="M12 1.5v3M12 19.5v3M1.5 12h3M19.5 12h3"/>',
		'text'        => '<path d="M4 6h16M12 6v13"/>',
		'star'        => '<path d="M12 3l2.6 5.6 6.1.6-4.6 4.1 1.3 6-5.4-3.2-5.4 3.2 1.3-6-4.6-4.1 6.1-.6z"/>',
		'pencil'      => '<path d="M4 20l4-1 11-11-3-3L5 16z"/><path d="M14 4l3 3"/>',
		'layers'      => '<path d="M12 3l8 4-8 4-8-4z"/><path d="M4 12l8 4 8-4"/><path d="M4 16l8 4 8-4"/>',
		'square'      => '<rect x="4" y="4" width="16" height="16" rx="1.5"/>',
		'rotate'      => '<path d="M4 12a8 8 0 1 1 2.3 5.6"/><path d="M4 17v-5h5"/>',
		'orientation' => '<rect x="7" y="3" width="10" height="14" rx="1.5"/><path d="M19 14a5 5 0 0 1-5 5"/><path d="M19 10v4h-4"/>',
		'flip-h'      => '<line x1="12" y1="4" x2="12" y2="20" stroke-dasharray="2.5 2.5"/><path d="M18 9l3 3-3 3"/><path d="M6 9L3 12l3 3"/>',
		'flip-v'      => '<line x1="4" y1="12" x2="20" y2="12" stroke-dasharray="2.5 2.5"/><path d="M9 18l3 3 3-3"/><path d="M9 6l3-3 3 3"/>',
		'download'    => '<path d="M12 3v12"/><path d="M7 10l5 5 5-5"/><path d="M5 21h14"/>',
		'trash'       => '<path d="M4 7h16"/><path d="M10 11v6M14 11v6"/><path d="M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13"/><path d="M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3"/>',
		'align-left'  => '<path d="M4 6h16M4 12h10M4 18h13"/>',
		'align-center' => '<path d="M4 6h16M7 12h10M5.5 18h13"/>',
		'align-right' => '<path d="M4 6h16M10 12h10M7 18h13"/>',
		'copy'        => '<rect x="9" y="9" width="12" height="12" rx="1.5"/><path d="M6 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v2"/>',
	);

	/**
	 * @param string $name  One of the keys in self::$paths.
	 * @param int    $size  Width/height in px.
	 * @param string $class Extra class(es) to add.
	 * @return string SVG markup, or '' if $name isn't a known icon.
	 */
	public static function get( $name, $size = 20, $class = '' ) {
		if ( ! isset( self::$paths[ $name ] ) ) {
			return '';
		}
		return sprintf(
			'<svg class="prrint-icon%1$s" viewBox="0 0 24 24" width="%2$d" height="%2$d" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%3$s</svg>',
			$class ? ' ' . esc_attr( $class ) : '',
			(int) $size,
			self::$paths[ $name ] // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed, hand-authored SVG markup, not user input.
		);
	}
}
