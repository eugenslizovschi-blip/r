/* LibertBus: acordul pentru cookies. Fără dependențe. */
( function () {
	'use strict';

	var NAME = 'lbb_cookie_consent';
	var DAYS = 180;
	var box = document.getElementById( 'lbb-cc' );

	function read() {
		var m = document.cookie.match( new RegExp( '(?:^|; )' + NAME + '=([^;]*)' ) );
		return m ? decodeURIComponent( m[ 1 ] ) : '';
	}

	function write( value ) {
		document.cookie = NAME + '=' + value + '; path=/; max-age=' + ( DAYS * 86400 ) + '; SameSite=Lax' + ( location.protocol === 'https:' ? '; Secure' : '' );
	}

	// Pornește scripturile blocate, în ordinea din pagină.
	function activate() {
		var list = document.querySelectorAll( 'script[type="text/plain"][data-lbb-consent]' );
		for ( var i = 0; i < list.length; i++ ) {
			var old = list[ i ];
			var s = document.createElement( 'script' );
			for ( var a = 0; a < old.attributes.length; a++ ) {
				var at = old.attributes[ a ];
				if ( at.name !== 'type' && at.name !== 'data-lbb-consent' ) {
					s.setAttribute( at.name, at.value );
				}
			}
			if ( old.src ) {
				s.async = old.hasAttribute( 'async' );
			} else {
				s.text = old.text;
			}
			old.parentNode.replaceChild( s, old );
		}
	}

	// La retragerea acordului ștergem cookies-urile de statistică deja puse.
	function clearTrackers() {
		var host = location.hostname.replace( /^www\./, '' );
		document.cookie.split( '; ' ).forEach( function ( c ) {
			var name = c.split( '=' )[ 0 ];
			if ( /^(_ga|_gid|_gat|_gcl|sbjs_|_fbp|_hj|_clck|_clsk)/.test( name ) ) {
				[ '', '; domain=' + host, '; domain=.' + host ].forEach( function ( d ) {
					document.cookie = name + '=; path=/; max-age=0' + d;
				} );
			}
		} );
	}

	// Cât e deschis bannerul, pagina primește spațiu jos, ca butoanele de la final (ex. „Plasează comanda”)
	// să se poată derula deasupra lui.
	var pad = null;

	function show() {
		if ( box ) {
			box.hidden = false;
			if ( pad === null ) {
				pad = document.body.style.paddingBottom;
				var base = parseFloat( window.getComputedStyle( document.body ).paddingBottom ) || 0;
				document.body.style.paddingBottom = ( base + box.offsetHeight + 24 ) + 'px';
			}
		}
	}

	function hide() {
		if ( box ) {
			box.hidden = true;
		}
		if ( pad !== null ) {
			document.body.style.paddingBottom = pad;
			pad = null;
		}
	}

	function choose( value ) {
		var before = read();
		write( value );
		hide();
		if ( value === 'all' ) {
			activate();
		} else if ( before === 'all' ) {
			clearTrackers();
			location.reload();
		}
	}

	if ( box ) {
		box.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest ? e.target.closest( '[data-lbb-cc]' ) : null;
			if ( btn ) {
				choose( btn.getAttribute( 'data-lbb-cc' ) );
			}
		} );
	}

	// „Setări cookies”: orice link spre #lbb-cookies redeschide bannerul.
	document.addEventListener( 'click', function ( e ) {
		var a = e.target.closest ? e.target.closest( 'a[href$="#lbb-cookies"]' ) : null;
		if ( a ) {
			e.preventDefault();
			show();
			var first = box && box.querySelector( 'button' );
			if ( first ) {
				first.focus();
			}
		}
	} );

	// După ce s-a citit toată pagina: unele scripturi blocate vin după acesta, în subsol.
	function start() {
		var current = read();
		if ( current === 'all' ) {
			activate();
		} else if ( current !== 'necessary' ) {
			show();
		}
		if ( location.hash === '#lbb-cookies' ) {
			show();
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
