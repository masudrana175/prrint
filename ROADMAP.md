# Prrint — Build Roadmap

Tracks what's been built against the Colorplak reference (screenshots +
uploaded video walkthrough) and what's still outstanding. Updated as work
lands — check git log for the commit implementing each item.

## ✅ Done

### Core print ordering (pre-existing, v1.0.0)
- Multi-photo drag & drop upload, per-file progress
- Crop editor: drag/zoom/rotate 90°, portrait–landscape toggle, DPI quality badge
- Print size / paper / quantity / white border, live pricing, batch add-to-cart
- Server-side GD print pipeline, EXIF normalization
- Admin: global settings, per-product overrides, print-ready files, order downloads + ZIP

### My Account
- **My Prints** — order/print history tab, one-click Reorder with exact crop/design replay
- **My Photos** — permanent saved-photo library for signed-in customers, auto-filled on upload
- **Download** — every saved photo and item card has a Download link to save the original file locally

### Studio page — "Your uploaded photos"
- Signed-in customers now see their saved photo library **directly on the studio page**
  (product page or `[prrint_studio]`), not just tucked away in My Account — shown by
  default above the item cards, so returning customers don't have to re-upload.
- **Use this photo** on any library tile starts a new item card from it — same
  size/paper/qty/Add to cart flow as a fresh upload. Implemented by pointing a
  fresh upload token directly at the library's existing permanent file (no file
  copy needed) and reusing the exact upload-success code path, so it's the same
  server-verified pipeline either way, not a parallel one.
- **Delete** removes a photo from the library right from the studio page (same
  endpoint the My Account tab already used).
- **Refresh** re-fetches the library over AJAX — e.g. after uploading from another
  tab or device, without reloading the page. A fresh upload also refreshes the
  list automatically.
- Verified the new use/delete/list endpoints against a mock WP harness (10
  assertions: auth checks, response shape, the token pointing at the right file,
  row removal) and the full layout via Playwright at desktop and mobile widths.
