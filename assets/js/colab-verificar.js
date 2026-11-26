/**
 * Collaborators network (1.104.0): a shop checks a member card.
 *
 * The answer is only «valid» or «not valid» (plus the school year and the
 * time of the check). Nothing else about the member is shown or logged.
 * A code that arrives in the address (#c=…) is removed from the address bar
 * and from the history entry before it is checked.
 *
 * @since  1.104.0
 * @package ANPA_Socios
 */

(function () {
	'use strict';
	const { __, sprintf } = wp.i18n;

	var SVG_NS = 'http://www.w3.org/2000/svg';
	var LONXITUDE = 8;

	var root = document.getElementById('anpa-colab-verificar');
	if (!root) {
		return;
	}
	var url = root.dataset.verificarUrl || '';
	var ocupado = false;

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
				Object.keys(v).forEach(function (a) { node.setAttribute(a, String(v[a])); });
			} else {
				node[k] = v;
			}
		});
		(children || []).forEach(function (c) {
			if (c) { node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); }
		});
		return node;
	}

	function svg(tag, attrs) {
		var n = document.createElementNS(SVG_NS, tag);
		Object.keys(attrs).forEach(function (a) { n.setAttribute(a, attrs[a]); });
		return n;
	}

	function icona(valido) {
		var s = svg('svg', {
			class: 'anpa-colab-resultado-icona',
			viewBox: '0 0 48 48',
			width: '72',
			height: '72',
			'aria-hidden': 'true',
			focusable: 'false',
		});
		s.appendChild(svg('circle', { cx: '24', cy: '24', r: '22', fill: 'currentColor' }));
		s.appendChild(svg('path', {
			d: valido ? 'M14 25 l7 7 l13 -15' : 'M16 16 L32 32 M32 16 L16 32',
			fill: 'none',
			stroke: '#ffffff',
			'stroke-width': '5',
			'stroke-linecap': 'round',
			'stroke-linejoin': 'round',
		}));
		return s;
	}

	/** Stored form of a typed code: no spaces or hyphens, upper case. */
	function limpar(v) {
		return String(v || '').toUpperCase().replace(/[^0-9A-Z]/g, '');
	}

	/** XXXX-XXXX while typing. */
	function formatar(v) {
		var s = limpar(v).slice(0, LONXITUDE);
		return s.length > 4 ? s.slice(0, 4) + '-' + s.slice(4) : s;
	}

	/* ── Layout ────────────────────────────────────────────────────── */

	while (root.firstChild) { root.removeChild(root.firstChild); }

	var inputId = 'anpa-colab-verificar-codigo';
	var axudaId = inputId + '-axuda';
	var input = el('input', {
		type: 'text',
		id: inputId,
		name: 'codigo',
		maxLength: 9,
		className: 'anpa-colab-input-codigo',
		placeholder: 'XXXX-XXXX',
		attrs: {
			autocomplete: 'off',
			autocapitalize: 'characters',
			autocorrect: 'off',
			spellcheck: 'false',
			'aria-describedby': axudaId,
		},
	});
	var comprobar = el('button', { type: 'submit', class: 'anpa-colab-boton anpa-colab-boton--primario', text: __( 'Comprobar', 'anpa-socios' ) });
	var form = el('form', { class: 'anpa-colab-form anpa-colab-verificar-form', noValidate: true }, [
		el('div', { class: 'anpa-colab-campo' }, [
			el('label', { class: 'anpa-colab-etiqueta', attrs: { for: inputId }, text: __( 'Código do carné', 'anpa-socios' ) }),
			el('p', { class: 'anpa-colab-axuda', id: axudaId, text: __( 'Son 8 caracteres e aparecen baixo o QR do carné (por exemplo, ABCD-1234).', 'anpa-socios' ) }),
			input,
		]),
		el('div', { class: 'anpa-colab-accions' }, [comprobar]),
	]);

	var mensaxe = el('p', { class: 'anpa-colab-mensaxe', hidden: true, attrs: { 'aria-live': 'polite', 'aria-atomic': 'true' } });
	// Kept in the page (empty) so screen readers announce what is put in it.
	var resultado = el('div', { class: 'anpa-colab-resultado', attrs: { role: 'status', 'aria-atomic': 'true' } });
	var outro = el('button', {
		type: 'button',
		class: 'anpa-colab-boton anpa-colab-boton--secundario anpa-colab-outro',
		text: __( 'Comprobar outro', 'anpa-socios' ),
		hidden: true,
	});

	root.appendChild(el('h2', { class: 'anpa-colab-cabeceira', text: __( 'Comprobar un carné de socio/a', 'anpa-socios' ) }));
	root.appendChild(form);
	root.appendChild(mensaxe);
	root.appendChild(resultado);
	root.appendChild(outro);

	function showMessage(text) {
		mensaxe.textContent = text || '';
		mensaxe.hidden = !text;
		mensaxe.dataset.type = 'error';
	}

	function limparResultado() {
		while (resultado.firstChild) { resultado.removeChild(resultado.firstChild); }
		resultado.className = 'anpa-colab-resultado';
		outro.hidden = true;
	}

	function setBusy(on) {
		ocupado = on;
		comprobar.disabled = on;
		outro.disabled = on;
		root.classList.toggle('anpa-colab-app--ocupado', on);
		root.setAttribute('aria-busy', on ? 'true' : 'false');
	}

	function mostrarResultado(valido, curso, hora) {
		limparResultado();
		resultado.classList.add(valido ? 'anpa-colab-resultado--valido' : 'anpa-colab-resultado--invalido');
		var texto = el('div', { class: 'anpa-colab-resultado-texto' });
		if (valido) {
			texto.appendChild(el('p', { class: 'anpa-colab-resultado-titulo', text: __( 'Socio/a válido', 'anpa-socios' ) }));
			if (curso) {
				/* translators: %s: school year */
				texto.appendChild(el('p', { text: sprintf( __( 'Curso %s', 'anpa-socios' ), curso ) }));
			}
		} else {
			texto.appendChild(el('p', { class: 'anpa-colab-resultado-titulo', text: __( 'Carné non válido ou caducado', 'anpa-socios' ) }));
			texto.appendChild(el('p', { text: __( 'Pídelle á familia que abra de novo «O meu carné» na área de socios: o código cambia cada 10 minutos.', 'anpa-socios' ) }));
		}
		if (hora) {
			/* translators: %s: time (HH:MM) */
			texto.appendChild(el('p', { class: 'anpa-colab-resultado-hora', text: sprintf( __( 'Comprobado ás %s', 'anpa-socios' ), hora ) }));
		}
		resultado.appendChild(icona(valido));
		resultado.appendChild(texto);
		outro.hidden = false;
	}

	async function verificar() {
		if (ocupado) { return; }
		showMessage('');
		limparResultado();
		var codigo = limpar(input.value);
		if (codigo.length !== LONXITUDE) {
			input.setAttribute('aria-invalid', 'true');
			showMessage(__( 'O código ten 8 caracteres (por exemplo, ABCD-1234).', 'anpa-socios' ));
			try { input.focus(); } catch (_) {}
			return;
		}
		input.removeAttribute('aria-invalid');
		setBusy(true);
		var res;
		var data = null;
		try {
			try {
				res = await fetch(url, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
					body: JSON.stringify({ codigo: codigo }),
					credentials: 'same-origin',
				});
			} catch (_) {
				showMessage(__( 'Erro de rede. Téntao de novo.', 'anpa-socios' ));
				return;
			}
			try { data = await res.json(); } catch (_) { data = null; }
		} finally {
			setBusy(false);
		}
		if (!res.ok || !data || typeof data.valido !== 'boolean') {
			showMessage((data && typeof data.message === 'string' && data.message) || __( 'Non foi posible comprobar o carné. Téntao de novo.', 'anpa-socios' ));
			return;
		}
		mostrarResultado(
			data.valido,
			typeof data.curso === 'string' ? data.curso : '',
			typeof data.comprobado_en === 'string' ? data.comprobado_en : ''
		);
		try { outro.focus(); } catch (_) {}
	}

	input.addEventListener('input', function () {
		var f = formatar(input.value);
		if (f !== input.value) { input.value = f; }
		input.removeAttribute('aria-invalid');
	});

	form.addEventListener('submit', function (ev) {
		ev.preventDefault();
		verificar();
	});

	outro.addEventListener('click', function () {
		limparResultado();
		showMessage('');
		input.value = '';
		input.removeAttribute('aria-invalid');
		try { input.focus(); } catch (_) {}
	});

	// A code scanned from the card QR arrives as #c=<code>.
	var m = /^#c=([^&]*)$/.exec(window.location.hash || '');
	if (m) {
		var bruto = '';
		try { bruto = decodeURIComponent(m[1]); } catch (_) { bruto = m[1]; }
		try {
			window.history.replaceState(null, '', window.location.pathname + window.location.search);
		} catch (_) {
			try { window.location.hash = ''; } catch (_e) {}
		}
		input.value = formatar(bruto);
		verificar();
	}
})();
