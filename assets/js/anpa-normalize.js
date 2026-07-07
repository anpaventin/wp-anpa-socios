/**
 * Client-side normalization helpers for the area + admin forms.
 *
 * Pure functions: same input → same output, no side effects. Mirrors
 * the PHP `ANPA_Socios_Normalize` class so the user always sees the
 * canonical form before the data is sent to the server.
 *
 * @since  1.20.0
 * @package ANPA_Socios
 */
var AnpaNormalize = (function() {

	// Particles that stay lower-case inside multi-word names EXCEPT
	// when they appear at the very first position of the string.
	var PARTICLES = {
		de: 1, del: 1, la: 1, las: 1, el: 1, los: 1,
		y: 1, e: 1,
		da: 1, do: 1, dos: 1, das: 1,
		i: 1, van: 1, von: 1
	};

	/**
	 * Title case: first letter of every word upper, rest lower.
	 *
	 * Handles UTF-8 (toLocaleUpperCase handles accented chars in modern
	 * browsers and Node), particle preservation, hyphens, apostrophes,
	 * multiple spaces, mixed case.
	 *
	 * Examples:
	 *  - "maría JOSÉ"        → "María José"
	 *  - "RUIZ DE LA PRADA"  → "Ruiz de la Prada"
	 *  - "MARÍA-JOSÉ"        → "María-José"
	 *  - "O'RIANXO"          → "O'Ryanxo"
	 *  - "  maría   josé  "  → "María José"
	 */
	function titleCase(value) {
		if (value === null || value === undefined) { return ''; }
		var v = String(value).trim().replace(/\s+/g, ' ');
		if (!v) { return ''; }
		// Capitalize first letter of each word. Using a regex \b works for
		// ASCII but not for accented chars in some browsers, so we split
		// and apply per-word.
		var tokens = v.split(/(\s+|-|’|‘|\')/);
		var isFirst = true;
		var result  = '';
		for (var i = 0; i < tokens.length; i++) {
			var tok = tokens[i];
			if (!tok) { continue; }
			if (/^(\s+|-|’|‘|\')$/.test(tok)) {
				result += tok;
				continue;
			}
			var lower = tok.toLowerCase();
			if (!isFirst && PARTICLES[lower]) {
				result += lower;
			} else {
				result += lower.charAt(0).toLocaleUpperCase('gl') + lower.substring(1);
			}
			isFirst = false;
		}
		return result;
	}

	/**
	 * Canonical email: trim + lower-case.
	 * Returns null if the email is invalid (empty after trim is null too).
	 */
	function email(value) {
		if (value === null || value === undefined) { return null; }
		var v = String(value).toLowerCase().trim();
		if (!v) { return null; }
		// Same regex as the PHP filter_var FILTER_VALIDATE_EMAIL.
		if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) { return null; }
		return v;
	}

	/**
	 * Canonical Spanish phone: 9 digits, no country prefix.
	 * Accepts "+34", "0034", spaces, dots, dashes, parentheses.
	 * Returns null if not 9 valid Spanish digits.
	 */
	function telefono(value) {
		if (value === null || value === undefined) { return null; }
		var v = String(value).replace(/[\s.\-()]/g, '');
		if (v.indexOf('+34') === 0) { v = v.substring(3); }
		else if (v.indexOf('0034') === 0) { v = v.substring(4); }
		if (!/^[6-9]\d{8}$/.test(v)) { return null; }
		return v;
	}

	/**
	 * Canonical NIF/NIE: upper-case, no spaces.
	 * Does NOT validate the control letter (server does that) but
	 * returns the cleaned form.
	 */
	function nif(value) {
		if (value === null || value === undefined) { return ''; }
		return String(value).toUpperCase().replace(/\s+/g, '').trim();
	}

	return {
		titleCase: titleCase,
		email:     email,
		telefono:  telefono,
		nif:       nif,
	};
})();
