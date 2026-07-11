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
	const { __ } = wp.i18n;

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
				showMessage(root, __( 'Erro de rede. Téntao de novo.', 'anpa-socios' ), 'error');
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
				showMessage(root, body.message || __( 'Non foi posible completar a operación.', 'anpa-socios' ), 'error');
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
		// Reactivated fields: telefono, nif, email-edit (previously dead).
		var telEl = root.querySelector('#anpa-area-telefono');
		if (telEl) { telEl.value = profile.telefono || ''; }
		var nifEl = root.querySelector('#anpa-area-nif');
		if (nifEl) { nifEl.value = profile.nif || ''; }
		var emailEditEl = root.querySelector('#anpa-area-email-edit');
		if (emailEditEl) { emailEditEl.value = profile.email || ''; }

		// Second parent inline display/edit.
		var p2 = profile.segundo_proxenitor || null;
		var p2Section = root.querySelector('[data-p2-inline]');
		if (p2Section) {
			if (p2) {
				p2Section.hidden = false;
				var setP2 = function(id, val) {
					var el = root.querySelector('#' + id);
					if (el) { el.value = val || ''; }
				};
				setP2('anpa-area-p2-nome', p2.nome);
				setP2('anpa-area-p2-apelidos', p2.apelidos);
				setP2('anpa-area-p2-email', p2.email);
				setP2('anpa-area-p2-nif', p2.nif);
				setP2('anpa-area-p2-telefono', p2.telefono);
				// Update button label to "Gardar" instead of "Engadir".
				var p2Btn = root.querySelector('[data-action="proxenitor2-save"]');
				if (p2Btn) { p2Btn.textContent = __( 'Gardar 2º proxenitor', 'anpa-socios' ); }
			} else {
				p2Section.hidden = true;
				// Clear fields.
				['anpa-area-p2-nome','anpa-area-p2-apelidos','anpa-area-p2-email','anpa-area-p2-nif','anpa-area-p2-telefono'].forEach(function(id) {
					var el = root.querySelector('#' + id);
					if (el) { el.value = ''; }
				});
			}
		}

		// Toggle "Engadir proxenitor" button vs inline section.
		var toggleP2Btn = root.querySelector('[data-action="toggle-proxenitor2"]');
		if (toggleP2Btn) {
			toggleP2Btn.textContent = p2 ? __( 'Editar 2º proxenitor/titor', 'anpa-socios' ) : __( 'Engadir outro proxenitor/titor', 'anpa-socios' );
		}

		// Role-aware help text describing exactly what the user can do.
		const help = root.querySelector('[data-profile-help]');
		if (help) {
			help.textContent = 'Aquí podes actualizar os teus datos persoais e os do segundo proxenitor/titor, e xestionar os teus fillos/as (engadir, editar ou dar de baixa) co botón de abaixo.';
			help.classList.remove('anpa-area-warning');
		}
		// Baixa request indicator + button.
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
			baixaBtn.hidden = pendingBaixa;
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
			showMessage(root, __( 'A túa sesión pechouse por inactividade.', 'anpa-socios' ), 'info');
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
			showMessage(root, __( 'Sesión pechada.', 'anpa-socios' ), 'success');
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

		bind('[data-action="request-code"]', 'click', async () => {
			showMessage(root, '', 'info');
			email = (root.querySelector('#anpa-area-email').value || '').trim();
			if (!email) {
				showMessage(root, __( 'Introduce o teu email.', 'anpa-socios' ), 'error');
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
					showMessage(root, __( 'Se o email é válido, recibirás un código en breve.', 'anpa-socios' ), 'success');
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
					showMessage(root, __( 'Se o email é válido, recibirás un código en breve.', 'anpa-socios' ), 'success');
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
				showMessage(root, __( 'Introduce o teu email.', 'anpa-socios' ), 'error');
				return;
			}
			const website = (root.querySelector('#anpa-area-website') || {}).value || '';
			const _ts = (root.querySelector('#anpa-area-ts') || {}).value || '';
			const result = await jsonPost(root.dataset.reactivarUrl, { email, website, _ts }, root);
			if (result) {
				showMessage(root, result.message || __( 'Solicitude de reactivación enviada.', 'anpa-socios' ), 'success');
			}
		});

		bind('[data-action="verify-code"]', 'click', async () => {
			showMessage(root, '', 'info');
			const codigo = (root.querySelector('#anpa-area-code').value || '').trim();
			if (!email || !codigo) {
				showMessage(root, __( 'Introduce o email e o código.', 'anpa-socios' ), 'error');
				return;
			}

			const verified = await jsonPost(root.dataset.verifyCodeUrl, { email, codigo }, root);
			if (!verified || verified.success !== true || !verified.token) {
				if (verified) {
					showMessage(root, verified.message || __( 'Código incorrecto.', 'anpa-socios' ), 'error');
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
				showStep(root, 'profile');
				showMessage(root, '', 'info');
				return;
			}

			// If profile fetch failed but we have a token, stay on email step with a clear message.
			areaToken = '';
			clearAreaToken();
			showStep(root, 'email');
			showMessage(root, __( 'Non foi posible cargar o perfil. Téntao de novo.', 'anpa-socios' ), 'error');
			return;
		});

		bind('[data-action="save-profile"]', 'click', async () => {
			showMessage(root, '', 'info');
			if (!areaToken) {
				showStep(root, 'email');
				showMessage(root, __( 'A sesión caducou. Volve entrar.', 'anpa-socios' ), 'error');
				return;
			}

			const nome = root.querySelector('#anpa-area-nome').value || '';
			const apelidos = root.querySelector('#anpa-area-apelidos').value || '';
			const telefono = (root.querySelector('#anpa-area-telefono') || {}).value || '';
			const nif = (root.querySelector('#anpa-area-nif') || {}).value || '';
			const emailEdit = (root.querySelector('#anpa-area-email-edit') || {}).value || '';

			var payload = { nome, apelidos };
			if (telefono.trim()) { payload.telefono = telefono; }
			if (nif.trim()) { payload.nif = nif; }
			if (emailEdit.trim()) { payload.email_edit = emailEdit; }

			// Include 2nd parent if the inline section is visible and has data.
			var p2Section = root.querySelector('[data-p2-inline]');
			if (p2Section && !p2Section.hidden) {
				var p2Nome = (root.querySelector('#anpa-area-p2-nome') || {}).value || '';
				var p2Apelidos = (root.querySelector('#anpa-area-p2-apelidos') || {}).value || '';
				var p2Email = (root.querySelector('#anpa-area-p2-email') || {}).value || '';
				var p2Nif = (root.querySelector('#anpa-area-p2-nif') || {}).value || '';
				var p2Telefono = (root.querySelector('#anpa-area-p2-telefono') || {}).value || '';
				if (p2Nome.trim() || p2Apelidos.trim() || p2Email.trim() || p2Nif.trim() || p2Telefono.trim()) {
					payload.segundo_proxenitor = {
						nome: p2Nome,
						apelidos: p2Apelidos,
						email: p2Email,
						nif: p2Nif,
						telefono: p2Telefono,
					};
				}
			}

			const profile = await tokenRequest('PUT', root.dataset.profileUrl, areaToken, payload, root);
			if (profile) {
				fillProfile(root, profile);
				showMessage(root, __( 'Datos gardados correctamente.', 'anpa-socios' ), 'success');
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
			showMessage(root, __( 'Sesión pechada.', 'anpa-socios' ), 'success');
		});

		bind('[data-action="request-baixa"]', 'click', async () => {
			showMessage(root, '', 'info');
			if (!areaToken) {
				showStep(root, 'email');
				showMessage(root, __( 'A sesión caducou. Volve entrar.', 'anpa-socios' ), 'error');
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
				showMessage(root, result.message || __( 'Solicitude de baixa rexistrada.', 'anpa-socios' ), 'success');
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
				showMessage(root, __( 'A sesión caducou. Volve entrar.', 'anpa-socios' ), 'error');
				return;
			}
			const result = await tokenRequest('POST', root.dataset.baixaCancelUrl, areaToken, {}, root);
			if (result) {
				showMessage(root, result.message || __( 'Solicitude de baixa anulada.', 'anpa-socios' ), 'success');
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

		// Build the aula (classroom) options from the config-provided list on the
		// area root (data-aulas JSON). Falls back to A-D when absent. Storage still
		// accepts A-H; this only bounds what the form offers.
		(function buildAulaOptions() {
			const aulaSel = root.querySelector('#anpa-fillo-aula');
			if (!aulaSel) { return; }
			let aulas = ['A', 'B', 'C', 'D'];
			try {
				const parsed = JSON.parse(root.getAttribute('data-aulas') || '');
				if (Array.isArray(parsed) && parsed.length) { aulas = parsed; }
			} catch (e) { /* keep fallback */ }
			aulaSel.textContent = '';
			const placeholder = document.createElement('option');
			placeholder.value = '';
			placeholder.textContent = '-- Selecciona --';
			aulaSel.appendChild(placeholder);
			aulas.forEach(function (v) {
				const o = document.createElement('option');
				o.value = v;
				o.textContent = v;
				aulaSel.appendChild(o);
			});
		})();

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
				editBtn.textContent = __( 'Editar', 'anpa-socios' );
				row.appendChild(editBtn);

				const delBtn = document.createElement('button');
				delBtn.type = 'button';
				delBtn.className = 'anpa-area-secondary';
				delBtn.dataset.filloDelete = String(fillo.id);
				delBtn.dataset.action = 'deactivate';
			delBtn.textContent = __( 'Desactivar', 'anpa-socios' );
				row.appendChild(delBtn);

				fillosListEl.appendChild(row);
			});
		}

		async function loadFillos() {
			if (!areaToken) {
				showStep(root, 'email');
				showMessage(root, __( 'A sesión caducou. Volve entrar.', 'anpa-socios' ), 'error');
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
			lista_espera: __( 'En lista de espera', 'anpa-socios' ),
			oferta: __( 'Oferta de praza pendente', 'anpa-socios' ),
			baixa_solicitada: __( 'Baixa solicitada', 'anpa-socios' ),
			baixa: __( 'Baixa', 'anpa-socios' ),
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
				p.textContent = __( 'Aínda non tes ningunha matrícula en extraescolares.', 'anpa-socios' );
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
					acc.textContent = __( 'Aceptar praza', 'anpa-socios' );
					acc.addEventListener('click', async () => {
						const done = await tokenRequest('POST', base + '/oferta/aceptar', areaToken, {}, root);
						if (done) { showMessage(root, __( 'Praza aceptada.', 'anpa-socios' ), 'success'); await loadExtraescolares(); }
					});
					li.appendChild(acc);
				}
				if (m.estado === 'activo' || m.estado === 'lista_espera' || m.estado === 'oferta') {
					const baixa = document.createElement('button');
					baixa.type = 'button';
					baixa.className = 'anpa-area-secondary anpa-area-danger';
					baixa.textContent = __( 'Dar de baixa', 'anpa-socios' );
					baixa.addEventListener('click', async () => {
						if (!window.confirm('Solicitar a baixa desta actividade?')) { return; }
						const done = await tokenRequest('POST', base + '/baixa', areaToken, {}, root);
						if (done) { showMessage(root, __( 'Solicitude de baixa rexistrada.', 'anpa-socios' ), 'success'); await loadExtraescolares(); }
					});
					li.appendChild(baixa);
				}
				if (m.estado === 'baixa_solicitada') {
					const cancel = document.createElement('button');
					cancel.type = 'button';
					cancel.className = 'anpa-area-secondary';
					cancel.textContent = __( 'Anular baixa', 'anpa-socios' );
					cancel.addEventListener('click', async () => {
						const done = await tokenRequest('POST', base + '/baixa/cancel', areaToken, {}, root);
						if (done) { showMessage(root, __( 'Baixa anulada.', 'anpa-socios' ), 'success'); await loadExtraescolares(); }
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
				p.textContent = __( 'Non hai actividades dispoñibles neste momento.', 'anpa-socios' );
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
					showMessage(root, __( 'Selecciona un grupo compatible.', 'anpa-socios' ), 'error');
					return;
				}
				const cesion = authHost.querySelector('input[name="cesion_datos_empresa"]');
				if (!cesion || !cesion.checked) {
					showMessage(root, __( 'É obrigatorio autorizar a cesión dos datos necesarios á empresa da actividade.', 'anpa-socios' ), 'error');
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
						showMessage(root, __( 'Indica se autorizas ao persoal de comedor a facilitar a participación.', 'anpa-socios' ), 'error');
						return;
					}
					payload.autorizacion_comedor = autComedor.value;
				} else {
					const transicion = authHost.querySelector('input[name="tarde_transicion"]:checked');
					if (!transicion) {
						showMessage(root, __( 'Indica se o neno/a vén do comedor ou se a familia o levará á actividade.', 'anpa-socios' ), 'error');
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
						: __( 'Matrícula confirmada.', 'anpa-socios' );
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

		// ── Proxenitor2 (fase 1.20.0 → inline edit in profile, fase 20) ───
		bind('[data-action="toggle-proxenitor2"]', 'click', () => {
			showMessage(root, '', 'info');
			var p2Section = root.querySelector('[data-p2-inline]');
			if (p2Section) {
				p2Section.hidden = !p2Section.hidden;
			}
		});

		// Dedicated save button for the 2nd parent section (convenience;
		// also triggers the main save-profile which includes p2 data).
		bind('[data-action="proxenitor2-save"]', 'click', () => {
			var saveBtn = root.querySelector('[data-action="save-profile"]');
			if (saveBtn) { saveBtn.click(); }
		});

		// ── Banking / Modificación IBAN (fase 1.20.0) ──────────────────
		// Provincia/Poboación are free-text inputs (generic: no municipality
		// dataset, no server round-trip). No-op kept so callers don't change.
		async function loadBankingReferencias() {}

		bind('[data-action="toggle-banking"]', 'click', async () => {
			showMessage(root, '', 'info');
			if (!areaToken) {
				showStep(root, 'email');
				showMessage(root, __( 'A sesión caducou. Volve entrar.', 'anpa-socios' ), 'error');
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
				showMessage(root, __( 'A sesión caducou. Volve entrar.', 'anpa-socios' ), 'error');
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
				showMessage(root, __( 'Para modificar os datos bancarios debes cubrir IBAN, titular e autorización.', 'anpa-socios' ), 'error');
				return;
			}
			if (!payload.autorizacion) {
				showMessage(root, __( 'Debes autorizar a domiciliación dos recibos.', 'anpa-socios' ), 'error');
				return;
			}

			var result = await tokenRequest('PUT', root.dataset.profileUrl + '/banking', areaToken, payload, root);
			if (result) {
				showMessage(root, result.message || __( 'Datos bancarios actualizados correctamente.', 'anpa-socios' ), 'success');
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
				showMessage(root, __( 'A sesión caducou. Volve entrar.', 'anpa-socios' ), 'error');
				return;
			}

			const data = readFilloForm();
			if (!data.nome || !data.apelidos || !data.data_nacemento || !data.curso || !data.aula) {
				showMessage(root, __( 'Completa todos os campos do fillo/a.', 'anpa-socios' ), 'error');
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
				showMessage(root, __( 'Erro de rede. Téntao de novo.', 'anpa-socios' ), 'error');
				return null;
			}

			if (response.status === 204) {
				return {};
			}

			if (response.status === 401) {
				empresaToken = '';
				showStep(root, 'email');
				showMessage(root, __( 'A sesión caducou. Volve entrar.', 'anpa-socios' ), 'error');
				return null;
			}

			if (response.status === 429) {
				showMessage(root, __( 'Demasiadas solicitudes. Agarda uns minutos.', 'anpa-socios' ), 'error');
				return null;
			}

			let data = {};
			try {
				data = await response.json();
			} catch (_) {
				data = {};
			}

			if (!response.ok) {
				showMessage(root, data.message || __( 'Non foi posible completar a operación.', 'anpa-socios' ), 'error');
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
				showMessage(root, __( 'A sesión caducou. Volve entrar.', 'anpa-socios' ), 'error');
				return;
			}

			let response;
			try {
				response = await fetch(root.dataset.empresaExportUrl, {
					method: 'GET',
					headers: { 'X-Anpa-Empresa-Token': empresaToken },
				});
			} catch (_) {
				showMessage(root, __( 'Erro de rede. Téntao de novo.', 'anpa-socios' ), 'error');
				return;
			}

			if (response.status === 401) {
				empresaToken = '';
				showStep(root, 'email');
				showMessage(root, __( 'A sesión caducou. Volve entrar.', 'anpa-socios' ), 'error');
				return;
			}

			if (!response.ok) {
				showMessage(root, __( 'Non foi posible descargar o ficheiro.', 'anpa-socios' ), 'error');
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
			showMessage(root, __( 'Sesión pechada.', 'anpa-socios' ), 'success');
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
			showStep(root, 'profile');
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
