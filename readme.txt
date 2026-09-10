=== Prrint — Photo Print Studio for WooCommerce ===
Contributors: masudrana175
Tags: woocommerce, photo prints, print shop, image upload, product designer, photo printing
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.2
Stable tag: 1.8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn WooCommerce products into a photo print shop: multi-photo upload, crop editor, sizes, papers, borders, live pricing, print-ready files.

== Description ==

Prrint adds a complete photo print ordering studio to WooCommerce product
pages, the way dedicated print sites work:

* Multi-photo drag & drop upload with progress bars
* Full editor per photo: crop/zoom/rotate, one-click filters (B&W, Warm,
  Cold, Vintage, DuoTone, Legacy, Smooth), brightness/contrast/saturation
  adjustment, text/caption layers, sticker shapes, a freehand draw brush,
  and a custom-color border
* Crop frame locked to the selected print size aspect ratio
* Configurable print sizes and paper/finish options with surcharges
* Quantity stepper and live per-item + total pricing
* Live DPI print-quality indicator
* EXIF orientation normalization for phone photos
* Cart thumbnails showing the customer's actual crop
* Print-ready 300 DPI JPEGs generated on checkout, with admin download
  buttons and a per-order "Download all as ZIP"
* Global settings page plus per-product overrides
* "My Prints" order history in My Account with one-click reorder
* "My Photos" — a permanent, personal saved-photo library for signed-in
  customers, filled automatically as they upload
* HPOS compatible, no jQuery, no template overrides, GD-only requirement

== Installation ==

1. Upload the `prrint` folder to `wp-content/plugins/`.
2. Activate the plugin (WooCommerce must be active).
3. Publish the auto-created draft "Photo Prints" product, or enable the
   Print Studio checkbox in any simple product's "Print Studio" tab.
4. Adjust sizes/papers under WooCommerce → Prrint Studio.

== Frequently Asked Questions ==

= Where are customer photos stored? =
In `wp-content/uploads/prrint/` with random unguessable names. Temporary
uploads are purged after 8 days; files attached to orders are kept.

= Does it work with variable products? =
This version targets simple products; sizes/papers replace variations.

== Changelog ==

= 1.8.0 =
* Add Flip Horizontal / Flip Vertical to the editor's Transform panel
  (mirrors the cropped photo, identical in the browser preview and the
  print pipeline), plus a Reset to Default button that clears rotation
  and flip and re-centers the crop/zoom.

= 1.7.1 =
* Editor top bar now shows a live zoom percentage ("− 105% +") in place of
  the static hint text, synced with wheel zoom, the zoom slider, and new
  ± step buttons — matching the reference site's top bar.

= 1.7.0 =
* Add an Overlays tool to the editor: Vignette, Glow, Light Leak, Grain,
  Bokeh, and Scratches texture composites, confined to the photo area
  (not the border), rendered identically in the browser preview and the
  print pipeline. Textures are bundled, procedurally-generated PNGs.

= 1.6.0 =
* Expand the editor's Adjust panel: Gamma, Exposure, Clarity, Shadows,
  Highlights, alongside the existing Brightness/Contrast/Saturation.
  Gamma/Exposure use GD's native gamma correction; Clarity uses a real
  (not approximated) GD convolution sharpen kernel. Shadows/Highlights
  are global brightness/contrast approximations — true per-pixel
  luminance masking is too slow at print resolution without a job queue.

= 1.5.0 =
* Redesigned the Print Studio editor to a full-screen dark theme matching
  a top bar (Undo/Redo, Save/Close), large filter preview tiles, and a
  restructured Text panel (Font Family, Font Size + Alignment, Font/
  Background Color, Line Spacing).
* Add Undo/Redo for filter, adjust, border, rotate/orientation, and text/
  shape layer edits (crop/zoom/pan stay live camera state, not tracked).
* Fixed a layout overflow in the tool panel at narrow widths.

= 1.4.0 =
* DuoTone is now a true 2-stop color gradient (palette remapping), not a
  single-tint approximation — stays fast (~250ms) at full print resolution.
* Add "Add size" on each photo card — order the same photo (same crop and
  edits) in a second size/paper/quantity as its own cart line, without
  re-uploading.

= 1.3.0 =
* Add Elements (sticker shapes: circle, square, star, heart, arrow, line —
  colored, resizable, rotatable) and Draw (freehand doodle brush) to the
  Print Studio editor, alongside the existing Filters/Adjust/Text/Border.
* Sticker shapes are drawn from identical geometry in the browser preview
  and the GD print render, so what you place is exactly what prints.

= 1.2.0 =
* Add a full photo editor to the Print Studio: one-click filters, brightness/
  contrast/saturation adjustment, text/caption layers (font size, bold,
  alignment, color, background, rotation), and a custom-color/width border.
* Bundle Liberation Sans (SIL OFL) so text renders consistently regardless
  of server fonts.
* "My Prints" reorder and the print-ready/preview pipeline now carry the
  full editor design, not just the crop.

= 1.1.0 =
* Add "My Prints" order/print history tab in My Account, with a one-click
  Reorder that re-adds a past print with its exact crop, size and paper.
* Add "My Photos" — a permanent per-customer saved-photo library, filled
  automatically as signed-in customers upload, with delete support.

= 1.0.0 =
* Initial release.
