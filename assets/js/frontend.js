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
		borderEnable: document.getElementById('prrint-border-enable'),
		borderFields: document.getElementById('prrint-border-fields'),
		borderSwatches: document.getElementById('prrint-border-swatches'),
		borderWidth: document.getElementById('prrint-border-width')
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
			adjust: { brightness: 0, contrast: 0, saturation: 100 },
			border: { enabled: false, color: '#ffffff', width_in: cfg.borderIn || 0.25 },
			layers: []
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
		}
		return parts.length ? parts.join(' ') : 'none';
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
	function layerBox(drawCtx, layer, frame) {
		var fontPx = Math.max(6, Math.round(layer.fontSize * frame.h));
		drawCtx.font = (layer.bold ? 'bold ' : '') + fontPx + 'px "Prrint Liberation Sans", Arial, sans-serif';
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

	/**
	 * Draw every text layer of a design onto drawCtx, relative to frame.
	 * Mirrors Prrint_Image::draw_text_layer() line for line.
	 */
	function drawTextLayers(drawCtx, design, frame) {
		if (!design || !design.layers || !design.layers.length) { return; }
		design.layers.forEach(function (layer) {
			if (!layer.text) { return; }
			var box = layerBox(drawCtx, layer, frame);
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
					'<button type="button" class="prrint-remove-btn" aria-label="' + esc(cfg.i18n.remove) + '">🗑</button>' +
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
		c.drawImage(item.img, -item.natW / 2, -item.natH / 2);
		c.restore();

		c.filter = 'none';
		drawTextLayers(c, item.design, frame);

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

			var img = new Image();
			img.onload = function () {
				ph.remove();

				var item = {
					id: nextId++,
					token: resp.data.token,
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
			};
			img.onerror = function () {
				ph.remove();
				toast(cfg.i18n.uploadError, true);
			};
			img.src = resp.data.url;
		};
		xhr.onerror = function () {
			ph.remove();
			toast(cfg.i18n.uploadError, true);
		};
		xhr.send(fd);
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
		off: { x: 0, y: 0 },
		cssW: 0,
		cssH: 0,
		frame: { x: 0, y: 0, w: 0, h: 0 }
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
		var panel = els.canvas.parentElement;
		var w = Math.min(640, panel.clientWidth || 600);
		ed.cssW = w;
		ed.cssH = Math.round(Math.min(460, w * 0.8));

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
		ctx.scale(innerScale, innerScale);
		ctx.drawImage(it.img, -it.natW / 2, -it.natH / 2);
		ctx.restore();
		ctx.filter = 'none';

		drawTextLayers(ctx, ed.design, f);
		if (ed.activeTool === 'text') {
			drawLayerHandles();
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
		}
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
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'prrint-filter-swatch';
			btn.dataset.filter = f.id;
			btn.textContent = f.label;
			btn.addEventListener('click', function () {
				ed.design.filter = f.id;
				els.filterGrid.querySelectorAll('.prrint-filter-swatch').forEach(function (b) {
					b.classList.toggle('is-active', b.dataset.filter === f.id);
				});
				edDraw();
			});
			els.filterGrid.appendChild(btn);
		});
	}

	/* adjust panel */
	function edAdjustInput(el, key) {
		if (!el) { return; }
		el.addEventListener('input', function () {
			ed.design.adjust[key] = Number(this.value);
			edDraw();
		});
	}
	edAdjustInput(els.adjBrightness, 'brightness');
	edAdjustInput(els.adjContrast, 'contrast');
	edAdjustInput(els.adjSaturation, 'saturation');
	if (els.adjReset) {
		els.adjReset.addEventListener('click', function () {
			ed.design.adjust = { brightness: 0, contrast: 0, saturation: 100 };
			els.adjBrightness.value = '0';
			els.adjContrast.value = '0';
			els.adjSaturation.value = '100';
			edDraw();
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
	});

	if (els.borderEnable) {
		els.borderEnable.addEventListener('change', function () {
			ed.design.border.enabled = this.checked;
			els.borderFields.hidden = !this.checked;
			edDraw();
		});
	}
	if (els.borderWidth) {
		els.borderWidth.addEventListener('input', function () {
			ed.design.border.width_in = Number(this.value) / 100;
			edDraw();
		});
	}

	/* text panel */
	function selectedLayer() {
		if (!ed.selectedLayerId) { return null; }
		return ed.design.layers.filter(function (l) { return l.id === ed.selectedLayerId; })[0] || null;
	}

	function showLayerFields(layer) {
		if (!layer) {
			els.textFields.hidden = true;
			return;
		}
		els.textFields.hidden = false;
		els.textContent.value = layer.text;
		els.textSize.value = String(Math.round(layer.fontSize * 100));
		els.textSpacing.value = String(Math.round(layer.lineSpacing * 10));
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
		showLayerFields(selectedLayer());
		edDraw();
	}

	function addLayer() {
		var layer = {
			id: 'l' + (nextLayerId++),
			type: 'text',
			text: cfg.i18n.newTextDefault,
			fontSize: 0.08,
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
	}

	if (els.textAdd) {
		els.textAdd.addEventListener('click', addLayer);
	}
	if (els.textContent) {
		els.textContent.addEventListener('input', function () {
			var l = selectedLayer();
			if (l) { l.text = this.value; edDraw(); }
		});
	}
	if (els.textSize) {
		els.textSize.addEventListener('input', function () {
			var l = selectedLayer();
			if (l) { l.fontSize = Number(this.value) / 100; edDraw(); }
		});
	}
	if (els.textSpacing) {
		els.textSpacing.addEventListener('input', function () {
			var l = selectedLayer();
			if (l) { l.lineSpacing = Number(this.value) / 10; edDraw(); }
		});
	}
	if (els.textWidth) {
		els.textWidth.addEventListener('input', function () {
			var l = selectedLayer();
			if (l) { l.w = Number(this.value) / 100; edDraw(); }
		});
	}
	if (els.textRotation) {
		els.textRotation.addEventListener('input', function () {
			var l = selectedLayer();
			if (l) { l.rotation = Number(this.value); edDraw(); }
		});
	}
	if (els.textBold) {
		els.textBold.addEventListener('click', function () {
			var l = selectedLayer();
			if (!l) { return; }
			l.bold = !l.bold;
			els.textBold.classList.toggle('is-active', l.bold);
			edDraw();
		});
	}
	if (els.textFields) {
		els.textFields.querySelectorAll('[data-align]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var l = selectedLayer();
				if (!l) { return; }
				l.align = btn.dataset.align;
				els.textFields.querySelectorAll('[data-align]').forEach(function (b) {
					b.classList.toggle('is-active', b === btn);
				});
				edDraw();
			});
		});
	}
	buildSwatchRow(els.textColorSwatches, cfg.textColors, function (color) {
		var l = selectedLayer();
		if (l) { l.color = color; edDraw(); }
	});
	buildSwatchRow(els.textBgSwatches, cfg.textBgColors, function (color) {
		var l = selectedLayer();
		if (l) { l.bgColor = color; edDraw(); }
	});
	if (els.textDuplicate) {
		els.textDuplicate.addEventListener('click', function () {
			var l = selectedLayer();
			if (!l) { toast(cfg.i18n.noTextLayer, true); return; }
			var copy = JSON.parse(JSON.stringify(l));
			copy.id = 'l' + (nextLayerId++);
			copy.x = Math.min(0.9, l.x + 0.04);
			copy.y = Math.min(0.9, l.y + 0.04);
			ed.design.layers.push(copy);
			selectLayer(copy.id);
		});
	}
	if (els.textDelete) {
		els.textDelete.addEventListener('click', function () {
			if (!ed.selectedLayerId) { toast(cfg.i18n.noTextLayer, true); return; }
			ed.design.layers = ed.design.layers.filter(function (l) { return l.id !== ed.selectedLayerId; });
			ed.selectedLayerId = null;
			showLayerFields(null);
			edDraw();
		});
	}

	/* layer selection handles (drawn only while the Text tool is active) */
	function drawLayerHandles() {
		var sel = selectedLayer();
		if (!sel) { return; }
		var box = layerBox(ctx, sel, ed.frame);
		ctx.save();
		ctx.strokeStyle = '#4f46e5';
		ctx.lineWidth = 1.5;
		ctx.setLineDash([4, 3]);
		ctx.strokeRect(box.x - 4, box.y - 4, box.w + 8, box.h + 8);
		ctx.restore();
	}

	function hitTestLayer(px, py) {
		var layers = ed.design.layers;
		for (var i = layers.length - 1; i >= 0; i--) {
			var box = layerBox(ctx, layers[i], ed.frame);
			if (px >= box.x - 4 && px <= box.x + box.w + 4 && py >= box.y - 4 && py <= box.y + box.h + 4) {
				return layers[i];
			}
		}
		return null;
	}

	function openEditor(item) {
		ed.item = item;
		ed.rot = item.rot;
		ed.orientation = item.orientation;
		ed.sizeIdx = item.sizeIdx;
		ed.design = cloneDesign(item.design);
		ed.selectedLayerId = null;

		els.borderEnable.checked = !!ed.design.border.enabled;
		els.borderFields.hidden = !ed.design.border.enabled;
		els.borderWidth.value = String(Math.round(ed.design.border.width_in * 100));
		els.adjBrightness.value = String(ed.design.adjust.brightness);
		els.adjContrast.value = String(ed.design.adjust.contrast);
		els.adjSaturation.value = String(ed.design.adjust.saturation);
		if (els.filterGrid) {
			els.filterGrid.querySelectorAll('.prrint-filter-swatch').forEach(function (b) {
				b.classList.toggle('is-active', b.dataset.filter === (ed.design.filter || ''));
			});
		}
		buildSwatchRow(els.borderSwatches, cfg.borderColors, function (color) {
			ed.design.border.color = color;
			edDraw();
		});
		els.borderSwatches.querySelectorAll('.prrint-swatch').forEach(function (b) {
			b.classList.toggle('is-active', b.dataset.color === ed.design.border.color);
		});
		showLayerFields(null);

		setActiveTool('transform');

		els.editor.hidden = false;
		document.body.classList.add('prrint-modal-open');

		edLayout();
		edViewFromCrop(item.crop);
		edDraw();
		edUpdateDpi();
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
		item.design = cloneDesign(ed.design);
		closeEditor();
		renderCard(item);
		updateSummary();
	});

	/* editor interactions */
	var dragging = false;
	var dragMode = null; // 'photo' | 'layer'
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

		if (ed.activeTool === 'text') {
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
		}
	});
	els.canvas.addEventListener('pointerup', function () { dragging = false; dragMode = null; dragLayer = null; });
	els.canvas.addEventListener('pointercancel', function () { dragging = false; dragMode = null; dragLayer = null; });

	els.canvas.addEventListener('wheel', function (e) {
		if (!ed.item || ed.activeTool !== 'transform') { return; }
		e.preventDefault();
		var factor = Math.pow(1.0015, -e.deltaY);
		ed.scale = Math.max(ed.minScale, Math.min(ed.maxScale, ed.scale * factor));
		els.zoom.value = String(Math.round(((ed.scale - ed.minScale) / (ed.maxScale - ed.minScale || 1)) * 100));
		edClamp();
		edDraw();
		edUpdateDpi();
	}, { passive: false });

	els.zoom.addEventListener('input', function () {
		if (!ed.item) { return; }
		var t = Number(this.value) / 100;
		ed.scale = ed.minScale + (ed.maxScale - ed.minScale) * t;
		edClamp();
		edDraw();
		edUpdateDpi();
	});

	els.rotate.addEventListener('click', function () {
		if (!ed.item) { return; }
		ed.rot = (ed.rot + 1) % 4;
		edFit();
		edDraw();
		edUpdateDpi();
	});

	els.orient.addEventListener('click', function () {
		if (!ed.item) { return; }
		ed.orientation = ed.orientation === 'portrait' ? 'landscape' : 'portrait';
		edFit();
		edDraw();
		edUpdateDpi();
	});

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
