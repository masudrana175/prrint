/**
 * Framers Corner dashboard tabs. Without JS every section stays visible;
 * with it one section shows at a time and the URL hash (#pf-prices…) keeps
 * the open tab across reloads and shared links.
 */
(function () {
	'use strict';

	var root = document.getElementById('prrint-framers');
	if (!root) { return; }
	var tabs = root.querySelectorAll('[data-pf-tab]');
	var panels = root.querySelectorAll('[data-pf-panel]');
	if (!tabs.length) { return; }

	var keys = Array.prototype.map.call(tabs, function (t) { return t.dataset.pfTab; });

	function show(key, focus) {
		if (keys.indexOf(key) === -1) { key = keys[0]; }
		tabs.forEach(function (t) {
			var on = t.dataset.pfTab === key;
			t.classList.toggle('is-active', on);
			t.setAttribute('aria-selected', on ? 'true' : 'false');
			t.setAttribute('tabindex', on ? '0' : '-1');
			if (on && focus) { t.focus(); }
		});
		panels.forEach(function (p) { p.hidden = p.dataset.pfPanel !== key; });
	}

	root.classList.add('pf-has-tabs');

	tabs.forEach(function (t, i) {
		t.addEventListener('click', function (e) {
			e.preventDefault();
			show(t.dataset.pfTab);
			if (window.history && window.history.replaceState) {
				window.history.replaceState(null, '', '#pf-' + t.dataset.pfTab);
			}
		});
		t.addEventListener('keydown', function (e) {
			if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') { return; }
			e.preventDefault();
			var next = (i + (e.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
			show(keys[next], true);
		});
	});

	var fromHash = (window.location.hash || '').replace('#pf-', '');
	show(fromHash);
})();
