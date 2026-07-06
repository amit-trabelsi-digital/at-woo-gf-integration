/**
 * AT Cookie Consent — admin settings JS.
 * Add / remove cookie rows, keep the [cookies][i][field] indexes contiguous,
 * and pre-fill a row from a detected cookie name.
 */
( function () {
	'use strict';

	var cfg = window.atCookieAdmin || {};
	var OPTION = 'at_woo_gf_cookie_settings';

	var tbody = document.querySelector( '[data-at-cc-rows]' );
	var tmpl = document.getElementById( 'at-cc-row-template' );
	var addBtn = document.getElementById( 'at-cc-add-cookie' );
	if ( ! tbody || ! tmpl ) {
		return;
	}

	function reindex() {
		var rows = tbody.querySelectorAll( '.at-cc-admin__row' );
		rows.forEach( function ( row, i ) {
			row.querySelectorAll( '[data-field], input[name], select[name]' ).forEach( function ( el ) {
				var field = el.getAttribute( 'data-field' );
				if ( ! field ) {
					// derive field from existing name
					var m = ( el.getAttribute( 'name' ) || '' ).match( /\[(\w+)\]$/ );
					field = m ? m[ 1 ] : null;
				}
				if ( field ) {
					el.setAttribute( 'name', OPTION + '[cookies][' + i + '][' + field + ']' );
					el.removeAttribute( 'data-field' );
				}
			} );
		} );
	}

	function addRow( prefill ) {
		var frag = tmpl.content.cloneNode( true );
		var row = frag.querySelector( 'tr' );
		if ( prefill && prefill.name ) {
			var nameInput = row.querySelector( '[data-field="name"]' );
			if ( nameInput ) { nameInput.value = prefill.name; }
		}
		tbody.appendChild( row );
		reindex();
		return tbody.lastElementChild;
	}

	if ( addBtn ) {
		addBtn.addEventListener( 'click', function () {
			var row = addRow();
			var first = row.querySelector( 'input' );
			if ( first ) { first.focus(); }
		} );
	}

	tbody.addEventListener( 'click', function ( e ) {
		var del = e.target.closest( '.at-cc-admin__remove' );
		if ( ! del ) { return; }
		if ( cfg.strings && cfg.strings.confirmRemove && ! window.confirm( cfg.strings.confirmRemove ) ) {
			return;
		}
		var row = del.closest( '.at-cc-admin__row' );
		if ( row ) { row.remove(); reindex(); }
	} );

	// Add-from-detected buttons.
	document.querySelectorAll( '.at-cc-admin__add-detected' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var name = btn.getAttribute( 'data-name' );
			var row = addRow( { name: name } );
			btn.disabled = true;
			var cat = row.querySelector( 'select' );
			if ( cat ) { cat.focus(); }
		} );
	} );
} )();
