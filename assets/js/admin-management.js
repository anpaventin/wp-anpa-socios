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
	var activeCourse = String(cfg.cursoactivo || '');
	var filloCursos = Array.isArray(cfg.filloCursos) ? cfg.filloCursos.map(String) : ['1', '2', '3', '4', '5', '6'];
	var filloGrupos = Array.isArray(cfg.filloGrupos) ? cfg.filloGrupos.map(String) : ['A', 'B', 'C', 'D'];
	var SECTION_ALIASES = { 'cursos-matriculas': 'matriculas' };
	function normalizeSection(section) {
		return SECTION_ALIASES[section] || section;
	}
	// ── External helpers ──────────────────────────────────────────────
	var tbl = window.AnpaAdminTable || { sortRows: function (r) { return r; }, pageSlice: function (r) { return r; } };
	var utils = window.AnpaUtils || {};
	var baseColLabel = utils.colLabel || function (k) { return k; };
	var adminColLabels = {
		solicitado_en: 'Data da solicitude',
		resolto_en: 'Data da resolución',
		resolto_por: 'Resolto por',
		cursos_ofertados: 'Cursos nos que se oferta',
	};
	function colLabel(key) { return adminColLabels[key] || baseColLabel(key); }
	function formatAdminDate(value) {
		return String(value || '').replace(/^(\d{4})-(\d{2})-(\d{2})/, '$3/$2/$1');
	}
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
				return resp.json().catch(function () { return {}; }).then(function (err) {
					var error = new Error(err.message || 'Erro ' + resp.status);
					error.status = resp.status;
					error.code = err.code || '';
					error.data = err.data || {};
					throw error;
				});
			}
			if (resp.status === 204) {
				return null;
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

	function buildFilloSelect(options, current, labeler) {
		var select = document.createElement('select');
		select.required = true;
		var placeholder = document.createElement('option');
		placeholder.value = '';
		placeholder.textContent = '-- Selecciona --';
		select.appendChild(placeholder);
		options.forEach(function (value) {
			var option = document.createElement('option');
			option.value = value;
			option.textContent = labeler ? labeler(value) : value;
			option.selected = String(current || '') === value;
			select.appendChild(option);
		});
		return select;
	}

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

	// ── Passphrase modal
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
				if (Array.isArray(v)) {
					// e.g. cursos_ofertados: comma+space separated, em-dash when empty.
					td.textContent = v.length ? v.join(', ') : '\u2014';
				} else {
					td.textContent = v === null || v === undefined ? '' : String(v);
				}
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

	// Wires the search input for a section. Fixes the focus-loss bug: each
	// keystroke used to re-render the whole panel (destroying the input), so
	// after 2-3 letters focus was lost. Now we restore focus + caret to the
	// freshly-built input whenever the last render was triggered by typing.
	function wireSearchInput(bar, st, render) {
		var input = bar._searchInput;
		if (input._anpaSearchWired) { return; }
		input._anpaSearchWired = true;
		if (st._searchFocused) {
			// The panel was just rebuilt due to typing — return focus + caret.
			input.focus();
			try { var v = input.value; input.setSelectionRange(v.length, v.length); } catch (e) {}
		}
		var timer = null;
		input.addEventListener('input', function () {
			st.searchQuery = this.value;
			st._searchFocused = true;
			if (timer) { clearTimeout(timer); }
			timer = setTimeout(function () { st.page = 1; render(); }, 300);
		});
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
	function addCsvExportBtn(container, section) {
		var btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary';
		btn.textContent = 'Exportar CSV';
		btn.addEventListener('click', function () {
			exportServerCsv(section, 'anpa-' + section + '.csv');
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
	var SOCIOS_COLS = ['email', 'nome', 'apelidos', 'telefono', 'nif', 'segundo_proxenitor_nome', 'segundo_proxenitor_email', 'segundo_proxenitor_telefono', 'segundo_proxenitor_nif', 'estado'];

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
				r.segundo_proxenitor_telefono = sp.telefono || '';
				r.segundo_proxenitor_nif = sp.nif || '';
				if (!sp.email && !sp.nif && !sp.telefono) {
					r.segundo_proxenitor_nome += ' \u26A0';
				}
			} else {
				r.segundo_proxenitor_nome = '';
				r.segundo_proxenitor_email = '';
				r.segundo_proxenitor_telefono = '';
				r.segundo_proxenitor_nif = '';
			}
		});
		var st = sectionState.socios || (sectionState.socios = { sort: { key: 'email', dir: 'asc' }, page: 1, size: 10 });

		function render() {
			var currentSearch = st.searchQuery || '';
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
			bar._searchInput.value = currentSearch;

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

			wireSearchInput(bar, st, render);
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

			wireSearchInput(bar, st, render);
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

		// Definitive deletion is intentionally separate from setting estado=baixa.
		if (socio.estado === 'baixa') {
			var hardDeleteBtn = document.createElement('button');
			hardDeleteBtn.type = 'button';
			hardDeleteBtn.className = 'anpa-mgmt-btn anpa-mgmt-btn-danger';
			hardDeleteBtn.textContent = 'Eliminar definitivamente';
			hardDeleteBtn.addEventListener('click', function () {
				if (!window.confirm('Eliminar definitivamente este socio/a? Esta acción non se pode desfacer.')) { return; }
				var path = 'socio/' + encodeURIComponent(socio.email);
				anpaAdminFetch(path, { method: 'DELETE' })
					.then(finishFamilyDelete)
					.catch(function (error) {
						if (!error.data || !error.data.requires_family_confirmation) {
							showMessage(error.message, 'error');
							return;
						}
						var summary = error.data.summary || {};
						if (!window.confirm(buildFamilyDeleteWarning(summary))) { return; }
						var phrase = window.prompt('Para confirmar o borrado de toda a familia, escribe ELIMINAR_FAMILIA:');
						if (phrase !== 'ELIMINAR_FAMILIA') {
							showMessage('Borrado cancelado: a frase de confirmación non coincide.', 'info');
							return;
						}
						anpaAdminFetch(path + '?cascade_family=1&confirm=ELIMINAR_FAMILIA', { method: 'DELETE' })
							.then(finishFamilyDelete)
							.catch(function (e) { showMessage(e.message, 'error'); });
					});
			});
			actions.appendChild(hardDeleteBtn);
		}
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

	function buildFamilyDeleteWarning(summary) {
		var parents = Number(summary.other_parents || 0);
		var children = Number(summary.children || 0);
		var banking = Number(summary.banking || 0);
		var sessions = Number(summary.sessions || 0);
		var verificationCodes = Number(summary.verification_codes || 0);
		var lines = ['Este socio/a ten datos familiares asociados.', '', 'Eliminaranse tamén:'];
		if (parents) {
			lines.push('- Outros proxenitores: ' + parents);
		}
		if (children) {
			lines.push('- Fillos/as: ' + children);
		}
		lines.push('- Matrículas: ' + (parseInt(summary.enrolments, 10) || 0));
		lines.push('- Historial escolar: ' + (parseInt(summary.school_assignments, 10) || 0));
		lines.push('- Domiciliacións bancarias: ' + banking);
		lines.push('- Sesións abertas: ' + sessions);
		lines.push('- Códigos de verificación: ' + verificationCodes);
		lines.push('', 'Queres eliminar definitivamente o outro proxenitor, os fillos e todas as súas relacións?');

		return lines.join('\n');
	}

	function finishFamilyDelete(result) {
		var deleted = result && result.deleted ? result.deleted : {};
		showMessage(
			(result && result.message ? result.message : 'Eliminación completada.') +
			' Proxenitores: ' + (deleted.parents || 0) +
			'; fillos/as: ' + (deleted.children || 0) +
			'; matrículas: ' + (deleted.enrolments || 0) +
			'; historial escolar: ' + (deleted.school_assignments || 0) +
			'; domiciliacións: ' + (deleted.banking || 0) +
			'; sesións: ' + (deleted.sessions || 0) +
			'; códigos: ' + (deleted.verification_codes || 0) + '.',
			'success'
		);
		loadSocios();
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

		// ── Dynamic nivel→aula selectors (ES4) ──
		var anpaNiveis = Array.isArray(cfg.filloniveis) ? cfg.filloniveis : [];
		var anpaAulas  = Array.isArray(cfg.filloaulas) ? cfg.filloaulas : [];

		var cursoInput, aulaInput;
		if (anpaNiveis.length > 0) {
			// Nivel select
			cursoInput = document.createElement('select'); cursoInput.required = true;
			var ph1 = document.createElement('option'); ph1.value = ''; ph1.textContent = '-- Selecciona --';
			cursoInput.appendChild(ph1);
			anpaNiveis.forEach(function (n) {
				var o = document.createElement('option'); o.value = n.codigo; o.textContent = n.etiqueta;
				if (isEdit && fillo.curso === n.codigo) { o.selected = true; }
				cursoInput.appendChild(o);
			});
			// Aula select (dependent)
			aulaInput = document.createElement('select'); aulaInput.required = true;
			function atualizaAulas(nivelCod) {
				aulaInput.textContent = '';
				var ph2 = document.createElement('option'); ph2.value = ''; ph2.textContent = '-- Selecciona --';
				aulaInput.appendChild(ph2);
				var nid = null;
				anpaNiveis.forEach(function (n) { if (n.codigo === nivelCod) { nid = n.id; } });
				anpaAulas.forEach(function (a) {
					if (parseInt(a.nivel_id, 10) === parseInt(nid, 10)) {
						var op = document.createElement('option'); op.value = a.codigo; op.textContent = a.etiqueta;
						if (isEdit && fillo.aula === a.codigo) { op.selected = true; }
						aulaInput.appendChild(op);
					}
				});
			}
			cursoInput.addEventListener('change', function () { atualizaAulas(cursoInput.value); });
			if (isEdit) { atualizaAulas(fillo.curso); }
		} else {
			// Legacy fallback
			cursoInput = buildFilloSelect(filloCursos, isEdit ? fillo.curso : '', function (value) { return value + '\u00BA'; });
			aulaInput  = buildFilloSelect(filloGrupos, isEdit ? fillo.aula : '');
		}
		addInlineField('Curso', cursoInput);
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
			if (!payload.nome || !payload.apelidos || !payload.curso || !payload.aula) {
				showMessage('Nome, apelidos, curso e grupo son obrigatorios.', 'error'); return;
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
			anpaAdminFetch('approvals/history').then(function (history) {
				renderApprovals(rows, history);
			}).catch(function () {
				renderApprovals(rows, []);
				showMessage('Non se puido cargar o historial de aprobacións.', 'warning');
			});
		}).catch(sectionError);
	}

	function renderApprovals(rows, historyRows) {
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
		} else {
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

		// ── Historical approvals ──
		var hist = Array.isArray(historyRows) ? historyRows : [];
		if (hist.length) {
			var hr2 = document.createElement('hr');
			root.appendChild(hr2);
			var h3h = document.createElement('h3');
			h3h.textContent = 'Historial de aprobaci\u00F3ns';
			root.appendChild(h3h);
			var HIST_COLS = ['nome', 'apelidos', 'email', 'accion', 'solicitado_en', 'resolto_en', 'resolto_por'];
			var histTable = document.createElement('table');
			histTable.className = 'anpa-mgmt-table';
			var histThead = document.createElement('thead');
			var histHr = document.createElement('tr');
			HIST_COLS.forEach(function (c) {
				var th = document.createElement('th');
				th.textContent = colLabel(c) || c;
				histHr.appendChild(th);
			});
			histThead.appendChild(histHr);
			histTable.appendChild(histThead);
			var histTbody = document.createElement('tbody');
			hist.forEach(function (row) {
				var tr = document.createElement('tr');
				HIST_COLS.forEach(function (c) {
					var td = document.createElement('td');
					if (c === 'accion') {
						td.textContent = row[c] === 'approval_approve' ? 'Aprobado' : 'Rexeitado';
						if (row[c] === 'approval_approve') { td.style.color = '#1e7e34'; td.style.fontWeight = '600'; }
						else { td.style.color = '#b32d2e'; td.style.fontWeight = '600'; }
					} else if (c === 'solicitado_en' || c === 'resolto_en') {
						td.textContent = formatAdminDate(row[c]);
					} else {
						td.textContent = row[c] != null ? String(row[c]) : '';
					}
					tr.appendChild(td);
				});
				histTbody.appendChild(tr);
			});
			histTable.appendChild(histTbody);
			root.appendChild(histTable);
		}
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
			var currentSearch = st.searchQuery || '';
			root.textContent = '';
			var bar = buildFilterBar('fillos', { onRefresh: render });
			root.appendChild(bar);
			bar._searchInput.value = currentSearch;
			addCsvExportBtn(bar, 'fillos', allRows, FILLOS_COLS);
			addCsvImportBtn(bar, 'fillos');
			var query = bar._searchInput.value || '';
			var filtered = filterRows(allRows, query, FILLOS_COLS);
			var sorted = tbl.sortRows(filtered, st.sort.key, st.sort.dir);
			wireSearchInput(bar, st, render);
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

			wireSearchInput(bar, st, render);
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
		// Dynamic nivel/aula structure (localized via cfg). These were only
		// defined inside renderFilloForm before, so renderFilloEdit threw a
		// ReferenceError and rendered nothing when editing a fillo.
		var anpaNiveis = Array.isArray(cfg.filloniveis) ? cfg.filloniveis : [];
		var anpaAulas  = Array.isArray(cfg.filloaulas) ? cfg.filloaulas : [];
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
		// ── Dynamic nivel→aula selectors (ES4) ──
		var cursoInput, aulaInput;
		if (anpaNiveis.length > 0) {
			cursoInput = document.createElement('select'); cursoInput.required = true;
			var ph1 = document.createElement('option'); ph1.value = ''; ph1.textContent = '-- Selecciona --';
			cursoInput.appendChild(ph1);
			anpaNiveis.forEach(function (n) {
				var o = document.createElement('option'); o.value = n.codigo; o.textContent = n.etiqueta;
				if (fillo.curso === n.codigo) { o.selected = true; }
				cursoInput.appendChild(o);
			});
			aulaInput = document.createElement('select'); aulaInput.required = true;
			function atAulas(nivelCod) {
				aulaInput.textContent = '';
				var ph2 = document.createElement('option'); ph2.value = ''; ph2.textContent = '-- Selecciona --';
				aulaInput.appendChild(ph2);
				var nid = null;
				anpaNiveis.forEach(function (n) { if (n.codigo === nivelCod) { nid = n.id; } });
				anpaAulas.forEach(function (a) {
					if (parseInt(a.nivel_id, 10) === parseInt(nid, 10)) {
						var op = document.createElement('option'); op.value = a.codigo; op.textContent = a.etiqueta;
						if (fillo.aula === a.codigo) { op.selected = true; }
						aulaInput.appendChild(op);
					}
				});
			}
			cursoInput.addEventListener('change', function () { atAulas(cursoInput.value); });
			atAulas(fillo.curso);
		} else {
			cursoInput = buildFilloSelect(filloCursos, fillo.curso, function (value) { return value + '\u00BA'; });
			aulaInput  = buildFilloSelect(filloGrupos, fillo.aula);
		}
		addField('anpa-fillo-curso', 'Curso', cursoInput);
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
			if (!payload.nome || !payload.apelidos || !payload.curso || !payload.aula) {
				showMessage('Nome, apelidos, curso e grupo son obrigatorios.', 'error'); return;
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

		// Definitive delete: only for an already-disabled (baixa) fillo with no
		// matrículas. The backend enforces the same guard and returns 409 otherwise.
		if (fillo.estado === 'baixa') {
			var hardDelBtn = document.createElement('button');
			hardDelBtn.type = 'button'; hardDelBtn.className = 'anpa-mgmt-btn anpa-mgmt-btn-danger';
			hardDelBtn.textContent = 'Eliminar definitivamente';
			hardDelBtn.addEventListener('click', function () {
				if (!window.confirm('Eliminar definitivamente este fillo/a? Só é posible se non ten matrículas. Esta acción non se pode desfacer.')) { return; }
				anpaAdminFetch('fillo/' + fillo.id + '?hard=1', { method: 'DELETE' }).then(function () {
					showMessage('Fillo/a eliminado definitivamente.', 'success');
					loadFillos();
				}).catch(function (e) { showMessage(e.message, 'error'); });
			});
			actions.appendChild(hardDelBtn);
		}
		form.appendChild(actions);

		// ── Informational panel: matrículas by year (newest first) ──
		var matPanel = document.createElement('div');
		matPanel.className = 'anpa-mgmt-fillo-matriculas';
		matPanel.style.marginTop = '1.5rem';
		var matTitle = document.createElement('h4');
		matTitle.textContent = 'Matrículas asociadas';
		matPanel.appendChild(matTitle);
		var matBody = document.createElement('div');
		matBody.textContent = 'Cargando…';
		matPanel.appendChild(matBody);
		form.appendChild(matPanel);

		anpaAdminFetch('fillo/' + fillo.id + '/matriculas').then(function (rows) {
			matBody.textContent = '';
			var list = Array.isArray(rows) ? rows : [];
			if (!list.length) { matBody.appendChild(emptyEl('Este fillo/a non ten matrículas asociadas.')); return; }
			// Group by curso_escolar, newest year first (backend already sorts desc).
			var byYear = {};
			var order = [];
			list.forEach(function (r) {
				var y = r.curso_escolar || '—';
				if (!byYear[y]) { byYear[y] = []; order.push(y); }
				byYear[y].push(r);
			});
			order.forEach(function (y) {
				var h = document.createElement('h5');
				h.textContent = y;
				h.style.margin = '0.75rem 0 0.25rem';
				matBody.appendChild(h);
				var t = document.createElement('table');
				t.className = 'widefat striped';
				var thead = document.createElement('thead');
				thead.innerHTML = '<tr><th>Actividade</th><th>Franxa</th><th>Trimestre</th><th>Estado</th></tr>';
				t.appendChild(thead);
				var tb = document.createElement('tbody');
				byYear[y].forEach(function (r) {
					var tr = document.createElement('tr');
					[r.actividade, r.franxa, r.trimestre, r.estado].forEach(function (v) {
						var td = document.createElement('td'); td.textContent = v != null && v !== '' ? String(v) : '—'; tr.appendChild(td);
					});
					tb.appendChild(tr);
				});
				t.appendChild(tb);
				matBody.appendChild(t);
			});
		}).catch(function () { matBody.textContent = 'Non foi posible cargar as matrículas.'; });

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
			var currentSearch = st.searchQuery || '';
			root.textContent = '';
			var active = allRows.filter(function (r) { return r.estado !== 'inactivo'; });
			var visible = st.showInactive ? allRows : active;
			var bar = buildFilterBar('empresas', { hasInactive: true, activeCount: active.length, totalCount: allRows.length, onRefresh: render });
			root.appendChild(bar);
			bar._searchInput.value = currentSearch;
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
			wireSearchInput(bar, st, render);
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

				// Delete button only when inactive
				if (row.estado === 'inactivo') {
					var deleteBtn = document.createElement('button');
					deleteBtn.type = 'button'; deleteBtn.className = 'anpa-mgmt-btn anpa-mgmt-btn-danger';
					deleteBtn.textContent = 'Eliminar';
					deleteBtn.addEventListener('click', function () {
						if (!window.confirm('Eliminar esta empresa? Esta acci\u00F3n non se pode desfacer.')) { return; }
						anpaAdminFetch('empresa/' + row.id, { method: 'DELETE' }).then(function () {
							showMessage('Empresa eliminada.', 'success');
							loadEmpresas();
						}).catch(function (e) { showMessage(e.message, 'error'); loadEmpresas(); });
					});
					actionsTd.appendChild(deleteBtn);
				}
			});

			root.appendChild(table);

			if (st.size > 0 && sorted.length > st.size) {
				root.appendChild(buildPagination(sorted.length, st.page, st.size, function (p, s) {
					st.page = p; st.size = s; render();
				}));
			}

			wireSearchInput(bar, st, render);
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

		// Show related actividades when editing an existing empresa
		if (isEdit && empresa.id) {
			var h4 = document.createElement('h4');
			h4.textContent = 'Actividades desta empresa';
			form.appendChild(h4);

			anpaAdminFetch('actividades').then(function (acts) {
				var relActs = acts.filter(function (a) { return String(a.empresa_id) === String(empresa.id); });
				if (relActs.length === 0) {
					var p = document.createElement('p');
					p.textContent = 'Esta empresa non ten actividades asociadas.';
					p.className = 'description';
					form.appendChild(p);
					return;
				}
				var actTable = document.createElement('table');
				actTable.className = 'widefat striped';
				actTable.style.marginTop = '1em';
				var thead = document.createElement('thead');
				var headerRow = document.createElement('tr');
				['Nome', 'Curso escolar', 'Estado'].forEach(function (h) {
					var th = document.createElement('th');
					th.textContent = h;
					headerRow.appendChild(th);
				});
				thead.appendChild(headerRow);
				actTable.appendChild(thead);
				var tbody = document.createElement('tbody');
				relActs.forEach(function (a) {
					var tr = document.createElement('tr');
					var td1 = document.createElement('td');
					td1.textContent = a.nome || '';
					var td2 = document.createElement('td');
					td2.textContent = a.curso_escolar || '';
					var td3 = document.createElement('td');
					td3.textContent = a.estado || '';
					tr.appendChild(td1);
					tr.appendChild(td2);
					tr.appendChild(td3);
					tbody.appendChild(tr);
				});
				actTable.appendChild(tbody);
				form.appendChild(actTable);
			}).catch(function () {
				// Silently ignore fetch errors for this ancillary data
			});
		}

		root.appendChild(form);
	}

	// ── Section: Actividades ─────────────────────────────────────────
	var ACTIV_COLS = ['_empresa_nome', 'nome', 'custo', 'estado'];
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
			var currentSearch = st.searchQuery || '';
			root.textContent = '';
			var active = allRows.filter(function (r) {
				return r.estado !== 'inactivo' &&
					Array.isArray(r.cursos_ofertados) &&
					r.cursos_ofertados.indexOf(activeCourse) !== -1;
			});
			var visible = st.showInactive ? allRows : active;
			var bar = buildFilterBar('actividades', { hasInactive: true, activeCount: active.length, totalCount: allRows.length, onRefresh: render });
			root.appendChild(bar);
			bar._searchInput.value = currentSearch;
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
			wireSearchInput(bar, st, render);
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
							descripcion: row.descripcion || '',
							custo: row.custo, estado: newEstado,
							// Preserve every offered year: omitting this wipes the
							// activity's actividades_cursos rows for all OTHER years
							// via sync_actividad_cursos()'s array_diff-based removal.
							cursos: Array.isArray(row.cursos_ofertados) ? row.cursos_ofertados : [],
						};
						anpaAdminFetch('actividad/' + row.id, { method: 'PUT', body: payload }).then(function () {
							showMessage('Estado da actividade actualizado.', 'success');
							loadActividades();
						}).catch(function (e) { showMessage(e.message, 'error'); });
					});
				actionsTd.appendChild(toggleBtn);

				if (row.estado === 'inactivo') {
					var deleteBtn = document.createElement('button');
					deleteBtn.type = 'button'; deleteBtn.className = 'anpa-mgmt-btn anpa-mgmt-btn-danger';
					deleteBtn.textContent = 'Eliminar';
					deleteBtn.addEventListener('click', function () {
						if (!confirm('Eliminar esta actividade? Esta acción non se pode desfacer.')) return;
						anpaAdminFetch('actividad/' + row.id, { method: 'DELETE' }).then(function () {
							showMessage('Actividade eliminada.', 'success');
							loadActividades();
						}).catch(function (e) { showMessage(e.message, 'error'); loadActividades(); });
					});
					actionsTd.appendChild(deleteBtn);
				}
			});

			root.appendChild(table);

			if (st.size > 0 && sorted.length > st.size) {
				root.appendChild(buildPagination(sorted.length, st.page, st.size, function (p, s) {
					st.page = p; st.size = s; render();
				}));
			}

			wireSearchInput(bar, st, render);
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
			lbl.setAttribute('for', id); lbl.textContent = labelText; input.id = id;
			form.appendChild(lbl); form.appendChild(input);
		}

		var empresaSelect = document.createElement('select');
		var emptyEmpresa = document.createElement('option');
		emptyEmpresa.value = ''; emptyEmpresa.textContent = '-- Seleccionar empresa --';
		empresaSelect.appendChild(emptyEmpresa);
		(Array.isArray(empresaList) ? empresaList : []).forEach(function (e) {
			if (e.estado === 'inactivo') { return; }
			var opt = document.createElement('option'); opt.value = String(e.id); opt.textContent = e.nome;
			if (isEdit && String(act.empresa_id) === String(e.id)) { opt.selected = true; }
			empresaSelect.appendChild(opt);
		});
		addField('anpa-act-empresa', 'Empresa', empresaSelect);

		var nomeInput = document.createElement('input'); nomeInput.type = 'text';
		nomeInput.value = isEdit ? (act.nome || '') : '';
		addField('anpa-act-nome', 'Nome', nomeInput);
		var descInput = document.createElement('textarea'); descInput.rows = 3;
		descInput.value = isEdit ? (act.descripcion || '') : '';
		addField('anpa-act-descripcion', 'Descrición', descInput);

		var iconos = [
			{ emoji: '🎒', label: 'Xeral' }, { emoji: '⚽', label: 'Fútbol' },
			{ emoji: '🎨', label: 'Debuxo' }, { emoji: '🎭', label: 'Teatro' },
			{ emoji: '🎵', label: 'Música' }, { emoji: '♟️', label: 'Xadrez' },
			{ emoji: '🤸', label: 'Deporte' }, { emoji: '🎬', label: 'Cine' },
			{ emoji: '🔬', label: 'Ciencia' }, { emoji: '📚', label: 'Lectura' }
		];
		var initialIcono = isEdit ? (act.icono || '🎒') : '🎒';
		var matchedIconoPreset = iconos.some(function (p) { return p.emoji === initialIcono; });
		var iconWrap = document.createElement('div'); iconWrap.className = 'anpa-mgmt-icono-group';
		iconWrap.setAttribute('role', 'radiogroup');
		iconWrap.setAttribute('aria-label', 'Icona da actividade');
		var iconRadios = [];
		iconos.forEach(function (p) {
			var label = document.createElement('label');
			var radio = document.createElement('input'); radio.type = 'radio'; radio.name = 'anpa-act-icono'; radio.value = p.emoji;
			radio.setAttribute('aria-label', p.label);
			if (initialIcono === p.emoji) { radio.checked = true; }
			label.appendChild(radio); label.appendChild(document.createTextNode(' ' + p.emoji + ' ' + p.label));
			iconWrap.appendChild(label); iconRadios.push(radio);
		});
		var iconLabel = document.createElement('label'); iconLabel.textContent = 'Icona';
		form.appendChild(iconLabel); form.appendChild(iconWrap);
		var iconoCustomInput = document.createElement('input'); iconoCustomInput.type = 'text'; iconoCustomInput.maxLength = 20;
		iconoCustomInput.value = matchedIconoPreset ? '' : initialIcono;
		addField('anpa-act-icono-custom', 'Icona personalizada (emoji)', iconoCustomInput);
		var iconoPreview = document.createElement('p');
		iconoPreview.className = 'anpa-mgmt-icono-preview';
		iconoPreview.setAttribute('aria-live', 'polite');
		iconoPreview.textContent = initialIcono;
		form.appendChild(iconoPreview);
		function updateIconoPreview() { iconoPreview.textContent = getSelectedIcono(); }
		iconoCustomInput.addEventListener('input', function () {
			if (iconoCustomInput.value.trim()) { iconRadios.forEach(function (r) { r.checked = false; }); }
			updateIconoPreview();
		});
		iconRadios.forEach(function (r) { r.addEventListener('change', function () { iconoCustomInput.value = ''; updateIconoPreview(); }); });
		function getSelectedIcono() {
			for (var i = 0; i < iconRadios.length; i++) { if (iconRadios[i].checked) { return iconRadios[i].value; } }
			return iconoCustomInput.value.trim() || '🎒';
		}

		var cursosLabel = document.createElement('label'); cursosLabel.textContent = 'Curso activo no que se oferta';
		form.appendChild(cursosLabel);
		var cursosContainer = document.createElement('div'); cursosContainer.className = 'anpa-mgmt-multicurso';
		var seleccionados = isEdit && Array.isArray(act.cursos_ofertados) ? act.cursos_ofertados : [];
		var historicalCourses = seleccionados.filter(function (yr) { return yr !== activeCourse; });
		var activeCourseCheckbox = null;
		if (activeCourse) {
			var activeLabel = document.createElement('label');
			activeCourseCheckbox = document.createElement('input');
			activeCourseCheckbox.type = 'checkbox';
			activeCourseCheckbox.value = activeCourse;
			activeCourseCheckbox.checked = !isEdit || seleccionados.indexOf(activeCourse) !== -1;
			activeCourseCheckbox.disabled = !isEdit;
			activeLabel.appendChild(activeCourseCheckbox);
			activeLabel.appendChild(document.createTextNode(' ' + activeCourse));
			cursosContainer.appendChild(activeLabel);
		} else {
			cursosContainer.appendChild(emptyEl('Non hai un curso activo. Actívao primeiro en Axustes → Cursos.'));
		}
		form.appendChild(cursosContainer);
		if (isEdit && activeCourse) {
			var activeCourseHelp = document.createElement('p');
			activeCourseHelp.className = 'description';
			activeCourseHelp.textContent = 'Desmarca para retirar a oferta deste curso. O historial conservarase.';
			form.appendChild(activeCourseHelp);
		}
		if (historicalCourses.length) {
			var history = document.createElement('details');
			var historySummary = document.createElement('summary');
			historySummary.textContent = 'Cursos nos que se ofertou';
			history.appendChild(historySummary);
			var historyText = document.createElement('p');
			historyText.textContent = historicalCourses.join(', ');
			history.appendChild(historyText);
			form.appendChild(history);
		}

		var custoInput = document.createElement('input'); custoInput.type = 'text'; custoInput.placeholder = '0.00';
		custoInput.value = isEdit ? (act.custo || '0') : '';
		addField('anpa-act-custo', 'Custo (€)', custoInput);
		var estadoSelect = document.createElement('select');
		['activo', 'inactivo'].forEach(function (v) {
			var opt = document.createElement('option'); opt.value = v; opt.textContent = v;
			if (isEdit && act.estado === v) { opt.selected = true; } estadoSelect.appendChild(opt);
		});
		addField('anpa-act-estado', 'Estado', estadoSelect);

		if (isEdit) {
			var gruposSection = document.createElement('section'); gruposSection.className = 'anpa-mgmt-activity-groups';
			var gh = document.createElement('h4'); gh.textContent = 'Grupos da actividade'; gruposSection.appendChild(gh);
			var gp = document.createElement('p'); gp.textContent = 'Nos grupos defínense anos, niveis, horario, franxa, días e capacidade.'; gruposSection.appendChild(gp);
			var manage = document.createElement('button'); manage.type = 'button'; manage.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary'; manage.textContent = 'Xestionar grupos';
			manage.addEventListener('click', function () { renderGruposPanel(act); }); gruposSection.appendChild(manage); form.appendChild(gruposSection);
		}

		var actions = document.createElement('div'); actions.className = 'anpa-mgmt-form-actions';
		var saveBtn = document.createElement('button'); saveBtn.type = 'button'; saveBtn.className = 'anpa-mgmt-btn';
		saveBtn.textContent = isEdit ? 'Gardar cambios' : 'Crear actividade';
		saveBtn.addEventListener('click', function () {
			var activeSelection = activeCourseCheckbox && activeCourseCheckbox.checked ? [activeCourse] : [];
			var cursos = historicalCourses.concat(activeSelection);
			var payload = {
				empresa_id: parseInt(empresaSelect.value, 10) || 0,
				nome: nomeInput.value.trim(), icono: getSelectedIcono(), descripcion: descInput.value.trim(),
				custo: custoInput.value.trim(), estado: estadoSelect.value, cursos: cursos
			};
			if (!payload.empresa_id || !payload.nome || !payload.descripcion || !payload.cursos.length) {
				showMessage('Empresa, nome, descrición e polo menos un curso escolar son obrigatorios.', 'error'); return;
			}
			anpaAdminFetch(isEdit ? 'actividad/' + act.id : 'actividades', { method: isEdit ? 'PUT' : 'POST', body: payload }).then(function () {
				showMessage(isEdit ? 'Actividade actualizada.' : 'Actividade creada.', 'success'); loadActividades();
			}).catch(function (e) { showMessage(e.message, 'error'); });
		});
		actions.appendChild(saveBtn);
		var cancelBtn = document.createElement('button'); cancelBtn.type = 'button'; cancelBtn.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary'; cancelBtn.textContent = 'Volver';
		cancelBtn.addEventListener('click', loadActividades); actions.appendChild(cancelBtn); form.appendChild(actions);
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
		var panel = document.createElement('div'); panel.className = 'anpa-mgmt-form'; panel.style.maxWidth = '1100px';
		var h3 = document.createElement('h3'); h3.textContent = 'Grupos de: ' + (actividad.nome || ''); panel.appendChild(h3);
		var listContainer = document.createElement('div'); panel.appendChild(listContainer);
		var actions = document.createElement('div'); actions.className = 'anpa-mgmt-form-actions';
		var newBtn = document.createElement('button'); newBtn.type = 'button'; newBtn.className = 'anpa-mgmt-btn'; newBtn.textContent = 'Novo grupo';
		newBtn.addEventListener('click', function () { renderGrupoForm(null, actividad); }); actions.appendChild(newBtn);
		var backBtn = document.createElement('button'); backBtn.type = 'button'; backBtn.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary'; backBtn.textContent = 'Volver a actividades';
		backBtn.addEventListener('click', loadActividades); actions.appendChild(backBtn); panel.appendChild(actions); root.appendChild(panel);

		anpaAdminFetch('actividad/' + actividad.id + '/grupos').then(function (rows) {
			var grupos = Array.isArray(rows) ? rows : [];
			listContainer.textContent = '';
			if (!grupos.length) { listContainer.appendChild(emptyEl('Sen grupos.')); return; }
			var table = document.createElement('table'); table.className = 'anpa-mgmt-table';
			var thead = document.createElement('thead'); var hr = document.createElement('tr');
			['Grupo', 'Horario', 'Franxa', 'Días', 'Cursos escolares', 'Min', 'Max', 'Estado', ''].forEach(function (label) {
				var th = document.createElement('th'); th.textContent = label; hr.appendChild(th);
			});
			thead.appendChild(hr); table.appendChild(thead); var tbody = document.createElement('tbody');
			grupos.forEach(function (g) {
				var tr = document.createElement('tr');
				[g.nome, g.horario_label || (g.horario === 'maña' ? 'Mañá' : g.horario === 'manha' ? 'Comedor' : 'Tarde'), g.franxa, g.dias,
				 (Array.isArray(g.cursos) ? g.cursos.join(', ') : ''), g.min_pupilos, g.max_pupilos, g.estado].forEach(function (v) {
					var td = document.createElement('td'); td.textContent = v != null ? String(v) : ''; tr.appendChild(td);
				});
				var tdActions = document.createElement('td'); tdActions.className = 'anpa-mgmt-actions';
				var edit = document.createElement('button'); edit.type = 'button'; edit.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary'; edit.textContent = 'Editar';
				edit.addEventListener('click', function () { renderGrupoForm(g, actividad); }); tdActions.appendChild(edit);
				var toggle = document.createElement('button'); toggle.type = 'button'; toggle.className = g.estado === 'aberto' ? 'anpa-mgmt-btn anpa-mgmt-btn-danger' : 'anpa-mgmt-btn';
				toggle.textContent = g.estado === 'aberto' ? 'Pechar' : 'Abrir'; toggle.addEventListener('click', function () {
					anpaAdminFetch('grupo/' + g.id + '/estado', { method: 'POST', body: { estado: g.estado === 'aberto' ? 'pechado' : 'aberto' } }).then(function () { renderGruposPanel(actividad); }).catch(function (e) { showMessage(e.message, 'error'); });
				}); tdActions.appendChild(toggle);
				var mats = document.createElement('button'); mats.type = 'button'; mats.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary'; mats.textContent = 'Matrículas';
				mats.addEventListener('click', function () { renderGrupoMatriculas(g, actividad); }); tdActions.appendChild(mats);
				var del = document.createElement('button'); del.type = 'button'; del.className = 'anpa-mgmt-btn anpa-mgmt-btn-danger'; del.textContent = 'Eliminar';
				del.addEventListener('click', function () {
					if (!confirm('Eliminar definitivamente esta serie de grupo? Só é posible se non ten matrículas nin histórico.')) { return; }
					anpaAdminFetch('grupo/' + g.id, { method: 'DELETE' }).then(function () { renderGruposPanel(actividad); }).catch(function (e) { showMessage(e.message, 'error'); });
				}); tdActions.appendChild(del);
				tr.appendChild(tdActions); tbody.appendChild(tr);
			});
			table.appendChild(tbody); listContainer.appendChild(table);
		}).catch(function (e) { listContainer.appendChild(emptyEl('Erro ao cargar grupos: ' + e.message)); });
	}

	function renderGrupoForm(grupo, actividad) {
		root.textContent = '';
		var isEdit = grupo !== null; var form = document.createElement('div'); form.className = 'anpa-mgmt-form';
		var h3 = document.createElement('h3'); h3.textContent = (isEdit ? 'Editar grupo' : 'Novo grupo') + ' — ' + (actividad.nome || ''); form.appendChild(h3);
		function addField(id, label, input) { var l = document.createElement('label'); l.htmlFor = id; l.textContent = label; input.id = id; form.appendChild(l); form.appendChild(input); }

		var nome = document.createElement('input'); nome.type = 'text'; nome.value = isEdit ? (grupo.nome || '') : ''; addField('anpa-grupo-nome', 'Nome do grupo', nome);
		var yearsLabel = document.createElement('label'); yearsLabel.textContent = 'Cursos escolares'; form.appendChild(yearsLabel);
		var yearsWrap = document.createElement('div'); yearsWrap.className = 'anpa-mgmt-multicurso'; form.appendChild(yearsWrap);
		var offered = Array.isArray(actividad.cursos_ofertados) ? actividad.cursos_ofertados : [];
		var selected = isEdit && Array.isArray(grupo.cursos) ? grupo.cursos : [];
		var levelControls = {};
		function addLevelYear(year) {
			if (levelControls[year]) { return; }
			var body = document.createElement('div'); body.className = 'anpa-mgmt-niveis-row'; body.textContent = 'Cargando…';
			var block = yearsWrap.querySelector('[data-ano="' + year + '"]');
			if (block) { block.appendChild(body); }
			levelControls[year] = { body: body, block: block };
			anpaAdminFetch('estrutura?curso_escolar=' + encodeURIComponent(year)).then(function (resp) {
				body.textContent = ''; var levels = resp && Array.isArray(resp.niveis) ? resp.niveis : [];
				var pre = isEdit && grupo.niveis_por_ano && Array.isArray(grupo.niveis_por_ano[year]) ? grupo.niveis_por_ano[year].map(String) : [];
				levels.forEach(function (n) {
					var label = document.createElement('label'); var chk = document.createElement('input'); chk.type = 'checkbox'; chk.value = String(n.id); chk.checked = pre.indexOf(String(n.id)) !== -1;
					label.appendChild(chk); label.appendChild(document.createTextNode(' ' + (n.etiqueta || n.codigo))); body.appendChild(label);
				});
				if (!levels.length) { body.appendChild(emptyEl('Este curso non ten niveis dados de alta.')); }
			}).catch(function () { body.textContent = 'Non foi posible cargar os niveis.'; });
		}
		function removeLevelYear(year) { if (levelControls[year]) { levelControls[year].body.remove(); delete levelControls[year]; } }
		offered.forEach(function (year) {
			var block = document.createElement('div'); block.className = 'anpa-mgmt-ano-block'; block.setAttribute('data-ano', year);
			var label = document.createElement('label'); var chk = document.createElement('input'); chk.type = 'checkbox'; chk.className = 'anpa-mgmt-ano-check'; chk.value = year; chk.checked = selected.indexOf(year) !== -1;
			chk.addEventListener('change', function () { if (chk.checked) { addLevelYear(year); } else { removeLevelYear(year); } });
			label.appendChild(chk); label.appendChild(document.createTextNode(' ' + year)); block.appendChild(label); yearsWrap.appendChild(block);
			if (chk.checked) { addLevelYear(year); }
		});

		var horario = document.createElement('div');
		[['maña', 'Mañá'], ['manha', 'Comedor'], ['tarde', 'Tarde']].forEach(function (pair) {
			var label = document.createElement('label'); var radio = document.createElement('input'); radio.type = 'radio'; radio.name = 'anpa-grupo-horario'; radio.value = pair[0]; radio.checked = isEdit ? grupo.horario === pair[0] : pair[0] === 'tarde';
			label.appendChild(radio); label.appendChild(document.createTextNode(' ' + pair[1])); horario.appendChild(label);
		});
		var horarioLabel = document.createElement('label'); horarioLabel.textContent = 'Horario'; form.appendChild(horarioLabel); form.appendChild(horario);

		var parts = isEdit && grupo.franxa ? /^(\d{2}:\d{2})-(\d{2}:\d{2})$/.exec(grupo.franxa) : null;
		var timeWrap = document.createElement('div'); timeWrap.className = 'anpa-mgmt-franxa-row'; var start = document.createElement('input'); start.type = 'time'; start.step = '300'; start.value = parts ? parts[1] : '';
		var sep = document.createElement('span'); sep.textContent = '–'; var end = document.createElement('input'); end.type = 'time'; end.step = '300'; end.value = parts ? parts[2] : '';
		timeWrap.appendChild(start); timeWrap.appendChild(sep); timeWrap.appendChild(end); var timeLabel = document.createElement('label'); timeLabel.textContent = 'Franxa horaria'; form.appendChild(timeLabel); form.appendChild(timeWrap);

		var days = document.createElement('div'); var dayTokens = ['luns','martes','mercores','xoves','venres']; var dayLabels = ['Luns','Martes','Mércores','Xoves','Venres']; var existingDays = isEdit ? String(grupo.dias || '').split(',') : [];
		dayTokens.forEach(function (day, i) { var label = document.createElement('label'); var chk = document.createElement('input'); chk.type = 'checkbox'; chk.value = day; chk.checked = existingDays.indexOf(day) !== -1; label.appendChild(chk); label.appendChild(document.createTextNode(' ' + dayLabels[i])); days.appendChild(label); });
		var daysLabel = document.createElement('label'); daysLabel.textContent = 'Días'; form.appendChild(daysLabel); form.appendChild(days);
		var min = document.createElement('input'); min.type = 'number'; min.min = '1'; min.value = isEdit ? grupo.min_pupilos : '10'; addField('anpa-grupo-min', 'Mínimo de alumnos/as', min);
		var max = document.createElement('input'); max.type = 'number'; max.min = '1'; max.value = isEdit ? grupo.max_pupilos : '15'; addField('anpa-grupo-max', 'Máximo de alumnos/as', max);
		var state = document.createElement('select'); ['aberto','pechado'].forEach(function (v) { var o = document.createElement('option'); o.value = v; o.textContent = v; o.selected = isEdit && grupo.estado === v; state.appendChild(o); }); addField('anpa-grupo-estado', 'Estado', state);

		var actions = document.createElement('div'); actions.className = 'anpa-mgmt-form-actions'; var save = document.createElement('button'); save.type = 'button'; save.className = 'anpa-mgmt-btn'; save.textContent = isEdit ? 'Gardar cambios' : 'Crear grupo';
		save.addEventListener('click', function () {
			var cursos = []; yearsWrap.querySelectorAll('.anpa-mgmt-ano-check:checked').forEach(function (c) { cursos.push(c.value); }); var levels = {};
			cursos.forEach(function (year) { levels[year] = []; if (levelControls[year]) { levelControls[year].body.querySelectorAll('input:checked').forEach(function (c) { levels[year].push(parseInt(c.value,10)); }); } });
			var checkedHorario = horario.querySelector('input:checked'); var selectedDays = []; days.querySelectorAll('input:checked').forEach(function (c) { selectedDays.push(c.value); });
			var payload = { nome: nome.value.trim(), cursos: cursos, niveis_por_ano: levels, horario: checkedHorario ? checkedHorario.value : '', franxa: start.value && end.value ? start.value + '-' + end.value : '', dias: selectedDays, min_pupilos: parseInt(min.value,10), max_pupilos: parseInt(max.value,10), estado: state.value };
			anpaAdminFetch(isEdit ? 'grupo/' + grupo.id : 'actividad/' + actividad.id + '/grupos', { method: isEdit ? 'PATCH' : 'POST', body: payload }).then(function () { renderGruposPanel(actividad); }).catch(function (e) { showMessage(e.message, 'error'); });
		}); actions.appendChild(save);
		var cancel = document.createElement('button'); cancel.type = 'button'; cancel.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary'; cancel.textContent = 'Volver'; cancel.addEventListener('click', function () { renderGruposPanel(actividad); }); actions.appendChild(cancel); form.appendChild(actions); root.appendChild(form);
	}

	function renderGrupoMatriculas(grupo, actividad) {
		root.textContent = '';
		var panel = document.createElement('div');
		panel.className = 'anpa-mgmt-form';
		panel.style.maxWidth = '900px';
		var h3 = document.createElement('h3');
		h3.textContent = 'Matr\u00EDculas do grupo ' + (grupo.nome || '') + ' \u2014 ' + (actividad.nome || '');
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
				var sourceYear = Array.isArray(grupo.cursos) && grupo.cursos.length ? grupo.cursos[0] : '';
				var otherGrupos = allGrupos.filter(function (g) {
					return g.id !== grupo.id && g.annual_ids && g.annual_ids[sourceYear];
				});
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
							opt.value = String(g.annual_ids[sourceYear]);
							opt.textContent = (g.nome || 'Grupo') + ' — ' + (g.horario_label || '') + ' (' + g.dias + ')';
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
		var active = list.filter(function (c) { return c.curso_escolar === current && c.estado === 'activo'; })[0] || null;

		var h3 = document.createElement('h3');
		h3.textContent = 'Matrículas';
		root.appendChild(h3);

		var courseNotice = document.createElement('p');
		courseNotice.className = 'description';
		courseNotice.appendChild(document.createTextNode('O estado, as datas e a apertura do curso escolar configúranse en '));
		var courseSettingsLink = document.createElement('a');
		courseSettingsLink.href = 'admin.php?page=anpa-socios-settings&tab=cursos';
		courseSettingsLink.textContent = 'Axustes → Cursos';
		courseNotice.appendChild(courseSettingsLink);
		courseNotice.appendChild(document.createTextNode('. Aquí só se consulta e xestiona a operativa das matrículas.'));
		root.appendChild(courseNotice);

		if (active) {
			var status = document.createElement('p');
			status.className = 'anpa-mgmt-summary';
			status.textContent = 'Curso activo: ' + active.curso_escolar + ' · Matrículas ' + (active.matriculas_abertas ? 'abertas' : 'pechadas');
			root.appendChild(status);
		}

		if (!list.length) { return; }

		// Course selector for matrículas (defaults to current active course)
		var matCursoDiv = document.createElement('div');
		matCursoDiv.style.marginBottom = '0.75rem';
		var matCursoLabel = document.createElement('label');
		matCursoLabel.textContent = 'Mostrar matrículas de: ';
		matCursoLabel.style.fontWeight = '600';
		var matCursoSelect = document.createElement('select');
		matCursoSelect.setAttribute('aria-label', 'Curso para ver matrículas');
		list.forEach(function (c) {
			var opt = document.createElement('option');
			opt.value = c.curso_escolar;
			opt.textContent = c.curso_escolar;
			matCursoSelect.appendChild(opt);
		});
		matCursoSelect.value = current || list[0].curso_escolar;
		matCursoLabel.appendChild(matCursoSelect);
		matCursoDiv.appendChild(matCursoLabel);

		var viewBtn = document.createElement('button');
		viewBtn.type = 'button';
		viewBtn.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary';
		viewBtn.textContent = 'Ver matrículas';
		viewBtn.style.marginLeft = '0.5rem';
		viewBtn.addEventListener('click', function () {
			loadMat(matCursoSelect.value);
		});
		matCursoDiv.appendChild(viewBtn);
		root.appendChild(matCursoDiv);

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
					var currentSearch = matSt.searchQuery || '';
					matHost.textContent = '';
					var bar = buildFilterBar('matriculas', { onRefresh: renderMat });
					matHost.appendChild(bar);
					bar._searchInput.value = currentSearch;
					addCsvExportBtn(bar, 'matriculas', matRows, MAT_COLS);
					addCsvImportBtn(bar, 'matriculas');
					var query = bar._searchInput.value || '';
					var filtered = filterRows(matRows, query, MAT_COLS);
					var sorted = tbl.sortRows(filtered, matSt.sort.key, matSt.sort.dir);
					wireSearchInput(bar, matSt, renderMat);
					if (!sorted.length) { matHost.appendChild(emptyEl('Sen matr\u00EDculas.')); return; }
					var paged = tbl.pageSlice(sorted, matSt.page, matSt.size || 0);
					var matTable = buildTable(paged, MAT_COLS, matSt.sort, renderMat, null);
					matHost.appendChild(matTable);
					if (matSt.size > 0 && sorted.length > matSt.size) {
						matHost.appendChild(buildPagination(sorted.length, matSt.page, matSt.size, function (p, s) {
							matSt.page = p; matSt.size = s; renderMat();
						}));
					}
					wireSearchInput(bar, matSt, renderMat);
				}
				renderMat();
			}).catch(function (e) { matHost.textContent = ''; showMessage(e.message, 'error'); });
		}
		loadMat(matCursoSelect.value);
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
			var currentSearch = st.searchQuery || '';
			root.textContent = '';
			var bar = buildFilterBar('audit', { onRefresh: render });
			root.appendChild(bar);
			bar._searchInput.value = currentSearch;
			var query = bar._searchInput.value || '';
			var filtered = filterRows(allRows, query, AUDIT_COLS);
			var sorted = tbl.sortRows(filtered, st.sort.key, st.sort.dir);
			wireSearchInput(bar, st, render);
			if (!sorted.length) { root.appendChild(emptyEl('Sen entradas de auditor\u00EDa.')); return; }
			var paged = tbl.pageSlice(sorted, st.page, st.size || 0);
			var table = buildTable(paged, AUDIT_COLS, st.sort, render, null);
			root.appendChild(table);

			if (st.size > 0 && sorted.length > st.size) {
				root.appendChild(buildPagination(sorted.length, st.page, st.size, function (p, s) {
					st.page = p; st.size = s; render();
				}));
			}

			wireSearchInput(bar, st, render);
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

	function loadGruposHorarios() {
		root.textContent = '';

		var panel = document.createElement('section');
		panel.className = 'anpa-mgmt-form anpa-mgmt-placeholder';

		var h3 = document.createElement('h3');
		h3.textContent = 'Grupos e horarios';
		panel.appendChild(h3);

		var intro = document.createElement('p');
		intro.textContent = 'Vista agrupada en preparación. A xestión activa de grupos segue dentro de Actividades e a persistencia chegará en PR-26s7.';
		panel.appendChild(intro);

		var bullets = document.createElement('ul');
		['Horario, franxa e días por grupo', 'Capacidade e estado de apertura', 'Matrículas asociadas e movementos entre grupos'].forEach(function (item) {
			var li = document.createElement('li');
			li.textContent = item;
			bullets.appendChild(li);
		});
		panel.appendChild(bullets);

		var actions = document.createElement('div');
		actions.className = 'anpa-mgmt-form-actions';
		var backBtn = document.createElement('button');
		backBtn.type = 'button';
		backBtn.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary';
		backBtn.textContent = 'Ir a Actividades';
		backBtn.addEventListener('click', function () { navigateTo('actividades'); });
		actions.appendChild(backBtn);
		panel.appendChild(actions);

		root.appendChild(panel);
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
		'grupos-horarios': loadGruposHorarios,
		'matriculas': loadCursos,
		'auditoria': loadAudit,
		'importar-listados': loadImportar,
	};

	function navigateTo(section) {
		section = normalizeSection(section);
		currentSection = section;
		clearMessage();
		// Reset the search state when entering a section so a previous query
		// (and its "Sen resultados") does not persist across tab switches.
		if (sectionState[section]) {
			sectionState[section].searchQuery = '';
			sectionState[section]._searchFocused = false;
			sectionState[section].page = 1;
		}
		// Update nav active state
		if (navEl) {
			navEl.querySelectorAll('button').forEach(function (btn) {
				var selected = normalizeSection(btn.dataset.section) === section;
				btn.setAttribute('aria-selected', selected ? 'true' : 'false');
				btn.tabIndex = selected ? 0 : -1;
			});
		}
		var loader = SECTION_MAP[section];
		if (loader) { loader(); }
		else { root.textContent = ''; root.appendChild(emptyEl('Secci\u00F3n non dispo\u00F1ible.')); }
	}

	// ── Wire navigation buttons ──────────────────────────────────────
	if (navEl) {
		var navButtons = Array.prototype.slice.call(navEl.querySelectorAll('button[data-section]'));
		navButtons.forEach(function (btn, index) {
			btn.addEventListener('click', function () {
				navigateTo(btn.dataset.section);
			});
			btn.addEventListener('keydown', function (event) {
				var next = index;
				if (event.key === 'ArrowRight' || event.key === 'ArrowDown') { next = (index + 1) % navButtons.length; }
				else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') { next = (index - 1 + navButtons.length) % navButtons.length; }
				else if (event.key === 'Home') { next = 0; }
				else if (event.key === 'End') { next = navButtons.length - 1; }
				else { return; }
				event.preventDefault();
				navButtons[next].focus();
				navigateTo(navButtons[next].dataset.section);
			});
		});
	}

	// ── Initial load ─────────────────────────────────────────────────
	navigateTo('socios');

})();
