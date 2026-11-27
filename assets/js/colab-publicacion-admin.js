/**
 * Collaborators network (1.105.0): «Publicación semanal» settings.
 *
 * Media-library picker for the seasonal image overrides and the
 * confirmation of «Publicar agora». Only attributes and values are set.
 *
 * @package ANPA_Socios
 */
( function () {
	'use strict';

	function previa( campo ) {
		return document.getElementById( campo + '-previa' );
	}

	function escoller( boton ) {
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}
		var campo = boton.getAttribute( 'data-campo' );
		var frame = window.wp.media( {
			title: boton.getAttribute( 'data-titulo' ) || '',
			button: { text: boton.getAttribute( 'data-boton' ) || '' },
			library: { type: 'image' },
			multiple: false
		} );
		frame.on( 'select', function () {
			var sel = frame.state().get( 'selection' ).first();
			if ( ! sel ) {
				return;
			}
			var a = sel.toJSON();
			var input = document.getElementById( campo );
			var img = previa( campo );
			if ( input ) {
				input.value = String( parseInt( a.id, 10 ) || 0 );
			}
			if ( img ) {
				var url = ( a.sizes && a.sizes.medium && a.sizes.medium.url ) ? a.sizes.medium.url : a.url;
				img.setAttribute( 'src', url || '' );
				img.style.display = url ? '' : 'none';
			}
		} );
		frame.open();
	}

	function restaurar( boton ) {
		var campo = boton.getAttribute( 'data-campo' );
		var input = document.getElementById( campo );
		var img = previa( campo );
		if ( input ) {
			input.value = '0';
		}
		if ( img ) {
			var orixinal = img.getAttribute( 'data-orixinal' ) || '';
			img.setAttribute( 'src', orixinal );
			img.style.display = orixinal ? '' : 'none';
		}
	}

	document.addEventListener( 'click', function ( ev ) {
		var t = ev.target;
		if ( ! ( t instanceof Element ) ) {
			return;
		}
		var b = t.closest( '.anpa-colab-pub-escoller' );
		if ( b ) {
			ev.preventDefault();
			escoller( b );
			return;
		}
		b = t.closest( '.anpa-colab-pub-restaurar' );
		if ( b ) {
			ev.preventDefault();
			restaurar( b );
		}
	} );

	document.addEventListener( 'submit', function ( ev ) {
		var f = ev.target;
		if ( ! ( f instanceof HTMLFormElement ) || ! f.classList.contains( 'anpa-colab-pub-agora' ) ) {
			return;
		}
		var msg = f.getAttribute( 'data-confirmar' ) || '';
		if ( msg && ! window.confirm( msg ) ) {
			ev.preventDefault();
		}
	} );
}() );
