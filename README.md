# Prrint — Photo Print Studio for WooCommerce

Turn any WooCommerce product into a full photo print shop, the way dedicated
print sites (Colorplak, Mpix, …) work — but inside your own WordPress store.

![WordPress 5.8+](https://img.shields.io/badge/WordPress-5.8%2B-blue)
![WooCommerce 5.0+](https://img.shields.io/badge/WooCommerce-5.0%2B-96588a)
![PHP 7.2+](https://img.shields.io/badge/PHP-7.2%2B-777bb3)
![License GPL--2.0](https://img.shields.io/badge/License-GPL--2.0-green)

## Features

### Customer-facing (front end)
- **Standalone design & order page** — a `[prrint_studio]` shortcode puts
  the whole upload → design → order flow on its own WordPress Page (like a
  dedicated "Create" page), independent of any WooCommerce product page. A
  regular product still runs the order behind the scenes (pricing, cart,
  checkout) — customers never need to see or visit it.
- **Multi-photo upload** — drag & drop or browse, several photos at once,
  with per-file progress bars. JPEG / PNG / WebP up to a configurable size.
- **Full-screen dark editor** — a Colorplak-style studio: top bar with
  Undo/Redo and Save/Close, left icon rail, and per-tool side panels with
  large filter preview tiles.
- **Crop editor** — full-screen modal editor per photo: drag to reposition,
  scroll-wheel, slider or typed-number zoom, a numeric Crop Size (W × H in
  source-photo pixels, aspect-locked to the print size) for exact crops, a
  "Keep Resolution" toggle that stops you zooming past the target print
  DPI, 90° rotation, flip horizontal/vertical, a one-click Reset to
  Default, portrait/landscape toggle, rule-of-thirds guides, and an
  inch/pixel ruler along the print frame. The crop frame is always locked
  to the chosen print size's aspect ratio: what the customer sees is
  exactly what prints.
- **Filters & adjust** — one-click tone presets (B&W, Warm, Cold, Vintage,
  DuoTone, Legacy, Smooth) plus brightness/contrast/saturation sliders,
  rendered identically in the browser preview and the GD print pipeline.
  DuoTone is a true 2-stop color gradient (not a single-tint approximation),
  done via palette remapping so it stays fast at full print resolution.
- **Order one photo in several sizes** — "Add size" on any card clones that
  photo (same crop/edits) into a second, independently priced cart line so
  a customer can order the same shot as, say, one 8×10 and two 5×7s.
- **Text layers** — add captions on top of the photo: font size, bold,
  alignment, text color, background color, line spacing and rotation, with
  move-by-drag, duplicate and delete. Great for cards, gifts and greetings.
- **Elements** — colored sticker shapes (circle, square, triangle, diamond,
  pentagon, hexagon, star, heart, arrow, cross, line), resizable and
  rotatable, drawn from the same geometry client- and server-side so what
  you place is exactly what prints.
- **Draw** — a freehand doodle brush right on the photo, for a quick note,
  circle or arrow.
- **Focus** — tilt-shift style depth-of-field blur: Radial (a soft circular
  sharp zone), Linear (a single graduated edge), Mirrored (a sharp band with
  blur fading symmetrically on both sides), or Gaussian (blur the whole
  photo evenly), with adjustable position, size and feather.
- **Overlays** — texture composites (Vignette, Glow, Light Leak, Grain,
  Bokeh, Scratches), confined to the photo area, not the border.
- **Custom border** — any color and width, not just the classic white mat.
- **Per-photo options** — print size, paper/finish, quantity stepper, and an
  optional white border, each photo independently.
- **Live pricing** — per-item and order totals update instantly; sticky
  summary bar with a one-click **Add to cart** for the whole batch.
- **Print-quality check** — a live DPI indicator (green/amber/red) on every
  photo and inside the editor warns before a blurry print gets ordered.
- **EXIF-safe** — phone photos are orientation-normalized on upload, so
  crops never come out sideways.
- **Cart shows the real thing** — each cart line displays a thumbnail of the
  customer's actual cropped photo plus its size, paper and border.
- **My Account → My Prints** — every past order's print items, with a
  thumbnail, size/paper/qty, download links for the customer's own photo and
  print-ready file, and a one-click **Reorder** that re-adds the exact same
  print (crop, size, paper, border) to the cart.
- **My Account → My Photos** — every photo a signed-in customer uploads is
  automatically saved to a permanent personal library (separate from the
  8-day temp-upload cleanup), viewable and deletable from their account.

### Store-owner facing (admin)
- **Global settings page** (WooCommerce → Prrint Studio): editable tables of
  print sizes (label / inches / price) and papers (label / surcharge), plus
  upload limits, JPEG quality, print DPI and warning thresholds.
- **Per-product overrides** — a "Print Studio" tab in the product editor:
  enable the studio per product and optionally override sizes/papers.
- **Print-ready files** — every order line gets a flattened 300 DPI JPEG
  (configurable) rendered with the exact crop and border the customer chose,
  plus a copy of the original upload. Buttons on the admin order screen, and
  a **Download all as ZIP** metabox for one-click fulfillment.
- **Sample product on activation** — a draft "Photo Prints" product is
  created automatically so you can test in seconds.
- **Housekeeping** — expired temporary uploads are purged daily; files
  belonging to orders are kept permanently.

## Installation

1. Download / clone this repository into `wp-content/plugins/prrint`.
2. Activate **Prrint — Photo Print Studio for WooCommerce** (WooCommerce must
   be active). This also drafts a product ("Photo Prints") and a page
   ("Create Your Print", containing `[prrint_studio]`) to get you started.
3. Publish the "Photo Prints" product and give it a regular price — the
   product is only used to process the order (pricing, cart, checkout); the
   price is the fallback for sizes priced at 0.
4. Publish the "Create Your Print" page — that's the studio's URL, the one
   to link/promote, independent of the product's own page.
5. Optionally adjust sizes, papers and quality under
   **WooCommerce → Prrint Studio**.

### The `[prrint_studio]` shortcode

The design-and-order studio (upload, editor, sizes/papers, Add to cart) is a
shortcode, so it can live on any WordPress Page — a dedicated "Create" page,
your homepage, wherever — instead of a WooCommerce product page. It designs
against one Print-Studio-enabled product behind the scenes (for pricing and
checkout only):

- `[prrint_studio]` — uses the store's default product (the auto-created
  sample product, or whichever one you've pointed it at).
- `[prrint_studio id="123"]` — designs against a specific product id, e.g.
  if you run several print types as separate products.

The studio still also appears on the product page itself of any
Print-Studio-enabled product (via the **Print Studio** checkbox in the
product editor) — the shortcode is an additional, independent entry point,
not a replacement; use whichever fits your site, or both.

### URL preselection

Link to the studio with `?size=8x10` or `?paper=luster` (slugs of your
labels) to preselect options — handy for category-style landing links such
as "Luster prints". Works on both a product page and a `[prrint_studio]`
page.

## Compatibility & conflict safety

- Works with WooCommerce HPOS (custom order tables) — compatibility declared.
- No jQuery or external libraries; all JS/CSS is vanilla, namespaced under
  `prrint-`/`Prrint_`, and only loaded on pages that need it.
- No WooCommerce template overrides — everything hooks into standard actions
  and filters, so themes and other plugins keep working.
- Requires the PHP GD extension (bundled with virtually every host);
  ZipArchive is optional (only used for the ZIP download button).

## Technical notes

- Customer files live in `wp-content/uploads/prrint/` with random,
  unguessable names (`tmp/` for pending uploads, `orders/` for permanent
  order files, `previews/` for cart thumbnails, `library/<user_id>/` for
  signed-in customers' saved-photo library).
- Crop coordinates are exchanged between the browser editor and the GD
  renderer in a single well-defined space (source pixels after N quarter-turn
  rotations), so client preview and server output always match.
- Filters/adjust/text/border are a second render pass on top of the cropped
  output; text layer position and size are fractions of that output canvas,
  so the same numbers describe a small cart-preview render and the full
  300 DPI print alike. See `Prrint_Image::render()`.
- The text tool renders with a bundled Liberation Sans (Regular/Bold,
  `assets/fonts/`, SIL Open Font License) so print output doesn't depend on
  fonts installed on the server.
- Uninstalling removes plugin options but intentionally keeps order files.

## License

GPL-2.0-or-later.
