(function () {
	'use strict';

	// Consistent token keys shared with unified.js and area.js.
	const STORAGE_TOKEN_KEY = 'anpa_area_token';
	const STORAGE_EXPIRES_KEY = 'anpa_area_token_expires';

	function getAreaToken() {
		try {
			const token = localStorage.getItem(STORAGE_TOKEN_KEY);
			const expires = localStorage.getItem(STORAGE_EXPIRES_KEY);
			if (!token) {
				return null;
			}
			if (expires && parseInt(expires, 10) < Date.now()) {
				localStorage.removeItem(STORAGE_TOKEN_KEY);
				localStorage.removeItem(STORAGE_EXPIRES_KEY);
				return null;
			}
			return token;
		} catch (e) {
			return null;
		}
	}

	function parseConfig() {
		const el = document.getElementById('anpa-header-nav-config');
		if (!el || !el.textContent) {
			return null;
		}
		try {
			return JSON.parse(el.textContent);
		} catch (e) {
			return null;
		}
	}

	async function logout(config) {
		const token = getAreaToken();
		if (token && config.logoutUrl) {
			try {
				await fetch(config.logoutUrl, {
					method: 'DELETE',
					headers: {
						'X-Anpa-Area-Token': token,
					},
				});
			} catch (e) {
				// Ignore network errors; still clear local state.
			}
		}
		try {
			localStorage.removeItem(STORAGE_TOKEN_KEY);
			localStorage.removeItem(STORAGE_EXPIRES_KEY);
			localStorage.removeItem('anpa_session_token');
			localStorage.removeItem('anpa_session_expires');
		} catch (e) {
			// ignore
		}
		window.location.reload();
	}

	function renderDropdown(config) {
		const mount = document.getElementById('anpa-header-nav-mount');
		if (!mount) {
			return;
		}

		const details = document.createElement('details');
		details.className = 'anpa-header-nav';

		const summary = document.createElement('summary');
		summary.className = 'anpa-header-nav__toggle';
		summary.setAttribute('aria-haspopup', 'true');
		summary.setAttribute('aria-expanded', 'false');
		summary.innerHTML =
			escHtml(config.i18n.toggle || 'Socios') +
			'<span class="anpa-header-nav__caret" aria-hidden="true"></span>';

		const nav = document.createElement('nav');
		nav.className = 'anpa-header-nav__menu';
		nav.setAttribute('aria-label', config.i18n.ariaLabel || 'Socios');

		if (config.areaUrl) {
			nav.appendChild(makeLink(config.areaUrl, config.i18n.area));
		}
		if (config.baixaUrl) {
			nav.appendChild(makeLink(config.baixaUrl, config.i18n.baixa));
		}

		const logoutBtn = document.createElement('button');
		logoutBtn.className = 'anpa-header-nav__item anpa-header-nav__item--button';
		logoutBtn.type = 'button';
		logoutBtn.textContent = config.i18n.logout || 'Pechar sesión';
		logoutBtn.addEventListener('click', function (e) {
			e.preventDefault();
			logout(config);
		});
		nav.appendChild(logoutBtn);

		details.appendChild(summary);
		details.appendChild(nav);
		mount.appendChild(details);

		details.addEventListener('toggle', function () {
			summary.setAttribute('aria-expanded', String(details.open));
		});
	}

	function makeLink(href, text) {
		const a = document.createElement('a');
		a.className = 'anpa-header-nav__item';
		a.href = href;
		a.textContent = text;
		return a;
	}

	function escHtml(text) {
		const div = document.createElement('div');
		div.textContent = text;
		return div.innerHTML;
	}

	function init() {
		if (!getAreaToken()) {
			return;
		}
		const config = parseConfig();
		if (!config) {
			return;
		}
		renderDropdown(config);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	// Allow other scripts (e.g. area.js) to re-trigger the check after login.
	window.anpaHeaderNavCheck = init;
})();
