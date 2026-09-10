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
})();
