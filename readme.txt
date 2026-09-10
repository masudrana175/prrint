=== Prrint — Photo Print Studio for WooCommerce ===
Contributors: masudrana175
Tags: woocommerce, photo prints, print shop, image upload, product designer, photo printing
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn WooCommerce products into a photo print shop: multi-photo upload, crop editor, sizes, papers, borders, live pricing, print-ready files.

== Description ==

Prrint adds a complete photo print ordering studio to WooCommerce product
pages, the way dedicated print sites work:

* Multi-photo drag & drop upload with progress bars
* Full-screen crop editor per photo (drag, zoom, rotate, portrait/landscape)
* Crop frame locked to the selected print size aspect ratio
* Configurable print sizes and paper/finish options with surcharges
* Optional white border per print
* Quantity stepper and live per-item + total pricing
* Live DPI print-quality indicator
* EXIF orientation normalization for phone photos
* Cart thumbnails showing the customer's actual crop
* Print-ready 300 DPI JPEGs generated on checkout, with admin download
  buttons and a per-order "Download all as ZIP"
* Global settings page plus per-product overrides
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

= 1.0.0 =
* Initial release.
