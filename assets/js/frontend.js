/**
 * Prrint — Photo Print Studio front end.
 *
 * Multi-photo upload, per-photo crop editor, live pricing, batch add-to-cart.
 * Crop coordinates are exported in the source image's pixel space after
 * applying `rotation` quarter-turns clockwise — the exact space the
 * server-side renderer (Prrint_Image::render) expects.
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
		toast: document.getElementById('prrint-toast')
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

	var defaultSize = cfg.preselectSize >= 0 ? cfg.preselectSize : 0;
	var defaultPaper = cfg.preselectPaper >= 0 ? cfg.preselectPaper : 0;

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
			item.border = this.checked;
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
	 * Redraw one card: thumbnail canvas, price, dpi dot.
	 */
	function renderCard(item) {
		var card = item.card;
		if (!card) { return; }

		// Thumbnail canvas: draw the crop (with optional border) at ~220px wide.
		var canvas = card.querySelector('.prrint-thumb-canvas');
		var p = printDims(item);
		var TW = 440; // retina-ish backing store
		var TH = Math.round(TW * p.h / p.w);
		canvas.width = TW;
		canvas.height = TH;

		var c = canvas.getContext('2d');
		c.fillStyle = '#ffffff';
		c.fillRect(0, 0, TW, TH);

		var bx = 0, by = 0;
		if (item.border) {
			bx = Math.round(TW * cfg.borderIn / p.w);
			by = Math.round(TH * cfg.borderIn / p.h);
		}
		var innerW = TW - 2 * bx;
		var innerH = TH - 2 * by;

		var d = rotatedDims(item);
		var s = innerW / item.crop.w;

		c.save();
		c.beginPath();
		c.rect(bx, by, innerW, innerH);
		c.clip();
		c.translate(bx, by);
		c.scale(s, s);
		c.translate(-item.crop.x, -item.crop.y);
		c.translate(d.w / 2, d.h / 2);
		c.rotate(item.rot * Math.PI / 2);
		c.drawImage(item.img, -item.natW / 2, -item.natH / 2);
		c.restore();

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
					border: false,
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

		var cx = ed.cssW / 2 + ed.off.x;
		var cy = ed.cssH / 2 + ed.off.y;

		ctx.save();
		ctx.translate(cx, cy);
		ctx.rotate(ed.rot * Math.PI / 2);
		ctx.scale(ed.scale, ed.scale);
		ctx.drawImage(it.img, -it.natW / 2, -it.natH / 2);
		ctx.restore();

		var f = ed.frame;
		ctx.fillStyle = 'rgba(15,15,20,0.6)';
		ctx.fillRect(0, 0, ed.cssW, f.y);
		ctx.fillRect(0, f.y + f.h, ed.cssW, ed.cssH - f.y - f.h);
		ctx.fillRect(0, f.y, f.x, f.h);
		ctx.fillRect(f.x + f.w, f.y, ed.cssW - f.x - f.w, f.h);

		ctx.strokeStyle = '#ffffff';
		ctx.lineWidth = 2;
		ctx.strokeRect(f.x + 1, f.y + 1, f.w - 2, f.h - 2);

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

	function openEditor(item) {
		ed.item = item;
		ed.rot = item.rot;
		ed.orientation = item.orientation;
		ed.sizeIdx = item.sizeIdx;

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
		closeEditor();
		renderCard(item);
		updateSummary();
	});

	/* editor interactions */
	var dragging = false;
	var last = { x: 0, y: 0 };

	els.canvas.addEventListener('pointerdown', function (e) {
		if (!ed.item) { return; }
		dragging = true;
		last.x = e.clientX;
		last.y = e.clientY;
		els.canvas.setPointerCapture(e.pointerId);
		e.preventDefault();
	});
	els.canvas.addEventListener('pointermove', function (e) {
		if (!dragging) { return; }
		ed.off.x += e.clientX - last.x;
		ed.off.y += e.clientY - last.y;
		last.x = e.clientX;
		last.y = e.clientY;
		edClamp();
		edDraw();
		edUpdateDpi();
	});
	els.canvas.addEventListener('pointerup', function () { dragging = false; });
	els.canvas.addEventListener('pointercancel', function () { dragging = false; });

	els.canvas.addEventListener('wheel', function (e) {
		if (!ed.item) { return; }
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
				border: it.border ? 1 : 0,
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
