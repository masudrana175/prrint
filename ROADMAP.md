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

### Visual design
- Full-screen dark theme matching the reference (top bar, icon rail, filter preview
  tiles, Text panel layout) — verified with an actual Playwright render, not just CSS review

## 🚧 Not started / partially covered

Found by reviewing the uploaded video frame-by-frame. Roughly ordered by
how feasible + valuable each is to build next:

| Item | What the reference has | Status |
|---|---|---|
| **Focus** | Radial / Mirrored / Linear / Gaussian tilt-shift blur | Not started |
| **Text Design** | Library of pre-made word-art templates (multi-text-layer compositions with stylized layouts), Shuffle Layout, Invert | Not started — this was the mystery "bookmark" icon |
| **Transform — richer controls** | Numeric Crop Size (W×H), "Keep Resolution" toggle, Reset to Default, common aspect-ratio presets, continuous-rotation dial, flip H/V | Not started (we have drag/zoom/90°-rotate only) |
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
