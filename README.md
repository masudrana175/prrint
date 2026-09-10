# Prrint — Photo Print Studio for WooCommerce

Turn any WooCommerce product into a full photo print shop, the way dedicated
print sites (Colorplak, Mpix, …) work — but inside your own WordPress store.

![WordPress 5.8+](https://img.shields.io/badge/WordPress-5.8%2B-blue)
![WooCommerce 5.0+](https://img.shields.io/badge/WooCommerce-5.0%2B-96588a)
![PHP 7.2+](https://img.shields.io/badge/PHP-7.2%2B-777bb3)
![License GPL--2.0](https://img.shields.io/badge/License-GPL--2.0-green)

## Features

### Customer-facing (front end)
- **Multi-photo upload** — drag & drop or browse, several photos at once,
  with per-file progress bars. JPEG / PNG / WebP up to a configurable size.
- **Crop editor** — full-screen modal editor per photo: drag to reposition,
  scroll-wheel & slider zoom, 90° rotation, portrait/landscape toggle,
  rule-of-thirds guides. The crop frame is always locked to the chosen print
  size's aspect ratio: what the customer sees is exactly what prints.
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
   be active).
3. Publish the auto-created draft product "Photo Prints" (or enable the
   **Print Studio** checkbox on any simple product) and give it a regular
   price — the price is the fallback for sizes priced at 0.
4. Optionally adjust sizes, papers and quality under
   **WooCommerce → Prrint Studio**.

### URL preselection

Link to a product with `?size=8x10` or `?paper=luster` (slugs of your labels)
to preselect options — handy for category-style landing links such as
"Luster prints".

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
- Uninstalling removes plugin options but intentionally keeps order files.

## License

GPL-2.0-or-later.