- **Configurable retention** — uploads (guest and signed-in alike, before a
  signed-in customer's file also lands in their permanent library) are now kept
  for an admin-configurable number of days (Settings → "Keep uploaded photos
  for," default 14) instead of a hardcoded 8, and the upload token's expiry now
  matches that same window so it never goes stale before the file itself does
  — previously the token (1 week) and file (8 days) had two different, unrelated
  hardcoded lifetimes.

### Cart, checkout, order page & emails show the real photo
- **WooCommerce Cart/Checkout Blocks support** — the React-based Cart/Checkout
  Blocks (now WooCommerce's default checkout) don't render classic PHP
  templates at all, so the existing `woocommerce_cart_item_thumbnail` filter
  never ran for them; they pull item images from the Store API instead, which
  has its own filter (`woocommerce_store_api_cart_item_images`), now also
  hooked — so the customer's actual edited/cropped preview shows correctly
  regardless of which checkout the store runs.
- **Order page & emails** — previously showed no item image or download link
  at all (core WooCommerce doesn't render one by default). Both the customer's
  order-received/View order page and order emails now show the print preview
  plus a **Download image** link, added via `woocommerce_order_item_name`
  (the one filter both contexts share).
- Verified against a mock WC harness (12 assertions: Store API image shape,
  order-item passthrough for non-Prrint items, download link preferring the
  print-ready file over the source photo).

### Visual design — studio page layout
- Consistent vertical rhythm between the studio's major sections (Upload,
  Your Photos, item cards, cart summary) via one shared rule instead of each
  section setting its own one-off margin, a subtle divider before "Your
  uploaded photos," and a max-width so the page doesn't feel sprawling on
  very wide screens. Verified via Playwright at mobile, desktop, and a
  1920px-wide viewport.

### Editor — style previews show the real photo
- Filters and Overlays swatches now render the customer's own uploaded photo with
  each style actually applied (Filters via the same CSS approximation the live
  preview already uses; Overlays layers the texture over the photo) instead of an
  abstract color-gradient placeholder — so "what will this look like" is visible
  right in the tool, not just after clicking it.

### Editor — tool rail
- **Transform** — crop/zoom/drag, 90° rotate, portrait/landscape, flip H/V, a typeable
  Zoom % field (in addition to drag/wheel/slider) for exact numeric control, and an
  inch/pixel ruler along the print frame (admin-configurable unit) — the frame is the
  fixed print output, so ruler ticks depend only on the selected size, not zoom
- **Filters** — B&W, Warm, Cold, Vintage, DuoTone, Legacy, Smooth
  - DuoTone is a **true 2-stop gradient** (palette remap technique), not an approximation
- **Adjust** — Brightness, Contrast, Saturation, Gamma, Exposure, Clarity, Shadows, Highlights
  (Shadows/Highlights are global approximations — see "Notes on feasibility limits")
- **Text** — multi-layer captions: a searchable Font Family dropdown (each option
  rendered in its own font) over the popular-fonts list, or type any Google Fonts
  name and press Enter — not a fixed picklist — font size, bold, alignment, color,
  background color, line spacing, rotation, drag-to-move, and an on-canvas floating
  toolbar (Edit/Move to Front/Duplicate/Delete) above the selected layer, alongside
  the side-panel controls
  - Google Fonts: the browser preview loads the family live from Google's CSS2 API;
    the GD print pipeline (which needs a local TTF, not a webfont) downloads one via
    Google's legacy `css?family=` endpoint — spoofing an old user agent to get a
    plain .ttf response instead of woff2, a technique several PDF-generation
    libraries use for the same reason — and caches it under
    `wp-content/uploads/prrint/fonts/gfonts/`. Falls back to the bundled Liberation
    Sans on any failure (no outbound internet, an invalid/unknown family name), so
    a print never breaks over a font fetch. Family names are validated against the
    same `[A-Za-z0-9 ]` allowlist both before the client ever builds a Google Fonts
    URL and again server-side before the AJAX-submitted design is trusted, since the
    name flows into a server-side outbound HTTP request
- **Elements** — sticker shapes (circle, square, triangle, diamond, pentagon, hexagon,
  star, heart, arrow, cross, line), colored, resizable, rotatable; identical geometry
  client- and server-side (verified point-for-point equal, and rendered through the
  real GD drawing function before shipping)
- **Draw** — freehand doodle brush, composited as a transparent PNG layer
- **Border** — any color/width, not just white
- **Overlays** — texture composites (Vignette, Glow, Light Leak, Grain, Bokeh, Scratches),
  procedurally generated (no internet access to fetch the reference's real textures),
  confined to the photo area (not the border), reusing the Draw layer's compositing pipeline
- **Undo/Redo** — history stack over filter/adjust/border/rotation/layer edits
- **Order one photo in multiple sizes** — "Add size" clones a card with the same edits
- **Zoom % readout** — top bar center shows "− 105% +", synced with wheel zoom,
  the slider, and the ± buttons
- **Flip Horizontal / Vertical + Reset to Default** — Transform panel gained
  Flip H/V toggles (mirrors the cropped photo, identical math client- and
  server-side) and a Reset button that clears rotation/flip and re-centers
  the crop/zoom
- **Focus** — tilt-shift style blur with Radial / Linear / Mirrored / Gaussian
  shapes. GD has no per-pixel masked-composite primitive, so the server
  downscales-blurs-upscales (verified ~4-8x faster than a full-res blur with
  no visible quality loss) then masks it in via tiled `imagecopymerge` calls
  (exact for the two straight-edge shapes, a smooth approximation for
  radial); ~1s at full print resolution. The editor/thumbnail preview uses a
  canvas `blur()` + gradient-mask approximation of the same effect (see
  "Notes on feasibility limits")
- **Text Design** — a library of 6 pre-made word-art layouts (Banner, Stacked,
  Quote, Corner Tag, Stamp, Side Strip), Shuffle Layout (reapplies a random
  other layout to the same typed text) and Invert (swaps foreground/background,
  or flips black↔white when there's no background). Solves the mystery
  "bookmark" rail icon from the video. Built entirely on the existing text-layer
  schema/renderer — a template is just a named preset of layer fields, so no
  server rendering changes were needed at all, only client-side composition
  and sanitizer pass-through (which already whitelists layer fields and drops
  anything else, so the template-tracking fields never reach the server)

### Standalone design & order page
- **`[prrint_studio]` shortcode** — the full upload → design → order flow on its own
  WordPress Page, independent of the WooCommerce single-product template (reversing
  the earlier in-product-page-only decision, per explicit re-request). Designs against
  one configured/auto-created product behind the scenes purely to process the order
  (pricing, cart, checkout) — customers never need to see that product's own page.
  `[prrint_studio id="123"]` points it at a specific product. Activation now also
  drafts a "Create Your Print" page with the shortcode already in it, alongside the
  existing sample product. The old product-page integration still works unchanged —
  this is an additional entry point, not a replacement.

### Admin controls
- **Standalone studio page product** — a dropdown in Settings picks which product
  `[prrint_studio]` (and the default studio flow) designs against, instead of being
  locked to whichever product got auto-created on activation
- **Editor tool visibility** — checkboxes to turn whole tools off (Filters, Adjust,
  Focus, Text, Text Design, Elements, Draw, Overlays, Border) to simplify the editor
  per store. Transform (crop/rotate) always stays on — it decides what gets printed.
  Disabling a tool removes its rail icon (its only entry point); the panel markup
  itself still renders (just unreachable) rather than being conditionally omitted,
  since dozens of existing JS call sites assume these elements always exist and
  aren't null-guarded — omitting markup risked breaking the editor for anyone who
  disables a tool. Same customer-facing result, none of the risk.
- **Text Design layout toggles** — checkboxes for which of the 6 word-art layouts
  appear in the Text Design panel
- **Color palettes** — the text/background/border/shape/draw color swatch lists are
  now editable (comma-separated hex, "transparent" for no fill) instead of a fixed
  hardcoded set, with a live swatch preview in the admin page

### Visual design
- Full-screen dark theme matching the reference (top bar, icon rail, filter preview
  tiles, Text panel layout) — verified with an actual Playwright render, not just CSS review
- **My Account pages redesigned** — My Prints and My Photos had never gotten the same
  design attention as the studio/editor (a bare table and an unstyled grid, effectively
  default browser styling). Rebuilt using the studio's own design tokens (accent color,
  radius, shadow system) for a consistent look across the plugin: card-style print
  history rows with pill-shaped action buttons, hover-lift photo tiles, and a
  mobile layout where the table collapses into stacked cards below 700px — verified
  both breakpoints via Playwright
- **Transform panel buttons polished** — Rotate/Orientation/Flip H/Flip V/Reset had
  zero spacing between rows and a faint transparent-outline look; now have proper
  padding, a subtle fill so they read as real buttons, and consistent vertical rhythm
  above/below each row.
- **Numeric Crop Size + Keep Resolution** — a "Crop Size" W × H field pair (in
  source-photo pixels) next to Reset to Default: typing either one recalculates
  zoom to match, and always stays locked to the selected print size's aspect
  ratio (the crop rectangle's aspect is mathematically forced to equal the
  frame's, so W and H can never diverge from it). "Keep Resolution" is a
  checkbox that caps how far you can zoom *in* so the crop's effective DPI
  never drops below the admin's configured target DPI — reusing the exact DPI
  formula the live badge already shows. This corrects an earlier note below
  that called a numeric crop control "not meaningful" — the reference's field
  describes the crop rectangle in source pixels (exactly what
  `edExportCrop()` already computes), not the print's physical inches, so it
  was buildable without the bigger architecture change that note worried about.
- **Crop tool verified end-to-end, no bug found** — a user report of "crop not
  working" couldn't be pinned down to specifics, so rather than guess, it was
  tested directly: a Playwright script drives the *real* plugin JS/CSS against
  the *real* server-rendered markup (via reflection into `render_studio_markup`,
  not a hand-copied mock) — uploads a photo, opens the editor, drags the canvas,
  scroll-wheel zooms, drags the zoom slider, types into the Crop Size fields,
  toggles Keep Resolution, clicks Reset to Default — and asserts the visible
  DPI/zoom/crop-size readouts actually change, while watching for any JS
  exception. Every interaction passed clean. If it's still not working on a
  live site, the likely cause is stale cached assets rather than a code bug —
  worth a hard refresh / cache-plugin purge before re-reporting.
- **General visual polish pass** — every editor tool, not just Transform, got a
  consistency pass: `<input type="range">` sliders (Adjust, Text, Border, Focus,
  Draw) now use a custom-styled thumb/track instead of relying on the browser's
  own look (`accent-color` alone renders very differently across browsers);
  color swatches get a hover scale-up and a smoother active ring; filter/overlay
  preview tiles lift on hover; shape buttons and the tool-rail icons got matching
  hover/active micro-interactions. Verified visually via Playwright screenshots
  of Adjust, Elements and the Font dropdown against the running plugin.

## 🚧 Not started / partially covered

Found by reviewing the uploaded video frame-by-frame. Roughly ordered by
how feasible + valuable each is to build next:

| Item | What the reference has | Status |
|---|---|---|
| **Transform — richer controls** | Numeric Crop Size (W×H), "Keep Resolution" toggle, Reset to Default, common aspect-ratio presets, continuous-rotation dial, flip H/V | **Flip H/V, Reset to Default, typeable Zoom %, numeric Crop Size (W×H in source pixels, aspect-locked), and Keep Resolution all shipped.** Common aspect-ratio/size presets (the reference's "COMMON" grid — Square, 6x4, 4x6, 7x5, 10x8, 14x11, etc.) and a continuous-rotation dial are still not started. The presets grid raises a bigger question first: those tiles look like a second way to pick print size/aspect *from inside the editor*, which currently only lives in the card's Size dropdown outside the editor — needs a decision on whether to duplicate size-selection into the editor (and keep it synced both ways) before building it, rather than building a grid that doesn't actually change anything |
| **Floating layer toolbar — rotate handle** | A drag-handle circle below the selected layer for freehand rotation, in addition to Edit/Move to Front/Duplicate/Delete | **The Edit/Move to Front/Duplicate/Delete toolbar itself shipped** (positioned above the selected layer on canvas, matching the reference, alongside the existing side-panel controls). The drag-handle rotation gesture is still not built — the side panel's Rotation slider covers the same value today |

## ❌ Not started at all

- **Canvas / Wall Art** product type (gallery wrap, frame color, bleed)
- **Photo Books** product type (multi-page builder, layouts, cover, PDF/per-page export)
- **Greeting Cards** product type (pack pricing, front caption)
- Multi-select bulk sizing (the reference lets you select several uploaded photos at
  once and apply a size/quantity to all of them via +/- next to each size in the list —
  bigger than our current one-photo-at-a-time "Add size" duplicate)

## Notes on feasibility limits

A few reference features hit real constraints of this plugin's environment and are
built as honest approximations rather than pixel-perfect ports:

- **No internet access in this build environment** — can't fetch the exact fonts,
  textures, or template art the reference uses. Text uses bundled Liberation Sans
  (OFL-licensed); Overlays use procedurally-generated textures instead of photos.
- **GD (not ImageMagick)** — some effects (true per-pixel Shadows/Highlights masking)
  would require a per-pixel PHP loop that's too slow at 300 DPI print resolution
  without a job queue. Where this applies, we use GD's native fast operations
  (`imagegammacorrect`, `imageconvolution`, palette remapping) to get as close as
  possible without a blocking performance cost, and say so in code comments.
- **Focus's editor/thumbnail preview is a CSS-canvas approximation, not a pixel match
  for the print.** GD has no "blend two images using a third image's per-pixel alpha as
  a mask" primitive (that's an ImageMagick feature), so the server masks blur in via many
  small `imagecopymerge` calls tiled across the canvas — exact for Focus's two straight-
  edge shapes, and a close approximation for the radial shape (smooth at the ~60-tile
  resolution used, verified against a checkerboard test image). The live editor instead
  uses the canvas 2D API's own `blur()` filter plus a `radial-/linear-gradient`
  destination-in mask — visually very close, same "CSS approximates GD, GD print output
  is ground truth" principle the Filters/Adjust panels already use (see their code
  comments), just not literally the same algorithm pixel-for-pixel.
- **Continuous-rotation dial and numeric Crop W×H are deferred, not just an approximation
  gap.** The crop coordinate contract between the browser editor and the GD renderer
  (`Prrint_Image::render()`) is built entirely around 90°-quarter-turn rotation — crop
  x/y/w/h are exchanged in "source pixels after N quarter turns," which is exact and
  cheap (`imagerotate($im, -90 * $rotation, 0)`, always axis-aligned). An arbitrary-angle
  dial needs the crop rectangle itself to rotate with the photo (a rotated rect, not an
  axis-aligned one), which changes what "crop x/y/w/h" means everywhere it's read: the
  renderer, the cart-preview thumbnail, the print-ready export, and the reorder replay.
  That's a coordinate-model change to code this session deliberately left alone as
  "unchanged legacy," not a one-slider addition — worth doing as its own pass with
  focused testing rather than folded into this one. Numeric Crop W×H has the same
  dependency (it's just a text-input front end onto the same rect). Aspect-ratio presets
  don't apply the same way here to begin with: Prrint's crop frame is always locked to
  the chosen print size's aspect ratio by design (see README "Crop editor"), where the
  reference's presets exist because its canvas *isn't* pre-locked to a print size.
