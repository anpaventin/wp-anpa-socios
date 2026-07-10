/**
 * Native wp-admin management page for "Xestión ANPA".
 *
 * Self-contained IIFE. Uses WP REST nonce for auth (X-WP-Nonce).
 * Reuses AnpaAdminTable (sort/pagination) and AnpaUtils (labels/filter/csv).
 *
 * @since  1.33.0
 * @package ANPA_Socios
 */
(function () {
	'use strict';

	// ── Config from wp_localize_script ────────────────────────────────
	var cfg = window.anpaAdminMgmt || {};
	var apiRoot = cfg.root || '/wp-json/anpa-socios/v1/admin/';
	var nonce = cfg.nonce || '';

	// ── External helpers ──────────────────────────────────────────────
	var tbl = window.AnpaAdminTable || { sortRows: function (r) { return r; }, pageSlice: function (r) { return r; } };
	var utils = window.AnpaUtils || {};
	var colLabel = utils.colLabel || function (k) { return k; };
	var filterRows = utils.filterRows || function (r) { return r; };
	var buildCsvString = utils.buildCsvString || function () { return ''; };
	var isInactiveRow = utils.isInactiveRow || function () { return false; };

	// ── DOM references ───────────────────────────────────────────────
	var root = document.getElementById('anpa-management-root');
	var navEl = document.querySelector('.anpa-mgmt-nav');
	var msgEl = document.getElementById('anpa-mgmt-message');
	if (!root) { return; }

	// ── State ────────────────────────────────────────────────────────
	var currentSection = 'socios';
	var sectionState = {};

	// ── Fetch helper (always sends X-WP-Nonce) ──────────────────────
	function anpaAdminFetch(path, opts) {
		opts = opts || {};
		var url = apiRoot + path;
		var headers = { 'X-WP-Nonce': nonce };
		if (opts.body && typeof opts.body === 'object') {
			headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify(opts.body);
		}
		return fetch(url, {
			method: opts.method || 'GET',
			headers: Object.assign(headers, opts.headers || {}),
			body: opts.body || undefined,
			credentials: 'same-origin',
		}).then(function (resp) {
			if (!resp.ok) {
				return resp.json().then(function (err) {
					throw new Error(err.message || 'Erro ' + resp.status);
				}).catch(function (e) {
					if (e.message) { throw e; }
					throw new Error('Erro ' + resp.status);
				});
			}
			var ct = resp.headers.get('content-type') || '';
			if (ct.indexOf('text/csv') !== -1) {
				return resp.blob();
			}
			return resp.json();
		});
	}

	// ── UI helpers ───────────────────────────────────────────────────
	function showMessage(text, type) {
		if (!msgEl) { return; }
		msgEl.textContent = text || '';
		msgEl.setAttribute('data-type', type || 'info');
	}

	function clearMessage() { showMessage('', 'info'); }

	function downloadBlob(blob, filename) {
		var url = URL.createObjectURL(blob);
		var a = document.createElement('a');
		a.href = url;
		a.download = filename;
		document.body.appendChild(a);
		a.click();
		document.body.removeChild(a);
		URL.revokeObjectURL(url);
	}

	function downloadCsv(filename, csv) {
		var bom = '\uFEFF';
		var blob = new Blob([bom + csv], { type: 'text/csv;charset=utf-8;' });
		downloadBlob(blob, filename);
	}

	// ── Passphrase modal ─────────────────────────────────────────────
	function promptPassphrase(title, onSubmit) {
		var overlay = document.createElement('div');
		overlay.className = 'anpa-mgmt-modal-overlay';
		var modal = document.createElement('div');
		modal.className = 'anpa-mgmt-modal';
		var h3 = document.createElement('h3');
		h3.textContent = title || 'Contrasinal de descifrado';
		modal.appendChild(h3);
		var p = document.createElement('p');
		p.textContent = 'Introduce a frase de 5 palabras da clave bancaria:';
		modal.appendChild(p);
		var input = document.createElement('input');
		input.type = 'password';
		input.autocomplete = 'off';
		modal.appendChild(input);
		var errP = document.createElement('p');
		errP.style.color = '#d63638';
		errP.style.fontSize = '13px';
		modal.appendChild(errP);
		var actions = document.createElement('div');
		actions.className = 'anpa-mgmt-modal-actions';
		var submitBtn = document.createElement('button');
		submitBtn.type = 'button';
		submitBtn.className = 'anpa-mgmt-btn';
		submitBtn.textContent = 'Descargar';
		var cancelBtn = document.createElement('button');
		cancelBtn.type = 'button';
		cancelBtn.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary';
		cancelBtn.textContent = 'Cancelar';

		cancelBtn.addEventListener('click', function () { overlay.remove(); });
		submitBtn.addEventListener('click', function () {
			var val = (input.value || '').trim();
			if (!val) { errP.textContent = 'O contrasinal non pode estar baleiro.'; return; }
			submitBtn.disabled = true;
			submitBtn.textContent = 'Procesando\u2026';
			onSubmit(val, function onDone(err) {
				if (err) {
					errP.textContent = err;
					submitBtn.disabled = false;
					submitBtn.textContent = 'Descargar';
				} else {
					overlay.remove();
				}
			});
		});
		actions.appendChild(submitBtn);
		actions.appendChild(cancelBtn);
		modal.appendChild(actions);
		overlay.appendChild(modal);
		document.body.appendChild(overlay);
		input.focus();
	}

	// ── Generic table renderer ───────────────────────────────────────
	function buildTable(rows, cols, sortState, onSortClick, rowDecorator) {
		var table = document.createElement('table');
		table.className = 'anpa-mgmt-table';
		var thead = document.createElement('thead');
		var headRow = document.createElement('tr');
		cols.forEach(function (c) {
			var th = document.createElement('th');
			th.className = 'anpa-sortable';
			var label = colLabel(c.replace(/^_/, '')) || c.replace(/^_/, '');
			if (sortState.key === c) {
				label += sortState.dir === 'asc' ? ' \u25B2' : ' \u25BC';
			}
			th.textContent = label;
			th.addEventListener('click', function () {
				if (sortState.key === c) {
					sortState.dir = sortState.dir === 'asc' ? 'desc' : 'asc';
				} else {
					sortState.key = c;
					sortState.dir = 'asc';
				}
				onSortClick();
			});
			headRow.appendChild(th);
		});
		// Actions column header
		headRow.appendChild(document.createElement('th'));
		thead.appendChild(headRow);
		table.appendChild(thead);

		var tbody = document.createElement('tbody');
		rows.forEach(function (row) {
			var tr = document.createElement('tr');
			if (rowDecorator) { rowDecorator(tr, row); }
			cols.forEach(function (c) {
				var td = document.createElement('td');
				var v = row[c];
				td.textContent = v === null || v === undefined ? '' : String(v);
				tr.appendChild(td);
			});
			var actionsTd = document.createElement('td');
			actionsTd.className = 'anpa-mgmt-actions';
			tr.appendChild(actionsTd);
			tr._actionsCell = actionsTd;
			tbody.appendChild(tr);
		});
		table.appendChild(tbody);
		return table;
	}

	// ── Filter bar builder ────────────────────────────────────────────
	function buildFilterBar(section, opts) {
		opts = opts || {};
		var bar = document.createElement('div');
		bar.className = 'anpa-mgmt-filter-bar';

		// Show inactive toggle
		if (opts.hasInactive) {
			var label = document.createElement('label');
			var cb = document.createElement('input');
			cb.type = 'checkbox';
			cb.checked = !!(sectionState[section] || {}).showInactive;
			cb.addEventListener('change', function () {
				if (!sectionState[section]) { sectionState[section] = {}; }
				sectionState[section].showInactive = cb.checked;
				opts.onRefresh();
			});
			label.appendChild(cb);
			label.appendChild(document.createTextNode(' Mostrar inactivos'));
			bar.appendChild(label);
		}

		// Count
		if (opts.activeCount !== undefined) {
			var count = document.createElement('span');
			count.style.color = '#646970';
			count.style.fontSize = '12px';
			count.textContent = '(' + opts.activeCount + ' activos de ' + opts.totalCount + ')';
			bar.appendChild(count);
		}

		// Search input
		var searchInput = document.createElement('input');
		searchInput.type = 'text';
		searchInput.placeholder = 'Buscar\u2026';
		searchInput.setAttribute('aria-label', 'Buscar na listaxe');
		bar.appendChild(searchInput);
		bar._searchInput = searchInput;

		return bar;
	}

	// ── Pagination builder ────────────────────────────────────────────
	function buildPagination(total, page, size, onPageChange) {
		var pages = Math.max(1, Math.ceil(total / (size || total)));
		var bar = document.createElement('div');
		bar.className = 'anpa-mgmt-pagination';

		var sizeLabel = document.createElement('label');
		sizeLabel.textContent = 'Por p\u00E1xina: ';
		var sel = document.createElement('select');
		[['10', '10'], ['50', '50'], ['100', '100'], ['0', 'Todos']].forEach(function (o) {
			var opt = document.createElement('option');
			opt.value = o[0];
			opt.textContent = o[1];
			if (String(size) === o[0]) { opt.selected = true; }
			sel.appendChild(opt);
		});
		sel.addEventListener('change', function () {
			onPageChange(1, parseInt(sel.value, 10) || 0);
		});
		sizeLabel.appendChild(sel);
		bar.appendChild(sizeLabel);

		var prev = document.createElement('button');
		prev.type = 'button';
		prev.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary';
		prev.textContent = 'Anterior';
		prev.disabled = page <= 1;
		prev.addEventListener('click', function () { onPageChange(page - 1, size); });
		bar.appendChild(prev);

		var info = document.createElement('span');
		info.textContent = 'P\u00E1xina ' + page + ' de ' + pages + ' (' + total + ')';
		bar.appendChild(info);

		var next = document.createElement('button');
		next.type = 'button';
		next.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary';
		next.textContent = 'Seguinte';
		next.disabled = page >= pages;
		next.addEventListener('click', function () { onPageChange(page + 1, size); });
		bar.appendChild(next);

		return bar;
	}

	// ── CSV export button ─────────────────────────────────────────────
	function addCsvExportBtn(container, section, rows, cols) {
		var btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary';
		btn.textContent = 'Exportar CSV';
		btn.addEventListener('click', function () {
			var csv = buildCsvString(rows, cols);
			downloadCsv('anpa-' + section + '.csv', csv);
		});
		container.appendChild(btn);
	}

	// ── Server CSV export (via REST) ──────────────────────────────────
	function exportServerCsv(entity, filename) {
		anpaAdminFetch('export/' + entity).then(function (blob) {
			downloadBlob(blob, filename || ('anpa-' + entity + '.csv'));
		}).catch(function (e) {
			showMessage(e.message || 'Erro ao exportar', 'error');
		});
	}

	// ── Sensitive export (requires passphrase) ────────────────────────
	function exportSensitive() {
		promptPassphrase('Descargar Socios IBAN', function (passphrase, done) {
			anpaAdminFetch('export/full', {
				method: 'POST',
				body: { passphrase: passphrase, include_banking: true },
			}).then(function (blob) {
				if (blob instanceof Blob) {
					downloadBlob(blob, 'socios-iban-' + new Date().toISOString().slice(0, 10) + '.csv');
				}
				done(null);
				showMessage('Exportación completada.', 'success');
			}).catch(function (e) {
				done(e.message || 'Erro descoñecido');
			});
		});
	}

	// ── Section: Socios ──────────────────────────────────────────────
	var SOCIOS_COLS = ['email', 'nome', 'apelidos', 'telefono', 'nif', 'estado', 'rol'];

	function loadSocios() {
		showLoading();
		anpaAdminFetch('socios').then(function (rows) {
			renderSocios(rows);
		}).catch(sectionError);
	}

	function renderSocios(rows) {
		var allRows = Array.isArray(rows) ? rows : [];
		var st = sectionState.socios || (sectionState.socios = { sort: { key: 'email', dir: 'asc' }, page: 1, size: 10 });

		function render() {
			root.textContent = '';
			var showInactive = st.showInactive;
			var active = allRows.filter(function (r) { return !isInactiveRow('socios', r); });
			var visible = showInactive ? allRows : active;

			var bar = buildFilterBar('socios', {
				hasInactive: true,
				activeCount: active.length,
				totalCount: allRows.length,
				onRefresh: render,
			});
			root.appendChild(bar);

			// Export buttons
			addCsvExportBtn(bar, 'socios', visible, SOCIOS_COLS);
			var ibanBtn = document.createElement('button');
			ibanBtn.type = 'button';
			ibanBtn.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary';
			ibanBtn.textContent = 'Descargar Socios IBAN';
			ibanBtn.addEventListener('click', exportSensitive);
			bar.appendChild(ibanBtn);

			var query = bar._searchInput.value || '';
			var filtered = filterRows(visible, query, SOCIOS_COLS);
			var sorted = tbl.sortRows(filtered, st.sort.key, st.sort.dir);
			var paged = tbl.pageSlice(sorted, st.page, st.size || 0);

			if (!sorted.length) {
				var emptyP = document.createElement('p');
				emptyP.className = 'anpa-mgmt-empty';
				emptyP.textContent = 'Sen resultados.';
				root.appendChild(emptyP);
				return;
			}

			var table = buildTable(paged, SOCIOS_COLS, st.sort, render, function (tr, row) {
				if (row.estado === 'baixa') { tr.classList.add('anpa-row-baixa'); }
				else if (row.estado === 'pendiente_alta') { tr.classList.add('anpa-row-pendente'); }
				else if (row.baixa_estado === 'solicitada') { tr.classList.add('anpa-row-baixa-pending'); }
			});

			// Add edit buttons per row
			var tbodyRows = table.querySelectorAll('tbody tr');
			paged.forEach(function (row, i) {
				var actionsTd = tbodyRows[i] ? tbodyRows[i]._actionsCell || tbodyRows[i].lastElementChild : null;
				if (!actionsTd) { return; }
				var editBtn = document.createElement('button');
				editBtn.type = 'button';
				editBtn.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary';
				editBtn.textContent = 'Editar';
				editBtn.addEventListener('click', function () { renderSocioEdit(row, allRows); });
				actionsTd.appendChild(editBtn);
			});
			root.appendChild(table);

			if (st.size > 0 && sorted.length > st.size) {
				root.appendChild(buildPagination(sorted.length, st.page, st.size, function (p, s) {
					st.page = p; st.size = s; render();
				}));
			}

			// Wire search
			var timer = null;
			bar._searchInput.addEventListener('input', function () {
				if (timer) { clearTimeout(timer); }
				timer = setTimeout(function () { st.page = 1; render(); }, 250);
			});
		}
		render();
	}

	// ── Socio inline edit form ────────────────────────────────────────
	function renderSocioEdit(socio, allRows) {
		root.textContent = '';
		var form = document.createElement('div');
		form.className = 'anpa-mgmt-form';
		var h3 = document.createElement('h3');
		h3.textContent = 'Editar socio/a: ' + (socio.email || '');
		form.appendChild(h3);

		function addField(labelText, input) {
			var lbl = document.createElement('label');
			lbl.textContent = labelText;
			form.appendChild(lbl);
			form.appendChild(input);
		}

		var nome = document.createElement('input'); nome.type = 'text'; nome.value = socio.nome || ''; addField('Nome', nome);
		var apelidos = document.createElement('input'); apelidos.type = 'text'; apelidos.value = socio.apelidos || ''; addField('Apelidos', apelidos);
		var telefono = document.createElement('input'); telefono.type = 'tel'; telefono.value = socio.telefono || ''; addField('Tel\u00E9fono', telefono);
		var nifInput = document.createElement('input'); nifInput.type = 'text'; nifInput.value = socio.nif || ''; addField('NIF / NIE', nifInput);

		var estado = document.createElement('select');
		['activo', 'pendiente_alta', 'baixa'].forEach(function (v) {
			var opt = document.createElement('option'); opt.value = v; opt.textContent = v;
			if (socio.estado === v) { opt.selected = true; }
			estado.appendChild(opt);
		});
		addField('Estado', estado);

		var rolNote = document.createElement('p');
		rolNote.style.color = '#646970'; rolNote.style.fontSize = '12px';
		rolNote.textContent = 'Rol: ' + (socio.rol || 'socio') + ' (x\u00E9stionase desde Administradores).';
		form.appendChild(rolNote);

		var actions = document.createElement('div');
		actions.className = 'anpa-mgmt-form-actions';
		var saveBtn = document.createElement('button');
		saveBtn.type = 'button'; saveBtn.className = 'anpa-mgmt-btn';
		saveBtn.textContent = 'Gardar cambios';
		saveBtn.addEventListener('click', function () {
			clearMessage();
			var payload = {
				nome: (nome.value || '').trim(),
				apelidos: (apelidos.value || '').trim(),
				telefono: (telefono.value || '').trim(),
				nif: (nifInput.value || '').trim(),
				estado: estado.value,
				rol: socio.rol || 'socio',
			};
			if (!payload.nome || !payload.apelidos) {
				showMessage('Nome e apelidos son obrigatorios.', 'error'); return;
			}
			anpaAdminFetch('socio/' + encodeURIComponent(socio.email), {
				method: 'PATCH', body: payload,
			}).then(function () {
				showMessage('Socio/a actualizado.', 'success');
				loadSocios();
			}).catch(function (e) { showMessage(e.message, 'error'); });
		});
		actions.appendChild(saveBtn);

		var cancelBtn = document.createElement('button');
		cancelBtn.type = 'button'; cancelBtn.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary';
		cancelBtn.textContent = 'Volver';
		cancelBtn.addEventListener('click', function () { renderSocios(allRows); });
		actions.appendChild(cancelBtn);
		form.appendChild(actions);
		root.appendChild(form);
	}

	// ── Section: Aprobacións ─────────────────────────────────────────
	function loadApprovals() {
		showLoading();
		anpaAdminFetch('approvals').then(function (rows) {
			renderApprovals(rows);
		}).catch(sectionError);
	}

	function renderApprovals(rows) {
		root.textContent = '';
		var list = Array.isArray(rows) ? rows : [];
		var h3 = document.createElement('h3');
		h3.textContent = 'Socios/as pendentes de aprobaci\u00F3n';
		root.appendChild(h3);
		if (!list.length) {
			var emptyP = document.createElement('p');
			emptyP.className = 'anpa-mgmt-empty';
			emptyP.textContent = 'Non hai solicitudes pendentes.';
			root.appendChild(emptyP);
			return;
		}
		var COLS = ['email', 'nome', 'apelidos', 'telefono', 'creado_en'];
		var table = document.createElement('table');
		table.className = 'anpa-mgmt-table';
		var thead = document.createElement('thead');
		var hr = document.createElement('tr');
		var thCheck = document.createElement('th');
		var selectAll = document.createElement('input');
		selectAll.type = 'checkbox';
		thCheck.appendChild(selectAll);
		hr.appendChild(thCheck);
		COLS.forEach(function (c) {
			var th = document.createElement('th');
			th.textContent = colLabel(c) || c;
			hr.appendChild(th);
		});
		thead.appendChild(hr);
		table.appendChild(thead);

		var tbody = document.createElement('tbody');
		list.forEach(function (row) {
			var tr = document.createElement('tr');
			var tdCb = document.createElement('td');
			var cb = document.createElement('input');
			cb.type = 'checkbox'; cb.className = 'anpa-approval-cb'; cb.value = row.email || '';
			tdCb.appendChild(cb); tr.appendChild(tdCb);
			COLS.forEach(function (c) {
				var td = document.createElement('td');
				td.textContent = row[c] != null ? String(row[c]) : '';
				tr.appendChild(td);
			});
			tbody.appendChild(tr);
		});
		table.appendChild(tbody);
		root.appendChild(table);
		selectAll.addEventListener('change', function () {
			root.querySelectorAll('.anpa-approval-cb').forEach(function (c) { c.checked = selectAll.checked; });
		});

		function selectedEmails() {
			var out = [];
			root.querySelectorAll('.anpa-approval-cb:checked').forEach(function (c) { if (c.value) { out.push(c.value); } });
			return out;
		}
		function processApprovals(mode) {
			var emails = selectedEmails();
			if (!emails.length) { showMessage('Selecciona polo menos unha solicitude.', 'error'); return; }
			if (!window.confirm((mode === 'approve' ? 'Aprobar' : 'Rexeitar') + ' ' + emails.length + ' solicitude(s)?')) { return; }
			anpaAdminFetch('approvals/' + mode, { method: 'POST', body: { emails: emails } }).then(function (r) {
				showMessage((mode === 'approve' ? 'Aprobadas' : 'Rexeitadas') + ': ' + (r.processed || 0) + '.', 'success');
				loadApprovals();
			}).catch(function (e) { showMessage(e.message, 'error'); });
		}
		var acts = document.createElement('div'); acts.style.marginTop = '0.8rem'; acts.style.display = 'flex'; acts.style.gap = '0.5rem';
		var approveBtn = document.createElement('button'); approveBtn.type = 'button'; approveBtn.className = 'anpa-mgmt-btn'; approveBtn.textContent = 'Aprobar seleccionados';
		approveBtn.addEventListener('click', function () { processApprovals('approve'); });
		var rejectBtn = document.createElement('button'); rejectBtn.type = 'button'; rejectBtn.className = 'anpa-mgmt-btn anpa-mgmt-btn-danger'; rejectBtn.textContent = 'Rexeitar seleccionados';
		rejectBtn.addEventListener('click', function () { processApprovals('reject'); });
		acts.appendChild(approveBtn); acts.appendChild(rejectBtn);
		root.appendChild(acts);
	}

	// ── Section: Fillos ──────────────────────────────────────────────
	var FILLOS_COLS = ['proxenitor_apelidos', 'proxenitor_nome', 'fillo_apelidos', 'fillo_nome', 'data_nacemento', 'curso', 'aula', 'estado'];

	function loadFillos() {
		showLoading();
		anpaAdminFetch('fillos').then(function (rows) { renderFillos(rows); }).catch(sectionError);
	}

	function renderFillos(rows) {
		var allRows = Array.isArray(rows) ? rows : [];
		var st = sectionState.fillos || (sectionState.fillos = { sort: { key: 'proxenitor_apelidos', dir: 'asc' } });
		function render() {
			root.textContent = '';
			var bar = buildFilterBar('fillos', { onRefresh: render });
			root.appendChild(bar);
			addCsvExportBtn(bar, 'fillos', allRows, FILLOS_COLS);
			var query = bar._searchInput.value || '';
			var filtered = filterRows(allRows, query, FILLOS_COLS);
			var sorted = tbl.sortRows(filtered, st.sort.key, st.sort.dir);
			if (!sorted.length) { root.appendChild(emptyEl('Sen fillos/as rexistrados.')); return; }
			var table = buildTable(sorted, FILLOS_COLS, st.sort, render, null);
			root.appendChild(table);
			var timer = null;
			bar._searchInput.addEventListener('input', function () {
				if (timer) { clearTimeout(timer); }
				timer = setTimeout(render, 250);
			});
		}
		render();
	}

	// ── Section: Empresas ────────────────────────────────────────────
	var EMPRESAS_COLS = ['nome', 'email', 'responsable', 'telefono', 'estado'];

	function loadEmpresas() {
		showLoading();
		anpaAdminFetch('empresas').then(function (rows) { renderEmpresas(rows); }).catch(sectionError);
	}

	function renderEmpresas(rows) {
		var allRows = Array.isArray(rows) ? rows : [];
		var st = sectionState.empresas || (sectionState.empresas = { sort: { key: 'nome', dir: 'asc' } });
		function render() {
			root.textContent = '';
			var active = allRows.filter(function (r) { return r.estado !== 'inactivo'; });
			var visible = st.showInactive ? allRows : active;
			var bar = buildFilterBar('empresas', { hasInactive: true, activeCount: active.length, totalCount: allRows.length, onRefresh: render });
			root.appendChild(bar);
			addCsvExportBtn(bar, 'empresas', visible, EMPRESAS_COLS);
			var query = bar._searchInput.value || '';
			var filtered = filterRows(visible, query, EMPRESAS_COLS);
			var sorted = tbl.sortRows(filtered, st.sort.key, st.sort.dir);
			if (!sorted.length) { root.appendChild(emptyEl('Sen empresas.')); return; }
			var table = buildTable(sorted, EMPRESAS_COLS, st.sort, render, function (tr, row) {
				if (row.estado === 'inactivo') { tr.classList.add('anpa-row-baixa'); }
			});
			root.appendChild(table);
			var timer = null;
			bar._searchInput.addEventListener('input', function () {
				if (timer) { clearTimeout(timer); }
				timer = setTimeout(render, 250);
			});
		}
		render();
	}

	// ── Section: Actividades ─────────────────────────────────────────
	var ACTIV_COLS = ['nome', '_empresa_nome', 'curso_escolar', 'franxa', 'estado'];

	function loadActividades() {
		showLoading();
		Promise.all([
			anpaAdminFetch('actividades'),
			anpaAdminFetch('empresas'),
		]).then(function (results) {
			renderActividades(results[0], results[1]);
		}).catch(sectionError);
	}

	function renderActividades(rows, empresas) {
		var allRows = Array.isArray(rows) ? rows : [];
		var empresaList = Array.isArray(empresas) ? empresas : [];
		var empresaNome = {};
		empresaList.forEach(function (e) { empresaNome[String(e.id)] = e.nome; });
		allRows.forEach(function (r) { r._empresa_nome = empresaNome[String(r.empresa_id)] || ''; });

		var st = sectionState.actividades || (sectionState.actividades = { sort: { key: 'nome', dir: 'asc' } });
		function render() {
			root.textContent = '';
			var active = allRows.filter(function (r) { return r.estado !== 'inactivo'; });
			var visible = st.showInactive ? allRows : active;
			var bar = buildFilterBar('actividades', { hasInactive: true, activeCount: active.length, totalCount: allRows.length, onRefresh: render });
			root.appendChild(bar);
			addCsvExportBtn(bar, 'actividades', visible, ACTIV_COLS);
			var query = bar._searchInput.value || '';
			var filtered = filterRows(visible, query, ACTIV_COLS);
			var sorted = tbl.sortRows(filtered, st.sort.key, st.sort.dir);
			if (!sorted.length) { root.appendChild(emptyEl('Sen actividades.')); return; }
			var table = buildTable(sorted, ACTIV_COLS, st.sort, render, function (tr, row) {
				if (row.estado === 'inactivo') { tr.classList.add('anpa-row-baixa'); }
			});
			root.appendChild(table);
			var timer = null;
			bar._searchInput.addEventListener('input', function () {
				if (timer) { clearTimeout(timer); }
				timer = setTimeout(render, 250);
			});
		}
		render();
	}

	// ── Section: Cursos e matrículas ─────────────────────────────────
	function loadCursos() {
		showLoading();
		anpaAdminFetch('cursos').then(function (data) { renderCursos(data); }).catch(sectionError);
	}

	function renderCursos(data) {
		root.textContent = '';
		var current = (data && data.current) || '';
		var list = data && Array.isArray(data.cursos) ? data.cursos : [];

		var h3 = document.createElement('h3');
		h3.textContent = 'Cursos escolares (actual: ' + current + ')';
		root.appendChild(h3);

		if (!list.length) { root.appendChild(emptyEl('Sen cursos.')); return; }

		var COLS = ['curso_escolar', '_estado_display', 'actualizado_en'];
		list.forEach(function (r) {
			r._estado_display = r.matriculas_abertas ? 'Aberto' : 'Pechado';
		});
		var st = sectionState.cursos || (sectionState.cursos = { sort: { key: 'curso_escolar', dir: 'desc' } });
		var sorted = tbl.sortRows(list, st.sort.key, st.sort.dir);
		var table = buildTable(sorted, COLS, st.sort, function () { renderCursos(data); }, function (tr, row) {
			if (!row.matriculas_abertas) { tr.classList.add('anpa-row-baixa'); }
		});
		// Add toggle button per row
		var tbodyRows = table.querySelectorAll('tbody tr');
		sorted.forEach(function (row, i) {
			var cell = tbodyRows[i] ? tbodyRows[i]._actionsCell || tbodyRows[i].lastElementChild : null;
			if (!cell) { return; }
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary';
			btn.textContent = row.matriculas_abertas ? 'Pechar' : 'Abrir';
			btn.addEventListener('click', function () {
				if (!window.confirm((row.matriculas_abertas ? 'Pechar' : 'Abrir') + ' matr\u00EDcula para ' + row.curso_escolar + '?')) { return; }
				anpaAdminFetch('curso', { method: 'PUT', body: { curso_escolar: row.curso_escolar, matriculas_abertas: !row.matriculas_abertas } })
					.then(function () { showMessage('Curso actualizado.', 'success'); loadCursos(); })
					.catch(function (e) { showMessage(e.message, 'error'); });
			});
			cell.appendChild(btn);
		});
		root.appendChild(table);

		// Matriculas sub-section
		var hr = document.createElement('hr');
		root.appendChild(hr);
		var h3b = document.createElement('h3');
		h3b.textContent = 'Matr\u00EDculas';
		root.appendChild(h3b);
		var selLabel = document.createElement('label');
		selLabel.textContent = 'Curso: ';
		var cursoSel = document.createElement('select');
		list.forEach(function (c) {
			var o = document.createElement('option'); o.value = c.curso_escolar;
			o.textContent = c.curso_escolar + (c.actual ? ' (actual)' : '');
			if (c.curso_escolar === current) { o.selected = true; }
			cursoSel.appendChild(o);
		});
		selLabel.appendChild(cursoSel);
		root.appendChild(selLabel);
		var loadBtn = document.createElement('button'); loadBtn.type = 'button';
		loadBtn.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary'; loadBtn.textContent = 'Ver matr\u00EDculas';
		loadBtn.style.marginLeft = '0.5rem';
		root.appendChild(loadBtn);
		var matHost = document.createElement('div');
		root.appendChild(matHost);

		var MAT_COLS = ['fillo_apelidos', 'fillo_nome', 'actividade', 'curso_completo', 'estado', 'franxa', 'dias', 'trimestre'];

		function loadMat(curso) {
			matHost.textContent = '';
			matHost.innerHTML = '<p class="anpa-mgmt-loading">Cargando\u2026</p>';
			anpaAdminFetch('matriculas?curso=' + encodeURIComponent(curso)).then(function (rows) {
				matHost.textContent = '';
				var matRows = Array.isArray(rows) ? rows : [];
				if (!matRows.length) { matHost.appendChild(emptyEl('Sen matr\u00EDculas.')); return; }
				var matSt = sectionState.matriculas || (sectionState.matriculas = { sort: { key: 'fillo_apelidos', dir: 'asc' } });
				var bar = buildFilterBar('matriculas', { onRefresh: function () { loadMat(curso); } });
				matHost.appendChild(bar);
				addCsvExportBtn(bar, 'matriculas', matRows, MAT_COLS);
				var query = bar._searchInput.value || '';
				var filtered = filterRows(matRows, query, MAT_COLS);
				var sorted = tbl.sortRows(filtered, matSt.sort.key, matSt.sort.dir);
				var matTable = buildTable(sorted, MAT_COLS, matSt.sort, function () { loadMat(curso); }, null);
				matHost.appendChild(matTable);
			}).catch(function (e) { matHost.textContent = ''; showMessage(e.message, 'error'); });
		}
		loadBtn.addEventListener('click', function () { loadMat(cursoSel.value || current); });
		loadMat(current);
	}

	// ── Section: Administradores ─────────────────────────────────────
	var ADMIN_COLS = ['email', 'nome', 'apelidos', 'estado'];

	function loadAdmins() {
		showLoading();
		anpaAdminFetch('admins').then(function (rows) { renderAdmins(rows); }).catch(sectionError);
	}

	function renderAdmins(rows) {
		var allRows = Array.isArray(rows) ? rows : [];
		var st = sectionState.admins || (sectionState.admins = { sort: { key: 'email', dir: 'asc' } });
		function render() {
			root.textContent = '';
			var bar = buildFilterBar('admins', { onRefresh: render });
			root.appendChild(bar);
			var query = bar._searchInput.value || '';
			var filtered = filterRows(allRows, query, ADMIN_COLS);
			var sorted = tbl.sortRows(filtered, st.sort.key, st.sort.dir);
			if (!sorted.length) { root.appendChild(emptyEl('Sen administradores.')); return; }
			var table = buildTable(sorted, ADMIN_COLS, st.sort, render, null);
			// Revoke buttons
			var tbodyRows = table.querySelectorAll('tbody tr');
			sorted.forEach(function (row, i) {
				var cell = tbodyRows[i] ? tbodyRows[i]._actionsCell || tbodyRows[i].lastElementChild : null;
				if (!cell) { return; }
				var revokeBtn = document.createElement('button');
				revokeBtn.type = 'button';
				revokeBtn.className = 'anpa-mgmt-btn anpa-mgmt-btn-danger';
				revokeBtn.textContent = 'Revogar';
				revokeBtn.addEventListener('click', function () {
					if (!window.confirm('Revogar permisos de ' + (row.email || '') + '?')) { return; }
					anpaAdminFetch('admins/' + encodeURIComponent(row.email), { method: 'DELETE' })
						.then(function () { showMessage('Administrador revogado.', 'success'); loadAdmins(); })
						.catch(function (e) { showMessage(e.message, 'error'); });
				});
				cell.appendChild(revokeBtn);
			});
			root.appendChild(table);
			var timer = null;
			bar._searchInput.addEventListener('input', function () {
				if (timer) { clearTimeout(timer); }
				timer = setTimeout(render, 250);
			});
		}
		render();
	}

	// ── Section: Auditoría ───────────────────────────────────────────
	var AUDIT_COLS = ['timestamp', 'actor_email', 'accion', 'target_tipo', 'target_id'];

	function loadAudit() {
		showLoading();
		anpaAdminFetch('audit?limit=100').then(function (rows) { renderAudit(rows); }).catch(sectionError);
	}

	function renderAudit(rows) {
		var allRows = Array.isArray(rows) ? rows : [];
		var st = sectionState.audit || (sectionState.audit = { sort: { key: 'timestamp', dir: 'desc' } });
		function render() {
			root.textContent = '';
			var bar = buildFilterBar('audit', { onRefresh: render });
			root.appendChild(bar);
			var query = bar._searchInput.value || '';
			var filtered = filterRows(allRows, query, AUDIT_COLS);
			var sorted = tbl.sortRows(filtered, st.sort.key, st.sort.dir);
			if (!sorted.length) { root.appendChild(emptyEl('Sen entradas de auditor\u00EDa.')); return; }
			var table = buildTable(sorted, AUDIT_COLS, st.sort, render, null);
			root.appendChild(table);
			var timer = null;
			bar._searchInput.addEventListener('input', function () {
				if (timer) { clearTimeout(timer); }
				timer = setTimeout(render, 250);
			});
		}
		render();
	}

	// ── Section: Importar listados (placeholder) ────────────────────
	function loadImportar() {
		root.textContent = '';
		var h3 = document.createElement('h3');
		h3.textContent = 'Importar listados';
		root.appendChild(h3);
		var p = document.createElement('p');
		p.className = 'anpa-mgmt-empty';
		p.textContent = 'Funci\u00F3n de importaci\u00F3n masiva en desenvolvemento. Pr\u00F3ximamente poder\u00E1s importar listados de socios, fillos, empresas e actividades desde CSV.';
		root.appendChild(p);
	}

	// ── Helpers ───────────────────────────────────────────────────────
	function emptyEl(text) {
		var p = document.createElement('p');
		p.className = 'anpa-mgmt-empty';
		p.textContent = text;
		return p;
	}

	function showLoading() {
		root.textContent = '';
		var p = document.createElement('p');
		p.className = 'anpa-mgmt-loading';
		p.textContent = 'Cargando\u2026';
		root.appendChild(p);
	}

	function sectionError(e) {
		root.textContent = '';
		showMessage(e.message || 'Erro ao cargar a secci\u00F3n.', 'error');
	}

	// ── Section router ───────────────────────────────────────────────
	var SECTION_MAP = {
		'socios': loadSocios,
		'aprobacions': loadApprovals,
		'fillos': loadFillos,
		'empresas': loadEmpresas,
		'actividades': loadActividades,
		'cursos-matriculas': loadCursos,
		'administradores': loadAdmins,
		'auditoria': loadAudit,
		'importar-listados': loadImportar,
	};

	function navigateTo(section) {
		currentSection = section;
		clearMessage();
		// Update nav active state
		if (navEl) {
			navEl.querySelectorAll('button').forEach(function (btn) {
				btn.setAttribute('aria-selected', btn.dataset.section === section ? 'true' : 'false');
			});
		}
		var loader = SECTION_MAP[section];
		if (loader) { loader(); }
		else { root.textContent = ''; root.appendChild(emptyEl('Secci\u00F3n non dispo\u00F1ible.')); }
	}

	// ── Wire navigation buttons ──────────────────────────────────────
	if (navEl) {
		navEl.querySelectorAll('button[data-section]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				navigateTo(btn.dataset.section);
			});
		});
	}

	// ── Initial load ─────────────────────────────────────────────────
	navigateTo('socios');

})();
