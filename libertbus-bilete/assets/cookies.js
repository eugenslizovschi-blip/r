/* LibertBus: acordul pentru cookies (Legea nr. 195/2024 / GDPR). Fără dependențe. */
( function () {
	'use strict';

	var NAME = 'lbb_cookie_consent';
	var VERSION = 'v1';
	var DAYS = 180;
	var CATS = [ 'statistics', 'marketing' ];
	var box = document.getElementById( 'lbb-cc' );
	var panel = box && box.querySelector( '[data-lbb-cc-panel]' );

	// Valoarea cookie-ului: „v1|statistics,marketing|20261003” (versiunea textului, categoriile, data acordului).
	function read() {
		var m = document.cookie.match( new RegExp( '(?:^|; )' + NAME + '=([^;]*)' ) );
		if ( ! m ) {
			return null;
		}
		var v = decodeURIComponent( m[ 1 ] );
		if ( v === 'all' ) {
			return CATS.slice();
		}
		if ( v === 'necessary' ) {
			return [];
		}
		var parts = v.split( '|' );
		// Alt text al politicii (ex. „v0” sau, după o schimbare de scopuri, VERSION nou): acordul vechi nu mai e valabil,
		// așa că bannerul reapare și nimic de statistică/marketing nu pornește până la o nouă alegere.
		if ( parts.length > 1 && parts[ 0 ] !== VERSION ) {
			return null;
		}
		return parts.length > 1 && parts[ 1 ] ? parts[ 1 ].split( ',' ).filter( function ( c ) {
			return CATS.indexOf( c ) > -1;
		} ) : [];
	}

	function write( cats ) {
		var d = new Date();
		var day = d.getFullYear() + ( '0' + ( d.getMonth() + 1 ) ).slice( -2 ) + ( '0' + d.getDate() ).slice( -2 );
		document.cookie = NAME + '=' + encodeURIComponent( VERSION + '|' + cats.join( ',' ) + '|' + day ) + '; path=/; max-age=' + ( DAYS * 86400 ) + '; SameSite=Lax' + ( location.protocol === 'https:' ? '; Secure' : '' );
	}

	// Google: statistica fără reclame dacă „Marketing” nu e bifat (Consent Mode).
	function googleConsent( cats ) {
		window.dataLayer = window.dataLayer || [];
		var gtag = function () {
			window.dataLayer.push( arguments );
		};
		var ads = cats.indexOf( 'marketing' ) > -1 ? 'granted' : 'denied';
		gtag( 'consent', 'default', {
			analytics_storage: cats.indexOf( 'statistics' ) > -1 ? 'granted' : 'denied',
			ad_storage: ads,
			ad_user_data: ads,
			ad_personalization: ads,
		} );
	}

	// Pornește scripturile blocate din categoriile acceptate, în ordinea din pagină.
	function activate( cats ) {
		if ( ! cats.length ) {
			return;
		}
		googleConsent( cats );
		var list = document.querySelectorAll( 'script[type="text/plain"][data-lbb-consent]' );
		for ( var i = 0; i < list.length; i++ ) {
			var old = list[ i ];
			if ( cats.indexOf( old.getAttribute( 'data-lbb-consent' ) ) < 0 ) {
				continue;
			}
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

	// La retragerea acordului ștergem cookies-urile deja puse.
	function clearTrackers() {
		var host = location.hostname.replace( /^www\./, '' );
		document.cookie.split( '; ' ).forEach( function ( c ) {
			var name = c.split( '=' )[ 0 ];
			if ( /^(_ga|_gid|_gat|_gcl|sbjs_|_fbp|_fbc|_hj|_clck|_clsk|_ym|_ttp)/.test( name ) ) {
				[ '', '; domain=' + host, '; domain=.' + host ].forEach( function ( d ) {
					document.cookie = name + '=; path=/; max-age=0' + d;
				} );
			}
		} );
	}

	// Cât e deschis bannerul, pagina primește spațiu jos, ca butoanele de la final (ex. „Plasează comanda”)
	// să se poată derula deasupra lui.
	var pad = null;

	function fitPage() {
		if ( pad === null ) {
			pad = document.body.style.paddingBottom;
		}
		document.body.style.paddingBottom = '';
		var base = parseFloat( window.getComputedStyle( document.body ).paddingBottom ) || 0;
		document.body.style.paddingBottom = ( base + box.offsetHeight + 24 ) + 'px';
	}

	// Bannerul își schimbă înălțimea la rotirea telefonului sau la redimensionare: refacem spațiul.
	window.addEventListener( 'resize', function () {
		if ( box && ! box.hidden && pad !== null ) {
			fitPage();
		}
	} );

	function show( withSettings ) {
		if ( ! box ) {
			return;
		}
		box.hidden = false;
		if ( panel ) {
			var cats = read() || [];
			CATS.forEach( function ( c ) {
				var cb = panel.querySelector( 'input[value="' + c + '"]' );
				if ( cb ) {
					cb.checked = cats.indexOf( c ) > -1;
				}
			} );
			panel.hidden = ! withSettings;
			box.classList.toggle( 'is-settings', !! withSettings );
		}
		fitPage();
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

	// Cursorul pe prima bifă care se poate schimba („Necesare” e mereu bifată și dezactivată).
	function focusFirst() {
		var first = box && box.querySelector( '[data-lbb-cc-panel] input:not([disabled]), button' );
		if ( first ) {
			first.focus();
		}
	}

	function choose( cats ) {
		var before = read() || [];
		write( cats );
		hide();
		var withdrawn = before.some( function ( c ) {
			return cats.indexOf( c ) < 0;
		} );
		if ( withdrawn ) {
			clearTrackers();
			location.reload();
		} else if ( before.length && cats.length > before.length ) {
			// Unele scripturi rulează deja: o reîncărcare pornește totul o singură dată, cu acordul nou.
			location.reload();
		} else if ( ! before.length ) {
			activate( cats );
		}
	}

	if ( box ) {
		box.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest ? e.target.closest( '[data-lbb-cc]' ) : null;
			if ( ! btn ) {
				return;
			}
			var action = btn.getAttribute( 'data-lbb-cc' );
			if ( action === 'all' ) {
				choose( CATS.slice() );
			} else if ( action === 'necessary' ) {
				choose( [] );
			} else if ( action === 'settings' ) {
				show( true );
				focusFirst();
			} else if ( action === 'save' ) {
				choose( CATS.filter( function ( c ) {
					var cb = panel.querySelector( 'input[value="' + c + '"]' );
					return cb && cb.checked;
				} ) );
			}
		} );
	}

	// Escape: cine a ales deja închide bannerul fără schimbări; la prima vizită doar se strâng „Setări”.
	document.addEventListener( 'keydown', function ( e ) {
		if ( ( e.key !== 'Escape' && e.key !== 'Esc' ) || ! box || box.hidden ) {
			return;
		}
		if ( read() !== null ) {
			hide();
		} else if ( panel && ! panel.hidden ) {
			show( false );
			var btn = box.querySelector( '[data-lbb-cc="settings"]' );
			if ( btn ) {
				btn.focus();
			}
		}
	} );

	// „Setări cookies”: orice link spre #lbb-cookies redeschide bannerul, cu categoriile.
	document.addEventListener( 'click', function ( e ) {
		var a = e.target.closest ? e.target.closest( 'a[href$="#lbb-cookies"]' ) : null;
		if ( a ) {
			e.preventDefault();
			show( true );
			focusFirst();
		}
	} );

	// După ce s-a citit toată pagina: unele scripturi blocate vin după acesta, în subsol.
	function start() {
		var current = read();
		if ( current === null ) {
			show( false );
		} else {
			activate( current );
		}
		if ( location.hash === '#lbb-cookies' ) {
			show( true );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
