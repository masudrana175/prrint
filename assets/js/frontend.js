/**
 * Prrint — Photo Print Studio front end.
 *
 * Multi-photo upload, per-photo editor (crop/zoom/rotate, filters, adjust,
 * text layers, border), live pricing, batch add-to-cart.
 *
 * Crop coordinates are exported in the source image's pixel space after
 * applying `rotation` quarter-turns clockwise — the exact space the
 * server-side renderer (Prrint_Image::render) expects.
 *
 * The editor's "design" (filter/adjust/border/text layers) is a second,
 * independent pass applied on top of the cropped output: layer x/y/w and
 * fontSize are fractions of the crop frame, resolution independent, so the
 * same numbers describe a small preview canvas and the full-size print
 * canvas alike — exactly what Prrint_Image::render()'s design stage expects.
 */
(function () {
	'use strict';

	var cfg = window.prrintData;
	var root = document.getElementById('prrint-studio');
	if (!cfg || !root) {
		return;
	}

	/* ------------------------------------------------------------- dom */

	var els = {
		dropzone: document.getElementById('prrint-dropzone'),
		fileInput: document.getElementById('prrint-file-input'),
		items: document.getElementById('prrint-items'),
		summary: document.getElementById('prrint-summary'),
		count: document.getElementById('prrint-count'),
		total: document.getElementById('prrint-total'),
		addBtn: document.getElementById('prrint-add-to-cart'),
		editor: document.getElementById('prrint-editor'),
		canvas: document.getElementById('prrint-canvas'),
		zoom: document.getElementById('prrint-zoom'),
		rotate: document.getElementById('prrint-rotate'),
		orient: document.getElementById('prrint-orient'),
		dpi: document.getElementById('prrint-dpi'),
		editorDone: document.getElementById('prrint-editor-done'),
		editorCancel: document.getElementById('prrint-editor-cancel'),
		toast: document.getElementById('prrint-toast'),
		toolRail: document.querySelector('.prrint-tool-rail'),
		toolPanels: document.querySelectorAll('.prrint-tool-panel'),
		filterGrid: document.getElementById('prrint-filter-grid'),
		adjBrightness: document.getElementById('prrint-adj-brightness'),
		adjContrast: document.getElementById('prrint-adj-contrast'),
		adjSaturation: document.getElementById('prrint-adj-saturation'),
		adjGamma: document.getElementById('prrint-adj-gamma'),
		adjClarity: document.getElementById('prrint-adj-clarity'),
		adjShadows: document.getElementById('prrint-adj-shadows'),
		adjHighlights: document.getElementById('prrint-adj-highlights'),
		adjExposure: document.getElementById('prrint-adj-exposure'),
		adjReset: document.getElementById('prrint-adj-reset'),
		textAdd: document.getElementById('prrint-text-add'),
		textFields: document.getElementById('prrint-text-fields'),
		textContent: document.getElementById('prrint-text-content'),
		textSize: document.getElementById('prrint-text-size'),
		textSpacing: document.getElementById('prrint-text-spacing'),
		textWidth: document.getElementById('prrint-text-width'),
		textRotation: document.getElementById('prrint-text-rotation'),
		textBold: document.getElementById('prrint-text-bold'),
		textColorSwatches: document.getElementById('prrint-text-color-swatches'),
		textBgSwatches: document.getElementById('prrint-text-bg-swatches'),
		textDuplicate: document.getElementById('prrint-text-duplicate'),
		textDelete: document.getElementById('prrint-text-delete'),
		textFont: document.getElementById('prrint-text-font'),
		layerToolbar: document.getElementById('prrint-layer-toolbar'),
		layerEdit: document.getElementById('prrint-layer-edit'),
		layerFront: document.getElementById('prrint-layer-front'),
		layerDuplicate: document.getElementById('prrint-layer-duplicate'),
		layerDelete: document.getElementById('prrint-layer-delete'),
		borderEnable: document.getElementById('prrint-border-enable'),
		borderFields: document.getElementById('prrint-border-fields'),
		borderSwatches: document.getElementById('prrint-border-swatches'),
		borderWidth: document.getElementById('prrint-border-width'),
		shapeGrid: document.getElementById('prrint-shape-grid'),
		shapeFields: document.getElementById('prrint-shape-fields'),
		shapeSize: document.getElementById('prrint-shape-size'),
		shapeRotation: document.getElementById('prrint-shape-rotation'),
		shapeColorSwatches: document.getElementById('prrint-shape-color-swatches'),
		shapeDuplicate: document.getElementById('prrint-shape-duplicate'),
		shapeDelete: document.getElementById('prrint-shape-delete'),
		drawColorSwatches: document.getElementById('prrint-draw-color-swatches'),
		drawSize: document.getElementById('prrint-draw-size'),
		drawClear: document.getElementById('prrint-draw-clear'),
		overlayGrid: document.getElementById('prrint-overlay-grid'),
		zoomOut: document.getElementById('prrint-zoom-out'),
		zoomIn: document.getElementById('prrint-zoom-in'),
		zoomPct: document.getElementById('prrint-zoom-pct'),
		zoomInput: document.getElementById('prrint-zoom-input'),
		undoBtn: document.getElementById('prrint-undo'),
		redoBtn: document.getElementById('prrint-redo'),
		textSpacingOut: document.getElementById('prrint-text-spacing-out'),
		flipH: document.getElementById('prrint-flip-h'),
		flipV: document.getElementById('prrint-flip-v'),
		transformReset: document.getElementById('prrint-transform-reset'),
		keepResolution: document.getElementById('prrint-keep-resolution'),
		cropW: document.getElementById('prrint-crop-w'),
		cropH: document.getElementById('prrint-crop-h'),
		focusShapes: document.getElementById('prrint-focus-shapes'),
		focusFields: document.getElementById('prrint-focus-fields'),
		focusPositionFields: document.getElementById('prrint-focus-position-fields'),
		focusAmount: document.getElementById('prrint-focus-amount'),
		focusX: document.getElementById('prrint-focus-x'),
		focusY: document.getElementById('prrint-focus-y'),
		focusPos: document.getElementById('prrint-focus-pos'),
		focusOrient: document.getElementById('prrint-focus-orient'),
		focusRadius: document.getElementById('prrint-focus-radius'),
		focusWidth: document.getElementById('prrint-focus-width'),
		focusFeather: document.getElementById('prrint-focus-feather'),
		focusXRow: document.getElementById('prrint-focus-x-row'),
		focusYRow: document.getElementById('prrint-focus-y-row'),
		focusPosRow: document.getElementById('prrint-focus-pos-row'),
		focusOrientRow: document.getElementById('prrint-focus-orient-row'),
		focusRadiusRow: document.getElementById('prrint-focus-radius-row'),
		focusWidthRow: document.getElementById('prrint-focus-width-row'),
		focusFeatherRow: document.getElementById('prrint-focus-feather-row'),
		textdesignGrid: document.getElementById('prrint-textdesign-grid'),
		textdesignShuffle: document.getElementById('prrint-textdesign-shuffle'),
		textdesignInvert: document.getElementById('prrint-textdesign-invert'),
		library: document.getElementById('prrint-library'),
		libraryGrid: document.getElementById('prrint-library-grid'),
		libraryEmpty: document.getElementById('prrint-library-empty'),
		libraryRefresh: document.getElementById('prrint-library-refresh')
	};
	var ctx = els.canvas.getContext('2d');

	// Hide the theme's default add-to-cart controls; the studio replaces them.
	var cartForm = root.closest('form.cart');
	if (cartForm) {
		var defQty = cartForm.querySelector('div.quantity');
		var defBtn = cartForm.querySelector('.single_add_to_cart_button');
		if (defQty) { defQty.style.display = 'none'; }
		if (defBtn) { defBtn.style.display = 'none'; }
		cartForm.addEventListener('submit', function (e) { e.preventDefault(); });
	}

	/* ----------------------------------------------------------- state */

	var items = [];   // studio items
	var nextId = 1;
	var nextLayerId = 1;

	var defaultSize = cfg.preselectSize >= 0 ? cfg.preselectSize : 0;
	var defaultPaper = cfg.preselectPaper >= 0 ? cfg.preselectPaper : 0;

	function newDesign() {
		return {
			filter: '',
			adjust: { brightness: 0, contrast: 0, saturation: 100, gamma: 0, exposure: 0, clarity: 0, shadows: 0, highlights: 0 },
			border: { enabled: false, color: '#ffffff', width_in: cfg.borderIn || 0.25 },
			layers: [],
			drawing: null, // { dataUrl } once the customer has drawn something
			overlay: '',   // bundled texture id, e.g. 'vignette'
			flipH: false,  // mirror the cropped photo horizontally
			flipV: false,  // mirror the cropped photo vertically
			focus: { shape: '', amount: 0, x: 0.5, y: 0.5, radius: 0.3, pos: 0.5, width: 0.15, feather: 0.25, orientation: 'horizontal' }
		};
	}

	function cloneDesign(d) {
		return JSON.parse(JSON.stringify(d || newDesign()));
	}

	/* ----------------------------------------------------------- utils */

	function formatPrice(n) {
		var c = cfg.currency;
		var fixed = n.toFixed(c.decimals);
		var parts = fixed.split('.');
		parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, c.thousandSep);
		var num = parts.join(c.decimalSep);
		switch (c.position) {
			case 'right': return num + c.symbol;
			case 'left_space': return c.symbol + ' ' + num;
			case 'right_space': return num + ' ' + c.symbol;
			default: return c.symbol + num;
		}
	}

	function toast(message, isError) {
		els.toast.textContent = message;
		els.toast.classList.toggle('prrint-toast-error', !!isError);
		els.toast.hidden = false;
		clearTimeout(toast._t);
		toast._t = setTimeout(function () { els.toast.hidden = true; }, 4000);
	}

	function rotatedDims(item) {
		return (item.rot % 2)
			? { w: item.natH, h: item.natW }
			: { w: item.natW, h: item.natH };
	}

	function printDims(item) {
		var s = cfg.sizes[item.sizeIdx];
		var lo = Math.min(s.w, s.h);
		var hi = Math.max(s.w, s.h);
		return item.orientation === 'landscape' ? { w: hi, h: lo } : { w: lo, h: hi };
	}

	function unitPrice(item) {
		var s = cfg.sizes[item.sizeIdx];
		var p = cfg.papers[item.paperIdx];
		var base = s.price > 0 ? s.price : cfg.basePrice;
		return base + p.surcharge;
	}

	function itemDpi(item) {
		var p = printDims(item);
		return Math.min(item.crop.w / p.w, item.crop.h / p.h);
	}

	/**
	 * Centered maximal crop for the item's current size/orientation/rotation.
	 */
	function centeredCrop(item) {
		var d = rotatedDims(item);
		var p = printDims(item);
		var aspect = p.w / p.h;
		var sw = Math.min(d.w, d.h * aspect);
		var sh = sw / aspect;
		return {
			x: (d.w - sw) / 2,
			y: (d.h - sh) / 2,
			w: sw,
			h: sh
		};
	}

	/**
	 * Refit an item's crop after size/orientation change, keeping its center.
	 */
	function refitCrop(item) {
		var d = rotatedDims(item);
		var p = printDims(item);
		var aspect = p.w / p.h;
		var cx = item.crop.x + item.crop.w / 2;
		var cy = item.crop.y + item.crop.h / 2;
		var sw = Math.min(item.crop.w, d.w, d.h * aspect);
		var sh = sw / aspect;
		if (sh > d.h) { sh = d.h; sw = sh * aspect; }
		var x = Math.max(0, Math.min(cx - sw / 2, d.w - sw));
		var y = Math.max(0, Math.min(cy - sh / 2, d.h - sh));
		item.crop = { x: x, y: y, w: sw, h: sh };
	}

	/* -------------------------------------------------- design / filters */

	// CSS canvas-filter approximations of the server's GD imagefilter()
	// presets. Only a preview — the ground truth is the server-rendered
	// preview generated right after Add to cart, same as the crop tool.
	var FILTER_CSS = {
		bw: 'grayscale(1)',
		warm: 'sepia(0.35) saturate(1.3)',
		cold: 'saturate(1.15) sepia(0.15) hue-rotate(180deg)',
		vintage: 'sepia(0.6) contrast(0.9) brightness(1.05)',
		duotone: 'grayscale(1) sepia(0.35) hue-rotate(220deg) saturate(1.6)',
		legacy: 'grayscale(0.7) sepia(0.4) contrast(0.85) brightness(0.92)',
		smooth: 'blur(0.5px)'
	};

	function designFilterCss(design) {
		var parts = [];
		if (design && design.filter && FILTER_CSS[design.filter]) {
			parts.push(FILTER_CSS[design.filter]);
		}
		if (design && design.adjust) {
			var a = design.adjust;
			if (a.brightness) { parts.push('brightness(' + (1 + a.brightness / 100) + ')'); }
			if (a.contrast) { parts.push('contrast(' + (1 + a.contrast / 100) + ')'); }
			if (typeof a.saturation === 'number' && a.saturation !== 100) {
				parts.push('saturate(' + Math.max(0, a.saturation / 100) + ')');
			}
			// CSS has no gamma/clarity/shadows/highlights filter — these are
			// brightness/contrast approximations for the live preview only;
			// the server-rendered preview (shown after Add to Cart) is ground
			// truth, same principle as the crop tool.
			if (a.gamma) { parts.push('brightness(' + Math.pow(1.3, a.gamma / 100) + ')'); }
			if (a.exposure) { parts.push('brightness(' + Math.pow(2, a.exposure / 100) + ')'); }
			if (a.clarity) { parts.push('contrast(' + (1 + a.clarity / 100 * 0.15) + ')'); }
			if (a.shadows) { parts.push('brightness(' + (1 + a.shadows / 100 * 0.18) + ')'); }
			if (a.highlights) { parts.push('brightness(' + (1 + a.highlights / 100 * -0.12) + ') contrast(' + (1 + a.highlights / 100 * -0.06) + ')'); }
		}
		return parts.length ? parts.join(' ') : 'none';
	}

	/**
	 * Any Google Fonts family name a customer types (not a fixed picklist —
	 * see Prrint_Fonts::popular_families() for the autocomplete suggestions
	 * only). Loaded on demand via Google's CSS2 API; the server independently
	 * downloads + caches a TTF for print rendering (Prrint_Fonts::get_ttf_path)
	 * so the browser preview and the print pipeline use the same family.
	 */
	var gfontState = {}; // family -> 'loading' | 'ready'

	function sanitizeFontFamily(name) {
		name = (name || '').trim();
		if (!name || name.length > 60 || !/^[A-Za-z0-9 ]+$/.test(name)) { return ''; }
		return name;
	}

	function ensureGoogleFont(family, onReady) {
		family = sanitizeFontFamily(family);
		if (!family) { return; }
		if (gfontState[family] === 'ready') {
			if (onReady) { onReady(); }
			return;
		}
		if (gfontState[family] !== 'loading') {
			gfontState[family] = 'loading';
			var link = document.createElement('link');
			link.rel = 'stylesheet';
			link.href = 'https://fonts.googleapis.com/css2?family=' + encodeURIComponent(family).replace(/%20/g, '+') + ':wght@400;700&display=swap';
			document.head.appendChild(link);
			var mark = function () { gfontState[family] = 'ready'; };
			if (window.document && document.fonts && document.fonts.load) {
				Promise.all([
					document.fonts.load('400 32px "' + family + '"'),
					document.fonts.load('700 32px "' + family + '"')
				]).then(mark).catch(mark);
			} else {
				setTimeout(mark, 400);
			}
		}
		if (onReady) {
			var tries = 0;
			var poll = setInterval(function () {
				tries++;
				if (gfontState[family] === 'ready' || tries > 30) {
					clearInterval(poll);
					if (gfontState[family] === 'ready') { onReady(); }
				}
			}, 150);
		}
	}

	function fontFamilyCss(family) {
		family = sanitizeFontFamily(family);
		return family ? '"' + family + '", "Prrint Liberation Sans", Arial, sans-serif' : '"Prrint Liberation Sans", Arial, sans-serif';
	}

	function wrapTextLine(measureCtx, line, maxW) {
		var words = line.trim().split(/\s+/);
		if (!words.length || (words.length === 1 && words[0] === '')) {
			return [''];
		}
		var out = [];
		var cur = '';
		words.forEach(function (word) {
			var t = cur ? cur + ' ' + word : word;
			if (measureCtx.measureText(t).width > maxW && cur) {
				out.push(cur);
				cur = word;
			} else {
				cur = t;
			}
		});
		if (cur) { out.push(cur); }
		return out.length ? out : [''];
	}

	/**
	 * Bounding box (canvas px) of a text layer, ignoring rotation — used
	 * for hit-testing and for the background-box fill. Frame is the crop
	 * frame rect the layer's x/y/w fractions are relative to.
	 */
	function textLayerBox(drawCtx, layer, frame) {
		if (layer.fontFamily) { ensureGoogleFont(layer.fontFamily); }
		var fontPx = Math.max(6, Math.round(layer.fontSize * frame.h));
		drawCtx.font = (layer.bold ? 'bold ' : '') + fontPx + 'px ' + fontFamilyCss(layer.fontFamily);
		var x = frame.x + layer.x * frame.w;
		var y = frame.y + layer.y * frame.h;
		var w = Math.max(fontPx, layer.w * frame.w);
		var lines = [];
		(layer.text || '').split('\n').forEach(function (src) {
			lines = lines.concat(wrapTextLine(drawCtx, src, w));
		});
		var lineH = fontPx * (layer.lineSpacing || 1.3);
		var h = lineH * lines.length;
		return { x: x, y: y, w: w, h: h, fontPx: fontPx, lineH: lineH, lines: lines };
	}

	function drawOneTextLayer(drawCtx, layer, frame) {
		if (!layer.text) { return; }
		var box = textLayerBox(drawCtx, layer, frame);
		var angleRad = (layer.rotation || 0) * Math.PI / 180;

		if (layer.bgColor) {
			var pad = box.fontPx * 0.4;
			drawCtx.save();
			drawCtx.fillStyle = layer.bgColor;
			drawCtx.fillRect(box.x - pad, box.y - pad, box.w + pad * 2, box.h + pad * 2);
			drawCtx.restore();
		}

		drawCtx.fillStyle = layer.color || '#ffffff';
		drawCtx.textBaseline = 'alphabetic';
		box.lines.forEach(function (line, i) {
			if (!line) { return; }
			var lineW = drawCtx.measureText(line).width;
			var lx = box.x;
			if (layer.align === 'center') { lx = box.x + (box.w - lineW) / 2; }
			else if (layer.align === 'right') { lx = box.x + box.w - lineW; }
			var ly = box.y + box.fontPx + i * box.lineH;
			drawCtx.save();
			drawCtx.translate(lx, ly);
			drawCtx.rotate(angleRad);
			drawCtx.fillText(line, 0, 0);
			drawCtx.restore();
		});
	}

	/**
	 * Normalized (-0.5..0.5) point sets for sticker shapes. Kept in exact
	 * lockstep with Prrint_Image::shape_points() so the browser preview and
	 * the GD print render draw the identical silhouette.
	 */
	var SHAPE_POINTS = {
		square: function () {
			return [[-0.5, -0.5], [0.5, -0.5], [0.5, 0.5], [-0.5, 0.5]];
		},
		circle: function () {
			var pts = [];
			for (var i = 0; i < 40; i++) {
				var t = (i / 40) * Math.PI * 2;
				pts.push([0.5 * Math.cos(t), 0.5 * Math.sin(t)]);
			}
			return pts;
		},
		star: function () {
			var pts = [];
			var outer = 0.5, inner = 0.5 * 0.382;
			for (var i = 0; i < 10; i++) {
				var r = (i % 2 === 0) ? outer : inner;
				var t = (i / 10) * Math.PI * 2 - Math.PI / 2;
				pts.push([r * Math.cos(t), r * Math.sin(t)]);
			}
			return pts;
		},
		heart: function () {
			var raw = [], n = 40;
			var minX = Infinity, maxX = -Infinity, minY = Infinity, maxY = -Infinity;
			for (var i = 0; i <= n; i++) {
				var t = (i / n) * Math.PI * 2;
				var x = 16 * Math.pow(Math.sin(t), 3);
				var y = -(13 * Math.cos(t) - 5 * Math.cos(2 * t) - 2 * Math.cos(3 * t) - Math.cos(4 * t));
				raw.push([x, y]);
				if (x < minX) { minX = x; } if (x > maxX) { maxX = x; }
				if (y < minY) { minY = y; } if (y > maxY) { maxY = y; }
			}
			var sx = maxX - minX, sy = maxY - minY;
			return raw.map(function (p) { return [(p[0] - minX) / sx - 0.5, (p[1] - minY) / sy - 0.5]; });
		},
		arrow: function () {
			return [[-0.5, -0.15], [0.15, -0.15], [0.15, -0.35], [0.5, 0], [0.15, 0.35], [0.15, 0.15], [-0.5, 0.15]];
		},
		line: function () {
			return [[-0.5, -0.06], [0.5, -0.06], [0.5, 0.06], [-0.5, 0.06]];
		},
		triangle: function () {
			return regularPolygonPoints(3);
		},
		diamond: function () {
			return [[0, -0.5], [0.5, 0], [0, 0.5], [-0.5, 0]];
		},
		pentagon: function () {
			return regularPolygonPoints(5);
		},
		hexagon: function () {
			return regularPolygonPoints(6);
		},
		cross: function () {
			return [
				[-0.15, -0.5], [0.15, -0.5], [0.15, -0.15], [0.5, -0.15],
				[0.5, 0.15], [0.15, 0.15], [0.15, 0.5], [-0.15, 0.5],
				[-0.15, 0.15], [-0.5, 0.15], [-0.5, -0.15], [-0.15, -0.15]
			];
		}
	};

	/**
	 * A regular N-gon inscribed in a 0.5 radius, first vertex pointing up —
	 * mirrors Prrint_Image::regular_polygon_points() exactly, point for
	 * point, so client preview and server print match.
	 */
	function regularPolygonPoints(sides) {
		var pts = [];
		for (var i = 0; i < sides; i++) {
			var t = (i / sides) * 2 * Math.PI - Math.PI / 2;
			pts.push([0.5 * Math.cos(t), 0.5 * Math.sin(t)]);
		}
		return pts;
	}

	/**
	 * Text Design: pre-made word-art layouts. Each is a small set of text
	 * layers (fontSize/x/y/w fractions of the canvas — the same
	 * resolution-independent scheme every text layer already uses), applied
	 * on top of the Text tool's freeform single-layer editing rather than
	 * replacing it. Layers a template creates are tagged `_tpl` (stripped by
	 * the server sanitizer, which only reads the fields it whitelists) so
	 * Shuffle/Invert and re-applying a different layout only touch the
	 * template's own layers, not anything the customer added by hand.
	 */
	var TEXT_TEMPLATES = {
		banner: [
			{ text: 'YOUR TEXT HERE', fontSize: 0.08, bold: true, align: 'center', color: '#ffffff', bgColor: '#000000', lineSpacing: 1.2, x: 0.1, y: 0.8, w: 0.8, rotation: 0 }
		],
		stacked: [
			{ text: 'TITLE', fontSize: 0.1, bold: true, align: 'center', color: '#ffffff', bgColor: '', lineSpacing: 1.2, x: 0.1, y: 0.06, w: 0.8, rotation: 0 },
			{ text: 'a little subtitle', fontSize: 0.045, bold: false, align: 'center', color: '#ffffff', bgColor: '', lineSpacing: 1.2, x: 0.15, y: 0.19, w: 0.7, rotation: 0 }
		],
		quote: [
			{ text: '"Your quote here"', fontSize: 0.06, bold: false, align: 'center', color: '#ffffff', bgColor: '', lineSpacing: 1.3, x: 0.1, y: 0.44, w: 0.8, rotation: 0 }
		],
		corner: [
			{ text: 'Est. 2024', fontSize: 0.04, bold: false, align: 'left', color: '#000000', bgColor: '#ffffff', lineSpacing: 1.2, x: 0.05, y: 0.87, w: 0.4, rotation: 0 }
		],
		stamp: [
			{ text: 'SPECIAL', fontSize: 0.045, bold: true, align: 'center', color: '#ffffff', bgColor: '#dc2626', lineSpacing: 1.2, x: 0.58, y: 0.06, w: 0.36, rotation: 10 }
		],
		sidestrip: [
			{ text: 'MEMORIES', fontSize: 0.06, bold: true, align: 'center', color: '#ffffff', bgColor: '#000000', lineSpacing: 1.2, x: -0.18, y: 0.35, w: 0.55, rotation: -90 }
		]
	};

	function shapeLayerBox(layer, frame) {
		return {
			x: frame.x + layer.x * frame.w,
			y: frame.y + layer.y * frame.h,
			w: layer.w * frame.w,
			h: layer.h * frame.h
		};
	}

	function drawOneShapeLayer(drawCtx, layer, frame) {
		var gen = SHAPE_POINTS[layer.shape];
		if (!gen) { return; }
		var box = shapeLayerBox(layer, frame);
		var cx = box.x + box.w / 2;
		var cy = box.y + box.h / 2;
		var angle = (layer.rotation || 0) * Math.PI / 180;

		drawCtx.save();
		drawCtx.translate(cx, cy);
		drawCtx.rotate(angle);
		drawCtx.fillStyle = layer.color || '#000000';
		drawCtx.beginPath();
		gen().forEach(function (p, i) {
			var px = p[0] * box.w, py = p[1] * box.h;
			if (i === 0) { drawCtx.moveTo(px, py); } else { drawCtx.lineTo(px, py); }
		});
		drawCtx.closePath();
		drawCtx.fill();
		drawCtx.restore();
	}

	/**
	 * Bounding box of any layer type, canvas px, ignoring rotation.
	 */
	function layerBoundingBox(drawCtx, layer, frame) {
		return layer.type === 'shape' ? shapeLayerBox(layer, frame) : textLayerBox(drawCtx, layer, frame);
	}

	/**
	 * Full "on top of the photo" pass: doodle, then text/shape layers in
	 * array order — mirrors Prrint_Image::render()'s design stage exactly.
	 *
	 * @param drawingSource CanvasImageSource|null — ed.drawCanvas (editor,
	 *        always ready) or item._drawingImg (card thumbnail, may still
	 *        be loading — check .complete before passing it in).
	 */
	function clamp01(v) { return Math.max(0, Math.min(1, v)); }

	/**
	 * Canvas-native approximation of the server's Focus effect (blur + a
	 * gradient mask), for the live editor and card thumbnails. Like the
	 * filter/adjust CSS approximations above, this is a preview only — the
	 * GD-rendered print/cart-line preview is ground truth.
	 *
	 * @param drawCtx  Context to composite the result onto.
	 * @param design   The item's design object.
	 * @param frame    {x,y,w,h} crop frame in drawCtx's canvas px.
	 * @param bx,by    Border inset within that frame.
	 * @param canvasW,canvasH  Full backing-canvas size (drawCtx's own size).
	 * @param drawPhoto(offCtx) Replays the exact same photo transform/draw
	 *   used for the sharp pass, onto an offscreen context of the same size
	 *   and coordinate space as drawCtx's canvas.
	 */
	function applyFocusBlur(drawCtx, design, frame, bx, by, canvasW, canvasH, drawPhoto) {
		var focus = design && design.focus;
		if (!focus || !focus.shape || !focus.amount) { return; }
		var fw = frame.w - 2 * bx, fh = frame.h - 2 * by;
		if (fw <= 0 || fh <= 0) { return; }

		var off = document.createElement('canvas');
		off.width = canvasW;
		off.height = canvasH;
		var octx = off.getContext('2d');
		octx.filter = 'blur(' + Math.max(1, focus.amount / 100 * 16) + 'px)';
		drawPhoto(octx);
		octx.filter = 'none';

		if (focus.shape !== 'gaussian') {
			octx.save();
			octx.globalCompositeOperation = 'destination-in';
			octx.translate(frame.x + bx, frame.y + by);
			octx.fillStyle = buildFocusMaskGradient(octx, focus, fw, fh);
			octx.fillRect(0, 0, fw, fh);
			octx.restore();
		}

		drawCtx.save();
		drawCtx.beginPath();
		drawCtx.rect(frame.x + bx, frame.y + by, fw, fh);
		drawCtx.clip();
		drawCtx.drawImage(off, 0, 0);
		drawCtx.restore();
	}

	/**
	 * A black-alpha gradient (opaque = blurred, transparent = sharp) used as
	 * a destination-in mask, in frame-local px (0..fw, 0..fh).
	 */
	function buildFocusMaskGradient(ctx, focus, fw, fh) {
		if (focus.shape === 'radial') {
			var cx = (typeof focus.x === 'number' ? focus.x : 0.5) * fw;
			var cy = (typeof focus.y === 'number' ? focus.y : 0.5) * fh;
			var half = Math.min(fw, fh) / 2;
			var r0 = Math.max(1, (typeof focus.radius === 'number' ? focus.radius : 0.3) * half);
			var r1 = r0 + Math.max(4, (typeof focus.feather === 'number' ? focus.feather : 0.25) * half);
			var rg = ctx.createRadialGradient(cx, cy, r0, cx, cy, r1);
			rg.addColorStop(0, 'rgba(0,0,0,0)');
			rg.addColorStop(1, 'rgba(0,0,0,1)');
			return rg;
		}

		var vertical = focus.orientation === 'vertical';
		var span = vertical ? fw : fh;
		var pos = (typeof focus.pos === 'number' ? focus.pos : 0.5) * span;
		var feather = Math.max(4, (typeof focus.feather === 'number' ? focus.feather : 0.25) * span);
		var lg = vertical ? ctx.createLinearGradient(0, 0, fw, 0) : ctx.createLinearGradient(0, 0, 0, fh);

		if (focus.shape === 'linear') {
			lg.addColorStop(clamp01(pos / span), 'rgba(0,0,0,0)');
			lg.addColorStop(clamp01((pos + feather) / span), 'rgba(0,0,0,1)');
			return lg;
		}

		// Mirrored: a sharp band, symmetric falloff on both sides.
		var width = Math.max(2, (typeof focus.width === 'number' ? focus.width : 0.15) * span);
		var stops = [
			[pos - width - feather, 1],
			[pos - width, 0],
			[pos + width, 0],
			[pos + width + feather, 1]
		];
		var last = 0;
		stops.forEach(function (s) {
			var off = Math.max(clamp01(s[0] / span), last);
			last = off;
			lg.addColorStop(off, 'rgba(0,0,0,' + s[1] + ')');
		});
		return lg;
	}

	function drawDesignOverlay(drawCtx, design, frame, drawingSource) {
		if (drawingSource) {
			drawCtx.drawImage(drawingSource, frame.x, frame.y, frame.w, frame.h);
		}
		if (!design || !design.layers) { return; }
		design.layers.forEach(function (layer) {
			if (layer.type === 'text') { drawOneTextLayer(drawCtx, layer, frame); }
			else if (layer.type === 'shape') { drawOneShapeLayer(drawCtx, layer, frame); }
		});
	}

	/**
	 * Fill + inset math for a border, given the crop frame (canvas px) and
	 * the item's print dimensions in inches. Mirrors the bx/by inset the
	 * PHP renderer computes.
	 */
	function borderInset(frame, design, printDim) {
		if (!design || !design.border || !design.border.enabled) {
			return { bx: 0, by: 0, color: '' };
		}
		var wIn = printDim.w;
		var hIn = printDim.h;
		var bx = Math.round(frame.w * design.border.width_in / wIn);
		var by = Math.round(frame.h * design.border.width_in / hIn);
		bx = Math.min(bx, Math.floor((frame.w - 2) / 2));
		by = Math.min(by, Math.floor((frame.h - 2) / 2));
		return { bx: Math.max(0, bx), by: Math.max(0, by), color: design.border.color || '#ffffff' };
	}

	/* --------------------------------------------------- item cards ui */

	function buildCard(item) {
		var card = document.createElement('div');
		card.className = 'prrint-item';
		card.dataset.itemId = String(item.id);

		card.innerHTML =
			'<div class="prrint-thumb">' +
				'<canvas class="prrint-thumb-canvas"></canvas>' +
				'<span class="prrint-dpi-dot" title=""></span>' +
				'<a class="prrint-download-btn" href="' + esc(item.img.src) + '" download title="' + esc(cfg.i18n.download) + '">⬇</a>' +
				'<button type="button" class="prrint-edit-btn">' + esc(cfg.i18n.edit) + '</button>' +
			'</div>' +
			'<div class="prrint-item-fields">' +
				'<label class="prrint-field"><span>' + esc(cfg.i18n.size) + '</span>' +
					'<select class="prrint-size-select"></select></label>' +
				'<label class="prrint-field"><span>' + esc(cfg.i18n.paper) + '</span>' +
					'<select class="prrint-paper-select"></select></label>' +
				'<div class="prrint-field-row">' +
					'<label class="prrint-border-label"><input type="checkbox" class="prrint-border-check" /> ' + esc(cfg.i18n.whiteBorder) + '</label>' +
					'<div class="prrint-qty" aria-label="' + esc(cfg.i18n.qty) + '">' +
						'<button type="button" class="prrint-qty-dec" aria-label="-">−</button>' +
						'<input type="number" class="prrint-qty-input" value="1" min="1" max="999" inputmode="numeric" />' +
						'<button type="button" class="prrint-qty-inc" aria-label="+">+</button>' +
					'</div>' +
				'</div>' +
				'<div class="prrint-item-footer">' +
					'<span class="prrint-item-price"></span>' +
					'<span class="prrint-item-footer-actions">' +
						'<button type="button" class="prrint-addsize-btn" title="' + esc(cfg.i18n.addAnotherSize) + '">⧉ ' + esc(cfg.i18n.addSize) + '</button>' +
						'<button type="button" class="prrint-remove-btn" aria-label="' + esc(cfg.i18n.remove) + '">🗑</button>' +
					'</span>' +
				'</div>' +
			'</div>';

		var sizeSel = card.querySelector('.prrint-size-select');
		cfg.sizes.forEach(function (s, i) {
			var opt = document.createElement('option');
			opt.value = String(i);
			opt.textContent = s.label + ' — ' + formatPrice(s.price > 0 ? s.price : cfg.basePrice);
			sizeSel.appendChild(opt);
		});
		sizeSel.value = String(item.sizeIdx);

		var paperSel = card.querySelector('.prrint-paper-select');
		cfg.papers.forEach(function (p, i) {
			var opt = document.createElement('option');
			opt.value = String(i);
			opt.textContent = p.label + (p.surcharge > 0 ? ' (+' + formatPrice(p.surcharge) + ')' : '');
			paperSel.appendChild(opt);
		});
		paperSel.value = String(item.paperIdx);
		card.querySelector('.prrint-border-check').checked = !!item.design.border.enabled;

		/* events */
		sizeSel.addEventListener('change', function () {
			item.sizeIdx = Number(this.value) || 0;
			refitCrop(item);
			renderCard(item);
			updateSummary();
		});
		paperSel.addEventListener('change', function () {
			item.paperIdx = Number(this.value) || 0;
			renderCard(item);
			updateSummary();
		});
		card.querySelector('.prrint-border-check').addEventListener('change', function () {
			item.design.border.enabled = this.checked;
			if (this.checked && !item.design.border.color) {
				item.design.border.color = '#ffffff';
			}
			renderCard(item);
		});
		var qtyInput = card.querySelector('.prrint-qty-input');
		card.querySelector('.prrint-qty-dec').addEventListener('click', function () {
			item.qty = Math.max(1, item.qty - 1);
			qtyInput.value = String(item.qty);
			renderCard(item);
			updateSummary();
		});
		card.querySelector('.prrint-qty-inc').addEventListener('click', function () {
			item.qty = Math.min(999, item.qty + 1);
			qtyInput.value = String(item.qty);
			renderCard(item);
			updateSummary();
		});
		qtyInput.addEventListener('change', function () {
			item.qty = Math.max(1, Math.min(999, Number(this.value) || 1));
			this.value = String(item.qty);
			renderCard(item);
			updateSummary();
		});
		card.querySelector('.prrint-remove-btn').addEventListener('click', function () {
			items = items.filter(function (it) { return it.id !== item.id; });
			card.remove();
			updateSummary();
		});
		card.querySelector('.prrint-addsize-btn').addEventListener('click', function () {
			duplicateItemForNewSize(item);
		});
		card.querySelector('.prrint-edit-btn').addEventListener('click', function () {
			openEditor(item);
		});
		card.querySelector('.prrint-thumb-canvas').addEventListener('click', function () {
			openEditor(item);
		});

		item.card = card;
		els.items.appendChild(card);
		renderCard(item);
	}

	/**
	 * "Order this same photo in another size" — adds a second independent
	 * card sharing the same upload token (and thus the same source file
	 * server-side), with its own size/crop/design so it becomes its own
	 * cart line. Cheaper to build correctly this way than restructuring
	 * the whole studio around a single photo with many size/qty rows.
	 */
	function duplicateItemForNewSize(source) {
		var newSizeIdx = source.sizeIdx;
		if (cfg.sizes.length > 1) {
			newSizeIdx = (source.sizeIdx + 1) % cfg.sizes.length;
		}
		var item = {
			id: nextId++,
			token: source.token,
			img: source.img,
			natW: source.natW,
			natH: source.natH,
			rot: source.rot,
			orientation: source.orientation,
			sizeIdx: newSizeIdx,
			paperIdx: source.paperIdx,
			qty: 1,
			design: cloneDesign(source.design),
			crop: { x: source.crop.x, y: source.crop.y, w: source.crop.w, h: source.crop.h },
			ready: true,
			card: null,
			_drawingImg: source._drawingImg || null
		};
		refitCrop(item);
		items.push(item);
		buildCard(item);
		updateSummary();
		if (item.card && item.card.scrollIntoView) {
			item.card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
		}
	}

	function esc(s) {
		var d = document.createElement('div');
		d.textContent = s;
		return d.innerHTML;
	}

	/**
	 * Redraw one card: thumbnail canvas (photo + filter/adjust + border +
	 * text layers), price, dpi dot.
	 */
	function renderCard(item) {
		var card = item.card;
		if (!card) { return; }

		var canvas = card.querySelector('.prrint-thumb-canvas');
		var p = printDims(item);
		var TW = 440; // retina-ish backing store
		var TH = Math.round(TW * p.h / p.w);
		canvas.width = TW;
		canvas.height = TH;

		var c = canvas.getContext('2d');
		var frame = { x: 0, y: 0, w: TW, h: TH };
		var inset = borderInset(frame, item.design, p);

		c.fillStyle = inset.color || '#ffffff';
		c.fillRect(0, 0, TW, TH);

		var bx = inset.bx, by = inset.by;
		var innerW = TW - 2 * bx;
		var innerH = TH - 2 * by;

		var d = rotatedDims(item);
		var s = innerW / item.crop.w;

		c.save();
		c.beginPath();
		c.rect(bx, by, innerW, innerH);
		c.clip();
		c.filter = designFilterCss(item.design);
		c.translate(bx, by);
		c.scale(s, s);
		c.translate(-item.crop.x, -item.crop.y);
		c.translate(d.w / 2, d.h / 2);
		c.rotate(item.rot * Math.PI / 2);
		c.scale(item.design.flipH ? -1 : 1, item.design.flipV ? -1 : 1);
		c.drawImage(item.img, -item.natW / 2, -item.natH / 2);
		c.restore();

		c.filter = 'none';

		applyFocusBlur(c, item.design, frame, bx, by, TW, TH, function (octx) {
			octx.save();
			octx.translate(bx, by);
			octx.scale(s, s);
			octx.translate(-item.crop.x, -item.crop.y);
			octx.translate(d.w / 2, d.h / 2);
			octx.rotate(item.rot * Math.PI / 2);
			octx.scale(item.design.flipH ? -1 : 1, item.design.flipV ? -1 : 1);
			octx.drawImage(item.img, -item.natW / 2, -item.natH / 2);
			octx.restore();
		});

		if (item.design.overlay) {
			var cardOverlayImg = OVERLAY_IMAGES[item.design.overlay];
			if (cardOverlayImg && cardOverlayImg.complete) {
				c.save();
				c.beginPath();
				c.rect(bx, by, innerW, innerH);
				c.clip();
				c.drawImage(cardOverlayImg, bx, by, innerW, innerH);
				c.restore();
			}
		}

		var drawingSource = (item._drawingImg && item._drawingImg.complete) ? item._drawingImg : null;
		drawDesignOverlay(c, item.design, frame, drawingSource);

		// Price line.
		var unit = unitPrice(item);
		card.querySelector('.prrint-item-price').textContent =
			item.qty > 1
				? item.qty + ' × ' + formatPrice(unit) + ' = ' + formatPrice(unit * item.qty)
				: formatPrice(unit) + ' ' + cfg.i18n.each;

		// DPI dot.
		var dot = card.querySelector('.prrint-dpi-dot');
		var dpi = itemDpi(item);
		dot.classList.remove('prrint-dot-good', 'prrint-dot-ok', 'prrint-dot-low');
		if (dpi >= cfg.targetDpi) {
			dot.classList.add('prrint-dot-good');
			dot.title = cfg.i18n.dpiGood + ' · ' + Math.round(dpi) + ' DPI';
		} else if (dpi >= cfg.minDpi) {
			dot.classList.add('prrint-dot-ok');
			dot.title = cfg.i18n.dpiOk + ' · ' + Math.round(dpi) + ' DPI';
		} else {
			dot.classList.add('prrint-dot-low');
			dot.title = cfg.i18n.dpiLow + ' · ' + Math.round(dpi) + ' DPI';
		}
	}

	function updateSummary() {
		var totalPrints = 0;
		var totalPrice = 0;
		items.forEach(function (item) {
			if (!item.ready) { return; }
			totalPrints += item.qty;
			totalPrice += unitPrice(item) * item.qty;
		});

		if (totalPrints === 0) {
			els.summary.hidden = true;
			return;
		}
		els.summary.hidden = false;
		els.count.textContent = cfg.i18n.printCount.replace('%d', String(totalPrints));
		els.total.textContent = cfg.i18n.total + ': ' + formatPrice(totalPrice);
	}

	/* --------------------------------------------------------- uploads */

	/**
	 * POST an AJAX action with FormData and parse the JSON response —
	 * shared by every prrint_* call that isn't the file-upload XHR (which
	 * needs its own progress-tracking instance).
	 */
	function postAjax(action, fields, onSuccess, onError) {
		var fd = new FormData();
		fd.append('action', action);
		fd.append('nonce', cfg.nonce);
		Object.keys(fields || {}).forEach(function (key) {
			fd.append(key, fields[key]);
		});

		var xhr = new XMLHttpRequest();
		xhr.open('POST', cfg.ajaxUrl);
		xhr.onload = function () {
			var resp = null;
			try { resp = JSON.parse(xhr.responseText); } catch (err) { /* noop */ }
			if (resp && resp.success) {
				onSuccess(resp.data);
			} else {
				onError((resp && resp.data && resp.data.message) || '');
			}
		};
		xhr.onerror = function () { onError(''); };
		xhr.send(fd);
	}

	/**
	 * Build a new item card from an upload-shaped response ({ token, url,
	 * width, height }) — used both for a freshly uploaded file and for
	 * "Use this photo" on a saved library photo (which returns the exact
	 * same shape, just pointing at the library's own permanent file).
	 */
	function createItemFromUpload(data, placeholder) {
		var img = new Image();
		img.onload = function () {
			if (placeholder) { placeholder.remove(); }

			var item = {
				id: nextId++,
				token: data.token,
				img: img,
				natW: img.naturalWidth,
				natH: img.naturalHeight,
				rot: 0,
				orientation: img.naturalWidth >= img.naturalHeight ? 'landscape' : 'portrait',
				sizeIdx: defaultSize,
				paperIdx: defaultPaper,
				qty: 1,
				design: newDesign(),
				crop: null,
				ready: true,
				card: null
			};
			item.crop = centeredCrop(item);
			items.push(item);
			buildCard(item);
			updateSummary();
			if (item.card && item.card.scrollIntoView) {
				item.card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
			}
		};
		img.onerror = function () {
			if (placeholder) { placeholder.remove(); }
			toast(cfg.i18n.uploadError, true);
		};
		img.src = data.url;
	}

	function handleFiles(fileList) {
		Array.prototype.slice.call(fileList).forEach(function (file) {
			uploadFile(file);
		});
	}

	function uploadFile(file) {
		var okTypes = ['image/jpeg', 'image/png', 'image/webp'];
		if (okTypes.indexOf(file.type) === -1) {
			toast(cfg.i18n.badType, true);
			return;
		}
		if (file.size > cfg.maxMb * 1048576) {
			toast(cfg.i18n.tooLarge + ' (max ' + cfg.maxMb + ' MB)', true);
			return;
		}

		// Placeholder card with progress.
		var ph = document.createElement('div');
		ph.className = 'prrint-item prrint-item-loading';
		ph.innerHTML =
			'<div class="prrint-loading-box">' +
				'<div class="prrint-spinner"></div>' +
				'<div class="prrint-progress"><div class="prrint-progress-bar"></div></div>' +
				'<span class="prrint-loading-text">' + esc(cfg.i18n.uploading) + '</span>' +
			'</div>';
		els.items.appendChild(ph);
		var bar = ph.querySelector('.prrint-progress-bar');

		var fd = new FormData();
		fd.append('action', 'prrint_upload');
		fd.append('nonce', cfg.nonce);
		fd.append('file', file);

		var xhr = new XMLHttpRequest();
		xhr.open('POST', cfg.ajaxUrl);
		xhr.upload.onprogress = function (e) {
			if (e.lengthComputable) {
				bar.style.width = Math.round((e.loaded / e.total) * 100) + '%';
			}
		};
		xhr.onload = function () {
			var resp = null;
			try { resp = JSON.parse(xhr.responseText); } catch (err) { /* noop */ }

			if (!resp || !resp.success) {
				ph.remove();
				toast((resp && resp.data && resp.data.message) || cfg.i18n.uploadError, true);
				return;
			}
			createItemFromUpload(resp.data, ph);
			refreshLibrary();
		};
		xhr.onerror = function () {
			ph.remove();
			toast(cfg.i18n.uploadError, true);
		};
		xhr.send(fd);
	}

	/* ------------------------------------------------------ your photos */

	function libraryTileMarkup(photo) {
		return '<div class="prrint-library-tile" data-id="' + esc(photo.id) + '">' +
			'<img src="' + esc(photo.preview) + '" alt="" loading="lazy" />' +
			'<button type="button" class="prrint-library-use" data-id="' + esc(photo.id) + '">' + esc(cfg.i18n.usePhoto || 'Use this photo') + '</button>' +
			'<div class="prrint-library-tile-actions">' +
				'<a href="' + esc(photo.file) + '" class="prrint-library-download" download title="' + esc(cfg.i18n.download) + '">⬇</a>' +
				'<button type="button" class="prrint-library-delete" data-id="' + esc(photo.id) + '" title="' + esc(cfg.i18n.remove) + '">🗑</button>' +
			'</div>' +
		'</div>';
	}

	function renderLibraryGrid(photos) {
		if (!els.libraryGrid) { return; }
		els.libraryGrid.innerHTML = photos.map(libraryTileMarkup).join('');
		els.libraryGrid.hidden = photos.length === 0;
		if (els.libraryEmpty) { els.libraryEmpty.hidden = photos.length > 0; }
	}

	function refreshLibrary() {
		if (!els.library) { return; }
		postAjax('prrint_get_library', {}, function (data) {
			renderLibraryGrid(data.photos || []);
		}, function () { /* silent — the existing list just stays as-is */ });
	}

	if (els.libraryRefresh) {
		els.libraryRefresh.addEventListener('click', function () {
			els.libraryRefresh.disabled = true;
			var original = els.libraryRefresh.textContent;
			els.libraryRefresh.textContent = cfg.i18n.refreshing;
			postAjax('prrint_get_library', {}, function (data) {
				renderLibraryGrid(data.photos || []);
				els.libraryRefresh.disabled = false;
				els.libraryRefresh.textContent = original;
			}, function (message) {
				toast(message || cfg.i18n.uploadError, true);
				els.libraryRefresh.disabled = false;
				els.libraryRefresh.textContent = original;
			});
		});
	}

	if (els.libraryGrid) {
		els.libraryGrid.addEventListener('click', function (e) {
			var useBtn = e.target.closest('.prrint-library-use');
			if (useBtn) {
				useBtn.disabled = true;
				postAjax('prrint_use_library_photo', { id: useBtn.dataset.id }, function (data) {
					createItemFromUpload(data, null);
					useBtn.disabled = false;
				}, function (message) {
					toast(message || cfg.i18n.uploadError, true);
					useBtn.disabled = false;
				});
				return;
			}

			var delBtn = e.target.closest('.prrint-library-delete');
			if (delBtn) {
				if (!window.confirm(cfg.i18n.confirmDeletePhoto)) { return; }
				delBtn.disabled = true;
				postAjax('prrint_delete_photo', { id: delBtn.dataset.id }, function () {
					var tile = delBtn.closest('.prrint-library-tile');
					if (tile) { tile.remove(); }
					if (els.libraryGrid.children.length === 0) {
						els.libraryGrid.hidden = true;
						if (els.libraryEmpty) { els.libraryEmpty.hidden = false; }
					}
					toast(cfg.i18n.photoDeleted, false);
				}, function (message) {
					toast(message || cfg.i18n.uploadError, true);
					delBtn.disabled = false;
				});
			}
		});
	}

	/* dropzone wiring */
	els.dropzone.addEventListener('click', function () { els.fileInput.click(); });
	els.dropzone.addEventListener('keydown', function (e) {
		if (e.key === 'Enter' || e.key === ' ') {
			e.preventDefault();
			els.fileInput.click();
		}
	});
	els.fileInput.addEventListener('change', function () {
		handleFiles(this.files);
		this.value = '';
	});
	['dragenter', 'dragover'].forEach(function (evt) {
		els.dropzone.addEventListener(evt, function (e) {
			e.preventDefault();
			els.dropzone.classList.add('prrint-dz-hover');
		});
	});
	['dragleave', 'drop'].forEach(function (evt) {
		els.dropzone.addEventListener(evt, function (e) {
			e.preventDefault();
			els.dropzone.classList.remove('prrint-dz-hover');
		});
	});
	els.dropzone.addEventListener('drop', function (e) {
		if (e.dataTransfer && e.dataTransfer.files.length) {
			handleFiles(e.dataTransfer.files);
		}
	});

	/* ---------------------------------------------------------- editor */

	var ed = {
		item: null,          // item being edited
		rot: 0,
		orientation: 'portrait',
		sizeIdx: 0,
		design: newDesign(),
		selectedLayerId: null,
		activeTool: 'transform',
		scale: 1,
		minScale: 1,
		maxScale: 1,
		keepResolution: false,
		off: { x: 0, y: 0 },
		cssW: 0,
		cssH: 0,
		frame: { x: 0, y: 0, w: 0, h: 0 },
		drawCanvas: null,       // offscreen canvas the "Draw" tool paints on
		drawCtx: null,
		hasDrawing: false,
		brushColor: '#000000',
		brushSize: 4,
		history: [],            // JSON snapshots of {design, rot, orientation, sizeIdx}
		historyIndex: -1
	};

	function edRotatedDims() {
		var it = ed.item;
		return (ed.rot % 2) ? { w: it.natH, h: it.natW } : { w: it.natW, h: it.natH };
	}

	function edPrintDims() {
		var s = cfg.sizes[ed.sizeIdx];
		var lo = Math.min(s.w, s.h);
		var hi = Math.max(s.w, s.h);
		return ed.orientation === 'landscape' ? { w: hi, h: lo } : { w: lo, h: hi };
	}

	function edLayout() {
		// Full-viewport dark editor: fill whatever space the canvas-wrap has
		// (minus a little breathing room), rather than the old small-card cap.
		var wrap = els.canvas.parentElement;
		var availW = Math.max(280, (wrap.clientWidth || 800) - 32);
		var availH = Math.max(280, (wrap.clientHeight || 600) - 32);
		var w = Math.min(1400, availW);
		var h = Math.min(1000, availH);
		ed.cssW = w;
		ed.cssH = h;

		var dpr = window.devicePixelRatio || 1;
		els.canvas.width = Math.round(ed.cssW * dpr);
		els.canvas.height = Math.round(ed.cssH * dpr);
		els.canvas.style.width = ed.cssW + 'px';
		els.canvas.style.height = ed.cssH + 'px';
		ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

		edFrame();
	}

	function edFrame() {
		var p = edPrintDims();
		var aspect = p.w / p.h;
		var maxW = ed.cssW * 0.88;
		var maxH = ed.cssH * 0.88;
		var fw = maxW;
		var fh = fw / aspect;
		if (fh > maxH) { fh = maxH; fw = fh * aspect; }
		ed.frame = { x: (ed.cssW - fw) / 2, y: (ed.cssH - fh) / 2, w: fw, h: fh };
	}

	function edClamp() {
		var d = edRotatedDims();
		var maxX = Math.max(0, (d.w * ed.scale - ed.frame.w) / 2);
		var maxY = Math.max(0, (d.h * ed.scale - ed.frame.h) / 2);
		ed.off.x = Math.max(-maxX, Math.min(maxX, ed.off.x));
		ed.off.y = Math.max(-maxY, Math.min(maxY, ed.off.y));
	}

	/**
	 * Set the editor view (scale/offset) to show a given crop rect.
	 */
	function edViewFromCrop(crop) {
		var d = edRotatedDims();
		edFrame();
		ed.minScale = Math.max(ed.frame.w / d.w, ed.frame.h / d.h);
		ed.maxScale = ed.minScale * 5;
		ed.scale = Math.max(ed.minScale, Math.min(ed.maxScale, ed.frame.w / crop.w));
		ed.off.x = (d.w / 2 - (crop.x + crop.w / 2)) * ed.scale;
		ed.off.y = (d.h / 2 - (crop.y + crop.h / 2)) * ed.scale;
		edClamp();
		els.zoom.value = String(Math.round(((ed.scale - ed.minScale) / (ed.maxScale - ed.minScale || 1)) * 100));
		if (els.zoomPct) { els.zoomPct.textContent = els.zoom.value + '%'; }
	}

	function edFit() {
		var d = edRotatedDims();
		edFrame();
		ed.minScale = Math.max(ed.frame.w / d.w, ed.frame.h / d.h);
		ed.maxScale = ed.minScale * 5;
		ed.scale = ed.minScale;
		ed.off.x = 0;
		ed.off.y = 0;
		els.zoom.value = '0';
		if (els.zoomPct) { els.zoomPct.textContent = '0%'; }
		edClamp();
	}

	function edExportCrop() {
		var d = edRotatedDims();
		var f = ed.frame;
		var sx = (d.w * ed.scale / 2 - f.w / 2 - ed.off.x) / ed.scale;
		var sy = (d.h * ed.scale / 2 - f.h / 2 - ed.off.y) / ed.scale;
		var sw = f.w / ed.scale;
		var sh = f.h / ed.scale;

		sx = Math.max(0, Math.min(sx, d.w - 1));
		sy = Math.max(0, Math.min(sy, d.h - 1));
		sw = Math.min(sw, d.w - sx);
		sh = Math.min(sh, d.h - sy);

		return {
			x: Math.round(sx * 100) / 100,
			y: Math.round(sy * 100) / 100,
			w: Math.round(sw * 100) / 100,
			h: Math.round(sh * 100) / 100
		};
	}

	function edDraw() {
		var it = ed.item;
		if (!it) { return; }
		ctx.clearRect(0, 0, ed.cssW, ed.cssH);

		var f = ed.frame;
		var inset = borderInset(f, ed.design, edPrintDims());
		var bx = inset.bx, by = inset.by;

		if (inset.color) {
			ctx.fillStyle = inset.color;
			ctx.fillRect(f.x, f.y, f.w, f.h);
		}

		var cx = f.x + bx + (f.w - 2 * bx) / 2 + ed.off.x * ((f.w - 2 * bx) / f.w);
		var cy = f.y + by + (f.h - 2 * by) / 2 + ed.off.y * ((f.h - 2 * by) / f.h);
		var innerScale = ed.scale * ((f.w - 2 * bx) / f.w);

		ctx.save();
		ctx.beginPath();
		ctx.rect(f.x + bx, f.y + by, f.w - 2 * bx, f.h - 2 * by);
		ctx.clip();
		ctx.filter = designFilterCss(ed.design);
		ctx.translate(cx, cy);
		ctx.rotate(ed.rot * Math.PI / 2);
		ctx.scale(ed.design.flipH ? -1 : 1, ed.design.flipV ? -1 : 1);
		ctx.scale(innerScale, innerScale);
		ctx.drawImage(it.img, -it.natW / 2, -it.natH / 2);
		ctx.restore();
		ctx.filter = 'none';

		applyFocusBlur(ctx, ed.design, f, bx, by, ed.cssW, ed.cssH, function (octx) {
			octx.save();
			octx.translate(cx, cy);
			octx.rotate(ed.rot * Math.PI / 2);
			octx.scale(ed.design.flipH ? -1 : 1, ed.design.flipV ? -1 : 1);
			octx.scale(innerScale, innerScale);
			octx.drawImage(it.img, -it.natW / 2, -it.natH / 2);
			octx.restore();
		});

		if (ed.design.overlay) {
			var edOverlayImg = OVERLAY_IMAGES[ed.design.overlay];
			if (edOverlayImg && edOverlayImg.complete) {
				ctx.save();
				ctx.beginPath();
				ctx.rect(f.x + bx, f.y + by, f.w - 2 * bx, f.h - 2 * by);
				ctx.clip();
				ctx.drawImage(edOverlayImg, f.x + bx, f.y + by, f.w - 2 * bx, f.h - 2 * by);
				ctx.restore();
			}
		}

		drawDesignOverlay(ctx, ed.design, f, ed.drawCanvas);
		if (ed.activeTool === 'text' || ed.activeTool === 'elements') {
			drawLayerHandles();
		} else {
			hideLayerToolbar();
		}

		// Dim outside the frame + white frame stroke + rule-of-thirds guides.
		ctx.fillStyle = 'rgba(15,15,20,0.6)';
		ctx.fillRect(0, 0, ed.cssW, f.y);
		ctx.fillRect(0, f.y + f.h, ed.cssW, ed.cssH - f.y - f.h);
		ctx.fillRect(0, f.y, f.x, f.h);
		ctx.fillRect(f.x + f.w, f.y, ed.cssW - f.x - f.w, f.h);

		ctx.strokeStyle = '#ffffff';
		ctx.lineWidth = 2;
		ctx.strokeRect(f.x + 1, f.y + 1, f.w - 2, f.h - 2);

		if (ed.activeTool === 'transform') {
			ctx.strokeStyle = 'rgba(255,255,255,0.35)';
			ctx.lineWidth = 1;
			ctx.beginPath();
			for (var i = 1; i <= 2; i++) {
				ctx.moveTo(f.x + (f.w * i) / 3, f.y);
				ctx.lineTo(f.x + (f.w * i) / 3, f.y + f.h);
				ctx.moveTo(f.x, f.y + (f.h * i) / 3);
				ctx.lineTo(f.x + f.w, f.y + (f.h * i) / 3);
			}
			ctx.stroke();

			drawRulers(f);
		}
	}

	/**
	 * Inch/pixel ruler along the top and left of the print frame — always
	 * describes the fixed print output (frame size never changes with
	 * zoom/pan, only what's visible inside it does), so the tick math only
	 * depends on the selected size/orientation, not the current zoom.
	 * "Pixel" mode ticks are print-output pixels at the configured target
	 * DPI, the same frame of reference "inch" mode uses (the print), not
	 * the source photo's own resolution.
	 */
	function drawRulers(f) {
		var RULER = 20;
		if (f.x < RULER || f.y < RULER) { return; } // not enough margin to draw without clipping

		var p = edPrintDims();
		var unit = cfg.scaleUnit === 'px' ? 'px' : 'in';
		var totalW = unit === 'px' ? p.w * cfg.targetDpi : p.w;
		var totalH = unit === 'px' ? p.h * cfg.targetDpi : p.h;
		var pxPerUnitX = f.w / totalW;
		var pxPerUnitY = f.h / totalH;

		var candidates = unit === 'px' ? [50, 100, 250, 500, 1000, 2000] : [0.25, 0.5, 1, 2, 5, 10];
		var interval = candidates[candidates.length - 1];
		for (var i = 0; i < candidates.length; i++) {
			if (candidates[i] * pxPerUnitX >= 40) { interval = candidates[i]; break; }
		}

		function formatUnit(v) {
			if (unit === 'px') { return String(Math.round(v)); }
			return (Math.round(v * 100) / 100) % 1 === 0 ? String(v) : v.toFixed(2).replace(/0+$/, '').replace(/\.$/, '');
		}

		ctx.save();
		ctx.fillStyle = 'rgba(11,11,15,0.92)';
		ctx.fillRect(f.x, f.y - RULER, f.w, RULER);
		ctx.fillRect(f.x - RULER, f.y, RULER, f.h);
		ctx.fillRect(f.x - RULER, f.y - RULER, RULER, RULER);

		ctx.strokeStyle = 'rgba(255,255,255,0.5)';
		ctx.fillStyle = 'rgba(255,255,255,0.85)';
		ctx.font = '10px sans-serif';
		ctx.lineWidth = 1;

		ctx.beginPath();
		var xUnit, px;
		for (xUnit = 0; xUnit <= totalW + 0.001; xUnit += interval) {
			px = f.x + xUnit * pxPerUnitX;
			ctx.moveTo(px, f.y - RULER);
			ctx.lineTo(px, f.y - RULER * 0.35);
		}
		ctx.stroke();
		ctx.textAlign = 'left';
		ctx.textBaseline = 'middle';
		for (xUnit = 0; xUnit <= totalW + 0.001; xUnit += interval) {
			px = f.x + xUnit * pxPerUnitX;
			if (px + 22 > f.x + f.w) { continue; }
			ctx.fillText(formatUnit(xUnit), px + 3, f.y - RULER / 2);
		}

		ctx.beginPath();
		var yUnit, py;
		for (yUnit = 0; yUnit <= totalH + 0.001; yUnit += interval) {
			py = f.y + yUnit * pxPerUnitY;
			ctx.moveTo(f.x - RULER, py);
			ctx.lineTo(f.x - RULER * 0.35, py);
		}
		ctx.stroke();
		ctx.save();
		ctx.textAlign = 'center';
		for (yUnit = 0; yUnit <= totalH + 0.001; yUnit += interval) {
			py = f.y + yUnit * pxPerUnitY;
			if (py - 14 < f.y) { continue; }
			ctx.save();
			ctx.translate(f.x - RULER / 2, py - 6);
			ctx.rotate(-Math.PI / 2);
			ctx.fillText(formatUnit(yUnit), 0, 0);
			ctx.restore();
		}
		ctx.restore();

		ctx.restore();
	}

	function edUpdateDpi() {
		var crop = edExportCrop();
		var p = edPrintDims();
		var dpi = Math.min(crop.w / p.w, crop.h / p.h);

		els.dpi.hidden = false;
		els.dpi.classList.remove('prrint-dpi-good', 'prrint-dpi-ok', 'prrint-dpi-low');
		if (dpi >= cfg.targetDpi) {
			els.dpi.textContent = cfg.i18n.dpiGood + ' · ' + Math.round(dpi) + ' DPI';
			els.dpi.classList.add('prrint-dpi-good');
		} else if (dpi >= cfg.minDpi) {
			els.dpi.textContent = cfg.i18n.dpiOk + ' · ' + Math.round(dpi) + ' DPI';
			els.dpi.classList.add('prrint-dpi-ok');
		} else {
			els.dpi.textContent = cfg.i18n.dpiLow + ' · ' + Math.round(dpi) + ' DPI';
			els.dpi.classList.add('prrint-dpi-low');
		}
		syncCropSizeInputs(crop);
	}

	/**
	 * The most the customer can zoom in (in `ed.scale` terms) while keeping
	 * the effective print DPI at or above the configured target — used only
	 * when the "Keep Resolution" toggle is on.
	 */
	function edKeepResMaxScale() {
		var p = edPrintDims();
		var f = ed.frame;
		return Math.max(ed.minScale, f.w / (p.w * cfg.targetDpi));
	}

	function edEffectiveMaxScale() {
		return ed.keepResolution ? Math.min(ed.maxScale, edKeepResMaxScale()) : ed.maxScale;
	}

	/**
	 * Reflect the current crop rectangle (source-photo pixels) into the
	 * numeric "Crop Size" W/H fields, unless the customer is actively typing
	 * in one of them.
	 */
	function syncCropSizeInputs(crop) {
		if (!els.cropW || !els.cropH) { return; }
		if (document.activeElement === els.cropW || document.activeElement === els.cropH) { return; }
		crop = crop || edExportCrop();
		els.cropW.value = String(Math.round(crop.w));
		els.cropH.value = String(Math.round(crop.h));
	}

	/**
	 * Set the crop's source-pixel width (or height); the other dimension
	 * follows automatically since the crop is always locked to the selected
	 * print size's aspect ratio.
	 */
	function setCropWidthPx(px) {
		if (!ed.item || !px || px <= 0) { return; }
		var f = ed.frame;
		ed.scale = Math.max(ed.minScale, Math.min(edEffectiveMaxScale(), f.w / px));
		edClamp();
		edDraw();
		edUpdateDpi();
		els.zoom.value = String(Math.round(((ed.scale - ed.minScale) / (ed.maxScale - ed.minScale || 1)) * 100));
		updateZoomPct();
	}

	function setCropHeightPx(px) {
		if (!ed.item || !px || px <= 0) { return; }
		var f = ed.frame;
		ed.scale = Math.max(ed.minScale, Math.min(edEffectiveMaxScale(), f.h / px));
		edClamp();
		edDraw();
		edUpdateDpi();
		els.zoom.value = String(Math.round(((ed.scale - ed.minScale) / (ed.maxScale - ed.minScale || 1)) * 100));
		updateZoomPct();
	}

	/* --------------------------------------------------------- tool rail */

	function setActiveTool(tool) {
		ed.activeTool = tool;
		if (els.toolRail) {
			els.toolRail.querySelectorAll('.prrint-tool-btn').forEach(function (btn) {
				btn.classList.toggle('is-active', btn.dataset.tool === tool);
			});
		}
		els.toolPanels.forEach(function (panel) {
			panel.hidden = panel.dataset.panel !== tool;
		});
		els.canvas.classList.toggle('prrint-cursor-draw', tool === 'draw');
		els.canvas.classList.toggle('prrint-cursor-crosshair', tool === 'text' || tool === 'elements');
		edDraw();
	}

	if (els.toolRail) {
		els.toolRail.addEventListener('click', function (e) {
			var btn = e.target.closest('.prrint-tool-btn');
			if (btn) { setActiveTool(btn.dataset.tool); }
		});
	}

	/* filters panel */
	if (els.filterGrid) {
		cfg.filters.forEach(function (f) {
			var tile = document.createElement('button');
			tile.type = 'button';
			tile.className = 'prrint-filter-swatch prrint-filter-preview-' + (f.id || 'none');
			tile.dataset.filter = f.id;
			tile.innerHTML = '<span class="prrint-filter-caption">' + esc(f.label) + '</span>';
			tile.addEventListener('click', function () {
				ed.design.filter = f.id;
				els.filterGrid.querySelectorAll('.prrint-filter-swatch').forEach(function (b) {
					b.classList.toggle('is-active', b.dataset.filter === f.id);
				});
				edDraw();
				pushHistory();
			});
			els.filterGrid.appendChild(tile);
		});
	}

	/**
	 * Swap the Filters/Overlays swatch tiles from generic placeholder
	 * gradients to the customer's own photo (with that filter's CSS
	 * approximation applied), so "what will this actually look like" is
	 * visible right in the tool instead of an abstract color chip. Called
	 * whenever the editor opens for a photo.
	 */
	function updateStylePreviewThumbnails() {
		if (!ed.item) { return; }
		var src = ed.item.img.src;

		if (els.filterGrid) {
			els.filterGrid.querySelectorAll('.prrint-filter-swatch').forEach(function (tile) {
				var filterId = tile.dataset.filter;
				tile.style.backgroundImage = 'url(' + src + ')';
				tile.style.backgroundSize = 'cover';
				tile.style.backgroundPosition = 'center';
				tile.style.filter = (filterId && FILTER_CSS[filterId]) ? FILTER_CSS[filterId] : 'none';
			});
		}

		if (els.overlayGrid) {
			els.overlayGrid.querySelectorAll('.prrint-overlay-swatch').forEach(function (tile) {
				var overlayId = tile.dataset.overlay;
				var overlayImg = OVERLAY_IMAGES[overlayId];
				tile.style.backgroundImage = overlayImg && overlayImg.src
					? 'url(' + overlayImg.src + '), url(' + src + ')'
					: 'url(' + src + ')';
				tile.style.backgroundSize = 'cover';
				tile.style.backgroundPosition = 'center';
			});
		}
	}

	/* overlays panel — bundled textures composited only over the photo area
	   (inside any border), applied after Filters/Adjust like a real layer */
	var OVERLAY_IMAGES = {};
	cfg.overlays.forEach(function (o) {
		if (!o.id || !o.url) { return; }
		var img = new Image();
		img.src = o.url;
		OVERLAY_IMAGES[o.id] = img;
	});

	if (els.overlayGrid) {
		cfg.overlays.forEach(function (o) {
			var tile = document.createElement('button');
			tile.type = 'button';
			tile.className = 'prrint-filter-swatch prrint-overlay-swatch' + (o.url ? '' : ' prrint-filter-preview-none');
			tile.dataset.overlay = o.id;
			if (o.url) {
				tile.style.backgroundImage = 'linear-gradient(135deg,#3a3a42,#6f6f7a), url(' + o.url + ')';
			}
			tile.innerHTML = '<span class="prrint-filter-caption">' + esc(o.label) + '</span>';
			tile.addEventListener('click', function () {
				ed.design.overlay = o.id;
				els.overlayGrid.querySelectorAll('.prrint-overlay-swatch').forEach(function (b) {
					b.classList.toggle('is-active', b.dataset.overlay === o.id);
				});
				edDraw();
				pushHistory();
			});
			els.overlayGrid.appendChild(tile);
		});
	}

	/* text design panel — pre-made word-art layouts */
	function makeTemplateLayer(def, tplId) {
		return {
			id: 'l' + (nextLayerId++),
			type: 'text',
			text: def.text,
			fontSize: def.fontSize,
			bold: def.bold,
			align: def.align,
			color: def.color,
			bgColor: def.bgColor,
			lineSpacing: def.lineSpacing,
			x: def.x,
			y: def.y,
			w: def.w,
			rotation: def.rotation,
			_tpl: true,
			_tplId: tplId
		};
	}

	/**
	 * Which template (if any) the current text-design layers came from —
	 * derived from the layers themselves (not separately tracked editor
	 * state) so it stays correct across Undo/Redo, which replaces
	 * ed.design wholesale from a JSON snapshot.
	 */
	function activeTextTemplateId() {
		var l = ed.design.layers.filter(function (l) { return l._tpl; })[0];
		return l ? l._tplId : '';
	}

	function applyTextTemplate(tplId, preserveText) {
		var defs = TEXT_TEMPLATES[tplId];
		if (!defs || !ed.item) { return; }
		var oldTplLayers = ed.design.layers.filter(function (l) { return l._tpl; });
		ed.design.layers = ed.design.layers.filter(function (l) { return !l._tpl; });
		var newLayers = defs.map(function (def, i) {
			var layer = makeTemplateLayer(def, tplId);
			if (preserveText && oldTplLayers[i] && oldTplLayers[i].text) {
				layer.text = oldTplLayers[i].text;
			}
			return layer;
		});
		ed.design.layers = ed.design.layers.concat(newLayers);
		syncTextDesignPanelUI();
		selectLayer(newLayers[0] ? newLayers[0].id : null);
		edDraw();
		pushHistory();
	}

	function syncTextDesignPanelUI() {
		if (!els.textdesignGrid) { return; }
		var activeId = activeTextTemplateId();
		els.textdesignGrid.querySelectorAll('.prrint-filter-swatch').forEach(function (b) {
			b.classList.toggle('is-active', b.dataset.template === activeId);
		});
	}

	function templatePreviewMarkup(defs) {
		return defs.map(function (def) {
			var rot = def.rotation ? ' rotate(' + def.rotation + 'deg)' : '';
			// A layer with no bgColor still needs a visible placeholder bar
			// in this miniature preview, or it'd be an invisible blank spot.
			var bg = def.bgColor || (def.color === '#ffffff' ? 'rgba(255,255,255,.4)' : 'rgba(0,0,0,.4)');
			var barH = Math.max(4, def.fontSize * 60);
			var style = 'position:absolute;left:' + (def.x * 100) + '%;top:' + (def.y * 100) + '%;' +
				'width:' + (def.w * 100) + '%;height:' + barH + 'px;background:' + bg + ';border-radius:2px;' +
				'transform:translate(0,0)' + rot + ';';
			return '<span style="' + style + '"></span>';
		}).join('');
	}

	if (els.textdesignGrid) {
		cfg.textTemplates.forEach(function (t) {
			var tile = document.createElement('button');
			tile.type = 'button';
			tile.className = 'prrint-filter-swatch prrint-filter-preview-none';
			tile.dataset.template = t.id;
			tile.innerHTML = templatePreviewMarkup(TEXT_TEMPLATES[t.id] || []) +
				'<span class="prrint-filter-caption">' + esc(t.label) + '</span>';
			tile.addEventListener('click', function () {
				applyTextTemplate(t.id, false);
			});
			els.textdesignGrid.appendChild(tile);
		});
	}

	if (els.textdesignShuffle) {
		els.textdesignShuffle.addEventListener('click', function () {
			if (!ed.item) { return; }
			var current = activeTextTemplateId();
			var ids = Object.keys(TEXT_TEMPLATES).filter(function (id) { return id !== current; });
			if (!ids.length) { return; }
			var pick = ids[Math.floor(Math.random() * ids.length)];
			applyTextTemplate(pick, true);
		});
	}

	if (els.textdesignInvert) {
		els.textdesignInvert.addEventListener('click', function () {
			if (!ed.item) { return; }
			var any = false;
			ed.design.layers.forEach(function (l) {
				if (!l._tpl) { return; }
				any = true;
				if (l.bgColor) {
					var c = l.color; l.color = l.bgColor; l.bgColor = c;
				} else {
					l.color = (l.color === '#000000') ? '#ffffff' : '#000000';
				}
			});
			if (!any) { toast(cfg.i18n.noTextDesign, true); return; }
			edDraw();
			pushHistory();
		});
	}

	/* adjust panel */
	function edAdjustInput(el, key) {
		if (!el) { return; }
		var out = document.getElementById(el.id + '-out');
		el.addEventListener('input', function () {
			ed.design.adjust[key] = Number(this.value);
			if (out) { out.textContent = this.value; }
			edDraw();
		});
		el.addEventListener('change', pushHistory);
	}
	edAdjustInput(els.adjBrightness, 'brightness');
	edAdjustInput(els.adjContrast, 'contrast');
	edAdjustInput(els.adjSaturation, 'saturation');
	edAdjustInput(els.adjGamma, 'gamma');
	edAdjustInput(els.adjClarity, 'clarity');
	edAdjustInput(els.adjShadows, 'shadows');
	edAdjustInput(els.adjHighlights, 'highlights');
	edAdjustInput(els.adjExposure, 'exposure');
	if (els.adjReset) {
		els.adjReset.addEventListener('click', function () {
			ed.design.adjust = { brightness: 0, contrast: 0, saturation: 100, gamma: 0, exposure: 0, clarity: 0, shadows: 0, highlights: 0 };
			[
				[els.adjBrightness, '0'], [els.adjContrast, '0'], [els.adjSaturation, '100'], [els.adjGamma, '0'],
				[els.adjClarity, '0'], [els.adjShadows, '0'], [els.adjHighlights, '0'], [els.adjExposure, '0']
			].forEach(function (pair) {
				if (!pair[0]) { return; }
				pair[0].value = pair[1];
				var out = document.getElementById(pair[0].id + '-out');
				if (out) { out.textContent = pair[1]; }
			});
			edDraw();
			pushHistory();
		});
	}

	/* border panel */
	function buildSwatchRow(container, colors, onPick) {
		if (!container) { return; }
		container.innerHTML = '';
		colors.forEach(function (color) {
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'prrint-swatch';
			btn.dataset.color = color;
			if (!color) {
				btn.classList.add('prrint-swatch-none');
				btn.title = cfg.i18n.transparent;
			} else {
				btn.style.backgroundColor = color;
			}
			btn.addEventListener('click', function () {
				container.querySelectorAll('.prrint-swatch').forEach(function (b) { b.classList.remove('is-active'); });
				btn.classList.add('is-active');
				onPick(color);
			});
			container.appendChild(btn);
		});
	}

	buildSwatchRow(els.borderSwatches, cfg.borderColors, function (color) {
		ed.design.border.color = color;
		edDraw();
		pushHistory();
	});

	if (els.borderEnable) {
		els.borderEnable.addEventListener('change', function () {
			ed.design.border.enabled = this.checked;
			els.borderFields.hidden = !this.checked;
			edDraw();
			pushHistory();
		});
	}
	if (els.borderWidth) {
		els.borderWidth.addEventListener('input', function () {
			ed.design.border.width_in = Number(this.value) / 100;
			edDraw();
		});
		els.borderWidth.addEventListener('change', pushHistory);
	}

	/* text panel */
	function selectedLayer(type) {
		if (!ed.selectedLayerId) { return null; }
		var l = ed.design.layers.filter(function (x) { return x.id === ed.selectedLayerId; })[0] || null;
		if (l && type && l.type !== type) { return null; }
		return l;
	}

	/* on-canvas floating toolbar (Edit / Move to Front / Duplicate / Delete) —
	   shown above whichever text/shape layer is currently selected */
	function hideLayerToolbar() {
		if (els.layerToolbar) { els.layerToolbar.hidden = true; }
	}

	function positionLayerToolbar(box) {
		if (!els.layerToolbar) { return; }
		var rect = els.canvas.getBoundingClientRect();
		els.layerToolbar.hidden = false;
		els.layerToolbar.style.left = (rect.left + box.x + box.w / 2) + 'px';
		els.layerToolbar.style.top = (rect.top + box.y - 4) + 'px';
	}

	function duplicateSelectedLayer() {
		var l = selectedLayer(null);
		if (!l) { return; }
		var copy = JSON.parse(JSON.stringify(l));
		copy.id = 'l' + (nextLayerId++);
		copy.x = Math.min(0.9, l.x + 0.04);
		copy.y = Math.min(0.9, l.y + 0.04);
		ed.design.layers.push(copy);
		selectLayer(copy.id);
		pushHistory();
	}

	function deleteSelectedLayer() {
		var l = selectedLayer(null);
		if (!l) { return; }
		ed.design.layers = ed.design.layers.filter(function (x) { return x.id !== l.id; });
		ed.selectedLayerId = null;
		showLayerFields(null);
		showShapeFields(null);
		hideLayerToolbar();
		edDraw();
		pushHistory();
	}

	function moveSelectedLayerToFront() {
		var l = selectedLayer(null);
		if (!l) { return; }
		var layers = ed.design.layers;
		var idx = layers.indexOf(l);
		if (idx < 0 || idx === layers.length - 1) { return; }
		layers.splice(idx, 1);
		layers.push(l);
		edDraw();
		pushHistory();
	}

	if (els.layerEdit) {
		els.layerEdit.addEventListener('click', function () {
			var l = selectedLayer(null);
			if (l && l.type === 'text' && els.textContent) { els.textContent.focus(); }
		});
	}
	if (els.layerFront) {
		els.layerFront.addEventListener('click', moveSelectedLayerToFront);
	}
	if (els.layerDuplicate) {
		els.layerDuplicate.addEventListener('click', duplicateSelectedLayer);
	}
	if (els.layerDelete) {
		els.layerDelete.addEventListener('click', deleteSelectedLayer);
	}

	function showLayerFields(layer) {
		if (!layer) {
			els.textFields.hidden = true;
			return;
		}
		els.textFields.hidden = false;
		els.textContent.value = layer.text;
		if (els.textFont) { els.textFont.value = layer.fontFamily || ''; }
		els.textSize.value = String(Math.round(layer.fontSize * 100));
		els.textSpacing.value = String(Math.round(layer.lineSpacing * 10));
		if (els.textSpacingOut) { els.textSpacingOut.textContent = layer.lineSpacing.toFixed(1); }
		els.textWidth.value = String(Math.round(layer.w * 100));
		els.textRotation.value = String(Math.round(layer.rotation));
		els.textBold.classList.toggle('is-active', !!layer.bold);
		els.textFields.querySelectorAll('[data-align]').forEach(function (b) {
			b.classList.toggle('is-active', b.dataset.align === layer.align);
		});
		els.textColorSwatches.querySelectorAll('.prrint-swatch').forEach(function (b) {
			b.classList.toggle('is-active', b.dataset.color === layer.color);
		});
		els.textBgSwatches.querySelectorAll('.prrint-swatch').forEach(function (b) {
			b.classList.toggle('is-active', b.dataset.color === (layer.bgColor || ''));
		});
	}

	function selectLayer(id) {
		ed.selectedLayerId = id;
		var layer = ed.design.layers.filter(function (l) { return l.id === id; })[0] || null;
		if (layer && layer.type === 'shape') {
			showShapeFields(layer);
			showLayerFields(null);
		} else {
			showLayerFields(layer);
			showShapeFields(null);
		}
		edDraw();
	}

	function addLayer() {
		var layer = {
			id: 'l' + (nextLayerId++),
			type: 'text',
			text: cfg.i18n.newTextDefault,
			fontSize: 0.08,
			fontFamily: '',
			bold: false,
			align: 'center',
			color: '#ffffff',
			bgColor: '',
			lineSpacing: 1.3,
			x: 0.15,
			y: 0.4,
			w: 0.7,
			rotation: 0
		};
		ed.design.layers.push(layer);
		selectLayer(layer.id);
		pushHistory();
	}

	if (els.textAdd) {
		els.textAdd.addEventListener('click', addLayer);
	}
	if (els.textContent) {
		els.textContent.addEventListener('input', function () {
			var l = selectedLayer('text');
			if (l) { l.text = this.value; edDraw(); }
		});
		els.textContent.addEventListener('change', pushHistory);
	}
	if (els.textSize) {
		els.textSize.addEventListener('input', function () {
			var l = selectedLayer('text');
			if (l) { l.fontSize = Number(this.value) / 100; edDraw(); }
		});
		els.textSize.addEventListener('change', pushHistory);
	}
	if (els.textFont) {
		els.textFont.addEventListener('change', function () {
			var l = selectedLayer('text');
			if (!l) { return; }
			l.fontFamily = sanitizeFontFamily(this.value);
			this.value = l.fontFamily;
			edDraw();
			var item = ed.item;
			ensureGoogleFont(l.fontFamily, function () {
				if (ed.item === item) { edDraw(); }
				if (item) { renderCard(item); }
			});
			pushHistory();
		});
	}
	if (els.textSpacing) {
		els.textSpacing.addEventListener('input', function () {
			var l = selectedLayer('text');
			if (!l) { return; }
			l.lineSpacing = Number(this.value) / 10;
			if (els.textSpacingOut) { els.textSpacingOut.textContent = l.lineSpacing.toFixed(1); }
			edDraw();
		});
		els.textSpacing.addEventListener('change', pushHistory);
	}
	if (els.textWidth) {
		els.textWidth.addEventListener('input', function () {
			var l = selectedLayer('text');
			if (l) { l.w = Number(this.value) / 100; edDraw(); }
		});
		els.textWidth.addEventListener('change', pushHistory);
	}
	if (els.textRotation) {
		els.textRotation.addEventListener('input', function () {
			var l = selectedLayer('text');
			if (l) { l.rotation = Number(this.value); edDraw(); }
		});
		els.textRotation.addEventListener('change', pushHistory);
	}
	if (els.textBold) {
		els.textBold.addEventListener('click', function () {
			var l = selectedLayer('text');
			if (!l) { return; }
			l.bold = !l.bold;
			els.textBold.classList.toggle('is-active', l.bold);
			edDraw();
			pushHistory();
		});
	}
	if (els.textFields) {
		els.textFields.querySelectorAll('[data-align]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var l = selectedLayer('text');
				if (!l) { return; }
				l.align = btn.dataset.align;
				els.textFields.querySelectorAll('[data-align]').forEach(function (b) {
					b.classList.toggle('is-active', b === btn);
				});
				edDraw();
				pushHistory();
			});
		});
	}
	buildSwatchRow(els.textColorSwatches, cfg.textColors, function (color) {
		var l = selectedLayer('text');
		if (l) { l.color = color; edDraw(); pushHistory(); }
	});
	buildSwatchRow(els.textBgSwatches, cfg.textBgColors, function (color) {
		var l = selectedLayer('text');
		if (l) { l.bgColor = color; edDraw(); pushHistory(); }
	});
	if (els.textDuplicate) {
		els.textDuplicate.addEventListener('click', function () {
			var l = selectedLayer('text');
			if (!l) { toast(cfg.i18n.noTextLayer, true); return; }
			var copy = JSON.parse(JSON.stringify(l));
			copy.id = 'l' + (nextLayerId++);
			copy.x = Math.min(0.9, l.x + 0.04);
			copy.y = Math.min(0.9, l.y + 0.04);
			ed.design.layers.push(copy);
			selectLayer(copy.id);
			pushHistory();
		});
	}
	if (els.textDelete) {
		els.textDelete.addEventListener('click', function () {
			var l = selectedLayer('text');
			if (!l) { toast(cfg.i18n.noTextLayer, true); return; }
			ed.design.layers = ed.design.layers.filter(function (x) { return x.id !== l.id; });
			ed.selectedLayerId = null;
			showLayerFields(null);
			edDraw();
			pushHistory();
		});
	}

	/* elements (shape sticker) panel */
	function showShapeFields(layer) {
		if (!layer) {
			els.shapeFields.hidden = true;
			return;
		}
		els.shapeFields.hidden = false;
		els.shapeSize.value = String(Math.round(layer.w * 100));
		els.shapeRotation.value = String(Math.round(layer.rotation));
		els.shapeColorSwatches.querySelectorAll('.prrint-swatch').forEach(function (b) {
			b.classList.toggle('is-active', b.dataset.color === layer.color);
		});
		if (els.shapeGrid) {
			els.shapeGrid.querySelectorAll('.prrint-shape-btn').forEach(function (b) {
				b.classList.toggle('is-active', b.dataset.shape === layer.shape);
			});
		}
	}

	function addShapeLayer(shapeId) {
		var layer = {
			id: 'l' + (nextLayerId++),
			type: 'shape',
			shape: shapeId,
			color: '#f43f5e',
			x: 0.4,
			y: 0.4,
			w: 0.2,
			h: 0.2,
			rotation: 0
		};
		ed.design.layers.push(layer);
		selectLayer(layer.id);
		pushHistory();
	}

	if (els.shapeGrid) {
		cfg.shapes.forEach(function (s) {
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'prrint-shape-btn';
			btn.dataset.shape = s.id;
			btn.textContent = s.label;
			btn.title = s.id;
			btn.addEventListener('click', function () { addShapeLayer(s.id); });
			els.shapeGrid.appendChild(btn);
		});
	}
	if (els.shapeSize) {
		els.shapeSize.addEventListener('input', function () {
			var l = selectedLayer('shape');
			if (l) { l.w = l.h = Number(this.value) / 100; edDraw(); }
		});
		els.shapeSize.addEventListener('change', pushHistory);
	}
	if (els.shapeRotation) {
		els.shapeRotation.addEventListener('input', function () {
			var l = selectedLayer('shape');
			if (l) { l.rotation = Number(this.value); edDraw(); }
		});
		els.shapeRotation.addEventListener('change', pushHistory);
	}
	buildSwatchRow(els.shapeColorSwatches, cfg.shapeColors, function (color) {
		var l = selectedLayer('shape');
		if (l) { l.color = color; edDraw(); pushHistory(); }
	});
	if (els.shapeDuplicate) {
		els.shapeDuplicate.addEventListener('click', function () {
			var l = selectedLayer('shape');
			if (!l) { toast(cfg.i18n.noShapeLayer, true); return; }
			var copy = JSON.parse(JSON.stringify(l));
			copy.id = 'l' + (nextLayerId++);
			copy.x = Math.min(0.9, l.x + 0.04);
			copy.y = Math.min(0.9, l.y + 0.04);
			ed.design.layers.push(copy);
			selectLayer(copy.id);
			pushHistory();
		});
	}
	if (els.shapeDelete) {
		els.shapeDelete.addEventListener('click', function () {
			var l = selectedLayer('shape');
			if (!l) { toast(cfg.i18n.noShapeLayer, true); return; }
			ed.design.layers = ed.design.layers.filter(function (x) { return x.id !== l.id; });
			ed.selectedLayerId = null;
			showShapeFields(null);
			edDraw();
			pushHistory();
		});
	}

	/* draw (freehand doodle) panel */
	buildSwatchRow(els.drawColorSwatches, cfg.drawColors, function (color) {
		ed.brushColor = color;
	});
	if (els.drawSize) {
		els.drawSize.addEventListener('input', function () {
			ed.brushSize = Number(this.value);
		});
	}
	if (els.drawClear) {
		els.drawClear.addEventListener('click', function () {
			if (!ed.drawCanvas) { return; }
			ed.drawCtx.clearRect(0, 0, ed.drawCanvas.width, ed.drawCanvas.height);
			ed.hasDrawing = false;
			edDraw();
		});
	}

	function createDrawCanvas() {
		var p = edPrintDims();
		var maxDim = 900;
		var w, h;
		if (p.w >= p.h) { w = maxDim; h = Math.round(maxDim * p.h / p.w); }
		else { h = maxDim; w = Math.round(maxDim * p.w / p.h); }
		var c = document.createElement('canvas');
		c.width = w;
		c.height = h;
		return c;
	}

	function canvasToDrawCoords(pt) {
		var f = ed.frame;
		return {
			x: ((pt.x - f.x) / f.w) * ed.drawCanvas.width,
			y: ((pt.y - f.y) / f.h) * ed.drawCanvas.height
		};
	}

	function brushPx() {
		return Math.max(1, ed.brushSize * (ed.drawCanvas.width / 300));
	}

	/* layer selection handles (drawn while the Text/Elements tool is active) */
	function drawLayerHandles() {
		var type = ed.activeTool === 'elements' ? 'shape' : 'text';
		var sel = selectedLayer(type);
		if (!sel) { hideLayerToolbar(); return; }
		var box = layerBoundingBox(ctx, sel, ed.frame);
		ctx.save();
		ctx.strokeStyle = '#4f46e5';
		ctx.lineWidth = 1.5;
		ctx.setLineDash([4, 3]);
		ctx.strokeRect(box.x - 4, box.y - 4, box.w + 8, box.h + 8);
		ctx.restore();
		positionLayerToolbar(box);
	}

	function hitTestLayer(px, py) {
		var type = ed.activeTool === 'elements' ? 'shape' : (ed.activeTool === 'text' ? 'text' : null);
		if (!type) { return null; }
		var layers = ed.design.layers;
		for (var i = layers.length - 1; i >= 0; i--) {
			if (layers[i].type !== type) { continue; }
			var box = layerBoundingBox(ctx, layers[i], ed.frame);
			if (px >= box.x - 4 && px <= box.x + box.w + 4 && py >= box.y - 4 && py <= box.y + box.h + 4) {
				return layers[i];
			}
		}
		return null;
	}

	/* ------------------------------------------------------- undo / redo */
	// Tracks design/rotation/orientation/size — not crop/zoom/pan, which
	// stay live camera state like in most editors. Capped at 50 steps.

	function snapshotState() {
		return JSON.stringify({ design: ed.design, rot: ed.rot, orientation: ed.orientation, sizeIdx: ed.sizeIdx });
	}

	function updateUndoRedoButtons() {
		if (els.undoBtn) { els.undoBtn.disabled = ed.historyIndex <= 0; }
		if (els.redoBtn) { els.redoBtn.disabled = ed.historyIndex >= ed.history.length - 1; }
	}

	function pushHistory() {
		if (!ed.item) { return; }
		var snap = snapshotState();
		if (ed.history[ed.historyIndex] === snap) { return; }
		ed.history = ed.history.slice(0, ed.historyIndex + 1);
		ed.history.push(snap);
		if (ed.history.length > 50) { ed.history.shift(); }
		ed.historyIndex = ed.history.length - 1;
		updateUndoRedoButtons();
	}

	function restoreHistorySnapshot(snap) {
		var state = JSON.parse(snap);
		ed.design = state.design;
		ed.rot = state.rot;
		ed.orientation = state.orientation;
		ed.sizeIdx = state.sizeIdx;
		ed.selectedLayerId = null;
		syncPanelUI();
		edFit(); // crop/zoom aren't tracked in history, so re-fit to the restored aspect
		edDraw();
		edUpdateDpi();
	}

	function undoEdit() {
		if (ed.historyIndex <= 0) { return; }
		ed.historyIndex--;
		restoreHistorySnapshot(ed.history[ed.historyIndex]);
		updateUndoRedoButtons();
	}

	function redoEdit() {
		if (ed.historyIndex >= ed.history.length - 1) { return; }
		ed.historyIndex++;
		restoreHistorySnapshot(ed.history[ed.historyIndex]);
		updateUndoRedoButtons();
	}

	if (els.undoBtn) { els.undoBtn.addEventListener('click', undoEdit); }
	if (els.redoBtn) { els.redoBtn.addEventListener('click', redoEdit); }

	/**
	 * Refresh the Focus panel's shape buttons, sliders and which rows are
	 * visible (each shape only uses a subset of x/y/radius/pos/width) to
	 * reflect ed.design.focus.
	 */
	function syncFocusPanelUI() {
		var focus = ed.design.focus;
		if (!focus) { return; }

		if (els.focusShapes) {
			els.focusShapes.querySelectorAll('[data-shape]').forEach(function (b) {
				b.classList.toggle('is-active', b.dataset.shape === focus.shape);
			});
		}
		if (els.focusFields) { els.focusFields.hidden = !focus.shape; }
		if (!focus.shape) { return; }

		if (els.focusAmount) { els.focusAmount.value = String(focus.amount); }
		var amtOut = document.getElementById('prrint-focus-amount-out');
		if (amtOut) { amtOut.textContent = String(focus.amount); }
		if (els.focusX) { els.focusX.value = String(Math.round(focus.x * 100)); }
		if (els.focusY) { els.focusY.value = String(Math.round(focus.y * 100)); }
		if (els.focusPos) { els.focusPos.value = String(Math.round(focus.pos * 100)); }
		if (els.focusRadius) { els.focusRadius.value = String(Math.round(focus.radius * 100)); }
		if (els.focusWidth) { els.focusWidth.value = String(Math.round(focus.width * 100)); }
		if (els.focusFeather) { els.focusFeather.value = String(Math.round(focus.feather * 100)); }
		if (els.focusOrient) {
			els.focusOrient.textContent = focus.orientation === 'vertical' ? '⇅ Vertical' : '⇄ Horizontal';
		}

		var shape = focus.shape;
		function show(el, on) { if (el) { el.hidden = !on; } }
		show(els.focusXRow, shape === 'radial');
		show(els.focusYRow, shape === 'radial');
		show(els.focusRadiusRow, shape === 'radial');
		show(els.focusPosRow, shape === 'linear' || shape === 'mirrored');
		show(els.focusOrientRow, shape === 'linear' || shape === 'mirrored');
		show(els.focusWidthRow, shape === 'mirrored');
		show(els.focusFeatherRow, shape !== 'gaussian');
	}

	/**
	 * Refresh every panel control to reflect ed.design — used on open and
	 * after Undo/Redo restores a different snapshot.
	 */
	function syncPanelUI() {
		if (els.flipH) { els.flipH.classList.toggle('is-active', !!ed.design.flipH); }
		if (els.flipV) { els.flipV.classList.toggle('is-active', !!ed.design.flipV); }
		syncFocusPanelUI();
		syncTextDesignPanelUI();
		els.borderEnable.checked = !!ed.design.border.enabled;
		els.borderFields.hidden = !ed.design.border.enabled;
		els.borderWidth.value = String(Math.round(ed.design.border.width_in * 100));
		[
			[els.adjBrightness, 'brightness'], [els.adjContrast, 'contrast'], [els.adjSaturation, 'saturation'],
			[els.adjGamma, 'gamma'], [els.adjClarity, 'clarity'], [els.adjShadows, 'shadows'],
			[els.adjHighlights, 'highlights'], [els.adjExposure, 'exposure']
		].forEach(function (pair) {
			if (!pair[0]) { return; }
			var v = ed.design.adjust[pair[1]];
			pair[0].value = String(v);
			var out = document.getElementById(pair[0].id + '-out');
			if (out) { out.textContent = String(v); }
		});
		if (els.filterGrid) {
			els.filterGrid.querySelectorAll('.prrint-filter-swatch').forEach(function (b) {
				b.classList.toggle('is-active', b.dataset.filter === (ed.design.filter || ''));
			});
		}
		if (els.overlayGrid) {
			els.overlayGrid.querySelectorAll('.prrint-overlay-swatch').forEach(function (b) {
				b.classList.toggle('is-active', b.dataset.overlay === (ed.design.overlay || ''));
			});
		}
		buildSwatchRow(els.borderSwatches, cfg.borderColors, function (color) {
			ed.design.border.color = color;
			edDraw();
			pushHistory();
		});
		els.borderSwatches.querySelectorAll('.prrint-swatch').forEach(function (b) {
			b.classList.toggle('is-active', b.dataset.color === ed.design.border.color);
		});
		showLayerFields(null);
		showShapeFields(null);
	}

	function openEditor(item) {
		ed.item = item;
		ed.rot = item.rot;
		ed.orientation = item.orientation;
		ed.sizeIdx = item.sizeIdx;
		ed.design = cloneDesign(item.design);
		ed.selectedLayerId = null;
		ed.history = [];
		ed.historyIndex = -1;
		ed.keepResolution = false;
		if (els.keepResolution) { els.keepResolution.checked = false; }

		syncPanelUI();
		updateStylePreviewThumbnails();

		ed.design.layers.forEach(function (l) {
			if (l.type === 'text' && l.fontFamily) {
				ensureGoogleFont(l.fontFamily, function () { if (ed.item === item) { edDraw(); } });
			}
		});

		ed.brushColor = cfg.drawColors[0] || '#000000';
		ed.brushSize = 4;
		if (els.drawSize) { els.drawSize.value = '4'; }

		ed.drawCanvas = createDrawCanvas();
		ed.drawCtx = ed.drawCanvas.getContext('2d');
		ed.hasDrawing = false;
		if (ed.design.drawing && ed.design.drawing.dataUrl) {
			var seedImg = new Image();
			seedImg.onload = function () {
				if (ed.item !== item || !ed.drawCanvas) { return; } // editor closed/reopened meanwhile
				ed.drawCtx.drawImage(seedImg, 0, 0, ed.drawCanvas.width, ed.drawCanvas.height);
				ed.hasDrawing = true;
				edDraw();
			};
			seedImg.src = ed.design.drawing.dataUrl;
		}

		setActiveTool('transform');

		els.editor.hidden = false;
		document.body.classList.add('prrint-modal-open');

		edLayout();
		edViewFromCrop(item.crop);
		edDraw();
		edUpdateDpi();
		pushHistory();
	}

	function closeEditor() {
		els.editor.hidden = true;
		document.body.classList.remove('prrint-modal-open');
		ed.item = null;
	}

	els.editorCancel.addEventListener('click', closeEditor);
	els.editor.addEventListener('click', function (e) {
		if (e.target === els.editor) { closeEditor(); }
	});
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && !els.editor.hidden) { closeEditor(); }
	});

	els.editorDone.addEventListener('click', function () {
		var item = ed.item;
		if (!item) { return; }
		item.rot = ed.rot;
		item.orientation = ed.orientation;
		item.crop = edExportCrop();
		ed.design.drawing = ed.hasDrawing ? { dataUrl: ed.drawCanvas.toDataURL('image/png') } : null;
		item.design = cloneDesign(ed.design);

		if (item.design.drawing) {
			var img = new Image();
			img.onload = function () { renderCard(item); };
			img.src = item.design.drawing.dataUrl;
			item._drawingImg = img;
		} else {
			item._drawingImg = null;
		}

		closeEditor();
		renderCard(item);
		updateSummary();
	});

	/* editor interactions */
	var dragging = false;
	var dragMode = null; // 'photo' | 'layer' | 'draw'
	var dragLayer = null;
	var last = { x: 0, y: 0 };

	function canvasPoint(e) {
		var rect = els.canvas.getBoundingClientRect();
		return { x: e.clientX - rect.left, y: e.clientY - rect.top };
	}

	els.canvas.addEventListener('pointerdown', function (e) {
		if (!ed.item) { return; }
		dragging = true;
		last.x = e.clientX;
		last.y = e.clientY;

		if (ed.activeTool === 'text' || ed.activeTool === 'elements') {
			var pt = canvasPoint(e);
			var hit = hitTestLayer(pt.x, pt.y);
			if (hit) {
				selectLayer(hit.id);
				dragMode = 'layer';
				dragLayer = hit;
			} else {
				dragMode = null;
				dragLayer = null;
			}
		} else if (ed.activeTool === 'draw') {
			dragMode = 'draw';
			var dp = canvasToDrawCoords(canvasPoint(e));
			ed.drawCtx.lineJoin = 'round';
			ed.drawCtx.lineCap = 'round';
			ed.drawCtx.strokeStyle = ed.brushColor;
			ed.drawCtx.lineWidth = brushPx();
			ed.drawCtx.beginPath();
			ed.drawCtx.moveTo(dp.x, dp.y);
			ed.drawCtx.lineTo(dp.x + 0.01, dp.y + 0.01); // draw a dot for a simple tap
			ed.drawCtx.stroke();
			ed.drawLast = dp;
			ed.hasDrawing = true;
			edDraw();
		} else {
			dragMode = 'photo';
		}

		els.canvas.setPointerCapture(e.pointerId);
		e.preventDefault();
	});
	els.canvas.addEventListener('pointermove', function (e) {
		if (!dragging) { return; }
		var dx = e.clientX - last.x;
		var dy = e.clientY - last.y;
		last.x = e.clientX;
		last.y = e.clientY;

		if (dragMode === 'layer' && dragLayer) {
			dragLayer.x = Math.max(-0.5, Math.min(1.4, dragLayer.x + dx / ed.frame.w));
			dragLayer.y = Math.max(-0.5, Math.min(1.4, dragLayer.y + dy / ed.frame.h));
			edDraw();
		} else if (dragMode === 'photo') {
			ed.off.x += dx;
			ed.off.y += dy;
			edClamp();
			edDraw();
			edUpdateDpi();
		} else if (dragMode === 'draw') {
			var dp = canvasToDrawCoords(canvasPoint(e));
			ed.drawCtx.beginPath();
			ed.drawCtx.moveTo(ed.drawLast.x, ed.drawLast.y);
			ed.drawCtx.lineTo(dp.x, dp.y);
			ed.drawCtx.stroke();
			ed.drawLast = dp;
			edDraw();
		}
	});
	els.canvas.addEventListener('pointerup', function () {
		if (dragMode === 'layer') { pushHistory(); }
		dragging = false;
		dragMode = null;
		dragLayer = null;
	});
	els.canvas.addEventListener('pointercancel', function () { dragging = false; dragMode = null; dragLayer = null; });

	function updateZoomPct() {
		if (els.zoomPct) { els.zoomPct.textContent = els.zoom.value + '%'; }
		if (els.zoomInput && document.activeElement !== els.zoomInput) { els.zoomInput.value = els.zoom.value; }
	}

	function setZoomFraction(t) {
		t = Math.max(0, Math.min(100, t));
		ed.scale = Math.min(ed.minScale + (ed.maxScale - ed.minScale) * (t / 100), edEffectiveMaxScale());
		els.zoom.value = String(Math.round(((ed.scale - ed.minScale) / (ed.maxScale - ed.minScale || 1)) * 100));
		edClamp();
		edDraw();
		edUpdateDpi();
		updateZoomPct();
	}

	els.canvas.addEventListener('wheel', function (e) {
		if (!ed.item || ed.activeTool !== 'transform') { return; }
		e.preventDefault();
		var factor = Math.pow(1.0015, -e.deltaY);
		ed.scale = Math.max(ed.minScale, Math.min(edEffectiveMaxScale(), ed.scale * factor));
		els.zoom.value = String(Math.round(((ed.scale - ed.minScale) / (ed.maxScale - ed.minScale || 1)) * 100));
		edClamp();
		edDraw();
		edUpdateDpi();
		updateZoomPct();
	}, { passive: false });

	els.zoom.addEventListener('input', function () {
		if (!ed.item) { return; }
		var t = Number(this.value) / 100;
		ed.scale = Math.min(ed.minScale + (ed.maxScale - ed.minScale) * t, edEffectiveMaxScale());
		els.zoom.value = String(Math.round(((ed.scale - ed.minScale) / (ed.maxScale - ed.minScale || 1)) * 100));
		edClamp();
		edDraw();
		edUpdateDpi();
		updateZoomPct();
	});

	if (els.zoomOut) {
		els.zoomOut.addEventListener('click', function () {
			if (!ed.item) { return; }
			setZoomFraction(Number(els.zoom.value) - 10);
		});
	}
	if (els.zoomIn) {
		els.zoomIn.addEventListener('click', function () {
			if (!ed.item) { return; }
			setZoomFraction(Number(els.zoom.value) + 10);
		});
	}
	if (els.zoomInput) {
		els.zoomInput.addEventListener('change', function () {
			if (!ed.item) { return; }
			var v = Number(els.zoomInput.value);
			if (isNaN(v)) { v = Number(els.zoom.value); }
			setZoomFraction(v);
			pushHistory();
		});
	}

	els.rotate.addEventListener('click', function () {
		if (!ed.item) { return; }
		ed.rot = (ed.rot + 1) % 4;
		edFit();
		edDraw();
		edUpdateDpi();
		pushHistory();
	});

	els.orient.addEventListener('click', function () {
		if (!ed.item) { return; }
		ed.orientation = ed.orientation === 'portrait' ? 'landscape' : 'portrait';
		edFit();
		edDraw();
		edUpdateDpi();
		pushHistory();
	});

	if (els.flipH) {
		els.flipH.addEventListener('click', function () {
			if (!ed.item) { return; }
			ed.design.flipH = !ed.design.flipH;
			els.flipH.classList.toggle('is-active', ed.design.flipH);
			edDraw();
			pushHistory();
		});
	}

	if (els.flipV) {
		els.flipV.addEventListener('click', function () {
			if (!ed.item) { return; }
			ed.design.flipV = !ed.design.flipV;
			els.flipV.classList.toggle('is-active', ed.design.flipV);
			edDraw();
			pushHistory();
		});
	}

	if (els.keepResolution) {
		els.keepResolution.addEventListener('change', function () {
			if (!ed.item) { return; }
			ed.keepResolution = this.checked;
			var cap = edEffectiveMaxScale();
			if (ed.scale > cap) {
				ed.scale = cap;
				edClamp();
				edDraw();
				edUpdateDpi();
				els.zoom.value = String(Math.round(((ed.scale - ed.minScale) / (ed.maxScale - ed.minScale || 1)) * 100));
				updateZoomPct();
			}
			pushHistory();
		});
	}

	if (els.cropW) {
		els.cropW.addEventListener('change', function () {
			var v = Number(els.cropW.value);
			if (!isNaN(v) && v > 0) { setCropWidthPx(v); pushHistory(); } else { syncCropSizeInputs(); }
		});
	}

	if (els.cropH) {
		els.cropH.addEventListener('change', function () {
			var v = Number(els.cropH.value);
			if (!isNaN(v) && v > 0) { setCropHeightPx(v); pushHistory(); } else { syncCropSizeInputs(); }
		});
	}

	if (els.transformReset) {
		els.transformReset.addEventListener('click', function () {
			var item = ed.item;
			if (!item) { return; }
			ed.rot = 0;
			ed.design.flipH = false;
			ed.design.flipV = false;
			if (els.flipH) { els.flipH.classList.remove('is-active'); }
			if (els.flipV) { els.flipV.classList.remove('is-active'); }
			edFit();
			edDraw();
			edUpdateDpi();
			pushHistory();
		});
	}

	if (els.focusShapes) {
		els.focusShapes.querySelectorAll('[data-shape]').forEach(function (b) {
			b.addEventListener('click', function () {
				if (!ed.item) { return; }
				var shape = b.dataset.shape;
				ed.design.focus.shape = ed.design.focus.shape === shape ? '' : shape;
				if (ed.design.focus.shape && !ed.design.focus.amount) { ed.design.focus.amount = 60; }
				syncFocusPanelUI();
				edDraw();
				pushHistory();
			});
		});
	}

	[
		[els.focusAmount, 'amount', 1],
		[els.focusX, 'x', 100],
		[els.focusY, 'y', 100],
		[els.focusPos, 'pos', 100],
		[els.focusRadius, 'radius', 100],
		[els.focusWidth, 'width', 100],
		[els.focusFeather, 'feather', 100]
	].forEach(function (row) {
		var input = row[0], key = row[1], divisor = row[2];
		if (!input) { return; }
		input.addEventListener('input', function () {
			if (!ed.item) { return; }
			ed.design.focus[key] = Number(input.value) / divisor;
			if ('amount' === key) {
				var amtOut = document.getElementById('prrint-focus-amount-out');
				if (amtOut) { amtOut.textContent = input.value; }
			}
			edDraw();
		});
		input.addEventListener('change', function () { if (ed.item) { pushHistory(); } });
	});

	if (els.focusOrient) {
		els.focusOrient.addEventListener('click', function () {
			if (!ed.item) { return; }
			ed.design.focus.orientation = ed.design.focus.orientation === 'vertical' ? 'horizontal' : 'vertical';
			syncFocusPanelUI();
			edDraw();
			pushHistory();
		});
	}

	window.addEventListener('resize', function () {
		if (!ed.item || els.editor.hidden) { return; }
		var crop = edExportCrop();
		edLayout();
		edViewFromCrop(crop);
		edDraw();
	});

	// Canvas text is drawn with the bundled webfont; redraw once it has
	// finished loading so an in-progress edit doesn't stay on the fallback.
	if (window.document && document.fonts && document.fonts.ready) {
		document.fonts.ready.then(function () {
			if (ed.item && !els.editor.hidden) { edDraw(); }
			items.forEach(renderCard);
		}).catch(function () { /* noop */ });
	}

	/* ------------------------------------------------------ add to cart */

	els.addBtn.addEventListener('click', function () {
		var ready = items.filter(function (it) { return it.ready; });
		if (ready.length === 0) {
			toast(cfg.i18n.noItems, true);
			return;
		}

		var payload = ready.map(function (it) {
			return {
				token: it.token,
				size: it.sizeIdx,
				paper: it.paperIdx,
				qty: it.qty,
				orientation: it.orientation,
				border: it.design.border.enabled ? 1 : 0,
				design: it.design,
				crop: {
					x: it.crop.x,
					y: it.crop.y,
					w: it.crop.w,
					h: it.crop.h,
					rotation: it.rot
				}
			};
		});

		els.addBtn.disabled = true;
		els.addBtn.textContent = cfg.i18n.adding;

		var fd = new FormData();
		fd.append('action', 'prrint_add_to_cart');
		fd.append('nonce', cfg.nonce);
		fd.append('product_id', String(cfg.productId));
		fd.append('items', JSON.stringify(payload));

		var xhr = new XMLHttpRequest();
		xhr.open('POST', cfg.ajaxUrl);
		xhr.onload = function () {
			var resp = null;
			try { resp = JSON.parse(xhr.responseText); } catch (err) { /* noop */ }

			if (resp && resp.success) {
				els.addBtn.textContent = cfg.i18n.added;
				toast(cfg.i18n.added, false);
				window.location.href = resp.data.cart_url;
			} else {
				els.addBtn.disabled = false;
				els.addBtn.textContent = cfg.i18n.addToCart;
				toast((resp && resp.data && resp.data.message) || cfg.i18n.addError, true);
			}
		};
		xhr.onerror = function () {
			els.addBtn.disabled = false;
			els.addBtn.textContent = cfg.i18n.addToCart;
			toast(cfg.i18n.addError, true);
		};
		xhr.send(fd);
	});
})();
