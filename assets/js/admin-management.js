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
	var SOCIOS_COLS = ['email', 'nome', 'apelidos', 'telefono', 'nif', 'segundo_proxenitor_nome', 'segundo_proxenitor_email', 'segundo_proxenitor_nif', 'estado'];

	function loadSocios() {
		showLoading();
		anpaAdminFetch('socios').then(function (rows) {
			renderSocios(rows);
		}).catch(sectionError);
	}

	function renderSocios(rows) {
		var allRows = Array.isArray(rows) ? rows : [];
		// Flatten segundo_proxenitor for display.
		allRows.forEach(function (r) {
			if (r.segundo_proxenitor) {
				var sp = r.segundo_proxenitor;
				r.segundo_proxenitor_nome = (sp.nome || '') + ' ' + (sp.apelidos || '');
				r.segundo_proxenitor_email = sp.email || '';
				r.segundo_proxenitor_nif = sp.nif || '';
				if (!sp.email && !sp.nif) {
					r.segundo_proxenitor_nome += ' \u26A0';
				}
			} else {
				r.segundo_proxenitor_nome = '';
				r.segundo_proxenitor_email = '';
				r.segundo_proxenitor_nif = '';
			}
		});
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
			addCsvImportBtn(bar, 'socios');
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
		document.title = 'Editar socio/a — Xestión ANPA';
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

		// ── 2nd parent section ──
		var sp = socio.segundo_proxenitor || {};
		var h3p2 = document.createElement('h3');
		h3p2.textContent = '2\u00BA proxenitor/titor';
		h3p2.style.marginTop = '1.5rem';
		form.appendChild(h3p2);
		var p2desc = document.createElement('p');
		p2desc.style.fontSize = '12px';
		p2desc.style.color = '#646970';
		p2desc.textContent = 'Se o socio/a ten un segundo proxenitor/titor, completa os seus datos. Deixa baleiro se non.';
		form.appendChild(p2desc);

		var p2nome = document.createElement('input'); p2nome.type = 'text'; p2nome.value = sp.nome || ''; addField('Nome (2\u00BA)', p2nome);
		var p2apelidos = document.createElement('input'); p2apelidos.type = 'text'; p2apelidos.value = sp.apelidos || ''; addField('Apelidos (2\u00BA)', p2apelidos);
		var p2email = document.createElement('input'); p2email.type = 'email'; p2email.value = sp.email || ''; addField('Email (2\u00BA) \u2014 Se est\u00E1 baleiro, non ter\u00E1 acceso \u00E1 \u00E1rea persoal', p2email);
		var p2nif = document.createElement('input'); p2nif.type = 'text'; p2nif.value = sp.nif || ''; addField('NIF / NIE (2\u00BA)', p2nif);
		var p2tel = document.createElement('input'); p2tel.type = 'tel'; p2tel.value = sp.telefono || ''; addField('Tel\u00E9fono (2\u00BA)', p2tel);

		// ── Fillos section (placeholder, loaded async) ──
		var h3f = document.createElement('h3');
		h3f.textContent = 'Fillos/as';
		h3f.style.marginTop = '1.5rem';
		form.appendChild(h3f);
		var fillosContainer = document.createElement('div');
		fillosContainer.innerHTML = '<p class="anpa-mgmt-loading">Cargando fillos/as\u2026</p>';
		form.appendChild(fillosContainer);

		var actions = document.createElement('div');
		actions.className = 'anpa-mgmt-form-actions';
		var saveBtn = document.createElement('button');
		saveBtn.type = 'button'; saveBtn.className = 'anpa-mgmt-btn';
		saveBtn.textContent = 'Gardar cambios';
		saveBtn.addEventListener('click', function () {
			clearMessage();
			var p2data = {};
			var p2nomeV = (p2nome.value || '').trim();
			var p2apelidosV = (p2apelidos.value || '').trim();
			if (p2nomeV || p2apelidosV) {
				p2data = {
					nome: p2nomeV,
					apelidos: p2apelidosV,
					email: (p2email.value || '').trim() || null,
					nif: (p2nif.value || '').trim() || null,
					telefono: (p2tel.value || '').trim() || null,
				};
			}
			var payload = {
				nome: (nome.value || '').trim(),
				apelidos: (apelidos.value || '').trim(),
				telefono: (telefono.value || '').trim(),
				nif: (nifInput.value || '').trim(),
				estado: estado.value,
				rol: socio.rol || 'socio',
			};
			if (Object.keys(p2data).length > 0) {
				payload.segundo_proxenitor = p2data;
			}
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

		// ── Load fillos (async) ──
		anpaAdminFetch('socio/' + encodeURIComponent(socio.email) + '/fillos').then(function (fillos) {
			renderFillosInline(fillosContainer, fillos, socio);
		}).catch(function (e) {
			fillosContainer.textContent = '';
			fillosContainer.appendChild(emptyEl('Erro ao cargar fillos/as: ' + e.message));
		});
	}

	/**
	 * Renders fillo list (inline table + add button) inside the socio edit form.
	 * @param {HTMLElement} container - The fillos container div.
	 * @param {Array} fillos - Fillos array from REST.
	 * @param {object} socio - Current socio being edited.
	 */
	function renderFillosInline(container, fillos, socio) {
		container.textContent = '';
		var list = Array.isArray(fillos) ? fillos : [];

		var addBtn = document.createElement('button');
		addBtn.type = 'button';
		addBtn.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary';
		addBtn.textContent = 'Novo fillo/a';
		addBtn.addEventListener('click', function () {
			renderFilloInlineForm(container, null, socio);
		});
		container.appendChild(addBtn);

		if (!list.length) {
			container.appendChild(emptyEl('Sen fillos/as rexistrados.'));
			return;
		}

		var table = document.createElement('table');
		table.className = 'anpa-mgmt-table';
		var thead = document.createElement('thead');
		var hr = document.createElement('tr');
		['Nome', 'Apelidos', 'Data nacemento', 'Curso', 'Grupo', 'Estado', ''].forEach(function (t) {
			var th = document.createElement('th'); th.textContent = t; hr.appendChild(th);
		});
		thead.appendChild(hr); table.appendChild(thead);

		var tbody = document.createElement('tbody');
		list.forEach(function (f) {
			var tr = document.createElement('tr');
			if (f.estado === 'baixa') { tr.classList.add('anpa-row-baixa'); }
			[f.nome, f.apelidos, f.data_nacemento, f.curso, f.aula, f.estado].forEach(function (v) {
				var td = document.createElement('td'); td.textContent = v || ''; tr.appendChild(td);
			});
			var actionsTd = document.createElement('td');
			actionsTd.className = 'anpa-mgmt-actions';

			var editBtn = document.createElement('button');
			editBtn.type = 'button'; editBtn.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary';
			editBtn.textContent = 'Editar';
			editBtn.addEventListener('click', function () {
				renderFilloInlineForm(container, f, socio);
			});
			actionsTd.appendChild(editBtn);

			var delBtn = document.createElement('button');
			delBtn.type = 'button'; delBtn.className = 'anpa-mgmt-btn anpa-mgmt-btn-danger';
			delBtn.textContent = 'Eliminar';
			delBtn.addEventListener('click', function () {
				if (!window.confirm('Eliminar a ' + (f.nome || '') + ' ' + (f.apelidos || '') + '? (baixa l\u00F3xica)')) { return; }
				anpaAdminFetch('fillo/' + f.id, { method: 'DELETE' }).then(function () {
					showMessage('Fillo/a dado de baixa.', 'success');
					anpaAdminFetch('socio/' + encodeURIComponent(socio.email) + '/fillos').then(function (newFillos) {
						renderFillosInline(container, newFillos, socio);
					}).catch(function (e) { showMessage(e.message, 'error'); });
				}).catch(function (e) { showMessage(e.message, 'error'); });
			});
			actionsTd.appendChild(delBtn);

			tr.appendChild(actionsTd);
			tbody.appendChild(tr);
		});
		table.appendChild(tbody);
		container.appendChild(table);
	}

	/**
	 * Renders create/edit form for a fillo inside the socio edit form.
	 * @param {HTMLElement} container - The fillos container div.
	 * @param {object|null} fillo - Existing fillo row, or null for create.
	 * @param {object} socio - Current socio (for POST email).
	 */
	function renderFilloInlineForm(container, fillo, socio) {
		container.textContent = '';
		var isEdit = fillo !== null;
		var form = document.createElement('div');
		form.className = 'anpa-mgmt-form-inline';
		form.style.border = '1px solid #dcdcde';
		form.style.padding = '1rem';
		form.style.marginTop = '0.5rem';
		form.style.borderRadius = '4px';
		form.style.backgroundColor = '#f6f7f7';

		var title = document.createElement('h4');
		title.textContent = isEdit ? 'Editar fillo/a' : 'Novo fillo/a';
		title.style.marginTop = '0';
		form.appendChild(title);

		function addInlineField(labelText, input) {
			var lbl = document.createElement('label');
			lbl.style.display = 'block';
			lbl.style.marginTop = '0.5rem';
			lbl.textContent = labelText;
			lbl.appendChild(input);
			form.appendChild(lbl);
		}

		var nomeInput = document.createElement('input'); nomeInput.type = 'text'; nomeInput.value = isEdit ? (fillo.nome || '') : '';
		addInlineField('Nome', nomeInput);

		var apelidosInput = document.createElement('input'); apelidosInput.type = 'text'; apelidosInput.value = isEdit ? (fillo.apelidos || '') : '';
		addInlineField('Apelidos', apelidosInput);

		var nacemInput = document.createElement('input'); nacemInput.type = 'date'; nacemInput.value = isEdit ? (fillo.data_nacemento || '') : '';
		addInlineField('Data nacemento', nacemInput);

		var cursoInput = document.createElement('input'); cursoInput.type = 'text'; cursoInput.value = isEdit ? (fillo.curso || '') : '';
		addInlineField('Curso', cursoInput);

		var aulaInput = document.createElement('input'); aulaInput.type = 'text'; aulaInput.value = isEdit ? (fillo.aula || '') : '';
		addInlineField('Grupo', aulaInput);

		var estadoSel = document.createElement('select');
		['activo', 'baixa'].forEach(function (v) {
			var opt = document.createElement('option'); opt.value = v; opt.textContent = v;
			if (isEdit && fillo.estado === v) { opt.selected = true; }
			estadoSel.appendChild(opt);
		});
		addInlineField('Estado', estadoSel);

		var formActions = document.createElement('div');
		formActions.className = 'anpa-mgmt-form-actions';
		formActions.style.marginTop = '0.75rem';

		var saveBtn = document.createElement('button');
		saveBtn.type = 'button'; saveBtn.className = 'anpa-mgmt-btn';
		saveBtn.textContent = isEdit ? 'Gardar' : 'Engadir';
		saveBtn.addEventListener('click', function () {
			clearMessage();
			var payload = {
				nome: (nomeInput.value || '').trim(),
				apelidos: (apelidosInput.value || '').trim(),
				data_nacemento: (nacemInput.value || '').trim(),
				curso: (cursoInput.value || '').trim(),
				aula: (aulaInput.value || '').trim(),
				estado: estadoSel.value,
			};
			if (!payload.nome || !payload.apelidos) {
				showMessage('Nome e apelidos son obrigatorios.', 'error'); return;
			}
			var method = isEdit ? 'PATCH' : 'POST';
			var path = isEdit ? 'fillo/' + fillo.id : 'socio/' + encodeURIComponent(socio.email) + '/fillos';
			anpaAdminFetch(path, { method: method, body: payload }).then(function () {
				showMessage(isEdit ? 'Fillo/a actualizado.' : 'Fillo/a creado.', 'success');
				// Reload fillos list
				anpaAdminFetch('socio/' + encodeURIComponent(socio.email) + '/fillos').then(function (newFillos) {
					renderFillosInline(container, newFillos, socio);
				}).catch(function (e) { showMessage(e.message, 'error'); });
			}).catch(function (e) { showMessage(e.message, 'error'); });
		});
		formActions.appendChild(saveBtn);

		var cancelBtn = document.createElement('button');
		cancelBtn.type = 'button'; cancelBtn.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary';
		cancelBtn.textContent = 'Cancelar';
		cancelBtn.addEventListener('click', function () {
			// Re-render fillos list
			anpaAdminFetch('socio/' + encodeURIComponent(socio.email) + '/fillos').then(function (newFillos) {
				renderFillosInline(container, newFillos, socio);
			}).catch(function (e) { showMessage(e.message, 'error'); });
		});
		formActions.appendChild(cancelBtn);
		form.appendChild(formActions);
		container.appendChild(form);
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
	var FILLOS_COLS = ['proxenitor_apelidos', 'proxenitor_nome', 'socio_email', 'apelidos', 'nome', 'data_nacemento', 'curso', 'aula', 'estado'];

	function loadFillos() {
		showLoading();
		anpaAdminFetch('fillos').then(function (rows) { renderFillos(rows); }).catch(sectionError);
	}

	function renderFillos(rows) {
		var allRows = Array.isArray(rows) ? rows : [];
		var st = sectionState.fillos || (sectionState.fillos = { sort: { key: 'apelidos', dir: 'asc' }, page: 1, size: 10 });
		function render() {
			root.textContent = '';
			var bar = buildFilterBar('fillos', { onRefresh: render });
			root.appendChild(bar);
			addCsvExportBtn(bar, 'fillos', allRows, FILLOS_COLS);
			addCsvImportBtn(bar, 'fillos');
			var query = bar._searchInput.value || '';
			var filtered = filterRows(allRows, query, FILLOS_COLS);
			var sorted = tbl.sortRows(filtered, st.sort.key, st.sort.dir);
			if (!sorted.length) { root.appendChild(emptyEl('Sen fillos/as rexistrados.')); return; }
			var paged = tbl.pageSlice(sorted, st.page, st.size || 0);
			var table = buildTable(paged, FILLOS_COLS, st.sort, render, function (tr, row) {
				if (row.estado === 'baixa') { tr.classList.add('anpa-row-baixa'); }
			});

			// Add edit buttons per fillo row
			var tbodyRows = table.querySelectorAll('tbody tr');
			paged.forEach(function (row, i) {
				var actionsTd = tbodyRows[i] ? tbodyRows[i]._actionsCell || tbodyRows[i].lastElementChild : null;
				if (!actionsTd) { return; }
				var editBtn = document.createElement('button');
				editBtn.type = 'button';
				editBtn.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary';
				editBtn.textContent = 'Editar';
				editBtn.addEventListener('click', function () { renderFilloEdit(row, allRows); });
				actionsTd.appendChild(editBtn);
			});
			root.appendChild(table);

			if (st.size > 0 && sorted.length > st.size) {
				root.appendChild(buildPagination(sorted.length, st.page, st.size, function (p, s) {
					st.page = p; st.size = s; render();
				}));
			}

			var timer = null;
			bar._searchInput.addEventListener('input', function () {
				if (timer) { clearTimeout(timer); }
				timer = setTimeout(function () { st.page = 1; render(); }, 250);
			});
		}
		render();
	}

	/**
	 * Renders edit form for a fillo (PATCH /admin/fillo/<id>).
	 * @param {object} fillo - The fillo row.
	 * @param {Array} allRows - All fillos for returning to list.
	 */
	function renderFilloEdit(fillo, allRows) {
		root.textContent = '';
		var form = document.createElement('div');
		form.className = 'anpa-mgmt-form';
		form.setAttribute('role', 'form');
		form.setAttribute('aria-label', 'Editar fillo/a');
		var h3 = document.createElement('h3');
		h3.textContent = 'Editar fillo/a: ' + (fillo.nome || '') + ' ' + (fillo.apelidos || '');
		form.appendChild(h3);

		function addField(id, labelText, input) {
			var lbl = document.createElement('label');
			lbl.setAttribute('for', id);
			lbl.textContent = labelText;
			input.id = id;
			form.appendChild(lbl);
			form.appendChild(input);
		}

		var nomeInput = document.createElement('input'); nomeInput.type = 'text'; nomeInput.value = fillo.nome || '';
		addField('anpa-fillo-nome', 'Nome', nomeInput);
		var apelidosInput = document.createElement('input'); apelidosInput.type = 'text'; apelidosInput.value = fillo.apelidos || '';
		addField('anpa-fillo-apelidos', 'Apelidos', apelidosInput);
		var nacementoInput = document.createElement('input'); nacementoInput.type = 'date'; nacementoInput.value = fillo.data_nacemento || '';
		addField('anpa-fillo-nacemento', 'Data de nacemento', nacementoInput);
		var cursoInput = document.createElement('input'); cursoInput.type = 'text'; cursoInput.value = fillo.curso || '';
		addField('anpa-fillo-curso', 'Curso', cursoInput);
		var aulaInput = document.createElement('input'); aulaInput.type = 'text'; aulaInput.value = fillo.aula || '';
		addField('anpa-fillo-aula', 'Grupo', aulaInput);
		var estadoSelect = document.createElement('select');
		['activo', 'baixa'].forEach(function (v) {
			var opt = document.createElement('option'); opt.value = v; opt.textContent = v;
			if (fillo.estado === v) { opt.selected = true; }
			estadoSelect.appendChild(opt);
		});
		addField('anpa-fillo-estado', 'Estado', estadoSelect);

		var actions = document.createElement('div');
		actions.className = 'anpa-mgmt-form-actions';
		var saveBtn = document.createElement('button');
		saveBtn.type = 'button'; saveBtn.className = 'anpa-mgmt-btn';
		saveBtn.textContent = 'Gardar cambios';
		saveBtn.addEventListener('click', function () {
			clearMessage();
			var payload = {
				nome: (nomeInput.value || '').trim(),
				apelidos: (apelidosInput.value || '').trim(),
				data_nacemento: (nacementoInput.value || '').trim(),
				curso: (cursoInput.value || '').trim(),
				aula: (aulaInput.value || '').trim(),
				estado: estadoSelect.value,
			};
			if (!payload.nome || !payload.apelidos) {
				showMessage('Nome e apelidos son obrigatorios.', 'error'); return;
			}
			anpaAdminFetch('fillo/' + fillo.id, {
				method: 'PATCH', body: payload,
			}).then(function () {
				showMessage('Fillo/a actualizado.', 'success');
				loadFillos();
			}).catch(function (e) { showMessage(e.message, 'error'); });
		});
		actions.appendChild(saveBtn);

		var cancelBtn = document.createElement('button');
		cancelBtn.type = 'button'; cancelBtn.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary';
		cancelBtn.textContent = 'Volver';
		cancelBtn.addEventListener('click', function () { renderFillos(allRows); });
		actions.appendChild(cancelBtn);
		form.appendChild(actions);
		root.appendChild(form);
	}

	// ── Section: Empresas ────────────────────────────────────────────
	var EMPRESAS_COLS = ['nome', 'email', 'responsable', 'telefono', 'url_web', 'estado'];

	function loadEmpresas() {
		showLoading();
		anpaAdminFetch('empresas').then(function (rows) { renderEmpresas(rows); }).catch(sectionError);
	}

	function renderEmpresas(rows) {
		var allRows = Array.isArray(rows) ? rows : [];
		var st = sectionState.empresas || (sectionState.empresas = { sort: { key: 'nome', dir: 'asc' }, page: 1, size: 10 });
		function render() {
			root.textContent = '';
			var active = allRows.filter(function (r) { return r.estado !== 'inactivo'; });
			var visible = st.showInactive ? allRows : active;
			var bar = buildFilterBar('empresas', { hasInactive: true, activeCount: active.length, totalCount: allRows.length, onRefresh: render });
			root.appendChild(bar);
			addCsvExportBtn(bar, 'empresas', visible, EMPRESAS_COLS);
			addCsvImportBtn(bar, 'empresas');

			// "Nova empresa" button
			var novaBtn = document.createElement('button');
			novaBtn.type = 'button';
			novaBtn.className = 'anpa-mgmt-btn';
			novaBtn.textContent = 'Nova empresa';
			novaBtn.addEventListener('click', function () { renderEmpresaForm(null); });
			bar.appendChild(novaBtn);

			var query = bar._searchInput.value || '';
			var filtered = filterRows(visible, query, EMPRESAS_COLS);
			var sorted = tbl.sortRows(filtered, st.sort.key, st.sort.dir);
			if (!sorted.length) { root.appendChild(emptyEl('Sen empresas.')); return; }
			var paged = tbl.pageSlice(sorted, st.page, st.size || 0);
			var table = buildTable(paged, EMPRESAS_COLS, st.sort, render, function (tr, row) {
				if (row.estado === 'inactivo') { tr.classList.add('anpa-row-baixa'); }
			});

			// Add edit + toggle buttons per row
			var tbodyRows = table.querySelectorAll('tbody tr');
			paged.forEach(function (row, i) {
				var actionsTd = tbodyRows[i] ? tbodyRows[i]._actionsCell : null;
				if (!actionsTd) { return; }
				var editBtn = document.createElement('button');
				editBtn.type = 'button';
				editBtn.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary';
				editBtn.textContent = 'Editar';
				editBtn.addEventListener('click', function () { renderEmpresaForm(row); });
				actionsTd.appendChild(editBtn);
				// Toggle estado
				var toggleBtn = document.createElement('button');
				toggleBtn.type = 'button';
				toggleBtn.className = row.estado === 'activo' ? 'anpa-mgmt-btn anpa-mgmt-btn-danger' : 'anpa-mgmt-btn';
				toggleBtn.textContent = row.estado === 'activo' ? 'Desactivar' : 'Activar';
				toggleBtn.addEventListener('click', function () {
					var newEstado = row.estado === 'activo' ? 'inactivo' : 'activo';
					var payload = { nome: row.nome, email: row.email, responsable: row.responsable, telefono: row.telefono, url_web: row.url_web || '', estado: newEstado };
					anpaAdminFetch('empresa/' + row.id, { method: 'PUT', body: payload }).then(function () {
						showMessage('Estado da empresa actualizado.', 'success');
						loadEmpresas();
					}).catch(function (e) { showMessage(e.message, 'error'); });
				});
				actionsTd.appendChild(toggleBtn);
			});

			root.appendChild(table);

			if (st.size > 0 && sorted.length > st.size) {
				root.appendChild(buildPagination(sorted.length, st.page, st.size, function (p, s) {
					st.page = p; st.size = s; render();
				}));
			}

			var timer = null;
			bar._searchInput.addEventListener('input', function () {
				if (timer) { clearTimeout(timer); }
				timer = setTimeout(function () { st.page = 1; render(); }, 250);
			});
		}
		render();
	}

	/**
	 * Renders create/edit form for an empresa.
	 * @param {object|null} empresa - Existing empresa row for edit, or null for create.
	 */
	function renderEmpresaForm(empresa) {
		root.textContent = '';
		var isEdit = empresa !== null;
		var form = document.createElement('div');
		form.className = 'anpa-mgmt-form';
		form.setAttribute('role', 'form');
		form.setAttribute('aria-label', isEdit ? 'Editar empresa' : 'Nova empresa');
		var h3 = document.createElement('h3');
		h3.textContent = isEdit ? 'Editar empresa' : 'Nova empresa';
		form.appendChild(h3);

		function addField(id, labelText, input) {
			var lbl = document.createElement('label');
			lbl.setAttribute('for', id);
			lbl.textContent = labelText;
			input.id = id;
			form.appendChild(lbl);
			form.appendChild(input);
		}

		var nomeInput = document.createElement('input'); nomeInput.type = 'text'; nomeInput.value = isEdit ? (empresa.nome || '') : '';
		addField('anpa-empresa-nome', 'Nome da empresa', nomeInput);

		var emailInput = document.createElement('input'); emailInput.type = 'email'; emailInput.value = isEdit ? (empresa.email || '') : '';
		addField('anpa-empresa-email', 'Email', emailInput);

		var respInput = document.createElement('input'); respInput.type = 'text'; respInput.value = isEdit ? (empresa.responsable || '') : '';
		addField('anpa-empresa-responsable', 'Responsable', respInput);

		var telInput = document.createElement('input'); telInput.type = 'tel'; telInput.value = isEdit ? (empresa.telefono || '') : '';
		addField('anpa-empresa-telefono', 'Tel\u00E9fono', telInput);

		var urlInput = document.createElement('input'); urlInput.type = 'url'; urlInput.value = isEdit ? (empresa.url_web || '') : '';
		addField('anpa-empresa-url', 'URL web', urlInput);

		var estadoSelect = document.createElement('select');
		['activo', 'inactivo'].forEach(function (v) {
			var opt = document.createElement('option'); opt.value = v; opt.textContent = v;
			if (isEdit && empresa.estado === v) { opt.selected = true; }
			estadoSelect.appendChild(opt);
		});
		addField('anpa-empresa-estado', 'Estado', estadoSelect);

		var actions = document.createElement('div');
		actions.className = 'anpa-mgmt-form-actions';
		var saveBtn = document.createElement('button');
		saveBtn.type = 'button'; saveBtn.className = 'anpa-mgmt-btn';
		saveBtn.textContent = isEdit ? 'Gardar cambios' : 'Crear empresa';
		saveBtn.addEventListener('click', function () {
			clearMessage();
			var payload = {
				nome: (nomeInput.value || '').trim(),
				email: (emailInput.value || '').trim(),
				responsable: (respInput.value || '').trim(),
				telefono: (telInput.value || '').trim(),
				url_web: (urlInput.value || '').trim(),
				estado: estadoSelect.value,
			};
			if (!payload.nome || !payload.email || !payload.responsable || !payload.telefono) {
				showMessage('Nome, email, responsable e tel\u00E9fono son obrigatorios.', 'error'); return;
			}
			var method = isEdit ? 'PUT' : 'POST';
			var path = isEdit ? 'empresa/' + empresa.id : 'empresas';
			anpaAdminFetch(path, { method: method, body: payload }).then(function () {
				showMessage(isEdit ? 'Empresa actualizada.' : 'Empresa creada.', 'success');
				loadEmpresas();
			}).catch(function (e) { showMessage(e.message, 'error'); });
		});
		actions.appendChild(saveBtn);

		var cancelBtn = document.createElement('button');
		cancelBtn.type = 'button'; cancelBtn.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary';
		cancelBtn.textContent = 'Volver';
		cancelBtn.addEventListener('click', function () { loadEmpresas(); });
		actions.appendChild(cancelBtn);
		form.appendChild(actions);
		root.appendChild(form);
	}

	// ── Section: Actividades ─────────────────────────────────────────
	var ACTIV_COLS = ['nome', '_empresa_nome', 'curso_escolar', 'franxa', 'estado'];
	var _cachedEmpresas = null;

	function loadActividades() {
		showLoading();
		Promise.all([
			anpaAdminFetch('actividades'),
			anpaAdminFetch('empresas'),
		]).then(function (results) {
			_cachedEmpresas = Array.isArray(results[1]) ? results[1] : [];
			renderActividades(results[0], _cachedEmpresas);
		}).catch(sectionError);
	}

	function renderActividades(rows, empresas) {
		var allRows = Array.isArray(rows) ? rows : [];
		var empresaList = Array.isArray(empresas) ? empresas : [];
		var empresaNome = {};
		empresaList.forEach(function (e) { empresaNome[String(e.id)] = e.nome; });
		allRows.forEach(function (r) { r._empresa_nome = empresaNome[String(r.empresa_id)] || ''; });

		var st = sectionState.actividades || (sectionState.actividades = { sort: { key: 'nome', dir: 'asc' }, page: 1, size: 10 });
		function render() {
			root.textContent = '';
			var active = allRows.filter(function (r) { return r.estado !== 'inactivo'; });
			var visible = st.showInactive ? allRows : active;
			var bar = buildFilterBar('actividades', { hasInactive: true, activeCount: active.length, totalCount: allRows.length, onRefresh: render });
			root.appendChild(bar);
			addCsvExportBtn(bar, 'actividades', visible, ACTIV_COLS);
			addCsvImportBtn(bar, 'actividades');

			// "Nova actividade" button
			var novaBtn = document.createElement('button');
			novaBtn.type = 'button';
			novaBtn.className = 'anpa-mgmt-btn';
			novaBtn.textContent = 'Nova actividade';
			novaBtn.addEventListener('click', function () { renderActividadForm(null, empresaList); });
			bar.appendChild(novaBtn);

			var query = bar._searchInput.value || '';
			var filtered = filterRows(visible, query, ACTIV_COLS);
			var sorted = tbl.sortRows(filtered, st.sort.key, st.sort.dir);
			if (!sorted.length) { root.appendChild(emptyEl('Sen actividades.')); return; }
			var paged = tbl.pageSlice(sorted, st.page, st.size || 0);
			var table = buildTable(paged, ACTIV_COLS, st.sort, render, function (tr, row) {
				if (row.estado === 'inactivo') { tr.classList.add('anpa-row-baixa'); }
			});

			// Add action buttons per row
			var tbodyRows = table.querySelectorAll('tbody tr');
			paged.forEach(function (row, i) {
				var actionsTd = tbodyRows[i] ? tbodyRows[i]._actionsCell : null;
				if (!actionsTd) { return; }
				var editBtn = document.createElement('button');
				editBtn.type = 'button';
				editBtn.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary';
				editBtn.textContent = 'Editar';
				editBtn.addEventListener('click', function () { renderActividadForm(row, empresaList); });
				actionsTd.appendChild(editBtn);

				var gruposBtn = document.createElement('button');
				gruposBtn.type = 'button';
				gruposBtn.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary';
				gruposBtn.textContent = 'Grupos';
				gruposBtn.addEventListener('click', function () { renderGruposPanel(row); });
				actionsTd.appendChild(gruposBtn);

				var copyBtn = document.createElement('button');
				copyBtn.type = 'button';
				copyBtn.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary';
				copyBtn.textContent = 'Duplicar';
				copyBtn.addEventListener('click', function () {
					clearMessage();
					// Show a small dropdown to pick the target course.
					var cursoRange = generateCursoRange(row.curso_escolar || '');
					var sel = document.createElement('select');
					cursoRange.forEach(function (yr) {
						var opt = document.createElement('option');
						opt.value = yr;
						opt.textContent = yr;
						sel.appendChild(opt);
					});
					// Default to the current school year (first option that matches).
					var now = new Date();
					var startYear = now.getMonth() >= 6 ? now.getFullYear() : now.getFullYear() - 1;
					var curActual = startYear + '/' + (startYear + 1);
					sel.value = curActual;

					var dialog = document.createElement('div');
					dialog.className = 'anpa-mgmt-form';
					dialog.style.padding = '1em';
					var dlgLabel = document.createElement('label');
					dlgLabel.textContent = 'Curso destino:';
					dialog.appendChild(dlgLabel);
					dialog.appendChild(sel);
					var confirmBtn = document.createElement('button');
					confirmBtn.type = 'button';
					confirmBtn.className = 'anpa-mgmt-btn';
					confirmBtn.textContent = 'Duplicar';
					confirmBtn.addEventListener('click', function () {
						anpaAdminFetch('actividad/' + row.id + '/duplicate', { method: 'POST', body: { target_curso: sel.value } }).then(function () {
							showMessage('Actividade duplicada ao curso ' + sel.value + '.', 'success');
							loadActividades();
						}).catch(function (e) { showMessage(e.message, 'error'); });
					});
					dialog.appendChild(confirmBtn);
					var cancelDlg = document.createElement('button');
					cancelDlg.type = 'button';
					cancelDlg.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary';
					cancelDlg.textContent = 'Cancelar';
					cancelDlg.addEventListener('click', function () { loadActividades(); });
					dialog.appendChild(cancelDlg);
					root.textContent = '';
					root.appendChild(dialog);
				});
				actionsTd.appendChild(copyBtn);

				var toggleBtn = document.createElement('button');
				toggleBtn.type = 'button';
				toggleBtn.className = row.estado === 'activo' ? 'anpa-mgmt-btn anpa-mgmt-btn-danger' : 'anpa-mgmt-btn';
				toggleBtn.textContent = row.estado === 'activo' ? 'Desactivar' : 'Activar';
				toggleBtn.addEventListener('click', function () {
					var newEstado = row.estado === 'activo' ? 'inactivo' : 'activo';
					var payload = {
						empresa_id: row.empresa_id, nome: row.nome, icono: row.icono || '',
						descripcion: row.descripcion || '', curso_escolar: row.curso_escolar,
						franxa: row.franxa || '', horarios: row.horarios || '',
						grupos: row.grupos || '', dias: row.dias || '',
						curso_min: row.curso_min, curso_max: row.curso_max,
						min_pupilos: row.min_pupilos, max_pupilos: row.max_pupilos,
						custo: row.custo, estado: newEstado,
					};
					anpaAdminFetch('actividad/' + row.id, { method: 'PUT', body: payload }).then(function () {
						showMessage('Estado da actividade actualizado.', 'success');
						loadActividades();
					}).catch(function (e) { showMessage(e.message, 'error'); });
				});
				actionsTd.appendChild(toggleBtn);
			});

			root.appendChild(table);

			if (st.size > 0 && sorted.length > st.size) {
				root.appendChild(buildPagination(sorted.length, st.page, st.size, function (p, s) {
					st.page = p; st.size = s; render();
				}));
			}

			var timer = null;
			bar._searchInput.addEventListener('input', function () {
				if (timer) { clearTimeout(timer); }
				timer = setTimeout(function () { st.page = 1; render(); }, 250);
			});
		}
		render();
	}

	/**
	 * Generates a range of school years around the given one (or current).
	 * Returns e.g. ['2024/2025', '2025/2026', '2026/2027'].
	 */
	function generateCursoRange(curso) {
		var match = /^(\d{4})\/(\d{4})$/.exec(curso);
		var start = match ? parseInt(match[1], 10) : new Date().getFullYear() - (new Date().getMonth() < 6 ? 1 : 0);
		var out = [];
		for (var y = start - 1; y <= start + 2; y++) {
			out.push(y + '/' + (y + 1));
		}
		return out;
	}

	/**
	 * Renders create/edit form for an actividade.
	 * @param {object|null} act - Existing actividad row for edit, or null for create.
	 * @param {Array} empresaList - List of empresas for the dropdown.
	 */
	function renderActividadForm(act, empresaList) {
		root.textContent = '';
		var isEdit = act !== null;
		var form = document.createElement('div');
		form.className = 'anpa-mgmt-form';
		form.setAttribute('role', 'form');
		form.setAttribute('aria-label', isEdit ? 'Editar actividade' : 'Nova actividade');
		var h3 = document.createElement('h3');
		h3.textContent = isEdit ? 'Editar actividade' : 'Nova actividade';
		form.appendChild(h3);

		function addField(id, labelText, input) {
			var lbl = document.createElement('label');
			lbl.setAttribute('for', id);
			lbl.textContent = labelText;
			input.id = id;
			form.appendChild(lbl);
			form.appendChild(input);
		}

		// Empresa selector (shows nome, stores empresa_id)
		var empresaSelect = document.createElement('select');
		var emptyOpt = document.createElement('option');
		emptyOpt.value = ''; emptyOpt.textContent = '-- Seleccionar empresa --';
		empresaSelect.appendChild(emptyOpt);
		empresaList.forEach(function (e) {
			if (e.estado === 'inactivo') { return; }
			var opt = document.createElement('option');
			opt.value = String(e.id);
			opt.textContent = e.nome;
			if (isEdit && String(act.empresa_id) === String(e.id)) { opt.selected = true; }
			empresaSelect.appendChild(opt);
		});
		addField('anpa-act-empresa', 'Empresa', empresaSelect);

		var nomeInput = document.createElement('input'); nomeInput.type = 'text';
		nomeInput.value = isEdit ? (act.nome || '') : '';
		addField('anpa-act-nome', 'Nome', nomeInput);

		var descInput = document.createElement('textarea');
		descInput.rows = 3; descInput.value = isEdit ? (act.descripcion || '') : '';
		addField('anpa-act-descripcion', 'Descrici\u00F3n', descInput);

		var cursoInput = document.createElement('input'); cursoInput.type = 'text';
		cursoInput.placeholder = '2025/2026';
		cursoInput.value = isEdit ? (act.curso_escolar || '') : '';
		addField('anpa-act-curso', 'Curso escolar (primario)', cursoInput);

		// Multi-course selector: checkboxes for available school years.
		var cursosContainer = document.createElement('div');
		cursosContainer.className = 'anpa-mgmt-multicurso';
		var cursosLabel = document.createElement('label');
		cursosLabel.textContent = 'Cursos nos que se oferta';
		form.appendChild(cursosLabel);
		var currentYear = cursoInput.value || '';
		var yearList = generateCursoRange(currentYear);
		var selectedCursos = isEdit && Array.isArray(act.cursos) ? act.cursos : (isEdit && act.curso_escolar ? [act.curso_escolar] : []);
		yearList.forEach(function (yr) {
			var chkLabel = document.createElement('label');
			chkLabel.style.display = 'inline-block';
			chkLabel.style.marginRight = '1em';
			var chk = document.createElement('input');
			chk.type = 'checkbox';
			chk.value = yr;
			chk.checked = selectedCursos.indexOf(yr) !== -1;
			chkLabel.appendChild(chk);
			chkLabel.appendChild(document.createTextNode(' ' + yr));
			cursosContainer.appendChild(chkLabel);
		});
		form.appendChild(cursosContainer);

		var franxaInput = document.createElement('input'); franxaInput.type = 'text';
		franxaInput.placeholder = 'ma\u00F1\u00E1s / tardes';
		franxaInput.value = isEdit ? (act.franxa || '') : '';
		addField('anpa-act-franxa', 'Franxa', franxaInput);

		var horariosInput = document.createElement('input'); horariosInput.type = 'text';
		horariosInput.placeholder = '09:00-10:00,10:00-11:00';
		horariosInput.value = isEdit ? (act.horarios || '') : '';
		addField('anpa-act-horarios', 'Horarios (separados por coma)', horariosInput);

		var gruposInput = document.createElement('input'); gruposInput.type = 'text';
		gruposInput.placeholder = '1-2,3-4,5-6';
		gruposInput.value = isEdit ? (act.grupos || '') : '';
		addField('anpa-act-grupos', 'Grupos curriculares (separados por coma)', gruposInput);

		var diasInput = document.createElement('input'); diasInput.type = 'text';
		diasInput.placeholder = 'luns,martes,m\u00E9rcores';
		diasInput.value = isEdit ? (act.dias || '') : '';
		addField('anpa-act-dias', 'D\u00EDas (separados por coma)', diasInput);

		var minPupInput = document.createElement('input'); minPupInput.type = 'number'; minPupInput.min = '1';
		minPupInput.value = isEdit ? (act.min_pupilos || 10) : '10';
		addField('anpa-act-minpup', 'M\u00EDnimo de pupilos/as', minPupInput);

		var maxPupInput = document.createElement('input'); maxPupInput.type = 'number'; maxPupInput.min = '1';
		maxPupInput.value = isEdit ? (act.max_pupilos || 15) : '15';
		addField('anpa-act-maxpup', 'M\u00E1ximo de pupilos/as', maxPupInput);

		var idadeMinInput = document.createElement('input'); idadeMinInput.type = 'number'; idadeMinInput.min = '0';
		idadeMinInput.value = isEdit && act.curso_min != null ? act.curso_min : '';
		addField('anpa-act-idademin', 'Curso m\u00EDnimo (opcional)', idadeMinInput);

		var idadeMaxInput = document.createElement('input'); idadeMaxInput.type = 'number'; idadeMaxInput.min = '0';
		idadeMaxInput.value = isEdit && act.curso_max != null ? act.curso_max : '';
		addField('anpa-act-idademax', 'Curso m\u00E1ximo (opcional)', idadeMaxInput);

		var custoInput = document.createElement('input'); custoInput.type = 'text';
		custoInput.placeholder = '0.00';
		custoInput.value = isEdit ? (act.custo || '0') : '';
		addField('anpa-act-custo', 'Custo (\u20AC)', custoInput);

		var estadoSelect = document.createElement('select');
		['activo', 'inactivo'].forEach(function (v) {
			var opt = document.createElement('option'); opt.value = v; opt.textContent = v;
			if (isEdit && act.estado === v) { opt.selected = true; }
			estadoSelect.appendChild(opt);
		});
		addField('anpa-act-estado', 'Estado', estadoSelect);

		var actions = document.createElement('div');
		actions.className = 'anpa-mgmt-form-actions';
		var saveBtn = document.createElement('button');
		saveBtn.type = 'button'; saveBtn.className = 'anpa-mgmt-btn';
		saveBtn.textContent = isEdit ? 'Gardar cambios' : 'Crear actividade';
		saveBtn.addEventListener('click', function () {
			clearMessage();
			// Gather selected courses from checkboxes.
			var selectedCursosArr = [];
			var chks = cursosContainer.querySelectorAll('input[type="checkbox"]');
			for (var ci = 0; ci < chks.length; ci++) {
				if (chks[ci].checked) { selectedCursosArr.push(chks[ci].value); }
			}
			var payload = {
				empresa_id: parseInt(empresaSelect.value, 10) || 0,
				nome: (nomeInput.value || '').trim(),
				icono: isEdit ? (act.icono || '') : '',
				descripcion: (descInput.value || '').trim(),
				curso_escolar: (cursoInput.value || '').trim(),
				franxa: (franxaInput.value || '').trim(),
				horarios: (horariosInput.value || '').trim(),
				grupos: (gruposInput.value || '').trim(),
				dias: (diasInput.value || '').trim(),
				curso_min: idadeMinInput.value !== '' ? parseInt(idadeMinInput.value, 10) : null,
				curso_max: idadeMaxInput.value !== '' ? parseInt(idadeMaxInput.value, 10) : null,
				min_pupilos: parseInt(minPupInput.value, 10) || 10,
				max_pupilos: parseInt(maxPupInput.value, 10) || 15,
				custo: (custoInput.value || '').trim(),
				estado: estadoSelect.value,
				cursos: selectedCursosArr,
			};
			if (!payload.empresa_id || !payload.nome || !payload.descripcion || !payload.curso_escolar) {
				showMessage('Empresa, nome, descrici\u00F3n e curso escolar son obrigatorios.', 'error'); return;
			}
			var method = isEdit ? 'PUT' : 'POST';
			var path = isEdit ? 'actividad/' + act.id : 'actividades';
			anpaAdminFetch(path, { method: method, body: payload }).then(function () {
				showMessage(isEdit ? 'Actividade actualizada.' : 'Actividade creada.', 'success');
				loadActividades();
			}).catch(function (e) { showMessage(e.message, 'error'); });
		});
		actions.appendChild(saveBtn);

		var cancelBtn = document.createElement('button');
		cancelBtn.type = 'button'; cancelBtn.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary';
		cancelBtn.textContent = 'Volver';
		cancelBtn.addEventListener('click', function () { loadActividades(); });
		actions.appendChild(cancelBtn);
		form.appendChild(actions);
		root.appendChild(form);
	}

	// ── Grupos sub-panel (opened from an actividade row) ─────────────

	/**
	 * Renders a management panel for the grupos of a given actividade.
	 * Displays list, create form, edit, set estado, and mover matrícula.
	 * @param {object} actividad - The parent actividad row.
	 */
	function renderGruposPanel(actividad) {
		root.textContent = '';
		var panel = document.createElement('div');
		panel.className = 'anpa-mgmt-form';
		panel.style.maxWidth = '900px';
		var h3 = document.createElement('h3');
		h3.textContent = 'Grupos de: ' + (actividad.nome || '');
		panel.appendChild(h3);

		var listContainer = document.createElement('div');
		panel.appendChild(listContainer);

		var actions = document.createElement('div');
		actions.className = 'anpa-mgmt-form-actions';
		actions.style.marginTop = '0.5rem';
		var newGrupoBtn = document.createElement('button');
		newGrupoBtn.type = 'button'; newGrupoBtn.className = 'anpa-mgmt-btn';
		newGrupoBtn.textContent = 'Novo grupo';
		newGrupoBtn.addEventListener('click', function () { renderGrupoForm(null, actividad); });
		actions.appendChild(newGrupoBtn);

		var backBtn = document.createElement('button');
		backBtn.type = 'button'; backBtn.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary';
		backBtn.textContent = 'Volver a actividades';
		backBtn.addEventListener('click', function () { loadActividades(); });
		actions.appendChild(backBtn);
		panel.appendChild(actions);
		root.appendChild(panel);

		// Load and render grupos list
		anpaAdminFetch('actividad/' + actividad.id + '/grupos').then(function (rows) {
			listContainer.textContent = '';
			var grupos = Array.isArray(rows) ? rows : [];
			if (!grupos.length) {
				listContainer.appendChild(emptyEl('Sen grupos para esta actividade.'));
				return;
			}
			var table = document.createElement('table');
			table.className = 'anpa-mgmt-table';
			var thead = document.createElement('thead');
			var hr = document.createElement('tr');
			['Curso', 'Franxa', 'D\u00EDas', 'Min', 'Max', 'Estado', ''].forEach(function (t) {
				var th = document.createElement('th'); th.textContent = t; hr.appendChild(th);
			});
			thead.appendChild(hr); table.appendChild(thead);
			var tbody = document.createElement('tbody');
			grupos.forEach(function (g) {
				var tr = document.createElement('tr');
				[g.curso_range, g.franxa, g.dias, g.min_pupilos, g.max_pupilos, g.estado].forEach(function (v) {
					var td = document.createElement('td'); td.textContent = v != null ? String(v) : ''; tr.appendChild(td);
				});
				var actionsTd = document.createElement('td');
				actionsTd.className = 'anpa-mgmt-actions';

				// Edit button
				var editBtn = document.createElement('button');
				editBtn.type = 'button'; editBtn.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary';
				editBtn.textContent = 'Editar';
				editBtn.addEventListener('click', function () { renderGrupoForm(g, actividad); });
				actionsTd.appendChild(editBtn);

				// Toggle estado (aberto/pechado)
				var toggleBtn = document.createElement('button');
				toggleBtn.type = 'button';
				toggleBtn.className = g.estado === 'aberto' ? 'anpa-mgmt-btn anpa-mgmt-btn-danger' : 'anpa-mgmt-btn';
				toggleBtn.textContent = g.estado === 'aberto' ? 'Pechar' : 'Abrir';
				toggleBtn.addEventListener('click', function () {
					var newEstado = g.estado === 'aberto' ? 'pechado' : 'aberto';
					anpaAdminFetch('grupo/' + g.id + '/estado', { method: 'POST', body: { estado: newEstado } }).then(function () {
						showMessage('Estado do grupo actualizado.', 'success');
						renderGruposPanel(actividad);
					}).catch(function (e) { showMessage(e.message, 'error'); });
				});
				actionsTd.appendChild(toggleBtn);

				// View matrículas button
				var matBtn = document.createElement('button');
				matBtn.type = 'button'; matBtn.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary';
				matBtn.textContent = 'Matr\u00EDculas';
				matBtn.addEventListener('click', function () { renderGrupoMatriculas(g, actividad); });
				actionsTd.appendChild(matBtn);

				tr.appendChild(actionsTd);
				tbody.appendChild(tr);
			});
			table.appendChild(tbody);
			listContainer.appendChild(table);
		}).catch(function (e) {
			listContainer.textContent = '';
			listContainer.appendChild(emptyEl('Erro ao cargar grupos: ' + e.message));
		});
	}

	/**
	 * Renders create/edit form for a grupo.
	 * @param {object|null} grupo - Existing grupo row or null for create.
	 * @param {object} actividad - Parent actividad row.
	 */
	function renderGrupoForm(grupo, actividad) {
		root.textContent = '';
		var isEdit = grupo !== null;
		var form = document.createElement('div');
		form.className = 'anpa-mgmt-form';
		form.setAttribute('role', 'form');
		form.setAttribute('aria-label', isEdit ? 'Editar grupo' : 'Novo grupo');
		var h3 = document.createElement('h3');
		h3.textContent = (isEdit ? 'Editar grupo' : 'Novo grupo') + ' \u2014 ' + (actividad.nome || '');
		form.appendChild(h3);

		function addField(id, labelText, input) {
			var lbl = document.createElement('label');
			lbl.setAttribute('for', id);
			lbl.textContent = labelText;
			input.id = id;
			form.appendChild(lbl);
			form.appendChild(input);
		}

		var cursoEscInput = document.createElement('input'); cursoEscInput.type = 'text';
		cursoEscInput.placeholder = '2025/2026';
		cursoEscInput.value = isEdit ? (grupo.curso_escolar || '') : (actividad.curso_escolar || '');
		addField('anpa-grupo-curso-esc', 'Curso escolar', cursoEscInput);

		var cursoRangeInput = document.createElement('input'); cursoRangeInput.type = 'text';
		cursoRangeInput.placeholder = '1-2';
		cursoRangeInput.value = isEdit ? (grupo.curso_range || '') : '';
		addField('anpa-grupo-curso-range', 'Grupo curricular (ex: 1-2, 3-4)', cursoRangeInput);

		var franxaInput = document.createElement('input'); franxaInput.type = 'text';
		franxaInput.placeholder = 'ma\u00F1\u00E1s / tardes';
		franxaInput.value = isEdit ? (grupo.franxa || '') : (actividad.franxa || '');
		addField('anpa-grupo-franxa', 'Franxa', franxaInput);

		var diasInput = document.createElement('input'); diasInput.type = 'text';
		diasInput.placeholder = 'luns,martes';
		diasInput.value = isEdit ? (grupo.dias || '') : '';
		addField('anpa-grupo-dias', 'D\u00EDas (separados por coma)', diasInput);

		var minInput = document.createElement('input'); minInput.type = 'number'; minInput.min = '0';
		minInput.value = isEdit ? (grupo.min_pupilos || 0) : '10';
		addField('anpa-grupo-min', 'M\u00EDnimo pupilos/as', minInput);

		var maxInput = document.createElement('input'); maxInput.type = 'number'; maxInput.min = '1';
		maxInput.value = isEdit ? (grupo.max_pupilos || 15) : '15';
		addField('anpa-grupo-max', 'M\u00E1ximo pupilos/as', maxInput);

		var estadoSelect = document.createElement('select');
		['aberto', 'pechado'].forEach(function (v) {
			var opt = document.createElement('option'); opt.value = v; opt.textContent = v;
			if (isEdit && grupo.estado === v) { opt.selected = true; }
			estadoSelect.appendChild(opt);
		});
		addField('anpa-grupo-estado', 'Estado', estadoSelect);

		var formActions = document.createElement('div');
		formActions.className = 'anpa-mgmt-form-actions';
		var saveBtn = document.createElement('button');
		saveBtn.type = 'button'; saveBtn.className = 'anpa-mgmt-btn';
		saveBtn.textContent = isEdit ? 'Gardar cambios' : 'Crear grupo';
		saveBtn.addEventListener('click', function () {
			clearMessage();
			var payload = {
				curso_escolar: (cursoEscInput.value || '').trim(),
				curso_range: (cursoRangeInput.value || '').trim(),
				franxa: (franxaInput.value || '').trim(),
				dias: (diasInput.value || '').trim(),
				min_pupilos: parseInt(minInput.value, 10) || 0,
				max_pupilos: parseInt(maxInput.value, 10) || 15,
				estado: estadoSelect.value,
			};
			if (!payload.curso_escolar || !payload.curso_range || !payload.dias) {
				showMessage('Curso escolar, grupo curricular e d\u00EDas son obrigatorios.', 'error'); return;
			}
			var method = isEdit ? 'PUT' : 'POST';
			var path = isEdit ? 'grupo/' + grupo.id : 'actividad/' + actividad.id + '/grupos';
			anpaAdminFetch(path, { method: method, body: payload }).then(function () {
				showMessage(isEdit ? 'Grupo actualizado.' : 'Grupo creado.', 'success');
				renderGruposPanel(actividad);
			}).catch(function (e) { showMessage(e.message, 'error'); });
		});
		formActions.appendChild(saveBtn);

		var cancelBtn = document.createElement('button');
		cancelBtn.type = 'button'; cancelBtn.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary';
		cancelBtn.textContent = 'Volver a grupos';
		cancelBtn.addEventListener('click', function () { renderGruposPanel(actividad); });
		formActions.appendChild(cancelBtn);
		form.appendChild(formActions);
		root.appendChild(form);
	}

	/**
	 * Shows the matrículas of a grupo and allows mover.
	 * @param {object} grupo - The grupo row.
	 * @param {object} actividad - Parent actividad row.
	 */
	function renderGrupoMatriculas(grupo, actividad) {
		root.textContent = '';
		var panel = document.createElement('div');
		panel.className = 'anpa-mgmt-form';
		panel.style.maxWidth = '900px';
		var h3 = document.createElement('h3');
		h3.textContent = 'Matr\u00EDculas do grupo ' + (grupo.curso_range || '') + ' \u2014 ' + (actividad.nome || '');
		panel.appendChild(h3);

		var listEl = document.createElement('div');
		panel.appendChild(listEl);

		var backBtn = document.createElement('button');
		backBtn.type = 'button'; backBtn.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary';
		backBtn.textContent = 'Volver a grupos';
		backBtn.style.marginTop = '1rem';
		backBtn.addEventListener('click', function () { renderGruposPanel(actividad); });
		panel.appendChild(backBtn);
		root.appendChild(panel);

		// Load matrículas and all grupos for this actividad
		Promise.all([
			anpaAdminFetch('grupo/' + grupo.id + '/matriculas'),
			anpaAdminFetch('actividad/' + actividad.id + '/grupos'),
		]).then(function (results) {
			var matriculas = Array.isArray(results[0]) ? results[0] : [];
			var allGrupos = Array.isArray(results[1]) ? results[1] : [];
			listEl.textContent = '';
			if (!matriculas.length) {
				listEl.appendChild(emptyEl('Sen matr\u00EDculas neste grupo.'));
				return;
			}
			var table = document.createElement('table');
			table.className = 'anpa-mgmt-table';
			var thead = document.createElement('thead');
			var hr = document.createElement('tr');
			['Alumno/a', 'Curso', 'Estado', ''].forEach(function (t) {
				var th = document.createElement('th'); th.textContent = t; hr.appendChild(th);
			});
			thead.appendChild(hr); table.appendChild(thead);
			var tbody = document.createElement('tbody');
			matriculas.forEach(function (m) {
				var tr = document.createElement('tr');
				var nombre = (m.fillo_nome || '') + ' ' + (m.fillo_apelidos || '');
				[nombre.trim(), m.curso_completo || '', m.estado || ''].forEach(function (v) {
					var td = document.createElement('td'); td.textContent = v; tr.appendChild(td);
				});
				var actionsTd = document.createElement('td');
				actionsTd.className = 'anpa-mgmt-actions';

				// Move button (show select with other grupos)
				var otherGrupos = allGrupos.filter(function (g) { return g.id !== grupo.id; });
				if (otherGrupos.length > 0) {
					var moverBtn = document.createElement('button');
					moverBtn.type = 'button'; moverBtn.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary';
					moverBtn.textContent = 'Mover';
					moverBtn.addEventListener('click', function () {
						// Replace button with a group selector
						actionsTd.textContent = '';
						var sel = document.createElement('select');
						sel.setAttribute('aria-label', 'Grupo destino');
						var defOpt = document.createElement('option');
						defOpt.value = ''; defOpt.textContent = '-- Grupo destino --';
						sel.appendChild(defOpt);
						otherGrupos.forEach(function (g) {
							var opt = document.createElement('option');
							opt.value = String(g.id);
							opt.textContent = g.curso_range + ' (' + g.dias + ')';
							sel.appendChild(opt);
						});
						actionsTd.appendChild(sel);
						var confirmBtn = document.createElement('button');
						confirmBtn.type = 'button'; confirmBtn.className = 'anpa-mgmt-btn';
						confirmBtn.textContent = 'Confirmar';
						confirmBtn.addEventListener('click', function () {
							var targetId = parseInt(sel.value, 10);
							if (!targetId) { showMessage('Selecciona un grupo destino.', 'error'); return; }
							anpaAdminFetch('matricula/' + m.id + '/mover', { method: 'POST', body: { grupo_id: targetId } }).then(function () {
								showMessage('Matr\u00EDcula movida.', 'success');
								renderGrupoMatriculas(grupo, actividad);
							}).catch(function (e) { showMessage(e.message, 'error'); });
						});
						actionsTd.appendChild(confirmBtn);
					});
					actionsTd.appendChild(moverBtn);
				}
				tr.appendChild(actionsTd);
				tbody.appendChild(tr);
			});
			table.appendChild(tbody);
			listEl.appendChild(table);
		}).catch(function (e) {
			listEl.textContent = '';
			listEl.appendChild(emptyEl('Erro ao cargar matr\u00EDculas: ' + e.message));
		});
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
		var matHost = document.createElement('div');
		root.appendChild(matHost);

		var MAT_COLS = ['fillo_apelidos', 'fillo_nome', 'actividade', 'curso_completo', 'estado', 'franxa', 'dias', 'trimestres', 'creado_en', 'posicion'];

		function loadMat(curso) {
			matHost.textContent = '';
			matHost.innerHTML = '<p class="anpa-mgmt-loading">Cargando\u2026</p>';
			anpaAdminFetch('matriculas?curso=' + encodeURIComponent(curso)).then(function (rows) {
				matHost.textContent = '';
				var matRows = Array.isArray(rows) ? rows : [];
				if (!matRows.length) { matHost.appendChild(emptyEl('Sen matr\u00EDculas.')); return; }
				var matSt = sectionState.matriculas || (sectionState.matriculas = { sort: { key: 'fillo_apelidos', dir: 'asc' }, page: 1, size: 10 });
				function renderMat() {
					matHost.textContent = '';
					var bar = buildFilterBar('matriculas', { onRefresh: renderMat });
					matHost.appendChild(bar);
					addCsvExportBtn(bar, 'matriculas', matRows, MAT_COLS);
					addCsvImportBtn(bar, 'matriculas');
					var query = bar._searchInput.value || '';
					var filtered = filterRows(matRows, query, MAT_COLS);
					var sorted = tbl.sortRows(filtered, matSt.sort.key, matSt.sort.dir);
					if (!sorted.length) { matHost.appendChild(emptyEl('Sen matr\u00EDculas.')); return; }
					var paged = tbl.pageSlice(sorted, matSt.page, matSt.size || 0);
					var matTable = buildTable(paged, MAT_COLS, matSt.sort, renderMat, null);
					matHost.appendChild(matTable);
					if (matSt.size > 0 && sorted.length > matSt.size) {
						matHost.appendChild(buildPagination(sorted.length, matSt.page, matSt.size, function (p, s) {
							matSt.page = p; matSt.size = s; renderMat();
						}));
					}
					var timer = null;
					bar._searchInput.addEventListener('input', function () {
						if (timer) { clearTimeout(timer); }
						timer = setTimeout(function () { matSt.page = 1; renderMat(); }, 250);
					});
				}
				renderMat();
			}).catch(function (e) { matHost.textContent = ''; showMessage(e.message, 'error'); });
		}
		cursoSel.addEventListener('change', function () { loadMat(cursoSel.value || current); });
		loadMat(current);
	}

	// ── Section: Auditoría ───────────────────────────────────────────
	var AUDIT_COLS = ['timestamp', 'actor_email', 'accion', 'target_tipo', 'target_id'];

	function loadAudit() {
		showLoading();
		anpaAdminFetch('audit?limit=100').then(function (rows) { renderAudit(rows); }).catch(sectionError);
	}

	function renderAudit(rows) {
		var allRows = Array.isArray(rows) ? rows : [];
		var st = sectionState.audit || (sectionState.audit = { sort: { key: 'timestamp', dir: 'desc' }, page: 1, size: 50 });
		function render() {
			root.textContent = '';
			var bar = buildFilterBar('audit', { onRefresh: render });
			root.appendChild(bar);
			var query = bar._searchInput.value || '';
			var filtered = filterRows(allRows, query, AUDIT_COLS);
			var sorted = tbl.sortRows(filtered, st.sort.key, st.sort.dir);
			if (!sorted.length) { root.appendChild(emptyEl('Sen entradas de auditor\u00EDa.')); return; }
			var paged = tbl.pageSlice(sorted, st.page, st.size || 0);
			var table = buildTable(paged, AUDIT_COLS, st.sort, render, null);
			root.appendChild(table);

			if (st.size > 0 && sorted.length > st.size) {
				root.appendChild(buildPagination(sorted.length, st.page, st.size, function (p, s) {
					st.page = p; st.size = s; render();
				}));
			}

			var timer = null;
			bar._searchInput.addEventListener('input', function () {
				if (timer) { clearTimeout(timer); }
				timer = setTimeout(function () { st.page = 1; render(); }, 250);
			});
		}
		render();
	}

	// ── Section: Importar listados ──────────────────────────────────
	function loadImportar() {
		root.textContent = '';
		var h3 = document.createElement('h3');
		h3.textContent = 'Importar listados';
		root.appendChild(h3);
		var desc = document.createElement('p');
		desc.textContent = 'Selecciona a entidade a importar e sube un ficheiro CSV. A orde recomendada \u00E9: Empresas \u2192 Actividades \u2192 Socios \u2192 Fillos \u2192 Matr\u00EDculas.';
		root.appendChild(desc);

		var entities = ['empresas', 'actividades', 'socios', 'fillos', 'matriculas'];
		var entityLabels = { empresas: 'Empresas', actividades: 'Actividades', socios: 'Socios', fillos: 'Fillos/as', matriculas: 'Matr\u00EDculas' };

		entities.forEach(function (entity) {
			var section = document.createElement('div');
			section.className = 'anpa-import-section';
			section.style.marginBottom = '1.5rem';
			section.style.padding = '1rem';
			section.style.border = '1px solid #dcdcde';
			section.style.borderRadius = '4px';

			var title = document.createElement('h4');
			title.textContent = entityLabels[entity] || entity;
			title.style.marginTop = '0';
			section.appendChild(title);

			var fileInput = document.createElement('input');
			fileInput.type = 'file';
			fileInput.accept = '.csv,text/csv';
			fileInput.setAttribute('aria-label', 'Ficheiro CSV para ' + (entityLabels[entity] || entity));
			section.appendChild(fileInput);

			var dryRunBtn = document.createElement('button');
			dryRunBtn.type = 'button';
			dryRunBtn.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary';
			dryRunBtn.textContent = 'Verificar (dry-run)';
			dryRunBtn.style.marginLeft = '0.5rem';
			section.appendChild(dryRunBtn);

			var reportArea = document.createElement('div');
			reportArea.className = 'anpa-import-report';
			reportArea.style.marginTop = '0.75rem';
			section.appendChild(reportArea);

			var commitBtn = document.createElement('button');
			commitBtn.type = 'button';
			commitBtn.className = 'anpa-mgmt-btn';
			commitBtn.textContent = 'Confirmar importaci\u00F3n';
			commitBtn.style.display = 'none';
			commitBtn.style.marginTop = '0.5rem';
			section.appendChild(commitBtn);

			dryRunBtn.addEventListener('click', function () {
				var file = fileInput.files && fileInput.files[0];
				if (!file) { showMessage('Selecciona un ficheiro CSV.', 'error'); return; }
				reportArea.textContent = 'Verificando\u2026';
				commitBtn.style.display = 'none';
				readCsvFile(file, function (csvText) {
					anpaAdminFetch('import/' + entity, {
						method: 'POST',
						body: { csv: csvText, commit: false },
					}).then(function (data) {
						renderImportReport(reportArea, data, entity);
						if (data.to_insert_count > 0) {
							commitBtn.style.display = '';
							commitBtn.onclick = function () {
								if (!window.confirm('Confirmar a importaci\u00F3n de ' + data.to_insert_count + ' rexistros?')) { return; }
								commitBtn.disabled = true;
								commitBtn.textContent = 'Importando\u2026';
								anpaAdminFetch('import/' + entity, {
									method: 'POST',
									body: { csv: csvText, commit: true },
								}).then(function (result) {
									commitBtn.style.display = 'none';
									renderImportResult(reportArea, result);
									showMessage('Importaci\u00F3n completada.', 'success');
								}).catch(function (e) {
									commitBtn.disabled = false;
									commitBtn.textContent = 'Confirmar importaci\u00F3n';
									showMessage(e.message, 'error');
								});
							};
						}
					}).catch(function (e) {
						reportArea.textContent = '';
						showMessage(e.message, 'error');
					});
				});
			});

			root.appendChild(section);
		});
	}

	function readCsvFile(file, callback) {
		var reader = new FileReader();
		reader.onload = function (e) { callback(e.target.result); };
		reader.readAsText(file, 'UTF-8');
	}

	function renderImportReport(container, data, entity) {
		container.textContent = '';
		var summary = document.createElement('p');
		summary.innerHTML = '<strong>Total filas:</strong> ' + data.total +
			' | <strong>A inserir:</strong> ' + data.to_insert_count +
			' | <strong>Duplicados (omitidos):</strong> ' + data.duplicates_count;
		container.appendChild(summary);

		if (data.errors && data.errors.length > 0) {
			var errTitle = document.createElement('p');
			errTitle.style.color = '#d63638';
			errTitle.style.fontWeight = 'bold';
			errTitle.textContent = 'Erros (' + data.errors.length + '):';
			container.appendChild(errTitle);
			var errList = document.createElement('ul');
			errList.style.color = '#d63638';
			errList.style.fontSize = '13px';
			data.errors.slice(0, 20).forEach(function (err) {
				var li = document.createElement('li');
				li.textContent = 'Fila ' + (err.row + 2) + ': ' + (err.field ? err.field + ' \u2014 ' : '') + err.msg;
				errList.appendChild(li);
			});
			if (data.errors.length > 20) {
				var more = document.createElement('li');
				more.textContent = '\u2026 e ' + (data.errors.length - 20) + ' m\u00E1is.';
				errList.appendChild(more);
			}
			container.appendChild(errList);
		}

		if (data.preview && data.preview.length > 0) {
			var preTitle = document.createElement('p');
			preTitle.style.fontWeight = 'bold';
			preTitle.textContent = 'Vista previa (primeiras ' + data.preview.length + ' filas a inserir):';
			container.appendChild(preTitle);
			var cols = Object.keys(data.preview[0]);
			var preTable = document.createElement('table');
			preTable.className = 'anpa-mgmt-table';
			preTable.style.fontSize = '12px';
			var thead = document.createElement('thead');
			var hr = document.createElement('tr');
			cols.forEach(function (c) {
				var th = document.createElement('th');
				th.textContent = colLabel(c) || c;
				hr.appendChild(th);
			});
			thead.appendChild(hr);
			preTable.appendChild(thead);
			var tbody = document.createElement('tbody');
			data.preview.forEach(function (row) {
				var tr = document.createElement('tr');
				cols.forEach(function (c) {
					var td = document.createElement('td');
					td.textContent = row[c] != null ? String(row[c]) : '';
					tr.appendChild(td);
				});
				tbody.appendChild(tr);
			});
			preTable.appendChild(tbody);
			container.appendChild(preTable);
		}
	}

	function renderImportResult(container, result) {
		container.textContent = '';
		var p = document.createElement('p');
		p.style.color = '#00a32a';
		p.innerHTML = '<strong>Inseridos:</strong> ' + result.inserted +
			' | <strong>Omitidos (xa exist\u00EDan):</strong> ' + result.skipped;
		container.appendChild(p);
		if (result.errors && result.errors.length > 0) {
			var errP = document.createElement('p');
			errP.style.color = '#d63638';
			errP.textContent = 'Erros durante commit: ' + result.errors.length;
			container.appendChild(errP);
		}
		if (result.fillo_merges && result.fillo_merges.length > 0) {
			var mergeP = document.createElement('p');
			mergeP.style.color = '#dba617';
			mergeP.textContent = 'Fillos/as duplicados fusionados: ' + result.fillo_merges.reduce(function (acc, m) { return acc + m.merged; }, 0);
			container.appendChild(mergeP);
		}
	}

	// ── Per-section import button (co-located with export) ───────────
	function addCsvImportBtn(container, entity) {
		var btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary';
		btn.textContent = 'Importar CSV';
		btn.style.marginLeft = '0.3rem';
		btn.addEventListener('click', function () {
			navigateTo('importar-listados');
		});
		container.appendChild(btn);
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
