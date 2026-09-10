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

### Editor — tool rail
- **Transform** — crop/zoom/drag, 90° rotate, portrait/landscape (existing, unchanged)
- **Filters** — B&W, Warm, Cold, Vintage, DuoTone, Legacy, Smooth
  - DuoTone is a **true 2-stop gradient** (palette remap technique), not an approximation
- **Adjust** — Brightness, Contrast, Saturation, Gamma, Exposure, Clarity, Shadows, Highlights
  (Shadows/Highlights are global approximations — see "Notes on feasibility limits")
- **Text** — multi-layer captions: font size, bold, alignment, color, background color,
  line spacing, rotation, drag-to-move, duplicate/delete
- **Elements** — sticker shapes (circle, square, star, heart, arrow, line), colored,
  resizable, rotatable; identical geometry client- and server-side
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

### Visual design
- Full-screen dark theme matching the reference (top bar, icon rail, filter preview
  tiles, Text panel layout) — verified with an actual Playwright render, not just CSS review

## 🚧 Not started / partially covered

Found by reviewing the uploaded video frame-by-frame. Roughly ordered by
how feasible + valuable each is to build next:

| Item | What the reference has | Status |
|---|---|---|
| **Text Design** | Library of pre-made word-art templates (multi-text-layer compositions with stylized layouts), Shuffle Layout, Invert | Not started — this was the mystery "bookmark" icon |
| **Transform — richer controls** | Numeric Crop Size (W×H), "Keep Resolution" toggle, Reset to Default, common aspect-ratio presets, continuous-rotation dial, flip H/V | **Flip H/V and Reset to Default shipped.** Numeric crop W×H, aspect-ratio presets and a continuous-rotation dial are still not started — see feasibility note below |
| **Floating layer toolbar** | Edit/Move to Front/Duplicate/Delete appears *above the selected layer on canvas*; drag-handle for rotation | We built these as side-panel controls instead — functionally equivalent, visually different |

## ❌ Not started at all

- **Canvas / Wall Art** product type (gallery wrap, frame color, bleed)
- **Photo Books** product type (multi-page builder, layouts, cover, PDF/per-page export)
- **Greeting Cards** product type (pack pricing, front caption)
- **Standalone Design Studio page** — a separate build-your-design-first flow, decided
  against earlier in favor of the in-product-page editor; would need to be explicitly
  requested again since it reverses that decision
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
