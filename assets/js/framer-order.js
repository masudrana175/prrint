/**
 * Framers Corner order form — the online version of ColorPlak's paper
 * order form. Pricing here is a live preview only; the server re-prices
 * every row (Prrint_Wholesale::quote) before anything reaches the cart, and
 * this file mirrors those rules exactly so the two never disagree.
 */
(function () {
	'use strict';

	var cfg = window.prrintFramer;
	var root = document.getElementById('prrint-framer-order');
	if (!cfg || !root) { return; }

	var C = cfg.catalog;
	var t = cfg.i18n;
	var els = {
		items: document.getElementById('prrint-fo-items'),
		add: document.getElementById('prrint-fo-add'),
		po: document.getElementById('prrint-fo-po'),
		needed: document.getElementById('prrint-fo-needed'),
		rush: document.getElementById('prrint-fo-rush'),
		blind: document.getElementById('prrint-fo-blind'),
		mailin: document.getElementById('prrint-fo-mailin'),
		plak: document.getElementById('prrint-fo-plak'),
		discountRow: document.getElementById('prrint-fo-discount-row'),
		discountLabel: document.getElementById('prrint-fo-discount-label'),
		discount: document.getElementById('prrint-fo-discount'),
		rushRow: document.getElementById('prrint-fo-rush-row'),
		rushAmt: document.getElementById('prrint-fo-rushamt'),
		boxingLabel: document.getElementById('prrint-fo-boxing-label'),
		boxing: document.getElementById('prrint-fo-boxing'),
		freight: document.getElementById('prrint-fo-freight'),
		total: document.getElementById('prrint-fo-total'),
		save: document.getElementById('prrint-fo-save'),
		submit: document.getElementById('prrint-fo-submit'),
		message: document.getElementById('prrint-fo-message')
	};

	var rows = [];
	var nextId = 1;

	/* ------------------------------------------------------------ utils */

	function round2(n) { return Math.round((n + 1e-9) * 100) / 100; }

	function sprintf(str) {
		var args = Array.prototype.slice.call(arguments, 1);
		var i = 0;
		return str.replace(/%%|%(\d\$)?[sd]/g, function (m, pos) {
			if (m === '%%') { return '%'; }
			return String(pos ? args[parseInt(pos, 10) - 1] : args[i++]);
		});
	}

	function money(n) {
		var c = cfg.currency;
		var parts = n.toFixed(c.decimals).split('.');
		parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, c.thousandSep);
		var num = parts.join(c.decimalSep);
		switch (c.position) {
			case 'right': return num + c.symbol;
			case 'left_space': return c.symbol + ' ' + num;
			case 'right_space': return num + ' ' + c.symbol;
			default: return c.symbol + num;
		}
	}

	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			if (k === 'text') { node.textContent = attrs[k]; } else if (k === 'class') { node.className = attrs[k]; } else { node.setAttribute(k, attrs[k]); }
		});
		(children || []).forEach(function (ch) { if (ch) { node.appendChild(ch); } });
		return node;
	}

	function normName(s) { return String(s || '').toLowerCase().replace(/[^a-z0-9]/g, ''); }

	function edgeAvailable(edge, productLabel) {
		if (!edge.exclude) { return true; }
		var target = normName(productLabel);
		return !String(edge.exclude).split(',').some(function (n) { return normName(n) !== '' && normName(n) === target; });
	}

	function edgeLabel(edge) { return edge.code ? edge.code + ' - ' + edge.label : edge.label; }

	/* ---------------------------------------------- pricing (mirrors PHP) */

	function tierFor(line, length, width) {
		length = Number(length); width = Number(width);
		if (!(length > 0) || !(width > 0)) { return { error: t.enterSize }; }
		var max = C.lines[line].max;
		var shortSide = Math.min(length, width), longSide = Math.max(length, width);
		if (shortSide > max[0] + C.tolerance || longSide > max[1] + C.tolerance) {
			return { error: sprintf(t.tooBig, C.lines[line].label, max[0], max[1]) };
		}
		for (var s = 0; s < C.tiers.length; s++) {
			var dims = C.tiers[s][0].split('x').map(Number);
			if (Math.abs(shortSide - Math.min(dims[0], dims[1])) <= C.tolerance && Math.abs(longSide - Math.max(dims[0], dims[1])) <= C.tolerance) {
				return { tier: s };
			}
		}
		var sq = Math.max(0, length - C.tolerance) * Math.max(0, width - C.tolerance);
		for (var i = 0; i < C.tiers.length; i++) {
			if (sq <= C.tiers[i][1] + 1e-9) { return { tier: i }; }
		}
		return { error: sprintf(t.tooBig, C.lines[line].label, max[0], max[1]) };
	}

	function quote(row) {
		var p = C.products[row.product];
		if (!p) { return { error: t.chooseProduct }; }
		var tr = tierFor(p.line, row.length, row.width);
		if (tr.error) { return tr; }
		var digital = p.supplied === null ? true : !!row.digital;
		var base = p[digital ? 'printed' : 'supplied'][tr.tier];
		if (base === null || base === '') { return { error: sprintf(t.notOffered, p.label, C.tiers[tr.tier][0]) }; }
		var unit = Number(base);
		if (row.leather && p.line === 'paper' && C.leather[tr.tier] !== null) { unit += Number(C.leather[tr.tier]); }
		if (row.mat) { unit += unit * C.options.mat_percent / 100; }
		if (row.recut) { unit += C.options.recut; }
		if (row.trim) { unit += C.options.trim; }
		unit = round2(unit);
		var qty = Math.max(1, Math.min(999, parseInt(row.qty, 10) || 1));
		return { tier: tr.tier, tierLabel: C.tiers[tr.tier][0], unit: unit, qty: qty, lineTotal: round2(unit * qty), digital: digital };
	}

	function shipping(pieces) {
		var counts = C.boxes.map(function () { return 0; });
		pieces.forEach(function (pc) {
			var sq = C.tiers[pc.tier][1];
			for (var r = 0; r < C.boxes.length; r++) {
				if (sq <= C.boxes[r].max_sqin) { counts[r] += pc.qty; break; }
			}
		});
		var out = { boxing: 0, freight: 0, boxes: 0, quote: false };
		C.boxes.forEach(function (rule, r) {
			if (!counts[r]) { return; }
			var boxes = Math.ceil(counts[r] / Math.max(1, rule.per_box));
			out.boxes += boxes;
			out.boxing += boxes * Number(rule.box);
			if (rule.freight === null || rule.freight === '') { out.quote = true; } else { out.freight += boxes * Number(rule.freight); }
		});
		out.boxing = round2(out.boxing);
		out.freight = round2(out.freight);
		return out;
	}

	/* ------------------------------------------------------------- rows */

	function newRow(data) {
		var row = {
			id: nextId++, title: '', product: '', length: '', width: '', qty: 1, edge: '',
			digital: false, condition: '', token: '', fileName: '', previewUrl: '',
			mat: false, recut: false, trim: false, leather: false, instructions: ''
		};
		Object.keys(data || {}).forEach(function (k) { if (k !== 'id' && k in row) { row[k] = data[k]; } });
		return row;
	}

	function productSelect(row) {
		var sel = el('select', { class: 'prrint-fo-product' });
		sel.appendChild(el('option', { value: '', text: t.choose }));
		Object.keys(C.lines).forEach(function (line) {
			var group = el('optgroup', { label: C.lines[line].label });
			Object.keys(C.products).forEach(function (id) {
				if (C.products[id].line === line) { group.appendChild(el('option', { value: id, text: C.products[id].label })); }
			});
			sel.appendChild(group);
		});
		sel.value = row.product;
		return sel;
	}

	function field(label, input, cls) {
		return el('label', { class: 'prrint-fo-field ' + (cls || '') }, [el('span', { text: label }), input]);
	}

	function check(label, checked, cls) {
		var input = el('input', { type: 'checkbox', class: cls });
		input.checked = !!checked;
		return el('label', { class: 'prrint-fo-check' }, [input, el('span', { text: ' ' + label })]);
	}

	function renderRow(row, index) {
		var p = C.products[row.product];
		var card = el('div', { class: 'prrint-fo-item', 'data-id': String(row.id) });

		var head = el('div', { class: 'prrint-fo-item-head' }, [
			el('strong', { text: t.item + ' ' + (index + 1) }),
			el('span', { class: 'prrint-fo-item-actions' }, [
				el('button', { type: 'button', class: 'prrint-fo-dup button-link', text: t.duplicate }),
				el('button', { type: 'button', class: 'prrint-fo-remove button-link', text: t.remove })
			])
		]);
		card.appendChild(head);

		var title = el('input', { type: 'text', class: 'prrint-fo-title', maxlength: '120' });
		title.value = row.title;
		var length = el('input', { type: 'number', class: 'prrint-fo-length', min: '1', step: '0.125', inputmode: 'decimal' });
		length.value = row.length;
		var width = el('input', { type: 'number', class: 'prrint-fo-width', min: '1', step: '0.125', inputmode: 'decimal' });
		width.value = row.width;
		var qty = el('input', { type: 'number', class: 'prrint-fo-qty', min: '1', max: '999', step: '1' });
		qty.value = row.qty;

		card.appendChild(el('div', { class: 'prrint-fo-grid' }, [
			field(t.title, title, 'prrint-fo-wide'),
			field(t.product, productSelect(row), 'prrint-fo-wide'),
			field(t.length, length),
			field(t.width, width),
			field(t.qty, qty)
		]));

		var extra = el('div', { class: 'prrint-fo-grid' });
		if (p && p.edge) {
			var edgeSel = el('select', { class: 'prrint-fo-edge' });
			edgeSel.appendChild(el('option', { value: '', text: t.choose }));
			cfg.edgeColors.forEach(function (e, i) {
				if (edgeAvailable(e, p.label)) { edgeSel.appendChild(el('option', { value: String(i), text: edgeLabel(e) })); }
			});
			edgeSel.value = String(row.edge);
			if (edgeSel.value !== String(row.edge)) { row.edge = ''; edgeSel.value = ''; }
			var swatch = el('i', { class: 'prrint-fo-swatch' });
			if (row.edge !== '' && cfg.edgeColors[row.edge]) { swatch.style.background = cfg.edgeColors[row.edge].color; }
			extra.appendChild(field(t.edge, el('span', { class: 'prrint-fo-edge-wrap' }, [swatch, edgeSel]), 'prrint-fo-wide'));
		}

		if (p) {
			var mailable = p.supplied !== null;
			var digital = mailable ? !!row.digital : true;
			if (mailable) {
				var mode = el('div', { class: 'prrint-fo-mode', role: 'radiogroup', 'aria-label': t.image });
				[['0', t.mailIn], ['1', t.digital]].forEach(function (opt) {
					var r = el('input', { type: 'radio', name: 'prrint-fo-mode-' + row.id, value: opt[0], class: 'prrint-fo-digital' });
					r.checked = (opt[0] === '1') === digital;
					mode.appendChild(el('label', { class: 'prrint-fo-check' }, [r, el('span', { text: ' ' + opt[1] })]));
				});
				extra.appendChild(field(t.image, mode, 'prrint-fo-wide'));
			} else {
				extra.appendChild(el('p', { class: 'prrint-fo-note prrint-fo-wide', text: t.digitalOnly }));
			}

			if (!digital) {
				var cond = el('select', { class: 'prrint-fo-condition' });
				cond.appendChild(el('option', { value: '', text: t.choose }));
				Object.keys(C.conditions).forEach(function (k) { cond.appendChild(el('option', { value: k, text: C.conditions[k] })); });
				cond.value = row.condition;
				extra.appendChild(field(t.condition, cond));
			} else {
				var file = el('input', { type: 'file', class: 'prrint-fo-file', accept: 'image/jpeg,image/png,image/webp' });
				var status = el('span', { class: 'prrint-fo-file-status', text: row.token ? t.uploaded + ': ' + row.fileName : '' });
				var preview = row.previewUrl ? el('img', { class: 'prrint-fo-preview', src: row.previewUrl, alt: '' }) : null;
				extra.appendChild(el('div', { class: 'prrint-fo-field prrint-fo-wide' }, [
					el('span', { text: t.file }),
					el('div', { class: 'prrint-fo-file-row' }, [preview, file, status]),
					el('small', { class: 'prrint-fo-note', text: t.fileHint })
				]));
			}

			var opts = el('div', { class: 'prrint-fo-opts prrint-fo-wide' }, [
				check(t.mat, row.mat, 'prrint-fo-mat'),
				check(t.recut, row.recut, 'prrint-fo-recut'),
				check(t.trim, row.trim, 'prrint-fo-trim'),
				p.line === 'paper' ? check(t.leather, row.leather, 'prrint-fo-leather') : null
			]);
			extra.appendChild(opts);
		}

		var instr = el('textarea', { class: 'prrint-fo-instructions', rows: '2', maxlength: '1000' });
		instr.value = row.instructions;
		extra.appendChild(field(t.instructions, instr, 'prrint-fo-wide'));
		card.appendChild(extra);

		card.appendChild(el('div', { class: 'prrint-fo-item-price' }));
		bindRow(card, row);
		return card;
	}

	function bindRow(card, row) {
		function on(sel, evt, fn) {
			var node = card.querySelector(sel);
			if (node) { node.addEventListener(evt, fn); }
		}
		on('.prrint-fo-title', 'input', function () { row.title = this.value; card.classList.remove('has-error'); });
		on('.prrint-fo-product', 'change', function () { row.product = this.value; rerender(); });
		on('.prrint-fo-length', 'input', function () { row.length = this.value; update(); });
		on('.prrint-fo-width', 'input', function () { row.width = this.value; update(); });
		on('.prrint-fo-qty', 'input', function () { row.qty = this.value; update(); });
		on('.prrint-fo-edge', 'change', function () {
			row.edge = this.value;
			var sw = card.querySelector('.prrint-fo-swatch');
			sw.style.background = row.edge !== '' && cfg.edgeColors[row.edge] ? cfg.edgeColors[row.edge].color : '';
		});
		card.querySelectorAll('.prrint-fo-digital').forEach(function (r) {
			r.addEventListener('change', function () { row.digital = this.value === '1'; rerender(); });
		});
		on('.prrint-fo-condition', 'change', function () { row.condition = this.value; });
		on('.prrint-fo-mat', 'change', function () { row.mat = this.checked; update(); });
		on('.prrint-fo-recut', 'change', function () { row.recut = this.checked; update(); });
		on('.prrint-fo-trim', 'change', function () { row.trim = this.checked; update(); });
		on('.prrint-fo-leather', 'change', function () { row.leather = this.checked; update(); });
		on('.prrint-fo-instructions', 'input', function () { row.instructions = this.value; });
		on('.prrint-fo-file', 'change', function () { if (this.files && this.files[0]) { upload(row, card, this.files[0]); } });
		on('.prrint-fo-remove', 'click', function () {
			rows = rows.filter(function (r) { return r !== row; });
			if (!rows.length) { rows.push(newRow()); }
			rerender();
		});
		on('.prrint-fo-dup', 'click', function () {
			var copy = newRow(JSON.parse(JSON.stringify(row)));
			rows.splice(rows.indexOf(row) + 1, 0, copy);
			rerender();
		});
	}

	function upload(row, card, file) {
		var status = card.querySelector('.prrint-fo-file-status');
		if (file.size > cfg.maxMb * 1048576) { status.textContent = t.uploadFailed + ' (max ' + cfg.maxMb + ' MB)'; return; }
		var fd = new FormData();
		fd.append('action', 'prrint_upload');
		fd.append('nonce', cfg.uploadNonce);
		fd.append('file', file);
		var xhr = new XMLHttpRequest();
		xhr.open('POST', cfg.ajaxUrl);
		xhr.upload.onprogress = function (e) {
			if (e.lengthComputable) { status.textContent = t.uploading + ' ' + Math.round(e.loaded / e.total * 100) + '%'; }
		};
		xhr.onload = function () {
			var resp = null;
			try { resp = JSON.parse(xhr.responseText); } catch (err) { /* noop */ }
			if (resp && resp.success) {
				row.token = resp.data.token;
				row.previewUrl = resp.data.url;
				row.fileName = file.name;
				rerender();
			} else {
				status.textContent = (resp && resp.data && resp.data.message) || t.uploadFailed;
			}
		};
		xhr.onerror = function () { status.textContent = t.uploadFailed; };
		status.textContent = t.uploading;
		xhr.send(fd);
	}

	/* ---------------------------------------------------------- totals */

	function update() {
		var plak = 0;
		var pieces = [];
		var anyMail = false;
		rows.forEach(function (row) {
			var card = els.items.querySelector('[data-id="' + row.id + '"]');
			if (!card) { return; }
			var out = card.querySelector('.prrint-fo-item-price');
			var q = quote(row);
			if (q.error) {
				out.textContent = row.product || row.length || row.width ? q.error : '';
				out.classList.toggle('is-error', !!(row.product && (row.length || row.width)));
				return;
			}
			out.classList.remove('is-error');
			out.textContent = sprintf(t.billedAs, q.tierLabel) + ' · ' + money(q.unit) + ' ' + t.each + (q.qty > 1 ? ' × ' + q.qty + ' = ' + money(q.lineTotal) : '');
			plak += q.lineTotal;
			pieces.push({ tier: q.tier, qty: q.qty });
			if (!q.digital) { anyMail = true; }
		});
		plak = round2(plak);
		var discount = round2(plak * cfg.discount / 100);
		var rush = els.rush.checked ? round2((plak - discount) * C.options.rush_percent / 100) : 0;
		var ship = shipping(pieces);

		els.plak.textContent = money(plak);
		els.discountRow.hidden = !(discount > 0);
		els.discountLabel.textContent = sprintf(t.discount, cfg.discount);
		els.discount.textContent = '−' + money(discount);
		els.rushRow.hidden = !(rush > 0);
		els.rushAmt.textContent = money(rush);
		els.boxing.textContent = money(ship.boxing);
		els.boxingLabel.textContent = t.boxing + (ship.boxes ? ' (' + sprintf(t.boxes, ship.boxes) + ')' : '');
		els.freight.textContent = money(ship.freight) + (ship.quote ? ' + ' + t.quote : '');
		els.total.textContent = money(round2(plak - discount + rush + ship.boxing + ship.freight));
		els.mailin.hidden = !anyMail;
	}

	function rerender() {
		els.items.innerHTML = '';
		rows.forEach(function (row, i) { els.items.appendChild(renderRow(row, i)); });
		update();
	}

	/* ------------------------------------------------- validate / send */

	function validate() {
		var firstBad = null;
		rows.forEach(function (row) {
			var card = els.items.querySelector('[data-id="' + row.id + '"]');
			var p = C.products[row.product];
			var q = quote(row);
			var msg = '';
			if (!row.title.trim()) { msg = t.needTitle; } else if (q.error) { msg = q.error; } else if (p.edge && row.edge === '') { msg = t.needEdge; } else if (!q.digital && !row.condition) { msg = t.needCondition; }
			card.classList.toggle('has-error', !!msg);
			if (msg) {
				card.querySelector('.prrint-fo-item-price').textContent = msg;
				card.querySelector('.prrint-fo-item-price').classList.add('is-error');
				if (!firstBad) { firstBad = card; }
			}
		});
		if (firstBad) { firstBad.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
		return !firstBad;
	}

	function payload() {
		return {
			po: els.po.value,
			needed_by: els.needed.value,
			rush: els.rush.checked,
			blind_ship: els.blind.checked,
			items: rows.map(function (r) {
				var copy = JSON.parse(JSON.stringify(r));
				delete copy.id;
				return copy;
			})
		};
	}

	function post(action, fields, done) {
		var fd = new FormData();
		fd.append('action', action);
		fd.append('nonce', cfg.nonce);
		Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
		var xhr = new XMLHttpRequest();
		xhr.open('POST', cfg.ajaxUrl);
		xhr.onload = function () {
			var resp = null;
			try { resp = JSON.parse(xhr.responseText); } catch (err) { /* noop */ }
			done(resp);
		};
		xhr.onerror = function () { done(null); };
		xhr.send(fd);
	}

	function say(msg, isError) {
		els.message.textContent = msg;
		els.message.classList.toggle('is-error', !!isError);
	}

	els.add.addEventListener('click', function () {
		rows.push(newRow());
		rerender();
		var cards = els.items.querySelectorAll('.prrint-fo-item');
		var input = cards[cards.length - 1].querySelector('.prrint-fo-title');
		if (input) { input.focus(); }
	});
	els.rush.addEventListener('change', update);

	els.save.addEventListener('click', function () {
		els.save.disabled = true;
		post('prrint_framer_save_draft', { draft: JSON.stringify(payload()) }, function (resp) {
			els.save.disabled = false;
			say(resp && resp.success ? t.saved : t.saveFailed, !(resp && resp.success));
		});
	});

	els.submit.addEventListener('click', function () {
		if (!validate()) { say(t.missing, true); return; }
		els.submit.disabled = true;
		say(t.submitting, false);
		post('prrint_framer_submit', { order: JSON.stringify(payload()) }, function (resp) {
			if (resp && resp.success) {
				window.location.href = resp.data.redirect;
				return;
			}
			els.submit.disabled = false;
			say((resp && resp.data && resp.data.message) || t.error, true);
			if (resp && resp.data && typeof resp.data.item === 'number' && rows[resp.data.item]) {
				var card = els.items.querySelector('[data-id="' + rows[resp.data.item].id + '"]');
				if (card) { card.classList.add('has-error'); card.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
			}
		});
	});

	/* ------------------------------------------------------------ boot */

	var draft = cfg.draft;
	if (draft && Array.isArray(draft.items) && draft.items.length) {
		rows = draft.items.map(function (d) { return newRow(d); });
		els.po.value = draft.po || '';
		els.needed.value = draft.needed_by || '';
		els.rush.checked = !!draft.rush;
		els.blind.checked = !!draft.blind_ship;
	} else {
		rows = [newRow()];
	}
	rerender();
})();
