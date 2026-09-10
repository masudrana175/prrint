/**
 * Prrint — My Account: "My Photos" delete and "My Prints" reorder.
 */
(function () {
	'use strict';

	var cfg = window.prrintAccount;
	if (!cfg) {
		return;
	}

	function toast(message, isError) {
		var el = document.querySelector('.prrint-account-toast');
		if (!el) {
			el = document.createElement('div');
			el.className = 'prrint-account-toast';
			document.body.appendChild(el);
		}
		el.textContent = message;
		el.classList.toggle('prrint-toast-error', !!isError);
		clearTimeout(toast._t);
		toast._t = setTimeout(function () { el.remove(); }, 4000);
	}

	document.addEventListener('click', function (e) {
		var del = e.target.closest && e.target.closest('.prrint-photo-delete');
		if (del) {
			if (!window.confirm(cfg.i18n.confirmDelete)) {
				return;
			}
			var tile = del.closest('.prrint-photo-tile');
			var fd = new FormData();
			fd.append('action', 'prrint_delete_photo');
			fd.append('nonce', cfg.nonce);
			fd.append('id', del.dataset.id);

			del.disabled = true;
			fetch(cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (resp) {
					if (resp && resp.success) {
						if (tile) { tile.remove(); }
					} else {
						del.disabled = false;
						toast((resp && resp.data && resp.data.message) || cfg.i18n.genericError, true);
					}
				})
				.catch(function () {
					del.disabled = false;
					toast(cfg.i18n.genericError, true);
				});
			return;
		}

		var reorder = e.target.closest && e.target.closest('.prrint-reorder-btn');
		if (reorder) {
			var original = reorder.textContent;
			reorder.disabled = true;
			reorder.textContent = cfg.i18n.reordering;

			var fd2 = new FormData();
			fd2.append('action', 'prrint_reorder');
			fd2.append('nonce', cfg.nonce);
			fd2.append('order_id', reorder.dataset.orderId);
			fd2.append('item_id', reorder.dataset.itemId);

			fetch(cfg.ajaxUrl, { method: 'POST', body: fd2, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (resp) {
					if (resp && resp.success) {
						window.location.href = resp.data.cart_url;
					} else {
						reorder.disabled = false;
						reorder.textContent = original;
						toast((resp && resp.data && resp.data.message) || cfg.i18n.genericError, true);
					}
				})
				.catch(function () {
					reorder.disabled = false;
					reorder.textContent = original;
					toast(cfg.i18n.genericError, true);
				});
		}
	});
})();
