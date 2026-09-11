=== Prrint — Photo Print Studio for WooCommerce ===
Contributors: masudrana175
Tags: woocommerce, photo prints, print shop, image upload, product designer, photo printing
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.2
Stable tag: 1.20.0
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

= 1.20.0 =
* Add admin control over exactly which Filters, Overlays and Elements
  (shapes) show in the editor, matching the existing whole-tool on/off
  switches — e.g. keep the Filters tool on but only offer B&W and Warm.
  "None" always stays available for Filters/Overlays so a customer can
  clear one they applied.
* Add a "Preview" button next to the studio product picker in
  WooCommerce → Prrint Studio that opens the live [prrint_studio] page
  in a new tab.
* Fix a double-escaped "&" in the new settings card's heading.

= 1.19.0 =
* Font Family in the Text tool is now a proper styled dropdown: click to
  open a searchable list of popular Google Fonts, each option rendered in
  its own font, or type any Google Fonts name and press Enter — still
  "unlimited," just easier to browse. Fixed a bug (only reachable if a
  site's localized strings are incomplete, e.g. right after an update)
  where the search box could throw and leave the list stuck half-filtered.
* Polish pass across every editor tool: sliders (Adjust, Text, Border,
  Focus, Draw) now have a custom thumb/track instead of the plain browser
  default; color swatches, filter/overlay preview tiles, shape buttons and
  the tool rail icons all got hover/active micro-interactions (lift, glow,
  scale) for a more polished, consistent feel across every panel.
* Verified the crop tool (drag, scroll/slider/typed-number zoom, the
  numeric Crop Size fields, Keep Resolution) end-to-end with an automated
  browser test against the real plugin code — found no bugs in it. If
  crop still isn't working on your site, please confirm you're on this
  version and describe exactly what happens (nothing responds? an error?
  the final print looks wrong?) so it can be reproduced and fixed.

= 1.18.0 =
* Add unlimited Google Fonts to the Text tool: type any Google Fonts family
  name (with autocomplete suggestions for popular ones) instead of only
  the bundled Liberation Sans. The browser preview loads it live from
  Google Fonts; the print pipeline downloads and caches a matching TTF the
  first time it's used (falling back to Liberation Sans automatically if
  a site has no outbound internet access or the name doesn't match a real
  font, so a print never fails to render).
* Add an on-canvas floating toolbar (Edit, Move To Front, Duplicate,
  Delete) above the selected text or shape layer, alongside the existing
  side-panel controls.

= 1.17.0 =
* Add a numeric "Crop Size" (W × H, in source-photo pixels) to the Transform
  panel — type exact numbers instead of only dragging the zoom slider or
  scroll-wheeling; the two fields stay locked to the selected print size's
  aspect ratio, so entering one updates the other.
* Add a "Keep Resolution" checkbox next to it: turn it on and the editor
  won't let you zoom in past the point where the print would drop below
  the configured target DPI.

= 1.16.0 =
* Polish the Transform panel's buttons (Rotate, Orientation, Flip H/V,
  Reset): proper padding and a subtle fill instead of a faint outline,
  plus consistent spacing between rows and above/below the zoom slider.
* Add a typeable Zoom % field next to the slider for precise numeric
  zoom control.
* Add an inch/pixel ruler along the editor's print frame, with a new
  admin setting (Quality & uploads → "Editor ruler units") to choose
  which.
* Add 5 more Elements shapes: Triangle, Diamond, Pentagon, Hexagon,
  Cross — 11 total now.

= 1.15.0 =
* Add a "Keep uploaded photos for (days)" setting (default 14, applies
  to every upload, signed in or not) — replaces a hardcoded 8-day
  cleanup, and the upload token's own expiry now matches it so it
  never goes stale before the file does.
* Fix: the customer's actual edited/cropped photo now shows correctly
  in cart and checkout on stores using the WooCommerce Cart/Checkout
  Blocks (the now-default React-based checkout), which don't respect
  the classic PHP template filter Prrint already used.
* Add the print preview and a Download image link to the customer's
  order-received/View order page and to order emails — previously
  neither showed any image or download link at all.
* Polish the studio page's overall layout: consistent spacing between
  sections, a divider before "Your uploaded photos," and a max-width
  so it doesn't feel sprawling on very wide screens.

= 1.14.0 =
* Add "Your uploaded photos" directly to the studio page for signed-in
  customers: their saved photo library shows by default, with Use
  this photo (starts a new size/paper/qty/cart item from it), Delete,
  Download, and a Refresh button next to Upload.
* Add a Download link to every item card and saved photo, so
  customers can save their original file locally.
* Filters and Overlays swatches now preview on the customer's own
  photo instead of a generic color gradient, so it's clear what each
  style actually does before picking one.

= 1.13.0 =
* Redesign the My Account pages (My Prints, My Photos), which had
  never gotten the same visual attention as the studio/editor. Uses
  the studio's own design tokens for a consistent look: card-style
  print history rows with pill action buttons, hover-lift photo
  tiles, and a mobile layout where the table collapses into stacked
  cards below 700px.

= 1.12.0 =
* Add admin controls under WooCommerce → Prrint Studio: pick which
  product the [prrint_studio] standalone page designs against, turn
  whole editor tools on/off, choose which Text Design layouts show,
  and edit the text/background/border/shape/draw color palettes
  offered to customers (comma-separated hex, with a live preview).

= 1.11.2 =
* Fix: the editor and the sticky "Add to cart" bar showed on page load
  even before a photo was uploaded, permanently covering the upload
  dropzone underneath — on every page (product page and [prrint_studio]
  alike). A CSS `display: flex` declaration on both elements silently
  overrode the browser's own `[hidden]` behavior, since author
  stylesheet rules always take precedence over the user-agent default
  regardless of specificity. Added explicit `[hidden] { display: none }`
  rules for both.

= 1.11.1 =
* Fix: [prrint_studio] on a Page rendered unstyled and non-functional —
  its CSS/JS were enqueued from inside the shortcode callback, which
  runs too late (after wp_head() has already printed styles in
  virtually every theme). Assets are now enqueued at the correct time
  by detecting the shortcode on the current page during the standard
  wp_enqueue_scripts hook, matching how the product-page integration
  already worked.

= 1.11.0 =
* Add a [prrint_studio] shortcode: the full upload/design/order studio on
  any WordPress Page, independent of the WooCommerce single-product
  template. Designs against one product behind the scenes purely to
  process the order (pricing, cart, checkout) — [prrint_studio id="123"]
  targets a specific product, or it falls back to the store's default.
  Activation now drafts a "Create Your Print" page with the shortcode
  already in it. The existing product-page integration is unchanged and
  still works alongside it.
* Add a Text Design tool to the editor: 6 pre-made word-art layouts
  (Banner, Stacked, Quote, Corner Tag, Stamp, Side Strip), plus Shuffle
  Layout and Invert.

= 1.9.0 =
* Add a Focus tool to the editor: Radial, Linear, Mirrored and Gaussian
  tilt-shift style blur, with adjustable position/size/feather, rendered
  identically fast (~1s) at full 300 DPI print resolution.

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
