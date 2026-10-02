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
- **Guest photo library** — supersedes an earlier "longer retention only, no
  cookie gallery" decision from the same conversation, made when only asked in
  the abstract; once explicitly re-requested ("I think we use cookie"), it's
  buildable safely: a guest (not signed in) who uploads gets an unguessable
  cookie (`Prrint_Account::guest_id()`, same random-token trust model the tmp
  upload token itself already relies on — not more sensitive than what was
  already shipped) identifying a library stored as a transient
  (`prrint_guest_lib_<id>`) instead of user meta, with matching files under
  `library/guest-<id>/`. Unlike a signed-in customer's permanent library, it's
  bounded — an admin-configurable retention window (default 90 days, Quality &
  uploads → "Keep guest photo libraries for"), sliding: any upload/use/delete
  activity resets the clock. `prrint_cleanup_guest_library()` (new daily cron
  job alongside the existing tmp sweep) removes a `library/guest-*` folder once
  its newest file is older than that window; a logged-in customer's
  `library/<user_id>/` folder is exempt purely by not matching that glob, so
  it's never at risk from this sweep regardless of file age. The cookie itself
  is set lazily — only on an actual upload, not on every page view — since it
  exists purely for this optional convenience feature, not for anything the
  page needs to function, and a guest who never uploads shouldn't get a
  long-lived cookie for no functional reason.
  - **Found while building this**: `Prrint_Account::init()` never registered
    `wp_ajax_nopriv_*` variants for the library's list/use/delete AJAX actions
    — meaning they silently only ever worked for logged-in requests despite
    `delete_photo()`/`get_library_json()`/`use_library_photo()` themselves
    looking guest-aware-ready-to-extend at a glance. Fixed alongside this
    change (all three now have both variants registered; `reorder` correctly
    stays logged-in-only, since it operates on a real order tied to a
    customer id).
  - Verified with a mock harness covering the full guest flow (upload → cookie
    minted → library lists it → use-photo mints a fresh token → a *different*
    guest cookie sees an empty library, i.e. no cross-guest leakage → delete
    removes it) plus the logged-in path as a regression check, and a second
    harness proving the cleanup sweep removes a stale guest folder, keeps a
    recently-active one, and never touches a logged-in folder regardless of
    its file age.
- **Full-plugin review pass** — read through every PHP class not already
  covered by a recent targeted change (`class-prrint-cart.php`,
  `class-prrint-orders.php`, `class-prrint-product.php`) looking for
  correctness/security issues; none found there. Did turn up two stale docs
  from before retention became configurable: readme.txt's FAQ still said
  uploads are "purged after 8 days" (hardcoded number from before that
  setting existed) and README.md repeated the same "8-day" figure — both
  fixed to describe the actual (admin-configurable) behavior instead of a
  number that stopped being true several versions ago.

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
- **Filters / Overlays / Elements presets** — one level finer than the whole-tool
  toggle above: keep a tool on but pick exactly which presets it offers (e.g. only
  B&W and Warm out of the 7 filters). "None" is never in the checkbox list for
  Filters/Overlays — it's always injected server-side so a customer can still clear
  a filter they applied even if a store disables every named preset. The AJAX
  sanitizer's own whitelist (`class-prrint-ajax.php`) deliberately stays the *full*
  id list regardless of these settings — it's a data-validation boundary, not a UI
  preference, and must keep accepting a shape/filter/overlay id from an order or
  reorder placed before an admin later disabled it.
