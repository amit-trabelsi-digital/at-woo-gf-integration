/**
 * AT Cookie Consent — frontend banner, preferences modal, and cookie scanner.
 *
 * GDPR posture: this script fires NOTHING with side effects before consent.
 * Other scripts should gate on the `at-cookie-consent` event or
 * window.atCookieConsent.choice.
 */
( function () {
	'use strict';

	var cfg = window.atCookieConsent || {};
	if ( ! cfg.cookieName ) {
		return;
	}

	var root = document.querySelector( '.at-cc' );
	if ( ! root ) {
		return;
	}

	var banner = root.querySelector( '[data-at-cc-banner]' );
	var modal = root.querySelector( '[data-at-cc-modal]' );
	var reopen = root.querySelector( '.at-cc__reopen' );

	var categoryKeys = Object.keys( cfg.categories || {} );

	/* ── cookie helpers ──────────────────────────────────────────────── */
	function readCookie( name ) {
		var m = document.cookie.match( '(?:^|; )' + name.replace( /([.*+?^${}()|[\]\\])/g, '\\$1' ) + '=([^;]*)' );
		return m ? decodeURIComponent( m[ 1 ] ) : null;
	}
	function writeCookie( name, value, days ) {
		var d = new Date();
		d.setTime( d.getTime() + days * 24 * 60 * 60 * 1000 );
		document.cookie = name + '=' + encodeURIComponent( value ) + ';expires=' + d.toUTCString() + ';path=/;SameSite=Lax' + ( location.protocol === 'https:' ? ';Secure' : '' );
	}

	function getStoredChoice() {
		var raw = readCookie( cfg.cookieName );
		if ( ! raw ) {
			return null;
		}
		try {
			var parsed = JSON.parse( raw );
			return parsed && parsed.choice ? parsed.choice : null;
		} catch ( e ) {
			return null;
		}
	}

	/* ── state application ───────────────────────────────────────────── */
	function applyChoice( choice ) {
		root.setAttribute( 'data-consented', '1' );
		// Expose + broadcast so third-party snippets can gate themselves.
		window.atCookieConsent = window.atCookieConsent || {};
		window.atCookieConsent.choice = choice;
		document.documentElement.setAttribute( 'data-cc-analytics', choice.analytics ? '1' : '0' );
		document.documentElement.setAttribute( 'data-cc-marketing', choice.marketing ? '1' : '0' );
		document.documentElement.setAttribute( 'data-cc-functional', choice.functional ? '1' : '0' );
		try {
			document.dispatchEvent( new CustomEvent( 'at-cookie-consent', { detail: choice } ) );
		} catch ( e ) {}
	}

	function persist( choice ) {
		writeCookie( cfg.cookieName, JSON.stringify( { v: 1, choice: choice, t: Math.floor( Date.now() / 1000 ) } ), 183 );
		applyChoice( choice );
		// Mirror server-side (best-effort; UI does not depend on it).
		post( 'at_cookie_consent_save', { choice: choice } );
	}

	function post( action, data ) {
		if ( ! cfg.ajaxUrl ) {
			return;
		}
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );
		Object.keys( data || {} ).forEach( function ( k ) {
			var v = data[ k ];
			if ( v && typeof v === 'object' ) {
				Object.keys( v ).forEach( function ( kk ) {
					body.append( k + '[' + kk + ']', v[ kk ] ? '1' : '0' );
				} );
			} else {
				body.append( k, v );
			}
		} );
		// Arrays (scanner) handled separately below.
		if ( data && Array.isArray( data.cookies ) ) {
			body.delete( 'cookies' );
			data.cookies.forEach( function ( c ) {
				body.append( 'cookies[]', c );
			} );
		}
		fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} ).catch( function () {} );
	}

	/* ── choice builders ─────────────────────────────────────────────── */
	function allChoice( value ) {
		var c = { necessary: true };
		categoryKeys.forEach( function ( k ) {
			c[ k ] = k === 'necessary' ? true : !! value;
		} );
		return c;
	}
	function modalChoice() {
		var c = { necessary: true };
		if ( modal ) {
			modal.querySelectorAll( '.at-cc__cat-input' ).forEach( function ( input ) {
				c[ input.getAttribute( 'data-cat' ) ] = input.checked;
			} );
		}
		c.necessary = true;
		return c;
	}

	/* ── UI ──────────────────────────────────────────────────────────── */
	function showBanner() {
		root.hidden = false;
		if ( banner ) { banner.hidden = false; }
	}
	function hideBanner() {
		if ( banner ) { banner.hidden = true; }
	}
	function openModal( choice ) {
		root.hidden = false;
		if ( modal ) {
			// reflect current choice on the toggles
			modal.querySelectorAll( '.at-cc__cat-input' ).forEach( function ( input ) {
				var cat = input.getAttribute( 'data-cat' );
				if ( input.disabled ) { return; }
				input.checked = choice ? !! choice[ cat ] : false;
			} );
			modal.hidden = false;
			document.body.classList.add( 'at-cc-modal-open' );
			var first = modal.querySelector( '.at-cc__close' );
			if ( first ) { first.focus(); }
		}
	}
	function closeModal() {
		if ( modal ) {
			modal.hidden = true;
			document.body.classList.remove( 'at-cc-modal-open' );
		}
	}
	function showReopen() {
		if ( reopen ) { reopen.hidden = false; }
		root.hidden = false;
	}

	function finalize( choice ) {
		persist( choice );
		hideBanner();
		closeModal();
		showReopen();
	}

	/* ── events ──────────────────────────────────────────────────────── */
	root.addEventListener( 'click', function ( e ) {
		var t = e.target.closest( '[data-at-cc]' );
		if ( ! t ) { return; }
		var action = t.getAttribute( 'data-at-cc' );
		if ( action === 'accept' ) {
			finalize( allChoice( true ) );
		} else if ( action === 'reject' ) {
			finalize( allChoice( false ) );
		} else if ( action === 'save' ) {
			finalize( modalChoice() );
		} else if ( action === 'customize' ) {
			openModal( getStoredChoice() );
		} else if ( action === 'close' ) {
			closeModal();
			// If no prior choice, keep the banner visible.
			if ( ! getStoredChoice() ) { showBanner(); }
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Escape' && modal && ! modal.hidden ) {
			closeModal();
			if ( ! getStoredChoice() ) { showBanner(); }
		}
	} );

	/* ── scanner: report cookies not in the registry ─────────────────── */
	function scan() {
		var known = {};
		( cfg.cookies || [] ).forEach( function ( c ) { known[ c.name ] = true; } );
		known[ cfg.cookieName ] = true;
		var found = {};
		document.cookie.split( ';' ).forEach( function ( pair ) {
			var name = pair.split( '=' )[ 0 ].trim();
			if ( ! name || known[ name ] ) { return; }
			// Ignore obvious per-request/session noise prefixes lightly.
			found[ name ] = true;
		} );
		var names = Object.keys( found );
		if ( names.length ) {
			post( 'at_cookie_consent_report', { cookies: names } );
		}
	}

	/* ── boot ────────────────────────────────────────────────────────── */
	var stored = getStoredChoice();
	if ( stored ) {
		applyChoice( stored );
		showReopen();
	} else {
		showBanner();
	}
	// Scan a moment after load so late-set cookies are captured.
	if ( 'requestIdleCallback' in window ) {
		requestIdleCallback( scan, { timeout: 3000 } );
	} else {
		setTimeout( scan, 2000 );
	}
} )();
