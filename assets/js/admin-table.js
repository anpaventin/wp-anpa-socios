/**
 * Pure, dependency-free helpers for admin list tables: sorting and pagination.
 *
 * UMD-style: attaches to window.AnpaAdminTable in the browser and exports for
 * Node (so the logic is unit-testable without a browser). No DOM, no I/O.
 *
 * @since 1.9.0
 * @package ANPA_Socios
 */
(function (root, factory) {
	'use strict';
	const api = factory();
	if (typeof module !== 'undefined' && module.exports) {
		module.exports = api;
	}
	if (root) {
		root.AnpaAdminTable = api;
	}
})(typeof window !== 'undefined' ? window : null, function () {
	'use strict';

	/**
	 * Returns a NEW array sorted by `key` ascending/descending. Pure: never
	 * mutates the input. Strings compare locale-aware + case-insensitive;
	 * numbers compare numerically. An unknown key yields the original order.
	 *
	 * @param {Array<object>} rows
	 * @param {string} key
	 * @param {('asc'|'desc')} dir
	 * @returns {Array<object>}
	 */
	function sortRows(rows, key, dir) {
		const list = Array.isArray(rows) ? rows.slice() : [];
		if (!key) {
			return list;
		}
		const factor = dir === 'desc' ? -1 : 1;
		// Decorate-sort-undecorate keeps the sort stable across engines.
		return list
			.map((row, index) => ({ row: row, index: index }))
			.sort((a, b) => {
				const cmp = compare(a.row[key], b.row[key]);
				return cmp !== 0 ? cmp * factor : a.index - b.index;
			})
			.map((entry) => entry.row);
	}

	function compare(a, b) {
		const an = a === null || a === undefined ? '' : a;
		const bn = b === null || b === undefined ? '' : b;
		const anum = typeof an === 'number';
		const bnum = typeof bn === 'number';
		if (anum && bnum) {
			return an < bn ? -1 : (an > bn ? 1 : 0);
		}
		return String(an).localeCompare(String(bn), undefined, { numeric: true, sensitivity: 'base' });
	}

	/**
	 * Returns the slice of `rows` for a 1-based page. A size of 0 (or a
	 * non-positive / non-finite size) returns ALL rows. Out-of-range pages
	 * return an empty array. Pure: never mutates the input.
	 *
	 * @param {Array<object>} rows
	 * @param {number} page  1-based page number
	 * @param {number} size  page size; 0/Infinity = all
	 * @returns {Array<object>}
	 */
	function pageSlice(rows, page, size) {
		const list = Array.isArray(rows) ? rows : [];
		if (!size || size <= 0 || !isFinite(size)) {
			return list.slice();
		}
		const p = Math.max(1, Math.floor(page) || 1);
		const start = (p - 1) * size;
		return list.slice(start, start + size);
	}

	return { sortRows: sortRows, pageSlice: pageSlice };
});