- **Studio preview button** — a "Preview" link next to the product picker opens the
  live `[prrint_studio]` page (once it's published) in a new tab, so a store owner
  doesn't have to go hunting for the URL after changing settings.

### Visual design
- Full-screen dark theme matching the reference (top bar, icon rail, filter preview
  tiles, Text panel layout) — verified with an actual Playwright render, not just CSS review
- **Real icon set, no external library** — every emoji/Unicode glyph used as a UI icon
  (editor tool rail, Transform panel Rotate/Orientation/Flip H/Flip V, text alignment,
  refresh/download/delete/add-size, all 8 admin settings card icons + the header logo)
  replaced with a small set of hand-authored inline SVG icons (`Prrint_Icons::get()`,
  `includes/class-prrint-icons.php`) — 24×24 viewBox, `stroke="currentColor"`, one
  consistent line-icon style throughout. Deliberately *not* Font Awesome or any other
  icon font/CDN — the README's own "no jQuery, no external libraries" stance ruled that
  out; inline SVG gets the same "looks like a real product" upgrade with zero added
  dependency, no extra HTTP request, and crisp rendering at any size (emoji/Unicode
  glyphs render wildly differently across OS/browser font stacks, which was the actual
  "doesn't look professional" complaint). Icons needed by JS-generated markup (the
  download/trash/copy icons in card and library-tile templates) are localized from PHP
  into `cfg.icons.*` rather than duplicated as separate strings in `frontend.js`, so
  there's exactly one source of truth for each icon's markup.
  - **Found while wiring this in**: the studio page's Refresh button toggled its own
    `.textContent` between "Refresh" and "Refreshing…" and back — harmless with a plain
    text label, but `textContent`'s setter replaces *all* children, so giving the button
    an icon *and* leaving that code as-is would have deleted the icon permanently the
    first time anyone clicked Refresh. Fixed by moving the label into its own `<span>`
    that JS swaps instead of the whole button.
  - Verified with the same real-server-rendered-markup Playwright harness used for the
    crop/font-dropdown work (uploads a photo, opens the editor, exercises every panel
    touched) — zero JS errors — plus a dedicated screenshot pass over the rail, Transform
    panel, Text panel, item card, and the full admin settings page.
- **Studio "Your Photos" tiles enlarged, icons hardened** — a user screenshot from a live
  site showed the library tiles' download/delete icons as barely-visible thin marks
  instead of clear icons, plus a request for bigger/nicer cards generally. Re-rendering
  the exact same markup/CSS locally showed correct icons, so the live-site issue is most
  likely a theme's own `svg { width: 100%; height: auto }` reset (common for responsive
  images) or an HTML-minifying cache plugin mangling the inline SVG — not reproducible
  here, but `.prrint-icon` now explicitly reasserts `width/height: auto; max-width: none`
  everywhere it's used (frontend, account, admin CSS) to cancel that class of override
  regardless of the exact cause. Independently, the tiles themselves got a real size/style
  pass either way: grid tiles ~120px → ~190px+, image height 110px → 190px, action-button
  circles 26px → 36px with correspondingly larger icons (a separate `libraryDownload`/
  `libraryTrash` localized icon size from the item-card's smaller versions, since they'd
  otherwise share one size unsuited to both contexts).
