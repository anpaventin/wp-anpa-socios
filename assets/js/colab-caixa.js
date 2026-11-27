/**
 * Collaborators network (1.105.0 v2): the till (caixa) of a shop.
 *
 * Three steps: 1) scan the shop's QR (its key; the device remembers it for
 * 12 hours), 2) scan the family's card QR, 3) see «valid member family» and
 * that family's offers at this shop, choose one and apply it (with undo for
 * 10 minutes).
 *
 * Codes arrive only in the URL fragment (#k=… shop key, #f=… family code),
 * which is removed from the address bar before anything else, and travel
 * only in a header (shop key) or a POST body (family code). They are never
 * put in a query string, the page title or the console.
 *
 * Every text from the server is put on the page with textContent: this file
 * never builds markup from strings.
 *
 * @since  1.105.0
 * @package ANPA_Socios
 */

(function () {
	'use strict';

	// Before anything else: take the fragment and remove it from the address bar.
	var fragmentoInicial = '';
	function tomarFragmento() {
		var h = '';
		try { h = String(window.location.hash || ''); } catch (_) {}
		if (h) {
			try { window.history.replaceState(null, '', window.location.pathname + window.location.search); } catch (_) {}
		}
		return h;
	}
	fragmentoInicial = tomarFragmento();

	const { __, sprintf } = wp.i18n;

	var CAIXA_HEADER = 'X-Anpa-Colab-Caixa';
	var STORE_KEY = 'anpa_colab_caixa';
	var DURACION_CLAVE = 12 * 3600 * 1000;
	var DESFACER_MS = 10 * 60 * 1000;
	var ALFABETO = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
	var LONX_FAMILIA = 12;
	var LONX_CLAVE = 26;

	var root = document.getElementById('anpa-colab-caixa');
	if (!root) {
		return;
	}

	var urls = {
		caixa: root.dataset.caixaUrl || '',
		familia: root.dataset.familiaUrl || '',
		aplicar: root.dataset.aplicarUrl || '',
		desfacer: root.dataset.desfacerUrl || '',
		acceso: root.dataset.accesoPaxina || '',
	};
	var asociacion = root.dataset.asociacion || '';

	var memClave = null; // {k, exp} when localStorage is not available.
	var estado = {
		comercio: '',
		familia: '',          // Family code being served (memory only).
		pendenteFamilia: '',  // Family code that arrived before the shop key.
		valida: false,
		ofertas: [],
		escollida: 0,
		ultimoUso: null,      // {id, titulo, desconto, ts}
	};
	var busyDepth = 0;
	var desfacerTimer = null;
	var escaner = null;

	/* ── Codes ─────────────────────────────────────────────────────── */

	/** Same rules as ANPA_Socios_Colab::normalizar_codigo(). */
	function normalizar(v, lonx) {
		var s = String(v === undefined || v === null ? '' : v).toUpperCase();
		if (s.length > 80) { return ''; }
		s = s.replace(/[\s-]+/g, '').replace(/O/g, '0').replace(/[IL]/g, '1');
		if (s.length !== lonx) { return ''; }
		for (var i = 0; i < s.length; i++) {
			if (ALFABETO.indexOf(s.charAt(i)) < 0) { return ''; }
		}
		return s;
	}

	/** {k, f} from a fragment («#k=…», «#f=…»). */
	function lerFragmento(h) {
		var out = { k: '', f: '' };
		var s = String(h || '').replace(/^#/, '');
		if (!s) { return out; }
		try {
			var p = new URLSearchParams(s);
			out.k = p.get('k') || '';
			out.f = p.get('f') || '';
		} catch (_) {}
		return out;
	}

	/** What a scanned QR holds: a URL with a fragment, or a bare code. */
	function lerQr(texto) {
		var t = String(texto || '').trim();
		var i = t.indexOf('#');
		if (i >= 0) { return lerFragmento(t.slice(i)); }
		if (normalizar(t, LONX_CLAVE)) { return { k: t, f: '' }; }
		if (normalizar(t, LONX_FAMILIA)) { return { k: '', f: t }; }
		return { k: '', f: '' };
	}

	function formatarCodigo(c) {
		return String(c || '').replace(/(.{4})(?=.)/g, '$1-');
	}

	/* ── Shop key storage (12 hours; works for the session without storage) ── */

	function gardarClave(k) {
		memClave = { k: k, exp: Date.now() + DURACION_CLAVE };
		try { window.localStorage.setItem(STORE_KEY, JSON.stringify(memClave)); } catch (_) {}
	}

	function lerClave() {
		var candidatos = [];
		try {
			var raw = window.localStorage.getItem(STORE_KEY);
			if (raw) { candidatos.push(JSON.parse(raw)); }
		} catch (_) {}
		candidatos.push(memClave);
		for (var i = 0; i < candidatos.length; i++) {
			var d = candidatos[i];
			if (d && typeof d.k === 'string' && normalizar(d.k, LONX_CLAVE) && typeof d.exp === 'number' && d.exp > Date.now()) {
				return d.k;
			}
		}
		esquecerClave();
		return '';
	}

	function esquecerClave() {
		memClave = null;
		try { window.localStorage.removeItem(STORE_KEY); } catch (_) {}
	}

	/* ── DOM helpers (no markup strings, ever) ─────────────────────── */

	function el(tag, props, children) {
		var node = document.createElement(tag);
		props = props || {};
		Object.keys(props).forEach(function (k) {
			var v = props[k];
			if (v === undefined || v === null) { return; }
			if (k === 'text') {
				node.textContent = String(v);
			} else if (k === 'class') {
				node.className = v;
			} else if (k === 'attrs') {
				Object.keys(v).forEach(function (a) {
					if (v[a] !== undefined && v[a] !== null && v[a] !== false) {
						node.setAttribute(a, String(v[a]));
					}
				});
			} else {
				node[k] = v;
			}
		});
		(children || []).forEach(function (c) {
			if (c === null || c === undefined || c === false) { return; }
			node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
		});
		return node;
	}

	function boton(texto, variante, onClick, extra) {
		var b = el('button', Object.assign({
			type: 'button',
			class: 'anpa-colab-boton anpa-colab-boton--grande' + (variante ? ' anpa-colab-boton--' + variante : ''),
			text: texto,
		}, extra || {}));
		if (onClick) { b.addEventListener('click', onClick); }
		return b;
	}

	function urlSegura(u) {
		return typeof u === 'string' && /^https?:\/\//i.test(u) ? u : '';
	}

	function cabeceira(texto) {
		return el('h2', { class: 'anpa-colab-cabeceira anpa-colab-caixa-titulo', text: texto, attrs: { tabindex: '-1' } });
	}

	function enfocar(node) {
		if (!node) { return; }
		try { node.focus(); } catch (_) {}
	}

	function hora(ts) {
		var d = new Date(ts);
		return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
	}

	function textoRestan(o) {
		if (o.restan === null || o.restan === undefined) { return __( 'Usos ilimitados', 'anpa-socios' ); }
		var n = parseInt(o.restan, 10) || 0;
		if (n <= 0) { return __( 'Xa usada', 'anpa-socios' ); }
		if (n === 1) { return __( 'Queda 1 uso', 'anpa-socios' ); }
		/* translators: %d: remaining uses */
		return sprintf( __( 'Quedan %d usos', 'anpa-socios' ), n );
	}

	/* ── Layout ────────────────────────────────────────────────────── */

	while (root.firstChild) { root.removeChild(root.firstChild); }
	var topo = el('div', { class: 'anpa-colab-caixa-topo', hidden: true });
	var mensaxe = el('div', {
		class: 'anpa-colab-mensaxe',
		hidden: true,
		attrs: { 'aria-live': 'polite', 'aria-atomic': 'true' },
	});
	var vista = el('div', { class: 'anpa-colab-vista' });
	root.classList.add('anpa-colab-caixa');
	if (asociacion) {
		root.appendChild(el('p', { class: 'anpa-colab-caixa-asociacion', text: asociacion + ' · ' + __( 'Caixa dos comercios colaboradores', 'anpa-socios' ) }));
	}
	root.appendChild(topo);
	root.appendChild(mensaxe);
	root.appendChild(vista);

	var mensaxeSeq = 0;
	function showMessage(text, type) {
		mensaxeSeq += 1;
		var seq = mensaxeSeq;
		mensaxe.textContent = '';
		mensaxe.dataset.type = type || 'info';
		mensaxe.hidden = !text;
		if (text) {
			// Set after clearing so a repeated message is announced again.
			window.setTimeout(function () {
				if (seq === mensaxeSeq) { mensaxe.textContent = text; }
			}, 30);
		}
	}

	function limparVista() {
		pecharEscaner();
		if (desfacerTimer) { window.clearInterval(desfacerTimer); desfacerTimer = null; }
		while (vista.firstChild) { vista.removeChild(vista.firstChild); }
	}

	function pintarTopo() {
		while (topo.firstChild) { topo.removeChild(topo.firstChild); }
		topo.hidden = !estado.comercio;
		if (!estado.comercio) { return; }
		topo.appendChild(el('p', { class: 'anpa-colab-caixa-comercio' }, [
			el('span', { text: __( 'Caixa de', 'anpa-socios' ) + ' ' }),
			el('strong', { text: estado.comercio }),
		]));
		topo.appendChild(el('button', {
			type: 'button',
			class: 'anpa-colab-boton anpa-colab-boton--secundario',
			text: __( 'Saír', 'anpa-socios' ),
			onclick: function () {
				if (!window.confirm(__( 'Este dispositivo deixará de ser a caixa do comercio ata que escanees de novo o seu QR. Saír?', 'anpa-socios' ))) { return; }
				esquecerClave();
				estado.comercio = '';
				limparFamilia();
				pintarTopo();
				renderPaso1();
				showMessage(__( 'Saíches. Este dispositivo xa non lembra o comercio.', 'anpa-socios' ), 'info');
			},
		}));
	}

	function indicador(paso) {
		/* translators: 1: current step, 2: total steps */
		return el('p', { class: 'anpa-colab-progreso-texto', text: sprintf( __( 'Paso %1$d de %2$d', 'anpa-socios' ), paso, 3 ) });
	}

	/* ── Busy state ────────────────────────────────────────────────── */

	function setBusy(on) {
		busyDepth = Math.max(0, busyDepth + (on ? 1 : -1));
		var ocupado = busyDepth > 0;
		root.classList.toggle('anpa-colab-app--ocupado', ocupado);
		root.setAttribute('aria-busy', ocupado ? 'true' : 'false');
		root.querySelectorAll('button').forEach(function (b) {
			if (ocupado && !b.disabled) {
				b.disabled = true;
				b.dataset.colabBusy = '1';
			} else if (!ocupado && b.dataset.colabBusy) {
				b.disabled = false;
				delete b.dataset.colabBusy;
			}
		});
	}

	/* ── Requests ──────────────────────────────────────────────────── */

	/**
	 * A till request with the shop key in the header. Returns {ok, status,
	 * data}, or null when the failure was already handled (network, bad key,
	 * too many requests).
	 */
	async function api(method, url, clave, body) {
		var headers = { Accept: 'application/json' };
		headers[CAIXA_HEADER] = clave; // Header only: never in a URL.
		if (body !== undefined) { headers['Content-Type'] = 'application/json'; }
		setBusy(true);
		try {
			var res;
			try {
				res = await fetch(url, {
					method: method,
					headers: headers,
					body: body !== undefined ? JSON.stringify(body) : undefined,
					credentials: 'omit',
					cache: 'no-store',
				});
			} catch (_) {
				showMessage(__( 'Erro de rede. Comproba a conexión e téntao de novo.', 'anpa-socios' ), 'error');
				return null;
			}
			var data = null;
			try { data = await res.json(); } catch (_) { data = null; }
			if (res.status === 401) {
				esquecerClave();
				estado.comercio = '';
				pintarTopo();
				var pendente = estado.familia || estado.pendenteFamilia;
				limparFamilia();
				estado.pendenteFamilia = pendente;
				renderPaso1();
				showMessage((data && typeof data.message === 'string' && data.message) || __( 'Este dispositivo non ten a clave dun comercio activo. Escanea de novo o QR do teu comercio.', 'anpa-socios' ), 'error');
				return null;
			}
			if (res.status === 429) {
				showMessage(__( 'Houbo demasiadas comprobacións seguidas. Agarda uns minutos e téntao de novo.', 'anpa-socios' ), 'error');
				return null;
			}
			return { ok: res.ok, status: res.status, data: data };
		} finally {
			setBusy(false);
		}
	}

	function errorMessage(res) {
		return (res && res.data && typeof res.data.message === 'string' && res.data.message)
			|| __( 'Non foi posible completar a operación.', 'anpa-socios' );
	}

	function codigoErro(res) {
		return res && res.data && typeof res.data.code === 'string' ? res.data.code : '';
	}

	function limparFamilia() {
		estado.familia = '';
		estado.pendenteFamilia = '';
		estado.valida = false;
		estado.ofertas = [];
		estado.escollida = 0;
		estado.ultimoUso = null;
	}

	/* ── Camera scanner ────────────────────────────────────────────── */

	function haiCamara() {
		return !!(navigator.mediaDevices && typeof navigator.mediaDevices.getUserMedia === 'function' && window.isSecureContext !== false);
	}

	function podeEscanear() {
		return haiCamara() && (('BarcodeDetector' in window) || typeof window.jsQR === 'function');
	}

	async function detectorNativo() {
		if (!('BarcodeDetector' in window)) { return null; }
		try {
			if (typeof window.BarcodeDetector.getSupportedFormats === 'function') {
				var f = await window.BarcodeDetector.getSupportedFormats();
				if (Array.isArray(f) && f.indexOf('qr_code') < 0) { return null; }
			}
			return new window.BarcodeDetector({ formats: ['qr_code'] });
		} catch (_) {
			return null;
		}
	}

	function pecharEscaner() {
		var e = escaner;
		escaner = null;
		if (!e) { return; }
		if (e.timer) { window.clearTimeout(e.timer); }
		if (e.stream) {
			try { e.stream.getTracks().forEach(function (t) { t.stop(); }); } catch (_) {}
		}
		try { e.video.srcObject = null; } catch (_) {}
		if (e.caixa && e.caixa.parentNode) { e.caixa.parentNode.removeChild(e.caixa); }
		if (e.volver) { enfocar(e.volver); }
	}

	/**
	 * Opens the in-page scanner inside `host`. `onTexto(text)` gets the first
	 * QR read; the camera is stopped before it is called.
	 */
	async function abrirEscaner(host, volver, onTexto) {
		pecharEscaner();
		if (!podeEscanear()) {
			showMessage(__( 'Este navegador non pode escanear aquí. Escribe o código a man.', 'anpa-socios' ), 'error');
			return;
		}
		var detector = await detectorNativo();
		if (!detector && typeof window.jsQR !== 'function') {
			showMessage(__( 'Este navegador non pode escanear aquí. Escribe o código a man.', 'anpa-socios' ), 'error');
			return;
		}
		var video = el('video', { class: 'anpa-colab-escaner-video', muted: true, playsInline: true, attrs: { playsinline: '', muted: '', 'aria-hidden': 'true' } });
		var pechar = el('button', { type: 'button', class: 'anpa-colab-boton anpa-colab-boton--secundario anpa-colab-boton--grande', text: __( 'Pechar a cámara', 'anpa-socios' ) });
		var caixa = el('div', { class: 'anpa-colab-escaner', attrs: { role: 'group', 'aria-label': __( 'Escáner de QR', 'anpa-socios' ) } }, [
			el('div', { class: 'anpa-colab-escaner-marco' }, [video, el('span', { class: 'anpa-colab-escaner-guia', attrs: { 'aria-hidden': 'true' } })]),
			el('p', { class: 'anpa-colab-intro', text: __( 'Apunta ao QR e mantén o móbil quieto un momento.', 'anpa-socios' ) }),
			pechar,
		]);
		var e = { video: video, caixa: caixa, stream: null, timer: null, volver: volver };
		escaner = e;
		pechar.addEventListener('click', function () { pecharEscaner(); });
		caixa.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { pecharEscaner(); } });
		host.appendChild(caixa);
		try {
			e.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
		} catch (err) {
			var nome = err && err.name ? err.name : '';
			pecharEscaner();
			showMessage(nome === 'NotAllowedError' || nome === 'SecurityError'
				? __( 'Non hai permiso para usar a cámara. Permíteo no navegador ou escribe o código a man.', 'anpa-socios' )
				: __( 'Non se puido abrir a cámara. Escribe o código a man.', 'anpa-socios' ), 'error');
			return;
		}
		if (escaner !== e) {
			// Closed while the permission prompt was open.
			e.stream.getTracks().forEach(function (t) { t.stop(); });
			return;
		}
		video.srcObject = e.stream;
		try { await video.play(); } catch (_) {}
		enfocar(pechar);

		var lenzo = null;
		var ctx = null;
		var atopado = function (texto) {
			if (escaner !== e) { return; }
			e.volver = null;
			pecharEscaner();
			onTexto(String(texto || ''));
		};
		var seguinte = function () {
			if (escaner === e) { e.timer = window.setTimeout(tick, 200); }
		};
		var tick = function () {
			if (escaner !== e) { return; }
			if (video.readyState < 2 || !video.videoWidth) { seguinte(); return; }
			if (detector) {
				detector.detect(video).then(function (codigos) {
					if (codigos && codigos.length && codigos[0].rawValue) { atopado(codigos[0].rawValue); } else { seguinte(); }
				}).catch(seguinte);
				return;
			}
			try {
				var escala = Math.min(1, 640 / video.videoWidth);
				var w = Math.max(1, Math.round(video.videoWidth * escala));
				var h = Math.max(1, Math.round(video.videoHeight * escala));
				if (!lenzo) {
					lenzo = document.createElement('canvas');
					ctx = lenzo.getContext('2d', { willReadFrequently: true });
				}
				lenzo.width = w;
				lenzo.height = h;
				ctx.drawImage(video, 0, 0, w, h);
				var img = ctx.getImageData(0, 0, w, h);
				var r = window.jsQR(img.data, w, h, { inversionAttempts: 'dontInvert' });
				if (r && r.data) { atopado(r.data); return; }
			} catch (_) {}
			seguinte();
		};
		tick();
	}

	// The camera never stays on in the background.
	document.addEventListener('visibilitychange', function () {
		if (document.visibilityState === 'hidden') { pecharEscaner(); }
	});
	window.addEventListener('pagehide', pecharEscaner);

	/* ── Step 1: the shop QR ───────────────────────────────────────── */

	function renderPaso1() {
		limparVista();
		var h = cabeceira(__( 'Escanea o QR do teu comercio', 'anpa-socios' ));
		var sec = el('section', { class: 'anpa-colab-tarxeta-paso anpa-colab-caixa-paso' }, [indicador(1), h]);
		sec.appendChild(el('p', { class: 'anpa-colab-intro', text: __( 'Usa a cámara deste móbil ou tableta para escanear o QR do panel do teu comercio. O dispositivo lembrará o comercio durante 12 horas.', 'anpa-socios' ) }));
		if (estado.pendenteFamilia) {
			sec.appendChild(el('p', { class: 'anpa-colab-aviso', text: __( 'Escaneaches o carné dunha familia, pero antes hai que escanear o QR do teu comercio. Despois comprobaremos ese carné.', 'anpa-socios' ) }));
		}
		var zona = el('div', { class: 'anpa-colab-escaner-zona' });
		if (podeEscanear()) {
			var b = boton(__( 'Escanear o QR do comercio', 'anpa-socios' ), 'primario', function () {
				showMessage('', 'info');
				abrirEscaner(zona, b, function (texto) {
					var q = lerQr(texto);
					if (q.f && !q.k) {
						estado.pendenteFamilia = q.f;
						showMessage(__( 'Ese QR é dun carné de familia. Primeiro escanea o QR do teu comercio.', 'anpa-socios' ), 'error');
						renderPaso1();
						return;
					}
					usarClave(q.k);
				});
			});
			zona.appendChild(b);
		}
		sec.appendChild(zona);

		// Typed or pasted key.
		var id = 'anpa-colab-caixa-clave';
		var input = el('input', {
			type: 'text',
			id: id,
			class: 'anpa-colab-input-codigo anpa-colab-input-clave',
			attrs: { autocomplete: 'off', autocapitalize: 'characters', spellcheck: 'false', 'aria-describedby': id + '-axuda' },
		});
		var form = el('form', { class: 'anpa-colab-form', noValidate: true }, [
			el('div', { class: 'anpa-colab-campo' }, [
				el('label', { class: 'anpa-colab-etiqueta', attrs: { for: id }, text: podeEscanear() ? __( 'Ou escribe a clave do comercio', 'anpa-socios' ) : __( 'Escribe a clave do comercio', 'anpa-socios' ) }),
				el('p', { class: 'anpa-colab-axuda', id: id + '-axuda', text: __( 'Son 26 caracteres. Está no panel do comercio, debaixo do QR («Ver a clave en texto»).', 'anpa-socios' ) }),
				input,
			]),
			el('div', { class: 'anpa-colab-accions' }, [
				el('button', { type: 'submit', class: 'anpa-colab-boton anpa-colab-boton--secundario anpa-colab-boton--grande', text: __( 'Continuar', 'anpa-socios' ) }),
			]),
		]);
		form.addEventListener('submit', function (ev) {
			ev.preventDefault();
			if (busyDepth > 0) { return; }
			var q = lerQr(input.value);
			usarClave(q.k || input.value);
		});
		sec.appendChild(form);

		var acceso = urlSegura(urls.acceso);
		if (acceso) {
			sec.appendChild(el('p', { class: 'anpa-colab-axuda' }, [
				__( 'Onde está o QR? No panel do comercio, en «O QR do meu comercio».', 'anpa-socios' ) + ' ',
				el('a', { href: acceso, class: 'anpa-colab-ligazon', text: __( 'Ir ao panel do comercio', 'anpa-socios' ) }),
			]));
		}
		vista.appendChild(sec);
		enfocar(h);
	}

	async function usarClave(cru) {
		var k = normalizar(cru, LONX_CLAVE);
		if (!k) {
			showMessage(__( 'Iso non parece a clave dun comercio. Escanea o QR do panel do teu comercio.', 'anpa-socios' ), 'error');
			return;
		}
		var res = await api('GET', urls.caixa, k);
		if (!res) { return; }
		if (!res.ok || !res.data || typeof res.data.comercio !== 'string') {
			showMessage(errorMessage(res), 'error');
			return;
		}
		gardarClave(k);
		estado.comercio = res.data.comercio;
		pintarTopo();
		var pendente = estado.pendenteFamilia;
		estado.pendenteFamilia = '';
		if (pendente) {
			comprobarFamilia(pendente);
			return;
		}
		renderPaso2();
		/* translators: %s: shop name */
		showMessage(sprintf( __( 'Listo: este dispositivo é a caixa de %s durante 12 horas.', 'anpa-socios' ), estado.comercio ), 'success');
	}

	/* ── Step 2: the family QR ─────────────────────────────────────── */

	function renderPaso2() {
		limparVista();
		var h = cabeceira(__( 'Escanea o QR da familia', 'anpa-socios' ));
		var sec = el('section', { class: 'anpa-colab-tarxeta-paso anpa-colab-caixa-paso' }, [indicador(2), h]);
		sec.appendChild(el('p', { class: 'anpa-colab-intro', text: __( 'Pídelle á familia o seu carné de socio/a (no móbil ou impreso) e escanea o QR.', 'anpa-socios' ) }));
		var zona = el('div', { class: 'anpa-colab-escaner-zona' });
		if (podeEscanear()) {
			var b = boton(__( 'Escanear o carné', 'anpa-socios' ), 'primario', function () {
				showMessage('', 'info');
				abrirEscaner(zona, b, function (texto) {
					var q = lerQr(texto);
					if (q.k && !q.f) {
						showMessage(__( 'Ese QR é o dun comercio, non o dun carné. Escanea o carné da familia.', 'anpa-socios' ), 'error');
						enfocar(b);
						return;
					}
					usarFamilia(q.f);
				});
			});
			zona.appendChild(b);
		}
		sec.appendChild(zona);

		var id = 'anpa-colab-caixa-familia';
		var input = el('input', {
			type: 'text',
			id: id,
			class: 'anpa-colab-input-codigo',
			placeholder: 'XXXX-XXXX-XXXX',
			attrs: { autocomplete: 'off', autocapitalize: 'characters', spellcheck: 'false', 'aria-describedby': id + '-axuda' },
		});
		var form = el('form', { class: 'anpa-colab-form', noValidate: true }, [
			el('div', { class: 'anpa-colab-campo' }, [
				el('label', { class: 'anpa-colab-etiqueta', attrs: { for: id }, text: podeEscanear() ? __( 'Ou escribe o código do carné', 'anpa-socios' ) : __( 'Escribe o código do carné', 'anpa-socios' ) }),
				el('p', { class: 'anpa-colab-axuda', id: id + '-axuda', text: __( 'Son 12 caracteres, debaixo do QR do carné.', 'anpa-socios' ) }),
				input,
			]),
			el('div', { class: 'anpa-colab-accions' }, [
				el('button', { type: 'submit', class: 'anpa-colab-boton anpa-colab-boton--secundario anpa-colab-boton--grande', text: __( 'Comprobar', 'anpa-socios' ) }),
			]),
		]);
		form.addEventListener('submit', function (ev) {
			ev.preventDefault();
			if (busyDepth > 0) { return; }
			var q = lerQr(input.value);
			usarFamilia(q.f || input.value);
		});
		sec.appendChild(form);
		vista.appendChild(sec);
		enfocar(h);
	}

	function usarFamilia(cru) {
		var f = normalizar(cru, LONX_FAMILIA);
		if (!f) {
			showMessage(__( 'Iso non parece o código dun carné. Son 12 caracteres.', 'anpa-socios' ), 'error');
			return;
		}
		comprobarFamilia(f);
	}

	async function comprobarFamilia(codigo, msg) {
		var clave = lerClave();
		if (!clave) {
			estado.comercio = '';
			pintarTopo();
			estado.pendenteFamilia = codigo;
			renderPaso1();
			return;
		}
		// Kept while the request runs: if the key turns out to be bad (401),
		// this family is checked again right after the shop QR is scanned.
		estado.pendenteFamilia = codigo;
		var res = await api('POST', urls.familia, clave, { codigo: codigo });
		if (!res) {
			if (lerClave()) { estado.pendenteFamilia = ''; }
			return;
		}
		estado.pendenteFamilia = '';
		if (!res.ok || !res.data) {
			showMessage(errorMessage(res), 'error');
			return;
		}
		estado.familia = codigo;
		estado.valida = res.data.valida === true;
		estado.ofertas = Array.isArray(res.data.ofertas) ? res.data.ofertas : [];
		estado.ultimoUso = null;
		var libres = estado.ofertas.filter(function (o) { return o.disponible; });
		estado.escollida = libres.length === 1 ? parseInt(libres[0].id, 10) : 0;
		renderPaso3();
		showMessage(msg || '', 'error');
	}

	/* ── Step 3: result and offers ─────────────────────────────────── */

	function resultado(valida, titulo, texto) {
		var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
		svg.setAttribute('viewBox', '0 0 24 24');
		svg.setAttribute('class', 'anpa-colab-resultado-icona');
		svg.setAttribute('aria-hidden', 'true');
		svg.setAttribute('focusable', 'false');
		var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
		path.setAttribute('fill', 'none');
		path.setAttribute('stroke', 'currentColor');
		path.setAttribute('stroke-width', '2.5');
		path.setAttribute('stroke-linecap', 'round');
		path.setAttribute('stroke-linejoin', 'round');
		path.setAttribute('d', valida ? 'M4 12.5l5 5L20 6.5' : 'M6 6l12 12M18 6L6 18');
		svg.appendChild(path);
		return el('div', { class: valida ? 'anpa-colab-resultado--valido' : 'anpa-colab-resultado--invalido', attrs: { role: 'status' } }, [
			svg,
			el('div', { class: 'anpa-colab-resultado-texto' }, [
				el('p', { class: 'anpa-colab-resultado-titulo', text: titulo }),
				texto ? el('p', { text: texto }) : null,
			]),
		]);
	}

	function renderPaso3() {
		limparVista();
		var sec = el('section', { class: 'anpa-colab-tarxeta-paso anpa-colab-caixa-paso' }, [indicador(3)]);
		var h;
		if (!estado.valida) {
			h = cabeceira(__( 'Carné non válido', 'anpa-socios' ));
			h.classList.add('anpa-colab-visualmente-oculto');
			sec.appendChild(h);
			sec.appendChild(resultado(false, __( 'Carné non válido', 'anpa-socios' ), __( 'Este carné non é dunha familia socia agora mesmo (ou foi renovado). Non apliques ningunha oferta.', 'anpa-socios' )));
			sec.appendChild(el('div', { class: 'anpa-colab-accions' }, [
				boton(__( 'Seguinte familia', 'anpa-socios' ), 'primario', seguinteFamilia),
			]));
			vista.appendChild(sec);
			enfocar(h);
			return;
		}
		h = cabeceira(__( 'Familia socia válida', 'anpa-socios' ));
		h.classList.add('anpa-colab-visualmente-oculto');
		sec.appendChild(h);
		sec.appendChild(resultado(true, __( 'Familia socia válida', 'anpa-socios' ), ''));

		var ofertas = estado.ofertas;
		if (!ofertas.length) {
			sec.appendChild(el('p', { class: 'anpa-colab-aviso', text: __( 'O teu comercio non ten ofertas publicadas en vigor neste momento.', 'anpa-socios' ) }));
			sec.appendChild(el('div', { class: 'anpa-colab-accions' }, [
				boton(__( 'Seguinte familia', 'anpa-socios' ), 'primario', seguinteFamilia),
			]));
			vista.appendChild(sec);
			enfocar(h);
			return;
		}

		var grupo = 'anpa-colab-caixa-oferta';
		var lista = el('div', { class: 'anpa-colab-caixa-ofertas' });
		var fs = el('fieldset', { class: 'anpa-colab-campo anpa-colab-caixa-escolla' }, [
			el('legend', { class: 'anpa-colab-etiqueta', text: __( 'Escolle a oferta que vas aplicar', 'anpa-socios' ) }),
			lista,
		]);
		ofertas.forEach(function (o) {
			var id = parseInt(o.id, 10);
			if (!(id > 0)) { return; }
			var inputId = grupo + '-' + id;
			var input = el('input', { type: 'radio', name: grupo, id: inputId, value: String(id) });
			input.checked = estado.escollida === id;
			input.disabled = !o.disponible;
			input.addEventListener('change', function () { if (input.checked) { estado.escollida = id; } });
			var etiquetas = el('p', { class: 'anpa-colab-etiquetas' }, [
				el('span', { class: 'anpa-colab-etiqueta anpa-colab-etiqueta--restan' + (o.disponible ? '' : ' anpa-colab-etiqueta--esgotada'), text: textoRestan(o) }),
				' ',
				el('span', { class: 'anpa-colab-etiqueta' + (o.acumulable ? ' anpa-colab-etiqueta--si' : ''), text: o.acumulable ? __( 'Acumulable con outras ofertas', 'anpa-socios' ) : __( 'Non acumulable', 'anpa-socios' ) }),
			]);
			var corpo = el('span', { class: 'anpa-colab-caixa-oferta-corpo' }, [
				o.desconto ? el('span', { class: 'anpa-colab-desconto', text: String(o.desconto) }) : null,
				el('strong', { class: 'anpa-colab-titulo', text: String(o.titulo || '') }),
				o.descricion ? el('span', { class: 'anpa-colab-texto', text: String(o.descricion) }) : null,
				o.condicions ? el('span', { class: 'anpa-colab-condicions', text: String(o.condicions) }) : null,
				etiquetas,
			]);
			lista.appendChild(el('label', {
				class: 'anpa-colab-caixa-oferta' + (o.disponible ? '' : ' anpa-colab-caixa-oferta--usada'),
				attrs: { for: inputId },
			}, [input, corpo]));
		});
		sec.appendChild(fs);
		var aplicarB = boton(__( 'Aplicar', 'anpa-socios' ), 'primario', aplicar);
		var hai = ofertas.some(function (o) { return o.disponible; });
		if (!hai) {
			aplicarB.disabled = true;
			sec.appendChild(el('p', { class: 'anpa-colab-aviso', text: __( 'Esta familia xa usou todas as ofertas do teu comercio.', 'anpa-socios' ) }));
		}
		sec.appendChild(el('div', { class: 'anpa-colab-accions' }, [
			aplicarB,
			boton(__( 'Seguinte familia', 'anpa-socios' ), 'secundario', seguinteFamilia),
		]));
		vista.appendChild(sec);
		enfocar(h);
	}

	async function aplicar() {
		if (busyDepth > 0) { return; }
		var id = estado.escollida;
		var oferta = estado.ofertas.filter(function (o) { return parseInt(o.id, 10) === id; })[0];
		if (!oferta || !oferta.disponible) {
			showMessage(__( 'Escolle unha oferta dispoñible.', 'anpa-socios' ), 'error');
			var primeiro = vista.querySelector('input[type="radio"]:not(:disabled)');
			enfocar(primeiro);
			return;
		}
		var clave = lerClave();
		if (!clave) {
			esquecerClave();
			estado.comercio = '';
			pintarTopo();
			estado.pendenteFamilia = estado.familia;
			renderPaso1();
			showMessage(__( 'Pasaron as 12 horas: escanea de novo o QR do teu comercio.', 'anpa-socios' ), 'error');
			return;
		}
		showMessage('', 'info');
		var res = await api('POST', urls.aplicar, clave, { codigo: estado.familia, oferta_id: id });
		if (!res) { return; }
		if (!res.ok) {
			var c = codigoErro(res);
			if (res.status === 403 || c === 'anpa_colab_familia_non_valida') {
				estado.valida = false;
				estado.ofertas = [];
				renderPaso3();
				return;
			}
			if (res.status === 404 || res.status === 409) {
				comprobarFamilia(estado.familia, errorMessage(res));
				return;
			}
			showMessage(errorMessage(res), 'error');
			return;
		}
		estado.ofertas = Array.isArray(res.data && res.data.ofertas) ? res.data.ofertas : estado.ofertas;
		estado.ultimoUso = {
			id: parseInt(res.data && res.data.uso_id, 10) || 0,
			oferta: id,
			titulo: String(oferta.titulo || ''),
			desconto: String(oferta.desconto || ''),
			ts: Date.now(),
		};
		renderConfirmacion();
	}

	function renderConfirmacion() {
		limparVista();
		var u = estado.ultimoUso;
		var h = cabeceira(__( 'Oferta aplicada', 'anpa-socios' ));
		h.classList.add('anpa-colab-visualmente-oculto');
		var sec = el('section', { class: 'anpa-colab-tarxeta-paso anpa-colab-caixa-paso' }, [h]);
		/* translators: 1: offer title, 2: time */
		sec.appendChild(resultado(true, __( 'Oferta aplicada', 'anpa-socios' ), sprintf( __( '«%1$s» ás %2$s.', 'anpa-socios' ), u.titulo, hora(u.ts) )));
		if (u.desconto) {
			sec.appendChild(el('p', {}, [el('span', { class: 'anpa-colab-desconto anpa-colab-desconto--grande', text: u.desconto })]));
		}
		var agora = estado.ofertas.filter(function (o) { return parseInt(o.id, 10) === u.oferta; })[0];
		if (agora) {
			sec.appendChild(el('p', {
				class: 'anpa-colab-intro',
				text: agora.disponible
					/* translators: %s: «Queda 1 uso», «Usos ilimitados»… */
					? sprintf( __( 'Para esta familia: %s.', 'anpa-socios' ), textoRestan(agora) )
					: __( 'Esta familia xa non ten máis usos desta oferta.', 'anpa-socios' ),
			}));
		}

		var nota = el('p', { class: 'anpa-colab-axuda' });
		var desfacerB = boton(__( 'Desfacer', 'anpa-socios' ), 'perigo', desfacer, { attrs: { 'aria-describedby': 'anpa-colab-caixa-desfacer-nota' } });
		nota.id = 'anpa-colab-caixa-desfacer-nota';
		var actualizar = function () {
			var fin = u.ts + DESFACER_MS;
			if (Date.now() >= fin || !u.id) {
				desfacerB.disabled = true;
				delete desfacerB.dataset.colabBusy;
				nota.textContent = __( 'Xa non se pode desfacer (pasaron 10 minutos).', 'anpa-socios' );
				if (desfacerTimer) { window.clearInterval(desfacerTimer); desfacerTimer = null; }
				return;
			}
			/* translators: %s: time */
			nota.textContent = sprintf( __( 'Se te equivocaches, podes desfacelo ata as %s (10 minutos).', 'anpa-socios' ), hora(fin) );
		};
		actualizar();
		desfacerTimer = window.setInterval(actualizar, 15000);

		var seguinte = boton(__( 'Seguinte familia', 'anpa-socios' ), 'primario', seguinteFamilia);
		var accions = el('div', { class: 'anpa-colab-accions' }, [seguinte, desfacerB]);
		if (estado.ofertas.some(function (o) { return o.disponible; })) {
			accions.appendChild(boton(__( 'Outra oferta para esta familia', 'anpa-socios' ), 'secundario', function () {
				estado.escollida = 0;
				estado.ultimoUso = null;
				renderPaso3();
			}));
		}
		sec.appendChild(accions);
		sec.appendChild(nota);
		vista.appendChild(sec);
		enfocar(h);
	}

	async function desfacer() {
		if (busyDepth > 0) { return; }
		var u = estado.ultimoUso;
		if (!u || !u.id) { return; }
		if (!window.confirm(sprintf( /* translators: %s: offer title */ __( 'Desfacer o uso de «%s»? A familia recupera ese uso.', 'anpa-socios' ), u.titulo ))) {
			return;
		}
		var clave = lerClave();
		if (!clave) {
			showMessage(__( 'Pasaron as 12 horas: escanea de novo o QR do teu comercio.', 'anpa-socios' ), 'error');
			return;
		}
		var res = await api('POST', urls.desfacer, clave, { codigo: estado.familia, uso_id: u.id });
		if (!res) { return; }
		if (!res.ok) {
			if (res.status === 403) {
				estado.valida = false;
				estado.ofertas = [];
				renderPaso3();
				return;
			}
			showMessage(errorMessage(res), 'error');
			return;
		}
		estado.ofertas = Array.isArray(res.data && res.data.ofertas) ? res.data.ofertas : estado.ofertas;
		estado.ultimoUso = null;
		estado.escollida = 0;
		renderPaso3();
		showMessage(__( 'Uso desfeito. A familia recupera ese uso.', 'anpa-socios' ), 'success');
	}

	function seguinteFamilia() {
		limparFamilia();
		showMessage('', 'info');
		if (!lerClave()) {
			estado.comercio = '';
			pintarTopo();
			renderPaso1();
			return;
		}
		renderPaso2();
	}

	/* ── Start ─────────────────────────────────────────────────────── */

	async function procesarFragmento(h) {
		var p = lerFragmento(h);
		var k = normalizar(p.k, LONX_CLAVE);
		var f = normalizar(p.f, LONX_FAMILIA);
		if (f) { estado.pendenteFamilia = f; }
		if (k) {
			await usarClave(k);
			return true;
		}
		return false;
	}

	async function iniciar() {
		var feito = await procesarFragmento(fragmentoInicial);
		fragmentoInicial = '';
		if (feito) { return; }
		var clave = lerClave();
		if (!clave) {
			renderPaso1();
			return;
		}
		var res = await api('GET', urls.caixa, clave);
		if (!res) {
			if (!vista.firstChild) { renderPaso1(); }
			return;
		}
		if (!res.ok || !res.data || typeof res.data.comercio !== 'string') {
			renderPaso1();
			showMessage(errorMessage(res), 'error');
			return;
		}
		estado.comercio = res.data.comercio;
		pintarTopo();
		var pendente = estado.pendenteFamilia;
		estado.pendenteFamilia = '';
		if (pendente) {
			comprobarFamilia(pendente);
			return;
		}
		renderPaso2();
	}

	// A QR opened in this same tab (only the fragment changes).
	window.addEventListener('hashchange', function () {
		var h = tomarFragmento();
		if (!h) { return; }
		limparFamilia();
		showMessage('', 'info');
		procesarFragmento(h).then(function (feito) {
			if (feito) { return; }
			if (estado.pendenteFamilia) {
				var f = estado.pendenteFamilia;
				estado.pendenteFamilia = '';
				comprobarFamilia(f);
			}
		});
	});

	iniciar();
})();
