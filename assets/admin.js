(function () {
	'use strict';
	var W = window.WasmouWC || {}, T = W.i18n || {};
	function $(s, r) { return (r || document).querySelector(s); }
	function post(action, data) {
		var fd = new FormData();
		fd.append('action', action); fd.append('nonce', W.nonce);
		Object.keys(data || {}).forEach(function (k) {
			if (Array.isArray(data[k])) { data[k].forEach(function (v) { fd.append(k + '[]', v); }); } else { fd.append(k, data[k]); }
		});
		return fetch(W.ajax, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); });
	}
	function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

	// ---- connection test
	var test = $('#wasmou-test');
	if (test) {
		test.addEventListener('click', function () {
			var out = $('#wasmou-test-result'); out.className = 'wasmou-inline'; out.textContent = T.testing;
			post('wasmou_wc_test').then(function (r) {
				out.className = 'wasmou-inline ' + (r.success ? 'ok' : 'bad');
				out.textContent = (r.data && r.data.message) || T.error;
			}).catch(function () { out.className = 'wasmou-inline bad'; out.textContent = T.error; });
		});
	}

	// ---- sync now
	var sync = $('#wasmou-sync');
	if (sync) {
		sync.addEventListener('click', function () {
			var out = $('#wasmou-sync-result'); out.className = 'wasmou-inline'; out.textContent = T.syncing; sync.disabled = true;
			post('wasmou_wc_sync').then(function (r) {
				out.className = 'wasmou-inline ' + (r.success ? 'ok' : 'bad');
				out.textContent = (r.data && r.data.message) || T.error; sync.disabled = false;
			}).catch(function () { out.className = 'wasmou-inline bad'; out.textContent = T.error; sync.disabled = false; });
		});
	}

	// ---- import screen
	var root = $('#wasmou-import');
	if (!root) { return; }
	var grid = $('#wasmou-grid'), q = $('#wasmou-q'), cat = $('#wasmou-cat'), hide = $('#wasmou-hide-imported'),
		count = $('#wasmou-count'), btn = $('#wasmou-do-import'), all = $('#wasmou-select-all'), msg = $('#wasmou-msg'),
		bar = $('#wasmou-progress'), products = [], selected = {};
	msg.textContent = T.loading;

	function shown() {
		var term = q.value.trim().toLowerCase(), c = cat.value;
		return products.filter(function (p) {
			return (!term || p.name.toLowerCase().indexOf(term) > -1) && (!c || String(p.category) === c) && !(hide.checked && p.imported);
		});
	}
	function render() {
		var list = shown();
		grid.innerHTML = list.length ? list.map(function (p) {
			var on = selected[p.id] ? ' is-on' : '';
			return '<label class="wasmou-card' + on + '" data-id="' + p.id + '">' +
				'<input type="checkbox" ' + (selected[p.id] ? 'checked' : '') + ' />' +
				'<span class="wasmou-thumb">' + (p.image ? '<img loading="lazy" src="' + esc(p.image) + '" alt="" />' : '') + '</span>' +
				'<span class="wasmou-name">' + esc(p.name) + '</span>' +
				'<span class="wasmou-meta">' + esc(p.cat_name) + ' · ' + p.variants + ' ' + esc(p.variants === 1 ? T.plan : T.plans) + '</span>' +
				'<span class="wasmou-badge ' + (p.imported ? 'imp' : 'new') + '">' + esc(p.imported ? T.imported : T['new']) + '</span></label>';
		}).join('') : '<p>' + esc(T.none) + '</p>';
		var n = Object.keys(selected).length;
		count.textContent = n + ' ' + T.selected;
		btn.disabled = n === 0;
	}
	grid.addEventListener('change', function (e) {
		var card = e.target.closest('.wasmou-card'); if (!card) { return; }
		var id = card.getAttribute('data-id');
		if (e.target.checked) { selected[id] = true; card.classList.add('is-on'); } else { delete selected[id]; card.classList.remove('is-on'); }
		var n = Object.keys(selected).length; count.textContent = n + ' ' + T.selected; btn.disabled = n === 0;
	});
	q.addEventListener('input', render);
	// not on 'change' for the search box: that event fires on blur and would rebuild the grid under a click
	cat.addEventListener('change', render); hide.addEventListener('change', render);
	all.addEventListener('click', function () { shown().forEach(function (p) { selected[p.id] = true; }); render(); });

	post('wasmou_wc_list').then(function (r) {
		if (!r.success) { msg.className = 'wasmou-inline bad'; msg.textContent = (r.data && r.data.message) || T.error; return; }
		var d = r.data; products = d.products || [];
		msg.textContent = d.ok ? '' : d.message; if (!d.ok) { msg.className = 'wasmou-inline bad'; }
		var opts = ['<option value="">' + esc(T.all) + '</option>'];
		Object.keys(d.categories || {}).forEach(function (id) { opts.push('<option value="' + esc(id) + '">' + esc(d.categories[id]) + '</option>'); });
		cat.innerHTML = opts.join(''); render();
	}).catch(function () { msg.className = 'wasmou-inline bad'; msg.textContent = T.error; });

	btn.addEventListener('click', function () {
		var ids = Object.keys(selected), done = 0, failed = 0; if (!ids.length) { return; }
		btn.disabled = true; bar.hidden = false; bar.firstElementChild.style.width = '0%'; msg.className = 'wasmou-inline'; msg.textContent = T.importing;
		function next() {
			if (!ids.length) {
				msg.className = 'wasmou-inline ' + (failed ? 'bad' : 'ok'); msg.textContent = T.done + ': ' + done + (failed ? ' (' + failed + ' ' + T.error.toLowerCase() + ')' : '');
				selected = {}; render(); return;
			}
			var batch = ids.splice(0, 3), total = done + failed + batch.length + ids.length;
			post('wasmou_wc_import', { ids: batch }).then(function (r) {
				var res = (r.success && r.data) || {};
				batch.forEach(function (id) {
					var x = res[id]; if (x && (x.status === 'created' || x.status === 'updated' || x.status === 'skipped')) { done++; products.forEach(function (p) { if (String(p.id) === String(id) && x.status !== 'skipped') { p.imported = true; } }); } else { failed++; }
				});
			}).catch(function () { failed += batch.length; }).then(function () {
				bar.firstElementChild.style.width = Math.round((done + failed) / total * 100) + '%'; next();
			});
		}
		next();
	});
})();