- **Guest library retention default lowered to 20 days** — was 90 (a judgment call made
  when the feature first shipped); changed to 20 on explicit request. Only affects sites
  that haven't saved the settings page since — WordPress options only take the coded
  default before the first save, so a site that already saved 90 to its database keeps
  90 until an admin edits the field themselves.
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
- **Admin settings page redesigned** — the customer-facing studio and My Account had
  gotten real design attention; WooCommerce → Prrint Studio hadn't — it was almost
  entirely stock WordPress `form-table`/checkbox-row styling with a plain white-box
  wrapper. Gave it an actual design pass: a gradient header, a consistent icon +
  underline treatment on every card title, the five checkbox-grid sections (Editor
  tools, Filters, Overlays, Elements, Text Design layouts) restyled as toggle chips
  (`:has(input:checked)` for the checked look — no JS needed) instead of plain
  checkbox+label rows, bordered/header-shaded tables instead of default `widefat`.
  - **Caught mid-build, not shipped**: a first pass gave the Save button
    `position: sticky; bottom: 0` for an always-visible "save bar." Looked right in
    a full-page screenshot, but a viewport-sized scroll test told a different story —
    since it's the last element in a *very* tall `<form>`, sticky pinned it to the
    viewport bottom for the entire scroll of the page, covering every card's content
    below where it first appeared, not just near the true end. Reverted to a plain,
    non-sticky button — the lesson being that `fullPage: true` screenshots can hide
    exactly this class of scroll-position bug; a real, viewport-sized scroll-and-look
    is what actually caught it.
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
- **Continuous-rotation "Straighten" dial** — a -45°..+45° slider in the
  Transform panel for fine leveling, on top of the existing 90°-quarter-turn
  buttons. This supersedes the "deferred, not just an approximation gap" note
  below: the crop coordinate contract (`Prrint_Image::render()`) was extended
  rather than replaced — the client now exports the crop rectangle in
  "source pixels after N quarter-turns AND the fine rotation" (one extra
  `imagerotate()` call server-side, applied right after the existing
  quarter-turn and right before crop extraction, both keyed off the same
  `crop.fineRot` field so client and server never disagree about which
  space the rectangle is in). The two non-trivial pieces of math involved —
  the crop rectangle's position (its width/height don't change with
  rotation, only x/y do) and the pan-clamp (naively reusing the old
  unrotated clamp would let you pan a straightened photo until an empty
  corner shows) — were derived as closed-form formulas and checked against
  standalone brute-force coordinate-transform scripts before any product
  code was written; the pan-clamp script caught a real gap in the first
  draft (independent-axis clamping only becomes safe once you de-rotate
  into the photo's own frame first). Verified end-to-end with a mock GD
  test that renders a real photo through `Prrint_Image::render()` at
  fineRot=12.5°, 45° (the clamp boundary), and combined with a 90°
  quarter-turn, confirming no fatal errors and that fineRot=0 still
  produces pixel-for-pixel identical output dimensions to omitting the
  field entirely (no regression to the existing crop-only flow).
- **Item card visual polish** — cart-line photo cards got larger rounded
  corners, a hover lift, roomier field spacing, and a custom-styled select
  arrow on the Size/Paper dropdowns in place of the browser default.
- **Product styles + Edge Color (client request)** — a Style dropdown under
  Paper (admin table: name, surcharge, "colored edge" flag) and an Edge Color
  dropdown that appears only for styles with an edge. The edge is the mounted
  product's physical side, not part of the printed image, so it's previewed as
  a colored frame around the card's photo (and the cart thumbnail) rather than
  baked into the print file. Server re-validates both indexes against the saved
  settings; labels, surcharge and edge color go on the cart line, order meta and
  the Reorder snapshot (with `isset()` guards so older snapshots still replay).
- **Theme-proof studio + dashboard colors** — a client's theme turned the
  studio's buttons pink and uppercase. Every frontend.css selector is now
  prefixed with `#prrint-studio` (done by a comment-aware script, 212
  selectors; relative ordering unchanged since every rule gained the same
  one-ID boost), plus a reset for theme typography (`text-transform`,
  `letter-spacing`, `min-height`…). A new "Studio colors" card sets the CSS
  variables. Verified with Playwright against a deliberately hostile theme
  stylesheet loaded *after* the plugin's CSS: card, summary and editor buttons
  all kept the plugin's look, and a pink primary from settings applied.
  - *Found while building this:* the add-to-cart payload never sent the
    Straighten angle (`crop.fineRot`) and "Add size" dropped it too, so a
    straightened photo printed unstraightened. Both fixed; the Playwright
    test now asserts `fineRot` is in the payload.

- **Crop-box Transform tool (reference parity)** — the Transform tool now works
  like the reference: the photo stays fixed and a crop box moves/resizes over it
  (dimmed outside, thirds grid, corner handles), with a "Common" grid of the store's
  print sizes and a bottom bar (flip H/V, dotted straighten dial, rotate left/right).
  The existing crop math was reused unchanged: every formula only reads the frame's
  width/height plus scale and offset, so "box over a fixed photo" is the same model
  with the box as the frame, the photo's fit scale as the scale and offset =
  photo-center − box-center. Other tools still show the print-shaped view; switching
  exports the crop and re-views it. Corner resizing finds the largest valid box by
  binary search (valid boxes sharing an anchor corner are nested, so validity is
  monotonic in size). Undo/Redo now includes the crop. Verified with a Playwright
  run against the real markup: 24 checks covering drag/resize/scroll limits, presets,
  dial, rotate, undo/redo, tool switching, Save → card size, and the exported crop
  staying on the photo.
  - *Found while building this:* the new panel's `display:flex` overrode the
    `hidden` attribute, so the Transform panel stayed on screen in other tools
    (caught by the test, fixed with an explicit `[hidden]` rule); and Save never
    copied a size change back to the item, which didn't matter until the editor
    could change size.

## 🚧 Not started / partially covered

Found by reviewing the uploaded video frame-by-frame. Roughly ordered by
how feasible + valuable each is to build next:

| Item | What the reference has | Status |
|---|---|---|
| **Transform — "Custom" (free-aspect) preset** | A Custom tile that unlocks the crop box's aspect ratio | Everything else in the reference's Transform tool has shipped (see "Done"). Custom is intentionally left out: a free-aspect crop can't fill a fixed-size print without white bars or stretching, so it needs a decision on how such a print should be produced before it's offered |
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
- **Continuous-rotation dial has since shipped** (see "Done" above) — resolved by
  extending (not replacing) the crop contract: the crop rectangle is exchanged in
  "source pixels after N quarter turns AND the fine rotation," with the server
  applying the same extra `imagerotate()` step the client's canvas preview does. The
  "Common" presets also shipped, as the store's own print sizes (both orientations)
  rather than free aspect ratios, since the crop must match a sellable print size.
