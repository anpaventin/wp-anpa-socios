/**
 * Collaborators network (1.104.0, v2 in 1.105.0): the shop's own panel.
 *
 * Sign in with email + code, propose the shop's own data and logo, create or
 * edit offers with a guided form (with an optional image), and show / print /
 * renew the shop's till QR. The shop session token travels only in the
 * X-Anpa-Colab-Token header and is kept in sessionStorage.
 *
 * Every text written by a shop is put on the page with textContent: this file
 * never builds markup from strings.
 *
 * @since  1.104.0
 * @package ANPA_Socios
 */

(function () {
	'use strict';
	const { __, sprintf } = wp.i18n;

	var TOKEN_KEY = 'anpa_colab_token';
	var TOKEN_HEADER = 'X-Anpa-Colab-Token';
	var ESTADOS = ['pendente', 'aprobada', 'rexeitada', 'retirada'];
	var LIMITES = { titulo: 80, desconto: 60, descricion: 300, condicions: 400 };
	var LIMITES_DATOS = { nome: 120, responsable: 60, telefono: 30, url_web: 512, enderezo: 200, descricion: 400 };
	var CAMPOS_DATOS = ['nome', 'responsable', 'telefono', 'url_web', 'enderezo', 'descricion'];
	var USOS_OPCIONS = [1, 2, 3, 5, 10, 0];
	var IMAXE_TIPOS = ['image/jpeg', 'image/png', 'image/webp'];
	var IMAXE_MAX = 2 * 1024 * 1024;
	var PASOS = {
		1: ['titulo', 'desconto'],
		2: ['descricion', 'condicions'],
		3: ['valida_dende', 'valida_ata', 'acumulable', 'usos_familia'],
		4: ['imaxe'],
		5: [],
	};
	var TOTAL_PASOS = 5;

	var root = document.getElementById('anpa-colab-portal');
	if (!root) {
		return;
	}

	var urls = {
		codigo: root.dataset.codigoUrl || '',
		entrar: root.dataset.entrarUrl || '',
		me: root.dataset.meUrl || '',
		sair: root.dataset.sairUrl || '',
		logo: root.dataset.logoUrl || '',
		proposta: root.dataset.propostaUrl || '',
		qr: root.dataset.qrUrl || '',
		ofertas: (root.dataset.ofertasUrl || '').replace(/\/+$/, ''),
	};
	var contacto = root.dataset.contacto || '';
	var memToken = null; // Fallback when sessionStorage is not available.
	var panel = null;    // Last panel answer (every panel route returns it whole).
	var asistente = null; // Wizard state.
	var busyDepth = 0;
	var urlsLocais = []; // Object URLs of local image previews (revoked on change).

	/* ── Token ─────────────────────────────────────────────────────── */

	function saveToken(token) {
		memToken = token;
		try { sessionStorage.setItem(TOKEN_KEY, token); } catch (_) {}
	}

	function loadToken() {
		try {
			var t = sessionStorage.getItem(TOKEN_KEY);
			if (t) { return t; }
		} catch (_) {}
		return memToken;
	}

	function clearToken() {
		memToken = null;
		try { sessionStorage.removeItem(TOKEN_KEY); } catch (_) {}
	}

	/* ── DOM helpers (no markup strings, ever) ─────────────────────── */

	/**
	 * Creates an element. `props.text` becomes textContent, `props.class`
	 * the class list, `props.attrs` plain attributes; other keys are set as
	 * properties. Children may be nodes or strings (as text nodes).
	 */
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
			} else if (k === 'on') {
				Object.keys(v).forEach(function (ev) { node.addEventListener(ev, v[ev]); });
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
			class: 'anpa-colab-boton' + (variante ? ' anpa-colab-boton--' + variante : ''),
			text: texto,
		}, extra || {}));
		if (onClick) { b.addEventListener('click', onClick); }
		return b;
	}

	function urlSegura(u) {
		return typeof u === 'string' && /^https?:\/\//i.test(u) ? u : '';
	}

	/** An <img> for a server URL (only http/https), or null. */
	function imaxe(url, alt, clase) {
		var u = urlSegura(url);
		if (!u) { return null; }
		return el('img', { src: u, alt: alt || '', class: clase || '', attrs: { loading: 'lazy', decoding: 'async' } });
	}

	/** Object URL for a local file preview; all of them are revoked together. */
	function urlLocal(ficheiro) {
		try {
			var u = URL.createObjectURL(ficheiro);
			urlsLocais.push(u);
			return u;
		} catch (_) {
			return '';
		}
	}

	function soltarUrlsLocais() {
		urlsLocais.forEach(function (u) { try { URL.revokeObjectURL(u); } catch (_) {} });
		urlsLocais = [];
	}

	function lonxitude(s) {
		return Array.from(s || '').length;
	}

	function linha(s) {
		return String(s || '').replace(/\s+/g, ' ').trim();
	}

	function parrafo(s) {
		return String(s || '').replace(/\r\n?/g, '\n').replace(/[ \t]+/g, ' ').replace(/\n{3,}/g, '\n\n').trim();
	}

	function dataBaleira(d) {
		return !d || d === '0000-00-00';
	}

	function dataValida(s) {
		var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s);
		if (!m) { return false; }
		var y = +m[1], mo = +m[2], d = +m[3];
		var dt = new Date(Date.UTC(y, mo - 1, d));
		return dt.getUTCFullYear() === y && dt.getUTCMonth() === mo - 1 && dt.getUTCDate() === d;
	}

	function nomesMeses() {
		return [
			__( 'xaneiro', 'anpa-socios' ), __( 'febreiro', 'anpa-socios' ), __( 'marzo', 'anpa-socios' ),
			__( 'abril', 'anpa-socios' ), __( 'maio', 'anpa-socios' ), __( 'xuño', 'anpa-socios' ),
			__( 'xullo', 'anpa-socios' ), __( 'agosto', 'anpa-socios' ), __( 'setembro', 'anpa-socios' ),
			__( 'outubro', 'anpa-socios' ), __( 'novembro', 'anpa-socios' ), __( 'decembro', 'anpa-socios' ),
		];
	}

	function formatarData(s) {
		if (!dataValida(s)) { return s; }
		var p = s.split('-');
		/* translators: 1: day, 2: month name, 3: year */
		return sprintf( __( '%1$d de %2$s de %3$s', 'anpa-socios' ), parseInt(p[2], 10), nomesMeses()[parseInt(p[1], 10) - 1], p[0] );
	}

	function formatarTs(ts) {
		var n = parseInt(ts, 10);
		if (!(n > 0)) { return ''; }
		var d = new Date(n * 1000);
		var hh = String(d.getHours()).padStart(2, '0');
		var mm = String(d.getMinutes()).padStart(2, '0');
		/* translators: 1: day, 2: month name, 3: time */
		return sprintf( __( '%1$d de %2$s ás %3$s', 'anpa-socios' ), d.getDate(), nomesMeses()[d.getMonth()], hh + ':' + mm );
	}

	function textoUsosFamilia(n) {
		n = parseInt(n, 10) || 0;
		if (n <= 0) { return __( 'Sen límite de usos', 'anpa-socios' ); }
		if (n === 1) { return __( 'Un uso por familia', 'anpa-socios' ); }
		/* translators: %d: uses per family */
		return sprintf( __( '%d usos por familia', 'anpa-socios' ), n );
	}

	function textoAcumulable(si) {
		return si ? __( 'Acumulable con outras ofertas', 'anpa-socios' ) : __( 'Non acumulable', 'anpa-socios' );
	}

	function horasAprobacion() {
		var h = parseInt(panel && panel.horas_aprobacion, 10);
		return isNaN(h) ? 0 : h;
	}

	/** Client check of a chosen image (the server checks again and re-encodes it). */
	function erroImaxe(ficheiro) {
		if (!ficheiro) { return ''; }
		if (IMAXE_TIPOS.indexOf(ficheiro.type) < 0) {
			return __( 'A imaxe ten que ser JPG, PNG ou WebP.', 'anpa-socios' );
		}
		if (ficheiro.size > IMAXE_MAX) {
			return __( 'A imaxe non pode pasar de 2 MB.', 'anpa-socios' );
		}
		return '';
	}

	/* ── QR drawing (vendored generator, window.qrcode) ───────────── */

	/**
	 * Draws `texto` as a QR on a new canvas, with an optional caption below.
	 * Returns null when the generator is missing or the text does not fit.
	 */
	function canvasQr(texto, lendas) {
		if (!texto || typeof window.qrcode !== 'function') { return null; }
		var qr;
		try {
			qr = window.qrcode(0, 'M');
			qr.addData(texto);
			qr.make();
		} catch (_) { return null; }
		var n = qr.getModuleCount();
		var marxe = 4;
		var escala = Math.max(4, Math.floor(480 / (n + marxe * 2)));
		var lado = (n + marxe * 2) * escala;
		lendas = (lendas || []).filter(Boolean);
		var alto = lado + (lendas.length ? 24 + lendas.length * 34 : 0);
		var canvas = el('canvas', { class: 'anpa-colab-qr-canvas' });
		canvas.width = lado;
		canvas.height = alto;
		var ctx = canvas.getContext('2d');
		if (!ctx) { return null; }
		ctx.fillStyle = '#ffffff';
		ctx.fillRect(0, 0, lado, alto);
		ctx.fillStyle = '#000000';
		for (var r = 0; r < n; r++) {
			for (var c = 0; c < n; c++) {
				if (qr.isDark(r, c)) { ctx.fillRect((c + marxe) * escala, (r + marxe) * escala, escala, escala); }
			}
		}
		ctx.textAlign = 'center';
		ctx.textBaseline = 'middle';
		lendas.forEach(function (t, i) {
			ctx.font = (i === 0 ? '700 26px' : '400 20px') + ' system-ui, sans-serif';
			ctx.fillStyle = i === 0 ? '#1f1f1f' : '#4d4d4d';
			ctx.fillText(String(t), lado / 2, lado + 8 + i * 34 + 14, lado - 24);
		});
		return canvas;
	}

	function descargarCanvas(canvas, nome) {
		if (!canvas || typeof canvas.toBlob !== 'function') {
			showMessage(__( 'Este navegador non permite descargar a imaxe.', 'anpa-socios' ), 'error');
			return;
		}
		try {
			canvas.toBlob(function (blob) {
				if (!blob) {
					showMessage(__( 'Non se puido preparar a imaxe.', 'anpa-socios' ), 'error');
					return;
				}
				var u = URL.createObjectURL(blob);
				var a = el('a', { href: u, attrs: { download: nome } });
				a.hidden = true;
				document.body.appendChild(a);
				a.click();
				document.body.removeChild(a);
				window.setTimeout(function () { URL.revokeObjectURL(u); }, 30000);
			}, 'image/png');
		} catch (_) {
			showMessage(__( 'Non se puido preparar a imaxe.', 'anpa-socios' ), 'error');
		}
	}

	/** Prints only `node` (see the print rules in colab.css). */
	function imprimirSo(node) {
		var html = document.documentElement;
		var limpar = function () {
			html.classList.remove('anpa-colab-imprimindo');
			node.classList.remove('anpa-colab-imprimible');
			window.removeEventListener('afterprint', limpar);
		};
		html.classList.add('anpa-colab-imprimindo');
		node.classList.add('anpa-colab-imprimible');
		window.addEventListener('afterprint', limpar);
		try { window.print(); } catch (_) {}
		window.setTimeout(limpar, 1000);
	}

	/* ── Layout: message region + view ───────────────────────────── */

	while (root.firstChild) { root.removeChild(root.firstChild); }
	var mensaxe = el('div', {
		class: 'anpa-colab-mensaxe',
		hidden: true,
		attrs: { 'aria-live': 'polite', 'aria-atomic': 'true' },
	});
	var vista = el('div', { class: 'anpa-colab-vista' });
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
		soltarUrlsLocais();
		while (vista.firstChild) { vista.removeChild(vista.firstChild); }
	}

	function enfocar(node) {
		if (!node) { return; }
		try { node.focus(); } catch (_) {}
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
	 * Sends a request: JSON, or multipart when `body` is a FormData. Returns
	 * {ok, status, data}, or null when the request failed in a way already
	 * reported (network, expired session).
	 */
	async function api(method, url, body, auth) {
		var headers = { Accept: 'application/json' };
		var multipart = typeof FormData !== 'undefined' && body instanceof FormData;
		if (body !== undefined && !multipart) { headers['Content-Type'] = 'application/json'; }
		if (auth) {
			var token = loadToken();
			if (!token) {
				sessionEnded();
				return null;
			}
			headers[TOKEN_HEADER] = token; // Header only: never in a URL.
		}
		setBusy(true);
		try {
			var res;
			try {
				res = await fetch(url, {
					method: method,
					headers: headers,
					body: body === undefined ? undefined : (multipart ? body : JSON.stringify(body)),
					credentials: 'same-origin',
				});
			} catch (_) {
				showMessage(__( 'Erro de rede. Téntao de novo.', 'anpa-socios' ), 'error');
				return null;
			}
			var data = null;
			if (res.status !== 204) {
				try { data = await res.json(); } catch (_) { data = null; }
			}
			if (auth && (res.status === 401 || res.status === 403)) {
				sessionEnded(data && data.message);
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

	function esPanel(res) {
		return !!(res && res.ok && res.data && res.data.comercio);
	}

	function sessionEnded(msg) {
		clearToken();
		panel = null;
		asistente = null;
		renderEntrar(true);
		showMessage(msg || __( 'A sesión rematou. Entra de novo.', 'anpa-socios' ), 'error');
	}

	/** Uploads one image file (multipart) to `url` under `nomeCampo`. */
	function subirImaxe(url, nomeCampo, ficheiro) {
		var fd = new FormData();
		fd.append(nomeCampo, ficheiro, ficheiro.name || (nomeCampo + '.jpg'));
		return api('POST', url, fd, true);
	}

	/* ── Form field helpers ───────────────────────────────────────── */

	var uid = 0;
	function novoId(base) {
		uid += 1;
		return 'anpa-colab-' + base + '-' + uid;
	}

	/**
	 * A labelled field: label above, optional hint, control, optional
	 * counter, error line. Returns { wrap, input, erro, contador }.
	 */
	function campo(opts) {
		var id = novoId(opts.name);
		var describedby = [];
		var wrap = el('div', { class: 'anpa-colab-campo' });
		wrap.dataset.campo = opts.name;
		var etiqueta = el('label', { class: 'anpa-colab-etiqueta', attrs: { for: id }, text: opts.label });
		if (opts.optional) {
			etiqueta.appendChild(el('span', { class: 'anpa-colab-opcional', text: ' ' + __( '(opcional)', 'anpa-socios' ) }));
		}
		wrap.appendChild(etiqueta);
		var axuda = null;
		if (opts.hint) {
			axuda = el('p', { class: 'anpa-colab-axuda', id: id + '-axuda', text: opts.hint });
			describedby.push(axuda.id);
			wrap.appendChild(axuda);
		}
		var input = el(opts.multiline ? 'textarea' : 'input', { id: id, name: opts.name });
		if (!opts.multiline) { input.type = opts.type || 'text'; }
		if (opts.multiline) { input.rows = opts.rows || 4; }
		Object.keys(opts.attrs || {}).forEach(function (a) { input.setAttribute(a, opts.attrs[a]); });
		if (opts.max) { input.maxLength = opts.max; }
		if (opts.placeholder) { input.placeholder = opts.placeholder; }
		input.value = opts.value || '';
		wrap.appendChild(input);

		var contador = null;
		if (opts.max && opts.counter !== false) {
			contador = el('p', { class: 'anpa-colab-contador', id: id + '-contador' });
			describedby.push(contador.id);
			var actualizar = function () {
				var n = lonxitude(opts.multiline ? parrafo(input.value) : linha(input.value));
				/* translators: 1: characters typed, 2: maximum */
				contador.textContent = sprintf( __( '%1$d / %2$d caracteres', 'anpa-socios' ), n, opts.max );
				contador.classList.toggle('anpa-colab-contador--curto', !!opts.min && n > 0 && n < opts.min);
				contador.classList.toggle('anpa-colab-contador--limite', n >= opts.max);
			};
			input.addEventListener('input', actualizar);
			actualizar();
			wrap.appendChild(contador);
		}

		var erro = el('p', { class: 'anpa-colab-erro-campo', id: id + '-erro', hidden: true });
		wrap.appendChild(erro);
		describedby.push(erro.id);
		input.setAttribute('aria-describedby', describedby.join(' '));

		return { wrap: wrap, input: input, erro: erro, contador: contador };
	}

	/**
	 * A group of radio buttons in a fieldset. Returns { wrap, input (first
	 * radio, for focus), erro, valor() }.
	 */
	function radios(opts) {
		var nome = novoId(opts.name);
		var wrap = el('fieldset', { class: 'anpa-colab-campo anpa-colab-radios' });
		wrap.dataset.campo = opts.name;
		wrap.appendChild(el('legend', { class: 'anpa-colab-etiqueta', text: opts.label }));
		var describedby = [];
		if (opts.hint) {
			var axuda = el('p', { class: 'anpa-colab-axuda', id: nome + '-axuda', text: opts.hint });
			describedby.push(axuda.id);
			wrap.appendChild(axuda);
		}
		var erro = el('p', { class: 'anpa-colab-erro-campo', id: nome + '-erro', hidden: true });
		describedby.push(erro.id);
		var lista = el('div', { class: 'anpa-colab-radios-lista' });
		var inputs = [];
		opts.opcions.forEach(function (op) {
			var id = novoId(opts.name + '-op');
			var input = el('input', { type: 'radio', id: id, name: nome, value: String(op.valor) });
			input.checked = String(op.valor) === String(opts.value);
			if (opts.onChange) { input.addEventListener('change', function () { if (input.checked) { opts.onChange(op.valor); } }); }
			inputs.push(input);
			lista.appendChild(el('label', { class: 'anpa-colab-radio', attrs: { for: id } }, [input, el('span', { text: op.texto })]));
		});
		wrap.appendChild(lista);
		wrap.appendChild(erro);
		wrap.setAttribute('aria-describedby', describedby.join(' '));
		var marcado = inputs.filter(function (i) { return i.checked; })[0];
		return { wrap: wrap, input: marcado || inputs[0], erro: erro };
	}

	function marcarErro(c, texto) {
		if (texto) {
			c.erro.textContent = texto;
			c.erro.hidden = false;
			c.input.setAttribute('aria-invalid', 'true');
			c.wrap.classList.add('anpa-colab-campo--erro');
		} else {
			c.erro.textContent = '';
			c.erro.hidden = true;
			c.input.removeAttribute('aria-invalid');
			c.wrap.classList.remove('anpa-colab-campo--erro');
		}
	}

	function cabeceira(nivel, texto) {
		return el(nivel, { class: 'anpa-colab-cabeceira', text: texto, attrs: { tabindex: '-1' } });
	}

	/**
	 * An image picker: current image (if any), file input, local preview and
	 * an error line. `onChange(file|null)` is called with the valid file.
	 * Returns { wrap, input, erro }.
	 */
	function selectorImaxe(opts) {
		var id = novoId(opts.name);
		var wrap = el('div', { class: 'anpa-colab-campo anpa-colab-imaxe-campo' });
		wrap.dataset.campo = opts.name;
		var etiqueta = el('label', { class: 'anpa-colab-etiqueta', attrs: { for: id }, text: opts.label });
		if (opts.optional) {
			etiqueta.appendChild(el('span', { class: 'anpa-colab-opcional', text: ' ' + __( '(opcional)', 'anpa-socios' ) }));
		}
		wrap.appendChild(etiqueta);
		var axuda = el('p', { class: 'anpa-colab-axuda', id: id + '-axuda', text: opts.hint || '' });
		wrap.appendChild(axuda);

		var previa = el('div', { class: 'anpa-colab-imaxe-previa', attrs: { 'aria-live': 'polite' } });
		function amosar(src, texto) {
			while (previa.firstChild) { previa.removeChild(previa.firstChild); }
			if (src) {
				previa.appendChild(el('img', { src: src, alt: opts.alt || '' }));
			}
			if (texto) {
				previa.appendChild(el('p', { class: 'anpa-colab-axuda', text: texto }));
			}
		}
		amosar(urlSegura(opts.actual), opts.actual ? opts.textoActual : opts.textoSen);

		var input = el('input', { type: 'file', id: id, name: opts.name, accept: IMAXE_TIPOS.join(',') });
		var erro = el('p', { class: 'anpa-colab-erro-campo', id: id + '-erro', hidden: true });
		input.setAttribute('aria-describedby', axuda.id + ' ' + erro.id);
		var res = { wrap: wrap, input: input, erro: erro, amosar: amosar };
		input.addEventListener('change', function () {
			var f = input.files && input.files[0] ? input.files[0] : null;
			var e = erroImaxe(f);
			if (e) {
				marcarErro(res, e);
				input.value = '';
				opts.onChange(null);
				return;
			}
			marcarErro(res, '');
			if (f) {
				amosar(urlLocal(f), __( 'Imaxe nova (aínda sen enviar).', 'anpa-socios' ));
			}
			opts.onChange(f);
		});
		wrap.appendChild(previa);
		wrap.appendChild(input);
		wrap.appendChild(erro);
		return res;
	}

	/* ── a. Sign in ────────────────────────────────────────────────── */

	function renderEntrar(focus, email) {
		asistente = null;
		limparVista();
		var h = cabeceira('h2', __( 'Acceso para comercios colaboradores', 'anpa-socios' ));
		var intro = el('p', { class: 'anpa-colab-intro', text: __( 'Escribe o correo do comercio e enviarémosche un código de 6 cifras para entrar.', 'anpa-socios' ) });
		var cEmail = campo({
			name: 'email',
			type: 'email',
			label: __( 'Correo electrónico do comercio', 'anpa-socios' ),
			value: email || '',
			attrs: { autocomplete: 'email', required: 'required', inputmode: 'email', autocapitalize: 'off', spellcheck: 'false' },
		});

		// Honeypot: people never see or reach it; bots tend to fill it in.
		var hpId = novoId('website');
		var hpInput = el('input', { type: 'text', id: hpId, name: 'website', tabIndex: -1, attrs: { autocomplete: 'off' } });
		var hp = el('div', { class: 'anpa-colab-hp', attrs: { 'aria-hidden': 'true' } }, [
			el('label', { attrs: { for: hpId }, text: __( 'Non enchas este campo', 'anpa-socios' ) }),
			hpInput,
		]);

		var enviar = el('button', { type: 'submit', class: 'anpa-colab-boton anpa-colab-boton--primario', text: __( 'Enviar código', 'anpa-socios' ) });
		var form = el('form', { class: 'anpa-colab-form', noValidate: true }, [cEmail.wrap, hp, el('div', { class: 'anpa-colab-accions' }, [enviar])]);

		form.addEventListener('submit', async function (ev) {
			ev.preventDefault();
			if (busyDepth > 0) { return; }
			var valor = cEmail.input.value.trim();
			if (!valor || !cEmail.input.checkValidity()) {
				marcarErro(cEmail, __( 'Escribe un correo válido.', 'anpa-socios' ));
				enfocar(cEmail.input);
				return;
			}
			marcarErro(cEmail, '');
			var res = await api('POST', urls.codigo, { email: valor, website: hpInput.value, _ts: root.dataset.ts || '' }, false);
			if (!res) { return; }
			if (!res.ok) {
				showMessage(errorMessage(res), 'error');
				return;
			}
			renderCodigo(valor);
			showMessage((res.data && res.data.message) || __( 'Se o correo é dun comercio colaborador, recibirás un código en breve.', 'anpa-socios' ), 'info');
		});

		vista.appendChild(el('section', { class: 'anpa-colab-tarxeta-paso' }, [h, intro, form]));
		if (focus) { enfocar(h); }
	}

	/* ── b. Code ───────────────────────────────────────────────────── */

	function renderCodigo(email) {
		limparVista();
		var h = cabeceira('h2', __( 'Escribe o código', 'anpa-socios' ));
		/* translators: %s: email address */
		var intro = el('p', { class: 'anpa-colab-intro', text: sprintf( __( 'Mira o correo de %s e escribe aquí o código de 6 cifras. Se non chega en poucos minutos, revisa o correo lixo.', 'anpa-socios' ), email ) });
		var cCod = campo({
			name: 'codigo',
			label: __( 'Código de acceso', 'anpa-socios' ),
			// No maxlength: a pasted «123 456» is cleaned below instead of cut short.
			attrs: { inputmode: 'numeric', autocomplete: 'one-time-code', pattern: '[0-9]*', required: 'required' },
		});
		cCod.input.classList.add('anpa-colab-input-codigo');
		cCod.input.addEventListener('input', function () {
			var limpo = cCod.input.value.replace(/\D/g, '').slice(0, 6);
			if (limpo !== cCod.input.value) { cCod.input.value = limpo; }
		});

		var entrar = el('button', { type: 'submit', class: 'anpa-colab-boton anpa-colab-boton--primario', text: __( 'Entrar', 'anpa-socios' ) });
		var outro = boton(__( 'Pedir outro código', 'anpa-socios' ), 'ligazon', function () {
			showMessage('', 'info');
			renderEntrar(true, email);
		});
		var form = el('form', { class: 'anpa-colab-form', noValidate: true }, [cCod.wrap, el('div', { class: 'anpa-colab-accions' }, [entrar, outro])]);

		form.addEventListener('submit', async function (ev) {
			ev.preventDefault();
			if (busyDepth > 0) { return; }
			var codigo = cCod.input.value.replace(/\D/g, '');
			if (codigo.length !== 6) {
				marcarErro(cCod, __( 'O código ten 6 cifras.', 'anpa-socios' ));
				enfocar(cCod.input);
				return;
			}
			marcarErro(cCod, '');
			var res = await api('POST', urls.entrar, { email: email, codigo: codigo }, false);
			if (!res) { return; }
			if (!res.ok || !res.data || typeof res.data.session_token !== 'string' || !res.data.session_token) {
				showMessage(errorMessage(res), 'error');
				enfocar(cCod.input);
				return;
			}
			saveToken(res.data.session_token);
			showMessage('', 'info');
			cargarPanel(true, '', true);
		});

		vista.appendChild(el('section', { class: 'anpa-colab-tarxeta-paso' }, [h, intro, form]));
		enfocar(cCod.input);
	}

	/* ── c. Panel ──────────────────────────────────────────────────── */

	/** A shop without approved data and without a proposal fills its data first. */
	function precisaDatos() {
		return !!(panel && panel.comercio && panel.comercio.cubertos === false && !panel.proposta);
	}

	async function cargarPanel(focus, msg, entrada) {
		var res = await api('GET', urls.me, undefined, true);
		if (!res) { return; }
		if (!esPanel(res)) {
			if (!panel) { renderEntrar(focus); }
			showMessage(errorMessage(res), 'error');
			return;
		}
		panel = res.data;
		if (entrada && precisaDatos()) {
			renderDatos(true);
			return;
		}
		renderPanel(focus);
		if (msg) { showMessage(msg, 'success'); }
	}

	function ofertasVivas() {
		return ((panel && panel.ofertas) || []).filter(function (o) { return o.estado !== 'retirada'; }).length;
	}

	function maxOfertas() {
		var n = parseInt(panel && panel.max_ofertas, 10);
		return isNaN(n) ? 10 : n;
	}

	function maxDescricion() {
		var n = parseInt(panel && panel.max_descricion, 10);
		return n > 0 ? n : LIMITES.descricion;
	}

	function textoVixencia(dende, ata) {
		var d = dataBaleira(dende) ? '' : dende;
		var a = dataBaleira(ata) ? '' : ata;
		if (d && a) {
			/* translators: 1: start date, 2: end date */
			return sprintf( __( 'Do %1$s ao %2$s', 'anpa-socios' ), formatarData(d), formatarData(a) );
		}
		if (d) {
			/* translators: %s: date */
			return sprintf( __( 'Desde o %s, sen data de fin', 'anpa-socios' ), formatarData(d) );
		}
		if (a) {
			/* translators: %s: date */
			return sprintf( __( 'Válida ata o %s', 'anpa-socios' ), formatarData(a) );
		}
		return __( 'Sen data de fin (ata que a retires)', 'anpa-socios' );
	}

	function renderPanel(focus) {
		asistente = null;
		limparVista();
		var c = panel.comercio || {};

		var h = cabeceira('h2', c.nome || __( 'O teu comercio', 'anpa-socios' ));
		var sair = boton(__( 'Saír', 'anpa-socios' ), 'secundario', async function () {
			if (busyDepth > 0) { return; }
			if (loadToken()) {
				await api('DELETE', urls.sair, undefined, true);
			}
			clearToken();
			panel = null;
			renderEntrar(true);
			showMessage(__( 'Saíches do panel do comercio.', 'anpa-socios' ), 'info');
		});
		var topo = el('div', { class: 'anpa-colab-panel-topo' }, [h, sair]);

		vista.appendChild(el('div', { class: 'anpa-colab-panel' }, [topo, seccionDatos(), seccionOfertas(), seccionQr()]));
		if (focus) { enfocar(h); }
	}

	/* ── c1. «Os meus datos» ───────────────────────────────────────── */

	function etiquetasDatos() {
		return {
			nome: __( 'Nome do comercio', 'anpa-socios' ),
			responsable: __( 'Persoa de contacto', 'anpa-socios' ),
			telefono: __( 'Teléfono', 'anpa-socios' ),
			url_web: __( 'Web', 'anpa-socios' ),
			enderezo: __( 'Enderezo', 'anpa-socios' ),
			descricion: __( 'Descrición', 'anpa-socios' ),
		};
	}

	/** A read-only list of shop data (approved or proposed). */
	function fichaDatos(datos, conEmail) {
		var et = etiquetasDatos();
		var dl = el('dl', { class: 'anpa-colab-ficha' });
		function fila(etiqueta, valor) {
			if (!valor) { return; }
			dl.appendChild(el('dt', { text: etiqueta }));
			dl.appendChild(el('dd', {}, [valor]));
		}
		CAMPOS_DATOS.forEach(function (k) {
			var v = datos[k] ? String(datos[k]) : '';
			if (k === 'url_web' && v) {
				var web = urlSegura(v);
				fila(et[k], web ? el('a', { href: web, text: v, attrs: { rel: 'noopener nofollow', target: '_blank' } }) : v);
				return;
			}
			fila(et[k], v);
		});
		if (conEmail) {
			fila(__( 'Correo de acceso', 'anpa-socios' ), datos.email ? String(datos.email) : '');
		}
		return dl;
	}

	function seccionDatos() {
		var c = panel.comercio || {};
		var p = panel.proposta || null;
		var sec = el('section', { class: 'anpa-colab-seccion anpa-colab-datos-comercio' });
		var cab = el('div', { class: 'anpa-colab-ofertas-topo' }, [
			el('h3', { text: __( 'Os meus datos', 'anpa-socios' ) }),
			boton(__( 'Editar os meus datos', 'anpa-socios' ), 'secundario', function () { renderDatos(false); }),
		]);
		sec.appendChild(cab);

		// State of the data.
		var estado = el('div', { class: 'anpa-colab-proposta' });
		if (p && p.estado === 'pendente') {
			estado.classList.add('anpa-colab-proposta--pendente');
			estado.appendChild(el('span', { class: 'anpa-colab-chip anpa-colab-chip--pendente', text: __( 'Pendente de aprobación', 'anpa-socios' ) }));
			var cando = formatarTs(p.enviada_ts);
			estado.appendChild(el('p', {
				text: cando
					/* translators: %s: date and time */
					? sprintf( __( 'Enviaches cambios o %s. Mentres a xunta non os aproba, a web segue amosando os datos aprobados.', 'anpa-socios' ), cando )
					: __( 'Enviaches cambios. Mentres a xunta non os aproba, a web segue amosando os datos aprobados.', 'anpa-socios' ),
			}));
			var horas = horasAprobacion();
			if (!p.manual && horas > 0) {
				/* translators: %d: hours */
				estado.appendChild(el('p', { class: 'anpa-colab-axuda', text: sprintf( __( 'Se ninguén os revisa, aprobaranse sós en %d horas.', 'anpa-socios' ), horas ) }));
			} else if (p.manual) {
				estado.appendChild(el('p', { class: 'anpa-colab-axuda', text: __( 'Estes cambios non se aproban sós: ten que revisalos a xunta.', 'anpa-socios' ) }));
			}
		} else if (p && p.estado === 'rexeitada') {
			estado.classList.add('anpa-colab-proposta--rexeitada');
			estado.appendChild(el('span', { class: 'anpa-colab-chip anpa-colab-chip--rexeitada', text: __( 'Non aprobada', 'anpa-socios' ) }));
			estado.appendChild(el('p', { text: __( 'A xunta non aprobou os últimos cambios. Podes corrixilos e envialos de novo.', 'anpa-socios' ) }));
			if (p.motivo) {
				estado.appendChild(el('p', { class: 'anpa-colab-motivo' }, [
					el('strong', { text: __( 'Motivo:', 'anpa-socios' ) + ' ' }),
					String(p.motivo),
				]));
			}
		} else if (c.cubertos) {
			estado.classList.add('anpa-colab-proposta--aprobada');
			estado.appendChild(el('span', { class: 'anpa-colab-chip anpa-colab-chip--aprobada', text: __( 'Aprobados', 'anpa-socios' ) }));
			estado.appendChild(el('p', { text: __( 'Os teus datos están aprobados: son os que se ven na web.', 'anpa-socios' ) }));
		} else {
			estado.appendChild(el('p', { class: 'anpa-colab-aviso', text: __( 'Aínda non enviaches os datos do teu comercio. Preme «Editar os meus datos» para completalos.', 'anpa-socios' ) }));
		}
		sec.appendChild(estado);

		// What the website shows now (approved).
		var aprobados = el('div', { class: 'anpa-colab-datos-bloque' });
		aprobados.appendChild(el('h4', { text: __( 'Datos que se ven na web', 'anpa-socios' ) }));
		var logo = imaxe(c.logo_url, __( 'Logo do comercio', 'anpa-socios' ), 'anpa-colab-logo-panel');
		if (logo) { aprobados.appendChild(logo); }
		aprobados.appendChild(fichaDatos(c, true));
		aprobados.appendChild(el('p', {
			class: 'anpa-colab-nota-datos',
			text: contacto
				/* translators: %s: ANPA contact email */
				? sprintf( __( 'O correo de acceso só o cambia a ANPA. Se hai que cambialo, escribe a %s.', 'anpa-socios' ), contacto )
				: __( 'O correo de acceso só o cambia a ANPA. Se hai que cambialo, avisa á xunta directiva.', 'anpa-socios' ),
		}));
		sec.appendChild(aprobados);

		// What was proposed.
		if (p) {
			var proposto = el('div', { class: 'anpa-colab-datos-bloque anpa-colab-datos-bloque--proposta' });
			proposto.appendChild(el('h4', { text: p.estado === 'pendente' ? __( 'Cambios enviados', 'anpa-socios' ) : __( 'Cambios non aprobados', 'anpa-socios' ) }));
			var logoP = imaxe(p.logo_url, __( 'Logo proposto', 'anpa-socios' ), 'anpa-colab-logo-panel');
			if (logoP) { proposto.appendChild(logoP); }
			proposto.appendChild(fichaDatos(p.campos || {}, false));
			proposto.appendChild(el('div', { class: 'anpa-colab-accions' }, [
				boton(__( 'Retirar a proposta', 'anpa-socios' ), 'perigo', retirarProposta),
			]));
			sec.appendChild(proposto);
		}
		return sec;
	}

	async function retirarProposta() {
		if (busyDepth > 0) { return; }
		if (!window.confirm(__( 'Seguro que queres retirar os cambios enviados? A web seguirá amosando os datos aprobados.', 'anpa-socios' ))) {
			return;
		}
		var res = await api('DELETE', urls.proposta, undefined, true);
		if (!res) { return; }
		if (!esPanel(res)) {
			showMessage(errorMessage(res), 'error');
			return;
		}
		panel = res.data;
		renderPanel(true);
		showMessage(__( 'Proposta retirada.', 'anpa-socios' ), 'success');
	}

	/** The data form. `primeiraVez`: a shop without approved data, just signed in. */
	function renderDatos(primeiraVez) {
		asistente = null;
		limparVista();
		var c = panel.comercio || {};
		var p = panel.proposta || null;
		var base = {};
		CAMPOS_DATOS.forEach(function (k) {
			base[k] = String((p && p.campos && p.campos[k] !== undefined ? p.campos[k] : c[k]) || '');
		});
		var et = etiquetasDatos();
		var logoNovo = null;

		var h = cabeceira('h2', primeiraVez ? __( 'Benvida/o! Completa os datos do teu comercio', 'anpa-socios' ) : __( 'Os meus datos', 'anpa-socios' ));
		var corpo = el('div', { class: 'anpa-colab-paso-corpo' });
		if (primeiraVez) {
			corpo.appendChild(el('p', { class: 'anpa-colab-intro', text: __( 'Antes de nada, completa os datos do teu comercio e engade o teu logo. Así as familias saberán quen ofrece cada oferta.', 'anpa-socios' ) }));
		}
		var horas = horasAprobacion();
		corpo.appendChild(el('p', {
			class: 'anpa-colab-aviso anpa-colab-aviso--info',
			text: horas > 0
				/* translators: %d: hours */
				? sprintf( __( 'Calquera cambio queda pendente ata que o aprobe a xunta directiva (ou só, en %d horas). Mentres, a web segue amosando os datos aprobados.', 'anpa-socios' ), horas )
				: __( 'Calquera cambio queda pendente ata que o aprobe a xunta directiva. Mentres, a web segue amosando os datos aprobados.', 'anpa-socios' ),
		}));

		var campos = {};
		function engadir(opts) {
			opts.value = base[opts.name];
			opts.label = et[opts.name];
			opts.max = LIMITES_DATOS[opts.name];
			var cc = campo(opts);
			cc.input.addEventListener('input', function () { marcarErro(cc, ''); });
			campos[opts.name] = cc;
			corpo.appendChild(cc.wrap);
		}
		engadir({ name: 'nome', hint: __( 'Como queres que apareza na web.', 'anpa-socios' ), min: 2, attrs: { autocomplete: 'organization', required: 'required' } });
		engadir({ name: 'responsable', optional: true, hint: __( 'A persoa coa que pode falar a ANPA.', 'anpa-socios' ), attrs: { autocomplete: 'name' } });
		engadir({ name: 'telefono', type: 'tel', optional: true, counter: false, attrs: { autocomplete: 'tel', inputmode: 'tel' } });
		engadir({ name: 'url_web', type: 'url', optional: true, counter: false, hint: __( 'Ten que comezar por https:// ou http://.', 'anpa-socios' ), placeholder: 'https://', attrs: { autocomplete: 'url', inputmode: 'url', autocapitalize: 'off', spellcheck: 'false' } });
		engadir({ name: 'enderezo', optional: true, attrs: { autocomplete: 'street-address' } });
		engadir({ name: 'descricion', multiline: true, rows: 4, optional: true, hint: __( 'Que fas, horario, o que queiras contar ás familias.', 'anpa-socios' ) });

		var logoActual = (p && p.logo_url) || c.logo_url || '';
		var cLogo = selectorImaxe({
			name: 'logo',
			label: __( 'Logo do comercio', 'anpa-socios' ),
			optional: true,
			hint: __( 'JPG, PNG ou WebP, ata 2 MB. Mellor cadrado e con fondo claro.', 'anpa-socios' ),
			actual: logoActual,
			alt: __( 'Logo do comercio', 'anpa-socios' ),
			textoActual: (p && p.logo_url) ? __( 'Logo enviado (pendente).', 'anpa-socios' ) : __( 'Logo actual.', 'anpa-socios' ),
			textoSen: __( 'Aínda non hai logo.', 'anpa-socios' ),
			onChange: function (f) { logoNovo = f; },
		});
		corpo.appendChild(cLogo.wrap);

		var enviar = el('button', { type: 'submit', class: 'anpa-colab-boton anpa-colab-boton--primario', text: __( 'Enviar para aprobación', 'anpa-socios' ) });
		var cancelar = boton(primeiraVez ? __( 'Agora non, ir ao panel', 'anpa-socios' ) : __( 'Cancelar', 'anpa-socios' ), 'ligazon', function () {
			showMessage('', 'info');
			renderPanel(true);
		});
		var form = el('form', { class: 'anpa-colab-form', noValidate: true }, [corpo, el('div', { class: 'anpa-colab-accions anpa-colab-accions--asistente' }, [enviar, cancelar])]);

		form.addEventListener('submit', async function (ev) {
			ev.preventDefault();
			if (busyDepth > 0) { return; }
			var d = {};
			CAMPOS_DATOS.forEach(function (k) {
				d[k] = k === 'descricion' ? parrafo(campos[k].input.value) : linha(campos[k].input.value);
			});
			var erros = {};
			if (lonxitude(d.nome) < 2) { erros.nome = __( 'Escribe o nome do comercio.', 'anpa-socios' ); }
			if (d.url_web && !/^https?:\/\/\S+$/i.test(d.url_web)) { erros.url_web = __( 'A web debe comezar por http:// ou https://.', 'anpa-socios' ); }
			if (marcarErros(campos, erros)) { return; }

			var cambiou = CAMPOS_DATOS.some(function (k) { return d[k] !== (k === 'descricion' ? parrafo(base[k]) : linha(base[k])); });
			if (!cambiou && !logoNovo) {
				showMessage(__( 'Non cambiaches ningún dato.', 'anpa-socios' ), 'info');
				return;
			}
			if (cambiou) {
				var res = await api('PUT', urls.me, d, true);
				if (!res) { return; }
				if (!esPanel(res)) {
					var f = res.data && res.data.data && res.data.data.fields;
					if (res.status === 400 && f && typeof f === 'object') { marcarErros(campos, f); }
					showMessage(errorMessage(res), 'error');
					return;
				}
				panel = res.data;
			}
			if (logoNovo) {
				var r2 = await subirImaxe(urls.logo, 'logo', logoNovo);
				if (!r2) { return; }
				if (!esPanel(r2)) {
					if (cambiou) {
						renderPanel(true);
						/* translators: %s: server error */
						showMessage(sprintf( __( 'Os datos enviáronse, pero o logo non: %s', 'anpa-socios' ), errorMessage(r2) ), 'error');
					} else {
						marcarErro(cLogo, errorMessage(r2));
						showMessage(errorMessage(r2), 'error');
					}
					return;
				}
				panel = r2.data;
			}
			renderPanel(true);
			showMessage(__( 'Datos enviados. Quedan pendentes de aprobación.', 'anpa-socios' ), 'success');
		});

		vista.appendChild(el('section', { class: 'anpa-colab-asistente' }, [h, form]));
		enfocar(h);
	}

	/** Marks field errors (map name → text); focuses the first. Returns whether any. */
	function marcarErros(campos, erros) {
		var primeiro = null;
		Object.keys(campos).forEach(function (k) {
			var t = typeof erros[k] === 'string' ? erros[k] : '';
			marcarErro(campos[k], t);
			if (t && !primeiro) { primeiro = campos[k].input; }
		});
		if (primeiro) {
			enfocar(primeiro);
			showMessage(__( 'Revisa os campos marcados.', 'anpa-socios' ), 'error');
		}
		return !!primeiro;
	}

	/* ── c2. Offers ────────────────────────────────────────────────── */

	function seccionOfertas() {
		var vivas = ofertasVivas();
		var max = maxOfertas();
		var cheo = vivas >= max;
		var explicacionId = novoId('max');
		var nova = boton(__( 'Nova oferta', 'anpa-socios' ), 'primario', function () {
			abrirAsistente(null);
		});
		var cabOfertas = el('div', { class: 'anpa-colab-ofertas-topo' }, [
			el('h3', { text: __( 'As túas ofertas', 'anpa-socios' ) }),
			nova,
		]);
		/* translators: 1: offers not withdrawn, 2: maximum */
		var conta = el('p', { class: 'anpa-colab-conta', text: sprintf( __( '%1$d de %2$d ofertas (sen contar as retiradas).', 'anpa-socios' ), vivas, max ) });
		var envio = el('p', { class: 'anpa-colab-axuda' }, [
			__( 'As ofertas aprobadas publícanse todas xuntas unha vez á semana.', 'anpa-socios' ),
			panel.seguinte_envio ? ' ' : null,
			panel.seguinte_envio ? el('strong', { text: String(panel.seguinte_envio) + '.' }) : null,
		]);
		var explicacion = null;
		if (cheo) {
			nova.disabled = true;
			nova.setAttribute('aria-describedby', explicacionId);
			explicacion = el('p', {
				class: 'anpa-colab-aviso',
				id: explicacionId,
				text: __( 'Xa tes o máximo de ofertas. Retira algunha antes de crear outra.', 'anpa-socios' ),
			});
		}

		var lista;
		var ofertas = (panel.ofertas || []);
		if (ofertas.length === 0) {
			lista = el('p', { class: 'anpa-colab-intro', text: __( 'Aínda non tes ofertas. Crea a primeira con «Nova oferta».', 'anpa-socios' ) });
		} else {
			lista = el('ul', { class: 'anpa-colab-panel-ofertas' });
			ofertas.forEach(function (o) { lista.appendChild(tarxetaOferta(o)); });
		}

		return el('section', { class: 'anpa-colab-seccion' }, [cabOfertas, conta, envio, explicacion, lista]);
	}

	function tarxetaOferta(o) {
		var estado = ESTADOS.indexOf(o.estado) >= 0 ? o.estado : 'outro';
		var li = el('li', { class: 'anpa-colab-tarxeta anpa-colab-tarxeta--' + estado });
		var cab = el('div', { class: 'anpa-colab-tarxeta-cab' });
		var foto = imaxe(o.imaxe_url || (panel.comercio && panel.comercio.logo_url), '', 'anpa-colab-tarxeta-imaxe');
		if (foto) { cab.appendChild(foto); }
		var textos = el('div', { class: 'anpa-colab-tarxeta-textos' }, [
			el('span', { class: 'anpa-colab-chip anpa-colab-chip--' + estado, text: o.estado_texto || o.estado || '' }),
			el('h4', { class: 'anpa-colab-tarxeta-titulo', text: o.titulo || '' }),
			o.desconto ? el('p', {}, [el('span', { class: 'anpa-colab-desconto', text: o.desconto })]) : null,
		]);
		cab.appendChild(textos);
		li.appendChild(cab);
		if (o.sae_o) {
			li.appendChild(el('p', { class: 'anpa-colab-sae', text: String(o.sae_o) }));
		}
		if (o.descricion) {
			li.appendChild(el('p', { class: 'anpa-colab-texto', text: o.descricion }));
		}
		if (o.condicions) {
			li.appendChild(el('p', { class: 'anpa-colab-condicions', text: o.condicions }));
		}
		li.appendChild(el('p', { class: 'anpa-colab-vixencia', text: textoVixencia(o.valida_dende, o.valida_ata) }));
		li.appendChild(el('p', { class: 'anpa-colab-etiquetas' }, [
			el('span', { class: 'anpa-colab-etiqueta' + (o.acumulable ? ' anpa-colab-etiqueta--si' : ''), text: textoAcumulable(!!o.acumulable) }),
			' ',
			el('span', { class: 'anpa-colab-etiqueta', text: textoUsosFamilia(o.usos_familia) }),
			!o.imaxe_url ? ' ' : null,
			!o.imaxe_url ? el('span', { class: 'anpa-colab-etiqueta anpa-colab-etiqueta--suave', text: __( 'Sen imaxe: amósase o logo', 'anpa-socios' ) }) : null,
		]));
		// After a rejection the offer waits for the junta, whatever the hours.
		if (o.estado === 'pendente' && o.so_manual) {
			li.appendChild(el('p', { class: 'anpa-colab-usos', text: __( 'Esta oferta non se aproba soa: ten que aprobala a xunta.', 'anpa-socios' ) }));
		}
		// Uses applied at the till (counts only, never who).
		if (o.estado === 'aprobada' || (parseInt(o.usos, 10) || 0) > 0) {
			li.appendChild(el('p', {
				class: 'anpa-colab-usos',
				/* translators: 1: uses in total, 2: uses in the last 30 days */
				text: sprintf( __( 'Usos: %1$s en total · %2$s nos últimos 30 días', 'anpa-socios' ), String(parseInt(o.usos, 10) || 0), String(parseInt(o.usos_mes, 10) || 0) ),
			}));
		}
		if (o.motivo) {
			li.appendChild(el('p', { class: 'anpa-colab-motivo' }, [
				el('strong', { text: __( 'Motivo:', 'anpa-socios' ) + ' ' }),
				String(o.motivo),
			]));
		}

		var accions = el('div', { class: 'anpa-colab-accions' });
		// A withdrawn offer is not edited back to life here: that would skip the
		// limit of offers, which the server only checks on creation.
		if (o.estado !== 'retirada') {
			accions.appendChild(boton(__( 'Editar', 'anpa-socios' ), 'secundario', function () {
				abrirAsistente(o);
			}, { attrs: { 'aria-label': sprintf( /* translators: %s: offer title */ __( 'Editar «%s»', 'anpa-socios' ), o.titulo || '' ) } }));
			accions.appendChild(boton(__( 'Retirar', 'anpa-socios' ), 'perigo', function () {
				retirar(o);
			}, { attrs: { 'aria-label': sprintf( /* translators: %s: offer title */ __( 'Retirar «%s»', 'anpa-socios' ), o.titulo || '' ) } }));
		}
		if (accions.firstChild) { li.appendChild(accions); }
		return li;
	}

	async function retirar(o) {
		if (busyDepth > 0) { return; }
		/* translators: %s: offer title */
		if (!window.confirm(sprintf( __( 'Seguro que queres retirar a oferta «%s»? Deixará de verse na web e de poder usarse.', 'anpa-socios' ), o.titulo || '' ))) {
			return;
		}
		var res = await api('POST', urls.ofertas + '/' + encodeURIComponent(String(parseInt(o.id, 10))) + '/retirar', {}, true);
		if (!res) { return; }
		if (!esPanel(res)) {
			showMessage(errorMessage(res), 'error');
			return;
		}
		panel = res.data;
		renderPanel(true);
		showMessage(__( 'Oferta retirada.', 'anpa-socios' ), 'success');
	}

	/* ── c3. The shop's till QR ────────────────────────────────────── */

	function seccionQr() {
		var c = panel.comercio || {};
		var caixa = panel.caixa || {};
		var sec = el('section', { class: 'anpa-colab-seccion anpa-colab-qr-comercio' });
		sec.appendChild(el('h3', { text: __( 'O QR do meu comercio', 'anpa-socios' ) }));
		sec.appendChild(el('p', { class: 'anpa-colab-aviso anpa-colab-aviso--perigo' }, [
			el('strong', { text: __( 'Este QR é a clave do teu comercio.', 'anpa-socios' ) + ' ' }),
			__( 'Con el calquera pode aplicar ofertas no teu nome. Non o poñas á vista do público (nin no mostrador nin no escaparate) e gárdao como unha chave.', 'anpa-socios' ),
		]));
		sec.appendChild(el('p', { class: 'anpa-colab-intro', text: __( 'Escanéao coa cámara do móbil ou da tableta que uses na caixa: ese dispositivo quedará como caixa do teu comercio durante 12 horas. Despois só tes que escanear o carné de cada familia.', 'anpa-socios' ) }));

		var canvas = canvasQr(caixa.qr || '', [c.nome || '', __( 'QR de caixa · uso interno, non o expoñas', 'anpa-socios' )]);
		if (!canvas) {
			sec.appendChild(el('p', {
				class: 'anpa-colab-aviso',
				text: caixa.qr
					? __( 'Non se puido debuxar o QR neste navegador.', 'anpa-socios' )
					: __( 'Aínda non hai páxina de caixa na web. Avisa á xunta directiva.', 'anpa-socios' ),
			}));
		} else {
			canvas.setAttribute('role', 'img');
			canvas.setAttribute('aria-label', __( 'QR de caixa do teu comercio', 'anpa-socios' ));
			var marco = el('div', { class: 'anpa-colab-qr-marco' }, [canvas]);
			sec.appendChild(marco);
			sec.appendChild(el('div', { class: 'anpa-colab-accions' }, [
				boton(__( 'Imprimir', 'anpa-socios' ), 'secundario', function () { imprimirSo(marco); }),
				boton(__( 'Descargar', 'anpa-socios' ), 'secundario', function () { descargarCanvas(canvas, 'qr-caixa-comercio.png'); }),
				boton(__( 'Renovar QR', 'anpa-socios' ), 'perigo', renovarQr),
			]));
		}
		if (caixa.clave) {
			var clave = String(caixa.clave).replace(/(.{4})(?=.)/g, '$1-');
			sec.appendChild(el('details', { class: 'anpa-colab-clave' }, [
				el('summary', { text: __( 'Ver a clave en texto (se non podes escanear)', 'anpa-socios' ) }),
				el('p', { class: 'anpa-colab-axuda', text: __( 'Escríbea na páxina de caixa. É secreta coma o QR.', 'anpa-socios' ) }),
				el('p', { class: 'anpa-colab-clave-texto', text: clave }),
			]));
		}
		var paxina = urlSegura(caixa.url || root.dataset.caixaPaxina || '');
		if (paxina) {
			sec.appendChild(el('p', {}, [
				el('a', { href: paxina, class: 'anpa-colab-ligazon', text: __( 'Abrir a páxina de caixa', 'anpa-socios' ) }),
			]));
		}
		return sec;
	}

	async function renovarQr() {
		if (busyDepth > 0) { return; }
		if (!window.confirm(__( 'Se renovas o QR, o actual deixa de funcionar e terás que escanear o novo en todos os dispositivos da caixa. Renovalo agora?', 'anpa-socios' ))) {
			return;
		}
		var res = await api('POST', urls.qr, {}, true);
		if (!res) { return; }
		if (!esPanel(res)) {
			showMessage(errorMessage(res), 'error');
			return;
		}
		panel = res.data;
		renderPanel(true);
		showMessage(__( 'QR renovado. O anterior xa non funciona: escanea o novo nos dispositivos da caixa.', 'anpa-socios' ), 'success');
	}

	/* ── d. Guided offer form ──────────────────────────────────────── */

	function abrirAsistente(o) {
		showMessage('', 'info');
		var usos = o ? parseInt(o.usos_familia, 10) : 1;
		asistente = {
			id: o ? parseInt(o.id, 10) : 0,
			estado: o ? o.estado : '',
			paso: 1,
			erros: {},
			imaxeActual: o ? (o.imaxe_url || '') : '',
			imaxeNova: null,
			quitarImaxe: false,
			v: {
				titulo: o ? (o.titulo || '') : '',
				desconto: o ? (o.desconto || '') : '',
				descricion: o ? (o.descricion || '') : '',
				condicions: o ? (o.condicions || '') : '',
				valida_dende: o && !dataBaleira(o.valida_dende) ? o.valida_dende : '',
				valida_ata: o && !dataBaleira(o.valida_ata) ? o.valida_ata : '',
				acumulable: !!(o && o.acumulable),
				usos_familia: isNaN(usos) || usos < 0 ? 1 : usos,
			},
		};
		renderAsistente();
	}

	function valores() {
		var v = asistente.v;
		return {
			titulo: linha(v.titulo),
			desconto: linha(v.desconto),
			descricion: parrafo(v.descricion),
			condicions: parrafo(v.condicions),
			valida_dende: String(v.valida_dende || '').trim(),
			valida_ata: String(v.valida_ata || '').trim(),
			acumulable: !!v.acumulable,
			usos_familia: parseInt(v.usos_familia, 10) || 0,
		};
	}

	/** Same rules as ANPA_Socios_Colab::limpar_oferta(), for one step (or all). */
	function validar(paso) {
		var d = valores();
		var e = {};
		var todos = !paso;
		var maxD = maxDescricion();
		if (todos || paso === 1) {
			var nt = lonxitude(d.titulo);
			if (nt < 5) {
				e.titulo = __( 'Escribe un título de polo menos 5 caracteres.', 'anpa-socios' );
			} else if (nt > LIMITES.titulo) {
				e.titulo = __( 'O título pode ter como moito 80 caracteres.', 'anpa-socios' );
			}
			if (d.desconto === '') {
				e.desconto = __( 'Indica o desconto ou a vantaxe (por exemplo «10 %» ou «2x1»).', 'anpa-socios' );
			} else if (lonxitude(d.desconto) > LIMITES.desconto) {
				e.desconto = __( 'O desconto pode ter como moito 60 caracteres.', 'anpa-socios' );
			}
		}
		if (todos || paso === 2) {
			var nd = lonxitude(d.descricion);
			if (nd < 10) {
				e.descricion = __( 'Describe a oferta (polo menos 10 caracteres).', 'anpa-socios' );
			} else if (nd > maxD) {
				/* translators: %d: maximum characters */
				e.descricion = sprintf( __( 'A descrición pode ter como moito %d caracteres.', 'anpa-socios' ), maxD );
			}
			if (lonxitude(d.condicions) > LIMITES.condicions) {
				e.condicions = __( 'As condicións poden ter como moito 400 caracteres.', 'anpa-socios' );
			}
		}
		if (todos || paso === 3) {
			['valida_dende', 'valida_ata'].forEach(function (k) {
				if (d[k] !== '' && !dataValida(d[k])) {
					e[k] = __( 'A data non é válida.', 'anpa-socios' );
				}
			});
			if (!e.valida_dende && !e.valida_ata && d.valida_dende && d.valida_ata && d.valida_ata < d.valida_dende) {
				e.valida_ata = __( 'A data final non pode ser anterior á inicial.', 'anpa-socios' );
			}
			if (d.usos_familia < 0 || d.usos_familia > 99) {
				e.usos_familia = __( 'Os usos por familia van de 1 a 99 (ou ilimitados).', 'anpa-socios' );
			}
		}
		return e;
	}

	function nomesPasos() {
		return [
			__( 'Que ofreces', 'anpa-socios' ),
			__( 'Detalles', 'anpa-socios' ),
			__( 'Datas e usos', 'anpa-socios' ),
			__( 'Imaxe', 'anpa-socios' ),
			__( 'Revisar e enviar', 'anpa-socios' ),
		];
	}

	function progreso(paso) {
		var ol = el('ol', { class: 'anpa-colab-pasos' });
		nomesPasos().forEach(function (n, i) {
			var num = i + 1;
			var estado = num < paso ? 'feito' : (num === paso ? 'actual' : 'pendente');
			var li = el('li', { class: 'anpa-colab-paso anpa-colab-paso--' + estado }, [
				el('span', { class: 'anpa-colab-paso-num', text: String(num), attrs: { 'aria-hidden': 'true' } }),
				el('span', { class: 'anpa-colab-paso-nome', text: n }),
			]);
			if (num === paso) { li.setAttribute('aria-current', 'step'); }
			ol.appendChild(li);
		});
		var enchido = el('span', { class: 'anpa-colab-progreso-enchido' });
		enchido.style.width = Math.round(paso / TOTAL_PASOS * 100) + '%';
		var barra = el('div', { class: 'anpa-colab-progreso-barra', attrs: { 'aria-hidden': 'true' } }, [enchido]);
		/* translators: 1: current step, 2: total steps */
		var texto = el('p', { class: 'anpa-colab-progreso-texto', text: sprintf( __( 'Paso %1$d de %2$d', 'anpa-socios' ), paso, TOTAL_PASOS ) });
		return el('div', { class: 'anpa-colab-progreso' }, [texto, barra, ol]);
	}

	/** Image the offer will show: new local file, current one, or the shop logo. */
	function imaxeDaOferta() {
		var a = asistente;
		if (a.imaxeNova) { return { src: urlLocal(a.imaxeNova), local: true }; }
		if (a.imaxeActual && !a.quitarImaxe) { return { src: urlSegura(a.imaxeActual), local: false }; }
		return { src: urlSegura((panel && panel.comercio && panel.comercio.logo_url) || ''), local: false, logo: true };
	}

	function renderAsistente(focusField) {
		var a = asistente;
		limparVista();
		var editando = a.id > 0;
		var titulo = el('h2', { class: 'anpa-colab-asistente-titulo', text: editando ? __( 'Editar oferta', 'anpa-socios' ) : __( 'Nova oferta', 'anpa-socios' ) });
		var h = cabeceira('h3', nomesPasos()[a.paso - 1]);
		var corpo = el('div', { class: 'anpa-colab-paso-corpo' });
		var campos = {};

		function engadirCampo(opts) {
			opts.value = a.v[opts.name];
			var c = campo(opts);
			c.input.addEventListener('input', function () {
				a.v[opts.name] = c.input.value;
				if (a.erros[opts.name]) {
					delete a.erros[opts.name];
					marcarErro(c, '');
				}
			});
			if (a.erros[opts.name]) { marcarErro(c, a.erros[opts.name]); }
			campos[opts.name] = c;
			corpo.appendChild(c.wrap);
			return c;
		}

		if (a.paso === 1) {
			corpo.appendChild(el('p', { class: 'anpa-colab-intro', text: __( 'Conta en poucas palabras que lles ofreces ás familias socias.', 'anpa-socios' ) }));
			engadirCampo({
				name: 'titulo',
				label: __( 'Título da oferta', 'anpa-socios' ),
				hint: __( 'Entre 5 e 80 caracteres.', 'anpa-socios' ),
				placeholder: __( 'Por exemplo: 10 % en material escolar', 'anpa-socios' ),
				max: LIMITES.titulo,
				min: 5,
			});
			engadirCampo({
				name: 'desconto',
				label: __( 'Desconto ou vantaxe', 'anpa-socios' ),
				hint: __( 'O que se verá destacado: unha cifra ou unha vantaxe curta.', 'anpa-socios' ),
				placeholder: __( 'Por exemplo: 10 % ou 2x1', 'anpa-socios' ),
				max: LIMITES.desconto,
			});
		} else if (a.paso === 2) {
			var maxD = maxDescricion();
			engadirCampo({
				name: 'descricion',
				label: __( 'Descrición curta', 'anpa-socios' ),
				/* translators: %d: maximum characters */
				hint: sprintf( __( 'Que se ofrece, en poucas liñas (entre 10 e %d caracteres).', 'anpa-socios' ), maxD ),
				multiline: true,
				rows: 4,
				max: maxD,
				min: 10,
			});
			engadirCampo({
				name: 'condicions',
				label: __( 'Condicións', 'anpa-socios' ),
				optional: true,
				hint: __( 'Exclusións, se hai que avisar antes, compra mínima…', 'anpa-socios' ),
				multiline: true,
				rows: 3,
				max: LIMITES.condicions,
			});
		} else if (a.paso === 3) {
			corpo.appendChild(el('p', { class: 'anpa-colab-aviso anpa-colab-aviso--info', text: __( 'As datas son opcionais. Sen datas, a oferta non ten fin ata que a retires.', 'anpa-socios' ) }));
			var cDende = engadirCampo({
				name: 'valida_dende',
				type: 'date',
				label: __( 'Válida desde', 'anpa-socios' ),
				optional: true,
				hint: __( 'Baleiro: desde que se publique.', 'anpa-socios' ),
				placeholder: 'AAAA-MM-DD',
			});
			var cAta = engadirCampo({
				name: 'valida_ata',
				type: 'date',
				label: __( 'Válida ata', 'anpa-socios' ),
				optional: true,
				hint: __( 'Baleiro: sen data de fin.', 'anpa-socios' ),
				placeholder: 'AAAA-MM-DD',
			});
			var porMin = function () {
				if (dataValida(cDende.input.value)) { cAta.input.min = cDende.input.value; } else { cAta.input.removeAttribute('min'); }
			};
			cDende.input.addEventListener('change', porMin);
			porMin();

			var cAcum = radios({
				name: 'acumulable',
				label: __( 'Acumulable con outras ofertas', 'anpa-socios' ),
				hint: __( 'Se se pode xuntar con outras promocións ou descontos do teu comercio.', 'anpa-socios' ),
				value: a.v.acumulable ? '1' : '0',
				opcions: [
					{ valor: '0', texto: __( 'Non', 'anpa-socios' ) },
					{ valor: '1', texto: __( 'Si', 'anpa-socios' ) },
				],
				onChange: function (v) { a.v.acumulable = v === '1'; },
			});
			campos.acumulable = cAcum;
			corpo.appendChild(cAcum.wrap);

			var opcions = USOS_OPCIONS.slice();
			if (opcions.indexOf(a.v.usos_familia) < 0) { opcions.splice(opcions.length - 1, 0, a.v.usos_familia); }
			var cUsos = radios({
				name: 'usos_familia',
				label: __( 'Cantas veces pode usala cada familia?', 'anpa-socios' ),
				hint: __( 'Na caixa réstase un uso cada vez que a aplicas.', 'anpa-socios' ),
				value: String(a.v.usos_familia),
				opcions: opcions.map(function (n) {
					var texto;
					if (n === 0) {
						texto = __( 'Ilimitado', 'anpa-socios' );
					} else if (n === 1) {
						texto = __( '1 vez', 'anpa-socios' );
					} else {
						/* translators: %d: number of uses */
						texto = sprintf( __( '%d veces', 'anpa-socios' ), n );
					}
					return { valor: String(n), texto: texto };
				}),
				onChange: function (v) {
					a.v.usos_familia = parseInt(v, 10) || 0;
					if (a.erros.usos_familia) { delete a.erros.usos_familia; marcarErro(cUsos, ''); }
				},
			});
			if (a.erros.usos_familia) { marcarErro(cUsos, a.erros.usos_familia); }
			campos.usos_familia = cUsos;
			corpo.appendChild(cUsos.wrap);
		} else if (a.paso === 4) {
			var temLogo = !!urlSegura((panel && panel.comercio && panel.comercio.logo_url) || '');
			var actual = a.imaxeActual && !a.quitarImaxe ? a.imaxeActual : '';
			var cImaxe = selectorImaxe({
				name: 'imaxe',
				label: __( 'Imaxe da oferta', 'anpa-socios' ),
				optional: true,
				hint: __( 'Unha foto pequena do produto ou servizo. JPG, PNG ou WebP, ata 2 MB.', 'anpa-socios' ),
				actual: actual,
				alt: __( 'Imaxe da oferta', 'anpa-socios' ),
				textoActual: __( 'Imaxe actual.', 'anpa-socios' ),
				textoSen: temLogo
					? __( 'Se non engades imaxe, usarase o logo do teu comercio.', 'anpa-socios' )
					: __( 'Se non engades imaxe, usarase o logo do teu comercio (cando o teñas).', 'anpa-socios' ),
				onChange: function (f) {
					a.imaxeNova = f;
					if (f) { a.quitarImaxe = false; }
				},
			});
			if (a.imaxeNova) {
				cImaxe.amosar(urlLocal(a.imaxeNova), __( 'Imaxe nova (aínda sen enviar).', 'anpa-socios' ));
			}
			campos.imaxe = cImaxe;
			corpo.appendChild(cImaxe.wrap);
			if (a.imaxeActual || a.imaxeNova) {
				corpo.appendChild(el('div', { class: 'anpa-colab-accions' }, [
					boton(__( 'Quitar a imaxe', 'anpa-socios' ), 'ligazon', function () {
						a.imaxeNova = null;
						a.quitarImaxe = !!a.imaxeActual;
						renderAsistente('imaxe');
					}),
				]));
			}
		} else {
			corpo.appendChild(resumo(editando));
		}

		// Navigation.
		var nav = el('div', { class: 'anpa-colab-accions anpa-colab-accions--asistente' });
		if (a.paso > 1) {
			nav.appendChild(boton(__( 'Anterior', 'anpa-socios' ), 'secundario', function () {
				a.paso -= 1;
				renderAsistente();
			}));
		}
		if (a.paso < TOTAL_PASOS) {
			nav.appendChild(boton(__( 'Seguinte', 'anpa-socios' ), 'primario', function () {
				var e = validar(a.paso);
				if (Object.keys(e).length) {
					a.erros = Object.assign({}, a.erros, e);
					renderAsistente(PASOS[a.paso].filter(function (k) { return e[k]; })[0]);
					showMessage(__( 'Revisa os campos marcados.', 'anpa-socios' ), 'error');
					return;
				}
				PASOS[a.paso].forEach(function (k) { delete a.erros[k]; });
				showMessage('', 'info');
				a.paso += 1;
				renderAsistente();
			}));
		} else {
			nav.appendChild(boton(editando ? __( 'Gardar os cambios', 'anpa-socios' ) : __( 'Enviar a oferta', 'anpa-socios' ), 'primario', enviarOferta));
		}
		nav.appendChild(boton(__( 'Cancelar', 'anpa-socios' ), 'ligazon', function () {
			showMessage('', 'info');
			renderPanel(true);
		}));

		var form = el('form', { class: 'anpa-colab-form', noValidate: true }, [corpo, nav]);
		form.addEventListener('submit', function (ev) { ev.preventDefault(); });

		vista.appendChild(el('section', { class: 'anpa-colab-asistente' }, [titulo, progreso(a.paso), h, form]));

		if (focusField && campos[focusField]) {
			enfocar(campos[focusField].input);
		} else {
			enfocar(h);
		}
	}

	/** Last step: the offer as families will see it, plus what happens next. */
	function resumo(editando) {
		var d = valores();
		var wrap = el('div', { class: 'anpa-colab-resumo' });
		wrap.appendChild(el('p', { class: 'anpa-colab-intro', text: __( 'Así verán a oferta as familias socias:', 'anpa-socios' ) }));

		var im = imaxeDaOferta();
		var oferta = el('li', { class: 'anpa-colab-oferta' }, [
			im.src ? el('img', { class: 'anpa-colab-oferta-imaxe', src: im.src, alt: '' }) : null,
			el('span', { class: 'anpa-colab-desconto', text: d.desconto }),
			el('strong', { class: 'anpa-colab-titulo', text: d.titulo }),
			el('p', { class: 'anpa-colab-texto', text: d.descricion }),
		]);
		if (d.condicions) {
			oferta.appendChild(el('p', { class: 'anpa-colab-condicions', text: d.condicions }));
		}
		oferta.appendChild(el('p', { class: 'anpa-colab-etiquetas' }, [
			el('span', { class: 'anpa-colab-etiqueta' + (d.acumulable ? ' anpa-colab-etiqueta--si' : ''), text: textoAcumulable(d.acumulable) }),
			' ',
			el('span', { class: 'anpa-colab-etiqueta', text: textoUsosFamilia(d.usos_familia) }),
		]));
		if (d.valida_ata) {
			/* translators: %s: date */
			oferta.appendChild(el('p', { class: 'anpa-colab-vixencia', text: sprintf( __( 'Válida ata o %s', 'anpa-socios' ), formatarData(d.valida_ata) ) }));
		}
		var nome = (panel && panel.comercio && panel.comercio.nome) || '';
		wrap.appendChild(el('div', { class: 'anpa-colab-vista-previa', attrs: { role: 'group', 'aria-label': __( 'Vista previa', 'anpa-socios' ) } }, [
			nome ? el('p', { class: 'anpa-colab-vista-previa-comercio', text: nome }) : null,
			el('ul', { class: 'anpa-colab-ofertas' }, [oferta]),
		]));

		var dl = el('dl', { class: 'anpa-colab-ficha' }, [
			el('dt', { text: __( 'Vixencia', 'anpa-socios' ) }),
			el('dd', { text: textoVixencia(d.valida_dende, d.valida_ata) }),
			el('dt', { text: __( 'Imaxe', 'anpa-socios' ) }),
			el('dd', { text: im.logo ? __( 'Logo do comercio', 'anpa-socios' ) : (im.local ? __( 'Imaxe nova', 'anpa-socios' ) : __( 'Imaxe actual', 'anpa-socios' )) }),
		]);
		wrap.appendChild(dl);

		var horas = horasAprobacion();
		var envio = panel && panel.seguinte_envio ? String(panel.seguinte_envio) : '';
		wrap.appendChild(el('p', {
			class: 'anpa-colab-aviso anpa-colab-aviso--info',
			text: horas > 0
				/* translators: %d: hours */
				? sprintf( __( 'A xunta directiva revisará a oferta. Se ninguén a revisa, aprobarase soa en %d horas.', 'anpa-socios' ), horas )
				: __( 'A oferta aprobarase cando a xunta directiva a revise.', 'anpa-socios' ),
		}));
		if (envio) {
			wrap.appendChild(el('p', {
				class: 'anpa-colab-axuda',
				/* translators: %s: «Sae o xoves 3 ás 16:00» */
				text: sprintf( __( 'Unha vez aprobada, publícase co seguinte envío semanal ás familias (%s).', 'anpa-socios' ), envio ),
			}));
		}
		if (editando && asistente.estado === 'aprobada') {
			wrap.appendChild(el('p', {
				class: 'anpa-colab-aviso',
				text: __( 'Ao gardar os cambios, a oferta deixa de estar publicada ata que se aprobe de novo.', 'anpa-socios' ),
			}));
		}
		return wrap;
	}

	async function enviarOferta() {
		if (busyDepth > 0) { return; }
		var a = asistente;
		var e = validar(0);
		if (Object.keys(e).length) {
			irAoErro(e);
			showMessage(__( 'Revisa os campos marcados.', 'anpa-socios' ), 'error');
			return;
		}
		var editando = a.id > 0;
		var url = editando ? urls.ofertas + '/' + encodeURIComponent(String(a.id)) : urls.ofertas;
		var res = await api(editando ? 'PUT' : 'POST', url, valores(), true);
		if (!res) { return; }
		if (!res.ok) {
			var fields = res.data && res.data.data && res.data.data.fields;
			if (res.status === 400 && fields && typeof fields === 'object' && asistente === a) {
				var e2 = {};
				Object.keys(fields).forEach(function (k) {
					if (typeof fields[k] === 'string') { e2[k] = fields[k]; }
				});
				if (Object.keys(e2).length) { irAoErro(e2); }
			}
			showMessage(errorMessage(res), 'error');
			return;
		}
		if (esPanel(res)) { panel = res.data; }
		var id = editando ? a.id : parseInt(res.data && res.data.gardada, 10);
		var erroImx = '';
		if (id > 0 && a.imaxeNova) {
			var r2 = await subirImaxe(urls.ofertas + '/' + encodeURIComponent(String(id)) + '/imaxe', 'imaxe', a.imaxeNova);
			if (!r2) { return; }
			if (esPanel(r2)) { panel = r2.data; } else { erroImx = errorMessage(r2); }
		} else if (id > 0 && a.quitarImaxe && a.imaxeActual) {
			var r3 = await api('DELETE', urls.ofertas + '/' + encodeURIComponent(String(id)) + '/imaxe', undefined, true);
			if (!r3) { return; }
			if (esPanel(r3)) { panel = r3.data; } else { erroImx = errorMessage(r3); }
		}
		if (!esPanel(res)) {
			cargarPanel(true, __( 'Oferta gardada.', 'anpa-socios' ));
			return;
		}
		renderPanel(true);
		if (erroImx) {
			/* translators: %s: server error */
			showMessage(sprintf( __( 'A oferta gardouse, pero a imaxe non: %s', 'anpa-socios' ), erroImx ), 'error');
			return;
		}
		showMessage(editando
			? __( 'Cambios gardados. A xunta directiva revisará a oferta.', 'anpa-socios' )
			: __( 'Oferta enviada. A xunta directiva revisaraa en breve.', 'anpa-socios' ), 'success');
	}

	/** Jumps to the first step with an error and marks the fields. */
	function irAoErro(erros) {
		var a = asistente;
		var primeiro = 0;
		var campoErro = '';
		for (var p = 1; p <= TOTAL_PASOS && !primeiro; p++) {
			for (var i = 0; i < PASOS[p].length; i++) {
				if (erros[PASOS[p][i]]) {
					primeiro = p;
					campoErro = PASOS[p][i];
					break;
				}
			}
		}
		a.erros = erros;
		if (primeiro) {
			a.paso = primeiro;
			renderAsistente(campoErro);
		}
	}

	/* ── Start ─────────────────────────────────────────────────────── */

	if (loadToken()) {
		cargarPanel(false, '', true);
	} else {
		renderEntrar(false);
	}
})();
