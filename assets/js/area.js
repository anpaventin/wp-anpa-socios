/**
 * Passwordless state machine for the ANPA Socios personal area.
 *
 * The area token is kept in closure memory only. It is never stored in
 * localStorage, sessionStorage, cookies, or the DOM.
 *
 * @since  1.1.0
 * @package ANPA_Socios
 */

(function () {
	'use strict';

	// Pure functions come from anpa-utils.js (loaded before area.js).
	// Fallback to inline versions for safety.
	var colLabel = (typeof AnpaUtils !== 'undefined' && AnpaUtils.colLabel) || function (k) { return k; };
	var visibleColumns = (typeof AnpaUtils !== 'undefined' && AnpaUtils.visibleColumns) || function (ks) { return ks; };
	var filterRows = (typeof AnpaUtils !== 'undefined' && AnpaUtils.filterRows) || function (r) { return r; };
	var buildCsvString = (typeof AnpaUtils !== 'undefined' && AnpaUtils.buildCsvString) || function () { return ''; };
	var isInactiveRow = (typeof AnpaUtils !== 'undefined' && AnpaUtils.isInactiveRow) || function () { return false; };

	// Idle session timeout: end an inactive session and return to login.
	// 30 min is a sensible balance for a member/admin area handling sensitive
	// data; warn 2 min before so the user can stay connected.
	var IDLE_LIMIT_MS = 30 * 60 * 1000;
	var IDLE_WARN_MS = 2 * 60 * 1000;

	function showStep(root, step) {
		root.querySelectorAll('[data-step]').forEach((el) => {
			el.hidden = el.dataset.step !== step;
		});
	}

	/**
	 * Scrolls the page to the top of the admin form element (used after
	 * the user clicks "Editar" or "Engadir" so they don't have to scroll
	 * manually). Respects prefers-reduced-motion.
	 *
	 * @since 1.20.0
	 * @param {HTMLElement} formEl The form host element to scroll to.
	 */
	function scrollToAdminForm(formEl) {
		if (!formEl) { return; }
		formEl.setAttribute('data-admin-form', '');
		var reduced = false;
		try {
			reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
		} catch (_) {}
		try {
			formEl.scrollIntoView({
				behavior: reduced ? 'auto' : 'smooth',
				block: 'start',
			});
		} catch (_) {
			formEl.scrollIntoView();
		}
		setTimeout(function() {
			var first = formEl.querySelector('input:not([type=hidden]):not([disabled]), select:not([disabled]), textarea:not([disabled])');
			if (first) { try { first.focus({ preventScroll: true }); } catch (_) { first.focus(); } }
		}, reduced ? 0 : 250);
	}

	function showMessage(root, text, type) {
		const box = root.querySelector('[data-area-message]');
		box.textContent = text || '';
		box.dataset.type = type || 'info';
		box.hidden = !text;
		if (text) {
			// The notice sits at the top of the widget and is sticky; nudging it
			// into view guarantees the user sees errors even deep inside a panel.
			box.scrollIntoView({ block: 'nearest' });
		}
	}

	// Working-overlay control (shared across all area fetches). A depth counter
	// keeps the overlay up while any request is in flight.
	let busyDepth = 0;
	function setBusyOverlay(root, on) {
		busyDepth = Math.max(0, busyDepth + (on ? 1 : -1));
		const overlay = root.querySelector('[data-busy]');
		if (overlay) {
			overlay.hidden = busyDepth === 0;
		}
	}

	async function callJson(url, options, root) {
		setBusyOverlay(root, true);
		try {
			let response;
			try {
				response = await fetch(url, options);
			} catch (_) {
				showMessage(root, 'Erro de rede. Téntao de novo.', 'error');
				return null;
			}

			if (response.status === 204) {
				return {};
			}

			let body = {};
			try {
				body = await response.json();
			} catch (_) {
				body = {};
			}

			if (!response.ok) {
				showMessage(root, body.message || 'Non foi posible completar a operación.', 'error');
				return null;
			}

			return body;
		} finally {
			setBusyOverlay(root, false);
		}
	}

	function jsonPost(url, body, root) {
		return callJson(url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(body),
		}, root);
	}

	function tokenRequest(method, url, token, body, root) {
		// Double-submit CSRF protection: for state-changing methods,
		// include the token as a URL parameter too.
		if (token && (method === 'DELETE' || method === 'POST' || method === 'PUT' || method === 'PATCH')) {
			var sep = url.indexOf('?') === -1 ? '?' : '&';
			url += sep + '_csrf=' + encodeURIComponent(token);
		}
		const options = {
			method,
			headers: {
				'Content-Type': 'application/json',
				'X-Anpa-Area-Token': token,
			},
		};
		if (body) {
			options.body = JSON.stringify(body);
		}

		return callJson(url, options, root);
	}

	function fillProfile(root, profile) {
		root.querySelector('[data-profile-email]').textContent = profile.email || '';
		root.querySelector('#anpa-area-nome').value = profile.nome || '';
		root.querySelector('#anpa-area-apelidos').value = profile.apelidos || '';
		// Reveal the admin entry only for the master role. This is a cosmetic
		// hint: every /admin/* call is independently gated server-side by
		// permission_master, so hiding the button is not the security boundary.
		const isMaster = profile.rol === 'master';
		// There are several admin-entry buttons now (profile step + the session
		// dropdown menu). Reveal them all for masters.
		root.querySelectorAll('[data-admin-entry]').forEach(function (adminBtn) {
			adminBtn.hidden = !isMaster;
		});
		// Masters don't manage fillos or extraescolares (no personal children/enrollments).
		var fillosBtn = root.querySelector('[data-action="manage-fillos"]');
		if (fillosBtn) { fillosBtn.hidden = isMaster; }
		var extraBtn = root.querySelector('[data-action="manage-extraescolares"]');
		if (extraBtn) { extraBtn.hidden = isMaster; }
		var proxBtn = root.querySelector('[data-action="toggle-proxenitor2"]');
		if (proxBtn) { proxBtn.hidden = isMaster; }
		var bankBtn = root.querySelector('[data-action="toggle-banking"]');
		if (bankBtn) { bankBtn.hidden = isMaster; }
		// Role-aware help text describing exactly what the user can do.
		const help = root.querySelector('[data-profile-help]');
		if (help) {
			if (isMaster) {
				help.textContent = 'Es administrador/a da ANPA. Ademais dos teus datos, tes acceso á Xestión ANPA (socios, empresas, actividades e administradores). Ten coidado: os cambios afectan a toda a asociación.';
				help.classList.add('anpa-area-warning');
			} else {
				help.textContent = 'Aquí podes actualizar o teu nome e apelidos, e xestionar os teus fillos/as (engadir, editar ou dar de baixa) co botón de abaixo.';
				help.classList.remove('anpa-area-warning');
			}
		}
		// Baixa request indicator + button (non-admin socios only).
		const pendingBaixa = profile.baixa_estado === 'solicitada';
		const baixaStatus = root.querySelector('[data-baixa-status]');
		if (baixaStatus) {
			if (pendingBaixa) {
				baixaStatus.textContent = 'Tes unha solicitude de baixa pendente de confirmación pola directiva. A baixa será efectiva a fin de curso.';
				baixaStatus.hidden = false;
			} else {
				baixaStatus.textContent = '';
				baixaStatus.hidden = true;
			}
		}
		const baixaBtn = root.querySelector('[data-baixa-btn]');
		if (baixaBtn) {
			baixaBtn.hidden = isMaster || pendingBaixa;
		}
		const baixaCancelBtn = root.querySelector('[data-baixa-cancel-btn]');
		if (baixaCancelBtn) {
			baixaCancelBtn.hidden = !pendingBaixa;
		}
	}

	document.addEventListener('DOMContentLoaded', () => {
		const root = document.getElementById('anpa-area');
		if (!root) {
			return;
		}

		// Safe single-element binding: a missing node (markup/JS drift) must
		// never throw and break the wiring of every handler defined after it.
		function bind(selector, event, handler) {
			const el = root.querySelector(selector);
			if (el) {
				el.addEventListener(event, handler);
			}
		}

		let email = '';
		let fase1Token = '';
		let areaToken = '';
		let empresaToken = '';

		// ── Persistent session helpers (localStorage) ───────────────────
		// The token is stored client-side so the user can reload the page
		// without re-entering a code. It is a high-entropy bearer token bound
		// to User-Agent and TTL on the server. localStorage is acceptable
		// because the token is already scoped to the device/browser.
		const AREA_TOKEN_KEY = 'anpa_area_token';
		const AREA_TOKEN_EXPIRES_KEY = 'anpa_area_token_expires';

		function saveAreaToken(token, expiresInSeconds) {
			try {
				localStorage.setItem(AREA_TOKEN_KEY, token);
				const expiresAt = expiresInSeconds ? Date.now() + expiresInSeconds * 1000 : 0;
				localStorage.setItem(AREA_TOKEN_EXPIRES_KEY, String(expiresAt));
			} catch (_) {
				// localStorage can be unavailable in private mode; session
				// will simply not persist across page loads.
			}
		}

		function loadAreaToken() {
			try {
				const token = localStorage.getItem(AREA_TOKEN_KEY);
				const expires = localStorage.getItem(AREA_TOKEN_EXPIRES_KEY);
				if (!token) { return null; }
				if (expires && Date.now() > parseInt(expires, 10)) {
					clearAreaToken();
					return null;
				}
				return token;
			} catch (_) {
				return null;
			}
		}

		function clearAreaToken() {
			try {
				localStorage.removeItem(AREA_TOKEN_KEY);
				localStorage.removeItem(AREA_TOKEN_EXPIRES_KEY);
			} catch (_) {}
		}

		// ── Idle session timeout ────────────────────────────────────────
		let idleWarnTimer = null;
		let idleExpireTimer = null;
		let idleCountdown = null;
		let idleLastReset = 0;

		function sessionActive() {
			return !!(areaToken || empresaToken);
		}

		function clearIdleTimers() {
			if (idleWarnTimer) { clearTimeout(idleWarnTimer); idleWarnTimer = null; }
			if (idleExpireTimer) { clearTimeout(idleExpireTimer); idleExpireTimer = null; }
			if (idleCountdown) { clearInterval(idleCountdown); idleCountdown = null; }
			const w = root.querySelector('[data-idle-warning]');
			if (w) { w.hidden = true; }
		}

		function startIdleTimers() {
			clearIdleTimers();
			if (!sessionActive()) { return; }
			idleWarnTimer = setTimeout(showIdleWarning, Math.max(0, IDLE_LIMIT_MS - IDLE_WARN_MS));
			idleExpireTimer = setTimeout(idleExpire, IDLE_LIMIT_MS);
		}

		function showIdleWarning() {
			const box = root.querySelector('[data-idle-warning]');
			const txt = root.querySelector('[data-idle-text]');
			if (!box || !txt) { return; }
			let remaining = Math.round(IDLE_WARN_MS / 1000);
			const render = () => {
				txt.textContent = 'A túa sesión pechará en ' + remaining + ' s por inactividade. '
					+ 'Navega, fai unha acción ou preme «Seguir conectado» para continuar.';
			};
			render();
			box.hidden = false;
			idleCountdown = setInterval(() => {
				remaining -= 1;
				if (remaining <= 0) { clearInterval(idleCountdown); idleCountdown = null; return; }
				render();
			}, 1000);
		}

		async function idleExpire() {
			clearIdleTimers();
			const wasArea = !!areaToken;
			const wasEmpresa = !!empresaToken;
			try {
				if (wasArea) {
					await tokenRequest('DELETE', root.dataset.logoutUrl, areaToken, null, root);
				} else if (wasEmpresa) {
					await empresaCallChecked('DELETE', root.dataset.empresaLogoutUrl, null);
				}
			} catch (_) {
				// best-effort revocation; proceed to local logout regardless.
			}
			areaToken = '';
			empresaToken = '';
			fase1Token = '';
			clearAreaToken();
			root.classList.remove('anpa-area-wide');
			hideSessionHeader();
			showStep(root, 'email');
			showMessage(root, 'A túa sesión pechouse por inactividade.', 'info');
		}

		// Reset the idle timer on genuine activity (throttled to once / 3s).
		function onIdleActivity() {
			if (!sessionActive()) { return; }
			const now = Date.now();
			if (now - idleLastReset < 3000) { return; }
			idleLastReset = now;
			startIdleTimers();
		}

		['click', 'keydown', 'touchstart', 'mousemove', 'scroll'].forEach((evt) => {
			root.addEventListener(evt, onIdleActivity, { passive: true });
		});

		bind('[data-action="idle-stay"]', 'click', () => {
			startIdleTimers();
		});

		// ── Session header (logged-in indicator + logout) ───────────────
		function showSessionHeader(emailValue) {
			const who = root.querySelector('[data-session-email]');
			if (who) { who.textContent = emailValue || ''; }
			const hdr = root.querySelector('[data-session-header]');
			if (hdr) { hdr.hidden = false; }
		}

		function hideSessionHeader() {
			const hdr = root.querySelector('[data-session-header]');
			if (hdr) { hdr.hidden = true; }
		}

		bind('[data-action="header-logout"]', 'click', async () => {
			if (areaToken) {
				await tokenRequest('DELETE', root.dataset.logoutUrl, areaToken, null, root);
				areaToken = '';
			} else if (empresaToken) {
				await empresaCallChecked('DELETE', root.dataset.empresaLogoutUrl, null);
				empresaToken = '';
			}
			fase1Token = '';
			clearAreaToken();
			clearIdleTimers();
			hideSessionHeader();
			root.classList.remove('anpa-area-wide');
			showStep(root, 'email');
			showMessage(root, 'Sesión pechada.', 'success');
		});

		// ── Session dropdown menu shortcuts (PR-12l part 2) ──────────────
		// These live inside the green session-header <details> and act as
		// "remote controls" that click the already-wired buttons, so we never
		// duplicate a data-action (bind() uses first-match) nor its logic.
		function closeSessionMenu() {
			var menu = root.querySelector('[data-session-menu]');
			if (menu) { menu.open = false; }
		}

		bind('[data-action="header-area"]', 'click', () => {
			closeSessionMenu();
			showMessage(root, '', 'info');
			showStep(root, 'profile');
		});

		bind('[data-action="header-admin"]', 'click', () => {
			closeSessionMenu();
			var b = root.querySelector('[data-action="open-admin"]');
			if (b) { b.click(); }
		});

		bind('[data-action="header-exports"]', 'click', () => {
			closeSessionMenu();
			pendingAdminPanel = 'exports';
			var b = root.querySelector('[data-action="open-admin"]');
			if (b) { b.click(); }
		});

		bind('[data-action="header-fullexport"]', 'click', () => {
			closeSessionMenu();
			pendingAdminPanel = 'fullexport';
			var b = root.querySelector('[data-action="open-admin"]');
			if (b) { b.click(); }
		});

		bind('[data-action="request-code"]', 'click', async () => {
			showMessage(root, '', 'info');
			email = (root.querySelector('#anpa-area-email').value || '').trim();
			if (!email) {
				showMessage(root, 'Introduce o teu email.', 'error');
				return;
			}

			// Read anti-bot fields from the form.
			const website = (root.querySelector('#anpa-area-website') || {}).value || '';
			const _ts = (root.querySelector('#anpa-area-ts') || {}).value || '';

			// Preflight decides which flow to enter. The server returns
			// a generic 200 either way; the JS uses the opaque `next`
			// value to choose the right step.
			const preflight = await jsonPost(root.dataset.preflightUrl, { email, website, _ts }, root);
			const next = (preflight && preflight.next) || 'alta';

			if (next === 'inactivo') {
				showStep(root, 'inactivo');
				return;
			}

			if (next === 'empresa') {
				// Empresa flow: dedicated code-request endpoint that checks
				// empresa table instead of socios, then same verify → Fase-1
				// token, then POST /empresa/session.
				const empresaCodeUrl = root.dataset.empresaRequestCodeUrl || root.dataset.requestCodeUrl;
				const result = await jsonPost(empresaCodeUrl, { email, website, _ts }, root);
				if (result) {
					showStep(root, 'code');
					root.dataset.empresaFlow = '1';
					showMessage(root, 'Se o email é válido, recibirás un código en breve.', 'success');
				}
				return;
			}

			// 'area' or 'alta': same UX (ask for a code), different
			// copy once the user sees the code step. We let the
			// server route the email appropriately.
			const result = await jsonPost(root.dataset.requestCodeUrl, { email }, root);
			if (result) {
				showStep(root, 'code');
				if (next === 'alta') {
					showMessage(root, 'Se o email é válido, recibirás un código. Tras introducilo, pedirémosche os teus datos para completar a alta.', 'success');
				} else if (next === 'baixa_pendente') {
					showMessage(root, 'Tes unha solicitude de baixa pendente. Inicia sesión coa clave que che enviamos para xestionala (poderás anulala desde a túa área).', 'success');
				} else {
					showMessage(root, 'Se o email é válido, recibirás un código en breve.', 'success');
				}
			}
		});

		bind('[data-action="back-email"]', 'click', () => {
			fase1Token = '';
			areaToken = '';
			empresaToken = '';
			root.dataset.empresaFlow = '';
			clearIdleTimers();
			hideSessionHeader();
			root.classList.remove('anpa-area-wide');
			showMessage(root, '', 'info');
			showStep(root, 'email');
		});

		bind('[data-action="request-reactivar"]', 'click', async () => {
			showMessage(root, '', 'info');
			if (!email) {
				showStep(root, 'email');
				showMessage(root, 'Introduce o teu email.', 'error');
				return;
			}
			const website = (root.querySelector('#anpa-area-website') || {}).value || '';
			const _ts = (root.querySelector('#anpa-area-ts') || {}).value || '';
			const result = await jsonPost(root.dataset.reactivarUrl, { email, website, _ts }, root);
			if (result) {
				showMessage(root, result.message || 'Solicitude de reactivación enviada.', 'success');
			}
		});

		bind('[data-action="verify-code"]', 'click', async () => {
			showMessage(root, '', 'info');
			const codigo = (root.querySelector('#anpa-area-code').value || '').trim();
			if (!email || !codigo) {
				showMessage(root, 'Introduce o email e o código.', 'error');
				return;
			}

			const verified = await jsonPost(root.dataset.verifyCodeUrl, { email, codigo }, root);
			if (!verified || verified.success !== true || !verified.token) {
				if (verified) {
					showMessage(root, verified.message || 'Código incorrecto.', 'error');
				}
				return;
			}

			fase1Token = verified.token;

			// Branch: empresa flow uses a different session endpoint.
			if (root.dataset.empresaFlow === '1') {
				root.dataset.empresaFlow = '';
				const session = await jsonPost(root.dataset.empresaSessionUrl, { token: fase1Token }, root);
				if (!session || !session.session_token) {
					return;
				}
				empresaToken = session.session_token;
				fase1Token = '';
				await loadEmpresaPanel();
				return;
			}

			const session = await jsonPost(root.dataset.sessionUrl, { token: fase1Token }, root);
			if (!session || !session.session_token) {
				return;
			}

			areaToken = session.session_token;
			fase1Token = '';
			saveAreaToken(areaToken, session.expires_in || 86400);

			const profile = await tokenRequest('GET', root.dataset.profileUrl, areaToken, null, root);
			if (profile) {
				fillProfile(root, profile);
				startIdleTimers();
				showSessionHeader(profile.email || '');
				if (typeof window.anpaHeaderNavCheck === 'function') {
					window.anpaHeaderNavCheck();
				}
				// Admins land directly in the management panel; everyone else in profile.
				if (profile.rol === 'master') {
					await openAdminFlow();
				} else {
					showStep(root, 'profile');
					showMessage(root, '', 'info');
				}
				return;
			}

			// If profile fetch failed but we have a token, stay on email step with a clear message.
			areaToken = '';
			clearAreaToken();
			showStep(root, 'email');
			showMessage(root, 'Non foi posible cargar o perfil. Téntao de novo.', 'error');
			return;
		});

		bind('[data-action="save-profile"]', 'click', async () => {
			showMessage(root, '', 'info');
			if (!areaToken) {
				showStep(root, 'email');
				showMessage(root, 'A sesión caducou. Volve entrar.', 'error');
				return;
			}

			const nome = root.querySelector('#anpa-area-nome').value || '';
			const apelidos = root.querySelector('#anpa-area-apelidos').value || '';
			const profile = await tokenRequest('PUT', root.dataset.profileUrl, areaToken, { nome, apelidos }, root);
			if (profile) {
				fillProfile(root, profile);
				showMessage(root, 'Datos gardados correctamente.', 'success');
			}
		});

		bind('[data-action="logout"]', 'click', async () => {
			if (areaToken) {
				await tokenRequest('DELETE', root.dataset.logoutUrl, areaToken, null, root);
			}
			areaToken = '';
			fase1Token = '';
			clearAreaToken();
			clearIdleTimers();
			hideSessionHeader();
			root.classList.remove('anpa-area-wide');
			showStep(root, 'email');
			showMessage(root, 'Sesión pechada.', 'success');
		});

		bind('[data-action="request-baixa"]', 'click', async () => {
			showMessage(root, '', 'info');
			if (!areaToken) {
				showStep(root, 'email');
				showMessage(root, 'A sesión caducou. Volve entrar.', 'error');
				return;
			}
			const warning = 'Vas solicitar a baixa como socio/a.\n\n'
				+ '\u2022 A baixa será efectiva a FIN DE CURSO, tras a confirmación da directiva.\n'
				+ '\u2022 A cota anual do curso xa xerada NON se devolve.\n'
				+ '\u2022 Avisarase á directiva da túa solicitude.\n\n'
				+ 'Queres continuar?';
			if (!window.confirm(warning)) {
				return;
			}
			const result = await tokenRequest('POST', root.dataset.baixaUrl, areaToken, {}, root);
			if (result) {
				showMessage(root, result.message || 'Solicitude de baixa rexistrada.', 'success');
				const profile = await tokenRequest('GET', root.dataset.profileUrl, areaToken, null, root);
				if (profile) {
					fillProfile(root, profile);
				}
			}
		});

		bind('[data-action="cancel-baixa"]', 'click', async () => {
			showMessage(root, '', 'info');
			if (!areaToken) {
				showStep(root, 'email');
				showMessage(root, 'A sesión caducou. Volve entrar.', 'error');
				return;
			}
			const result = await tokenRequest('POST', root.dataset.baixaCancelUrl, areaToken, {}, root);
			if (result) {
				showMessage(root, result.message || 'Solicitude de baixa anulada.', 'success');
				const profile = await tokenRequest('GET', root.dataset.profileUrl, areaToken, null, root);
				if (profile) {
					fillProfile(root, profile);
				}
			}
		});

		// ── Fillos management ───────────────────────────────────────────

		const fillosListEl = root.querySelector('[data-fillos-list]');
		const filloEditIdEl = root.querySelector('[data-fillo-edit-id]');
		const filloFormTitleEl = root.querySelector('[data-fillos-form-title]');
		const cancelEditBtn = root.querySelector('[data-action="cancel-fillo-edit"]');

		function readFilloForm() {
			return {
				nome: (root.querySelector('#anpa-fillo-nome').value || '').trim(),
				apelidos: (root.querySelector('#anpa-fillo-apelidos').value || '').trim(),
				data_nacemento: (root.querySelector('#anpa-fillo-data').value || '').trim(),
				curso: (root.querySelector('#anpa-fillo-curso').value || '').trim(),
				aula: (root.querySelector('#anpa-fillo-aula').value || '').trim(),
			};
		}

		function resetFilloForm() {
			filloEditIdEl.value = '';
			root.querySelector('#anpa-fillo-nome').value = '';
			root.querySelector('#anpa-fillo-apelidos').value = '';
			root.querySelector('#anpa-fillo-data').value = '';
			root.querySelector('#anpa-fillo-curso').value = '';
			root.querySelector('#anpa-fillo-aula').value = '';
			filloFormTitleEl.textContent = 'Engadir fillo/a';
			cancelEditBtn.hidden = true;
		}

		function fillFilloForm(fillo) {
			filloEditIdEl.value = String(fillo.id);
			root.querySelector('#anpa-fillo-nome').value = fillo.nome || '';
			root.querySelector('#anpa-fillo-apelidos').value = fillo.apelidos || '';
			root.querySelector('#anpa-fillo-data').value = fillo.data_nacemento || '';
			root.querySelector('#anpa-fillo-curso').value = fillo.curso || '';
			root.querySelector('#anpa-fillo-aula').value = fillo.aula || '';
			filloFormTitleEl.textContent = 'Editar fillo/a';
			cancelEditBtn.hidden = false;
		}

		function renderFillos(list) {
			fillosListEl.textContent = '';
			if (!Array.isArray(list) || list.length === 0) {
				const empty = document.createElement('p');
				empty.className = 'anpa-area-muted';
				empty.textContent = 'Aínda non engadiches ningún fillo/a.';
				fillosListEl.appendChild(empty);
				return;
			}

			list.forEach((fillo) => {
				const row = document.createElement('div');
				row.className = 'anpa-fillo-row';

				const info = document.createElement('span');
				info.className = 'anpa-fillo-info';
				const detail = [fillo.curso, fillo.aula].filter(Boolean).join(' · ');
				info.textContent = (fillo.nome || '') + ' ' + (fillo.apelidos || '') + (detail ? ' (' + detail + ')' : '');
				row.appendChild(info);

				const editBtn = document.createElement('button');
				editBtn.type = 'button';
				editBtn.className = 'anpa-area-secondary';
				editBtn.dataset.filloEdit = String(fillo.id);
				editBtn.textContent = 'Editar';
				row.appendChild(editBtn);

				const delBtn = document.createElement('button');
				delBtn.type = 'button';
				delBtn.className = 'anpa-area-secondary';
				delBtn.dataset.filloDelete = String(fillo.id);
				delBtn.dataset.action = 'deactivate';
			delBtn.textContent = 'Desactivar';
				row.appendChild(delBtn);

				fillosListEl.appendChild(row);
			});
		}

		async function loadFillos() {
			if (!areaToken) {
				showStep(root, 'email');
				showMessage(root, 'A sesión caducou. Volve entrar.', 'error');
				return;
			}
			const list = await tokenRequest('GET', root.dataset.fillosUrl, areaToken, null, root);
			if (list) {
				renderFillos(list);
			}
		}

		bind('[data-action="manage-fillos"]', 'click', async () => {
			showMessage(root, '', 'info');
			resetFilloForm();
			showStep(root, 'fillos');
			await loadFillos();
		});

		// ── Extraescolares (socio self-enrolment, fase7 PR-7d) ───────────
		const EXTRA_DIA_LABELS = { luns: 'Luns', martes: 'Martes', mercores: 'Mércores', xoves: 'Xoves', venres: 'Venres' };
		const EXTRA_RANGE_LABELS = { '1-2-3': '1º-2º-3º', '4-5-6': '4º-5º-6º' };
		const EXTRA_ESTADO_LABELS = {
			activo: 'Matriculado/a',
			lista_espera: 'En lista de espera',
			oferta: 'Oferta de praza pendente',
			baixa_solicitada: 'Baixa solicitada',
			baixa: 'Baixa',
		};

		function extraDiasText(csv) {
			return String(csv || '').split(',').filter(Boolean).map((d) => EXTRA_DIA_LABELS[d] || d).join(', ');
		}

		bind('[data-action="manage-extraescolares"]', 'click', async () => {
			showMessage(root, '', 'info');
			showStep(root, 'extraescolares');
			await loadExtraescolares();
		});

		bind('[data-action="extra-back"]', 'click', () => {
			showMessage(root, '', 'info');
			showStep(root, 'profile');
		});

		async function loadExtraescolares() {
			const matsEl = root.querySelector('[data-extra-matriculas]');
			const enrolEl = root.querySelector('[data-extra-enrol]');
			if (!matsEl || !enrolEl) { return; }
			matsEl.textContent = '';
			enrolEl.textContent = '';

			const mats = await tokenRequest('GET', root.dataset.extraMatriculasUrl, areaToken, null, root);
			renderMatriculas(matsEl, Array.isArray(mats) ? mats : []);

			const fillos = await tokenRequest('GET', root.dataset.fillosUrl, areaToken, null, root);
			const fillosList = Array.isArray(fillos) ? fillos : [];
			let oferta = [];
			if (fillosList.length) {
				const sep = String(root.dataset.extraOfertaUrl || '').indexOf('?') === -1 ? '?' : '&';
				const ofertaData = await tokenRequest('GET', root.dataset.extraOfertaUrl + sep + 'fillo_id=' + encodeURIComponent(fillosList[0].id), areaToken, null, root);
				oferta = Array.isArray(ofertaData) ? ofertaData : (ofertaData && Array.isArray(ofertaData.activities) ? ofertaData.activities : []);
			}
			renderEnrolForm(enrolEl, oferta, fillosList);
		}

		function renderMatriculas(host, list) {
			if (!list.length) {
				const p = document.createElement('p');
				p.className = 'anpa-area-muted';
				p.textContent = 'Aínda non tes ningunha matrícula en extraescolares.';
				host.appendChild(p);
				return;
			}
			const ul = document.createElement('ul');
			ul.className = 'anpa-extra-mine';
			list.forEach((m) => {
				const li = document.createElement('li');
				const who = ((m.fillo_nome || '') + ' ' + (m.fillo_apelidos || '')).trim();
				const estado = EXTRA_ESTADO_LABELS[m.estado] || m.estado;
				let txt = who + ' — ' + (m.actividade || '') ;
				if (m.curso_range) { txt += ' (' + (EXTRA_RANGE_LABELS[m.curso_range] || m.curso_range) + ')'; }
				txt += ' · ' + estado;
				if (m.estado === 'lista_espera' && m.posicion) { txt += ' (posición ' + m.posicion + ')'; }
				const span = document.createElement('span');
				span.textContent = txt;
				li.appendChild(span);

				const base = root.dataset.extraMatriculaBaseUrl + encodeURIComponent(m.id);
				if (m.estado === 'oferta') {
					const acc = document.createElement('button');
					acc.type = 'button';
					acc.textContent = 'Aceptar praza';
					acc.addEventListener('click', async () => {
						const done = await tokenRequest('POST', base + '/oferta/aceptar', areaToken, {}, root);
						if (done) { showMessage(root, 'Praza aceptada.', 'success'); await loadExtraescolares(); }
					});
					li.appendChild(acc);
				}
				if (m.estado === 'activo' || m.estado === 'lista_espera' || m.estado === 'oferta') {
					const baixa = document.createElement('button');
					baixa.type = 'button';
					baixa.className = 'anpa-area-secondary anpa-area-danger';
					baixa.textContent = 'Dar de baixa';
					baixa.addEventListener('click', async () => {
						if (!window.confirm('Solicitar a baixa desta actividade?')) { return; }
						const done = await tokenRequest('POST', base + '/baixa', areaToken, {}, root);
						if (done) { showMessage(root, 'Solicitude de baixa rexistrada.', 'success'); await loadExtraescolares(); }
					});
					li.appendChild(baixa);
				}
				if (m.estado === 'baixa_solicitada') {
					const cancel = document.createElement('button');
					cancel.type = 'button';
					cancel.className = 'anpa-area-secondary';
					cancel.textContent = 'Anular baixa';
					cancel.addEventListener('click', async () => {
						const done = await tokenRequest('POST', base + '/baixa/cancel', areaToken, {}, root);
						if (done) { showMessage(root, 'Baixa anulada.', 'success'); await loadExtraescolares(); }
					});
					li.appendChild(cancel);
				}

				ul.appendChild(li);
			});
			host.appendChild(ul);
		}

		function renderEnrolForm(host, oferta, fillos) {
			if (!fillos.length) {
				const p = document.createElement('p');
				p.className = 'anpa-area-muted';
				p.textContent = 'Engade primeiro un fillo/a na sección «Xestionar fillos/as».';
				host.appendChild(p);
				return;
			}
			if (!oferta.length) {
				const p = document.createElement('p');
				p.className = 'anpa-area-muted';
				p.textContent = 'Non hai actividades dispoñibles neste momento.';
				host.appendChild(p);
				return;
			}

			const form = document.createElement('div');
			form.className = 'anpa-socio-edit';

			function addLabel(t) { const l = document.createElement('label'); l.textContent = t; form.appendChild(l); }

			addLabel('Alumno/a');
			const filloSel = document.createElement('select');
			fillos.forEach((f) => {
				const o = document.createElement('option');
				o.value = String(f.id);
				o.textContent = ((f.nome || '') + ' ' + (f.apelidos || '')).trim() + ' (curso ' + (f.curso || '?') + ')';
				o.dataset.curso = String(f.curso || '');
				filloSel.appendChild(o);
			});
			form.appendChild(filloSel);

			addLabel('Actividade');
			const actSel = document.createElement('select');
			function populateActivities() {
				actSel.textContent = '';
				if (!oferta.length) {
					const o = document.createElement('option');
					o.value = '';
					o.textContent = 'Non hai actividades dispoñibles para este alumno/a';
					actSel.appendChild(o);
					return;
				}
				oferta.forEach((a) => {
					const o = document.createElement('option');
					o.value = String(a.id);
					o.textContent = a.nome;
					actSel.appendChild(o);
				});
			}
			populateActivities();
			form.appendChild(actSel);

			addLabel('Grupo');
			const grupoSel = document.createElement('select');
			form.appendChild(grupoSel);

			function selectedCurso() {
				const opt = filloSel.options[filloSel.selectedIndex];
				return opt ? String(opt.dataset.curso || '') : '';
			}
			function cursoFits(curso, range) {
				const map = { '1-2-3': ['1', '2', '3'], '4-5-6': ['4', '5', '6'] };
				return !!map[range] && map[range].indexOf(String(curso).trim()) !== -1;
			}
			function refreshGrupos() {
				grupoSel.textContent = '';
				const act = oferta.find((a) => String(a.id) === actSel.value);
				const curso = selectedCurso();
				if (!act) { return; }
				(act.grupos || []).forEach((g) => {
					if (!cursoFits(curso, g.curso_range)) { return; }
					const o = document.createElement('option');
					o.value = String(g.id);
					o.dataset.franxa = String(g.franxa || '');
					let label = (EXTRA_RANGE_LABELS[g.curso_range] || g.curso_range) + ' · ' + (g.franxa || '') + ' · ' + extraDiasText(g.dias);
					label += g.cheo ? ' (completo — lista de espera)' : ' (' + (g.max_pupilos - g.activos) + ' prazas)';
					o.textContent = label;
					grupoSel.appendChild(o);
				});
				if (!grupoSel.options.length) {
					const o = document.createElement('option');
					o.value = '';
					o.textContent = 'Non hai grupos compatibles co curso do alumno/a';
					grupoSel.appendChild(o);
				}
				refreshAutorizacions();
			}

			function selectedGroupFranja() {
				const opt = grupoSel.options[grupoSel.selectedIndex];
				return opt ? String(opt.dataset.franxa || '') : '';
			}
			function isComedorFranja(franxa) {
				const m = String(franxa || '').match(/^(\d{2}):(\d{2})-/);
				if (!m) { return false; }
				return (parseInt(m[1], 10) * 60 + parseInt(m[2], 10)) < (16 * 60 + 10);
			}
			const authHost = document.createElement('div');
			authHost.className = 'anpa-extra-autorizacions';
			form.appendChild(authHost);

			function addRadio(name, value, label) {
				const l = document.createElement('label');
				const input = document.createElement('input');
				input.type = 'radio';
				input.name = name;
				input.value = value;
				l.appendChild(input);
				l.appendChild(document.createTextNode(' ' + label));
				authHost.appendChild(l);
			}
			function addCheckbox(name, label) {
				const l = document.createElement('label');
				const input = document.createElement('input');
				input.type = 'checkbox';
				input.name = name;
				l.appendChild(input);
				l.appendChild(document.createTextNode(' ' + label));
				authHost.appendChild(l);
				return input;
			}
			function refreshAutorizacions() {
				authHost.textContent = '';
				if (!grupoSel.value) { return; }
				const title = document.createElement('h4');
				title.textContent = 'Autorizacións';
				authHost.appendChild(title);
				if (isComedorFranja(selectedGroupFranja())) {
					const p = document.createElement('p');
					p.textContent = 'No caso de actividades realizadas durante o período de comedor:';
					authHost.appendChild(p);
					addRadio('autorizacion_comedor', 'si', 'Quero que o persoal de comedor lle facilite a participación na actividade ao meu neno/a.');
					addRadio('autorizacion_comedor', 'non', 'Non autorizo ao persoal de comedor a facilitar a participación na actividade.');
				} else {
					const p = document.createElement('p');
					p.textContent = 'No caso dunha actividade extraescolar realizada pola tarde:';
					authHost.appendChild(p);
					addRadio('tarde_transicion', 'comedor', 'O meu neno/a fai uso do servizo de comedor e quero que tralo comedor pase directamente á actividade.');
					addRadio('tarde_transicion', 'familia', 'O meu neno/a NON fai uso do servizo de comedor, polo que eu mesmo/a levarei ao neno/a á actividade.');
					addCheckbox('tardes_divertidas_continua', 'O meu neno/a fai uso do servizo de tardes divertidas e quero que cando finalice a actividade continúe en tardes divertidas.');
					addCheckbox('recollida_autorizada', 'Cando finalice a actividade eu ou unha persoa autorizada recollerá ao neno/a.');
				}
				addCheckbox('cesion_datos_empresa', 'Autorizo a que se cedan á empresa de actividades os datos necesarios para a correcta xestión da actividade extraescolar.');
			}
			filloSel.addEventListener('change', async () => {
				const sep = String(root.dataset.extraOfertaUrl || '').indexOf('?') === -1 ? '?' : '&';
				const ofertaData = await tokenRequest('GET', root.dataset.extraOfertaUrl + sep + 'fillo_id=' + encodeURIComponent(filloSel.value), areaToken, null, root);
				oferta = Array.isArray(ofertaData) ? ofertaData : (ofertaData && Array.isArray(ofertaData.activities) ? ofertaData.activities : []);
				populateActivities();
				refreshGrupos();
			});
			actSel.addEventListener('change', refreshGrupos);
			grupoSel.addEventListener('change', refreshAutorizacions);
			refreshGrupos();

			const actions = document.createElement('div');
			actions.className = 'anpa-area-actions';
			const enrolBtn = document.createElement('button');
			enrolBtn.type = 'button';
			enrolBtn.textContent = 'Matricular';
			enrolBtn.addEventListener('click', async () => {
				showMessage(root, '', 'info');
				if (!grupoSel.value) {
					showMessage(root, 'Selecciona un grupo compatible.', 'error');
					return;
				}
				const cesion = authHost.querySelector('input[name="cesion_datos_empresa"]');
				if (!cesion || !cesion.checked) {
					showMessage(root, 'É obrigatorio autorizar a cesión dos datos necesarios á empresa da actividade.', 'error');
					return;
				}
				const payload = {
					actividad_id: parseInt(actSel.value, 10) || 0,
					grupo_id: parseInt(grupoSel.value, 10) || 0,
					cesion_datos_empresa: true,
				};
				if (isComedorFranja(selectedGroupFranja())) {
					const autComedor = authHost.querySelector('input[name="autorizacion_comedor"]:checked');
					if (!autComedor) {
						showMessage(root, 'Indica se autorizas ao persoal de comedor a facilitar a participación.', 'error');
						return;
					}
					payload.autorizacion_comedor = autComedor.value;
				} else {
					const transicion = authHost.querySelector('input[name="tarde_transicion"]:checked');
					if (!transicion) {
						showMessage(root, 'Indica se o neno/a vén do comedor ou se a familia o levará á actividade.', 'error');
						return;
					}
					payload.tarde_transicion = transicion.value;
					payload.tardes_divertidas_continua = !!(authHost.querySelector('input[name="tardes_divertidas_continua"]') || {}).checked;
					payload.recollida_autorizada = !!(authHost.querySelector('input[name="recollida_autorizada"]') || {}).checked;
				}
				const url = root.dataset.extraFilloBaseUrl + encodeURIComponent(filloSel.value) + '/matricula';
				const result = await tokenRequest('POST', url, areaToken, payload, root);
				if (result) {
					const msg = (result.estado === 'lista_espera')
						? 'Matrícula en lista de espera (posición ' + (result.posicion || '?') + ').'
						: 'Matrícula confirmada.';
					showMessage(root, msg, 'success');
					await loadExtraescolares();
				}
			});
			actions.appendChild(enrolBtn);
			form.appendChild(actions);

			host.appendChild(form);
		}

		// Delegated listener: multiple panels have [data-action="back-profile"]
		// (proxenitor2, fillos, banking) but bind() uses querySelector so it
		// only catches the first one. A single delegated handler covers all.
		root.addEventListener('click', function _backProfile(ev) {
			var btn = ev.target.closest('[data-action="back-profile"]');
			if (!btn) { return; }
			showMessage(root, '', 'info');
			resetFilloForm();
			showStep(root, 'profile');
		});

		cancelEditBtn.addEventListener('click', () => {
			resetFilloForm();
			showMessage(root, '', 'info');
		});

		// ── Proxenitor2 (fase 1.20.0) ──────────────────────────────────
		bind('[data-action="toggle-proxenitor2"]', 'click', () => {
			showMessage(root, '', 'info');
			showStep(root, 'proxenitor2');
		});

		bind('[data-action="proxenitor2-add"]', 'click', async () => {
			showMessage(root, '', 'info');
			if (!areaToken) {
				showStep(root, 'email');
				showMessage(root, 'A sesión caducou. Volve entrar.', 'error');
				return;
			}

			var nome     = (root.querySelector('#anpa-area-p2-nome') || {}).value || '';
			var apelidos = (root.querySelector('#anpa-area-p2-apelidos') || {}).value || '';
			var email    = (root.querySelector('#anpa-area-p2-email') || {}).value || '';
			var nif      = (root.querySelector('#anpa-area-p2-nif') || {}).value || '';
			var telefono = (root.querySelector('#anpa-area-p2-telefono') || {}).value || '';

			if (!nome || !apelidos) {
				showMessage(root, 'Nome e apelidos son obrigatorios.', 'error');
				return;
			}
			if (!nif) {
				showMessage(root, 'O NIF/NIE é obrigatorio.', 'error');
				return;
			}

			var result = await tokenRequest('POST', root.dataset.proxenitor2AddUrl, areaToken, {
				nome: nome,
				apelidos: apelidos,
				email: email,
				nif: nif,
				telefono: telefono,
			}, root);

			if (result) {
				showMessage(root, result.message || '2º proxenitor engadido correctamente.', 'success');
				// Refresh profile to reflect new familia changes
				var profile = await tokenRequest('GET', root.dataset.profileUrl, areaToken, null, root);
				if (profile) {
					fillProfile(root, profile);
				}
			}
		});

		// ── Banking / Modificación IBAN (fase 1.20.0) ──────────────────
		// Provincia/Poboación are free-text inputs (generic: no municipality
		// dataset, no server round-trip). No-op kept so callers don't change.
		async function loadBankingReferencias() {}

		bind('[data-action="toggle-banking"]', 'click', async () => {
			showMessage(root, '', 'info');
			if (!areaToken) {
				showStep(root, 'email');
				showMessage(root, 'A sesión caducou. Volve entrar.', 'error');
				return;
			}
			showStep(root, 'banking');

			// Load provincia/poboacion dropdowns
			await loadBankingReferencias();

			// Load existing banking data
			var banking = await tokenRequest('GET', root.dataset.profileUrl + '/banking', areaToken, null, root);
			if (banking && banking.has_banking) {
				var setVal = function(id, val) {
					var el = root.querySelector('#' + id);
					if (el) { el.value = val || ''; }
				};
				setVal('anpa-bank-titular-nome', banking.titular_nome);
				setVal('anpa-bank-titular-apelidos', banking.titular_apelidos);
				setVal('anpa-bank-titular-nif', banking.titular_nif_mask);
				setVal('anpa-bank-entidade', banking.entidade_bancaria);
				setVal('anpa-bank-enderezo', banking.enderezo);
				setVal('anpa-bank-provincia', banking.provincia);
				setVal('anpa-bank-poboacion', banking.poboacion);
				setVal('anpa-bank-cp', banking.codigo_postal);
				setVal('anpa-bank-lugar-data', banking.lugar_data);

				// Show IBAN mask
				var maskEl = root.querySelector('[data-iban-mask]');
				if (maskEl && banking.iban_mascara) {
					maskEl.textContent = 'IBAN actual: ' + banking.iban_mascara;
					maskEl.hidden = false;
				}
			} else {
				var maskEl = root.querySelector('[data-iban-mask]');
				if (maskEl) { maskEl.hidden = true; }
			}
		});

		bind('[data-action="save-banking"]', 'click', async () => {
			showMessage(root, '', 'info');
			if (!areaToken) {
				showStep(root, 'email');
				showMessage(root, 'A sesión caducou. Volve entrar.', 'error');
				return;
			}

			var q = function(id) { return (root.querySelector('#' + id) || {}).value || ''; };

			var payload = {
				titular_nome: q('anpa-bank-titular-nome'),
				titular_apelidos: q('anpa-bank-titular-apelidos'),
				titular_nif: q('anpa-bank-titular-nif'),
				iban: q('anpa-bank-iban'),
				entidade_bancaria: q('anpa-bank-entidade'),
				enderezo: q('anpa-bank-enderezo'),
				provincia: q('anpa-bank-provincia'),
				poboacion: q('anpa-bank-poboacion'),
				codigo_postal: q('anpa-bank-cp'),
				autorizacion: !!(root.querySelector('#anpa-bank-autorizacion') || {}).checked,
			};

			if (!payload.iban && !payload.titular_nome && !payload.titular_nif) {
				showMessage(root, 'Para modificar os datos bancarios debes cubrir IBAN, titular e autorización.', 'error');
				return;
			}
			if (!payload.autorizacion) {
				showMessage(root, 'Debes autorizar a domiciliación dos recibos.', 'error');
				return;
			}

			var result = await tokenRequest('PUT', root.dataset.profileUrl + '/banking', areaToken, payload, root);
			if (result) {
				showMessage(root, result.message || 'Datos bancarios actualizados correctamente.', 'success');
				// Reload banking to show updated mask/fields
				var banking = await tokenRequest('GET', root.dataset.profileUrl + '/banking', areaToken, null, root);
				if (banking && banking.has_banking) {
					var maskEl = root.querySelector('[data-iban-mask]');
					if (maskEl && banking.iban_mascara) {
						maskEl.textContent = 'IBAN actual: ' + banking.iban_mascara;
						maskEl.hidden = false;
					}
					// Clear the IBAN input for security (mask is shown instead)
					var ibanInput = root.querySelector('#anpa-bank-iban');
					if (ibanInput) { ibanInput.value = ''; }
				}
			}
		});

		bind('[data-action="save-fillo"]', 'click', async () => {
			showMessage(root, '', 'info');
			if (!areaToken) {
				showStep(root, 'email');
				showMessage(root, 'A sesión caducou. Volve entrar.', 'error');
				return;
			}

			const data = readFilloForm();
			if (!data.nome || !data.apelidos || !data.data_nacemento || !data.curso || !data.aula) {
				showMessage(root, 'Completa todos os campos do fillo/a.', 'error');
				return;
			}

			const editId = (filloEditIdEl.value || '').trim();
			let result;
			if (editId) {
				result = await tokenRequest('PATCH', root.dataset.filloUrl + encodeURIComponent(editId), areaToken, data, root);
			} else {
				result = await tokenRequest('POST', root.dataset.fillosUrl, areaToken, data, root);
			}

			if (result) {
				resetFilloForm();
				showMessage(root, 'Datos do fillo/a gardados.', 'success');
				await loadFillos();
			}
		});

		fillosListEl.addEventListener('click', async (event) => {
			const target = event.target;
			if (!(target instanceof HTMLElement)) {
				return;
			}

			const editId = target.dataset.filloEdit;
			if (editId) {
				const list = await tokenRequest('GET', root.dataset.fillosUrl, areaToken, null, root);
				if (Array.isArray(list)) {
					const fillo = list.find((f) => String(f.id) === editId);
					if (fillo) {
						fillFilloForm(fillo);
						showMessage(root, '', 'info');
					}
				}
				return;
			}

			const deleteId = target.dataset.filloDelete;
			if (deleteId) {
				if (!window.confirm('Seguro que queres dar de baixa este fillo/a?')) {
					return;
				}
				const done = await tokenRequest('DELETE', root.dataset.filloUrl + encodeURIComponent(deleteId), areaToken, null, root);
				if (done) {
					showMessage(root, 'Fillo/a dado de baixa.', 'success');
					resetFilloForm();
					await loadFillos();
				}
			}
		});

		// ── Admin panel (master only; server-gated per call) ─────────────

		const adminContentEl = root.querySelector('[data-admin-content]');
		async function openAdminFlow() {
			showMessage(root, '', 'info');
			const initUrl = root.dataset.masterInitUrl;
			if (!initUrl) {
				showMessage(root, 'Erro de configuración (falta URL de inicialización).', 'error');
				return;
			}
			const initStatus = await tokenRequest('GET', initUrl, areaToken, null, root);
			if (!initStatus) { return; }
			if (!initStatus.initialized) {
				const aviso = root.querySelector('[data-master-aviso]');
				if (aviso && initStatus.aviso_master) {
					aviso.textContent = initStatus.aviso_master;
				}
				const hint = root.querySelector('[data-passphrase-hint]');
				if (hint && initStatus.generated_passphrase) {
					hint.textContent = 'Contrasinal suxerido: ' + initStatus.generated_passphrase;
				}
				const inp = document.getElementById('anpa-master-passphrase');
				if (inp && initStatus.generated_passphrase) { inp.value = initStatus.generated_passphrase; }
				showStep(root, 'master-init');
				return;
			}
			adminContentEl.textContent = '';
			closeAdminPanels();
			root.classList.add('anpa-area-wide');
			if (initStatus.admin_password_set) {
				showStep(root, 'admin-auth');
			} else {
				showStep(root, 'admin');
				await loadAdminSection('socios');
			}
		}

		// Cache the last socios list so the inline edit form can be populated
		// without a second round-trip (and without putting the email in a URL).
		let lastSociosRows = [];
		let sociosSort = { key: 'email', dir: 'asc' };
		let sociosPage = 1;
		let sociosSize = 10;

		// ── Show/hide inactivos filter state per section ────────────
		const showInactivos = { socios: false, empresas: false, actividades: false, admins: false };

		// Returns true if a row is considered inactive for filtering purposes.
		// Builds a filter toolbar: "Mostrar inactivos ☐  (X activos de Y total)"
		function buildFilterBar(section, rows) {
			const bar = document.createElement('div');
			bar.className = 'anpa-filter-bar';
			const active = rows.filter((r) => !isInactiveRow(section, r)).length;
			const total = rows.length;
			const label = document.createElement('label');
			label.className = 'anpa-filter-check';
			const cb = document.createElement('input');
			cb.type = 'checkbox';
			cb.checked = showInactivos[section];
			cb.addEventListener('change', () => {
				showInactivos[section] = cb.checked;
				loadAdminSection(section);
			});
			label.appendChild(cb);
			label.appendChild(document.createTextNode(' Mostrar inactivos'));
			bar.appendChild(label);
			const count = document.createElement('span');
			count.className = 'anpa-area-muted';
			count.textContent = `  (${active} activos de ${total} total)`;
			bar.appendChild(count);

			// Search/filter input: filters by any visible column value.
			var searchInput = document.createElement('input');
			searchInput.type = 'text';
			searchInput.placeholder = 'Buscar\u2026';
			searchInput.style.marginLeft = 'auto';
			searchInput.style.maxWidth = '220px';
			searchInput.style.padding = '0.3rem 0.6rem';
			searchInput.style.border = '1px solid var(--theme-border-color)';
			searchInput.style.borderRadius = '6px';
			searchInput.style.fontSize = '0.9rem';
			searchInput.setAttribute('data-search', section);
			bar.appendChild(searchInput);

			return bar;
		}

		/**
		 * Returns a client-side CSV string from the given rows and visible columns.
		 * @deprecated Moved to AnpaUtils.buildCsvString (anpa-utils.js).
		 */
		function buildCsvString(rows, cols) {
			return (typeof AnpaUtils !== 'undefined' && AnpaUtils.buildCsvString)
				? AnpaUtils.buildCsvString(rows, cols) : '';
		}

		/** Triggers a CSV file download. */
		function downloadCsv(filename, csv) {
			var bom = '\uFEFF';
			var blob = new Blob([bom + csv], { type: 'text/csv;charset=utf-8;' });
			var url = URL.createObjectURL(blob);
			var a = document.createElement('a');
			a.href = url;
			a.download = filename;
			document.body.appendChild(a);
			a.click();
			document.body.removeChild(a);
			URL.revokeObjectURL(url);
		}

		/**
		 * Adds a CSV export button next to the filter bar.
		 * The button preps a CSV from the currently displayed rows.
		 */
		function addExportCsvButton(container, section, rows, cols) {
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'anpa-area-secondary';
			btn.textContent = 'Exportar CSV';
			btn.style.fontSize = '0.85rem';
			btn.style.padding = '0.3rem 0.7rem';
			btn.addEventListener('click', function() {
				var csv = buildCsvString(rows, cols);
				downloadCsv('anpa-' + section + '.csv', csv);
			});
			container.appendChild(btn);
		}

		function adminUrl(path) {
			return (root.dataset.adminBaseUrl || '') + path;
		}

		function renderAdminTable(rows) {
			adminContentEl.textContent = '';
			if (!Array.isArray(rows) || rows.length === 0) {
				const empty = document.createElement('p');
				empty.className = 'anpa-area-muted';
				empty.textContent = 'Sen resultados.';
				adminContentEl.appendChild(empty);
				return;
			}

			const columns = Object.keys(rows[0]);
			const table = document.createElement('table');
			table.className = 'anpa-admin-table';

			const thead = document.createElement('thead');
			const headRow = document.createElement('tr');
			columns.forEach((col) => {
				const th = document.createElement('th');
				th.textContent = col;
				headRow.appendChild(th);
			});
			thead.appendChild(headRow);
			table.appendChild(thead);

			const tbody = document.createElement('tbody');
			rows.forEach((row) => {
				const tr = document.createElement('tr');
				columns.forEach((col) => {
					const td = document.createElement('td');
					const value = row[col];
					td.textContent = value === null || value === undefined ? '' : String(value);
					tr.appendChild(td);
				});
				tbody.appendChild(tr);
			});
			table.appendChild(tbody);

			adminContentEl.appendChild(table);
		}

		// Renders the socios list with sortable headers, estado row colours,
		// pagination, and an inline "Editar" action per row.
		const SOCIOS_COLUMNS = ['email', 'nome', 'apelidos', 'telefono', 'nif', 'estado', 'rol'];

		var sociosSearchTimer = null;

		function renderSociosTable(rows) {
			lastSociosRows = Array.isArray(rows) ? rows : [];
			sociosPage = 1;
			renderSociosView();

			// Fetch stats asynchronously and show warning if incomplete data.
			tokenRequest('GET', adminUrl('socios/stats'), areaToken, null, root).then(function(stats) {
				if (!stats) { return; }
				var warningEl = root.querySelector('#anpa-socios-stats-warning');
				if (warningEl) { warningEl.remove(); }
				var msgs = [];
				if (stats.sem_telefono > 0) { msgs.push(stats.sem_telefono + ' sen tel\u00E9fono'); }
				if (stats.sem_nif > 0) { msgs.push(stats.sem_nif + ' sen NIF'); }
				if (msgs.length > 0) {
					warningEl = document.createElement('div');
					warningEl.id = 'anpa-socios-stats-warning';
					warningEl.className = 'anpa-area-notice';
					warningEl.setAttribute('data-type', 'error');
					warningEl.textContent = 'Socios activos con datos incompletos: ' + msgs.join(', ') + '. Podes editalos premendo no email.';
					adminContentEl.insertBefore(warningEl, adminContentEl.firstChild);
				}
			}).catch(function() {});
		}

		function renderSociosView() {
			adminContentEl.textContent = '';
			const allFiltered = lastSociosRows.filter((r) => showInactivos.socios || !isInactiveRow('socios', r));
			const all = allFiltered;
			if (!all.length) {
				const empty = document.createElement('p');
				empty.className = 'anpa-area-muted';
				empty.textContent = 'Sen resultados.';
				adminContentEl.appendChild(empty);
				return;
			}

			var filterBar = buildFilterBar('socios', lastSociosRows);
			adminContentEl.appendChild(filterBar);
			addExportCsvButton(filterBar, 'socios', all, SOCIOS_COLUMNS);

			// Read the current search query (might be empty by default).
			var searchInput = filterBar.querySelector('[data-search="socios"]');
			var query = searchInput ? searchInput.value : '';

			const tbl = window.AnpaAdminTable;
			var filtered = filterRows(all, query, SOCIOS_COLUMNS);
			const sorted = tbl ? tbl.sortRows(filtered, sociosSort.key, sociosSort.dir) : filtered.slice();
			const total = sorted.length;
			const paginate = !!tbl && total > 10;
			const size = paginate ? sociosSize : 0;
			const pageRows = tbl ? tbl.pageSlice(sorted, sociosPage, size) : sorted;

			const table = document.createElement('table');
			table.className = 'anpa-admin-table';

			const thead = document.createElement('thead');
			const headRow = document.createElement('tr');
			SOCIOS_COLUMNS.forEach((col) => {
				const th = document.createElement('th');
				th.className = 'anpa-sortable';
				var label = colLabel(col) || col;
				if (sociosSort.key === col) {
					label += sociosSort.dir === 'asc' ? ' \u25B2' : ' \u25BC';
				}
				th.textContent = label;
				th.addEventListener('click', () => {
					if (sociosSort.key === col) {
						sociosSort.dir = sociosSort.dir === 'asc' ? 'desc' : 'asc';
					} else {
						sociosSort.key = col;
						sociosSort.dir = 'asc';
					}
					sociosPage = 1;
					renderSociosView();
				});
				headRow.appendChild(th);
			});
			headRow.appendChild(document.createElement('th'));
			thead.appendChild(headRow);
			table.appendChild(thead);

			const tbody = document.createElement('tbody');
			pageRows.forEach((row) => {
				const tr = document.createElement('tr');
				if (row.estado === 'baixa') {
					tr.classList.add('anpa-row-baixa');
				} else if (row.estado === 'pendiente_alta') {
					tr.classList.add('anpa-row-pendente');
				} else if (row.baixa_estado === 'solicitada') {
					tr.classList.add('anpa-row-baixa-pending');
				}
				SOCIOS_COLUMNS.forEach((col) => {
					const td = document.createElement('td');
					let value = row[col];
					value = value === null || value === undefined ? '' : String(value);
					if (col === 'estado' && row.baixa_estado === 'solicitada' && row.estado !== 'baixa') {
						value = value + ' \u00B7 baixa solicitada';
					}
					td.textContent = value;
					tr.appendChild(td);
				});
				const actions = document.createElement('td');
				const editBtn = document.createElement('button');
				editBtn.type = 'button';
				editBtn.className = 'anpa-area-secondary';
				editBtn.dataset.socioEdit = String(row.email || '');
				editBtn.textContent = 'Editar';
				actions.appendChild(editBtn);
				tr.appendChild(actions);
				tbody.appendChild(tr);
			});
			table.appendChild(tbody);
			adminContentEl.appendChild(table);

			if (paginate) {
				adminContentEl.appendChild(buildSociosPagination(total));
			}

			// Wire up the search input for live filtering.
			if (searchInput) {
				searchInput.addEventListener('input', function() {
					if (sociosSearchTimer) { clearTimeout(sociosSearchTimer); }
					sociosSearchTimer = setTimeout(function() {
						sociosPage = 1;
						renderSociosView();
					}, 250);
				});
			}
		}

		function buildSociosPagination(total) {
			const sizeForCount = sociosSize > 0 ? sociosSize : total;
			const pages = Math.max(1, Math.ceil(total / sizeForCount));
			if (sociosPage > pages) { sociosPage = pages; }

			const bar = document.createElement('div');
			bar.className = 'anpa-admin-pagination';

			const sizeLabel = document.createElement('label');
			sizeLabel.textContent = 'Por páxina: ';
			const sizeSel = document.createElement('select');
			[['10', '10'], ['50', '50'], ['100', '100'], ['0', 'Todos']].forEach((opt) => {
				const o = document.createElement('option');
				o.value = opt[0];
				o.textContent = opt[1];
				if (String(sociosSize) === opt[0]) { o.selected = true; }
				sizeSel.appendChild(o);
			});
			sizeSel.addEventListener('change', () => {
				sociosSize = parseInt(sizeSel.value, 10) || 0;
				sociosPage = 1;
				renderSociosView();
			});
			sizeLabel.appendChild(sizeSel);
			bar.appendChild(sizeLabel);

			const prev = document.createElement('button');
			prev.type = 'button';
			prev.className = 'anpa-area-secondary';
			prev.textContent = 'Anterior';
			prev.disabled = sociosPage <= 1;
			prev.addEventListener('click', () => { if (sociosPage > 1) { sociosPage -= 1; renderSociosView(); } });
			bar.appendChild(prev);

			const info = document.createElement('span');
			info.className = 'anpa-area-muted';
			info.textContent = 'Páxina ' + sociosPage + ' de ' + pages + ' (' + total + ' socios/as)';
			bar.appendChild(info);

			const next = document.createElement('button');
			next.type = 'button';
			next.className = 'anpa-area-secondary';
			next.textContent = 'Seguinte';
			next.disabled = sociosPage >= pages;
			next.addEventListener('click', () => { if (sociosPage < pages) { sociosPage += 1; renderSociosView(); } });
			bar.appendChild(next);

			return bar;
		}

		// Inline socio edit form. Preserves rol (sent explicitly) because the
		// PATCH endpoint resets omitted rol/estado to defaults.
		function renderSocioEditForm(socio) {
			adminContentEl.textContent = '';
			if (!socio.rol) {
				showMessage(root, 'Non se pode editar: falta o rol do socio/a.', 'error');
				return;
			}
			// Initial master lock: fully immutable from the admin UI (server enforces too).
			const masterEmail = (root.dataset.masterEmail || '').toLowerCase();
			if (masterEmail && String(socio.email || '').toLowerCase() === masterEmail) {
				const note = document.createElement('p');
				note.className = 'anpa-area-muted anpa-area-warning';
				note.textContent = 'Usuario master inicial (Xunta Directiva) — non é posible modificar os seus datos nin o seu estado.';
				adminContentEl.appendChild(note);
				const back = document.createElement('button');
				back.type = 'button';
				back.className = 'anpa-area-secondary';
				back.textContent = 'Volver';
				back.addEventListener('click', () => { loadAdminSection('socios'); });
				adminContentEl.appendChild(back);
				return;
			}
			const currentRol = socio.rol;

			const form = document.createElement('div');
			form.className = 'anpa-socio-edit';

			const title = document.createElement('h3');
			title.textContent = 'Editar socio/a: ' + (socio.email || '');
			form.appendChild(title);

			function addField(labelText, input) {
				const label = document.createElement('label');
				label.textContent = labelText;
				form.appendChild(label);
				form.appendChild(input);
			}

			const nome = document.createElement('input');
			nome.type = 'text';
			nome.value = socio.nome || '';
			addField('Nome', nome);

			const apelidos = document.createElement('input');
			apelidos.type = 'text';
			apelidos.value = socio.apelidos || '';
			addField('Apelidos', apelidos);

			const estado = document.createElement('select');
			['activo', 'pendiente_alta', 'baixa'].forEach((value) => {
				const opt = document.createElement('option');
				opt.value = value;
				opt.textContent = value;
				if ((socio.estado || '') === value) {
					opt.selected = true;
				}
				estado.appendChild(opt);
			});
			addField('Estado', estado);

			const telInput = document.createElement('input');
			telInput.type = 'tel';
			telInput.value = socio.telefono || '';
			telInput.placeholder = 'Teléfono (opcional)';
			addField('Teléfono', telInput);

			const nifInput = document.createElement('input');
			nifInput.type = 'text';
			nifInput.value = socio.nif || '';
			nifInput.placeholder = 'NIF / NIE';
			addField('NIF / NIE', nifInput);

			const rolNote = document.createElement('p');
			rolNote.className = 'anpa-area-muted';
			rolNote.textContent = 'Rol: ' + currentRol + ' (consérvase; xestiónase desde a sección Administradores).';
			form.appendChild(rolNote);

			if (socio.baixa_estado === 'solicitada') {
				const baixaNote = document.createElement('p');
				baixaNote.className = 'anpa-area-muted anpa-area-warning';
				baixaNote.textContent = 'Este socio/a solicitou a baixa'
					+ (socio.baixa_solicitada_en ? ' (' + socio.baixa_solicitada_en + ')' : '')
					+ '. Confirmar fará efectiva a baixa (estado = baixa).';
				form.appendChild(baixaNote);
			}

			const actions = document.createElement('div');
			actions.className = 'anpa-area-actions';

			const saveBtn = document.createElement('button');
			saveBtn.type = 'button';
			saveBtn.textContent = 'Gardar cambios';
			saveBtn.addEventListener('click', async () => {
				showMessage(root, '', 'info');
				const data = {
					nome: (nome.value || '').trim(),
					apelidos: (apelidos.value || '').trim(),
					telefono: (telInput.value || '').trim(),
					nif: (nifInput.value || '').trim(),
					estado: estado.value,
					rol: currentRol,
				};
				if (!data.nome || !data.apelidos) {
					showMessage(root, 'Nome e apelidos son obrigatorios.', 'error');
					return;
				}
				const result = await tokenRequest(
					'PATCH',
					adminUrl('socio/' + encodeURIComponent(socio.email)),
					areaToken,
					data,
					root
				);
				if (result) {
					showMessage(root, 'Socio/a actualizado.', 'success');
					await loadAdminSection('socios');
				}
			});
			actions.appendChild(saveBtn);

			const cancelBtn = document.createElement('button');
			cancelBtn.type = 'button';
			cancelBtn.className = 'anpa-area-secondary';
			cancelBtn.textContent = 'Cancelar';
			cancelBtn.addEventListener('click', async () => {
				showMessage(root, '', 'info');
				await loadAdminSection('socios');
			});
			actions.appendChild(cancelBtn);

			if (socio.baixa_estado === 'solicitada') {
				const confirmBaixaBtn = document.createElement('button');
				confirmBaixaBtn.type = 'button';
				confirmBaixaBtn.className = 'anpa-area-danger';
				confirmBaixaBtn.textContent = 'Confirmar baixa';
				confirmBaixaBtn.addEventListener('click', async () => {
					showMessage(root, '', 'info');
					if (!window.confirm('Confirmar a baixa de ' + (socio.email || '') + '? O socio/a pasará a estado "baixa".')) {
						return;
					}
					const res = await tokenRequest(
						'POST',
						adminUrl('socio/' + encodeURIComponent(socio.email) + '/baixa/confirm'),
						areaToken,
						{},
						root
					);
					if (res) {
						showMessage(root, 'Baixa confirmada.', 'success');
						await loadAdminSection('socios');
					}
				});
				actions.appendChild(confirmBaixaBtn);
			}

			form.appendChild(actions);
			adminContentEl.appendChild(form);

			// ── Eliminar socio (admin/master only) ──────────────────────────
			var elimBox = document.createElement('div');
			elimBox.className = 'anpa-area-card';
			var elimTitle = document.createElement('h3');
			elimTitle.textContent = 'Eliminar socio/a';
			elimBox.appendChild(elimTitle);
			var elimNote = document.createElement('p');
			elimNote.className = 'anpa-area-muted anpa-area-warning';
			elimNote.textContent = 'Esta acción é IRREVERSIBLE. Eliminarase permanentemente o socio/a, os seus fillos/as, matrículas e datos bancarios.';
			elimBox.appendChild(elimNote);

			var elimConfirmText = document.createElement('p');
			elimConfirmText.hidden = true;
			elimConfirmText.id = 'anpa-elim-confirm-text';
			elimConfirmText.innerHTML = 'Escribe <strong>BORRAR</strong> para confirmar:';
			elimBox.appendChild(elimConfirmText);

			var elimInput = document.createElement('input');
			elimInput.type = 'text';
			elimInput.id = 'anpa-elim-confirm-input';
			elimInput.hidden = true;
			elimInput.autocomplete = 'off';
			elimBox.appendChild(elimInput);

			var elimBtn = document.createElement('button');
			elimBtn.type = 'button';
			elimBtn.className = 'anpa-area-danger';
			elimBtn.textContent = 'Eliminar socio/a permanentemente';
			elimBtn.addEventListener('click', async function() {
				if (!elimConfirmText.hidden) {
					// Second step: verify the typed confirmation
					if ((elimInput.value || '').trim() !== 'BORRAR') {
						showMessage(root, 'Escribe exactamente \"BORRAR\" para confirmar a eliminación.', 'error');
						return;
					}
					showMessage(root, '', 'info');
					var res = await tokenRequest('DELETE', adminUrl('socio/' + encodeURIComponent(socio.email)), areaToken, null, root);
					if (res) {
						showMessage(root, 'Socio/a eliminado permanentemente.', 'success');
						await loadAdminSection('socios');
					}
					return;
				}
				// First step: show confirmation input
				elimConfirmText.hidden = false;
				elimInput.hidden = false;
				elimInput.value = '';
				elimInput.focus();
				showMessage(root, '⚠️ Primeira confirmación recibida. Agora escribe BORRAR e preme de novo o botón.', 'error');
			});
			elimBox.appendChild(elimBtn);
			adminContentEl.appendChild(elimBox);

			renderAdminSocioFillos(socio.email);
		}

		async function renderAdminSocioFillos(email) {
			var box = document.createElement('div');
			box.className = 'anpa-area-card anpa-admin-inline-card';
			var title = document.createElement('h3');
			title.textContent = 'Fillos/as';
			box.appendChild(title);
			var note = document.createElement('p');
			note.className = 'anpa-area-muted';
			note.textContent = 'Preme "Editar" para modificar nome, apelidos, data de nacemento, curso e grupo. "Eliminar" borra o fillo permanentemente.';
			box.appendChild(note);
			adminContentEl.appendChild(box);

			var fillos = await tokenRequest('GET', adminUrl('socio/' + encodeURIComponent(email) + '/fillos'), areaToken, null, root);
			if (!Array.isArray(fillos) || !fillos.length) {
				var empty = document.createElement('p');
				empty.className = 'anpa-area-muted';
				empty.textContent = 'Este socio/a non ten fillos/as rexistrados.';
				box.appendChild(empty);
				return;
			}

			var _filloEditHost = document.createElement('div');
			_filloEditHost.id = 'anpa-admin-fillo-edit';
			_filloEditHost.hidden = true;
			box.appendChild(_filloEditHost);

			fillos.forEach(function(fillo) {
				var row = document.createElement('div');
				row.className = 'anpa-admin-inline-row';

				var label = document.createElement('strong');
				var detail = (fillo.curso || '') + (fillo.aula ? '-' + fillo.aula : '');
				label.textContent = ((fillo.nome || '') + ' ' + (fillo.apelidos || '')).trim() + (detail ? ' (' + detail + ')' : '');
				row.appendChild(label);

				var editBtn = document.createElement('button');
				editBtn.type = 'button';
				editBtn.className = 'anpa-area-secondary';
				editBtn.textContent = 'Editar';
								editBtn.addEventListener('click', async function() {
									showMessage(root, '', 'info');
									await renderAdminFilloEditForm(email, fillo, _filloEditHost, box);
								});
				row.appendChild(editBtn);

				var delBtn = document.createElement('button');
				delBtn.type = 'button';
				delBtn.className = 'anpa-area-secondary anpa-area-danger';
				delBtn.textContent = 'Eliminar';
				delBtn.addEventListener('click', async function() {
					if (!window.confirm('Eliminar permanentemente a ' + (fillo.nome || '') + ' ' + (fillo.apelidos || '') + '? Os cursos e matrículas asociadas tamén se borrarán.')) {
						return;
					}
					if (!window.confirm('Confirmación: esta acción non se pode desfacer.')) {
						return;
					}
					showMessage(root, '', 'info');
					var res = await tokenRequest('DELETE', adminUrl('fillo/' + encodeURIComponent(fillo.id)), areaToken, null, root);
					if (res) {
						showMessage(root, 'Fillo/a eliminado.', 'success');
						// Re-render the fillos section
						_filloEditHost.hidden = true;
						_filloEditHost.textContent = '';
						box.textContent = '';
						await renderAdminSocioFillos(email);
					}
				});
				row.appendChild(delBtn);

				box.appendChild(row);
			});
		}

		async function renderAdminFilloEditForm(email, fillo, host, box) {
			host.hidden = false;
			host.textContent = '';

			var config = await loadAnpaConfig();
			var cursos = config.cursos || ['1','2','3','4','5','6'];
			var aulas  = config.aulas  || ['A','B','C','D'];

			var form = document.createElement('div');
			form.className = 'anpa-fillo-edit-form';

			var fTitle = document.createElement('h4');
			fTitle.textContent = 'Editar fillo/a: ' + (fillo.nome || '') + ' ' + (fillo.apelidos || '');
			form.appendChild(fTitle);

			function addF(label, input) {
				var l = document.createElement('label');
				l.textContent = label;
				form.appendChild(l);
				form.appendChild(input);
			}

			var nomeI = document.createElement('input');
			nomeI.type = 'text';
			nomeI.value = fillo.nome || '';
			nomeI.required = true;
			addF('Nome', nomeI);

			var apelidosI = document.createElement('input');
			apelidosI.type = 'text';
			apelidosI.value = fillo.apelidos || '';
			apelidosI.required = true;
			addF('Apelidos', apelidosI);

			var dataI = document.createElement('input');
			dataI.type = 'date';
			dataI.value = fillo.data_nacemento || '';
			dataI.required = true;
			addF('Data de nacemento', dataI);

			var cursoI = document.createElement('select');
			cursos.forEach(function(v) {
				var o = document.createElement('option');
				o.value = v;
				o.textContent = v + '\u00BA';
				if (String(fillo.curso || '') === v) { o.selected = true; }
				cursoI.appendChild(o);
			});
			if (!fillo.curso) {
				var ph = document.createElement('option');
				ph.value = '';
				ph.textContent = '-- Selecciona --';
				ph.selected = true;
				cursoI.insertBefore(ph, cursoI.firstChild);
			}
			cursoI.required = true;
			addF('Curso', cursoI);

			var aulaI = document.createElement('select');
			aulas.forEach(function(v) {
				var o = document.createElement('option');
				o.value = v;
				o.textContent = v;
				if (String(fillo.aula || '') === v) { o.selected = true; }
				aulaI.appendChild(o);
			});
			if (!fillo.aula) {
				var ph2 = document.createElement('option');
				ph2.value = '';
				ph2.textContent = '-- Selecciona --';
				ph2.selected = true;
				aulaI.insertBefore(ph2, aulaI.firstChild);
			}
			aulaI.required = true;
			addF('Grupo', aulaI);

			var actions = document.createElement('div');
			actions.className = 'anpa-area-actions';

			var saveBtn = document.createElement('button');
			saveBtn.type = 'button';
			saveBtn.textContent = 'Gardar cambios';
			saveBtn.addEventListener('click', async function() {
				showMessage(root, '', 'info');
				var payload = {
					nome: (nomeI.value || '').trim(),
					apelidos: (apelidosI.value || '').trim(),
					data_nacemento: (dataI.value || '').trim(),
					curso: cursoI.value,
					aula: aulaI.value,
				};
				if (!payload.nome || !payload.apelidos || !payload.data_nacemento || !payload.curso || !payload.aula) {
					showMessage(root, 'Todos os campos son obrigatorios.', 'error');
					return;
				}
				var res = await tokenRequest('PATCH', adminUrl('fillo/' + encodeURIComponent(fillo.id)), areaToken, payload, root);
				if (res) {
					showMessage(root, 'Fillo/a actualizado correctamente.', 'success');
					host.hidden = true;
					host.textContent = '';
					box.textContent = '';
					await renderAdminSocioFillos(email);
				}
			});
			actions.appendChild(saveBtn);

			var cancelBtn = document.createElement('button');
			cancelBtn.type = 'button';
			cancelBtn.className = 'anpa-area-secondary';
			cancelBtn.textContent = 'Cancelar';
			cancelBtn.addEventListener('click', function() {
				host.hidden = true;
				host.textContent = '';
			});
			actions.appendChild(cancelBtn);

			form.appendChild(actions);
			host.appendChild(form);
		}

		function openSocioEdit(emailKey) {
			// Use the cached list row — it already contains email, nome,
			// apelidos, estado and rol, so no extra GET is needed.
			const socio = lastSociosRows.find((r) => String(r.email) === String(emailKey));
			if (socio) {
				renderSocioEditForm(socio);
			} else {
				showMessage(root, 'Non se atopou o socio/a na lista. Recarga a sección.', 'error');
			}
		}

		async function loadAdminSection(section) {
			if (!areaToken) {
				showStep(root, 'email');
				showMessage(root, 'A sesión caducou. Volve entrar.', 'error');
				return;
			}
			adminContentEl.textContent = '';
			const rows = await tokenRequest('GET', adminUrl(section), areaToken, null, root);
			if (rows) {
				if (section === 'socios') {
					renderSociosTable(rows);
				} else if (section === 'empresas') {
					renderEmpresasSection(rows);
				} else if (section === 'actividades') {
					await renderActividadesSection(rows);
				} else if (section === 'cursos') {
					renderCursosSection(rows);
				} else if (section === 'matriculas') {
					await renderMatriculasCursoSection();
				} else if (section === 'fillos') {
					renderFillosSection(rows);
				} else if (section === 'admins') {
					renderAdminsSection(rows);
				} else if (section === 'approvals') {
					renderApprovalsSection(rows);
				} else {
					renderAdminTable(rows);
				}
			}

			// If the admin panel was opened from the session dropdown with a
			// pending panel request (Listados / Descargar IBAN), reveal it now
			// that the admin step is active.
			if (pendingAdminPanel) {
				var wanted = pendingAdminPanel;
				pendingAdminPanel = null;
				toggleAdminPanel(wanted);
			}
		}

		// Set by the session-dropdown shortcuts so the matching admin panel
		// opens once the admin step is active (after any admin-auth step).
		var pendingAdminPanel = null;

		// ── Pendentes de aprobación (PR-12h-B) ───────────────────────────
		// Lists socios in estado 'pendente_aprobacion' with per-row checkboxes
		// plus "Aprobar" / "Rexeitar" actions that work on one or several rows.
		function renderApprovalsSection(rows) {
			adminContentEl.textContent = '';
			var list = Array.isArray(rows) ? rows : [];

			var h = document.createElement('h3');
			h.textContent = 'Socios/as pendentes de aprobación';
			adminContentEl.appendChild(h);

			if (!list.length) {
				var empty = document.createElement('p');
				empty.className = 'anpa-area-muted';
				empty.textContent = 'Non hai solicitudes pendentes de aprobación.';
				adminContentEl.appendChild(empty);
				return;
			}

			var intro = document.createElement('p');
			intro.className = 'anpa-area-muted';
			intro.textContent = 'Marca as solicitudes e aproba ou rexeita unha ou varias á vez. Ao aprobar, enviaráselle un correo de benvida; ao rexeitar, un correo coa decisión.';
			adminContentEl.appendChild(intro);

			var table = document.createElement('table');
			table.className = 'anpa-admin-table';
			var thead = document.createElement('thead');
			thead.innerHTML = '<tr><th></th><th>Email</th><th>Nome</th><th>Apelidos</th><th>Teléfono</th><th>Solicitado</th></tr>';
			table.appendChild(thead);

			var tbody = document.createElement('tbody');
			list.forEach(function (row) {
				var tr = document.createElement('tr');

				var tdCheck = document.createElement('td');
				var cb = document.createElement('input');
				cb.type = 'checkbox';
				cb.className = 'anpa-approval-check';
				cb.value = row.email || '';
				tdCheck.appendChild(cb);
				tr.appendChild(tdCheck);

				['email', 'nome', 'apelidos', 'telefono', 'creado_en'].forEach(function (col) {
					var td = document.createElement('td');
					var v = row[col];
					td.textContent = (v === null || v === undefined) ? '' : String(v);
					tr.appendChild(td);
				});
				tbody.appendChild(tr);
			});
			table.appendChild(tbody);
			adminContentEl.appendChild(table);

			// Select-all toggle in the header checkbox cell.
			var selectAll = document.createElement('input');
			selectAll.type = 'checkbox';
			selectAll.title = 'Seleccionar todo';
			thead.querySelector('th').appendChild(selectAll);
			selectAll.addEventListener('change', function () {
				adminContentEl.querySelectorAll('.anpa-approval-check').forEach(function (c) { c.checked = selectAll.checked; });
			});

			function selectedEmails() {
				var out = [];
				adminContentEl.querySelectorAll('.anpa-approval-check:checked').forEach(function (c) {
					if (c.value) { out.push(c.value); }
				});
				return out;
			}

			async function process(mode) {
				var emails = selectedEmails();
				if (!emails.length) { showMessage(root, 'Selecciona polo menos unha solicitude.', 'error'); return; }
				var verb = (mode === 'approve') ? 'aprobar' : 'rexeitar';
				if (!window.confirm('Seguro que queres ' + verb + ' ' + emails.length + ' solicitude(s)?')) { return; }
				var res = await tokenRequest('POST', adminUrl('approvals/' + mode), areaToken, { emails: emails }, root);
				if (res) {
					showMessage(root, (mode === 'approve' ? 'Aprobadas' : 'Rexeitadas') + ': ' + (res.processed || 0) + '.', 'success');
					await loadAdminSection('approvals');
				}
			}

			var actions = document.createElement('div');
			actions.className = 'anpa-admin-actions';
			actions.style.marginTop = '0.8rem';

			var approveBtn = document.createElement('button');
			approveBtn.type = 'button';
			approveBtn.className = 'anpa-area-primary';
			approveBtn.textContent = 'Aprobar seleccionados';
			approveBtn.addEventListener('click', function () { process('approve'); });
			actions.appendChild(approveBtn);

			var rejectBtn = document.createElement('button');
			rejectBtn.type = 'button';
			rejectBtn.className = 'anpa-area-secondary anpa-area-danger';
			rejectBtn.style.marginLeft = '0.5rem';
			rejectBtn.textContent = 'Rexeitar seleccionados';
			rejectBtn.addEventListener('click', function () { process('reject'); });
			actions.appendChild(rejectBtn);

			adminContentEl.appendChild(actions);
		}

		// ── Cursos management (fase10) ───────────────────────────────────
		var _anpaConfig = null; // cached GET /admin/config

		async function loadAnpaConfig() {
			if (_anpaConfig) { return _anpaConfig; }
			var data = await tokenRequest('GET', adminUrl('config'), areaToken, null, root);
			if (data) { _anpaConfig = data; }
			return data || { cursos: ['1','2','3','4','5','6'], aulas: ['A','B','C','D'] };
		}
		function renderCursosSection(data) {
			adminContentEl.textContent = '';
			const current = (data && data.current) || '';
			const list = data && Array.isArray(data.cursos) ? data.cursos : [];
			const title = document.createElement('h3');
			title.textContent = 'Cursos escolares';
			adminContentEl.appendChild(title);
			const note = document.createElement('p');
			note.className = 'anpa-area-muted';
			note.textContent = 'Curso actual: ' + current + '. Cando un curso está pechado, non acepta novas matrículas nin baixas.';
			adminContentEl.appendChild(note);
			if (!list.length) { renderAdminTable([]); return; }

			var filterBar = buildFilterBar('cursos', list);
			adminContentEl.appendChild(filterBar);
			var searchInput = filterBar.querySelector('[data-search="cursos"]');
			var query = searchInput ? searchInput.value : '';

			// Enrich rows with display values.
			list.forEach(function(r) { r._curso_display = r.curso_escolar + (r.actual ? ' (actual)' : ''); r._estado_display = r.matriculas_abertas ? 'aberto' : 'pechado'; });
			var CURSOS_COLS = ['_curso_display', 'curso_escolar', '_estado_display', 'actualizado_en'];
			var filtered = filterRows(list, query, CURSOS_COLS);
			addExportCsvButton(filterBar, 'cursos', filtered, CURSOS_COLS);

			if (!filtered.length) {
				var empty = document.createElement('p');
				empty.className = 'anpa-area-muted';
				empty.textContent = 'Sen resultados.';
				adminContentEl.appendChild(empty);
				return;
			}

			const table = document.createElement('table');
			table.className = 'anpa-admin-table';
			const thead = document.createElement('thead');
			const hr = document.createElement('tr');
			CURSOS_COLS.forEach(function(c) {
				const th = document.createElement('th');
				th.className = 'anpa-sortable';
				var label = colLabel(c.replace(/^_/, '')) || c.replace(/^_/, '');
				th.textContent = label;
				hr.appendChild(th);
			});
			hr.appendChild(document.createElement('th'));
			thead.appendChild(hr); table.appendChild(thead);
			const tbody = document.createElement('tbody');
			filtered.forEach((row) => {
				const tr = document.createElement('tr');
				if (!row.matriculas_abertas) { tr.classList.add('anpa-row-baixa'); }
				CURSOS_COLS.forEach(function(c) {
					var v;
					if (c === '_curso_display') { v = row._curso_display; }
					else if (c === '_estado_display') { v = row._estado_display; }
					else { v = row[c]; }
					const td = document.createElement('td'); td.textContent = v === null || v === undefined ? '' : String(v); tr.appendChild(td);
				});
				const actions = document.createElement('td');
				const btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'anpa-area-secondary anpa-admin-action';
				btn.textContent = row.matriculas_abertas ? 'Pechar matrícula' : 'Abrir matrícula';
				btn.addEventListener('click', async () => {
					const next = !row.matriculas_abertas;
					if (!window.confirm((next ? 'Abrir' : 'Pechar') + ' matrícula para ' + row.curso_escolar + '?')) { return; }
					const done = await tokenRequest('PUT', adminUrl('curso'), areaToken, { curso_escolar: row.curso_escolar, matriculas_abertas: next }, root);
					if (done) { showMessage(root, 'Curso actualizado.', 'success'); await loadAdminSection('cursos'); }
				});
				actions.appendChild(btn); tr.appendChild(actions); tbody.appendChild(tr);
			});
			table.appendChild(tbody); adminContentEl.appendChild(table);

			if (searchInput) {
				searchInput.addEventListener('input', function() {
					if (window._cursosSearchTimer) { clearTimeout(window._cursosSearchTimer); }
					window._cursosSearchTimer = setTimeout(function() { renderCursosSection(data); }, 250);
				});
			}
		}

		async function renderMatriculasCursoSection() {
			adminContentEl.textContent = '';
			const data = await tokenRequest('GET', adminUrl('cursos'), areaToken, null, root);
			const current = (data && data.current) || '';
			const cursos = data && Array.isArray(data.cursos) ? data.cursos : [];
			const panel = document.createElement('div');
			panel.className = 'anpa-socio-edit';
			const label = document.createElement('label'); label.textContent = 'Curso escolar'; panel.appendChild(label);
			const sel = document.createElement('select');
			cursos.forEach((c) => { const o = document.createElement('option'); o.value = c.curso_escolar; o.textContent = c.curso_escolar + (c.actual ? ' (actual)' : ''); if (c.curso_escolar === current) { o.selected = true; } sel.appendChild(o); });
			panel.appendChild(sel);
			const loadBtn = document.createElement('button'); loadBtn.type = 'button'; loadBtn.className = 'anpa-area-secondary anpa-admin-action'; loadBtn.textContent = 'Ver matrículas'; panel.appendChild(loadBtn);
			adminContentEl.appendChild(panel);
			const host = document.createElement('div'); adminContentEl.appendChild(host);

			// MATRICULAS_COLS — visible columns (short labels, no internal IDs).
			var MATRICULAS_COLS = ['fillo_apelidos', 'fillo_nome', 'actividade', 'curso_completo', 'estado', 'franxa', 'dias', 'trimestre'];

			async function renderMatriculasTable(selCurso) {
				host.textContent = '';
				const rows = await tokenRequest('GET', adminUrl('matriculas?curso=' + encodeURIComponent(selCurso || current)), areaToken, null, root);
				const list = Array.isArray(rows) ? rows : [];
				if (!list.length) {
					const empty = document.createElement('p');
					empty.className = 'anpa-area-muted';
					empty.textContent = 'Sen matrículas para o curso seleccionado.';
					host.appendChild(empty);
					return;
				}

				// Filter bar at the top.
				var filterBar = buildFilterBar('matriculas', list);
				host.appendChild(filterBar);
				addExportCsvButton(filterBar, 'matriculas', list, MATRICULAS_COLS);

				var searchInput = filterBar.querySelector('[data-search="matriculas"]');
				var query = searchInput ? searchInput.value : '';
				var filtered = filterRows(list, query, MATRICULAS_COLS);

				if (!filtered.length) {
					var emptyP = document.createElement('p');
					emptyP.className = 'anpa-admin-empty';
					emptyP.textContent = 'Sen matrículas que coincidan coa busca.';
					host.appendChild(emptyP);
					return;
				}

				// Sort state.
				var key = window._matriculasSortKey || MATRICULAS_COLS[0];
				var dir = window._matriculasSortDir || 'asc';
				const tbl = window.AnpaAdminTable;
				const sorted = tbl ? tbl.sortRows(filtered, key, dir) : filtered.slice();

				const table = document.createElement('table');
				table.className = 'anpa-admin-table';
				const thead = document.createElement('thead');
				const headRow = document.createElement('tr');
				MATRICULAS_COLS.forEach(function(c) {
					const th = document.createElement('th');
					th.className = 'anpa-sortable';
					var label = colLabel(c) || c;
					if (key === c) { label += dir === 'asc' ? ' \u25B2' : ' \u25BC'; }
					th.textContent = label;
					th.addEventListener('click', function() {
						if (key === c) { dir = dir === 'asc' ? 'desc' : 'asc'; }
						else { key = c; dir = 'asc'; }
						window._matriculasSortKey = key;
						window._matriculasSortDir = dir;
						renderMatriculasTable(selCurso);
					});
					headRow.appendChild(th);
				});
				thead.appendChild(headRow);
				table.appendChild(thead);

				const tbody = document.createElement('tbody');
				sorted.forEach((row) => {
					const tr = document.createElement('tr');
					MATRICULAS_COLS.forEach(function(c) {
						const td = document.createElement('td');
						var v = row[c];
						if (c === 'trimestre') {
							v = String(v) + 'T';
						} else if (c === 'estado') {
							// Humanize estado.
							var estados = { 'activo': 'Activo', 'lista_espera': 'Lista de espera', 'oferta': 'Oferta', 'baixa_solicitada': 'Baixa solicitada', 'baixa': 'Baixa' };
							v = estados[v] || v;
						}
						td.textContent = v === null || v === undefined ? '' : String(v);
						tr.appendChild(td);
					});
					tbody.appendChild(tr);
				});
				table.appendChild(tbody);
				host.appendChild(table);

				// Search input wiring.
				if (searchInput) {
					searchInput.addEventListener('input', function() {
						if (window._matriculasSearchTimer) { clearTimeout(window._matriculasSearchTimer); }
						window._matriculasSearchTimer = setTimeout(function() { renderMatriculasTable(selCurso); }, 250);
					});
				}
			}

			loadBtn.addEventListener('click', function() { renderMatriculasTable(sel.value || current); });
			await renderMatriculasTable(sel.value || current);
		}

		// ── Empresas management (fase9 PR-9b) ───────────────────────────
		const EMPRESA_FIELDS = [
			{ key: 'nome', label: 'Nome', type: 'text' },
			{ key: 'email', label: 'Email', type: 'email' },
			{ key: 'responsable', label: 'Responsable', type: 'text' },
			{ key: 'telefono', label: 'Teléfono', type: 'tel' },
			{ key: 'url_web', label: 'Web (https://…)', type: 'url' },
		];

		function renderEmpresasSection(rows) {
			adminContentEl.textContent = '';

			const listAll = Array.isArray(rows) ? rows : [];
			const list = listAll.filter((r) => showInactivos.empresas || r.estado !== 'inactivo');

			const addBtn = document.createElement('button');
			addBtn.type = 'button';
			addBtn.textContent = 'Engadir empresa';
			addBtn.addEventListener('click', () => {
				showMessage(root, '', 'info');
				adminContentEl.querySelector('[data-empresa-form-host]').textContent = '';
				adminContentEl.querySelector('[data-empresa-form-host]').appendChild(buildEmpresaForm({}));
				scrollToAdminForm(adminContentEl.querySelector('[data-empresa-form-host]'));
			});
			adminContentEl.appendChild(addBtn);

			const formHost = document.createElement('div');
			formHost.dataset.empresaFormHost = '';
			adminContentEl.appendChild(formHost);

			if (!list.length) {
				addBtn.disabled = false;
				const empty = document.createElement('p');
				empty.className = 'anpa-area-muted';
				empty.textContent = 'Sen empresas.';
				adminContentEl.appendChild(empty);
				return;
			}

			var filterBar = buildFilterBar('empresas', listAll);
			adminContentEl.appendChild(filterBar);
			addExportCsvButton(filterBar, 'empresas', list, ['nome', 'email', 'responsable', 'telefono', 'estado']);

			var EMPRESAS_COLS = ['nome', 'email', 'responsable', 'telefono', 'estado'];
			var searchInput = filterBar.querySelector('[data-search="empresas"]');
			var query = searchInput ? searchInput.value : '';

			const tbl = window.AnpaAdminTable;
			const filtered = filterRows(list, query, EMPRESAS_COLS);
			var key = window._empresasSortKey || 'nome';
			var dir = window._empresasSortDir || 'asc';
			const sorted = tbl ? tbl.sortRows(filtered, key, dir) : filtered.slice();

			const table = document.createElement('table');
			table.className = 'anpa-admin-table';
			const thead = document.createElement('thead');
			const headRow = document.createElement('tr');
			EMPRESAS_COLS.forEach((c) => {
				const th = document.createElement('th');
				th.className = 'anpa-sortable';
				var label = colLabel(c) || c;
				if (key === c) { label += dir === 'asc' ? ' \u25B2' : ' \u25BC'; }
				th.textContent = label;
				th.addEventListener('click', () => {
					if (key === c) { dir = dir === 'asc' ? 'desc' : 'asc'; }
					else { key = c; dir = 'asc'; }
					window._empresasSortKey = key;
					window._empresasSortDir = dir;
					renderEmpresasSection(rows);
				});
				headRow.appendChild(th);
			});
			headRow.appendChild(document.createElement('th'));
			thead.appendChild(headRow);
			table.appendChild(thead);

			const tbody = document.createElement('tbody');
			sorted.forEach((row) => {
				const tr = document.createElement('tr');
				if (row.estado === 'inactivo') { tr.classList.add('anpa-row-baixa'); }
				EMPRESAS_COLS.forEach((col) => {
					const td = document.createElement('td');
					const v = row[col];
					td.textContent = v === null || v === undefined ? '' : String(v);
					tr.appendChild(td);
				});
				const actions = document.createElement('td');
				const editBtn = document.createElement('button');
				editBtn.type = 'button';
				editBtn.className = 'anpa-area-secondary';
				editBtn.textContent = 'Editar';
				editBtn.addEventListener('click', () => {
					showMessage(root, '', 'info');
					formHost.textContent = '';
					formHost.appendChild(buildEmpresaForm(row));
					scrollToAdminForm(formHost);
				});
				actions.appendChild(editBtn);

				const delBtn = document.createElement('button');
				delBtn.type = 'button';
				delBtn.className = 'anpa-area-secondary anpa-area-danger';
				delBtn.dataset.action = 'deactivate';
				delBtn.textContent = 'Desactivar';
				delBtn.addEventListener('click', async () => {
					showMessage(root, '', 'info');
					if (!window.confirm('Desactivar a empresa "' + (row.nome || '') + '"? Os datos manteranse na base de datos pero non aparecerá nos listados por defecto.')) { return; }
					const done = await tokenRequest('DELETE', adminUrl('empresa/' + encodeURIComponent(row.id)), areaToken, null, root);
					if (done) { showMessage(root, 'Empresa desactivada.', 'success'); await loadAdminSection('empresas'); }
				});
				actions.appendChild(delBtn);
				tr.appendChild(actions);
				tbody.appendChild(tr);
			});
			table.appendChild(tbody);
			adminContentEl.appendChild(table);

			if (searchInput) {
				searchInput.addEventListener('input', function() {
					if (window._empresasSearchTimer) { clearTimeout(window._empresasSearchTimer); }
					window._empresasSearchTimer = setTimeout(function() { renderEmpresasSection(rows); }, 250);
				});
			}
		}

		function buildEmpresaForm(empresa) {
			const isEdit = !!empresa.id;
			const form = document.createElement('div');
			form.className = 'anpa-socio-edit';

			const title = document.createElement('h3');
			title.textContent = isEdit ? ('Editar empresa: ' + (empresa.nome || '')) : 'Nova empresa';
			form.appendChild(title);

			const inputs = {};
			EMPRESA_FIELDS.forEach((f) => {
				const label = document.createElement('label');
				label.textContent = f.label;
				form.appendChild(label);
				const input = document.createElement('input');
				input.type = f.type;
				input.value = empresa[f.key] || '';
				form.appendChild(input);
				inputs[f.key] = input;
			});

			const estadoLabel = document.createElement('label');
			estadoLabel.textContent = 'Estado';
			form.appendChild(estadoLabel);
			const estado = document.createElement('select');
			['activo', 'inactivo'].forEach((v) => {
				const o = document.createElement('option');
				o.value = v;
				o.textContent = v;
				if ((empresa.estado || 'activo') === v) { o.selected = true; }
				estado.appendChild(o);
			});
			form.appendChild(estado);

			const actions = document.createElement('div');
			actions.className = 'anpa-area-actions';

			const saveBtn = document.createElement('button');
			saveBtn.type = 'button';
			saveBtn.textContent = isEdit ? 'Gardar cambios' : 'Crear empresa';
			saveBtn.addEventListener('click', async () => {
				showMessage(root, '', 'info');
				const payload = {
					nome: (inputs.nome.value || '').trim(),
					email: (inputs.email.value || '').trim(),
					responsable: (inputs.responsable.value || '').trim(),
					telefono: (inputs.telefono.value || '').trim(),
					url_web: (inputs.url_web.value || '').trim(),
					estado: estado.value,
				};
				if (!payload.nome || !payload.email || !payload.responsable || !payload.telefono) {
					showMessage(root, 'Completa nome, email, responsable e teléfono.', 'error');
					return;
				}
				let result;
				if (isEdit) {
					result = await tokenRequest('PUT', adminUrl('empresa/' + encodeURIComponent(empresa.id)), areaToken, payload, root);
				} else {
					result = await tokenRequest('POST', adminUrl('empresas'), areaToken, payload, root);
				}
				if (result) {
					showMessage(root, isEdit ? 'Empresa actualizada.' : 'Empresa creada.', 'success');
					await loadAdminSection('empresas');
				}
			});
			actions.appendChild(saveBtn);

			const cancelBtn = document.createElement('button');
			cancelBtn.type = 'button';
			cancelBtn.className = 'anpa-area-secondary';
			cancelBtn.textContent = 'Cancelar';
			cancelBtn.addEventListener('click', () => {
				showMessage(root, '', 'info');
				const host = adminContentEl.querySelector('[data-empresa-form-host]');
				if (host) { host.textContent = ''; }
			});
			actions.appendChild(cancelBtn);

			form.appendChild(actions);
			return form;
		}

		// ── Fillos management (phase H14) ────────────────────────────────
		function renderFillosSection(rows) {
			adminContentEl.textContent = '';
			var list = Array.isArray(rows) ? rows : [];
			if (!list.length) {
				var empty = document.createElement('p');
				empty.className = 'anpa-area-muted';
				empty.textContent = 'Non hai fillos/as rexistrados.';
				adminContentEl.appendChild(empty);
				return;
			}

			const COLS = ['proxenitor_apelidos', 'proxenitor_nome', 'fillo_apelidos', 'fillo_nome', 'data_nacemento', 'curso', 'aula', 'estado'];

			// Filter bar at the top.
			var filterBar = buildFilterBar('fillos', list);
			adminContentEl.appendChild(filterBar);
			addExportCsvButton(filterBar, 'fillos', list, COLS);

			var searchInput = filterBar.querySelector('[data-search="fillos"]');
			var query = searchInput ? searchInput.value : '';
			var filtered = filterRows(list, query, COLS);

			if (!filtered.length) {
				adminContentEl.appendChild(document.createElement('p')).className = 'anpa-admin-empty';
				adminContentEl.lastChild.textContent = 'Sen fillos que coincidan coa busca.';
				return;
			}

			// Sort state.
			var key = window._fillosSortKey || COLS[0];
			var dir = window._fillosSortDir || 'asc';
			const tbl = window.AnpaAdminTable;
			const sorted = tbl ? tbl.sortRows(filtered, key, dir) : filtered.slice();

			const table = document.createElement('table');
			table.className = 'anpa-admin-table';
			const thead = document.createElement('thead');
			const headRow = document.createElement('tr');
			COLS.forEach(function(c) {
				const th = document.createElement('th');
				th.className = 'anpa-sortable';
				var label = colLabel(c) || c;
				if (key === c) { label += dir === 'asc' ? ' \u25B2' : ' \u25BC'; }
				th.textContent = label;
				th.addEventListener('click', function() {
					if (key === c) { dir = dir === 'asc' ? 'desc' : 'asc'; }
					else { key = c; dir = 'asc'; }
					window._fillosSortKey = key;
					window._fillosSortDir = dir;
					renderFillosSection(rows);
				});
				headRow.appendChild(th);
			});
			thead.appendChild(headRow);
			table.appendChild(thead);

			const tbody = document.createElement('tbody');
			sorted.forEach(function(row) {
				const tr = document.createElement('tr');
				COLS.forEach(function(c) {
					const td = document.createElement('td');
					var v = c.indexOf('fillo_') === 0 ? row[c.replace('fillo_', '')] : row[c];
					if (c === 'estado') {
						var estados = { 'activo': 'Activo', 'lista_espera': 'Lista de espera', 'baixa': 'Baixa' };
						v = estados[v] || v;
					} else if (c === 'data_nacemento') {
						v = v || '';
					}
					td.textContent = v === null || v === undefined ? '' : String(v);
					tr.appendChild(td);
				});
				tbody.appendChild(tr);
			});
			table.appendChild(tbody);
			adminContentEl.appendChild(table);

			// Search input wiring.
			if (searchInput) {
				searchInput.addEventListener('input', function() {
					if (window._fillosSearchTimer) { clearTimeout(window._fillosSearchTimer); }
					window._fillosSearchTimer = setTimeout(function() { renderFillosSection(rows); }, 250);
				});
			}
		}

		// ── Administradores management (fase9 PR-9d) ────────────────────
		function renderAdminsSection(rows) {
			adminContentEl.textContent = '';

			// Add-admin form: promote an existing active socio by email.
			const form = document.createElement('div');
			form.className = 'anpa-socio-edit';
			const title = document.createElement('h3');
			title.textContent = 'Engadir administrador';
			form.appendChild(title);
			const note = document.createElement('p');
			note.className = 'anpa-area-muted';
			note.textContent = 'Introduce o email dun/dunha socio/a activo/a para concederlle permisos de administración.';
			form.appendChild(note);
			const emailLabel = document.createElement('label');
			emailLabel.textContent = 'Email do socio/a';
			form.appendChild(emailLabel);
			const emailInput = document.createElement('input');
			emailInput.type = 'email';
			form.appendChild(emailInput);
			const addBtn = document.createElement('button');
			addBtn.type = 'button';
			addBtn.textContent = 'Engadir administrador';
			addBtn.addEventListener('click', async () => {
				showMessage(root, '', 'info');
				const email = (emailInput.value || '').trim();
				if (!email) { showMessage(root, 'Introduce un email.', 'error'); return; }
				const result = await tokenRequest('POST', adminUrl('admins'), areaToken, { email: email }, root);
				if (result) {
					showMessage(root, 'Administrador engadido.', 'success');
					await loadAdminSection('admins');
				}
			});
			form.appendChild(addBtn);
			adminContentEl.appendChild(form);

			const listAll = Array.isArray(rows) ? rows : [];
			const list = listAll.filter((r) => showInactivos.admins || (r.estado !== 'baixa' && r.estado !== 'inactivo'));
			var filterBar = buildFilterBar('admins', listAll);
			adminContentEl.appendChild(filterBar);
			var searchInput = filterBar.querySelector('[data-search="admins"]');
			var query = searchInput ? searchInput.value : '';

			if (!list.length && !query) {
				const empty = document.createElement('p');
				empty.className = 'anpa-area-muted';
				empty.textContent = 'Sen administradores.';
				adminContentEl.appendChild(empty);
				return;
			}

			var ADMIN_COLS = ['email', 'nome', 'apelidos', 'estado'];
			var filtered = filterRows(list, query, ADMIN_COLS);
			addExportCsvButton(filterBar, 'admins', filtered, ADMIN_COLS);

			if (!filtered.length) {
				var empty = document.createElement('p');
				empty.className = 'anpa-area-muted';
				empty.textContent = 'Sen resultados.';
				adminContentEl.appendChild(empty);
				return;
			}

			var key = window._adminsSortKey || 'email';
			var dir = window._adminsSortDir || 'asc';
			const tbl = window.AnpaAdminTable;
			const sorted = tbl ? tbl.sortRows(filtered, key, dir) : filtered.slice();

			const table = document.createElement('table');
			table.className = 'anpa-admin-table';
			const thead = document.createElement('thead');
			const headRow = document.createElement('tr');
			ADMIN_COLS.forEach(function(c) {
				const th = document.createElement('th');
				th.className = 'anpa-sortable';
				var label = colLabel(c) || c;
				if (key === c) { label += dir === 'asc' ? ' \u25B2' : ' \u25BC'; }
				th.textContent = label;
				th.addEventListener('click', function() {
					if (key === c) { dir = dir === 'asc' ? 'desc' : 'asc'; }
					else { key = c; dir = 'asc'; }
					window._adminsSortKey = key;
					window._adminsSortDir = dir;
					renderAdminsSection(rows);
				});
				headRow.appendChild(th);
			});
			headRow.appendChild(document.createElement('th'));
			thead.appendChild(headRow);
			table.appendChild(thead);
			const tbody = document.createElement('tbody');
			const adminsMasterEmail = (root.dataset.masterEmail || '').toLowerCase();
			sorted.forEach((row) => {
				const tr = document.createElement('tr');
				ADMIN_COLS.forEach(function(c) {
					const td = document.createElement('td');
					const v = row[c];
					td.textContent = v === null || v === undefined ? '' : String(v);
					tr.appendChild(td);
				});
				const actions = document.createElement('td');
				if (adminsMasterEmail && String(row.email || '').toLowerCase() === adminsMasterEmail) {
					const tag = document.createElement('span');
					tag.className = 'anpa-area-muted';
					tag.textContent = 'Master inicial';
					actions.appendChild(tag);
				} else {
					const revokeBtn = document.createElement('button');
					revokeBtn.type = 'button';
					revokeBtn.className = 'anpa-area-secondary anpa-area-danger';
					revokeBtn.textContent = 'Revogar';
					revokeBtn.addEventListener('click', async () => {
						showMessage(root, '', 'info');
						if (!window.confirm('Revogar os permisos de administrador de ' + (row.email || '') + '?')) { return; }
						const done = await tokenRequest('DELETE', adminUrl('admins/' + encodeURIComponent(row.email)), areaToken, null, root);
						if (done) { showMessage(root, 'Administrador revogado.', 'success'); await loadAdminSection('admins'); }
					});
					actions.appendChild(revokeBtn);
				}
				tr.appendChild(actions);
				tbody.appendChild(tr);
			});
			table.appendChild(tbody);
			adminContentEl.appendChild(table);

			if (searchInput) {
				searchInput.addEventListener('input', function() {
					if (window._adminsSearchTimer) { clearTimeout(window._adminsSearchTimer); }
					window._adminsSearchTimer = setTimeout(function() { renderAdminsSection(rows); }, 250);
				});
			}
		}

		// ── Actividades management (fase9 PR-9c + fase 1.20.0) ──────────
		async function renderActividadesSection(rows) {
			adminContentEl.textContent = '';

			// Fetch empresas and cursos.
			const empresas = await tokenRequest('GET', adminUrl('empresas'), areaToken, null, root);
			const empresaList = Array.isArray(empresas) ? empresas : [];
			const empresaNome = {};
			empresaList.forEach((e) => { empresaNome[String(e.id)] = e.nome; });

			const cursosData = await tokenRequest('GET', adminUrl('cursos'), areaToken, null, root);
			const cursoList = cursosData && Array.isArray(cursosData.cursos) ? cursosData.cursos : [];
			const currentCurso = (cursosData && cursosData.current) || '';

			// ── Course selector (fase 1.20.0) ───────────────────────
			const toolbar = document.createElement('div');
			toolbar.className = 'anpa-admin-toolbar';
			const cursoLabel = document.createElement('label');
			cursoLabel.style.marginRight = '0.5rem';
			cursoLabel.textContent = 'Curso escolar:';
			const cursoSelect = document.createElement('select');
			cursoSelect.dataset.adminCursoSelect = '';
			cursoSelect.setAttribute('aria-label', 'Filtrar por curso escolar');
			const allOpt = document.createElement('option');
			allOpt.value = '__all__';
			allOpt.textContent = '— Todos os cursos —';
			cursoSelect.appendChild(allOpt);
			cursoList.forEach((c) => {
				const opt = document.createElement('option');
				opt.value = String(c.curso_escolar);
				opt.textContent = String(c.curso_escolar) + (c.curso_escolar === currentCurso ? ' (actual)' : '');
				cursoSelect.appendChild(opt);
			});
			var savedCurso = '';
			try { savedCurso = sessionStorage.getItem('anpa_admin_actividad_curso') || ''; } catch (_) {}
			if (savedCurso && (savedCurso === '__all__' || cursoList.find((c) => String(c.curso_escolar) === savedCurso))) {
				cursoSelect.value = savedCurso;
			} else {
				cursoSelect.value = String(currentCurso);
			}
			cursoLabel.appendChild(cursoSelect);
			toolbar.appendChild(cursoLabel);
			adminContentEl.appendChild(toolbar);

			const addBtn = document.createElement('button');
			addBtn.type = 'button';
			addBtn.textContent = 'Engadir actividade';
			addBtn.addEventListener('click', () => {
				showMessage(root, '', 'info');
				const host = adminContentEl.querySelector('[data-actividad-form-host]');
				host.textContent = '';
				host.appendChild(buildActividadForm({}, empresaList));
				scrollToAdminForm(host);
			});
			adminContentEl.appendChild(addBtn);

			if (!empresaList.length) {
				const warn = document.createElement('p');
				warn.className = 'anpa-area-muted';
				warn.textContent = 'Crea primeiro unha empresa para poder asignarlle actividades.';
				adminContentEl.appendChild(warn);
			}

			const formHost = document.createElement('div');
			formHost.dataset.actividadFormHost = '';
			adminContentEl.appendChild(formHost);

			const listHost = document.createElement('div');
			listHost.dataset.actividadListHost = '';
			adminContentEl.appendChild(listHost);

			function optsSummary(row) {
				const parts = [];
				if (row.horarios) { parts.push(row.horarios.replace(/manha/g, 'mañá')); }
				if (row.grupos) { parts.push(row.grupos); }
				if (row.dias) { parts.push(row.dias); }
				return parts.join(' · ');
			}

			function renderActividadesFiltered() {
				listHost.textContent = '';
				const sel = cursoSelect.value;
				let listAll = Array.isArray(rows) ? rows : [];
				if (sel && sel !== '__all__') {
					listAll = listAll.filter((r) => String(r.curso_escolar) === sel);
				}
				const visible = listAll.filter((r) => showInactivos.actividades || r.estado !== 'inactivo');
				var filterBar = buildFilterBar('actividades', listAll);
				listHost.appendChild(filterBar);

				// Pre-enrich rows with computed columns.
				visible.forEach(function(r) {
					r._empresa_nome = empresaNome[String(r.empresa_id)] || ('#' + r.empresa_id);
					r._opcions = optsSummary(r);
				});
				var searchInput = filterBar.querySelector('[data-search="actividades"]');
				var query = searchInput ? searchInput.value : '';
				var ACTIV_COLS = ['nome', '_empresa_nome', 'curso_escolar', 'franxa', '_opcions', 'estado'];
				var filtered = filterRows(visible, query, ACTIV_COLS);
				addExportCsvButton(filterBar, 'actividades', filtered, ACTIV_COLS);

				if (!filtered.length) {
					var empty = document.createElement('p');
					empty.className = 'anpa-admin-empty';
					empty.textContent = (sel && sel !== '__all__')
						? 'Non hai actividades rexistradas para o curso ' + sel + (query ? ' que coincidan coa busca' : '') + '.'
						: 'Sen actividades.';
					listHost.appendChild(empty);
					return;
				}

				var key = window._actividadesSortKey || 'nome';
				var dir = window._actividadesSortDir || 'asc';
				const tbl = window.AnpaAdminTable;
				const sorted = tbl ? tbl.sortRows(filtered, key, dir) : filtered.slice();

				const table = document.createElement('table');
				table.className = 'anpa-admin-table';
				const thead = document.createElement('thead');
				const headRow = document.createElement('tr');
				ACTIV_COLS.forEach(function(c) {
					const th = document.createElement('th');
					th.className = 'anpa-sortable';
					var label = colLabel(c.replace(/^_/, '')) || c.replace(/^_/, '');
					if (key === c) { label += dir === 'asc' ? ' \u25B2' : ' \u25BC'; }
					th.textContent = label;
					th.addEventListener('click', function() {
						if (key === c) { dir = dir === 'asc' ? 'desc' : 'asc'; }
						else { key = c; dir = 'asc'; }
						window._actividadesSortKey = key;
						window._actividadesSortDir = dir;
						renderActividadesFiltered();
					});
					headRow.appendChild(th);
				});
				headRow.appendChild(document.createElement('th'));
				thead.appendChild(headRow);
				table.appendChild(thead);

				const tbody = document.createElement('tbody');
				sorted.forEach((row) => {
					const tr = document.createElement('tr');
					if (row.estado === 'inactivo') { tr.classList.add('anpa-row-baixa'); }
					ACTIV_COLS.forEach(function(c) {
						const td = document.createElement('td');
						var v;
						if (c === '_empresa_nome') { v = row._empresa_nome; }
						else if (c === '_opcions') { v = row._opcions; }
						else { v = row[c]; }
						td.textContent = v === null || v === undefined ? '' : String(v);
						tr.appendChild(td);
					});
					const actions = document.createElement('td');
					actions.className = 'anpa-admin-actions';
					const editBtn = document.createElement('button');
					editBtn.type = 'button';
					editBtn.className = 'anpa-area-secondary';
					editBtn.textContent = 'Editar';
					editBtn.addEventListener('click', () => {
						showMessage(root, '', 'info');
						formHost.textContent = '';
						formHost.appendChild(buildActividadForm(row, empresaList));
						scrollToAdminForm(formHost);
					});
					actions.appendChild(editBtn);

					const gruposBtn = document.createElement('button');
					gruposBtn.type = 'button';
					gruposBtn.className = 'anpa-area-secondary';
					gruposBtn.textContent = 'Grupos';
					gruposBtn.addEventListener('click', async () => {
						showMessage(root, '', 'info');
						formHost.textContent = '';
						await renderGruposPanel(row, formHost);
						scrollToAdminForm(formHost);
					});
					actions.appendChild(gruposBtn);

					// Copy-to-current: only when viewing a non-current curso.
					if (sel && sel !== '__all__' && String(row.curso_escolar) !== String(currentCurso)) {
						const copyBtn = document.createElement('button');
						copyBtn.type = 'button';
						copyBtn.dataset.action = 'copy-to-current';
						copyBtn.textContent = 'Copiar ao curso actual';
						copyBtn.title = 'Duplica esta actividade no curso ' + currentCurso;
						copyBtn.addEventListener('click', async () => {
							showMessage(root, '', 'info');
							if (!window.confirm('Copiar "' + (row.nome || '') + '" ao curso ' + currentCurso + '?')) {
								return;
							}
							const done = await tokenRequest('POST', adminUrl('actividad/' + encodeURIComponent(row.id) + '/copy-to-current'), areaToken, {}, root);
							if (done) {
								// Warn if a same-name actividad already exists in the current curso.
								const fullList = Array.isArray(rows) ? rows : [];
								const dup = fullList.find((r) => r.curso_escolar === currentCurso && r.nome === row.nome);
								if (dup) {
									showMessage(root, 'Actividade copiada ao curso ' + currentCurso + '. Aviso: xa existía outra co mesmo nome; considera renomeala se foi un erro.', 'info');
								} else {
									showMessage(root, 'Actividade copiada ao curso ' + currentCurso + '.', 'success');
								}
								await loadAdminSection('actividades');
							}
						});
						actions.appendChild(copyBtn);
					}

					// Deactivate (or Reactivate if already inactive).
					const toggleBtn = document.createElement('button');
					toggleBtn.type = 'button';
					if (row.estado === 'inactivo') {
						toggleBtn.className = 'anpa-area-secondary';
						toggleBtn.textContent = 'Activar';
						toggleBtn.addEventListener('click', async () => {
							showMessage(root, '', 'info');
							if (!window.confirm('Activar a actividade "' + (row.nome || '') + '"?')) { return; }
							const body = Object.assign({}, row, { estado: 'activo' });
							const done = await tokenRequest('PUT', adminUrl('actividad/' + encodeURIComponent(row.id)), areaToken, body, root);
							if (done) {
								showMessage(root, 'Actividade activada.', 'success');
								await loadAdminSection('actividades');
							}
						});
					} else {
						toggleBtn.className = 'anpa-area-secondary anpa-area-danger';
						toggleBtn.dataset.action = 'deactivate';
						toggleBtn.textContent = 'Desactivar';
						toggleBtn.addEventListener('click', async () => {
							showMessage(root, '', 'info');
							if (!window.confirm('Desactivar a actividade "' + (row.nome || '') + '"? Os datos manteranse na base de datos pero non aparecerá nos listados por defecto.')) {
								return;
							}
							const done = await tokenRequest('DELETE', adminUrl('actividad/' + encodeURIComponent(row.id)), areaToken, null, root);
							if (done) {
								showMessage(root, 'Actividade desactivada.', 'success');
								await loadAdminSection('actividades');
							}
						});
					}
					actions.appendChild(toggleBtn);

					tr.appendChild(actions);
					tbody.appendChild(tr);
				});
				table.appendChild(tbody);
				listHost.appendChild(table);

				// Search input wiring.
				if (searchInput) {
					searchInput.addEventListener('input', function() {
						if (window._actividadesSearchTimer) { clearTimeout(window._actividadesSearchTimer); }
						window._actividadesSearchTimer = setTimeout(function() { renderActividadesFiltered(); }, 250);
					});
				}
				}

			cursoSelect.addEventListener('change', () => {
				try { sessionStorage.setItem('anpa_admin_actividad_curso', cursoSelect.value); } catch (_) {}
				renderActividadesFiltered();
			});
			renderActividadesFiltered();
		}

		function buildActividadForm(act, empresaList) {
			const isEdit = !!act.id;
			const form = document.createElement('div');
			form.className = 'anpa-socio-edit';

			const title = document.createElement('h3');
			title.textContent = isEdit ? ('Editar actividade: ' + (act.nome || '')) : 'Nova actividade';
			form.appendChild(title);

			function addLabel(text) {
				const l = document.createElement('label');
				l.textContent = text;
				form.appendChild(l);
			}

			addLabel('Empresa');
			const empresaSel = document.createElement('select');
			(empresaList || []).forEach((e) => {
				const o = document.createElement('option');
				o.value = String(e.id);
				o.textContent = e.nome;
				if (String(act.empresa_id || '') === String(e.id)) { o.selected = true; }
				empresaSel.appendChild(o);
			});
			form.appendChild(empresaSel);

			addLabel('Nome');
			const nome = document.createElement('input');
			nome.type = 'text';
			nome.value = act.nome || '';
			form.appendChild(nome);

			addLabel('Icono para a tarxeta pública (emoji)');
			const icono = document.createElement('input');
			icono.type = 'text';
			icono.maxLength = 8;
			icono.value = act.icono || '🎒';
			form.appendChild(icono);

			addLabel('Descrición');
			const descripcion = document.createElement('textarea');
			descripcion.value = act.descripcion || '';
			form.appendChild(descripcion);

			addLabel('Curso escolar (ex. 2025/2026)');
			const curso = document.createElement('input');
			curso.type = 'text';
			curso.value = act.curso_escolar || '';
			form.appendChild(curso);

			addLabel('Franxa por defecto da actividade (opcional; o horario público usa o horario de cada grupo)');
			const franxa = document.createElement('input');
			franxa.type = 'text';
			franxa.inputMode = 'text';
			franxa.value = act.franxa || '';
			form.appendChild(franxa);

			// fase7: option sets replace the numeric age range.
			function buildCheckboxGroup(labelText, opts, selectedCsv) {
				addLabel(labelText);
				const wrap = document.createElement('div');
				wrap.className = 'anpa-area-checkgroup';
				const selected = String(selectedCsv || '').split(',').map((s) => s.trim()).filter(Boolean);
				const boxes = [];
				opts.forEach((opt) => {
					const lab = document.createElement('label');
					lab.className = 'anpa-area-checkbox';
					const cb = document.createElement('input');
					cb.type = 'checkbox';
					cb.value = opt.value;
					if (selected.indexOf(opt.value) !== -1) { cb.checked = true; }
					const span = document.createElement('span');
					span.textContent = opt.label;
					lab.appendChild(cb);
					lab.appendChild(span);
					wrap.appendChild(lab);
					boxes.push(cb);
				});
				form.appendChild(wrap);
				return () => boxes.filter((b) => b.checked).map((b) => b.value);
			}

			const getHorarios = buildCheckboxGroup('Horario (polo menos un)', [
				{ value: 'manha', label: 'Mañá' },
				{ value: 'tarde', label: 'Tarde' },
			], act.horarios);

			const getGrupos = buildCheckboxGroup('Grupo (polo menos un)', [
				{ value: '1-2-3', label: '1º, 2º e 3º' },
				{ value: '4-5-6', label: '4º, 5º e 6º' },
			], act.grupos);

			const getDias = buildCheckboxGroup('Días da semana (polo menos un)', [
				{ value: 'luns', label: 'Luns' },
				{ value: 'martes', label: 'Martes' },
				{ value: 'mercores', label: 'Mércores' },
				{ value: 'xoves', label: 'Xoves' },
				{ value: 'venres', label: 'Venres' },
			], act.dias);

			addLabel('Custo (€) — opcional');
			const custo = document.createElement('input');
			custo.type = 'text';
			custo.inputMode = 'decimal';
			custo.value = (act.custo === null || act.custo === undefined) ? '' : act.custo;
			form.appendChild(custo);

			const activaLabel = document.createElement('label');
			activaLabel.className = 'anpa-area-checkbox';
			const activa = document.createElement('input');
			activa.type = 'checkbox';
			activa.checked = (act.estado || 'activo') === 'activo';
			const activaSpan = document.createElement('span');
			activaSpan.textContent = 'Actividade activa';
			activaLabel.appendChild(activa);
			activaLabel.appendChild(activaSpan);
			form.appendChild(activaLabel);

			const actions = document.createElement('div');
			actions.className = 'anpa-area-actions';

			const saveBtn = document.createElement('button');
			saveBtn.type = 'button';
			saveBtn.textContent = isEdit ? 'Gardar cambios' : 'Crear actividade';
			saveBtn.addEventListener('click', async () => {
				showMessage(root, '', 'info');
				const horarios = getHorarios();
				const grupos = getGrupos();
				const dias = getDias();
				const payload = {
					empresa_id: parseInt(empresaSel.value, 10) || 0,
					nome: (nome.value || '').trim(),
					icono: (icono.value || '').trim() || '🎒',
					descripcion: (descripcion.value || '').trim(),
					curso_escolar: (curso.value || '').trim(),
					franxa: (franxa.value || '').trim(),
					horarios: horarios,
					grupos: grupos,
					dias: dias,
					custo: (custo.value || '').trim(),
					estado: activa.checked ? 'activo' : 'inactivo',
				};
				if (!payload.empresa_id || !payload.nome || !payload.descripcion || !payload.curso_escolar) {
					showMessage(root, 'Completa empresa, nome, descrición e curso escolar.', 'error');
					return;
				}
				if (!horarios.length || !grupos.length || !dias.length) {
					showMessage(root, 'Selecciona polo menos un horario, un grupo e un día.', 'error');
					return;
				}
				let result;
				if (isEdit) {
					result = await tokenRequest('PUT', adminUrl('actividad/' + encodeURIComponent(act.id)), areaToken, payload, root);
				} else {
					result = await tokenRequest('POST', adminUrl('actividades'), areaToken, payload, root);
				}
				if (result) {
					showMessage(root, isEdit ? 'Actividade actualizada.' : 'Actividade creada.', 'success');
					await loadAdminSection('actividades');
				}
			});
			actions.appendChild(saveBtn);

			const cancelBtn = document.createElement('button');
			cancelBtn.type = 'button';
			cancelBtn.className = 'anpa-area-secondary';
			cancelBtn.textContent = 'Cancelar';
			cancelBtn.addEventListener('click', () => {
				showMessage(root, '', 'info');
				const host = adminContentEl.querySelector('[data-actividad-form-host]');
				if (host) { host.textContent = ''; }
			});
			actions.appendChild(cancelBtn);

			form.appendChild(actions);
			return form;
		}

		// ── Grupos management (fase7 PR-7b) ──────────────────────────────
		const DIA_LABELS = { luns: 'Luns', martes: 'Martes', mercores: 'Mércores', xoves: 'Xoves', venres: 'Venres' };
		const RANGE_LABELS = { '1-2-3': '1º-2º-3º', '4-5-6': '4º-5º-6º' };

		async function renderGruposPanel(act, host) {
			host.textContent = '';
			const panel = document.createElement('div');
			panel.className = 'anpa-socio-edit';

			const title = document.createElement('h3');
			title.textContent = 'Grupos de: ' + (act.nome || '');
			panel.appendChild(title);

			const actRanges = String(act.grupos || '').split(',').map((s) => s.trim()).filter(Boolean);
			const actDias = String(act.dias || '').split(',').map((s) => s.trim()).filter(Boolean);

			const addBtn = document.createElement('button');
			addBtn.type = 'button';
			addBtn.textContent = 'Engadir grupo';
			addBtn.addEventListener('click', () => {
				const formHost = panel.querySelector('[data-grupo-form-host]');
				formHost.textContent = '';
				formHost.appendChild(buildGrupoForm(act, {}, actRanges, actDias, host));
				scrollToAdminForm(formHost);
				});
				panel.appendChild(addBtn);

			const formHost = document.createElement('div');
			formHost.dataset.grupoFormHost = '';
			panel.appendChild(formHost);

			const rows = await tokenRequest('GET', adminUrl('actividad/' + encodeURIComponent(act.id) + '/grupos'), areaToken, null, root);
			const list = Array.isArray(rows) ? rows : [];
			if (!list.length) {
				const empty = document.createElement('p');
				empty.className = 'anpa-area-muted';
				empty.textContent = 'Sen grupos. Engade o primeiro.';
				panel.appendChild(empty);
			} else {
				const table = document.createElement('table');
				table.className = 'anpa-admin-table';
				const thead = document.createElement('thead');
				const hr = document.createElement('tr');
				['grupo', 'horario', 'días', 'min', 'max', 'estado', ''].forEach((c) => {
					const th = document.createElement('th');
					th.textContent = c;
					hr.appendChild(th);
				});
				thead.appendChild(hr);
				table.appendChild(thead);
				const tbody = document.createElement('tbody');
				list.forEach((g) => {
					const tr = document.createElement('tr');
					if (g.estado === 'pechado') { tr.classList.add('anpa-row-baixa'); }
					const dias = String(g.dias || '').split(',').filter(Boolean).map((d) => DIA_LABELS[d] || d).join(', ');
					[RANGE_LABELS[g.curso_range] || g.curso_range, g.franxa || '', dias, g.min_pupilos, g.max_pupilos, g.estado].forEach((v) => {
						const td = document.createElement('td');
						td.textContent = v === null || v === undefined ? '' : String(v);
						tr.appendChild(td);
					});
					const acts = document.createElement('td');

					const ed = document.createElement('button');
					ed.type = 'button';
					ed.className = 'anpa-area-secondary';
					ed.textContent = 'Editar';
					ed.addEventListener('click', () => {
						formHost.textContent = '';
						formHost.appendChild(buildGrupoForm(act, g, actRanges, actDias, host));
						scrollToAdminForm(formHost);
					});
					acts.appendChild(ed);

					const matsBtn = document.createElement('button');
					matsBtn.type = 'button';
					matsBtn.className = 'anpa-area-secondary';
					matsBtn.textContent = 'Matrículas';
					matsBtn.addEventListener('click', async () => {
						await renderGrupoMatriculas(act, g, list, host);
					});
					acts.appendChild(matsBtn);

					const toggle = document.createElement('button');
					toggle.type = 'button';
					toggle.className = 'anpa-area-secondary';
					toggle.textContent = (g.estado === 'aberto') ? 'Pechar' : 'Abrir';
					toggle.addEventListener('click', async () => {
						const next = (g.estado === 'aberto') ? 'pechado' : 'aberto';
						const done = await tokenRequest('POST', adminUrl('grupo/' + encodeURIComponent(g.id) + '/estado'), areaToken, { estado: next }, root);
						if (done) {
							showMessage(root, 'Estado do grupo actualizado.', 'success');
							await renderGruposPanel(act, host);
						}
					});
					acts.appendChild(toggle);

					const del = document.createElement('button');
					del.type = 'button';
					del.className = 'anpa-area-secondary anpa-area-danger';
					del.dataset.action = 'deactivate';
					del.textContent = 'Desactivar';
					del.addEventListener('click', async () => {
						if (!window.confirm('Desactivar este grupo? Os datos manteranse na base de datos pero non aparecerá nos listados por defecto.')) { return; }
						const done = await tokenRequest('DELETE', adminUrl('grupo/' + encodeURIComponent(g.id)), areaToken, null, root);
						if (done) {
							showMessage(root, 'Grupo eliminado.', 'success');
							await renderGruposPanel(act, host);
						}
					});
					acts.appendChild(del);

					tr.appendChild(acts);
					tbody.appendChild(tr);
				});
				table.appendChild(tbody);
				panel.appendChild(table);
			}

			const back = document.createElement('button');
			back.type = 'button';
			back.className = 'anpa-area-secondary';
			back.textContent = 'Pechar grupos';
			back.addEventListener('click', () => { host.textContent = ''; });
			panel.appendChild(back);

			host.appendChild(panel);
		}

		async function renderGrupoMatriculas(act, grupo, allGroups, host) {
			host.textContent = '';
			const panel = document.createElement('div');
			panel.className = 'anpa-socio-edit';

			const title = document.createElement('h3');
			title.textContent = 'Matrículas: ' + (act.nome || '') + ' — ' + (RANGE_LABELS[grupo.curso_range] || grupo.curso_range);
			panel.appendChild(title);

			const rows = await tokenRequest('GET', adminUrl('grupo/' + encodeURIComponent(grupo.id) + '/matriculas'), areaToken, null, root);
			const list = Array.isArray(rows) ? rows : [];

			// Other groups of the same activity = move targets.
			const targets = (allGroups || []).filter((x) => String(x.id) !== String(grupo.id));

			if (!list.length) {
				const empty = document.createElement('p');
				empty.className = 'anpa-area-muted';
				empty.textContent = 'Este grupo non ten matrículas.';
				panel.appendChild(empty);
			} else {
				const table = document.createElement('table');
				table.className = 'anpa-admin-table';
				const thead = document.createElement('thead');
				const hr = document.createElement('tr');
				['alumno/a', 'curso', 'estado', 'mover a', ''].forEach((c) => {
					const th = document.createElement('th');
					th.textContent = c;
					hr.appendChild(th);
				});
				thead.appendChild(hr);
				table.appendChild(thead);
				const tbody = document.createElement('tbody');
				list.forEach((m) => {
					const tr = document.createElement('tr');
					const who = ((m.fillo_nome || '') + ' ' + (m.fillo_apelidos || '')).trim();
					let estado = m.estado;
					if (m.estado === 'lista_espera' && m.posicion) { estado += ' (#' + m.posicion + ')'; }
					[who, m.curso, estado].forEach((v) => {
						const td = document.createElement('td');
						td.textContent = v === null || v === undefined ? '' : String(v);
						tr.appendChild(td);
					});

					const moveTd = document.createElement('td');
					const sel = document.createElement('select');
					const none = document.createElement('option');
					none.value = '';
					none.textContent = '—';
					sel.appendChild(none);
					targets.forEach((t) => {
						const o = document.createElement('option');
						o.value = String(t.id);
						o.textContent = (RANGE_LABELS[t.curso_range] || t.curso_range) + ' · ' + (t.franxa || '') + ' · ' + String(t.dias || '').split(',').filter(Boolean).map((d) => DIA_LABELS[d] || d).join(', ');
						sel.appendChild(o);
					});
					moveTd.appendChild(sel);
					tr.appendChild(moveTd);

					const actTd = document.createElement('td');
					const moveBtn = document.createElement('button');
					moveBtn.type = 'button';
					moveBtn.className = 'anpa-area-secondary';
					moveBtn.textContent = 'Mover';
					moveBtn.addEventListener('click', async () => {
						if (!sel.value) { showMessage(root, 'Selecciona un grupo destino.', 'error'); return; }
						const done = await tokenRequest('POST', adminUrl('matricula/' + encodeURIComponent(m.id) + '/mover'), areaToken, { grupo_id: parseInt(sel.value, 10) || 0 }, root);
						if (done) {
							showMessage(root, 'Alumno/a movido/a.', 'success');
							await renderGrupoMatriculas(act, grupo, allGroups, host);
						}
					});
					actTd.appendChild(moveBtn);
					tr.appendChild(actTd);

					tbody.appendChild(tr);
				});
				table.appendChild(tbody);
				panel.appendChild(table);
			}

			const back = document.createElement('button');
			back.type = 'button';
			back.className = 'anpa-area-secondary';
			back.textContent = 'Volver aos grupos';
			back.addEventListener('click', async () => { await renderGruposPanel(act, host); });
			panel.appendChild(back);

			host.appendChild(panel);
		}

		function buildGrupoForm(act, g, actRanges, actDias, host) {			const isEdit = !!g.id;
			const form = document.createElement('div');
			form.className = 'anpa-socio-edit';

			const title = document.createElement('h3');
			title.textContent = isEdit ? 'Editar grupo' : 'Novo grupo';
			form.appendChild(title);

			function addLabel(text) {
				const l = document.createElement('label');
				l.textContent = text;
				form.appendChild(l);
			}

			addLabel('Grupo curricular');
			const rangeSel = document.createElement('select');
			actRanges.forEach((r) => {
				const o = document.createElement('option');
				o.value = r;
				o.textContent = RANGE_LABELS[r] || r;
				if (g.curso_range === r) { o.selected = true; }
				rangeSel.appendChild(o);
			});
			form.appendChild(rangeSel);

			addLabel('Horario do grupo (ex. 14:20-15:10)');
			const franxaInput = document.createElement('input');
			franxaInput.type = 'text';
			franxaInput.inputMode = 'text';
			franxaInput.value = g.franxa || '';
			form.appendChild(franxaInput);

			addLabel('Días (polo menos un)');
			const diasWrap = document.createElement('div');
			diasWrap.className = 'anpa-area-checkgroup';
			const selectedDias = String(g.dias || '').split(',').map((s) => s.trim()).filter(Boolean);
			const diaBoxes = [];
			actDias.forEach((d) => {
				const lab = document.createElement('label');
				lab.className = 'anpa-area-checkbox';
				const cb = document.createElement('input');
				cb.type = 'checkbox';
				cb.value = d;
				if (selectedDias.indexOf(d) !== -1) { cb.checked = true; }
				const span = document.createElement('span');
				span.textContent = DIA_LABELS[d] || d;
				lab.appendChild(cb);
				lab.appendChild(span);
				diasWrap.appendChild(lab);
				diaBoxes.push(cb);
			});
			form.appendChild(diasWrap);

			addLabel('Mínimo de prazas');
			const minInput = document.createElement('input');
			minInput.type = 'number';
			minInput.min = '0';
			minInput.value = (g.min_pupilos === undefined || g.min_pupilos === null) ? '0' : g.min_pupilos;
			form.appendChild(minInput);

			addLabel('Máximo de prazas');
			const maxInput = document.createElement('input');
			maxInput.type = 'number';
			maxInput.min = '1';
			maxInput.value = (g.max_pupilos === undefined || g.max_pupilos === null) ? '' : g.max_pupilos;
			form.appendChild(maxInput);

			const estadoLabel = document.createElement('label');
			estadoLabel.className = 'anpa-area-checkbox';
			const abertoCb = document.createElement('input');
			abertoCb.type = 'checkbox';
			abertoCb.checked = (g.estado || 'aberto') === 'aberto';
			const estadoSpan = document.createElement('span');
			estadoSpan.textContent = 'Grupo aberto (acepta matrículas)';
			estadoLabel.appendChild(abertoCb);
			estadoLabel.appendChild(estadoSpan);
			form.appendChild(estadoLabel);

			const actions = document.createElement('div');
			actions.className = 'anpa-area-actions';

			const saveBtn = document.createElement('button');
			saveBtn.type = 'button';
			saveBtn.textContent = isEdit ? 'Gardar grupo' : 'Crear grupo';
			saveBtn.addEventListener('click', async () => {
				showMessage(root, '', 'info');
				const dias = diaBoxes.filter((b) => b.checked).map((b) => b.value);
				if (!dias.length) {
					showMessage(root, 'Selecciona polo menos un día.', 'error');
					return;
				}
				const payload = {
					curso_range: rangeSel.value,
					franxa: (franxaInput.value || '').trim(),
					dias: dias,
					min_pupilos: parseInt(minInput.value, 10) || 0,
					max_pupilos: parseInt(maxInput.value, 10) || 0,
					estado: abertoCb.checked ? 'aberto' : 'pechado',
				};
				if (!payload.franxa) {
					showMessage(root, 'Indica o horario do grupo.', 'error');
					return;
				}
				if (payload.max_pupilos <= 0 || payload.max_pupilos < payload.min_pupilos) {
					showMessage(root, 'O máximo debe ser maior que 0 e non menor que o mínimo.', 'error');
					return;
				}
				let result;
				if (isEdit) {
					result = await tokenRequest('PUT', adminUrl('grupo/' + encodeURIComponent(g.id)), areaToken, payload, root);
				} else {
					result = await tokenRequest('POST', adminUrl('actividad/' + encodeURIComponent(act.id) + '/grupos'), areaToken, payload, root);
				}
				if (result) {
					showMessage(root, isEdit ? 'Grupo actualizado.' : 'Grupo creado.', 'success');
					await renderGruposPanel(act, host);
				}
			});
			actions.appendChild(saveBtn);

			const cancelBtn = document.createElement('button');
			cancelBtn.type = 'button';
			cancelBtn.className = 'anpa-area-secondary';
			cancelBtn.textContent = 'Cancelar';
			cancelBtn.addEventListener('click', () => {
				const fh = host.querySelector('[data-grupo-form-host]');
				if (fh) { fh.textContent = ''; }
			});
			actions.appendChild(cancelBtn);

			form.appendChild(actions);
			return form;
		}
		// include_banking adds decrypted banking columns. The passphrase is
		// cleared from the DOM immediately after it is read.
		async function adminExportFull() {
			if (!areaToken) {
				showStep(root, 'email');
				showMessage(root, 'A sesión caducou. Volve entrar.', 'error');
				return;
			}

			const passInput = root.querySelector('#anpa-admin-export-pass');
			let passphrase = (passInput && passInput.value) || '';
			const includeBanking = !!(root.querySelector('#anpa-admin-export-banking') || {}).checked;
			if (passInput) {
				passInput.value = '';
			}

			if (!passphrase) {
				showMessage(root, 'Introduce o contrasinal de descifrado.', 'error');
				return;
			}

			let response;
			try {
				const payload = JSON.stringify({ passphrase: passphrase, include_banking: includeBanking });
				passphrase = '';
				response = await fetch(adminUrl('export/full'), {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-Anpa-Area-Token': areaToken,
					},
					body: payload,
				});
			} catch (_) {
				showMessage(root, 'Erro de rede. Téntao de novo.', 'error');
				return;
			}

			if (response.status === 401) {
				areaToken = '';
				showStep(root, 'email');
				showMessage(root, 'A sesión caducou. Volve entrar.', 'error');
				return;
			}
			if (!response.ok) {
				let message = 'Non foi posible descargar o ficheiro.';
				try {
					const body = await response.json();
					if (body && body.message) {
						message = body.message;
					}
				} catch (_) {
					// Non-JSON error body; keep the generic message.
				}
				showMessage(root, message, 'error');
				return;
			}

			const blob = await response.blob();
			const url = URL.createObjectURL(blob);
			const a = document.createElement('a');
			a.href = url;
			a.download = 'anpa-export-completo.csv';
			document.body.appendChild(a);
			a.click();
			document.body.removeChild(a);
			URL.revokeObjectURL(url);
			showMessage(root, 'Descarga iniciada.', 'success');
		}

		async function adminDownload(entity) {
			if (!areaToken) {
				showStep(root, 'email');
				showMessage(root, 'A sesión caducou. Volve entrar.', 'error');
				return;
			}
			const path = entity === 'alumnos' ? 'export/alumnos' : 'export/' + entity;

			let response;
			try {
				response = await fetch(adminUrl(path), {
					method: 'GET',
					headers: { 'X-Anpa-Area-Token': areaToken },
				});
			} catch (_) {
				showMessage(root, 'Erro de rede. Téntao de novo.', 'error');
				return;
			}

			if (response.status === 401) {
				areaToken = '';
				showStep(root, 'email');
				showMessage(root, 'A sesión caducou. Volve entrar.', 'error');
				return;
			}
			if (response.status === 403) {
				showMessage(root, 'Non tes permisos para esta acción.', 'error');
				return;
			}
			if (!response.ok) {
				showMessage(root, 'Non foi posible descargar o ficheiro.', 'error');
				return;
			}

			const blob = await response.blob();
			const url = URL.createObjectURL(blob);
			const a = document.createElement('a');
			a.href = url;
			a.download = 'anpa-' + entity + '.csv';
			document.body.appendChild(a);
			a.click();
			document.body.removeChild(a);
			URL.revokeObjectURL(url);
		}

		// Collapsible admin sub-panels (Exportar CSV / Descargar todo).
		// Accordion behaviour: opening one closes the other.
		function closeAdminPanels() {
			root.querySelectorAll('.anpa-admin-panel').forEach((p) => { p.hidden = true; });
			root.querySelectorAll('[data-action="toggle-exports"], [data-action="toggle-fullexport"]').forEach((b) => {
				b.setAttribute('aria-expanded', 'false');
			});
		}

		function toggleAdminPanel(name) {
			const panel = root.querySelector('[data-panel="' + name + '"]');
			const btn = root.querySelector('[data-action="toggle-' + name + '"]');
			if (!panel) {
				return;
			}
			const willShow = panel.hidden;
			closeAdminPanels();
			if (willShow) {
				panel.hidden = false;
				if (btn) {
					btn.setAttribute('aria-expanded', 'true');
				}
			}
		}

		bind('[data-action="open-admin"]', 'click', async () => {
			showMessage(root, '', 'info');
			// Check master init status first.
			const initUrl = root.dataset.masterInitUrl;
			if (!initUrl) {
				showMessage(root, 'Erro de configuración (falta URL de inicialización).', 'error');
				return;
			}
			const initStatus = await tokenRequest('GET', initUrl, areaToken, null, root);
			if (!initStatus) {
				return;
			}
			if (!initStatus.initialized) {
				// Show master init wizard (one-time).
				const aviso = root.querySelector('[data-master-aviso]');
				if (aviso && initStatus.aviso_master) {
					aviso.textContent = initStatus.aviso_master;
				}
				const hint = root.querySelector('[data-passphrase-hint]');
				if (hint && initStatus.generated_passphrase) {
					hint.textContent = 'Contrasinal suxerido: ' + initStatus.generated_passphrase;
				}
				const inp = document.getElementById('anpa-master-passphrase');
				if (inp && initStatus.generated_passphrase) {
					inp.value = initStatus.generated_passphrase;
				}
				showStep(root, 'master-init');
				return;
			}
			// Initialized — prepare admin panel.
			adminContentEl.textContent = '';
			closeAdminPanels();
			root.classList.add('anpa-area-wide');
			if (initStatus.admin_password_set) {
				// Need admin password this session.
				showStep(root, 'admin-auth');
			} else {
				showStep(root, 'admin');
				await loadAdminSection('socios');
			}
		});

		bind('[data-action="regenerate-passphrase"]', 'click', async () => {
			showMessage(root, '', 'info');
			const initUrl = root.dataset.masterInitUrl;
			if (!initUrl) { return; }
			const initStatus = await tokenRequest('GET', initUrl, areaToken, null, root);
			if (initStatus && initStatus.generated_passphrase) {
				const inp = document.getElementById('anpa-master-passphrase');
				if (inp) { inp.value = initStatus.generated_passphrase; }
				const hint = root.querySelector('[data-passphrase-hint]');
				if (hint) { hint.textContent = 'Contrasinal suxerido: ' + initStatus.generated_passphrase; }
			}
		});

		bind('[data-action="master-init-confirm"]', 'click', async () => {
			showMessage(root, '', 'info');
			const inp = document.getElementById('anpa-master-passphrase');
			const passphrase = (inp && inp.value || '').trim();
			const parts = passphrase.split('-').filter(Boolean);
			if (parts.length < 5 || passphrase.length < 20) {
				showMessage(root, 'O contrasinal debe ter polo menos 5 palabras separadas por guións (ex: ceu-mar-sol-lua-vento).', 'error');
				return;
			}
			if (!root.dataset.masterInitPostUrl) {
				showMessage(root, 'Erro de configuración (falta URL de inicialización).', 'error');
				return;
			}
			const result = await tokenRequest('POST', root.dataset.masterInitPostUrl, areaToken, { passphrase }, root);
			if (result && result.success) {
				showMessage(root, 'Base de datos inicializada. Agora establece o contrasinal de administración.', 'success');
				// After init, admin password is set to the passphrase; force change.
				showStep(root, 'admin-auth');
			}
		});

		bind('[data-action="admin-auth-submit"]', 'click', async () => {
			showMessage(root, '', 'info');
			const inp = document.getElementById('anpa-admin-auth-pass');
			const password = (inp && inp.value || '');
			if (!password) {
				showMessage(root, 'Introduce o contrasinal de administración.', 'error');
				return;
			}
			if (!root.dataset.adminAuthUrl) {
				showMessage(root, 'Erro de configuración (falta URL de autenticación).', 'error');
				return;
			}
			const result = await tokenRequest('POST', root.dataset.adminAuthUrl, areaToken, { admin_password: password }, root);
			if (result && result.success) {
				// Clear the password field.
				if (inp) { inp.value = ''; }
				showStep(root, 'admin');
				await loadAdminSection('socios');
			}
		});


		bind('[data-action="back-profile-admin"]', 'click', () => {
			showMessage(root, '', 'info');
			adminContentEl.textContent = '';
			closeAdminPanels();
			root.classList.remove('anpa-area-wide');
			showStep(root, 'profile');
		});

		bind('[data-action="toggle-exports"]', 'click', () => {
			showMessage(root, '', 'info');
			toggleAdminPanel('exports');
		});

		bind('[data-action="toggle-fullexport"]', 'click', () => {
			showMessage(root, '', 'info');
			toggleAdminPanel('fullexport');
		});

		root.querySelectorAll('[data-admin-section]').forEach((btn) => {
			btn.addEventListener('click', async () => {
				showMessage(root, '', 'info');
				await loadAdminSection(btn.dataset.adminSection);
			});
		});

		root.querySelectorAll('[data-admin-export]').forEach((btn) => {
			btn.addEventListener('click', async () => {
				showMessage(root, '', 'info');
				await adminDownload(btn.dataset.adminExport);
			});
		});

		bind('[data-action="admin-export-full"]', 'click', async () => {
			showMessage(root, '', 'info');
			await adminExportFull();
		});

		// Delegated handler for the inline "Editar" buttons in the socios table.
		adminContentEl.addEventListener('click', (event) => {
			const target = event.target;
			if (!(target instanceof HTMLElement)) {
				return;
			}
			const editEmail = target.dataset.socioEdit;
			if (editEmail) {
				showMessage(root, '', 'info');
				openSocioEdit(editEmail);
			}
		});

		// ── Empresa panel ───────────────────────────────────────────────

		async function empresaCallChecked(method, url, body) {
			let response;
			try {
				response = await fetch(url, {
					method,
					headers: {
						'Content-Type': 'application/json',
						'X-Anpa-Empresa-Token': empresaToken,
					},
					body: body ? JSON.stringify(body) : undefined,
				});
			} catch (_) {
				showMessage(root, 'Erro de rede. Téntao de novo.', 'error');
				return null;
			}

			if (response.status === 204) {
				return {};
			}

			if (response.status === 401) {
				empresaToken = '';
				showStep(root, 'email');
				showMessage(root, 'A sesión caducou. Volve entrar.', 'error');
				return null;
			}

			if (response.status === 429) {
				showMessage(root, 'Demasiadas solicitudes. Agarda uns minutos.', 'error');
				return null;
			}

			let data = {};
			try {
				data = await response.json();
			} catch (_) {
				data = {};
			}

			if (!response.ok) {
				showMessage(root, data.message || 'Non foi posible completar a operación.', 'error');
				return null;
			}

			return data;
		}

		function renderEmpresaProfile(profile) {
			root.querySelector('[data-empresa-nome]').textContent = profile.nome || '';
			root.querySelector('[data-empresa-email]').textContent = profile.email || '';
		}

		async function loadEmpresaPanel() {
			showMessage(root, '', 'info');
			const profile = await empresaCallChecked('GET', root.dataset.empresaMeUrl, null);
			if (!profile) {
				return;
			}
			renderEmpresaProfile(profile);
			showStep(root, 'empresa');
			startIdleTimers();
			showSessionHeader(profile.email || '');
		}

		// Empresa export: download CSV via authenticated fetch.
		bind('[data-action="empresa-export"]', 'click', async () => {
			showMessage(root, '', 'info');
			if (!empresaToken) {
				showStep(root, 'email');
				showMessage(root, 'A sesión caducou. Volve entrar.', 'error');
				return;
			}

			let response;
			try {
				response = await fetch(root.dataset.empresaExportUrl, {
					method: 'GET',
					headers: { 'X-Anpa-Empresa-Token': empresaToken },
				});
			} catch (_) {
				showMessage(root, 'Erro de rede. Téntao de novo.', 'error');
				return;
			}

			if (response.status === 401) {
				empresaToken = '';
				showStep(root, 'email');
				showMessage(root, 'A sesión caducou. Volve entrar.', 'error');
				return;
			}

			if (!response.ok) {
				showMessage(root, 'Non foi posible descargar o ficheiro.', 'error');
				return;
			}

			const blob = await response.blob();
			const url = URL.createObjectURL(blob);
			const a = document.createElement('a');
			a.href = url;
			a.download = 'alumnos-empresa.csv';
			document.body.appendChild(a);
			a.click();
			document.body.removeChild(a);
			URL.revokeObjectURL(url);
		});

		// Empresa logout
		bind('[data-action="empresa-logout"]', 'click', async () => {
			if (empresaToken) {
				await empresaCallChecked('DELETE', root.dataset.empresaLogoutUrl, null);
			}
			empresaToken = '';
			clearIdleTimers();
			hideSessionHeader();
			showStep(root, 'email');
			showMessage(root, 'Sesión pechada.', 'success');
		});

		// ── Persistent session recovery on page load ─────────────────────
		// If the browser has a valid stored area token, try to restore the
		// profile without asking for a new code. On failure, silently fall
		// back to the email step.
		function goToLogin() {
			// Centralised login lives on the unified /socios/ page. Sending the
			// user there avoids showing the old area email form (and its flash).
			var loginUrl = root.dataset.loginUrl || '';
			if (loginUrl) {
				window.location.href = loginUrl;
			} else {
				showStep(root, 'email');
			}
		}

		(async function restoreSession() {
			const stored = loadAreaToken();
			if (!stored || !root.dataset.sessionStatusUrl) {
				goToLogin();
				return;
			}
			const status = await callJson(root.dataset.sessionStatusUrl, {
				method: 'GET',
				headers: { 'X-Anpa-Area-Token': stored },
			}, root);
			if (!status || !status.email) {
				clearAreaToken();
				goToLogin();
				return;
			}
			areaToken = stored;
			email = status.email;
			fillProfile(root, status);
			showSessionHeader(status.email);
			startIdleTimers();
			if (status.rol === 'master') {
				if (adminContentEl) { adminContentEl.textContent = ''; }
				closeAdminPanels();
				root.classList.add('anpa-area-wide');
				// Gate the admin panel behind the admin password when one is set,
				// otherwise the admin REST calls 403 with "password required" and
				// the panel never loads (the prompt was being skipped here).
				if (status.admin_password_set) {
					showStep(root, 'admin-auth');
				} else {
					showStep(root, 'admin');
					await loadAdminSection('socios');
				}
			} else {
				showStep(root, 'profile');
			}
		}());
	});

	// ── Public API for unified flow (T5) ────────────────────────
	window.AnpaArea = {
		showMessage: showMessage,
		showStep: showStep,
		getSessionToken: loadAreaToken,
		saveSessionToken: saveAreaToken,
		clearSessionToken: clearAreaToken,
		showSessionHeader: showSessionHeader,
		hideSessionHeader: hideSessionHeader,
	};
})();
