/**
 * Pure, dependency‑free helpers for admin tables: labels, filtering, CSV.
 *
 * UMD-style: attaches to window.AnpaUtils in the browser and exports for
 * Node (so the logic is unit‑testable without a browser). No DOM, no I/O.
 *
 * @since  1.16.0
 * @package ANPA_Socios
 */
(function (root, factory) {
	'use strict';
	var api = factory();
	if (typeof module !== 'undefined' && module.exports) {
		module.exports = api;
	}
	if (root) {
		root.AnpaUtils = api;
	}
})(typeof window !== 'undefined' ? window : null, function () {
	'use strict';

	/**
	 * Column label map: converts snake_case keys to human-readable Galician labels.
	 * Keys that should be hidden are mapped to ''.
	 */
	var COLUMN_LABELS = {
		'email': 'Email',
		'nome': 'Nome',
		'apelidos': 'Apelidos',
		'estado': 'Estado',
		'rol': 'Rol',
		'creado_en': 'Creado',
		'actualizado_en': 'Actualizado',
		'telefono': 'Tel\u00E9fono',
		'nif': 'NIF',
		'baixa_estado': '',
		'baixa_solicitada_en': '',
		// Empresas
		'responsable': 'Responsable',
		// Actividades
		'empresa_id': '',
		'empresa_nome': 'Empresa',
		'descripcion': 'Descrici\u00F3n',
		'curso_escolar': 'Curso escolar',
		'idade_min': 'Idade m\u00EDn.',
		'idade_max': 'Idade m\u00E1x.',
		'custo': 'Custo',
		// Matr\u00EDculas
		'fillo_id': '',
		'fillo_nome': 'Fillo/a',
		'activitad_id': '',
		'actividade_nome': 'Actividade',
		'comedor': 'Comedor',
		'tarde': 'Tarde',
		'observaciones': 'Observaci\u00F3ns',
		// Cursos
		'curso': 'Curso',
		// Fillos / Audit
		'socio_email': 'Socio',
		'data_nacemento': 'Data nacemento',
		'aula': 'Grupo',
		'actor_email': 'Usuario',
		'actor_tipo': 'Tipo',
		'target_tipo': 'Destino',
		'target_id': 'ID',
		'accion': 'Acci\u00F3n',
		'timestamp': 'Data/Hora',
		'aula_nome': 'Aula',
		// _display computed columns
		'curso_display': 'Curso',
		'estado_display': 'Estado',
		'_empresa_nome': 'Empresa',
		'_opcions': 'Opci\u00F3ns',
		// matr\u00EDculas columns
		'fillo_apelidos': 'Apelidos',
		'fillo_nome': 'Nome',
		'actividade': 'Actividade',
		'curso_completo': 'Curso/Aula',
		'franxa': 'Franxa',
		'dias': 'D\u00EDas',
		'trimestre': 'Trim.',
		// fillos section
		'proxenitor_apelidos': 'Apelidos prox.',
		'proxenitor_nome': 'Nome prox.',
	};

	/** Returns the human label for a column key, or the original if unmapped. */
	function colLabel(key) {
		var mapped = COLUMN_LABELS[key];
		if (mapped === '') { return null; }  // hidden column
		return mapped || key;
	}

	/** Visible-column getter: skips empty labels and a few technical fields. */
	function visibleColumns(keys) {
		return keys.filter(function(k) {
			var lbl = colLabel(k);
			return lbl !== null && k !== 'id';
		});
	}

	/**
	 * Returns a filtered copy of `rows` where any visible-column value
	 * contains `query` (case-insensitive). Empty/missing query â all rows.
	 */
	function filterRows(rows, query, cols) {
		if (!query || !query.trim()) { return rows; }
		var q = query.trim().toLowerCase();
		var visible = visibleColumns(cols);
		return rows.filter(function(r) {
			for (var i = 0; i < visible.length; i++) {
				var v = r[visible[i]];
				if (v !== null && v !== undefined && String(v).toLowerCase().indexOf(q) !== -1) {
					return true;
				}
			}
			return false;
		});
	}

	/**
	 * Builds a client-side CSV string from the given rows and visible columns.
	 */
	function buildCsvString(rows, cols) {
		var visible = visibleColumns(cols);
		var lines = [];
		// Header row
		lines.push(visible.map(function(c) { return '"' + (colLabel(c) || c) + '"'; }).join(';'));
		// Data rows
		rows.forEach(function(r) {
			lines.push(visible.map(function(c) {
				var v = r[c];
				if (v === null || v === undefined) { v = ''; }
				v = String(v).replace(/"/g, '""');
				return '"' + v + '"';
			}).join(';'));
		});
		return lines.join('\r\n');
	}

	/**
	 * Determines whether a row is considered "inactive" for a section.
	 */
	function isInactiveRow(section, row) {
		if (section === 'socios') {
			return row.estado === 'baixa' || row.estado === 'pendiente_alta';
		}
		return row.estado === 'inactivo';
	}

	return {
		colLabel: colLabel,
		visibleColumns: visibleColumns,
		filterRows: filterRows,
		buildCsvString: buildCsvString,
		isInactiveRow: isInactiveRow,
	};
});
