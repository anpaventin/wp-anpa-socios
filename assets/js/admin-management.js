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
	var activeCourse = String(cfg.cursoactivo || cfg.activeCourse || '');
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
		trimestres: 'Trimestre inscripción',
		// buildTable strips the leading underscore before calling colLabel(),
		// so the lookup key here must be WITHOUT the underscore.
		estado_efectivo: 'Estado',
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
		var method = (opts.method || 'GET').toUpperCase();
		// Clear any stale notice as soon as the user performs a mutating action
		// (create/update/delete). Otherwise a previous error — e.g. "O grupo
		// solapa co horario de comedor…" — would linger after the corrected
		// action succeeds, making it look like the last change failed. Reads
		// (GET) keep the current message.
		if (method !== 'GET') { clearMessage(); }
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

	function openGroupEditor(activityId, groupId, serieUid) {
		var targetActivityId = String(activityId || '');
		var targetGroupId = String(groupId || '');
		var targetSerieUid = String(serieUid || '');
		if (!targetActivityId) {
			showMessage('Non se pode abrir o editor sen actividade asociada.', 'error');
			return Promise.resolve();
		}
		return Promise.all([
			anpaAdminFetch('actividades'),
			anpaAdminFetch('actividad/' + encodeURIComponent(targetActivityId) + '/grupos'),
		]).then(function (results) {
			var actividades = Array.isArray(results[0]) ? results[0] : [];
			var grupos = Array.isArray(results[1]) ? results[1] : [];
			var targetActivity = null;
			var targetGroup = null;
			actividades.forEach(function (actividad) {
				if (!targetActivity && String(actividad.id) === targetActivityId) {
					targetActivity = actividad;
				}
			});
			if (!targetActivity) {
				throw new Error('Non se atopou a actividade solicitada.');
			}
			grupos.forEach(function (group) {
				if (targetGroup) { return; }
				if (targetSerieUid && String(group.serie_uid || '') === targetSerieUid) {
					targetGroup = group;
					return;
				}
				if (targetGroupId && String(group.id || '') === targetGroupId) {
					targetGroup = group;
				}
			});
			if (!targetGroup) {
				throw new Error('Non se atopou o grupo solicitado.');
			}
			renderGrupoForm(targetGroup, targetActivity, activeCourse, true);
		}).catch(function (err) {
			showMessage(err && err.message ? err.message : 'Non se puido abrir o editor do grupo.', 'error');
		});
	}

	function openNewGroupEditor(course) {
		course = String(course || '');
		if (!course) {
			showMessage('Selecciona primeiro un curso escolar.', 'error');
			return Promise.resolve();
		}
		showLoading();
		return anpaAdminFetch('actividades').then(function (rows) {
			var activities = (Array.isArray(rows) ? rows : []).filter(function (actividad) {
				return actividad && actividad.estado !== 'inactivo';
			});
			activities.sort(function (a, b) { return String(a.nome || '').localeCompare(String(b.nome || '')); });
			root.textContent = '';
			var form = document.createElement('section');
			form.className = 'anpa-mgmt-form';
			var title = document.createElement('h3');
			title.textContent = 'Novo grupo — ' + course;
			form.appendChild(title);
			var intro = document.createElement('p');
			intro.textContent = 'Escolle a actividade á que pertencerá o novo grupo.';
			form.appendChild(intro);
			if (!activities.length) {
				form.appendChild(emptyEl('Non hai actividades activas.'));
			} else {
				var label = document.createElement('label');
				label.htmlFor = 'anpa-new-group-activity';
				label.textContent = 'Actividade';
				var select = document.createElement('select');
				select.id = 'anpa-new-group-activity';
				activities.forEach(function (actividad) {
					var option = document.createElement('option');
					option.value = String(actividad.id);
					option.textContent = actividad.nome || ('Actividade ' + actividad.id);
					select.appendChild(option);
				});
				form.appendChild(label);
				form.appendChild(select);
				var continueBtn = document.createElement('button');
				continueBtn.type = 'button';
				continueBtn.className = 'anpa-mgmt-btn';
				continueBtn.textContent = 'Continuar';
				continueBtn.addEventListener('click', function () {
					var selectedActivity = activities.filter(function (actividad) { return String(actividad.id) === select.value; })[0];
					if (selectedActivity) { renderGrupoForm(null, selectedActivity, course, true); }
				});
				form.appendChild(continueBtn);
			}
			var backBtn = document.createElement('button');
			backBtn.type = 'button';
			backBtn.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary';
			backBtn.textContent = 'Volver a grupos e horarios';
			backBtn.addEventListener('click', loadGruposHorarios);
			form.appendChild(backBtn);
			root.appendChild(form);
		}).catch(function (err) {
			sectionError(err);
		});
	}

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

	// 1.65.5: the browser starts the download AFTER the click returns, so the blob URL
	// has to outlive it. Revoking it right away (as before) made Firefox and recent
	// Chromium cancel the download silently: the panel said «descargado» and no file
	// appeared. Keep the link a minute, then clean up.
	function downloadBlob(blob, filename) {
		var url = URL.createObjectURL(blob);
		var a = document.createElement('a');
		a.href = url;
		a.download = filename;
		a.rel = 'noopener';
		a.style.display = 'none';
		document.body.appendChild(a);
		a.click();
		setTimeout(function () {
			document.body.removeChild(a);
			URL.revokeObjectURL(url);
		}, 60000);
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

	/**
	 * Prepends a grouped header row (spanning column groups) to a table built
	 * by buildTable. Purely presentational: it does not touch the columns, sort,
	 * search, pagination or CSV contract. `groups` is an array of
	 * { label, span }; the spans must add up to the table's total column count
	 * (data columns + the actions column).
	 * @param {HTMLTableElement} table
	 * @param {Array<{label:string, span:number}>} groups
	 */
	function prependGroupedHeader(table, groups) {
		var thead = table ? table.querySelector('thead') : null;
		if (!thead || !Array.isArray(groups)) { return; }
		var row = document.createElement('tr');
		row.className = 'anpa-mgmt-colgroup';
		groups.forEach(function (g) {
			var th = document.createElement('th');
			th.scope = 'colgroup';
			th.textContent = g && g.label ? g.label : '';
			if (g && g.span > 1) { th.colSpan = g.span; }
			row.appendChild(th);
		});
		thead.insertBefore(row, thead.firstChild);
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
			// Consume the flag: a later re-render caused by a sort click or the
			// pagination must not steal the focus back into the search box.
			st._searchFocused = false;
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
	// `onDone` (optional, 1.60.0) runs after a successful download — used by
	// Matrículas to warn that the list may still change while enrolments are open.
	function addCsvExportBtn(container, section, _rows, _cols, onDone) {
		var btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary';
		btn.textContent = 'Exportar CSV';
		btn.addEventListener('click', function () {
			exportServerCsv(section, 'anpa-' + section + '.csv', onDone);
		});
		container.appendChild(btn);
	}

	// ── Server CSV export (via REST) ──────────────────────────────────
	function exportServerCsv(entity, filename, onDone) {
		anpaAdminFetch('export/' + entity).then(function (blob) {
			downloadBlob(blob, filename || ('anpa-' + entity + '.csv'));
			if (typeof onDone === 'function') { onDone(); }
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
			wireSearchInput(bar, st, render);
			if (!sorted.length) { root.appendChild(emptyEl('Sen resultados.')); return; }
			var paged = tbl.pageSlice(sorted, st.page, st.size || 0);
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
			// Label ABOVE the control (like the other forms). The control is
			// nested in the label for implicit association, but rendered as a
			// block on its own line so the caption is not beside the field.
			var lbl = document.createElement('label');
			lbl.style.display = 'block';
			lbl.style.marginTop = '0.5rem';
			lbl.style.fontWeight = '500';
			var caption = document.createElement('span');
			caption.style.display = 'block';
			caption.style.marginBottom = '0.2rem';
			caption.textContent = labelText;
			lbl.appendChild(caption);
			input.style.display = 'block';
			input.style.width = '100%';
			input.style.maxWidth = '400px';
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
			// 1.68.0: enrolment requests made with the window closed wait here too.
			anpaAdminFetch('matriculas/pendentes').catch(function () { return { matriculas: [] }; }).then(function (pendentes) {
				anpaAdminFetch('approvals/history').then(function (history) {
					renderApprovals(rows, history, pendentes);
				}).catch(function () {
					renderApprovals(rows, [], pendentes);
					showMessage('Non se puido cargar o historial de aprobacións.', 'warning');
				});
			});
		}).catch(sectionError);
	}

	function renderApprovals(rows, historyRows, pendentes) {
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

		// ── 1.68.0: Matrículas pendentes de aprobación ──
		var pend = pendentes && Array.isArray(pendentes.matriculas) ? pendentes.matriculas : [];
		var h3m = document.createElement('h3');
		h3m.style.marginTop = '1.5rem';
		h3m.textContent = 'Matrículas pendentes de aprobación (' + pend.length + ')';
		root.appendChild(h3m);
		var introM = document.createElement('p');
		introM.className = 'description';
		introM.textContent = 'Solicitudes feitas polas familias co prazo de matrícula pechado. Aprobar dá praza se o grupo está aberto e ten sitio (se non, lista de espera); rexeitar anula a solicitude. Nos dous casos a familia recibe un correo («matricula_aprobada_praza», «matricula_aprobada_espera» ou «matricula_rexeitada» en Axustes → Plantillas de email). Ao activar o seguinte trimestre en Extraescolares → Matrículas pódense aprobar todas dunha vez.';
		root.appendChild(introM);
		if (!pend.length) {
			root.appendChild(emptyEl('Non hai matrículas pendentes de aprobación.'));
		} else {
			var tm = document.createElement('table'); tm.className = 'anpa-mgmt-table';
			var tmHead = document.createElement('thead'); var tmHr = document.createElement('tr');
			['Data', 'Alumno/a', 'Curso/Aula', 'Actividade', 'Grupo', 'Ocupación', 'Se se aproba', 'Familia (email)', 'Accións'].forEach(function (l) { var th = document.createElement('th'); th.textContent = l; tmHr.appendChild(th); });
			tmHead.appendChild(tmHr); tm.appendChild(tmHead);
			var tmBody = document.createElement('tbody');
			function cellM(text) { var td = document.createElement('td'); td.textContent = text == null ? '' : String(text); return td; }
			pend.forEach(function (m) {
				var tr = document.createElement('tr');
				tr.className = 'anpa-row-matricula-pendente';
				tr.appendChild(cellM(formatAdminDate(m.solicitada_en)));
				tr.appendChild(cellM(((m.fillo_apelidos || '') + ', ' + (m.fillo_nome || '')).replace(/^, /, '')));
				tr.appendChild(cellM(m.curso_completo));
				tr.appendChild(cellM(m.actividade));
				tr.appendChild(cellM([m.grupo, m.dias, m.franxa].filter(Boolean).join(' · ')));
				tr.appendChild(cellM((m.activos || 0) + '/' + (m.max_pupilos || 0) + ' inscritos' + (m.espera ? ' · ' + m.espera + ' en espera' : '') + (m.grupo_estado && m.grupo_estado !== 'aberto' ? ' · grupo ' + m.grupo_estado : '')));
				tr.appendChild(cellM(m.destino === 'activo' ? 'Praza' : 'Lista de espera'));
				tr.appendChild(cellM(m.socio_email));
				var td = document.createElement('td');
				var ok = document.createElement('button'); ok.type = 'button'; ok.className = 'anpa-mgmt-btn'; ok.textContent = 'Aprobar';
				ok.addEventListener('click', function () {
					if (!window.confirm('Aprobar a matrícula de ' + (m.fillo_nome || '') + ' en ' + (m.actividade || '') + '? ' + (m.destino === 'activo' ? 'Ten praza: queda activa.' : 'Non hai praza libre: queda en lista de espera.') + ' A familia recibe un correo.')) { return; }
					anpaAdminFetch('matricula/' + m.id + '/aprobar', { method: 'POST' }).then(function (r) {
						showMessage('Matrícula aprobada: ' + (r && r.estado === 'activo' ? 'praza confirmada' : 'lista de espera (posición ' + (r && r.posicion) + ')') + '. Correos enviados: ' + (r && r.correos) + '.', 'success');
						loadApprovals();
					}).catch(function (e) { showMessage(e.message, 'error'); loadApprovals(); });
				});
				var no = document.createElement('button'); no.type = 'button'; no.className = 'anpa-mgmt-btn anpa-mgmt-btn-danger'; no.textContent = 'Rexeitar'; no.style.marginLeft = '0.4rem';
				no.addEventListener('click', function () {
					if (!window.confirm('Rexeitar a solicitude de ' + (m.fillo_nome || '') + ' en ' + (m.actividade || '') + '? A matrícula pasa a baixa e a familia recibe un correo para contactar coa directiva se ten dúbidas.')) { return; }
					anpaAdminFetch('matricula/' + m.id + '/rexeitar', { method: 'POST' }).then(function (r) {
						showMessage('Solicitude rexeitada. Correos enviados: ' + (r && r.correos) + '.', 'success');
						loadApprovals();
					}).catch(function (e) { showMessage(e.message, 'error'); loadApprovals(); });
				});
				td.appendChild(ok); td.appendChild(no); tr.appendChild(td);
				tmBody.appendChild(tr);
			});
			tm.appendChild(tmBody);
			root.appendChild(tm);
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

	// ── Section: Baixas solicitadas (1.57.0) ─────────────────────────
	// Both queues the families can open from the area: leaving the association
	// (socio.baixa_estado = 'solicitada') and leaving an activity
	// (matricula.estado = 'baixa_solicitada'). Confirm/reject call the admin
	// endpoints; confirming a matrícula frees the seat for the waitlist.
	function loadBaixas() {
		showLoading();
		anpaAdminFetch('baixas-pendentes').then(function (data) { renderBaixas(data); }).catch(sectionError);
	}

	function renderBaixas(data) {
		root.textContent = '';
		document.title = 'Baixas de socios — Xestión ANPA';
		var socios = data && Array.isArray(data.socios) ? data.socios : [];

		var intro = document.createElement('p');
		intro.className = 'description';
		// 1.62.0: both decisions email the family with the templates in Axustes → Plantillas.
		intro.textContent = 'Solicitudes de baixa da asociación feitas polas familias desde a área de socios. Confirmar fai efectiva a baixa de toda a unidade familiar; rexeitar deixa todo como estaba. Nos dous casos a familia recibe un correo automático (plantillas «baixa_socio_confirmada» e «baixa_socio_rexeitada» en Axustes → Plantillas de email). As baixas de actividades pedidas polas familias xestiónanse en Extraescolares → Matrículas (1.68.1).';
		root.appendChild(intro);

		function actionCell(onConfirm, onReject, confirmLabel) {
			var td = document.createElement('td');
			var ok = document.createElement('button');
			ok.type = 'button'; ok.className = 'anpa-mgmt-btn anpa-mgmt-btn-danger'; ok.textContent = confirmLabel;
			ok.addEventListener('click', onConfirm);
			var no = document.createElement('button');
			no.type = 'button'; no.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary'; no.textContent = 'Rexeitar';
			no.style.marginLeft = '0.4rem';
			no.addEventListener('click', onReject);
			td.appendChild(ok); td.appendChild(no);
			return td;
		}
		function simpleTable(labels) {
			var table = document.createElement('table');
			table.className = 'anpa-mgmt-table';
			var thead = document.createElement('thead');
			var hr = document.createElement('tr');
			labels.forEach(function (l) { var th = document.createElement('th'); th.textContent = l; hr.appendChild(th); });
			thead.appendChild(hr); table.appendChild(thead);
			var tbody = document.createElement('tbody');
			table.appendChild(tbody);
			table._tbody = tbody;
			return table;
		}
		function cell(text) { var td = document.createElement('td'); td.textContent = text == null ? '' : String(text); return td; }
		function act(path, okMsg) {
			anpaAdminFetch(path, { method: 'POST' }).then(function (r) {
				// 1.62.0: say whether the family's email went out (the decision itself is already applied).
				var mail = '';
				// A member baixa covers the whole family unit: list the deregistered addresses.
				if (r && Array.isArray(r.emails_baixa) && r.emails_baixa.length) { okMsg += ' Correos dados de baixa: ' + r.emails_baixa.join(', ') + '.'; }
				if (r && typeof r.correos_enviados === 'number' && r.correo_enviado === true) { mail = ' Enviouse o correo a ' + r.correos_enviados + ' persoa(s) da familia.'; }
				else if (r && r.correo_enviado === true) { mail = ' Enviouse o correo á familia.'; }
				else if (r && r.correo_enviado === false) { mail = ' ATENCIÓN: non se puido enviar o correo á familia' + (r && typeof r.correos_enviados === 'number' ? ' (enviados: ' + r.correos_enviados + ')' : '') + '; avísaa por outro medio.'; }
				showMessage(okMsg + mail, r && r.correo_enviado === false ? 'warning' : 'success');
				loadBaixas();
			}).catch(function (e) { showMessage(e.message, 'error'); loadBaixas(); });
		}

		// ── Socios ──
		var h3s = document.createElement('h3');
		h3s.textContent = 'Baixas de socios/as pendentes (' + socios.length + ')';
		root.appendChild(h3s);
		if (!socios.length) {
			root.appendChild(emptyEl('Non hai solicitudes de baixa de socios/as.'));
		} else {
			var ts = simpleTable(['Data da solicitude', 'Email', 'Nome', 'Apelidos', 'Teléfono', 'Fillos/as activos', 'Matrículas vixentes', 'Accións']);
			socios.forEach(function (s) {
				var tr = document.createElement('tr');
				tr.className = 'anpa-row-baixa-pending';
				tr.appendChild(cell(formatAdminDate(s.baixa_solicitada_en)));
				tr.appendChild(cell(s.email)); tr.appendChild(cell(s.nome)); tr.appendChild(cell(s.apelidos)); tr.appendChild(cell(s.telefono));
				tr.appendChild(cell(s.fillos_activos)); tr.appendChild(cell(s.matriculas_vixentes));
				var path = 'socio/' + encodeURIComponent(s.email || '') + '/baixa/';
				tr.appendChild(actionCell(function () {
					if (!window.confirm('Confirmar a baixa de ' + (s.email || '') + '? Danse de baixa TODOS os membros da unidade familiar (proxenitor/a principal e secundario/a): pasan a estado «baixa», perden o acceso á área e cada un recibe un correo coa lista dos enderezos dados de baixa.')) { return; }
					act(path + 'confirm', 'Baixa de socio/a confirmada.');
				}, function () {
					if (!window.confirm('Rexeitar a solicitude de ' + (s.email || '') + '? Segue sendo socio/a activo/a.')) { return; }
					act(path + 'reject', 'Solicitude rexeitada; o socio/a segue activo/a.');
				}, 'Confirmar baixa'));
				ts._tbody.appendChild(tr);
			});
			root.appendChild(ts);
		}

	}

	// ── Section: Lista Gmail (1.58.0) ────────────────────────────────
	// Manual three-click procedure: download the Google Contacts CSV, open Google
	// Contacts, import. The server remembers the last export so the panel can say
	// how many members joined/left since then and whether a new import is needed.
	function loadListaGmail() {
		showLoading();
		anpaAdminFetch('contactos-google/estado').then(function (data) { renderListaGmail(data); }).catch(sectionError);
	}

	function renderListaGmail(data) {
		root.textContent = '';
		document.title = 'Lista Gmail — Xestión ANPA';
		var d = data || {};
		var etiqueta = d.etiqueta || 'Socios Web ANPA';
		var altas = Array.isArray(d.altas) ? d.altas : [];
		var baixas = Array.isArray(d.baixas) ? d.baixas : [];
		var ultima = d.ultima_exportacion || null;
		var pendentes = parseInt(d.baixas_sen_confirmar, 10) || 0;

		function el(tag, text, cls) {
			var e = document.createElement(tag);
			if (text != null) { e.textContent = text; }
			if (cls) { e.className = cls; }
			return e;
		}
		function fmtDateTime(v) {
			var s = String(v || '');
			return s ? formatAdminDate(s.slice(0, 10)) + (s.length > 16 ? ' ' + s.slice(11, 16) : '') : '';
		}

		root.appendChild(el('p', 'Etiqueta de Google Contactos «' + etiqueta + '»: os correos de todos os socios/as activos, é dicir, os dous proxenitores de cada familia cando os dous teñen correo (cada un coa súa alta), para que ambos reciban os avisos da ANPA; a cota cóbrase unha soa vez por familia. A web non pode escribir na conta de Google; o proceso é manual e leva tres pulsacións.', 'description'));

		// ── Estado ──
		var card = el('div', null, 'anpa-mgmt-form');
		card.style.maxWidth = '760px';
		card.appendChild(el('h3', 'Estado'));
		var ul = document.createElement('ul');
		// 1.65.0: the list carries BOTH parents of each family (each one is a socio/a with an email),
		// but the fee is charged once per family, so show both figures.
		ul.appendChild(el('li', 'Familias socias activas: ' + (d.total_familias || 0) + ' (unha cota por familia)'));
		ul.appendChild(el('li', 'Correos na lista: ' + (d.total_actuais || 0) + ' (os dous proxenitores de cada familia, para que ambos reciban os avisos)'));
		if (ultima) {
			// 1.61.0: a "novas" export only added members to the label; the total is what Google holds.
			ul.appendChild(el('li', 'Última exportación: ' + fmtDateTime(ultima.exportado_en) + (ultima.por ? ' por ' + ultima.por : '') + (ultima.tipo === 'novas' ? ' · só altas novas (' + (ultima.exportados || 0) + ' correos engadidos; ' + (ultima.total || 0) + ' na etiqueta)' : ' (' + (ultima.total || 0) + ' correos)')));
			ul.appendChild(el('li', 'Altas novas desde entón: ' + altas.length));
			ul.appendChild(el('li', 'Baixas desde entón: ' + baixas.length));
		} else {
			ul.appendChild(el('li', 'Aínda non se exportou nunca esta lista.'));
		}
		card.appendChild(ul);
		var status = el('p', null);
		status.style.fontWeight = '600';
		if (d.precisa_exportar) {
			status.textContent = 'Fai falta exportar de novo e importar o CSV en Google Contactos.';
			status.style.color = '#b32d2e';
		} else {
			status.textContent = 'A lista de Gmail está ao día coa última exportación.';
			status.style.color = '#1e7e34';
		}
		card.appendChild(status);
		if (pendentes > 0) {
			var warn = el('p', 'Atención: hai ' + pendentes + ' solicitude(s) de baixa de socio/a sen confirmar en Xestión → Socios → Baixas de socios. Mentres non se confirmen, eses correos seguen sendo socios activos e IRÁN na lista exportada. Confirma primeiro as baixas e exporta despois.');
			warn.style.color = '#8a6d00'; warn.style.fontWeight = '600';
			card.appendChild(warn);
		}
		if (!d.conta_google) {
			card.appendChild(el('p', 'Consello: en Axustes → Xeral → Configuración podes indicar o correo da conta de Google da xunta; así o botón «Abrir Google Contactos» abre directamente esa conta cando hai varias sesións iniciadas.', 'description'));
		}

		// ── Confirmación da descarga (1.66.0) ──
		// The browser cannot tell whether it really saved the file (corporate policies block
		// downloads silently), so the server keeps the export «pendente» until the junta answers.
		var confirmBox = el('div', null, 'anpa-mgmt-form-actions');
		confirmBox.hidden = true;
		confirmBox.style.marginTop = '0.75rem'; confirmBox.style.padding = '0.75rem 1rem';
		confirmBox.style.border = '1px solid #dba617'; confirmBox.style.background = '#fcf9e8'; confirmBox.style.borderRadius = '4px';
		function pedirConfirmacion(texto) {
			confirmBox.textContent = '';
			confirmBox.hidden = false;
			var p = el('p', texto); p.style.fontWeight = '600'; p.style.margin = '0 0 0.5rem';
			confirmBox.appendChild(p);
			confirmBox.appendChild(el('p', 'Ata que confirmes, a web non anota esta exportación: as altas e baixas seguen como estaban.', 'description'));
			var row = el('div', null, 'anpa-mgmt-form-actions');
			row.style.display = 'flex'; row.style.gap = '0.5rem'; row.style.flexWrap = 'wrap';
			var si = el('button', 'Si, gardouse: anotar a exportación', 'anpa-mgmt-btn'); si.type = 'button';
			var non = el('button', 'Non se descargou', 'anpa-mgmt-btn anpa-mgmt-btn-secondary'); non.type = 'button';
			si.addEventListener('click', function () {
				si.disabled = true; non.disabled = true;
				anpaAdminFetch('contactos-google/exportacion/confirmar', { method: 'POST' }).then(function (r) {
					showMessage(r && r.tipo === 'novas'
						? 'Exportación anotada. Importa o CSV en Google Contactos SEN eliminar a etiqueta (salta o paso 4): os correos novos súmanse á etiqueta «' + etiqueta + '».'
						: 'Exportación anotada. Agora importa o CSV en Google Contactos seguindo os pasos de abaixo.', 'success');
					loadListaGmail();
				}).catch(function (e) { si.disabled = false; non.disabled = false; showMessage(e.message, 'error'); });
			});
			non.addEventListener('click', function () {
				si.disabled = true; non.disabled = true;
				anpaAdminFetch('contactos-google/exportacion/descartar', { method: 'POST' }).then(function () {
					showMessage('Non se anotou nada. Se o navegador non garda o ficheiro, proba noutro navegador ou equipo: algunhas empresas bloquean as descargas.', 'warning');
					loadListaGmail();
				}).catch(function (e) { si.disabled = false; non.disabled = false; showMessage(e.message, 'error'); });
			});
			row.appendChild(si); row.appendChild(non);
			confirmBox.appendChild(row);
			confirmBox.scrollIntoView({ block: 'nearest' });
		}

		// ── Botóns ──
		var acts = el('div', null, 'anpa-mgmt-form-actions');
		acts.style.display = 'flex'; acts.style.gap = '0.5rem'; acts.style.flexWrap = 'wrap';
		var dl = el('button', 'Descargar CSV para Google Contactos', 'anpa-mgmt-btn');
		dl.type = 'button';
		dl.addEventListener('click', function () {
			dl.disabled = true; dlNovas.disabled = true;
			var nome = 'socios-web-anpa-google-' + new Date().toISOString().slice(0, 10) + '.csv';
			anpaAdminFetch('contactos-google/export').then(function (blob) {
				if (blob instanceof Blob) { downloadBlob(blob, nome); }
				// 1.66.0: nothing is recorded yet — ask whether the browser really saved the file.
				pedirConfirmacion('Gardou o navegador o ficheiro ' + nome + '? (Mira a lista de descargas.)');
			}).catch(function (e) { dl.disabled = false; dlNovas.disabled = !ultima || !altas.length; showMessage(e.message, 'error'); });
		});
		// 1.61.0: only the members that joined since the last export, to add them to
		// the existing label without deleting it (the baixas stay in Google until the
		// full procedure is run; the panel keeps listing them).
		var dlNovas = el('button', 'Descargar só as altas novas (' + altas.length + ') (CSV)', 'anpa-mgmt-btn anpa-mgmt-btn-secondary');
		dlNovas.type = 'button';
		dlNovas.disabled = !ultima || !altas.length;
		dlNovas.title = !ultima ? 'Primeiro fai unha exportación completa.' : (!altas.length ? 'Non hai altas novas desde a última exportación.' : 'Só os ' + altas.length + ' correos novos; impórtaos SEN eliminar a etiqueta.');
		dlNovas.addEventListener('click', function () {
			dl.disabled = true; dlNovas.disabled = true;
			var nome = 'socios-web-anpa-google-novas-' + new Date().toISOString().slice(0, 10) + '.csv';
			anpaAdminFetch('contactos-google/export?ambito=novas').then(function (blob) {
				if (blob instanceof Blob) { downloadBlob(blob, nome); }
				pedirConfirmacion('Gardou o navegador o ficheiro ' + nome + ' (só as altas novas)? (Mira a lista de descargas.)');
			}).catch(function (e) { dl.disabled = false; dlNovas.disabled = false; showMessage(e.message, 'error'); });
		});
		var open = el('button', 'Abrir Google Contactos', 'anpa-mgmt-btn anpa-mgmt-btn-secondary');
		open.type = 'button';
		open.addEventListener('click', function () { window.open(d.google_url || 'https://contacts.google.com/', '_blank', 'noopener'); });
		acts.appendChild(dl); acts.appendChild(dlNovas); acts.appendChild(open);
		card.appendChild(acts);
		card.appendChild(el('p', '«Descargar CSV para Google Contactos» leva todos os socios/as activos (para refacer a etiqueta enteira, quitando as baixas). «Descargar só as altas novas» leva só os correos dados de alta desde a última exportación, para engadilos á etiqueta que xa existe sen tocar o resto; as baixas seguen en Google ata facer o proceso completo.', 'description'));
		card.appendChild(confirmBox);
		root.appendChild(card);
		if (d.exportacion_pendente && d.exportacion_pendente.exportado_en) {
			// 1.66.0: a download from an earlier visit that was never confirmed nor discarded.
			var pend = d.exportacion_pendente;
			dl.disabled = true; dlNovas.disabled = true;
			pedirConfirmacion('Hai unha descarga sen confirmar do ' + fmtDateTime(pend.exportado_en) + (pend.tipo === 'novas' ? ' (só altas novas, ' : ' (completa, ') + (pend.exportados || 0) + ' correos). Gardouse ese ficheiro?');
		}

		// ── Instrucións ──
		var steps = el('div', null, 'anpa-mgmt-form');
		steps.style.maxWidth = '760px';
		steps.appendChild(el('h3', 'Pasos para actualizar a lista en Gmail'));
		var ol = document.createElement('ol');
		[
			'Confirma primeiro as baixas pendentes en Xestión → Socios → Baixas de socios. Se unha baixa non se confirma, esa persoa segue sendo socio/a activo/a e o seu correo NON sae da lista.',
			'Pulsa «Descargar CSV para Google Contactos», comproba que o navegador gardou o ficheiro socios-web-anpa-google-<data>.csv e responde «Si, gardouse»: só entón a web anota esta exportación para comparar coa seguinte. Se non se descargou, responde «Non se descargou» e non cambia nada.',
			'Pulsa «Abrir Google Contactos». Ten a sesión iniciada coa conta de Google da xunta' + (d.conta_google ? ' (' + d.conta_google + ')' : '') + '.',
			'En Google Contactos, no menú da esquerda, abre a etiqueta «' + etiqueta + '», pulsa o menú de tres puntos → «Eliminar etiqueta» → «Eliminar todos os contactos e a etiqueta». Así desaparecen as baixas. Se a etiqueta aínda non existe, salta este paso.',
			'Pulsa «Importar» → «Seleccionar ficheiro» → escolle o CSV descargado → «Importar». A etiqueta «' + etiqueta + '» créase de novo cos socios/as activos. Google engade ademais unha etiqueta «Importado o …» que podes eliminar (só a etiqueta, mantendo os contactos).'
		].forEach(function (t) { ol.appendChild(el('li', t)); });
		steps.appendChild(ol);
		steps.appendChild(el('p', 'Só altas novas (1.61.0): se desde a última exportación só houbo altas (ou as baixas poden esperar), pulsa «Descargar só as altas novas», salta o paso 4 (NON elimines a etiqueta) e importa o CSV: os correos novos engádense á etiqueta «' + etiqueta + '» que xa existe. Para quitar as baixas fai falta o proceso completo.', 'description'));
		steps.appendChild(el('p', 'Aviso: o paso 4 borra da conta de Google os contactos que estaban nesa etiqueta. Se a xunta gardou nesa mesma conta outros datos deses contactos, escolle «Manter os contactos e eliminar a etiqueta» e quita a man da lista os correos que aparecen abaixo en «Baixas».', 'description'));
		steps.appendChild(el('p', 'Gmail limita os envíos a 500 destinatarios ao día e marca como sospeitosos os correos con moitos destinatarios: usa a etiqueta en CCO e, para avisos a toda a asociación, a cola de envíos da web.', 'description'));
		root.appendChild(steps);

		// ── Diferenzas ──
		function diffTable(title, rows, empty) {
			root.appendChild(el('h3', title + ' (' + rows.length + ')'));
			if (!rows.length) { root.appendChild(emptyEl(empty)); return; }
			var table = document.createElement('table');
			table.className = 'anpa-mgmt-table';
			var thead = document.createElement('thead'); var hr = document.createElement('tr');
			['Apelidos', 'Nome', 'Email'].forEach(function (l) { hr.appendChild(el('th', l)); });
			thead.appendChild(hr); table.appendChild(thead);
			var tbody = document.createElement('tbody');
			rows.forEach(function (r) {
				var tr = document.createElement('tr');
				tr.appendChild(el('td', r.apelidos)); tr.appendChild(el('td', r.nome)); tr.appendChild(el('td', r.email));
				tbody.appendChild(tr);
			});
			table.appendChild(tbody);
			root.appendChild(table);
		}
		if (ultima) {
			diffTable('Altas desde a última exportación', altas, 'Ningunha alta nova.');
			diffTable('Baixas desde a última exportación', baixas, 'Ningunha baixa.');
		}

		// ── Correo de inicio de curso (1.62.0) ──
		// ONE email to the junta's inbox (template «inicio_curso»), forwarded from
		// Gmail to the label: WordPress never mails hundreds of families at once.
		var ic = el('div', null, 'anpa-mgmt-form');
		ic.style.maxWidth = '760px'; ic.style.marginTop = '1.5rem';
		ic.appendChild(el('h3', 'Correo de inicio de curso'));
		ic.appendChild(el('p', 'Correo para todas as familias co que fai a web: iniciar sesión como socio/a (código ao correo, sen contrasinal), darse de alta, modificar os datos e inscribirse nas actividades extraescolares. Para non saturar o WordPress envíase UNHA soa vez á conta da xunta' + (d.conta_xunta ? ' (' + d.conta_xunta + ')' : '') + ' e dende Gmail reenvíase á etiqueta «' + etiqueta + '» (en CCO). O texto edítase na plantilla «inicio_curso» de Axustes → Plantillas de email.', 'description'));
		var icActs = el('div', null, 'anpa-mgmt-form-actions');
		icActs.style.display = 'flex'; icActs.style.gap = '0.5rem'; icActs.style.flexWrap = 'wrap';
		// 1.63.0: the email links to the instructions post and the activities page from Axustes → Xeral.
		if (!d.instrucions_url) {
			var icWarn = el('p', 'Atención: aínda non está configurada a URL da entrada coas instrucións (Axustes → Xeral → «Entrada coas instrucións para as familias»); mentres tanto o correo liga á área de socios. Publica primeiro a entrada e garda a súa URL.');
			icWarn.style.color = '#8a6d00'; icWarn.style.fontWeight = '600';
			ic.appendChild(icWarn);
		} else {
			ic.appendChild(el('p', 'Ligazóns do correo: instrucións → ' + d.instrucions_url + (d.extraescolares_url ? ' · extraescolares → ' + d.extraescolares_url : ' · extraescolares → páxina co shortcode [anpa_extraescolares_ofertadas]'), 'description'));
		}
		var icSend = el('button', 'Enviar o correo de inicio de curso á conta da xunta', 'anpa-mgmt-btn');
		icSend.type = 'button';
		icSend.addEventListener('click', function () {
			if (!window.confirm('Enviar o correo de inicio de curso a ' + (d.conta_xunta || 'a conta da xunta') + '? Despois reenvíao dende Gmail á etiqueta «' + etiqueta + '».')) { return; }
			icSend.disabled = true;
			anpaAdminFetch('contactos-google/inicio-curso', { method: 'POST' }).then(function (r) {
				icSend.disabled = false;
				showMessage('Correo de inicio de curso enviado a ' + ((r && r.destinatario) || d.conta_xunta || 'a conta da xunta') + '. Ábreo en Gmail e reenvíao á etiqueta «' + etiqueta + '» en CCO.', 'success');
			}).catch(function (e) { icSend.disabled = false; showMessage(e.message || 'Non se puido enviar o correo.', 'error'); });
		});
		var icEdit = el('a', 'Editar a plantilla «inicio_curso»', 'anpa-mgmt-btn anpa-mgmt-btn-secondary');
		icEdit.href = 'admin.php?page=anpa-socios-templates&edit=inicio_curso';
		icActs.appendChild(icSend); icActs.appendChild(icEdit);
		ic.appendChild(icActs);
		root.appendChild(ic);
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
			// Multilevel header: FILLOS_COLS is 3 proxenitor columns + 6 fillo
			// columns; the trailing group spans the actions column.
			prependGroupedHeader(table, [
				{ label: 'Datos do proxenitor', span: 3 },
				{ label: 'Datos do/a fillo/a', span: 6 },
				{ label: '', span: 1 }
			]);
			// Enables the proxenitor-column tint (see admin-management.css) so the
			// first three columns read as a distinct block from the fillo columns.
			table.classList.add('anpa-fillos-table');

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
	// CSV export keeps the raw DB estado (canonical contract). The table shows
	// (and sorts/searches by) the effective-state label via _estado_efectivo.
	var ACTIV_COLS = ['_empresa_nome', 'nome', 'custo', 'estado'];
	var ACTIV_DISPLAY_COLS = ['_empresa_nome', 'nome', 'custo', '_estado_efectivo'];
	var _cachedEmpresas = null;

	/**
	 * Effective presentation state of an activity for the active course. The DB
	 * estado is authoritative for 'inactivo'; an otherwise-active activity with
	 * no group in the active course is presented as "sen grupo" and treated as
	 * inactive by the show/hide filter. Never mutates the row (CSV stays canonical).
	 * @param {object} row
	 * @returns {{token:string,label:string}}
	 */
	function activityEffectiveState(row) {
		if (!row || row.estado === 'inactivo') { return { token: 'inactivo', label: 'Inactiva' }; }
		if (row.ten_grupo_curso_activo !== true) { return { token: 'sen_grupo', label: 'Sen grupo' }; }
		return { token: 'activo', label: 'Activa' };
	}

	function loadActividades() {
		showLoading();
		Promise.all([
			anpaAdminFetch('actividades'),
			anpaAdminFetch('empresas'),
		]).then(function (results) {
			var rows = Array.isArray(results[0]) ? results[0] : [];
			_cachedEmpresas = Array.isArray(results[1]) ? results[1] : [];
			renderActividades(rows, _cachedEmpresas);
		}).catch(function (e) {
			sectionError(e);
		});
	}


	function renderActividades(rows, empresas) {
		var allRows = Array.isArray(rows) ? rows : [];
		var empresaList = Array.isArray(empresas) ? empresas : [];
		var empresaNome = {};
		empresaList.forEach(function (e) { empresaNome[String(e.id)] = e.nome; });
		allRows.forEach(function (r) {
			r._empresa_nome = empresaNome[String(r.empresa_id)] || '';
			// Presentation-only field for display/sort/search; NOT in ACTIV_COLS
			// so the CSV export keeps the raw estado. Does not touch r.estado.
			r._estado_efectivo = activityEffectiveState(r).label;
		});

		var st = sectionState.actividades || (sectionState.actividades = { sort: { key: 'nome', dir: 'asc' }, page: 1, size: 10 });
		function render() {
			var currentSearch = st.searchQuery || '';
			root.textContent = '';
			var active = allRows.filter(function (r) {
				return activityEffectiveState(r).token === 'activo';
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
			var filtered = filterRows(visible, query, ACTIV_DISPLAY_COLS);
			var sorted = tbl.sortRows(filtered, st.sort.key, st.sort.dir);
			wireSearchInput(bar, st, render);
			if (!sorted.length) { root.appendChild(emptyEl('Sen actividades.')); return; }
			var paged = tbl.pageSlice(sorted, st.page, st.size || 0);
			var table = buildTable(paged, ACTIV_DISPLAY_COLS, st.sort, render, function (tr, row) {
				if (activityEffectiveState(row).token !== 'activo') { tr.classList.add('anpa-row-baixa'); }
			});

			// Add action buttons per row
			var tbodyRows = table.querySelectorAll('tbody tr');
			paged.forEach(function (row, i) {
				var tr = tbodyRows[i];
				var actionsTd = tr ? tr._actionsCell : null;
				if (!actionsTd) { return; }

				var editBtn = document.createElement('button');
				editBtn.type = 'button';
				editBtn.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary';
				editBtn.textContent = 'Editar';
				editBtn.addEventListener('click', function () { renderActividadForm(row, empresaList); });
				actionsTd.appendChild(editBtn);

				// The per-activity "Grupos" panel is reachable from the edit form
				// ("Xestionar grupos"); it was removed from this listing to reduce
				// the number of row buttons (fase28).

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
		var cursosSummary = document.createElement('p');
		cursosSummary.className = 'description';
		cursosSummary.textContent = isEdit && act.ten_grupo_curso_activo && activeCourse ? activeCourse : 'Sen grupos no curso activo';
		form.appendChild(cursosSummary);
		if (isEdit && Array.isArray(act.cursos_con_grupos) && act.cursos_con_grupos.length) {
			var historicalCourses = act.cursos_con_grupos.filter(function (yr) { return String(yr || '') && String(yr) !== activeCourse; });
			if (historicalCourses.length) {
				var history = document.createElement('details');
				history.className = 'anpa-history-disclosure';
				var historySummary = document.createElement('summary');
				historySummary.textContent = 'Cursos escolares anteriores e inactivos';
				history.appendChild(historySummary);
				var historyList = document.createElement('ul');
				historicalCourses.forEach(function (course) {
					var item = document.createElement('li');
					item.textContent = String(course);
					historyList.appendChild(item);
				});
				history.appendChild(historyList);
				form.appendChild(history);
			}
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
			var gp = document.createElement('p'); gp.textContent = 'Todos os grupos móstranse abaixo. Os do curso activo aparecen como filas principais; os de cursos anteriores e pechados móstranse dentro do despregable de cada grupo.'; gruposSection.appendChild(gp);
			var groupsList = document.createElement('div');
			gruposSection.appendChild(groupsList);
			// Groups are fully manageable inline above (list + "Novo grupo" +
			// edit/create per row), so the redundant "Xestionar grupos" button
			// that only reopened the same list in a separate panel was removed.
			renderGroupSeriesList(groupsList, act, { scope: 'activity-inline' });
			form.appendChild(gruposSection);
		}

		var actions = document.createElement('div'); actions.className = 'anpa-mgmt-form-actions';
		var saveBtn = document.createElement('button'); saveBtn.type = 'button'; saveBtn.className = 'anpa-mgmt-btn';
		saveBtn.textContent = isEdit ? 'Gardar cambios' : 'Crear actividade';
		saveBtn.addEventListener('click', function () {
			var payload = {
				empresa_id: parseInt(empresaSelect.value, 10) || 0,
				nome: nomeInput.value.trim(), icono: getSelectedIcono(), descripcion: descInput.value.trim(),
				custo: custoInput.value.trim(), estado: estadoSelect.value
			};
			if (!payload.empresa_id || !payload.nome || !payload.descripcion) {
				showMessage('Empresa, nome e descrición son obrigatorios.', 'error'); return;
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

	// Group states (1.50.0). Kept in sync with ANPA_Socios_Grupo_Serie::ESTADOS.
	var GRUPO_ESTADOS = [
		['aberto', 'Aberto', 'Visible na páxina pública e no horario, ofertado na área; matrícula activa mentres haxa praza.'],
		['pechado', 'Pechado', 'Oculto da oferta pública e da área; as matrículas existentes seguen e unha nova iría a lista de espera.'],
		['deshabilitado', 'Deshabilitado', 'Oculto e sen matrículas; só se pode escoller cando o grupo non ten ningunha matrícula vixente. Consérvase para o histórico e para reutilizalo.']
	];
	function grupoEstadoLabel(v) {
		for (var i = 0; i < GRUPO_ESTADOS.length; i++) { if (GRUPO_ESTADOS[i][0] === v) { return GRUPO_ESTADOS[i][1]; } }
		return v || '';
	}
	function grupoEstadoRowClass(grupo) {
		if (!grupo || !grupo.ten_grupo_actual) { return 'anpa-row-baixa'; }
		var known = GRUPO_ESTADOS.some(function (e) { return e[0] === grupo.estado; });
		return 'anpa-row-grupo-' + (known ? grupo.estado : 'pechado');
	}
	function grupoEstadoLegend() {
		var legend = document.createElement('p');
		legend.className = 'anpa-grupos-legend description';
		GRUPO_ESTADOS.forEach(function (e) {
			var item = document.createElement('span');
			item.className = 'anpa-grupo-estado anpa-grupo-estado-' + e[0];
			item.title = e[2];
			item.textContent = e[1];
			legend.appendChild(item);
			legend.appendChild(document.createTextNode(' '));
		});
		legend.appendChild(document.createTextNode('— «Eliminar» só aparece nos grupos do curso activo sen ningunha matrícula nin histórico.'));
		return legend;
	}

	function renderGroupSeriesList(container, actividad, opts) {
		opts = opts || {};
		var scope = String(opts.scope || 'panel');
		var stateKey = 'activity-groups-' + String(actividad && actividad.id ? actividad.id : '') + '-' + scope;
		var st = sectionState[stateKey] || (sectionState[stateKey] = {});
		var rows = [];
		var loaded = false;
		container.textContent = '';
		var toolbar = document.createElement('div'); toolbar.className = 'anpa-grupos-horarios-selector anpa-grupos-activity-toolbar';
		var newBtn = document.createElement('button');
		newBtn.type = 'button';
		newBtn.className = 'anpa-mgmt-btn';
		newBtn.textContent = opts.newLabel || 'Novo grupo';
		newBtn.addEventListener('click', function () { renderGrupoForm(null, actividad, activeCourse, true); });
		toolbar.appendChild(newBtn);
		var info = document.createElement('p');
		info.className = 'anpa-grupos-horarios-status';
		info.textContent = activeCourse ? ('Curso escolar actual: ' + activeCourse) : 'Non hai un curso activo.';
		var list = document.createElement('div');
		list.className = 'anpa-grupos-activity-list';
		container.appendChild(toolbar);
		container.appendChild(info);
		container.appendChild(list);

		function historyDetails(grupo) {
			var history = document.createElement('details');
			history.className = 'anpa-history-disclosure';
			var summary = document.createElement('summary');
			summary.textContent = 'Cursos escolares anteriores e inactivos';
			history.appendChild(summary);
			var ul = document.createElement('ul');
			var groupCursos = Array.isArray(grupo.cursos_anteriores) ? grupo.cursos_anteriores : [];
			groupCursos.forEach(function (prev) {
				var li = document.createElement('li');
				var parts = [];
				parts.push(String(prev.curso_escolar || ''));
				if (prev.nome) { parts.push(prev.nome); }
				if (prev.horario_label || prev.horario) { parts.push(prev.horario_label || prev.horario); }
				if (prev.franxa) { parts.push(prev.franxa); }
				if (prev.dias) { parts.push(prev.dias); }
				if (Array.isArray(prev.nivel_ids) && prev.nivel_ids.length) { parts.push('Niveis ' + prev.nivel_ids.join(', ')); }
				if (prev.min_pupilos != null || prev.max_pupilos != null) { parts.push((prev.min_pupilos != null ? prev.min_pupilos : '') + '-' + (prev.max_pupilos != null ? prev.max_pupilos : '')); }
				if (prev.estado) { parts.push(grupoEstadoLabel(prev.estado)); }
				li.textContent = parts.filter(Boolean).join(' · ');
				ul.appendChild(li);
			});
			history.appendChild(ul);
			return history;
		}

		function renderRows() {
			list.textContent = '';
			var visible = rows;
			if (!visible.length) {
				list.appendChild(emptyEl('Sen grupos para esta actividade.'));
				return;
			}
			list.appendChild(grupoEstadoLegend());
			var table = document.createElement('table');
			table.className = 'anpa-mgmt-table anpa-mgmt-activity-groups-table';
			var thead = document.createElement('thead');
			var headRow = document.createElement('tr');
			['Grupo', 'Horario', 'Franxa', 'Días', 'Niveis', 'Min', 'Max', 'Estado', ''].forEach(function (label) {
				var th = document.createElement('th'); th.textContent = label; headRow.appendChild(th);
			});
			thead.appendChild(headRow); table.appendChild(thead);
			var tbody = document.createElement('tbody');
			visible.forEach(function (grupo) {
				var tr = document.createElement('tr');
				tr.className = grupoEstadoRowClass(grupo);
				[
					grupo.ten_grupo_actual ? (grupo.nome || 'Grupo') : 'Sen grupo actual',
					grupo.horario_label || (grupo.horario === 'maña' ? 'Mañá' : grupo.horario === 'manha' ? 'Comedor' : 'Tarde'),
					grupo.franxa,
					grupo.dias,
					Array.isArray(grupo.nivel_ids) ? grupo.nivel_ids.join(', ') : '',
					grupo.min_pupilos,
					grupo.max_pupilos,
					grupo.ten_grupo_actual ? grupoEstadoLabel(grupo.estado) : ''
				].forEach(function (v, idx) {
					var td = document.createElement('td'); td.textContent = v != null ? String(v) : '';
					if (idx === 7 && grupo.ten_grupo_actual) {
						td.textContent = '';
						var badge = document.createElement('span');
						badge.className = 'anpa-grupo-estado anpa-grupo-estado-' + (grupo.estado || 'pechado');
						badge.textContent = grupoEstadoLabel(grupo.estado);
						var vix = Number(grupo.matriculas_vixentes || 0);
						badge.title = vix + ' matrícula(s) vixente(s) · ' + Number(grupo.matriculas_total || 0) + ' en total';
						td.appendChild(badge);
					}
					tr.appendChild(td);
				});
				var tdActions = document.createElement('td'); tdActions.className = 'anpa-mgmt-actions';
				var edit = document.createElement('button'); edit.type = 'button'; edit.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary'; edit.textContent = grupo.ten_grupo_actual ? 'Editar' : 'Crear grupo actual';
				edit.addEventListener('click', function () {
					if (grupo.ten_grupo_actual) {
						openGroupEditor(actividad.id, grupo.id, grupo.serie_uid);
					} else {
						renderGrupoForm(null, actividad, activeCourse, true);
					}
				});
				tdActions.appendChild(edit);
				// E6 (1.50.0): the server already refuses to delete groups with history; the
				// button only shows when the count says there is nothing to lose.
				if (grupo.ten_grupo_actual && grupo.curso_escolar === activeCourse && Number(grupo.matriculas_total || 0) === 0) {
					var del = document.createElement('button'); del.type = 'button'; del.className = 'anpa-mgmt-btn anpa-mgmt-btn-danger'; del.textContent = 'Eliminar';
					del.addEventListener('click', function () {
						if (!window.confirm('Eliminar o grupo «' + (grupo.nome || '') + '» do curso ' + activeCourse + '? Non ten matrículas nin histórico. Esta acción non se pode desfacer; se prefires conservalo, pásao a «Deshabilitado».')) { return; }
						del.disabled = true;
						anpaAdminFetch('grupo/' + grupo.id, { method: 'DELETE' }).then(function () {
							showMessage('Grupo eliminado.', 'success');
							renderGroupSeriesList(container, actividad, opts);
						}).catch(function (e) { del.disabled = false; showMessage(e.message, 'error'); });
					});
					tdActions.appendChild(del);
				}
				tr.appendChild(tdActions);
				tbody.appendChild(tr);
				if (Array.isArray(grupo.cursos_anteriores) && grupo.cursos_anteriores.length) {
					var historyRow = document.createElement('tr');
					historyRow.className = 'anpa-history-row';
					var historyCell = document.createElement('td');
					historyCell.colSpan = 9;
					historyCell.appendChild(historyDetails(grupo));
					historyRow.appendChild(historyCell);
					tbody.appendChild(historyRow);
				}
			});
			table.appendChild(tbody);
			list.appendChild(table);
		}

		anpaAdminFetch('actividad/' + actividad.id + '/grupos').then(function (result) {
			rows = Array.isArray(result) ? result : [];
			loaded = true;
			renderRows();
		}).catch(function (e) {
			list.appendChild(emptyEl('Erro ao cargar grupos: ' + e.message));
		});
	}

	/**
	 * Renders a management panel for the grupos of a given actividade.
	 * Displays list, create form, edit, set estado, and mover matrícula.
	 * @param {object} actividad - The parent actividad row.
	 */
	function renderGruposPanel(actividad) {
		root.textContent = '';
		var panel = document.createElement('div'); panel.className = 'anpa-mgmt-form';
		var h3 = document.createElement('h3'); h3.textContent = 'Grupos de: ' + (actividad.nome || ''); panel.appendChild(h3);
		var listContainer = document.createElement('div'); panel.appendChild(listContainer);
		var actions = document.createElement('div'); actions.className = 'anpa-mgmt-form-actions';
		var backBtn = document.createElement('button'); backBtn.type = 'button'; backBtn.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary'; backBtn.textContent = 'Volver a actividades';
		backBtn.addEventListener('click', loadActividades); actions.appendChild(backBtn); panel.appendChild(actions); root.appendChild(panel);
		renderGroupSeriesList(listContainer, actividad, { scope: 'panel', newLabel: 'Novo grupo actual' });
	}

	function validateGrupoFormPayload(payload, levelControl) {
		if (!payload.nome) { return 'Escribe o nome do grupo.'; }
		if (!activeCourse) { return 'Non hai un curso activo.'; }
		if (!levelControl || levelControl.loading) { return 'Agarda a que remate a carga dos niveis do curso activo.'; }
		if (levelControl.failed) { return 'Non foi posible cargar os niveis do curso activo. Téntao de novo.'; }
		if (!Array.isArray(payload.nivel_ids) || !payload.nivel_ids.length) { return 'Selecciona polo menos un nivel.'; }
		if (['maña', 'manha', 'tarde'].indexOf(payload.horario) === -1) { return 'Selecciona Mañá, Comedor ou Tarde.'; }
		if (!/^\d{2}:\d{2}-\d{2}:\d{2}$/.test(payload.franxa)) { return 'Completa a hora de inicio e fin.'; }
		var times = payload.franxa.split('-');
		if (times[0] >= times[1]) { return 'A hora de fin debe ser posterior á de inicio.'; }
		if (!Array.isArray(payload.dias) || !payload.dias.length) { return 'Selecciona polo menos un día.'; }
		if (!Number.isInteger(payload.min_pupilos) || payload.min_pupilos < 1) { return 'Indica un mínimo válido de alumnos/as.'; }
		if (!Number.isInteger(payload.max_pupilos) || payload.max_pupilos < payload.min_pupilos) { return 'O máximo debe ser igual ou superior ao mínimo.'; }
		return '';
	}

	function renderGrupoForm(grupo, actividad, preferredCourse, returnToGrid) {
		root.textContent = '';
		var isEdit = grupo !== null; var form = document.createElement('div'); form.className = 'anpa-mgmt-form';
		var h3 = document.createElement('h3'); h3.textContent = (isEdit ? 'Editar grupo' : 'Novo grupo') + ' — ' + (actividad.nome || ''); form.appendChild(h3);
		function addField(id, label, input) { var l = document.createElement('label'); l.htmlFor = id; l.textContent = label; input.id = id; form.appendChild(l); form.appendChild(input); }

		var nome = document.createElement('input'); nome.type = 'text'; nome.value = isEdit ? (grupo.nome || '') : ''; addField('anpa-grupo-nome', 'Nome do grupo', nome);
		var courseLabel = document.createElement('label'); courseLabel.textContent = 'Curso escolar actual'; form.appendChild(courseLabel);
		var courseValue = document.createElement('p'); courseValue.className = 'description'; courseValue.textContent = activeCourse || 'Non hai un curso activo.'; form.appendChild(courseValue);
		var activeLevelsWrap = document.createElement('div'); activeLevelsWrap.className = 'anpa-mgmt-niveis-row anpa-mgmt-active-levels';
		activeLevelsWrap.textContent = 'Cargando…';
		form.appendChild(activeLevelsWrap);
		var selectedNivelIds = isEdit && Array.isArray(grupo.nivel_ids) ? grupo.nivel_ids.map(String) : [];
		var levelControl = { loading: true, failed: false, body: activeLevelsWrap };
		var pendingLevelLoads = activeCourse ? 1 : 0;
		var save = null;
		function updateSaveState() { if (save) { save.disabled = pendingLevelLoads > 0; } }
		if (!activeCourse) {
			activeLevelsWrap.textContent = 'Non hai un curso activo. Actívao primeiro en Axustes → Cursos.';
			levelControl.loading = false;
			levelControl.failed = true;
		} else {
			anpaAdminFetch('estrutura?curso_escolar=' + encodeURIComponent(activeCourse)).then(function (resp) {
				activeLevelsWrap.textContent = '';
				var levels = resp && Array.isArray(resp.niveis) ? resp.niveis : [];
				levels.forEach(function (n) {
					var label = document.createElement('label');
					var chk = document.createElement('input');
					chk.type = 'checkbox';
					chk.value = String(n.id);
					chk.checked = selectedNivelIds.indexOf(String(n.id)) !== -1;
					label.appendChild(chk);
					label.appendChild(document.createTextNode(' ' + (n.etiqueta || n.codigo)));
					activeLevelsWrap.appendChild(label);
				});
				if (!levels.length) { activeLevelsWrap.appendChild(emptyEl('Este curso non ten niveis dados de alta.')); }
				levelControl.loading = false;
				pendingLevelLoads = 0;
				updateSaveState();
			}).catch(function () {
				activeLevelsWrap.textContent = 'Non foi posible cargar os niveis.';
				levelControl.loading = false;
				levelControl.failed = true;
				pendingLevelLoads = 0;
				updateSaveState();
			});
		}

		if (isEdit && Array.isArray(grupo.cursos_anteriores) && grupo.cursos_anteriores.length) {
			var history = document.createElement('details');
			history.className = 'anpa-history-disclosure';
			var historySummary = document.createElement('summary');
			historySummary.textContent = 'Cursos escolares anteriores e inactivos';
			history.appendChild(historySummary);
			var historyList = document.createElement('ul');
			grupo.cursos_anteriores.forEach(function (prev) {
				var li = document.createElement('li');
				var bits = [];
				if (prev.curso_escolar) { bits.push(prev.curso_escolar); }
				if (prev.nome) { bits.push(prev.nome); }
				if (prev.horario_label || prev.horario) { bits.push(prev.horario_label || prev.horario); }
				if (prev.franxa) { bits.push(prev.franxa); }
				if (prev.dias) { bits.push(prev.dias); }
				if (Array.isArray(prev.nivel_ids) && prev.nivel_ids.length) { bits.push('Niveis ' + prev.nivel_ids.join(', ')); }
				if (prev.min_pupilos != null || prev.max_pupilos != null) { bits.push((prev.min_pupilos != null ? prev.min_pupilos : '') + '-' + (prev.max_pupilos != null ? prev.max_pupilos : '')); }
				if (prev.estado) { bits.push(grupoEstadoLabel(prev.estado)); }
				li.textContent = bits.join(' · ');
				historyList.appendChild(li);
			});
			history.appendChild(historyList);
			form.appendChild(history);
		}

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
		var vixentes = isEdit ? Number(grupo.matriculas_vixentes || 0) : 0;
		var state = document.createElement('select');
		GRUPO_ESTADOS.forEach(function (e) {
			var o = document.createElement('option'); o.value = e[0]; o.textContent = e[1] + ' — ' + e[2];
			o.selected = isEdit ? grupo.estado === e[0] : e[0] === 'aberto';
			if (e[0] === 'deshabilitado' && vixentes > 0 && grupo.estado !== 'deshabilitado') { o.disabled = true; o.textContent = e[1] + ' — non dispoñible: ' + vixentes + ' matrícula(s) vixente(s)'; }
			state.appendChild(o);
		});
		addField('anpa-grupo-estado', 'Estado', state);
		if (isEdit && vixentes > 0) {
			var stateHint = document.createElement('p'); stateHint.className = 'description';
			stateHint.textContent = 'Este grupo ten ' + vixentes + ' matrícula(s) vixente(s): pódese pechar, pero non deshabilitar ata que non quede ningunha.';
			form.appendChild(stateHint);
		}

		var actions = document.createElement('div'); actions.className = 'anpa-mgmt-form-actions'; save = document.createElement('button'); save.type = 'button'; save.className = 'anpa-mgmt-btn'; save.textContent = isEdit ? 'Gardar cambios' : 'Crear grupo'; save.disabled = pendingLevelLoads > 0;
		save.addEventListener('click', function () {
			if (save.disabled) { return; }
			var nivelIds = [];
			activeLevelsWrap.querySelectorAll('input:checked').forEach(function (c) { nivelIds.push(parseInt(c.value, 10)); });
			var selectedDays = [];
			days.querySelectorAll('input:checked').forEach(function (c) { selectedDays.push(c.value); });
			var checkedHorario = horario.querySelector('input:checked');
			var payload = { nome: nome.value.trim(), nivel_ids: nivelIds, horario: checkedHorario ? checkedHorario.value : '', franxa: start.value && end.value ? start.value + '-' + end.value : '', dias: selectedDays, min_pupilos: parseInt(min.value,10), max_pupilos: parseInt(max.value,10), estado: state.value };
			var validationError = validateGrupoFormPayload(payload, levelControl);
			if (validationError) { showMessage(validationError, 'error'); return; }
			save.disabled = true; save.textContent = 'Gardando…';
			anpaAdminFetch(isEdit ? 'grupo/' + grupo.id : 'actividad/' + actividad.id + '/grupos', { method: isEdit ? 'PATCH' : 'POST', body: payload }).then(function () { returnToGrid ? loadGruposHorarios() : renderGruposPanel(actividad); }).catch(function (e) { save.disabled = false; save.textContent = isEdit ? 'Gardar cambios' : 'Crear grupo'; showMessage(e.message, 'error'); });
		}); actions.appendChild(save);
		var cancel = document.createElement('button'); cancel.type = 'button'; cancel.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary'; cancel.textContent = 'Volver'; cancel.addEventListener('click', function () { returnToGrid ? loadGruposHorarios() : renderGruposPanel(actividad); }); actions.appendChild(cancel); form.appendChild(actions); root.appendChild(form);
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
				var sourceYear = grupo.curso_escolar || activeCourse;
				var otherGrupos = allGrupos.filter(function (g) {
					return g.id !== grupo.id && g.ten_grupo_actual && String(g.curso_escolar || '') === String(sourceYear || '');
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
							opt.value = String(g.id);
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

		// 1.68.1: three blocks in this order — (1) course cycle panel for the course
		// the server picks (active, else the most recent pending one), (2) pending
		// activity withdrawals, (3) the enrolment listing with its own course selector.
		var estadoHost = document.createElement('div');
		root.appendChild(estadoHost);
		renderEstadoCurso(estadoHost, '');

		var baixasHost = document.createElement('div');
		root.appendChild(baixasHost);
		renderBaixasActividades(baixasHost);

		var listado = document.createElement('div');
		listado.className = 'anpa-mgmt-form anpa-listado-matriculas';
		root.appendChild(listado);
		var h3 = document.createElement('h3');
		h3.textContent = 'Listado de matrículas';
		listado.appendChild(h3);

		var courseNotice = document.createElement('p');
		courseNotice.className = 'description';
		courseNotice.appendChild(document.createTextNode('Todas as matrículas do curso escollido, con busca e exportación. As datas do curso configúranse en '));
		var courseSettingsLink = document.createElement('a');
		courseSettingsLink.href = 'admin.php?page=anpa-socios-settings&tab=cursos';
		courseSettingsLink.textContent = 'Axustes → Cursos';
		courseNotice.appendChild(courseSettingsLink);
		courseNotice.appendChild(document.createTextNode('.'));
		listado.appendChild(courseNotice);

		// 1.60.0: trimester + enrolment window of the active course, coloured so
		// the reader knows whether the listing below can still change.
		var activeAviso = active && active.matriculas_aviso && typeof active.matriculas_aviso === 'object' ? active.matriculas_aviso : null;
		var avisoHost = document.createElement('div');
		listado.appendChild(avisoHost);
		function renderAviso(cursoSel) {
			avisoHost.textContent = '';
			var box = document.createElement('div');
			box.className = 'anpa-mgmt-aviso-matriculas';
			box.setAttribute('role', 'status');
			var strong = document.createElement('strong');
			var span = document.createElement('span');
			if (!active) {
				box.className += ' anpa-mgmt-aviso-matriculas--outro';
				strong.textContent = 'Sen curso activo';
				span.textContent = 'Matrículas PECHADAS: activa un curso en Axustes → Cursos.';
			} else if (cursoSel && cursoSel !== active.curso_escolar) {
				box.className += ' anpa-mgmt-aviso-matriculas--outro';
				strong.textContent = 'Curso ' + cursoSel + ' (non é o activo)';
				span.textContent = 'Este listado é doutro curso escolar e non varía. O curso activo é ' + active.curso_escolar + '.';
			} else {
				var abertas = activeAviso ? activeAviso.estado === 'abertas' : !!active.matriculas_abertas;
				box.className += abertas ? ' anpa-mgmt-aviso-matriculas--abertas' : ' anpa-mgmt-aviso-matriculas--pechadas';
				strong.textContent = 'Curso activo ' + active.curso_escolar + ' · ' + (activeAviso && activeAviso.titulo ? activeAviso.titulo : ('Matrículas ' + (abertas ? 'ABERTAS' : 'PECHADAS')));
				span.textContent = activeAviso && activeAviso.texto ? activeAviso.texto : '';
			}
			box.appendChild(strong);
			box.appendChild(span);
			avisoHost.appendChild(box);
		}
		renderAviso(current);
		// After a CSV download: warn only while enrolments are open (the file may go stale).
		function avisoDescarga() {
			if (activeAviso && activeAviso.estado === 'abertas') {
				var tri = parseInt(activeAviso.trimestre, 10) || 0;
				showMessage('CSV descargado. Aviso: as matrículas' + (tri ? ' do ' + tri + 'º trimestre' : '') + ' están abertas; este listado pode cambiar por altas e baixas.', 'warning');
			}
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
		listado.appendChild(matCursoDiv);

		var matHost = document.createElement('div');
		listado.appendChild(matHost);

		var MAT_COLS = ['fillo_apelidos', 'fillo_nome', 'actividade', 'curso_completo', 'estado', 'franxa', 'dias', 'trimestres', 'creado_en', 'posicion'];

		function loadMat(curso) {
			renderAviso(curso);
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
					addCsvExportBtn(bar, 'matriculas', matRows, MAT_COLS, avisoDescarga);
					addCsvImportBtn(bar, 'matriculas');
					var query = bar._searchInput.value || '';
					var filtered = filterRows(matRows, query, MAT_COLS);
					var sorted = tbl.sortRows(filtered, matSt.sort.key, matSt.sort.dir);
					wireSearchInput(bar, matSt, renderMat);
					if (!sorted.length) { matHost.appendChild(emptyEl('Sen matr\u00EDculas.')); return; }
					var paged = tbl.pageSlice(sorted, matSt.page, matSt.size || 0);
					// 1.60.0: baixas in red, pending baixas in amber; 1.60.1: waiting list in yellow.
					var matTable = buildTable(paged, MAT_COLS, matSt.sort, renderMat, function (tr, row) {
						if (row.estado === 'baixa') { tr.classList.add('anpa-row-matricula-baixa'); }
						else if (row.estado === 'baixa_solicitada') { tr.classList.add('anpa-row-baixa-pending'); }
						else if (row.estado === 'lista_espera') { tr.classList.add('anpa-row-matricula-espera'); }
						else if (row.estado === 'pendente_aprobacion') { tr.classList.add('anpa-row-matricula-pendente'); }
					});
					matHost.appendChild(matTable);
					if (matSt.size > 0 && sorted.length > matSt.size) {
						matHost.appendChild(buildPagination(sorted.length, matSt.page, matSt.size, function (p, s) {
							matSt.page = p; matSt.size = s; renderMat();
						}));
					}
				}
				renderMat();
			}).catch(function (e) { matHost.textContent = ''; showMessage(e.message, 'error'); });
		}
		loadMat(matCursoSelect.value);
	}

	// ── 1.68.1: Baixas de actividades pendentes (moved here from Socios → Baixas) ──
	function renderBaixasActividades(host) {
		host.textContent = '';
		var card = document.createElement('div');
		card.className = 'anpa-mgmt-form anpa-baixas-actividades';
		card.innerHTML = '<p class="anpa-mgmt-loading">Cargando baixas de actividades\u2026</p>';
		host.appendChild(card);
		anpaAdminFetch('baixas-pendentes').then(function (data) {
			card.textContent = '';
			var mats = data && Array.isArray(data.matriculas) ? data.matriculas : [];
			var h3m = document.createElement('h3');
			h3m.textContent = 'Baixas de actividades pendentes (' + mats.length + ')';
			card.appendChild(h3m);
			var intro = document.createElement('p');
			intro.className = 'description';
			intro.textContent = 'Baixas de actividades solicitadas polas familias desde a área. Confirmar fai efectiva a baixa e ofrece a praza á lista de espera; rexeitar devolve a matrícula a activa. Nos dous casos a familia recibe un correo («baixa_extraescolar_confirmada» / «baixa_extraescolar_rexeitada»): coa ventá de inscrición aínda aberta a baixa é inmediata e sen cobro; pechada, é efectiva ao remate do trimestre en curso.';
			card.appendChild(intro);
			if (!mats.length) { card.appendChild(emptyEl('Non hai solicitudes de baixa de actividades.')); return; }
			function cell(text) { var td = document.createElement('td'); td.textContent = text == null ? '' : String(text); return td; }
			function act(path, okMsg) {
				anpaAdminFetch(path, { method: 'POST' }).then(function (r) {
					var mail = '';
					if (r && r.correo_enviado === true) { mail = ' Enviouse o correo á familia.'; }
					else if (r && r.correo_enviado === false) { mail = ' ATENCIÓN: non se puido enviar o correo á familia; avísaa por outro medio.'; }
					showMessage(okMsg + mail, r && r.correo_enviado === false ? 'warning' : 'success');
					renderBaixasActividades(host);
				}).catch(function (e) { showMessage(e.message, 'error'); renderBaixasActividades(host); });
			}
			var tm = document.createElement('table'); tm.className = 'anpa-mgmt-table';
			var thead = document.createElement('thead'); var hr = document.createElement('tr');
			['Data da solicitude', 'Alumno/a', 'Curso/Aula', 'Actividade', 'Grupo', 'Curso escolar', 'Familia (email)', 'Accións'].forEach(function (l) { var th = document.createElement('th'); th.textContent = l; hr.appendChild(th); });
			thead.appendChild(hr); tm.appendChild(thead);
			var tbody = document.createElement('tbody');
			mats.forEach(function (m) {
				var tr = document.createElement('tr');
				tr.className = 'anpa-row-baixa-pending';
				tr.appendChild(cell(formatAdminDate(m.solicitada_en)));
				tr.appendChild(cell(((m.fillo_apelidos || '') + ', ' + (m.fillo_nome || '')).replace(/^, /, '')));
				tr.appendChild(cell(m.curso_completo));
				tr.appendChild(cell(m.actividade));
				tr.appendChild(cell([m.grupo, m.dias, m.franxa].filter(Boolean).join(' · ')));
				tr.appendChild(cell(m.curso_escolar));
				tr.appendChild(cell(m.socio_email));
				var path = 'matricula/' + m.id + '/baixa/';
				var td = document.createElement('td');
				var ok = document.createElement('button'); ok.type = 'button'; ok.className = 'anpa-mgmt-btn anpa-mgmt-btn-danger'; ok.textContent = 'Confirmar baixa';
				ok.addEventListener('click', function () {
					if (!window.confirm('Confirmar a baixa de ' + (m.fillo_nome || '') + ' en ' + (m.actividade || '') + '? A praza oférecese ao seguinte da lista de espera.')) { return; }
					act(path + 'confirm', 'Baixa da actividade confirmada.');
				});
				var no = document.createElement('button'); no.type = 'button'; no.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary'; no.textContent = 'Rexeitar'; no.style.marginLeft = '0.4rem';
				no.addEventListener('click', function () {
					if (!window.confirm('Rexeitar a solicitude? A matrícula volve a estar activa.')) { return; }
					act(path + 'reject', 'Solicitude rexeitada; a matrícula segue activa.');
				});
				td.appendChild(ok); td.appendChild(no); tr.appendChild(td);
				tbody.appendChild(tr);
			});
			tm.appendChild(tbody);
			card.appendChild(tm);
		}).catch(function (e) { card.textContent = ''; card.appendChild(emptyEl('Non se puideron cargar as baixas de actividades: ' + e.message)); });
	}

	// ── 1.68.0: Estado do curso e matrículas (Xestión → Extraescolares → Matrículas) ──
	// The single enrolment switch (two combos) plus the course-cycle notices to
	// the families. Every action goes to ANPA_Socios_Admin_Trimestres_Handler.
	function renderEstadoCurso(host, curso) {
		host.textContent = '';
		var panel = document.createElement('div');
		panel.className = 'anpa-mgmt-form anpa-estado-curso';
		panel.innerHTML = '<p class="anpa-mgmt-loading">Cargando o estado do curso\u2026</p>';
		host.appendChild(panel);
		anpaAdminFetch('trimestres?curso=' + encodeURIComponent(curso)).then(function (d) {
			panel.textContent = '';
			renderEstadoCursoPanel(panel, d || {});
		}).catch(function (e) {
			panel.textContent = '';
			panel.appendChild(emptyEl('Non se puido cargar o estado do curso: ' + e.message));
		});
	}

	function renderEstadoCursoPanel(panel, d) {
		var curso = String(d.curso || '');
		function el(tag, cls, text) { var e = document.createElement(tag); if (cls) { e.className = cls; } if (text != null) { e.textContent = text; } return e; }
		function ordinal(n) { return String(n) + '\u00BA'; }
		function destinatarios() {
			return 'Enviarase a ' + (d.socios_activos || 0) + ' socios/as activos en ' + (d.lotes || 0) + ' envío(s) de ata ' + (d.tamano_lote || 50) + ' destinatarios en CCO, con ' + (d.correo_xunta || 'a conta da xunta') + ' como destinatario visible.';
		}
		function resumoEnvio(e) {
			if (!e) { return ''; }
			if (!e.destinatarios) { return 'Sen destinatarios: non se enviou ningún correo.'; }
			return 'Correo enviado a ' + e.enviados + ' de ' + e.destinatarios + ' destinatarios en ' + e.lotes + ' envío(s)' + (e.fallidos ? '; ' + e.fallidos + ' fallaron (revisa Operacións → Auditoría)' : '') + '.';
		}
		function post(path, body, okMsg) {
			return anpaAdminFetch(path, { method: 'POST', body: body }).then(function (r) {
				var msg = okMsg;
				if (r && r.envio) { msg += ' ' + resumoEnvio(r.envio); }
				if (r && r.aprobacions && (r.aprobacions.praza || r.aprobacions.espera || r.aprobacions.erros)) {
					msg += ' Matrículas pendentes aprobadas: ' + r.aprobacions.praza + ' con praza, ' + r.aprobacions.espera + ' en lista de espera' + (r.aprobacions.erros ? ', ' + r.aprobacions.erros + ' con erro' : '') + '.';
				}
				if (r && typeof r.grupos_pechados === 'number') { msg += ' Grupos pechados: ' + r.grupos_pechados + '; matrículas dadas de baixa: ' + r.matriculas_baixa + '.'; }
				showMessage(msg, r && r.envio && r.envio.fallidos ? 'warning' : 'success');
				loadCursos();
			}).catch(function (e) { showMessage(e.message, 'error'); });
		}

		panel.appendChild(el('h3', null, 'Estado do curso ' + curso + ' e matrículas'));
		panel.appendChild(el('p', 'description', 'Aquí está o único interruptor das matrículas. O trimestre activo é informativo (calendario e avisos); «Matrículas abertas para» é o que abre ou pecha as matrículas, baixas e solicitudes das familias. Só pode haber un trimestre activo e unha ventá aberta á vez. Cada cambio queda rexistrado (quen, cando, orixe) e o sistema nunca cambia un estado por si só. Co prazo pechado e o curso activo, as solicitudes das familias quedan pendentes de aprobación (Socios → Aprobacións).'));

		if (d.estado_curso !== 'activo') {
			var warn = el('div', 'anpa-mgmt-aviso-matriculas anpa-mgmt-aviso-matriculas--outro');
			warn.appendChild(el('strong', null, 'Curso ' + (d.estado_curso === 'pendente' ? 'pendente' : (d.estado_curso === 'pechado' ? 'pechado' : 'sen configurar'))));
			warn.appendChild(el('span', null, d.estado_curso === 'pendente'
				? 'Aínda non está activo: «Notificar comezo do curso» actívao, abre as matrículas do 1\u00BA trimestre e avisa ás familias.'
				: 'As familias non poden matricular. Para volver a abrilo usa «Notificar comezo do curso».'));
			panel.appendChild(warn);
		}
		if (!d.inicializado) {
			var ini = el('div', 'anpa-mgmt-aviso-matriculas anpa-mgmt-aviso-matriculas--abertas');
			ini.appendChild(el('strong', null, 'Trimestres sen inicializar'));
			ini.appendChild(el('span', null, 'Este curso aínda non ten os tres trimestres configurados; ata entón non se poden aplicar cambios (o sistema non asume ningún estado por defecto).'));
			var iniBtn = el('button', 'anpa-mgmt-btn', 'Inicializar trimestres deste curso'); iniBtn.type = 'button';
			iniBtn.addEventListener('click', function () {
				if (!window.confirm('Inicializar os trimestres do curso ' + curso + '? Crea os que falten (1\u00BA activo, 2\u00BA e 3\u00BA pendentes, matrículas pechadas) sen tocar os que xa existen.')) { return; }
				post('trimestres/inicializar', { curso: curso }, 'Trimestres inicializados.');
			});
			ini.appendChild(iniBtn);
			panel.appendChild(ini);
		}

		// Read-only state table.
		var TRI_LABEL = { pendente: '\u23F3 Pendente', activo: '\uD83D\uDFE2 Activo', pechado: '\uD83D\uDD12 Pechado' };
		var table = el('table', 'anpa-mgmt-table anpa-estado-curso-tabela');
		var thead = document.createElement('thead'); var hr = document.createElement('tr');
		['Trimestre', 'Estado lectivo', 'Matrículas (ventá do trimestre)'].forEach(function (t) { hr.appendChild(el('th', null, t)); });
		thead.appendChild(hr); table.appendChild(thead);
		var tbody = document.createElement('tbody');
		(d.trimestres || []).forEach(function (t) {
			var tr = document.createElement('tr');
			tr.appendChild(el('td', null, ordinal(t.trimestre) + ' trimestre'));
			tr.appendChild(el('td', null, t.presente ? (TRI_LABEL[t.estado] || t.estado) : '\u26A0\uFE0F Sen configurar'));
			tr.appendChild(el('td', null, t.ventana_estado === 'aberta' ? '\uD83D\uDCE8 Abertas' : '\u26D4 Pechadas'));
			tbody.appendChild(tr);
		});
		table.appendChild(tbody);
		panel.appendChild(table);

		// 1.68.1: the operative dates behind the trimester (informative; edited in Axustes → Cursos).
		function dmy(v) { return v ? String(v).split('-').reverse().join('/') : 'sen configurar'; }
		var datas = d.datas || {};
		var pDatas = el('p', 'description anpa-estado-curso-datas');
		pDatas.appendChild(document.createTextNode('Datas do curso: comeza o ' + dmy(datas.inicio) + ' · fin do 1\u00BA trimestre ' + dmy(datas.t1) + ' · fin do 2\u00BA trimestre ' + dmy(datas.t2) + ' · remata o ' + dmy(datas.peche) + '. O trimestre actual derívase destas datas; edítanse en '));
		var aDatas = document.createElement('a'); aDatas.href = 'admin.php?page=anpa-socios-settings&tab=cursos'; aDatas.textContent = 'Axustes → Cursos';
		pDatas.appendChild(aDatas);
		pDatas.appendChild(document.createTextNode('.'));
		panel.appendChild(pDatas);

		// Combos.
		var combos = el('div', 'anpa-estado-curso-combos');
		var l1 = el('label', null, 'Trimestre activo: ');
		var selTri = document.createElement('select'); selTri.disabled = !d.inicializado;
		var actualTri = d.trimestre_activo === '' || d.trimestre_activo == null ? '' : String(d.trimestre_activo);
		if (actualTri === '') { var o0 = el('option', null, 'Sen trimestre activo'); o0.value = ''; o0.disabled = true; selTri.appendChild(o0); }
		[['1', '1\u00BA trimestre'], ['2', '2\u00BA trimestre'], ['3', '3\u00BA trimestre'], ['pechado', 'Curso pechado']].forEach(function (p) {
			var o = el('option', null, p[1]); o.value = p[0]; selTri.appendChild(o);
		});
		selTri.value = actualTri;
		l1.appendChild(selTri);
		var b1 = el('button', 'anpa-mgmt-btn anpa-mgmt-btn-secondary', 'Aplicar'); b1.type = 'button'; b1.disabled = !d.inicializado;
		b1.addEventListener('click', function () {
			var destino = selTri.value;
			if (!destino || destino === actualTri) { showMessage('Escolle un trimestre distinto do actual.', 'error'); return; }
			var body = { curso: curso, destino: destino === 'pechado' ? 'pechado' : parseInt(destino, 10) };
			var txt = destino === 'pechado'
				? 'Pechar o curso ' + curso + '? Péchanse os tres trimestres, as matrículas e o curso. As familias non poderán matricular nin dar de baixa. Para pechar tamén os grupos e as matrículas e avisar ás familias usa «Notificar fin de curso».'
				: 'Activar o ' + ordinal(destino) + ' trimestre? Os trimestres anteriores quedan pechados. As matrículas non cambian: ábrense ou péchanse co combo «Matrículas abertas para».';
			if (!window.confirm(txt)) { return; }
			if (destino !== 'pechado' && d.matriculas_pendentes > 0) {
				if (!window.confirm('Hai ' + d.matriculas_pendentes + ' matrícula(s) pendente(s) de aprobación. Ao activar o trimestre pasarán TODAS á súa actividade (praza se hai sitio, se non lista de espera) e as familias recibirán correo.\n\nAceptar = aprobalas todas agora e activar.\nCancelar = non activar; xestiónaas antes unha a unha en Socios → Aprobacións.')) { return; }
				body.aprobar_pendentes = true;
			}
			post('trimestres/estado', body, destino === 'pechado' ? 'Curso pechado.' : ordinal(destino) + ' trimestre activo.');
		});
		l1.appendChild(b1);
		combos.appendChild(l1);

		var l2 = el('label', null, 'Matrículas abertas para: ');
		var selVen = document.createElement('select'); selVen.disabled = !d.inicializado;
		[['0', 'Matrículas pechadas'], ['1', 'Abertas para o 1\u00BA trimestre'], ['2', 'Abertas para o 2\u00BA trimestre'], ['3', 'Abertas para o 3\u00BA trimestre']].forEach(function (p) {
			var o = el('option', null, p[1]); o.value = p[0]; selVen.appendChild(o);
		});
		var actualVen = String(parseInt(d.ventana_aberta, 10) || 0);
		selVen.value = actualVen;
		l2.appendChild(selVen);
		var chk = document.createElement('input'); chk.type = 'checkbox'; chk.id = 'anpa-estado-curso-notificar';
		var chkLabel = el('label', 'anpa-estado-curso-check'); chkLabel.appendChild(chk); chkLabel.appendChild(document.createTextNode(' Avisar por correo a todas as familias'));
		var b2 = el('button', 'anpa-mgmt-btn anpa-mgmt-btn-secondary', 'Aplicar'); b2.type = 'button'; b2.disabled = !d.inicializado;
		b2.addEventListener('click', function () {
			var destino = parseInt(selVen.value, 10) || 0;
			if (String(destino) === actualVen) { showMessage('Escolle un estado distinto do actual.', 'error'); return; }
			if (destino > 0 && d.estado_curso !== 'activo') { showMessage('Só se poden abrir as matrículas do curso activo. Usa «Notificar comezo do curso».', 'error'); return; }
			var notificar = !!chk.checked;
			var txt = (destino > 0 ? 'Abrir as matrículas para o ' + ordinal(destino) + ' trimestre' : 'Pechar as matrículas') + (actualVen !== '0' && destino > 0 ? ' (a ventá do ' + ordinal(actualVen) + ' péchase)' : '') + '.'
				+ (notificar ? '\n\nAvisarase ás familias co correo «' + (destino > 0 ? 'matriculas_abertas' : 'matriculas_pechadas') + '». ' + destinatarios() : '\n\nSen correo ás familias (marca a casilla se queres avisalas).');
			if (!window.confirm(txt)) { return; }
			post('trimestres/ventana', { curso: curso, destino: destino, notificar: notificar }, destino > 0 ? 'Matrículas abertas para o ' + ordinal(destino) + ' trimestre.' : 'Matrículas pechadas.');
		});
		l2.appendChild(b2);
		combos.appendChild(l2);
		combos.appendChild(chkLabel);
		panel.appendChild(combos);

		// Notices to the families.
		var avisos = el('div', 'anpa-estado-curso-avisos');
		avisos.appendChild(el('h4', null, 'Avisos ás familias'));
		avisos.appendChild(el('p', 'description', destinatarios() + ' Textos en Axustes → Plantillas de email («inicio_curso», «prazo_matriculas», «matriculas_abertas», «matriculas_pechadas», «fin_curso»).'));

		var fila1 = el('div', 'anpa-estado-curso-fila');
		var bComezo = el('button', 'anpa-mgmt-btn', 'Notificar comezo do curso'); bComezo.type = 'button';
		var outroActivo = d.curso_activo && d.curso_activo !== curso;
		if (outroActivo) { bComezo.disabled = true; bComezo.title = 'Xa hai outro curso activo (' + d.curso_activo + '). Péchao primeiro no seu panel.'; }
		bComezo.addEventListener('click', function () {
			if (!window.confirm('Notificar o comezo do curso ' + curso + '?\n\n' + (d.estado_curso !== 'activo' ? 'Activa o curso, ' : '') + 'pon o 1\u00BA trimestre activo, abre as matrículas do 1\u00BA trimestre e envía o correo «inicio_curso» a todas as familias.\n\n' + destinatarios())) { return; }
			post('avisos/comezo-curso', { curso: curso }, 'Comezo do curso notificado.');
		});
		fila1.appendChild(bComezo);
		fila1.appendChild(el('span', 'description', 'Activa o curso se está pendente, abre as matrículas do 1\u00BA trimestre e envía o correo de inicio de curso.'));
		avisos.appendChild(fila1);

		var fila2 = el('div', 'anpa-estado-curso-fila');
		var bPrazo = el('button', 'anpa-mgmt-btn anpa-mgmt-btn-secondary', 'Notificar prazo de matrículas'); bPrazo.type = 'button';
		var hoxe = new Date();
		var mercores = new Date(hoxe); mercores.setDate(hoxe.getDate() + (((3 - hoxe.getDay() + 7) % 7) || 7));
		var anoInicio = parseInt(curso.split('/')[0], 10) || hoxe.getFullYear();
		function iso(dt) { return dt.getFullYear() + '-' + String(dt.getMonth() + 1).padStart(2, '0') + '-' + String(dt.getDate()).padStart(2, '0'); }
		var lPeche = el('label', null, 'Remata o '); var inPeche = document.createElement('input'); inPeche.type = 'date'; inPeche.value = iso(mercores); lPeche.appendChild(inPeche);
		var lInicio = el('label', null, 'as actividades comezan o '); var inInicio = document.createElement('input'); inInicio.type = 'date'; inInicio.value = anoInicio + '-10-01'; lInicio.appendChild(inInicio);
		bPrazo.addEventListener('click', function () {
			if (!inPeche.value || !inInicio.value) { showMessage('Indica as dúas datas.', 'error'); return; }
			if (!window.confirm('Lembrar ás familias que o prazo de matrícula remata o ' + inPeche.value.split('-').reverse().join('/') + ' e que as actividades comezan o ' + inInicio.value.split('-').reverse().join('/') + '?\n\n' + destinatarios())) { return; }
			post('avisos/prazo-matriculas', { data_peche: inPeche.value, data_inicio_actividades: inInicio.value }, 'Prazo de matrículas notificado.');
		});
		fila2.appendChild(bPrazo); fila2.appendChild(lPeche); fila2.appendChild(lInicio);
		avisos.appendChild(fila2);

		var fila3 = el('div', 'anpa-estado-curso-fila');
		var bFin = el('button', 'anpa-mgmt-btn anpa-mgmt-btn-danger', 'Notificar fin de curso'); bFin.type = 'button';
		bFin.addEventListener('click', function () {
			if (!window.confirm('Notificar o FIN do curso ' + curso + '?\n\nPéchanse os tres trimestres e as matrículas, o curso pasa a pechado, TODOS os grupos abertos pasan a pechados e TODAS as matrículas vixentes (activas, lista de espera, ofertas, baixas solicitadas e pendentes) pasan a baixa con data de hoxe. Envíase o correo «fin_curso» a todas as familias.\n\n' + destinatarios())) { return; }
			if (!window.confirm('Esta acción non se pode desfacer. Confirmas o fin do curso ' + curso + '?')) { return; }
			post('avisos/fin-curso', { curso: curso }, 'Fin de curso notificado.');
		});
		fila3.appendChild(bFin);
		fila3.appendChild(el('span', 'description', 'Pecha trimestres, matrículas, grupos e curso, e agradece ás familias a participación.'));
		avisos.appendChild(fila3);
		panel.appendChild(avisos);
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
								var excluded = [];
								reportArea.querySelectorAll('input[type="checkbox"][data-row-index]').forEach(function (c) {
									if (!c.checked) { excluded.push(parseInt(c.dataset.rowIndex, 10)); }
								});
								var willImport = data.to_insert_count - excluded.length;
								if (willImport <= 0) { showMessage('Non hai filas seleccionadas para importar.', 'error'); return; }
								if (!window.confirm('Confirmar a importaci\u00F3n de ' + willImport + ' rexistros?')) { return; }
								commitBtn.disabled = true;
								commitBtn.textContent = 'Importando\u2026';
								anpaAdminFetch('import/' + entity, {
									method: 'POST',
									body: { csv: csvText, commit: true, exclude_rows: excluded },
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

		var state = sectionState.gruposHorarios || (sectionState.gruposHorarios = {});
		var cursosEscolares = Array.isArray(cfg.cursosescolares) ? cfg.cursosescolares.map(String) : [];
		if (!cursosEscolares.length && activeCourse) {
			cursosEscolares.push(activeCourse);
		}
		if (activeCourse && cursosEscolares.indexOf(activeCourse) === -1) {
			cursosEscolares.unshift(activeCourse);
		}
		var defaultCourse = state.curso || activeCourse || cursosEscolares[0] || '';
		if (defaultCourse && cursosEscolares.indexOf(defaultCourse) === -1) {
			cursosEscolares.unshift(defaultCourse);
		}
		state.curso = defaultCourse;

		var panel = document.createElement('section');
		panel.className = 'anpa-mgmt-form anpa-grupos-horarios-panel';

		var h3 = document.createElement('h3');
		h3.textContent = 'Grupos e horarios';
		panel.appendChild(h3);

		var intro = document.createElement('p');
		intro.className = 'anpa-grupos-horarios-intro';
		intro.textContent = 'Vista só de lectura para consultar grupos por franxa, nivel e día. Editar abre directamente o formulario do grupo.';
		panel.appendChild(intro);

		var selector = document.createElement('div');
		selector.className = 'anpa-grupos-horarios-selector';
		var newGroupBtn = document.createElement('button');
		newGroupBtn.type = 'button';
		newGroupBtn.className = 'anpa-mgmt-btn';
		newGroupBtn.textContent = 'Novo grupo';
		newGroupBtn.disabled = !state.curso;
		newGroupBtn.addEventListener('click', function () { openNewGroupEditor(state.curso); });
		selector.appendChild(newGroupBtn);
		var courseLabel = document.createElement('label');
		courseLabel.textContent = 'Curso escolar';
		var select = document.createElement('select');
		var courseMap = {};
		function addCourseOption(course) {
			course = String(course || '');
			if (!course || courseMap[course]) { return; }
			courseMap[course] = true;
			var option = document.createElement('option');
			option.value = course;
			option.textContent = course;
			select.appendChild(option);
		}
		cursosEscolares.forEach(addCourseOption);
		addCourseOption(defaultCourse);
		select.value = defaultCourse;
		select.addEventListener('change', function () {
			state.curso = select.value || '';
			renderCourse(state.curso);
		});
		courseLabel.appendChild(select);
		selector.appendChild(courseLabel);
		panel.appendChild(selector);

		var status = document.createElement('p');
		status.className = 'anpa-grupos-horarios-status';
		status.textContent = '';
		panel.appendChild(status);

		var content = document.createElement('div');
		content.className = 'anpa-grupos-horarios-content';
		panel.appendChild(content);
		root.appendChild(panel);

		if (!defaultCourse) {
			status.textContent = 'Non hai cursos escolares configurados.';
			content.appendChild(emptyEl('Non hai datos dispoñibles.'));
			return;
		}

		function timeToMinutes(value) {
			var parts = String(value || '').split(':');
			var hour = parseInt(parts[0], 10);
			var minute = parseInt(parts[1], 10);
			if (isNaN(hour) || isNaN(minute)) { return null; }
			return (hour * 60) + minute;
		}

		function franxaOverlapsComedor(franxa, comedor) {
			var franxaParts = String(franxa || '').split('-');
			if (franxaParts.length !== 2 || !comedor) { return false; }
			var start = timeToMinutes(franxaParts[0]);
			var end = timeToMinutes(franxaParts[1]);
			var comedorStart = timeToMinutes(comedor.inicio);
			var comedorEnd = timeToMinutes(comedor.fin);
			if (start === null || end === null || comedorStart === null || comedorEnd === null) { return false; }
			return start < comedorEnd && end > comedorStart;
		}

		function normalizeDayTokens(dias) {
			var tokens = [];
			if (dias && typeof dias === 'object') {
				Object.keys(dias).forEach(function (dia) {
					tokens.push(dia);
				});
			}
			if (!tokens.length) {
				tokens = ['luns', 'martes', 'mercores', 'xoves', 'venres'];
			}
			return tokens;
		}

		function normalizeFranxas(franxas, slots) {
			var list = Array.isArray(franxas) ? franxas.slice() : [];
			if (!list.length && Array.isArray(slots)) {
				slots.forEach(function (slot) {
					if (slot && slot.franxa && list.indexOf(slot.franxa) === -1) {
						list.push(slot.franxa);
					}
				});
				list.sort(function (a, b) {
					var am = timeToMinutes(String(a).split('-')[0]);
					var bm = timeToMinutes(String(b).split('-')[0]);
					if (am === null && bm === null) { return String(a).localeCompare(String(b)); }
					if (am === null) { return 1; }
					if (bm === null) { return -1; }
					return am - bm;
				});
			}
			return list;
		}

		function normalizeNiveis(niveis) {
			var list = Array.isArray(niveis) ? niveis.slice() : [];
			list.sort(function (a, b) {
				var ordeA = a && a.orde !== undefined && a.orde !== null ? parseInt(a.orde, 10) : 0;
				var ordeB = b && b.orde !== undefined && b.orde !== null ? parseInt(b.orde, 10) : 0;
				if (ordeA !== ordeB) { return ordeA - ordeB; }
				var labelA = String((a && (a.etiqueta || a.codigo)) || '');
				var labelB = String((b && (b.etiqueta || b.codigo)) || '');
				return labelA.localeCompare(labelB);
			});
			return list;
		}

		function buildLevelLookup(niveis) {
			var lookup = {};
			(niveis || []).forEach(function (nivel, index) {
				lookup[String(nivel && nivel.id)] = { nivel: nivel, index: index };
			});
			return lookup;
		}

		function levelLabel(nivel) {
			if (!nivel) { return ''; }
			return String(nivel.etiqueta || nivel.codigo || nivel.nome || nivel.id || '');
		}

		function uniqueLevelLabels(nivelIds, levelLookup) {
			var seen = {};
			var labels = [];
			(nivelIds || []).forEach(function (nivelId) {
				var key = String(nivelId || '');
				if (!key || seen[key]) { return; }
				seen[key] = true;
				var entry = levelLookup[key];
				var nivel = entry ? entry.nivel : null;
				var label = levelLabel(nivel) || key;
				labels.push(label);
			});
			return labels;
		}

		function groupSlotsByGroup(slots) {
			var grouped = {};
			(slots || []).forEach(function (slot) {
				if (!slot) { return; }
				var groupId = slot.grupo_id != null ? slot.grupo_id : (slot.group_id != null ? slot.group_id : slot.id);
				var key = [String(groupId || ''), String(slot.franxa || ''), String(slot.dia || '')].join('|');
				if (!grouped[key]) {
					grouped[key] = {
						group_id: groupId,
						grupo_id: groupId,
						activity_id: slot.actividade_id != null ? slot.actividade_id : slot.activity_id,
						actividade_id: slot.actividade_id != null ? slot.actividade_id : slot.activity_id,
						actividade_nome: slot.actividade_nome || '',
						grupo_nome: slot.grupo_nome || slot.nome || '',
						horario: slot.horario || '',
						horario_label: slot.horario_label || '',
						serie_uid: slot.serie_uid || '',
						franxa: slot.franxa || '',
						dia: slot.dia || '',
						estado: slot.estado || '',
						conflito_comedor: !!slot.conflito_comedor,
						// 1.68.0: occupancy (same for every slot of the group).
						min_pupilos: parseInt(slot.min_pupilos, 10) || 0,
						max_pupilos: parseInt(slot.max_pupilos, 10) || 0,
						activos: parseInt(slot.activos, 10) || 0,
						espera: parseInt(slot.espera, 10) || 0,
						pendentes: parseInt(slot.pendentes, 10) || 0,
						nivel_ids: [],
						slots: []
					};
				}
				var group = grouped[key];
				group.slots.push(slot);
				if (slot.conflito_comedor) {
					group.conflito_comedor = true;
				}
				if (slot.actividade_id !== undefined && slot.actividade_id !== null && group.actividade_id === undefined) {
					group.actividade_id = slot.actividade_id;
				}
				if (slot.grupo_nome && !group.grupo_nome) {
					group.grupo_nome = slot.grupo_nome;
				}
				if (slot.actividade_nome && !group.actividade_nome) {
					group.actividade_nome = slot.actividade_nome;
				}
				if (slot.horario_label && !group.horario_label) {
					group.horario_label = slot.horario_label;
				}
				var nivelId = slot.nivel_id != null ? String(slot.nivel_id) : '';
				if (nivelId && group.nivel_ids.indexOf(nivelId) === -1) {
					group.nivel_ids.push(nivelId);
				}
			});
			return Object.keys(grouped).map(function (key) {
				return grouped[key];
			}).sort(function (a, b) {
				var nameA = String(a.grupo_nome || a.actividade_nome || a.group_id || '');
				var nameB = String(b.grupo_nome || b.actividade_nome || b.group_id || '');
				if (nameA !== nameB) { return nameA.localeCompare(nameB); }
				return String(a.group_id || '').localeCompare(String(b.group_id || ''));
			});
		}

		function makeMetaLabel(label, value) {
			var wrap = document.createElement('span');
			var strong = document.createElement('strong');
			strong.textContent = label + ': ';
			wrap.appendChild(strong);
			wrap.appendChild(document.createTextNode(value));
			return wrap;
		}

		function buildSummaryBlock(title, levels, className, levelLookup) {
			var block = document.createElement('article');
			block.className = 'anpa-grupos-horarios-level ' + className;
			var header = document.createElement('div');
			header.className = 'anpa-grupos-horarios-level-header';
			var name = document.createElement('span');
			name.className = 'anpa-grupos-horarios-level-name';
			name.textContent = title;
			header.appendChild(name);
			block.appendChild(header);
			var body = document.createElement('div');
			body.className = 'anpa-grupos-horarios-level-body';
			var labels = uniqueLevelLabels((levels || []).map(function (nivel) { return nivel.id; }), levelLookup);
			body.appendChild(makeMetaLabel('Niveis', labels.length ? labels.join(', ') : '—'));
			if (className.indexOf('comedor') !== -1) {
				body.appendChild(makeMetaLabel('Aviso', 'Non dispoñible por comedor'));
			}
			block.appendChild(body);
			return block;
		}

		function buildGroupCard(group, levelLookup) {
			var card = document.createElement('article');
			card.className = 'anpa-grupos-horarios-slot anpa-grupos-horarios-group-card';
			if (group.conflito_comedor) {
				card.className += ' anpa-grupos-horarios-comedor';
			}
			// 1.68.1: closed or disabled groups (hidden from the offer) stay in the grid, shaded.
			if (group.estado && group.estado !== 'aberto') {
				card.className += ' anpa-grupos-horarios-group-card--oculto';
			}
			var title = document.createElement('div');
			title.className = 'anpa-grupos-horarios-slot-title';
			title.textContent = group.grupo_nome || group.actividade_nome || 'Grupo';
			card.appendChild(title);
			var meta = document.createElement('div');
			meta.className = 'anpa-grupos-horarios-slot-meta';
			meta.appendChild(makeMetaLabel('Grupo', group.grupo_nome || '—'));
			meta.appendChild(makeMetaLabel('Niveis', uniqueLevelLabels(group.nivel_ids, levelLookup).join(', ') || '—'));
			meta.appendChild(makeMetaLabel('Estado', group.estado || '—'));
			var ocup = makeMetaLabel('Ocupación', group.activos + '/' + group.max_pupilos + ' inscritos · ' + group.espera + ' en espera' + (group.pendentes ? ' · ' + group.pendentes + ' pendentes' : '') + ' · mínimo ' + group.min_pupilos);
			ocup.className = 'anpa-grupos-horarios-ocupacion' + (group.min_pupilos > 0 && group.activos < group.min_pupilos ? ' anpa-grupos-horarios-ocupacion--baixo-minimo' : '');
			meta.appendChild(ocup);
			meta.appendChild(makeMetaLabel('Franxa', group.franxa || '—'));
			if (group.horario_label || group.horario) {
				meta.appendChild(makeMetaLabel('Horario', group.horario_label || group.horario));
			}
			if (group.conflito_comedor) {
				meta.appendChild(makeMetaLabel('Aviso', 'Non dispoñible por comedor'));
			}
			card.appendChild(meta);
			if (group.actividade_id) {
				var actions = document.createElement('div');
				actions.className = 'anpa-grupos-horarios-slot-actions';
				var edit = document.createElement('button');
				edit.type = 'button';
				edit.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary';
				edit.textContent = 'Editar';
				edit.addEventListener('click', function () {
					openGroupEditor(group.actividade_id || group.activity_id, group.group_id || group.grupo_id, group.serie_uid);
				});
				actions.appendChild(edit);
				// 1.68.1: the per-group notices only EXIST while the enrolment window is closed
				// (and the gate could be read) and the group is open; closed/disabled groups only
				// keep «Editar». «Notificar grupo creado» is always offered (a company may run a
				// group below its minimum); «Pechar por non acadar o mínimo» only below the minimum.
				var pechadas = state.matriculasAbertas === false;
				var baixoMinimo = group.min_pupilos > 0 && group.activos < group.min_pupilos;
				if (pechadas && group.estado === 'aberto') {
				var gid = group.group_id || group.grupo_id;
				var nomeG = group.grupo_nome || 'Grupo';
				var nomeA = group.actividade_nome || '';
				var bComezo = document.createElement('button');
				bComezo.type = 'button';
				bComezo.className = 'anpa-mgmt-btn anpa-mgmt-btn-secondary';
				bComezo.textContent = 'Notificar grupo creado (comezo do trimestre)';
				bComezo.addEventListener('click', function () {
					if (!window.confirm('Avisar ás familias inscritas no grupo «' + nomeG + '» de «' + nomeA + '» (e á empresa) de que o grupo queda confirmado e comeza o trimestre? As de lista de espera reciben outro correo. Envío en CCO por lotes, coa xunta como destinatario visible.')) { return; }
					anpaAdminFetch('grupo/' + gid + '/notificar-comezo', { method: 'POST' }).then(function (r) {
						var i = (r && r.inscritos) || {}; var e = (r && r.espera) || {};
						showMessage('Aviso enviado. Inscritos e empresa: ' + (i.enviados || 0) + ' de ' + (i.destinatarios || 0) + '; lista de espera: ' + (e.enviados || 0) + ' de ' + (e.destinatarios || 0) + '.', (i.fallidos || e.fallidos) ? 'warning' : 'success');
					}).catch(function (err) { showMessage(err.message, 'error'); });
				});
				actions.appendChild(bComezo);
				if (baixoMinimo) {
				var bMin = document.createElement('button');
				bMin.type = 'button';
				bMin.className = 'anpa-mgmt-btn anpa-mgmt-btn-danger';
				bMin.textContent = 'Pechar por non acadar o mínimo';
				bMin.addEventListener('click', function () {
					if (!window.confirm('Pechar o grupo «' + nomeG + '» de «' + nomeA + '» por non acadar o mínimo (' + group.activos + ' de ' + group.min_pupilos + ')? O grupo queda deshabilitado (desaparece de Grupos e horarios, da oferta e da área; pódese reactivar dende Actividades → grupos), as súas matrículas e as de lista de espera pasan a baixa, e as familias e a empresa reciben o correo «grupo_pechado_minimo». Esta acción non se pode desfacer.')) { return; }
					anpaAdminFetch('grupo/' + gid + '/pechar-minimo', { method: 'POST' }).then(function (r) {
						var e = (r && r.envio) || {};
						showMessage('Grupo deshabilitado; matrículas dadas de baixa: ' + (r && r.matriculas_baixa) + '. Correo enviado a ' + (e.enviados || 0) + ' de ' + (e.destinatarios || 0) + ' destinatarios.', e.fallidos ? 'warning' : 'success');
						renderCourse(state.curso);
					}).catch(function (err) { showMessage(err.message, 'error'); });
				});
				actions.appendChild(bMin);
				}
				}
				card.appendChild(actions);
			}
			return card;
		}

		function buildCellContent(franxa, diaToken, niveis, levelLookup, slots) {
			var stack = document.createElement('div');
			stack.className = 'anpa-grupos-horarios-cell-stack';
			var groups = groupSlotsByGroup(slots || []);
			var occupied = {};
			groups.forEach(function (group) {
				(group.nivel_ids || []).forEach(function (nivelId) {
					occupied[String(nivelId)] = true;
				});
			});
			var remainingLevels = (niveis || []).filter(function (nivel) {
				return !occupied[String(nivel && nivel.id)];
			});
			var comedorLevels = remainingLevels.filter(function (nivel) {
				return franxaOverlapsComedor(franxa, nivel && nivel.comedor);
			});
			var freeLevels = remainingLevels.filter(function (nivel) {
				return !franxaOverlapsComedor(franxa, nivel && nivel.comedor);
			});
			if (comedorLevels.length) {
				stack.appendChild(buildSummaryBlock('Non dispoñible por comedor', comedorLevels, 'anpa-grupos-horarios-comedor', levelLookup));
			}
			if (freeLevels.length) {
				stack.appendChild(buildSummaryBlock('Libre', freeLevels, 'anpa-grupos-horarios-free', levelLookup));
			}
			groups.forEach(function (group) {
				stack.appendChild(buildGroupCard(group, levelLookup));
			});
			if (!stack.childNodes.length) {
				stack.appendChild(buildSummaryBlock('Libre', niveis || [], 'anpa-grupos-horarios-free', levelLookup));
			}
			return stack;
		}

		function renderView(payload) {
			var data = payload || {};
			var dias = data.dias && typeof data.dias === 'object' ? data.dias : {};
			var dayTokens = normalizeDayTokens(dias);
			var dayLabels = {};
			dayTokens.forEach(function (dia) {
				dayLabels[dia] = dias[dia] || dia;
			});
			var franxas = normalizeFranxas(data.franxas, data.slots);
			var niveis = normalizeNiveis(data.niveis);
			var levelLookup = buildLevelLookup(niveis);
			var slots = Array.isArray(data.slots) ? data.slots.slice() : [];
			var cellMap = {};
			slots.forEach(function (slot) {
				var key = [String(slot.franxa || ''), String(slot.dia || '')].join('|');
				if (!cellMap[key]) { cellMap[key] = []; }
				cellMap[key].push(slot);
			});

			content.textContent = '';
			if (!franxas.length || !niveis.length || !dayTokens.length) {
				content.appendChild(emptyEl('Non hai datos suficientes para construír a grella.'));
				return;
			}

			var gridWrap = document.createElement('div');
			gridWrap.className = 'anpa-grupos-horarios-grid-wrap';
			var gridTitle = document.createElement('p');
			gridTitle.className = 'anpa-grupos-horarios-status';
			gridTitle.textContent = 'Vista en grella';
			gridWrap.appendChild(gridTitle);
			var grid = document.createElement('table');
			grid.className = 'anpa-grupos-horarios-grid';
			var caption = document.createElement('caption');
			caption.textContent = 'Curso escolar ' + (state.curso || defaultCourse);
			grid.appendChild(caption);
			var thead = document.createElement('thead');
			var headRow = document.createElement('tr');
			var corner = document.createElement('th');
			corner.scope = 'col';
			corner.textContent = 'Franxa / día';
			headRow.appendChild(corner);
			dayTokens.forEach(function (diaToken) {
				var th = document.createElement('th');
				th.scope = 'col';
				th.className = 'anpa-grupos-horarios-day';
				th.textContent = dayLabels[diaToken] || diaToken;
				headRow.appendChild(th);
			});
			thead.appendChild(headRow);
			grid.appendChild(thead);
			var tbody = document.createElement('tbody');
			franxas.forEach(function (franxa) {
				var tr = document.createElement('tr');
				var rowHead = document.createElement('th');
				rowHead.scope = 'row';
				rowHead.className = 'anpa-grupos-horarios-rowhead';
				rowHead.textContent = franxa;
				tr.appendChild(rowHead);
				dayTokens.forEach(function (diaToken) {
					var td = document.createElement('td');
					td.className = 'anpa-grupos-horarios-cell';
					td.appendChild(buildCellContent(franxa, diaToken, niveis, levelLookup, cellMap[[String(franxa || ''), String(diaToken || '')].join('|')] || []));
					tr.appendChild(td);
				});
				tbody.appendChild(tr);
			});
			grid.appendChild(tbody);
			gridWrap.appendChild(grid);
			content.appendChild(gridWrap);

			var listWrap = document.createElement('div');
			listWrap.className = 'anpa-grupos-horarios-list';
			var listTitle = document.createElement('p');
			listTitle.className = 'anpa-grupos-horarios-status';
			listTitle.textContent = 'Vista lineal accesible';
			listWrap.appendChild(listTitle);
			franxas.forEach(function (franxa) {
				var franxaSection = document.createElement('section');
				franxaSection.className = 'anpa-grupos-horarios-list-franxa';
				var franxaTitle = document.createElement('h4');
				franxaTitle.textContent = franxa;
				franxaSection.appendChild(franxaTitle);
				dayTokens.forEach(function (diaToken) {
					var daySection = document.createElement('section');
					daySection.className = 'anpa-grupos-horarios-list-day';
					var dayTitle = document.createElement('h5');
					dayTitle.textContent = dayLabels[diaToken] || diaToken;
					daySection.appendChild(dayTitle);
					daySection.appendChild(buildCellContent(franxa, diaToken, niveis, levelLookup, cellMap[[String(franxa || ''), String(diaToken || '')].join('|')] || []));
					franxaSection.appendChild(daySection);
				});
				listWrap.appendChild(franxaSection);
			});
			content.appendChild(listWrap);
		}

		function renderCourse(curso) {
			curso = String(curso || '');
			if (!curso) {
				status.textContent = 'Non hai un curso escolar seleccionado.';
				content.textContent = '';
				content.appendChild(emptyEl('Selecciona un curso escolar.'));
				return;
			}
			status.textContent = 'Cargando grupos de ' + curso + '…';
			content.textContent = '';
			// 1.68.0: the enrolment gate decides whether the per-group notices are usable
			// (fail closed: if it cannot be read, the buttons stay disabled).
			var gateReq = anpaAdminFetch('trimestres?curso=' + encodeURIComponent(curso)).then(function (t) {
				return !!(t && t.gate && t.gate.abertas);
			}).catch(function () { return true; });
			Promise.all([anpaAdminFetch('grupos-horarios?curso_escolar=' + encodeURIComponent(curso)), gateReq]).then(function (res) {
				var resp = res[0];
				state.matriculasAbertas = res[1];
				status.textContent = 'Curso cargado: ' + curso + (res[1] ? ' · matrículas ABERTAS (os avisos por grupo aparecen ao pechar o prazo)' : ' · matrículas pechadas: cada grupo aberto ten os botóns de aviso');
				renderView(resp);
			}).catch(function (e) {
				status.textContent = 'Erro ao cargar ' + curso + '.';
				content.textContent = '';
				content.appendChild(emptyEl('Erro ao cargar grupos: ' + e.message));
			});
		}

		renderCourse(defaultCourse);
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

		// All rows that would be inserted (with implicit position index). The
		// user can uncheck individual rows to exclude them from the import.
		var insertRows = (data.insert_rows && data.insert_rows.length) ? data.insert_rows : (data.preview || []);
		if (insertRows.length > 0) {
			var preTitle = document.createElement('p');
			preTitle.style.fontWeight = 'bold';
			preTitle.textContent = 'Filas a inserir (' + insertRows.length + '). Desmarca as que non queiras importar:';
			container.appendChild(preTitle);
			var cols = Object.keys(insertRows[0]);
			var wrap = document.createElement('div');
			wrap.style.maxHeight = '340px';
			wrap.style.overflow = 'auto';
			wrap.style.border = '1px solid #dcdcde';
			wrap.style.marginTop = '0.25rem';
			var preTable = document.createElement('table');
			preTable.className = 'anpa-mgmt-table anpa-import-preview';
			preTable.style.fontSize = '12px';
			var thead = document.createElement('thead');
			var hr = document.createElement('tr');
			var thAll = document.createElement('th');
			var allChk = document.createElement('input');
			allChk.type = 'checkbox';
			allChk.checked = true;
			allChk.setAttribute('aria-label', 'Marcar/desmarcar todas');
			allChk.addEventListener('change', function () {
				preTable.querySelectorAll('tbody input[type="checkbox"][data-row-index]').forEach(function (c) { c.checked = allChk.checked; });
			});
			thAll.appendChild(allChk);
			hr.appendChild(thAll);
			cols.forEach(function (c) {
				var th = document.createElement('th');
				th.textContent = colLabel(c) || c;
				hr.appendChild(th);
			});
			thead.appendChild(hr);
			preTable.appendChild(thead);
			var tbody = document.createElement('tbody');
			insertRows.forEach(function (row, idx) {
				var tr = document.createElement('tr');
				var tdChk = document.createElement('td');
				var chk = document.createElement('input');
				chk.type = 'checkbox';
				chk.checked = true;
				chk.setAttribute('data-row-index', String(idx));
				tdChk.appendChild(chk);
				tr.appendChild(tdChk);
				cols.forEach(function (c) {
					var td = document.createElement('td');
					td.textContent = row[c] != null ? String(row[c]) : '';
					tr.appendChild(td);
				});
				tbody.appendChild(tr);
			});
			preTable.appendChild(tbody);
			wrap.appendChild(preTable);
			container.appendChild(wrap);
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
		'baixas': loadBaixas,
		'lista-gmail': loadListaGmail,
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
	navigateTo(cfg.section || 'socios');

})();
