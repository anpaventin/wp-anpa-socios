/**
 * Collaborators network (1.104.0): the shop's own panel.
 *
 * Sign in with email + code, see the shop's data and offers, and create or
 * edit offers with a four-step guided form. The shop session token travels
 * only in the X-Anpa-Colab-Token header and is kept in sessionStorage.
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
	var LIMITES = { titulo: 80, desconto: 60, descricion: 600, condicions: 400 };
	var PASOS = {
		1: ['titulo', 'desconto'],
		2: ['descricion', 'condicions'],
		3: ['valida_dende', 'valida_ata'],
		4: [],
	};
	var TOTAL_PASOS = 4;

	var root = document.getElementById('anpa-colab-portal');
	if (!root) {
		return;
	}

	var urls = {
		codigo: root.dataset.codigoUrl || '',
		entrar: root.dataset.entrarUrl || '',
		me: root.dataset.meUrl || '',
		sair: root.dataset.sairUrl || '',
		ofertas: (root.dataset.ofertasUrl || '').replace(/\/+$/, ''),
	};
	var contacto = root.dataset.contacto || '';
	var memToken = null; // Fallback when sessionStorage is not available.
	var panel = null;    // Last /colab/me answer.
	var asistente = null; // Wizard state.
	var busyDepth = 0;

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

	function formatarData(s) {
		if (!dataValida(s)) { return s; }
		var meses = [
			__( 'xaneiro', 'anpa-socios' ), __( 'febreiro', 'anpa-socios' ), __( 'marzo', 'anpa-socios' ),
			__( 'abril', 'anpa-socios' ), __( 'maio', 'anpa-socios' ), __( 'xuño', 'anpa-socios' ),
			__( 'xullo', 'anpa-socios' ), __( 'agosto', 'anpa-socios' ), __( 'setembro', 'anpa-socios' ),
			__( 'outubro', 'anpa-socios' ), __( 'novembro', 'anpa-socios' ), __( 'decembro', 'anpa-socios' ),
		];
		var p = s.split('-');
		/* translators: 1: day, 2: month name, 3: year */
		return sprintf( __( '%1$d de %2$s de %3$s', 'anpa-socios' ), parseInt(p[2], 10), meses[parseInt(p[1], 10) - 1], p[0] );
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
	 * Sends a JSON request. Returns {ok, status, data}, or null when the
	 * request failed in a way already reported (network, expired session).
	 */
	async function api(method, url, body, auth) {
		var headers = { Accept: 'application/json' };
		if (body !== undefined) { headers['Content-Type'] = 'application/json'; }
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
					body: body !== undefined ? JSON.stringify(body) : undefined,
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

	function sessionEnded(msg) {
		clearToken();
		panel = null;
		asistente = null;
		renderEntrar(true);
		showMessage(msg || __( 'A sesión rematou. Entra de novo.', 'anpa-socios' ), 'error');
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
		if (opts.max) {
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
			cargarPanel(true);
		});

		vista.appendChild(el('section', { class: 'anpa-colab-tarxeta-paso' }, [h, intro, form]));
		enfocar(cCod.input);
	}

	/* ── c. Panel ──────────────────────────────────────────────────── */

	async function cargarPanel(focus, msg) {
		var res = await api('GET', urls.me, undefined, true);
		if (!res) { return; }
		if (!res.ok || !res.data || !res.data.comercio) {
			if (!panel) { renderEntrar(focus); }
			showMessage(errorMessage(res), 'error');
			return;
		}
		panel = res.data;
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
		return __( 'Sen data de fin', 'anpa-socios' );
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

		// Shop data (read-only).
		var dl = el('dl', { class: 'anpa-colab-ficha' });
		function fila(etiqueta, valor) {
			if (!valor) { return; }
			dl.appendChild(el('dt', { text: etiqueta }));
			dl.appendChild(el('dd', {}, [valor]));
		}
		fila(__( 'Correo', 'anpa-socios' ), c.email || '');
		fila(__( 'Persoa responsable', 'anpa-socios' ), c.responsable || '');
		fila(__( 'Teléfono', 'anpa-socios' ), c.telefono || '');
		if (c.url_web) {
			var web = urlSegura(c.url_web);
			fila(__( 'Web', 'anpa-socios' ), web
				? el('a', { href: web, text: c.url_web, attrs: { rel: 'noopener nofollow', target: '_blank' } })
				: String(c.url_web));
		}
		fila(__( 'Enderezo', 'anpa-socios' ), c.enderezo || '');
		var notaDatos = el('p', {
			class: 'anpa-colab-nota-datos',
			text: contacto
				/* translators: %s: ANPA contact email */
				? sprintf( __( 'Os datos do comercio cámbiaos a ANPA. Se hai que corrixir algo, escribe a %s.', 'anpa-socios' ), contacto )
				: __( 'Os datos do comercio cámbiaos a ANPA. Se hai que corrixir algo, avisa á xunta directiva.', 'anpa-socios' ),
		});
		var seccionDatos = el('section', { class: 'anpa-colab-seccion' }, [
			el('h3', { text: __( 'Datos do comercio', 'anpa-socios' ) }),
			dl,
			notaDatos,
		]);

		// Offers.
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

		var seccionOfertas = el('section', { class: 'anpa-colab-seccion' }, [cabOfertas, conta, explicacion, lista]);

		vista.appendChild(el('div', { class: 'anpa-colab-panel' }, [topo, seccionDatos, seccionOfertas]));
		if (focus) { enfocar(h); }
	}

	function tarxetaOferta(o) {
		var estado = ESTADOS.indexOf(o.estado) >= 0 ? o.estado : 'outro';
		var li = el('li', { class: 'anpa-colab-tarxeta anpa-colab-tarxeta--' + estado });
		li.appendChild(el('span', { class: 'anpa-colab-chip anpa-colab-chip--' + estado, text: o.estado_texto || o.estado || '' }));
		li.appendChild(el('h4', { class: 'anpa-colab-tarxeta-titulo', text: o.titulo || '' }));
		if (o.desconto) {
			li.appendChild(el('p', {}, [el('span', { class: 'anpa-colab-desconto', text: o.desconto })]));
		}
		if (o.descricion) {
			li.appendChild(el('p', { class: 'anpa-colab-texto', text: o.descricion }));
		}
		if (o.condicions) {
			li.appendChild(el('p', { class: 'anpa-colab-condicions', text: o.condicions }));
		}
		li.appendChild(el('p', { class: 'anpa-colab-vixencia', text: textoVixencia(o.valida_dende, o.valida_ata) }));
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
		if (!window.confirm(sprintf( __( 'Seguro que queres retirar a oferta «%s»? Deixará de verse na web.', 'anpa-socios' ), o.titulo || '' ))) {
			return;
		}
		var res = await api('POST', urls.ofertas + '/' + encodeURIComponent(String(parseInt(o.id, 10))) + '/retirar', {}, true);
		if (!res) { return; }
		if (!res.ok || !res.data || !res.data.comercio) {
			showMessage(errorMessage(res), 'error');
			return;
		}
		panel = res.data;
		renderPanel(true);
		showMessage(__( 'Oferta retirada.', 'anpa-socios' ), 'success');
	}

	/* ── d. Guided form ────────────────────────────────────────────── */

	function abrirAsistente(o) {
		showMessage('', 'info');
		asistente = {
			id: o ? parseInt(o.id, 10) : 0,
			estado: o ? o.estado : '',
			paso: 1,
			erros: {},
			v: {
				titulo: o ? (o.titulo || '') : '',
				desconto: o ? (o.desconto || '') : '',
				descricion: o ? (o.descricion || '') : '',
				condicions: o ? (o.condicions || '') : '',
				valida_dende: o && !dataBaleira(o.valida_dende) ? o.valida_dende : '',
				valida_ata: o && !dataBaleira(o.valida_ata) ? o.valida_ata : '',
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
		};
	}

	/** Same rules as ANPA_Socios_Colab::limpar_oferta(), for one step (or all). */
	function validar(paso) {
		var d = valores();
		var e = {};
		var todos = !paso;
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
			} else if (nd > LIMITES.descricion) {
				e.descricion = __( 'A descrición pode ter como moito 600 caracteres.', 'anpa-socios' );
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
		}
		return e;
	}

	function progreso(paso) {
		var nomes = [
			__( 'Que ofreces', 'anpa-socios' ),
			__( 'Detalles', 'anpa-socios' ),
			__( 'Vixencia', 'anpa-socios' ),
			__( 'Revisar e enviar', 'anpa-socios' ),
		];
		var ol = el('ol', { class: 'anpa-colab-pasos' });
		nomes.forEach(function (n, i) {
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

	function renderAsistente(focusField) {
		var a = asistente;
		limparVista();
		var editando = a.id > 0;
		var titulo = el('h2', { class: 'anpa-colab-asistente-titulo', text: editando ? __( 'Editar oferta', 'anpa-socios' ) : __( 'Nova oferta', 'anpa-socios' ) });
		var nomesPasos = {
			1: __( 'Que ofreces', 'anpa-socios' ),
			2: __( 'Detalles', 'anpa-socios' ),
			3: __( 'Vixencia', 'anpa-socios' ),
			4: __( 'Revisar e enviar', 'anpa-socios' ),
		};
		var h = cabeceira('h3', nomesPasos[a.paso]);
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
			engadirCampo({
				name: 'descricion',
				label: __( 'Descrición', 'anpa-socios' ),
				hint: __( 'Explica a oferta (entre 10 e 600 caracteres).', 'anpa-socios' ),
				multiline: true,
				rows: 5,
				max: LIMITES.descricion,
				min: 10,
			});
			engadirCampo({
				name: 'condicions',
				label: __( 'Condicións', 'anpa-socios' ),
				optional: true,
				hint: __( 'Quen pode usala, exclusións, se hai que avisar antes…', 'anpa-socios' ),
				multiline: true,
				rows: 4,
				max: LIMITES.condicions,
			});
		} else if (a.paso === 3) {
			corpo.appendChild(el('p', { class: 'anpa-colab-intro', text: __( 'As dúas datas son opcionais. Se deixas a data final baleira, a oferta non caduca.', 'anpa-socios' ) }));
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

	/** Step 4: the offer as families will see it, plus what happens next. */
	function resumo(editando) {
		var d = valores();
		var wrap = el('div', { class: 'anpa-colab-resumo' });
		wrap.appendChild(el('p', { class: 'anpa-colab-intro', text: __( 'Así verán a oferta as familias socias:', 'anpa-socios' ) }));

		var oferta = el('li', { class: 'anpa-colab-oferta' }, [
			el('span', { class: 'anpa-colab-desconto', text: d.desconto }),
			el('strong', { class: 'anpa-colab-titulo', text: d.titulo }),
			el('p', { class: 'anpa-colab-texto', text: d.descricion }),
		]);
		if (d.condicions) {
			oferta.appendChild(el('p', { class: 'anpa-colab-condicions', text: d.condicions }));
		}
		if (d.valida_ata) {
			/* translators: %s: date */
			oferta.appendChild(el('p', { class: 'anpa-colab-vixencia', text: sprintf( __( 'Válida ata o %s', 'anpa-socios' ), formatarData(d.valida_ata) ) }));
		}
		var nome = (panel && panel.comercio && panel.comercio.nome) || '';
		wrap.appendChild(el('div', { class: 'anpa-colab-vista-previa', attrs: { 'aria-label': __( 'Vista previa', 'anpa-socios' ) } }, [
			nome ? el('p', { class: 'anpa-colab-vista-previa-comercio', text: nome }) : null,
			el('ul', { class: 'anpa-colab-ofertas' }, [oferta]),
		]));

		var dl = el('dl', { class: 'anpa-colab-ficha' }, [
			el('dt', { text: __( 'Vixencia', 'anpa-socios' ) }),
			el('dd', { text: textoVixencia(d.valida_dende, d.valida_ata) }),
		]);
		wrap.appendChild(dl);

		var horas = parseInt(panel && panel.horas_aprobacion, 10);
		wrap.appendChild(el('p', {
			class: 'anpa-colab-aviso anpa-colab-aviso--info',
			text: horas > 0
				/* translators: %d: hours */
				? sprintf( __( 'A xunta directiva revisará a oferta. Se ninguén a revisa, publicarase soa en %d horas.', 'anpa-socios' ), horas )
				: __( 'A oferta publicarase cando a xunta directiva a aprobe.', 'anpa-socios' ),
		}));
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
		if (!res.data || !res.data.comercio) {
			cargarPanel(true, __( 'Oferta gardada.', 'anpa-socios' ));
			return;
		}
		panel = res.data;
		renderPanel(true);
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
		cargarPanel(false);
	} else {
		renderEntrar(false);
	}
})();
