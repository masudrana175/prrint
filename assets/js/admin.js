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
			if (!table || !table.classList.contains('prrint-table')) {
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
})();
