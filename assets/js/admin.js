/**
 * Prrint admin: repeatable rows for the sizes/papers tables.
 */
(function () {
	'use strict';

	var counter = 1000; // unique indexes for new rows so PHP keeps them grouped

	document.addEventListener('click', function (e) {
		var addBtn = e.target.closest('.prrint-add-row');
		if (addBtn) {
			e.preventDefault();
			var wrap = addBtn.closest('p');
			var template = wrap ? wrap.previousElementSibling : null;
			if (!template || !template.classList.contains('prrint-row-template')) {
				return;
			}
			var table = template.previousElementSibling;
			if (table && !table.classList.contains('prrint-table')) {
				table = table.querySelector('.prrint-table'); // inside a scroll wrapper
			}
			if (!table) {
				return;
			}
			var html = template.innerHTML.replace(/__i__/g, 'new' + (counter++));
			var tbody = table.querySelector('tbody');
			var tr = document.createElement('tbody');
			tr.innerHTML = html;
			while (tr.firstElementChild) {
				tbody.appendChild(tr.firstElementChild);
			}
			var firstInput = tbody.lastElementChild.querySelector('input');
			if (firstInput) {
				tbody.lastElementChild.scrollIntoView({ block: 'nearest' });
				firstInput.focus();
			}
			return;
		}

		var removeBtn = e.target.closest('.prrint-remove-row');
		if (removeBtn) {
			e.preventDefault();
			var row = removeBtn.closest('tr');
			if (row) {
				row.remove();
			}
		}
	});

	/* Live swatch preview under each comma-separated color-list field. */
	function renderSwatchPreview(input) {
		var preview = input.parentElement.querySelector('[data-prrint-swatch-preview]');
		if (!preview) { return; }
		preview.innerHTML = '';
		input.value.split(',').forEach(function (part) {
			part = part.trim();
			if (!part) { return; }
			var isTransparent = /^(none|transparent)$/i.test(part);
			var chip = document.createElement('span');
			chip.className = 'prrint-swatch-chip';
			chip.style.background = isTransparent ? '#fff' : part;
			if (isTransparent) { chip.classList.add('prrint-swatch-chip-transparent'); }
			preview.appendChild(chip);
		});
	}

	document.addEventListener('input', function (e) {
		if (!e.target.classList || !e.target.classList.contains('prrint-color-input')) { return; }
		var code = e.target.parentElement.querySelector('.prrint-color-value');
		if (code) { code.textContent = e.target.value; }
	});

	document.addEventListener('click', function (e) {
		var reset = e.target.closest && e.target.closest('.prrint-color-reset');
		if (!reset) { return; }
		e.preventDefault();
		var input = reset.parentElement.querySelector('.prrint-color-input');
		if (!input) { return; }
		input.value = reset.dataset.default;
		input.dispatchEvent(new Event('input', { bubbles: true }));
	});

	document.querySelectorAll('.prrint-color-list-input').forEach(function (input) {
		renderSwatchPreview(input);
		input.addEventListener('input', function () { renderSwatchPreview(input); });
	});

	/* Settings page tabs: show one group of cards at a time. All cards stay
	   in the single form, so Save always submits every setting. */
	var tabButtons = document.querySelectorAll('[data-prrint-tab-target]');
	if (tabButtons.length) {
		var cards = document.querySelectorAll('[data-prrint-tab]');
		var referer = document.querySelector('input[name="_wp_http_referer"]');
		var keys = Array.prototype.map.call(tabButtons, function (b) { return b.dataset.prrintTabTarget; });

		var showTab = function (key) {
			if (keys.indexOf(key) === -1) { key = keys[0]; }
			tabButtons.forEach(function (b) {
				var on = b.dataset.prrintTabTarget === key;
				b.classList.toggle('is-active', on);
				b.setAttribute('aria-selected', on ? 'true' : 'false');
			});
			cards.forEach(function (c) { c.classList.toggle('prrint-tab-hidden', c.dataset.prrintTab !== key); });
			var url = new URL(window.location.href);
			url.searchParams.set('prrint_tab', key);
			url.searchParams.delete('settings-updated');
			window.history.replaceState(null, '', url.toString());
			// After saving, WordPress returns to this referer: keep the tab.
			if (referer) {
				var ref = new URL(referer.value, window.location.origin);
				ref.searchParams.set('prrint_tab', key);
				referer.value = ref.pathname + ref.search;
			}
		};

		tabButtons.forEach(function (b) {
			b.addEventListener('click', function () { showTab(b.dataset.prrintTabTarget); });
		});
		showTab(new URL(window.location.href).searchParams.get('prrint_tab') || keys[0]);
	}
})();
