/**
 * Unified Socios Area — entry point for both alta and area flows.
 *
 * Detects the user's session/status and routes them to the correct
 * flow: alta (new member), area (existing member), baixa (cancel),
 * or reactivation.
 *
 * BUGFIX 1.21.1: Changed pf.estado → pf.next (preflight returns {next}, not {estado}).
 * BUGFIX 1.21.1: Token is now stored properly — never use the email as a token.
 * BUGFIX 1.21.1: Added proxenitor2 detection via preflight flags.
 *
 * @since  1.21.0
 * @package ANPA_Socios
 */
(function () {
	'use strict';
	const { __ } = wp.i18n;

	/**
	 * Parse data attributes from the container element.
	 * @returns {object|null} Config map or null if container not found.
	 */
	function parseConfig() {
		var root = document.getElementById('anpa-unified');
		if (!root) { return null; }
		return {
			root: root,
			preflightUrl: root.dataset.preflightUrl,
			requestCodeUrl: root.dataset.requestCodeUrl,
			requestCodeAltaUrl: root.dataset.requestCodeAltaUrl,
			verifyCodeUrl: root.dataset.verifyCodeUrl,
			sessionUrl: root.dataset.sessionUrl,
			sessionStatusUrl: root.dataset.sessionStatusUrl,
			profileUrl: root.dataset.profileUrl,
			logoutUrl: root.dataset.logoutUrl,
			baixaUrl: root.dataset.baixaUrl,
			baixaCancelUrl: root.dataset.baixaCancelUrl,
			reactivarUrl: root.dataset.reactivarUrl,
			referenciasUrl: root.dataset.referenciasUrl,
			altaUrl: root.dataset.altaUrl,
			fillosUrl: root.dataset.fillosUrl,
			filloUrl: root.dataset.filloUrl,
			proxenitor2Url: root.dataset.proxenitor2Url,
			adminBaseUrl: root.dataset.adminBaseUrl,
			areaPageUrl: root.dataset.areaPageUrl,
			altaPageUrl: root.dataset.altaPageUrl || '',
			landingUrl: root.dataset.landingUrl || '',
		};
	}

	/**
	 * Make a JSON POST request.
	 * @param {string} url
	 * @param {object} body
	 * @param {string|null} token  Optional area session token.
	 * @returns {Promise<object|null>}
	 */
	async function apiPost(url, body, token) {
		var headers = { 'Content-Type': 'application/json' };
		if (token) {
			headers['X-Anpa-Area-Token'] = token;
		}
		var res;
		try {
			res = await fetch(url, {
				method: 'POST',
				headers: headers,
				body: JSON.stringify(body),
			});
		} catch (_) {
			return null;
		}
		if (!res.ok) {
			var errBody;
			try { errBody = await res.json(); } catch (_) {}
			return { error: (errBody && errBody.message) || __( 'Erro do servidor', 'anpa-socios' ), code: res.status };
		}
		try { return await res.json(); } catch (_) { return null; }
	}

	/**
	 * Show a step, hide all others.
	 * @param {object} cfg
	 * @param {string} step
	 */
	function showStep(cfg, step) {
		cfg.root.querySelectorAll('[data-step]').forEach(function (el) {
			el.hidden = el.dataset.step !== step;
		});
	}

	/**
	 * Show a notice message.
	 * @param {object} cfg
	 * @param {string} msg
	 * @param {boolean} isError
	 */
	function showNotice(cfg, msg, isError) {
		var el = cfg.root.querySelector('[data-notice]');
		if (!el) { return; }
		el.textContent = msg;
		el.className = 'anpa-unified-notice' + (isError ? ' anpa-unified-error' : '');
		el.hidden = false;
	}

	function hideNotice(cfg) {
		var el = cfg.root.querySelector('[data-notice]');
		if (el) { el.hidden = true; }
	}

	/**
	 * Session token stored for the current socio (stored temporarily during alta flow,
	 * permanently after login). Uses a consistent key shared with header-nav.
	 * @returns {string|null}
	 */
	function getToken() {
		try {
			return localStorage.getItem('anpa_area_token') || null;
		} catch (_) { return null; }
	}

	/**
	 * Save the area session token to localStorage.
	 * @param {string} token
	 * @param {number} ttlSeconds
	 */
	function saveToken(token, ttlSeconds) {
		try {
			var expires = Date.now() + (ttlSeconds || 86400) * 1000;
			localStorage.setItem('anpa_area_token', token);
			localStorage.setItem('anpa_area_token_expires', String(expires));
		} catch (_) {}
	}

	/**
	 * Store the email temporarily during the alta/code verification flow.
	 * Uses a separate key so it doesn't collide with the session token.
	 * @param {string} email
	 */
	function savePendingEmail(email) {
		try {
			localStorage.setItem('anpa_pending_email', email);
			localStorage.setItem('anpa_pending_email_ts', String(Date.now()));
		} catch (_) {}
	}

	/**
	 * Retrieve and clear the pending email.
	 * Only valid for 10 minutes.
	 * @returns {string|null}
	 */
	function getPendingEmail() {
		try {
			var email = localStorage.getItem('anpa_pending_email');
			var ts = parseInt(localStorage.getItem('anpa_pending_email_ts') || '0', 10);
			if (!email || !ts || Date.now() - ts > 600000) {
				clearPendingEmail();
				return null;
			}
			return email;
		} catch (_) { return null; }
	}

	function clearPendingEmail() {
		try {
			localStorage.removeItem('anpa_pending_email');
			localStorage.removeItem('anpa_pending_email_ts');
		} catch (_) {}
	}

	/**
	 * Remove all ANPA tokens from localStorage (complete logout).
	 */
	function clearAllTokens() {
		try {
			localStorage.removeItem('anpa_area_token');
			localStorage.removeItem('anpa_area_token_expires');
			localStorage.removeItem('anpa_session_token');
			localStorage.removeItem('anpa_session_expires');
			clearPendingEmail();
			localStorage.removeItem('anpa_unified_flow');
		} catch (_) {}
	}

	/**
	 * Check if we have a valid session token.
	 * @param {object} cfg
	 * @returns {Promise<object|null>} Session info or null.
	 */
	async function checkSession(cfg) {
		var token = getToken();
		if (!token) { return null; }

		try {
			var expires = parseInt(localStorage.getItem('anpa_area_token_expires') || '0', 10);
			if (expires && Date.now() > expires) {
				clearAllTokens();
				return null;
			}
		} catch (_) {}

		var res = await apiPost(cfg.sessionStatusUrl, {}, token);
		if (res && res.email) {
			return res;
		}
		clearAllTokens();
		return null;
	}

	/**
	 * Falls back to the legacy personal-area page when inline initialisation is
	 * unavailable. Kept during Fase 19 as a rollback path.
	 *
	 * @param {object} cfg
	 */
	function redirectToLegacyArea(cfg) {
		// Legacy area unavailable: fail closed on the canonical page instead
		// of reloading it forever on clean installations.
		if (!cfg.areaPageUrl) {
			cfg.root.hidden = false;
			showNotice(cfg, __( 'Non foi posible abrir a área persoal. Recarga a páxina e téntao de novo.', 'anpa-socios' ), true);
			return false;
		}

		var destination = cfg.areaPageUrl;
		var currentBase = String(window.location.href).split('#')[0].split('?')[0].replace(/\/+$/, '');
		var destinationBase = String(destination).split('#')[0].split('?')[0].replace(/\/+$/, '');
		if (destinationBase === currentBase) {
			cfg.root.hidden = false;
			showNotice(cfg, __( 'Non foi posible abrir a área persoal. Recarga a páxina e téntao de novo.', 'anpa-socios' ), true);
			return false;
		}

		window.location.href = destination
			+ (destination.indexOf('?') > -1 ? '&' : '?')
			+ 'anpa_r=' + Date.now();
		return true;
	}

	/**
	 * Reveals and initialises the shared area shell on this page.
	 *
	 * @param {object} cfg
	 * @returns {Promise<boolean>}
	 */
	async function openAreaInline(cfg) {
		var host = document.getElementById('anpa-area-host');
		var areaRoot = host ? host.querySelector('#anpa-area') : null;
		if (!host || !areaRoot || !window.AnpaArea || typeof window.AnpaArea.init !== 'function') {
			return false;
		}

		var altaHost = document.getElementById('anpa-alta-form-host');
		if (altaHost) { altaHost.hidden = true; }
		cfg.root.hidden = true;
		host.hidden = false;

		var opened = await window.AnpaArea.init(areaRoot);
		if (!opened) {
			host.hidden = true;
			cfg.root.hidden = false;
			return false;
		}

		try { areaRoot.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (_) {}
		return true;
	}

	async function exchangeVerifiedAreaSession(cfg, verificationToken) {
		if (!verificationToken) { return false; }

		var session = await apiPost(cfg.sessionUrl, { token: verificationToken });
		if (!session || !session.session_token) { return false; }

		saveToken(session.session_token, session.expires_in || 86400);
		try { localStorage.removeItem('anpa_unified_flow'); } catch (_) {}
		showNotice(cfg, __( 'Código verificado. Abrindo a túa área persoal...', 'anpa-socios' ));

		if (!await openAreaInline(cfg)) {
			redirectToLegacyArea(cfg);
		}
		return true;
	}

	/**
	 * Call preflight to determine status for a given email.
	 * @param {object} cfg
	 * @param {string} email
	 * @returns {Promise<object|null>}
	 */
	async function preflight(cfg, email) {
		// Include the anti-bot fields (time-trap + honeypot). Without a valid
		// _ts the server's antibot fails closed and preflight returns 'alta'
		// for everyone (even active socios), breaking the login flow.
		var tsEl = cfg.root.querySelector('#anpa-unified-ts');
		var hpEl = cfg.root.querySelector('#anpa-unified-website');
		return await apiPost(cfg.preflightUrl, {
			email: email,
			_ts: tsEl ? tsEl.value : '',
			website: hpEl ? hpEl.value : '',
		});
	}

	/**
	 * The main email handler: decides alta vs area based on preflight `next` value.
	 * @param {object} cfg
	 * @param {object} pf     Preflight response { next, message }
	 * @param {string} email
	 * @param {string} ts     Anti-bot timestamp
	 * @param {HTMLElement} requestBtn
	 */
	async function handlePreflightResult(cfg, pf, email, ts, requestBtn) {
		var next = pf && pf.next ? pf.next : 'alta';

		if (next === 'area' || next === 'baixa_pendente') {
			// Existing active socio (primary or secondary parent). A pending
			// cancellation still requires the normal verified login; once inside,
			// the authenticated area exposes the cancellation controls.
			try { localStorage.setItem('anpa_unified_flow', 'login'); } catch (_) {}
			// Send a verification code for login.
			var loginResult = await apiPost(cfg.requestCodeUrl, {
				email: email,
				_ts: ts,
			});
			if (!loginResult) {
				requestBtn.disabled = false;
				showNotice(cfg, 'Non se puido enviar o código. Téntao de novo.', true);
				return;
			}
			if (loginResult.error) {
				// e.g. pre-season gate (anpa_preseason 403): show the message, no code sent.
				requestBtn.disabled = false;
				showNotice(cfg, loginResult.error, true);
				return;
			}
			// Store the email temporarily (NOT as a token).
			savePendingEmail(email);
			showStep(cfg, 'code');
			var codeInput = cfg.root.querySelector('#anpa-unified-code');
			if (codeInput) {
				codeInput.value = '';
				codeInput.focus();
			}
			var loginMessage = next === 'baixa_pendente'
				? __( 'Enviouse un código para acceder e xestionar a túa solicitude de baixa.', 'anpa-socios' )
				: __( 'Enviouse un código de acceso a ', 'anpa-socios' ) + email;
			showNotice(cfg, loginMessage);
			return;
		}

		if (next === 'inactivo') {
			// Previously deactivated socio.
			savePendingEmail(email);
			showStep(cfg, 'inactivo');
			return;
		}


		if (next === 'pendente_aprobacion') {
			// Alta done but waiting for the junta directiva's approval. Do not
			// send a code or re-open the alta form — just explain the wait.
			requestBtn.disabled = false;
			showNotice(cfg, 'A túa solicitude de alta está pendente de aprobación pola directiva. Recibirás un correo cando estea aprobada.', false);
			return;
		}

		// Alta flow (new member or unknown email).
		// IMPORTANT: use the alta code endpoint, which issues a code to ANY
		// valid email. The login endpoint (requestCodeUrl = anpa/v1/solicitar-codigo)
		// only sends codes to already-registered active socios, so using it here
		// left new applicants without a code ("no llega el correo").
		try { localStorage.setItem('anpa_unified_flow', 'alta'); } catch (_) {}

		var altaCodeUrl = cfg.requestCodeAltaUrl || cfg.requestCodeUrl;
		var result = await apiPost(altaCodeUrl, {
			email: email,
			_ts: ts,
		});

		if (!result) {
			requestBtn.disabled = false;
			showNotice(cfg, 'Non se puido enviar o código. Téntao de novo.', true);
			return;
		}
		if (result.error) {
			// e.g. pre-season gate (anpa_preseason 403): show the message, no code sent.
			requestBtn.disabled = false;
			showNotice(cfg, result.error, true);
			return;
		}

		savePendingEmail(email);
		showStep(cfg, 'code');
		var codeInput2 = cfg.root.querySelector('#anpa-unified-code');
		if (codeInput2) {
			codeInput2.value = '';
			codeInput2.focus();
		}
		showNotice(cfg, __( 'Enviouse un código a ', 'anpa-socios' ) + email);
	}

	/**
	 * Step: alta — request verification code for the alta flow.
	 * @param {object} cfg
	 */
	function initAltaStep(cfg) {
		var emailInput = cfg.root.querySelector('#anpa-unified-email');
		var tsInput = cfg.root.querySelector('#anpa-unified-ts');

		var requestBtn = cfg.root.querySelector('[data-action="request-code-alta"]');
		if (!requestBtn) { return; }

		requestBtn.addEventListener('click', async function () {
			var email = (emailInput.value || '').trim().toLowerCase();
			if (!email) {
				showNotice(cfg, __( 'Introduce un correo electrónico.', 'anpa-socios' ), true);
				return;
			}
			hideNotice(cfg);
			requestBtn.disabled = true;
			var ts = tsInput ? tsInput.value : '0';

			var pf = await preflight(cfg, email);
			if (pf && pf.error) {
				requestBtn.disabled = false;
				showNotice(cfg, pf.error, true);
				return;
			}

			await handlePreflightResult(cfg, pf, email, ts, requestBtn);
			requestBtn.disabled = false;
		});
	}

	/**
	 * Step: code — verify the code and redirect to alta form or area.
	 * @param {object} cfg
	 */
	function initCodeStep(cfg) {
		var codeInput = cfg.root.querySelector('#anpa-unified-code');
		var verifyBtn = cfg.root.querySelector('[data-action="verify-code"]');
		var backBtn = cfg.root.querySelector('[data-action="back-email"]');

		if (verifyBtn) {
			verifyBtn.addEventListener('click', async function () {
				var code = (codeInput.value || '').trim();
				if (code.length !== 6) {
					showNotice(cfg, 'O código debe ter 6 díxitos.', true);
					return;
				}
				hideNotice(cfg);
				verifyBtn.disabled = true;

				var email = getPendingEmail();
				if (!email || email.indexOf('@') === -1) {
					showNotice(cfg, 'Perdeuse o email. Volve comezar.', true);
					showStep(cfg, 'alta');
					verifyBtn.disabled = false;
					return;
				}

				var result = await apiPost(cfg.verifyCodeUrl, {
					email: email,
					codigo: code,
				});

				if (!result || result.error || !result.token) {
					verifyBtn.disabled = false;
					showNotice(cfg, (result && result.error) || __( 'Código incorrecto ou caducado.', 'anpa-socios' ), true);
					return;
				}

				clearPendingEmail();

				var flow;
				try { flow = localStorage.getItem('anpa_unified_flow'); } catch (_) {}
				if (await exchangeVerifiedAreaSession(cfg, result.token)) {
					verifyBtn.disabled = false;
					return;
				}

				if (flow === 'login') {
					verifyBtn.disabled = false;
					showNotice(cfg, __( 'Non se puido iniciar a sesión. Téntao de novo.', 'anpa-socios' ), true);
					return;
				}

				// Alta flow (new member). The full alta form is embedded on THIS
				// page (#anpa-alta-form-host). Reveal it and hand off the verified
				// token so the applicant completes the alta here — no cross-page
				// redirect (that handoff proved fragile). asociarse.js exposes
				// window.AnpaAlta.initAltaForm(formEl, { email, token }).
				var altaHost = document.getElementById('anpa-alta-form-host');
				var altaForm = document.getElementById('anpa-asociarse');
				if (altaHost && altaForm && window.AnpaAlta && typeof window.AnpaAlta.initAltaForm === 'function') {
					hideNotice(cfg);
					// Hide the unified entry steps and show the alta form in place.
					cfg.root.querySelectorAll('[data-step]').forEach(function (el) { el.hidden = true; });
					altaHost.hidden = false;
					window.AnpaAlta.initAltaForm(altaForm, { email: email, token: result.token });
					try { altaForm.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (_) {}
					verifyBtn.disabled = false;
					return;
				}

				// Fallback (embedded form not present): use the configured signup
				// page if any, else stay on the members page.
				var altaDest = (cfg.altaPageUrl && cfg.altaPageUrl.length) ? cfg.altaPageUrl : '';
				if (altaDest) {
					showNotice(cfg, 'Código verificado. Redirixíndoche ao formulario de alta...');
					setTimeout(function () {
						window.location.href = altaDest + (altaDest.indexOf('?') > -1 ? '&' : '?') + 'email=' + encodeURIComponent(email);
					}, 1000);
				} else {
					showNotice(cfg, 'Código verificado. Xa podes continuar a túa alta na área de socios.');
					var backDest = (cfg.landingUrl || window.location.href.split('?')[0]);
					setTimeout(function () {
						window.location.href = backDest + (backDest.indexOf('?') > -1 ? '&' : '?') + 'anpa_r=' + Date.now();
					}, 1200);
				}
				verifyBtn.disabled = false;
			});
		}

		if (backBtn) {
			backBtn.addEventListener('click', function () {
				showStep(cfg, 'alta');
				hideNotice(cfg);
				clearPendingEmail();
			});
		}
	}


	/**
	 * Handle inactivo (reactivation) actions.
	 * @param {object} cfg
	 */
	function initInactivo(cfg) {
		var reactivarBtn = cfg.root.querySelector('[data-action="request-reactivar"]');
		if (reactivarBtn) {
			reactivarBtn.addEventListener('click', async function () {
				reactivarBtn.disabled = true;
				var email = getPendingEmail();
				if (!email || email.indexOf('@') === -1) {
					showNotice(cfg, 'Perdeuse o email. Volve comezar.', true);
					showStep(cfg, 'alta');
					reactivarBtn.disabled = false;
					return;
				}
				var result = await apiPost(cfg.reactivarUrl, { email: email });
				if (!result) {
					reactivarBtn.disabled = false;
					showNotice(cfg, 'Non se puido solicitar a reactivación. Téntao de novo.', true);
					return;
				}
				showNotice(cfg, 'Solicitude de reactivación enviada. A directiva revisaraa.');
				setTimeout(function () {
					showStep(cfg, 'alta');
				}, 3000);
				reactivarBtn.disabled = false;
			});
		}
	}

	/**
	 * Main init — check session and route.
	 */
	async function init() {
		var cfg = parseConfig();
		if (!cfg) { return; }

		// area.js owns canonical session restoration. The legacy status check
		// remains only as a fallback when the inline API cannot initialise.
		if (getToken()) {
			if (await openAreaInline(cfg)) {
				return;
			}
			var session = await checkSession(cfg);
			if (session) {
				redirectToLegacyArea(cfg);
				return;
			}
		}

		// Prefill email from URL parameter if present.
		var urlParams = new URLSearchParams(window.location.search);
		var prefilledEmail = urlParams.get('email');
		if (prefilledEmail) {
			var emailInput = cfg.root.querySelector('#anpa-unified-email');
			if (emailInput) {
				emailInput.value = prefilledEmail;
			}
		}

		// Start with the entry step.
		showStep(cfg, 'alta');
		initAltaStep(cfg);
		initCodeStep(cfg);
		initInactivo(cfg);
	}

	window.AnpaUnified = {
		openArea: function () {
			var cfg = parseConfig();
			return cfg ? openAreaInline(cfg) : Promise.resolve(false);
		},
		showEntry: function (message, isError) {
			var cfg = parseConfig();
			if (!cfg) { return false; }
			var host = document.getElementById('anpa-area-host');
			var altaHost = document.getElementById('anpa-alta-form-host');
			if (host) { host.hidden = true; }
			if (altaHost) { altaHost.hidden = true; }
			cfg.root.hidden = false;
			showStep(cfg, 'alta');
			if (message) {
				showNotice(cfg, message, !!isError);
			} else {
				hideNotice(cfg);
			}
			return true;
		},
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
