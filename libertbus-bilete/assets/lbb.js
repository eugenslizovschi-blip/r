/* LibertBus Bilete — formularul de rezervare. Fără dependențe. */
( function () {
	'use strict';

	function init( root ) {
		var cfg = decodeConfig( root.getAttribute( 'data-lbb-config' ) );
		var t = cfg.i18n;
		var el = {};
		[ 'from', 'route', 'date', 'time', 'status', 'adults', 'children', 'names', 'summary', 'mode' ].forEach( function ( k ) {
			el[ k ] = root.querySelector( '[data-lbb="' + k + '"]' );
		} );
		var buttons = root.querySelectorAll( '[data-lbb-submit]' );
		var compact = root.classList.contains( 'lbb-compact' );
		var step1 = root.querySelector( '[data-lbb="step1"]' );
		var step2 = root.querySelector( '[data-lbb="step2"]' );
		var nextWrap = root.querySelector( '[data-lbb="next-wrap"]' );
		var nextBtn = root.querySelector( '[data-lbb="next"]' );
		var backBtn = root.querySelector( '[data-lbb="back"]' );

		// Formular compact (în căsuțe mici, ex. pe homepage): pasul 1 = cursa, pasul 2 = datele și plata.
		var overlay = null;
		var placeholder = null;

		// Pasul 2 se deschide într-o fereastră peste pagină (ecran întreg pe telefon), ca să nu depindă
		// de căsuța temei: pe homepage căsuța are înălțime fixă și secțiunea următoare acoperea butoanele.
		function openOverlay() {
			if ( overlay ) {
				return;
			}
			placeholder = document.createComment( 'lbb-booking' );
			root.parentNode.insertBefore( placeholder, root );
			overlay = document.createElement( 'div' );
			overlay.className = 'lbb-overlay';
			overlay.setAttribute( 'role', 'dialog' );
			overlay.setAttribute( 'aria-modal', 'true' );
			overlay.setAttribute( 'aria-label', t.dialogLabel );
			overlay.appendChild( root );
			document.body.appendChild( overlay );
			document.documentElement.classList.add( 'lbb-lock' );
			overlay.scrollTop = 0;
		}

		function closeOverlay() {
			if ( ! overlay ) {
				return;
			}
			placeholder.parentNode.insertBefore( root, placeholder );
			placeholder.parentNode.removeChild( placeholder );
			overlay.parentNode.removeChild( overlay );
			overlay = null;
			document.documentElement.classList.remove( 'lbb-lock' );
		}

		function showStep( n ) {
			if ( ! compact ) {
				return;
			}
			root.setAttribute( 'data-step', String( n ) );
			step1.hidden = n === 2;
			if ( nextWrap ) {
				nextWrap.hidden = n === 2;
			}
			step2.hidden = n === 1;
			if ( n === 2 ) {
				openOverlay();
			} else {
				closeOverlay();
			}
		}

		document.addEventListener( 'keydown', function ( e ) {
			if ( ! overlay ) {
				return;
			}
			if ( e.key === 'Escape' || e.key === 'Esc' ) {
				showStep( 1 );
				el.time.focus();
				return;
			}
			// Tab rămâne în fereastră (pagina din spate nu se vede).
			if ( e.key === 'Tab' ) {
				var items = Array.prototype.filter.call(
					overlay.querySelectorAll( 'a[href], button, input, select, textarea, [tabindex]:not([tabindex="-1"])' ),
					function ( n ) {
						return ! n.disabled && n.type !== 'hidden' && n.offsetParent !== null && ! n.closest( '.lbb-hp' );
					}
				);
				if ( ! items.length ) {
					return;
				}
				var first = items[ 0 ];
				var last = items[ items.length - 1 ];
				var inside = overlay.contains( document.activeElement );
				if ( e.shiftKey && ( document.activeElement === first || ! inside ) ) {
					e.preventDefault();
					last.focus();
				} else if ( ! e.shiftKey && ( document.activeElement === last || ! inside ) ) {
					e.preventDefault();
					first.focus();
				}
			}
		} );
		var radios = root.querySelectorAll( '[data-lbb="currency"]' );
		var currencyTouched = false;

		var childrenWrap = root.querySelector( '[data-lbb="children-wrap"]' );
		var departures = [];
		var request = 0;
		var preset = cfg.preset || {};
		var dateTouched = !! preset.date;
		var skipped = 0;

		function option( value, label, disabled ) {
			var o = document.createElement( 'option' );
			o.value = value;
			o.textContent = label;
			if ( disabled ) {
				o.disabled = true;
			}
			return o;
		}

		function money( v, cur ) {
			var n = Number( v );
			var text = n % 1 === 0 ? String( n ) : n.toFixed( 2 ).replace( '.', ',' );
			return text + '\u00a0' + cur; // nu se rupe între sumă și monedă
		}

		function currency() {
			for ( var i = 0; i < radios.length; i++ ) {
				if ( radios[ i ].checked ) {
					return radios[ i ].value;
				}
			}
			return cfg.currencies[ 0 ];
		}

		// Moneda implicită: cea a rutei (MDL spre România, RON spre Moldova), până alege clientul.
		function syncCurrency() {
			var route = currentRoute();
			var want = preset.currency || ( route ? route.pay_cur : cfg.currencies[ 0 ] );
			if ( currencyTouched && ! preset.currency ) {
				return;
			}
			for ( var i = 0; i < radios.length; i++ ) {
				radios[ i ].checked = radios[ i ].value === want;
			}
		}

		function currentRoute() {
			var list = cfg.map[ el.from.value ] || [];
			for ( var i = 0; i < list.length; i++ ) {
				if ( String( list[ i ].id ) === el.route.value ) {
					return list[ i ];
				}
			}
			return null;
		}

		function currentDeparture() {
			for ( var i = 0; i < departures.length; i++ ) {
				if ( departures[ i ].time === el.time.value ) {
					return departures[ i ];
				}
			}
			return null;
		}

		function fillFrom() {
			el.from.innerHTML = '';
			el.from.appendChild( option( '', t.chooseFrom ) );
			Object.keys( cfg.map ).forEach( function ( city ) {
				el.from.appendChild( option( city, city ) );
			} );
			if ( preset.from && cfg.map[ preset.from ] ) {
				el.from.value = preset.from;
			}
		}

		function fillRoutes() {
			var list = cfg.map[ el.from.value ] || [];
			el.route.innerHTML = '';
			el.route.appendChild( option( '', t.chooseTo ) );
			list.forEach( function ( r ) {
				el.route.appendChild( option( r.id, r.to ) );
			} );
			list.forEach( function ( r ) {
				if ( ( preset.route && r.id === preset.route ) || ( ! preset.route && preset.to && r.to === preset.to ) ) {
					el.route.value = String( r.id );
				}
			} );
			el.route.disabled = ! list.length;
		}

		// Protecția hostingului poate răspunde o clipă cu o pagină HTML în loc de JSON: reîncercăm.
		function getJSON( url, tries ) {
			return fetch( url, { credentials: 'same-origin', headers: { Accept: 'application/json' } } )
				.then( function ( r ) {
					return r.text().then( function ( body ) {
						var data = null;
						try {
							data = JSON.parse( body );
						} catch ( e ) {}
						if ( ! r.ok || ! data ) {
							throw new Error( 'bad response ' + r.status );
						}
						return data;
					} );
				} )
				.catch( function ( err ) {
					if ( tries <= 1 ) {
						throw err;
					}
					return new Promise( function ( resolve ) {
						setTimeout( resolve, 1500 );
					} ).then( function () {
						return getJSON( url, tries - 1 );
					} );
				} );
		}

		function fillCounts() {
			var route = currentRoute();
			var dep = currentDeparture();
			if ( compact && dep && dep.bookable ) {
				el.status.textContent = dep.time + ': ' + dep.free + ' ' + t.free;
			}
			var max = cfg.maxPassengers;
			if ( dep && dep.free < max ) {
				max = dep.free;
			}
			max = Math.max( 1, max );
			var adults = parseInt( el.adults.value || preset.adults || 1, 10 );
			var children = el.children ? parseInt( el.children.value || preset.children || 0, 10 ) : 0;
			var hasChild = route && route.child_price !== null;
			if ( childrenWrap ) {
				childrenWrap.hidden = ! hasChild;
			}
			if ( ! hasChild ) {
				children = 0;
			}
			adults = Math.min( Math.max( adults, children ? 0 : 1 ), max );
			children = Math.min( children, max - adults );

			el.adults.innerHTML = '';
			for ( var a = 0; a <= max; a++ ) {
				el.adults.appendChild( option( a, a ) );
			}
			el.adults.value = adults;
			if ( el.children ) {
				el.children.innerHTML = '';
				for ( var c = 0; c <= max - adults; c++ ) {
					el.children.appendChild( option( c, c ) );
				}
				el.children.value = children;
			}
			fillNames( adults, children );
		}

		function fillNames( adults, children ) {
			var total = adults + children;
			var inputs = el.names.querySelectorAll( 'input' );
			var values = [];
			for ( var i = 0; i < inputs.length; i++ ) {
				values.push( inputs[ i ].value );
			}
			if ( ! values.length && preset.names ) {
				values = preset.names;
			}
			while ( el.names.children.length > 1 ) {
				el.names.removeChild( el.names.lastChild );
			}
			for ( var n = 0; n < total; n++ ) {
				var label = document.createElement( 'label' );
				label.className = 'lbb-field';
				var span = document.createElement( 'span' );
				span.textContent = t.passenger + ' ' + ( n + 1 ) + ( n >= adults ? ' (' + t.child + ')' : '' );
				var input = document.createElement( 'input' );
				input.type = 'text';
				input.name = 'lbb_names[]';
				input.placeholder = t.namePh;
				input.autocomplete = n === 0 ? 'name' : 'off';
				input.maxLength = 80;
				input.required = cfg.requireNames;
				input.value = values[ n ] || '';
				label.appendChild( span );
				label.appendChild( input );
				el.names.appendChild( label );
			}
			el.names.hidden = total === 0;
			summary();
		}

		function summary() {
			var route = currentRoute();
			var dep = currentDeparture();
			var adults = parseInt( el.adults.value || 0, 10 );
			var children = el.children ? parseInt( el.children.value || 0, 10 ) : 0;
			var ok = !! ( route && dep && dep.bookable && adults + children > 0 );
			for ( var b = 0; b < buttons.length; b++ ) {
				buttons[ b ].disabled = ! ok;
			}
			if ( nextBtn ) {
				nextBtn.disabled = ! ok;
			}
			if ( ! route ) {
				el.summary.hidden = true;
				return;
			}
			var cur = currency();
			var p = route.prices[ cur ] || route.prices[ cfg.currencies[ 0 ] ];
			var childPrice = p[ 1 ] === null ? p[ 0 ] : p[ 1 ];
			var total = adults * p[ 0 ] + children * childPrice;
			// Echivalent informativ: „≈ 62 RON” lângă suma în MDL (și invers).
			var approx = [];
			if ( cfg.showApprox ) {
				[ 'MDL', 'RON', route.orig_cur ].forEach( function ( c ) {
					if ( c !== cur && route.prices[ c ] && approx.indexOf( c ) < 0 ) {
						approx.push( c );
					}
				} );
			}
			var approxText = function ( factor ) {
				return approx.map( function ( c ) {
					var q = route.prices[ c ];
					var v = adults * q[ 0 ] + children * ( q[ 1 ] === null ? q[ 0 ] : q[ 1 ] );
					return '≈\u00a0' + money( Math.round( factor ? q[ 0 ] : v ), c );
				} ).join( ', ' );
			};
			el.summary.textContent = '';
			var line = document.createElement( 'div' );
			line.className = 'lbb-summary-route';
			line.textContent = el.from.value + ' → ' + route.to + ( dep ? ', ' + el.date.value.split( '-' ).reverse().join( '.' ) + ' ' + dep.time : '' );
			var price = document.createElement( 'div' );
			price.className = 'lbb-summary-total';
			price.textContent = t.total + ': ' + money( total, cur );
			if ( approx.length ) {
				var eq = document.createElement( 'span' );
				eq.className = 'lbb-summary-approx';
				eq.textContent = ' (' + approxText( false ) + ')';
				price.appendChild( eq );
			}
			var note = document.createElement( 'div' );
			note.className = 'lbb-summary-note';
			note.textContent = money( p[ 0 ], cur ) + ' / ' + t.passenger.toLowerCase() + ( approx.length ? ' (' + approxText( true ) + ')' : '' ) + ( approx.length ? ' · ' + t.approxNote : '' );
			el.summary.appendChild( line );
			el.summary.appendChild( price );
			el.summary.appendChild( note );
			el.summary.hidden = false;
		}

		function loadDepartures() {
			var route = currentRoute();
			syncCurrency();
			departures = [];
			el.time.innerHTML = '';
			el.time.appendChild( option( '', t.chooseTime ) );
			el.time.disabled = true;
			el.status.textContent = '';
			if ( ! route || ! el.date.value ) {
				fillCounts();
				return;
			}
			var id = ++request;
			el.status.textContent = t.loading;
			var url = cfg.restUrl + ( cfg.restUrl.indexOf( '?' ) > -1 ? '&' : '?' ) + 'route_id=' + route.id + '&date=' + encodeURIComponent( el.date.value );
			getJSON( url, 3 )
				.then( function ( data ) {
					if ( id !== request ) {
						return;
					}
					departures = data.departures || [];
					var open = departures.filter( function ( d ) {
						return d.bookable;
					} );
					// Data aleasă automat nu are plecări deschise (ex. seara): trecem la următoarea zi cu locuri.
					if ( ! open.length && ! dateTouched && skipped < 14 && el.date.value < cfg.maxDate ) {
						skipped++;
						var next = new Date( el.date.value + 'T12:00:00Z' );
						next.setUTCDate( next.getUTCDate() + 1 );
						el.date.value = next.toISOString().slice( 0, 10 );
						loadDepartures();
						return;
					}
					el.status.textContent = departures.length ? ( open.length ? '' : t.noneOpen ) : t.noDeparture;
					departures.forEach( function ( d ) {
						// În formularul compact lista e îngustă: doar ora; locurile libere apar sub câmpuri.
						var label = compact
							? d.time + ( d.bookable ? '' : ' · ' + ( d.reason === 'full' ? t.full : t.closed ) )
							: d.time + ' — ' + ( d.bookable ? d.free + ' ' + t.free : ( d.reason === 'full' ? t.full : t.closed ) );
						el.time.appendChild( option( d.time, label, ! d.bookable ) );
					} );
					var firstOpen = departures.filter( function ( d ) {
						return d.bookable;
					} );
					if ( preset.time && firstOpen.some( function ( d ) {
						return d.time === preset.time;
					} ) ) {
						el.time.value = preset.time;
					} else if ( firstOpen.length === 1 ) {
						el.time.value = firstOpen[ 0 ].time;
					}
					preset.time = '';
					el.time.disabled = ! departures.length;
					fillCounts();
				} )
				.catch( function () {
					if ( id === request ) {
						el.status.textContent = t.error;
					}
				} );
		}

		// După o eroare de la server pagina se reîncarcă sus: ducem clientul la mesaj (important pe telefon).
		var alertBox = root.querySelector( '[data-lbb="alert"]' );
		if ( alertBox ) {
			alertBox.scrollIntoView( { block: 'center' } );
			alertBox.focus( { preventScroll: true } );
		}

		showStep( root.getAttribute( 'data-step' ) === '2' ? 2 : 1 );
		if ( nextBtn ) {
			nextBtn.addEventListener( 'click', function () {
				showStep( 2 );
				var first = step2.querySelector( 'input' );
				if ( first ) {
					first.focus();
				}
			} );
		}
		if ( backBtn ) {
			backBtn.addEventListener( 'click', function () {
				showStep( 1 );
				el.time.focus();
			} );
		}

		fillFrom();
		fillRoutes();
		el.date.min = cfg.today;
		el.date.max = cfg.maxDate;
		el.date.value = preset.date && preset.date >= cfg.today ? preset.date : cfg.today;
		fillCounts();
		loadDepartures();

		el.from.addEventListener( 'change', function () {
			preset.route = 0;
			skipped = 0;
			fillRoutes();
			loadDepartures();
		} );
		el.route.addEventListener( 'change', function () {
			skipped = 0;
			loadDepartures();
		} );
		el.date.addEventListener( 'change', function () {
			dateTouched = true;
			loadDepartures();
		} );
		el.time.addEventListener( 'change', fillCounts );
		el.adults.addEventListener( 'change', fillCounts );
		if ( el.children ) {
			el.children.addEventListener( 'change', fillCounts );
		}
		for ( var r = 0; r < radios.length; r++ ) {
			radios[ r ].addEventListener( 'change', function () {
				currencyTouched = true;
				preset.currency = '';
				summary();
			} );
		}
		root.querySelector( 'form' ).addEventListener( 'submit', function ( e ) {
			var btn = e.submitter || buttons[ 0 ];
			if ( ! btn || btn.disabled ) {
				e.preventDefault();
				return;
			}
			el.mode.value = btn.value;
			// Dezactivăm după ce browserul a citit datele, ca să nu se trimită de două ori.
			setTimeout( function () {
				for ( var b = 0; b < buttons.length; b++ ) {
					buttons[ b ].disabled = true;
				}
				btn.classList.add( 'is-busy' );
			}, 0 );
		} );
	}

	// Configurația vine în base64 (JSON UTF-8), ca temele care scot „\\” din conținut să nu strice diacriticele.
	function decodeConfig( b64 ) {
		var bin = window.atob( b64 );
		var bytes = new Uint8Array( bin.length );
		for ( var i = 0; i < bin.length; i++ ) {
			bytes[ i ] = bin.charCodeAt( i );
		}
		return JSON.parse( new TextDecoder( 'utf-8' ).decode( bytes ) );
	}

	function boot() {
		var roots = document.querySelectorAll( '.lbb-booking[data-lbb-config]' );
		for ( var i = 0; i < roots.length; i++ ) {
			try {
				init( roots[ i ] );
			} catch ( e ) {
				if ( window.console ) {
					window.console.error( 'LibertBus:', e );
				}
			}
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
