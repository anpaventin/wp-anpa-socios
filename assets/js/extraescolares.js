/**
 * ANPA Socios — «Máis información» of the public activity cards as a floating
 * window (1.98.0).
 *
 * The cards carry the details inside <details class="anpa-extra-mais">, so the
 * information is there without JavaScript. With JavaScript (and native
 * <dialog> support) the summary opens a modal window instead: Esc, the close
 * button or a click outside close it, and focus returns to the summary.
 *
 * @since  1.98.0
 * @package ANPA_Socios
 */
(function () {
	'use strict';

	if (typeof window.HTMLDialogElement !== 'function') {
		return; // Old browsers keep the inline <details>.
	}

	var dialog = null;
	var titulo = null;
	var corpo = null;
	var orixe = null;

	function crear() {
		dialog = document.createElement('dialog');
		dialog.className = 'anpa-extra-dialog';
		dialog.setAttribute('aria-labelledby', 'anpa-extra-dialog-titulo');

		var cab = document.createElement('div');
		cab.className = 'anpa-extra-dialog-cab';
		titulo = document.createElement('h3');
		titulo.id = 'anpa-extra-dialog-titulo';
		cab.appendChild(titulo);
		var pechar = document.createElement('button');
		pechar.type = 'button';
		pechar.className = 'anpa-extra-dialog-pechar';
		pechar.setAttribute('aria-label', 'Pechar');
		pechar.textContent = '×';
		pechar.addEventListener('click', function () { dialog.close(); });
		cab.appendChild(pechar);
		dialog.appendChild(cab);

		corpo = document.createElement('div');
		corpo.className = 'anpa-extra-dialog-corpo';
		dialog.appendChild(corpo);

		// A click on the backdrop (outside the box) closes the window.
		dialog.addEventListener('click', function (ev) {
			if (ev.target === dialog) { dialog.close(); }
		});
		dialog.addEventListener('close', function () {
			document.documentElement.classList.remove('anpa-extra-dialog-aberto');
			if (orixe) { orixe.focus(); }
		});
		document.body.appendChild(dialog);
	}

	function abrir(details) {
		var fonte = details.querySelector('.anpa-extra-mais-corpo');
		if (!fonte) { return; }
		if (!dialog) { crear(); }
		orixe = details.querySelector('summary');
		titulo.textContent = fonte.getAttribute('data-titulo') || '';
		corpo.textContent = '';
		// The card's groups (timetable, state, places) first, then the details.
		var card = details.closest('.anpa-extra-card');
		var grupos = card ? card.querySelector('.anpa-extra-grupos') : null;
		var copia = fonte.cloneNode(true);
		corpo.appendChild(copia);
		if (grupos) {
			var h = document.createElement('h4');
			h.className = 'anpa-extra-dialog-subtitulo';
			h.textContent = 'Grupos e horario';
			corpo.appendChild(h);
			corpo.appendChild(grupos.cloneNode(true));
		}
		document.documentElement.classList.add('anpa-extra-dialog-aberto');
		dialog.showModal();
		var btn = dialog.querySelector('.anpa-extra-dialog-pechar');
		if (btn) { btn.focus(); }
	}

	document.addEventListener('click', function (ev) {
		var summary = ev.target.closest ? ev.target.closest('.anpa-extra-mais > summary') : null;
		if (!summary) { return; }
		ev.preventDefault();
		abrir(summary.parentElement);
	});
})();
