/* LibertBus Bilete — formularul de rezervare. Fără dependențe. */
( function () {
	'use strict';

	function init( root, index ) {
		var cfg = decodeConfig( root.getAttribute( 'data-lbb-config' ) );
		var t = cfg.i18n;
		var el = {};
		[ 'from', 'route', 'date', 'dateHint', 'groupNote', 'time', 'status', 'adults', 'children', 'names', 'summary', 'mode', 'sending' ].forEach( function ( k ) {
			el[ k ] = root.querySelector( '[data-lbb="' + k + '"]' );
		} );
		var buttons = root.querySelectorAll( '[data-lbb-submit]' );
		var compact = root.classList.contains( 'lbb-compact' );
		var step1 = root.querySelector( '[data-lbb="step1"]' );
		var step2 = root.querySelector( '[data-lbb="step2"]' );
		var nextWrap = root.querySelector( '[data-lbb="next-wrap"]' );
		var nextBtn = root.querySelector( '[data-lbb="next"]' );
		var backBtn = root.querySelector( '[data-lbb="back"]' );
		// Eroare de la server: câmpul vizat (telefon, email, nume…) e marcat până când clientul îl schimbă.
		var alertBox = root.querySelector( '[data-lbb="alert"]' );
		var badField = alertBox ? alertBox.getAttribute( 'data-lbb-field' ) || '' : '';

		function markInvalid( input ) {
			input.setAttribute( 'aria-invalid', 'true' );
			if ( alertBox.id ) {
				input.setAttribute( 'aria-describedby', alertBox.id );
			}
			var clear = function () {
				input.removeAttribute( 'aria-invalid' );
				input.removeAttribute( 'aria-describedby' );
				input.removeEventListener( 'input', clear );
				input.removeEventListener( 'change', clear );
			};
			input.addEventListener( 'input', clear );
			input.addEventListener( 'change', clear );
		}

		// Formular compact (în căsuțe mici, ex. pe homepage): pasul 1 = cursa, pasul 2 = datele și plata.
		var overlay = null;
		var placeholder = null;
		var byPointer = false;
		document.addEventListener( 'mousedown', function () {
			byPointer = true;
		}, true );
		document.addEventListener( 'touchstart', function () {
			byPointer = true;
		}, true );
		document.addEventListener( 'keydown', function () {
			byPointer = false;
		}, true );

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
			// Fereastra stă direct în pagină, în afara formularului: eticheta ei e textul plugin-ului, deci limba lui.
			if ( root.getAttribute( 'lang' ) ) {
				overlay.setAttribute( 'lang', root.getAttribute( 'lang' ) );
			}
			overlay.appendChild( root );
			document.body.appendChild( overlay );
			overlay.addEventListener( 'focusin', keepFocusVisible );
			document.documentElement.classList.add( 'lbb-lock' );
			overlay.scrollTop = 0;
			// Fereastra are o intrare proprie în istoric: „Înapoi” pe telefon (Android) o închide și revine la pasul 1,
			// nu pleacă de pe pagină cu tot ce s-a completat.
			// Revenit cu „Înapoi” de la plată pe intrarea ferestrei: ea există deja, nu mai adăugăm una.
			try {
				if ( ! stepInHistory() ) {
					window.history.pushState( stateWith( 'lbbStep' + ( index || 0 ), 2 ), '' );
				}
				pushedStep = true;
			} catch ( x ) {}
		}

		// Tab de la tastatură: câmpul cu focus (ex. al treilea nume) nu rămâne ascuns sub bara cu butoane lipită jos
		// (WCAG 2.4.11); fereastra derulează cât trebuie. Doar câmpurile de deasupra barei, cele de după ea se văd oricum.
		// Doar la focusul de la tastatură: la o atingere, o derulare chiar la apăsare mută elementul de sub deget și
		// clicul se pierde.
		function keepFocusVisible( e ) {
			var bar = root.querySelector( '.lbb-actions' );
			var tg = e.target;
			if ( byPointer || ! overlay || ! bar || ! tg || tg === bar || bar.contains( tg ) || ! ( bar.compareDocumentPosition( tg ) & 2 ) ) {
				return;
			}
			var q = tg.getBoundingClientRect();
			var b = bar.getBoundingClientRect();
			if ( q.bottom > b.top && q.top < b.bottom ) {
				overlay.scrollTop += Math.ceil( q.bottom - b.top ) + 12;
			}
		}

		function stepInHistory() {
			try {
				return !! ( window.history.state && 2 === window.history.state[ 'lbbStep' + ( index || 0 ) ] );
			} catch ( x ) {
				return false;
			}
		}

		function closeOverlay( fromHistory ) {
			if ( ! overlay ) {
				return;
			}
			placeholder.parentNode.insertBefore( root, placeholder );
			placeholder.parentNode.removeChild( placeholder );
			overlay.parentNode.removeChild( overlay );
			overlay = null;
			document.documentElement.classList.remove( 'lbb-lock' );
			// Închisă din buton sau cu Escape: scoatem și intrarea ei din istoric, ca „Înapoi” să nu mai treacă prin ea.
			if ( pushedStep && ! fromHistory ) {
				try {
					window.history.back();
				} catch ( x ) {}
			}
			pushedStep = false;
		}

		var pushedStep = false;
		window.addEventListener( 'popstate', function () {
			if ( overlay ) {
				showStep( 1, true );
				el.time.focus();
			}
		} );

		function showStep( n, fromHistory ) {
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
				closeOverlay( fromHistory );
			}
		}

		// Câmpul-capcană pentru boți nu primește focus. Fără Element.closest: lipsește pe Android 4.4 și iOS 8.
		function inHoneypot( n ) {
			for ( ; n && n !== overlay; n = n.parentNode ) {
				if ( n.className && ( ' ' + n.className + ' ' ).indexOf( ' lbb-hp ' ) !== -1 ) {
					return true;
				}
			}
			return false;
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
						return ! n.disabled && n.type !== 'hidden' && n.offsetParent !== null && ! inHoneypot( n );
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
		var shownDate = ''; // Data pentru care s-au cerut orele afișate.
		var memoKey = 'lbbTime' + ( index || 0 );
		var namesKey = 'lbbNames' + ( index || 0 );
		var paxKey = 'lbbPax' + ( index || 0 );

		// Starea curentă din istoric, plus o cheie (ce au pus alte formulare de pe pagină rămâne).
		function stateWith( key, value ) {
			var st = {};
			var old = window.history.state;
			if ( old && typeof old === 'object' ) {
				for ( var k in old ) {
					if ( Object.prototype.hasOwnProperty.call( old, k ) ) {
						st[ k ] = old[ k ];
					}
				}
			}
			st[ key ] = value;
			return st;
		}

		function remember( key, value ) {
			try {
				window.history.replaceState( stateWith( key, value ), '' );
			} catch ( x ) {}
		}

		// Ora aleasă se ține în istoricul paginii: la „Înapoi” browserul pune la loc data,
		// dar lista orelor se construiește din nou și alegerea s-ar pierde.
		function rememberTime() {
			remember( memoKey, el.date.value + '|' + el.time.value );
		}

		// La fel numele pasagerilor: câmpurile sunt create din JavaScript, iar la „Înapoi” de la plată browserul nu le
		// pune la loc (clientul primea „Scrieți numele fiecărui pasager” și le scria din nou). Se țin la „change” (care
		// vine și înainte de trimitere, la ieșirea din câmp sau la Enter), nu la fiecare literă: Safari limitează câte
		// replaceState se pot face.
		// Cu numele se țin și câți adulți și copii sunt: o pagină refăcută pornește cu 1 adult.
		function rememberNames() {
			var inputs = el.names.querySelectorAll( 'input' );
			var names = [];
			for ( var i = 0; i < inputs.length; i++ ) {
				names.push( inputs[ i ].value );
			}
			remember( namesKey, names );
			remember( paxKey, [ parseInt( el.adults.value || 0, 10 ), el.children ? parseInt( el.children.value || 0, 10 ) : 0 ] );
		}

		function rememberedPax() {
			try {
				var v = window.history.state && window.history.state[ paxKey ];
				return Object.prototype.toString.call( v ) === '[object Array]' && v.length === 2 ? v : null;
			} catch ( x ) {
				return null;
			}
		}

		// Numele ținute minte se pun la loc doar cât formularul se reface după „Înapoi”: după ce clientul schimbă singur
		// pasagerii sau scrie un nume, câmpurile rămân cum le-a lăsat el.
		var namesTouched = false;
		function touchNames( e ) {
			var tg = e.target;
			if ( tg === el.adults || tg === el.children || ( tg && tg.name === 'lbb_names[]' ) ) {
				namesTouched = true;
			}
		}
		root.addEventListener( 'change', touchNames, true );
		root.addEventListener( 'input', touchNames, true );

		function rememberedNames() {
			if ( namesTouched ) {
				return [];
			}
			try {
				var v = window.history.state && window.history.state[ namesKey ];
				return Object.prototype.toString.call( v ) === '[object Array]' ? v : [];
			} catch ( x ) {
				return [];
			}
		}

		function rememberedTime() {
			try {
				var v = String( ( window.history.state && window.history.state[ memoKey ] ) || '' ).split( '|' );
				return v[ 0 ] && v[ 0 ] === el.date.value ? v[ 1 ] || '' : '';
			} catch ( x ) {
				return '';
			}
		}

		// Browser fără calendar pentru dată (Safari pe Mac < 14.1, Firefox < 57): câmpul e text, iar clientul scrie
		// „25.12.2026” sau „25/12/2026”. Îl aducem la 2026-12-25, formatul cu care lucrează restul formularului.
		function isoDate( v ) {
			var m = /^\s*(\d{1,2})[.\/\-](\d{1,2})[.\/\-](\d{4}|\d{2})\.?\s*$/.exec( v || '' );
			if ( ! m ) {
				return v;
			}
			// Anul cu două cifre („25.12.26”) e 20xx: biletele se vând doar pentru zilele următoare.
			return ( m[ 3 ].length === 2 ? '20' : '' ) + m[ 3 ] + '-' + ( m[ 2 ].length < 2 ? '0' : '' ) + m[ 2 ] + '-' + ( m[ 1 ].length < 2 ? '0' : '' ) + m[ 1 ];
		}

		function option( value, label, disabled ) {
			var o = document.createElement( 'option' );
			o.value = value;
			o.textContent = label;
			if ( disabled ) {
				o.disabled = true;
			}
			return o;
		}

		// Ca pe bilet și în email (LBB_WooCommerce::money): „2.535 MDL”, „61,50 RON”.
		function money( v, cur ) {
			var n = Number( v );
			var parts = ( n % 1 === 0 ? String( n ) : n.toFixed( 2 ) ).split( '.' );
			var text = parts[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, '.' ) + ( parts[ 1 ] ? ',' + parts[ 1 ] : '' );
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

		// Ruta nu circulă zilnic: spunem în ce zile, ca clientul să nu caute dată cu dată.
		function runsOn( route ) {
			if ( ! route.days || ! route.days.length || route.days.length >= 7 ) {
				return '';
			}
			return ' ' + t.runsOn.replace( '%s', route.days.map( function ( d ) {
				return t.dayNames[ d ];
			} ).join( ', ' ) );
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
		// Telefonul din mesaje se apasă direct pentru apel, ca pe bilet și în emailuri.
		function phoneLink() {
			var a = document.createElement( 'a' );
			a.className = 'lbb-phone';
			a.href = t.phoneHref;
			a.textContent = t.phone;
			return a;
		}

		// Textul cu telefonul firmei transformat în link de apel.
		function fillWithPhone( node, text ) {
			node.textContent = '';
			var parts = t.phone ? String( text ).split( t.phone ) : [ String( text ) ];
			for ( var i = 0; i < parts.length; i++ ) {
				if ( i ) {
					node.appendChild( phoneLink() );
				}
				if ( parts[ i ] ) {
					node.appendChild( document.createTextNode( parts[ i ] ) );
				}
			}
		}

		function setStatus( text ) {
			fillWithPhone( el.status, text );
		}

		// Conexiune blocată (semnal slab pe drum): fetch ar aștepta minute întregi cu „Se verifică locurile…”. După
		// 8 secunde cererea se oprește și clientul vede „Încearcă din nou” și telefonul, fără alte reîncercări lungi.
		var FETCH_TIMEOUT = 8000;

		function getJSON( url, tries ) {
			var opts = { credentials: 'same-origin', headers: { Accept: 'application/json' } };
			var timer = null;
			var timedOut = false;
			if ( typeof window.AbortController === 'function' ) {
				var ctrl = new window.AbortController();
				opts.signal = ctrl.signal;
				timer = setTimeout( function () {
					timedOut = true;
					ctrl.abort();
				}, FETCH_TIMEOUT );
			}
			var req = fetch( url, opts );
			// Fără AbortController (iOS < 11.3, Android vechi) cererea nu se poate opri, dar nici nu mai așteptăm
			// după ea: după aceleași 8 secunde clientul vede „Încearcă din nou”.
			if ( ! opts.signal ) {
				req = Promise.race( [ req, new Promise( function ( resolve, reject ) {
					timer = setTimeout( function () {
						timedOut = true;
						reject( new Error( 'timeout' ) );
					}, FETCH_TIMEOUT );
				} ) ] );
			}
			return req
				.then( function ( r ) {
					clearTimeout( timer );
					return r.text().then( function ( body ) {
						var data = null;
						try {
							data = JSON.parse( body );
						} catch ( e ) {}
						if ( ! r.ok || ! data ) {
							var err = new Error( 'bad response ' + r.status );
							// 404: ruta a fost oprită între timp (pagina poate fi din cache); nu are rost să reîncercăm.
							err.notFound = r.status === 404;
							throw err;
						}
						return data;
					} );
				} )
				.catch( function ( err ) {
					clearTimeout( timer );
					if ( timedOut ) {
						err = new Error( 'timeout' );
						err.timeout = true;
					}
					if ( tries <= 1 || err.notFound || err.timeout ) {
						throw err;
					}
					return new Promise( function ( resolve ) {
						setTimeout( resolve, 1500 );
					} ).then( function () {
						return getJSON( url, tries - 1 );
					} );
				} );
		}

		// „1 loc liber”, „5 locuri libere”, „20 de locuri libere” (în română „de” de la 20 în sus, ca 120, dar nu 101–119).
		function freeLabel( n ) {
			var last = n % 100;
			if ( n === 1 ) {
				return '1 ' + ( t.freeOne || t.free );
			}
			return n + ( n >= 20 && ( last === 0 || last >= 20 ) ? ' de ' : ' ' ) + t.free;
		}

		function fillCounts() {
			var route = currentRoute();
			var dep = currentDeparture();
			if ( compact && dep && dep.bookable ) {
				el.status.textContent = dep.time + ': ' + freeLabel( dep.free );
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

			fillNumbers( el.adults, max );
			el.adults.value = adults;
			if ( el.children ) {
				fillNumbers( el.children, max - adults );
				el.children.value = children;
			}
			fillNames( adults, children );
			// La limita de pasageri pe rezervare: un grup mai mare află că poate suna (altfel pleacă fără bilet).
			// La o oră cu mai puține locuri decât limita, lista se oprește mai devreme: clientul află de ce și ce poate face.
			if ( el.groupNote && t.groupNote && t.phone ) {
				var bySeats = !! ( t.seatsNote && dep && dep.bookable && dep.free < cfg.maxPassengers && adults + children >= max );
				var atLimit = bySeats || adults + children >= cfg.maxPassengers;
				el.groupNote.hidden = ! atLimit;
				if ( atLimit ) {
					fillWithPhone( el.groupNote, bySeats ? t.seatsNote.replace( '{seats}', freeLabel( dep.free ) ) : t.groupNote );
				}
				// Cititorul de ecran o spune la listele de pasageri, dar doar cât se vede (un text ascuns legat
				// prin aria-describedby s-ar citi oricum).
				[ el.adults, el.children ].forEach( function ( s ) {
					if ( s && el.groupNote.id ) {
						if ( atLimit ) {
							s.setAttribute( 'aria-describedby', el.groupNote.id );
						} else {
							s.removeAttribute( 'aria-describedby' );
						}
					}
				} );
			}
		}

		// Opțiunile 0…max se refac doar dacă s-a schimbat maximul: un selector deschis pe telefon s-ar închide.
		function fillNumbers( select, max ) {
			if ( select.options.length === max + 1 ) {
				return;
			}
			select.innerHTML = '';
			for ( var i = 0; i <= max; i++ ) {
				select.appendChild( option( i, i ) );
			}
		}

		// Câmpurile existente rămân pe loc (doar se adaugă sau se scot de la coadă): orele vin după o cerere,
		// iar un câmp refăcut cât clientul scrie i-ar lua cursorul și i-ar închide tastatura pe telefon.
		// Adulții sunt primii, copiii după ei. Când se schimbă câți adulți sau copii sunt, fiecare grup își
		// păstrează numele (altfel un adult ar ajunge pe locul de copil, iar numele copilului s-ar pierde).
		var nameCounts = null;

		function fillNames( adults, children ) {
			var total = adults + children;
			var labels = el.names.querySelectorAll( 'label.lbb-field' );
			var stored = labels.length ? [] : ( preset.names && preset.names.length ? preset.names : rememberedNames() );
			var regroup = !! ( nameCounts && labels.length && ( nameCounts[ 0 ] !== adults || nameCounts[ 1 ] !== children ) );
			if ( regroup ) {
				var old = [];
				for ( var i = 0; i < labels.length; i++ ) {
					old.push( labels[ i ].querySelector( 'input' ).value );
				}
				stored = old.slice( 0, Math.min( adults, nameCounts[ 0 ] ) );
				while ( stored.length < adults ) {
					stored.push( '' );
				}
				stored = stored.concat( old.slice( nameCounts[ 0 ], nameCounts[ 0 ] + children ) );
			}
			for ( var n = 0; n < total; n++ ) {
				var label = labels[ n ];
				if ( ! label ) {
					label = nameField( n, stored[ n ] || '' );
					el.names.appendChild( label );
				} else if ( regroup ) {
					var input = label.querySelector( 'input' );
					input.value = stored[ n ] || '';
					checkName.call( input );
				}
				label.querySelector( 'span' ).textContent = t.passenger + ' ' + ( n + 1 ) + ( n >= adults ? ' (' + t.child + ')' : '' );
			}
			for ( var r = labels.length - 1; r >= total; r-- ) {
				el.names.removeChild( labels[ r ] );
			}
			// La „Înapoi” formularul pornește cu 1 adult, iar browserul pune la loc „2 adulți” abia după: câmpurile goale
			// își iau numele ținute minte, dacă lista are exact atâtea nume și nu contrazice ce e deja scris.
			var memo = rememberedNames();
			var cur = el.names.querySelectorAll( 'input' );
			var fits = memo.length === cur.length;
			for ( var m = 0; fits && m < cur.length; m++ ) {
				fits = ! cur[ m ].value || cur[ m ].value === memo[ m ];
			}
			for ( var f = 0; fits && f < cur.length; f++ ) {
				if ( ! cur[ f ].value && memo[ f ] ) {
					cur[ f ].value = String( memo[ f ] );
					checkName.call( cur[ f ] );
				}
			}
			nameCounts = [ adults, children ];
			el.names.hidden = total === 0;
			summary();
		}

		function nameField( n, value ) {
			var label = document.createElement( 'label' );
			label.className = 'lbb-field';
			var span = document.createElement( 'span' );
			var input = document.createElement( 'input' );
			input.type = 'text';
			input.name = 'lbb_names[]';
			input.placeholder = t.namePh;
			input.autocomplete = n === 0 ? 'name' : 'off';
			// Pe telefon: majusculă la fiecare nume, fără corectarea automată care schimbă numele de familie („Rusu” → „Rush”).
			input.setAttribute( 'autocapitalize', 'words' );
			input.setAttribute( 'autocorrect', 'off' );
			input.spellcheck = false;
			input.maxLength = 80;
			input.required = cfg.requireNames;
			input.value = value;
			input.addEventListener( 'input', checkName );
			input.addEventListener( 'change', rememberNames );
			checkName.call( input );
			if ( badField === 'names' && ( ! input.value.trim() || input.validity.customError ) ) {
				markInvalid( input );
			}
			label.appendChild( span );
			label.appendChild( input );
			return label;
		}

		// Ca pe server: un nume are cel puțin două litere (orice alfabet), altfel browserul oprește trimiterea.
		function checkName() {
			var letters = this.value.match( LETTERS );
			this.setCustomValidity( this.value.trim() && ( ! letters || letters.length < 2 ) ? t.nameInvalid : '' );
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
			var approxText = function () {
				return approx.map( function ( c ) {
					var q = route.prices[ c ];
					var v = adults * q[ 0 ] + children * ( q[ 1 ] === null ? q[ 0 ] : q[ 1 ] );
					return '≈\u00a0' + money( Math.round( v ), c );
				} ).join( ', ' );
			};
			el.summary.textContent = '';
			var line = document.createElement( 'div' );
			line.className = 'lbb-summary-route';
			// Data cu ziua săptămânii, ca pe bilet și în email: „21.10.2026 (miercuri) 08:45”.
			var dayOf = '';
			if ( /^\d{4}-\d{2}-\d{2}$/.test( el.date.value ) ) {
				dayOf = ' (' + t.dayNames[ new Date( el.date.value + 'T12:00:00Z' ).getUTCDay() || 7 ] + ')';
			}
			line.textContent = el.from.value + ' → ' + route.to + ( dep ? ', ' + el.date.value.split( '-' ).reverse().join( '.' ) + dayOf + ' ' + dep.time : '' );
			var price = document.createElement( 'div' );
			price.className = 'lbb-summary-total';
			price.textContent = t.total + ': ' + money( total, cur );
			if ( approx.length ) {
				var eq = document.createElement( 'span' );
				eq.className = 'lbb-summary-approx';
				eq.textContent = ' (' + approxText() + ')';
				price.appendChild( eq );
			}
			var note = document.createElement( 'div' );
			note.className = 'lbb-summary-note';
			// Prețul pe loc; cu copii la alt preț, ambele („250 MDL / adult · 150 MDL / copil”), ca să se vadă totalul.
			var unit = function ( kid ) {
				var own = kid && p[ 1 ] !== null ? p[ 1 ] : p[ 0 ];
				var eqs = approx.map( function ( c ) {
					var q = route.prices[ c ];
					return '≈\u00a0' + money( Math.round( kid && q[ 1 ] !== null ? q[ 1 ] : q[ 0 ] ), c );
				} ).join( ', ' );
				return money( own, cur ) + ( eqs ? ' (' + eqs + ')' : '' );
			};
			note.textContent = ( children && childPrice !== p[ 0 ]
				? unit( false ) + ' / ' + t.adult + ' · ' + unit( true ) + ' / ' + t.child
				: unit( false ) + ' / ' + t.passenger.toLowerCase() ) + ( approx.length ? ' · ' + t.approxNote : '' );
			el.summary.appendChild( line );
			// Unde se urcă (autogara, peronul), înainte de plată: altfel clientul sună să întrebe.
			if ( route.boarding ) {
				var board = document.createElement( 'div' );
				board.className = 'lbb-summary-boarding';
				board.textContent = t.boarding + ': ' + route.boarding;
				el.summary.appendChild( board );
			}
			el.summary.appendChild( price );
			el.summary.appendChild( note );
			el.summary.hidden = false;
		}

		// Data în cuvinte („luni, 12 octombrie”): pe un telefon în engleză câmpul arată 10/12/2026 (lună/zi).
		function dateHint() {
			var v = el.date.value;
			if ( ! el.dateHint || ! t.monthNames ) {
				return;
			}
			if ( ! /^\d{4}-\d{2}-\d{2}$/.test( v ) ) {
				el.dateHint.textContent = '';
				return;
			}
			var d = new Date( v + 'T12:00:00Z' );
			el.dateHint.textContent = t.dayNames[ d.getUTCDay() || 7 ] + ', ' + d.getUTCDate() + ' ' + t.monthNames[ d.getUTCMonth() ];
		}

		function nextDay( ymd ) {
			var d = new Date( ymd + 'T12:00:00Z' );
			d.setUTCDate( d.getUTCDate() + 1 );
			return d.toISOString().slice( 0, 10 );
		}

		function loadDepartures() {
			var route = currentRoute();
			dateHint();
			syncCurrency();
			shownDate = el.date.value;
			departures = [];
			el.time.innerHTML = '';
			el.time.appendChild( option( '', t.chooseTime ) );
			el.time.disabled = true;
			el.status.textContent = '';
			if ( ! route || ! el.date.value ) {
				fillCounts();
				return;
			}
			// Safari pe iPhone nu respectă min/max la calendar: explicăm intervalul în loc de „nu sunt plecări”.
			if ( el.date.value < cfg.today || el.date.value > cfg.maxDate ) {
				request++;
				el.status.textContent = t.dateRange;
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
					// Pagina poate veni dintr-un cache vechi: cheia formularului se ia proaspătă (doar pentru vizitatori;
					// un admin logat își păstrează cheia din pagină, legată de contul lui).
					if ( cfg.guest && data.nonce ) {
						var nonceField = root.querySelector( 'input[name="lbb_nonce"]' );
						if ( nonceField ) {
							nonceField.value = data.nonce;
						}
					}
					// Prețul curent al rutei (pagina din cache poate avea prețuri vechi): rezumatul arată ce se va plăti.
					if ( data.route && String( data.route.id ) === String( route.id ) && data.route.prices ) {
						route.prices = data.route.prices;
						route.child_price = data.route.child_price;
						route.orig_price = data.route.orig_price;
						route.orig_cur = data.route.orig_cur;
						route.boarding = data.route.boarding;
					}
					// Pagina din cache de ieri: intervalul de date se mută pe ziua de azi a serverului, iar o dată rămasă
					// în urmă (nealeasă de client) trece pe azi și orele se cer din nou.
					if ( data.today && data.today > cfg.today ) {
						cfg.today = data.today;
						el.date.min = data.today;
						if ( data.maxDate ) {
							cfg.maxDate = data.maxDate;
							el.date.max = data.maxDate;
						}
						if ( el.date.value < cfg.today ) {
							el.date.value = cfg.today;
							dateHint();
							loadDepartures();
							return;
						}
					}
					var open = departures.filter( function ( d ) {
						return d.bookable;
					} );
					// Data aleasă automat nu are plecări deschise (ex. seara): trecem la următoarea zi cu locuri.
					if ( ! open.length && ! dateTouched && skipped < 14 && el.date.value < cfg.maxDate ) {
						skipped++;
						el.date.value = nextDay( el.date.value );
						loadDepartures();
						return;
					}
					setStatus( departures.length ? ( open.length ? '' : t.noneOpen ) : ( data.closed ? t.closedDay : t.noDeparture + runsOn( route ) ) );
					// Fără locuri în ziua aleasă: pe telefon calendarul se redeschide greu, „Ziua următoare” e la o atingere.
					if ( ! open.length && t.nextDay && el.date.value < cfg.maxDate ) {
						el.status.appendChild( document.createTextNode( ' ' ) );
						var dayBtn = document.createElement( 'button' );
						dayBtn.type = 'button';
						dayBtn.className = 'lbb-next-day';
						dayBtn.textContent = t.nextDay + ' ';
						// Săgeata e decor: cititorul de ecran spune doar „Ziua următoare”, nu și „săgeată dreapta”.
						var arrow = document.createElement( 'span' );
						arrow.setAttribute( 'aria-hidden', 'true' );
						arrow.textContent = '→';
						dayBtn.appendChild( arrow );
						dayBtn.addEventListener( 'click', function () {
							dateTouched = true;
							el.date.value = nextDay( el.date.value );
							el.date.focus();
							loadDepartures();
						} );
						el.status.appendChild( dayBtn );
					}
					departures.forEach( function ( d ) {
						// În formularul compact lista e îngustă: doar ora; locurile libere apar sub câmpuri.
						var label = compact
							? d.time + ( d.bookable ? '' : ' · ' + ( d.reason === 'full' ? t.full : t.closed ) )
							: d.time + ' — ' + ( d.bookable ? freeLabel( d.free ) : ( d.reason === 'full' ? t.full : t.closed ) );
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
					if ( el.time.value ) {
						rememberTime(); // Ora aleasă automat (o singură plecare sau din link) nu dă „change”.
					}
					el.time.disabled = ! departures.length;
					fillCounts();
				} )
				.catch( function ( err ) {
					if ( id !== request ) {
						return;
					}
					if ( err && err.notFound ) {
						setStatus( t.routeGone );
						return;
					}
					// Internet slab: clientul reîncearcă dintr-o atingere, fără să schimbe data sau ruta.
					el.status.textContent = t.error + ' ';
					var again = document.createElement( 'button' );
					again.type = 'button';
					again.className = 'lbb-retry';
					again.textContent = t.retry;
					again.addEventListener( 'click', function () {
						loadDepartures();
					} );
					el.status.appendChild( again );
					el.status.appendChild( document.createTextNode( ' ' + t.errorCall + ' ' ) );
					el.status.appendChild( phoneLink() );
					el.status.appendChild( document.createTextNode( '.' ) );
				} );
		}

		// Pasul 2 se redeschide după o eroare de la server și la „Înapoi” de la plată, când clientul plecase din pasul 2
		// (altfel intrarea ferestrei rămânea în istoric și următorul „Înapoi” nu făcea nimic vizibil).
		showStep( root.getAttribute( 'data-step' ) === '2' || stepInHistory() ? 2 : 1 );

		// După o eroare de la server pagina se reîncarcă sus: ducem clientul la mesaj (important pe telefon).
		// După showStep, pentru că mutarea formularului în fereastră ar pierde focusul.
		if ( alertBox ) {
			var badInput = /^(phone|email|date)$/.test( badField ) ? root.querySelector( '[name="lbb_' + badField + '"]' ) : null;
			if ( badInput ) {
				markInvalid( badInput );
			}
			alertBox.scrollIntoView( { block: 'center' } );
			alertBox.focus( { preventScroll: true } );
		}
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
		// Pagina refăcută la „Înapoi” (fără date trimise de la server): pornește cu adulții și copiii ținuți minte.
		var pax = preset.names && preset.names.length ? null : rememberedPax();
		if ( pax ) {
			preset.adults = pax[ 0 ];
			preset.children = pax[ 1 ];
		}
		fillCounts();
		// Datele venite de la server (după o eroare) se țin minte și ele, pentru un „Înapoi” de mai târziu de la plată.
		if ( preset.names && preset.names.length ) {
			rememberNames();
		}
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
		if ( el.date.type !== 'date' ) {
			el.date.placeholder = 'zz.ll.aaaa';
		}
		el.date.addEventListener( 'change', function () {
			dateTouched = true;
			if ( isoDate( el.date.value ) !== el.date.value ) {
				el.date.value = isoDate( el.date.value );
			}
			loadDepartures();
		} );
		el.time.addEventListener( 'change', function () {
			rememberTime();
			fillCounts();
		} );
		el.adults.addEventListener( 'change', fillCounts );
		el.adults.addEventListener( 'change', rememberNames );
		if ( el.children ) {
			el.children.addEventListener( 'change', fillCounts );
			el.children.addEventListener( 'change', rememberNames );
		}
		for ( var r = 0; r < radios.length; r++ ) {
			radios[ r ].addEventListener( 'change', function () {
				currencyTouched = true;
				preset.currency = '';
				summary();
			} );
		}
		// Safari < 15.4 (iPhone cu iOS 14 / 15.0–15.3) nu are e.submitter: ținem minte butonul apăsat, altfel
		// „Rezerv, achit la urcare” s-ar trimite ca „Achit online” (primul buton).
		var clicked = null;
		function rememberClick() {
			clicked = this;
		}
		for ( var cb = 0; cb < buttons.length; cb++ ) {
			buttons[ cb ].addEventListener( 'click', rememberClick );
		}
		root.querySelector( 'form' ).addEventListener( 'submit', function ( e ) {
			var pressed = e.submitter || clicked;
			clicked = null;
			// Enter la pasul 1 al formularului compact = „Continuă”, nu trimitere fără nume.
			if ( compact && root.getAttribute( 'data-step' ) !== '2' ) {
				e.preventDefault();
				if ( nextBtn && ! nextBtn.disabled ) {
					nextBtn.click();
				}
				return;
			}
			var btn = pressed || buttons[ 0 ];
			if ( ! btn || btn.disabled ) {
				e.preventDefault();
				return;
			}
			// Formularul are novalidate (restul erorilor vin de la server): numele greșite le oprim aici.
			var nameInputs = el.names.querySelectorAll( 'input' );
			for ( var k = 0; k < nameInputs.length; k++ ) {
				if ( nameInputs[ k ].validity.customError ) {
					e.preventDefault();
					nameInputs[ k ].focus();
					nameInputs[ k ].reportValidity();
					return;
				}
			}
			// Fără semnal (ex. în autogară): browserul ar arăta pagina lui „Fără internet”, iar la „Înapoi” clientul poate
			// găsi formularul gol. Rămânem pe pagină, cu datele completate, și spunem ce s-a întâmplat.
			if ( false === navigator.onLine ) {
				e.preventDefault();
				if ( el.sending ) {
					el.sending.textContent = t.offline;
				}
				return;
			}
			// Coșul WooCommerce se ține într-un cookie: cu cookie-urile blocate clientul ar ajunge la plată cu coșul gol,
			// iar locurile ar rămâne ținute degeaba. Spunem de ce, în loc să-l trimitem acolo.
			if ( 'pay' === btn.value && false === navigator.cookieEnabled ) {
				e.preventDefault();
				if ( el.sending ) {
					el.sending.textContent = t.noCookies;
				}
				return;
			}
			el.mode.value = btn.value;
			// Dezactivăm după ce browserul a citit datele, ca să nu se trimită de două ori.
			setTimeout( function () {
				for ( var b = 0; b < buttons.length; b++ ) {
					buttons[ b ].disabled = true;
				}
				btn.classList.add( 'is-busy' );
				// Pe internet lent butonul estompat nu spune nimic: scriem ce se întâmplă (și pentru cititoarele de ecran).
				if ( el.sending ) {
					el.sending.textContent = 'pay' === btn.value ? t.sendingPay : t.sendingRes;
				}
			}, 0 );
		} );
		// După mesajul „fără internet”: când revine semnalul, clientul află că poate apăsa din nou.
		window.addEventListener( 'online', function () {
			if ( el.sending && el.sending.textContent === t.offline ) {
				el.sending.textContent = t.backOnline;
			}
			// Orele nu s-au putut încărca (butonul „Reîncercați” e afișat): le cerem singuri, fără atingerea clientului.
			if ( el.status.querySelector( '.lbb-retry' ) ) {
				loadDepartures();
			}
		} );
		// „Înapoi” din browser: din memorie (bfcache) pagina revine cu butoanele blocate de trimiterea de mai sus;
		// la o reîncărcare browserul pune la loc data aleasă de client după ce s-au cerut orele pentru azi.
		var triedKeep = ''; // Ora ținută minte deja încercată (dacă s-a umplut, nu o mai cerem iar).
		function syncRestored() {
			var keep = rememberedTime();
			if ( ( el.date.value && el.date.value !== shownDate ) || ( keep && ! el.time.value && keep !== triedKeep ) ) {
				dateTouched = true;
				skipped = 0;
				if ( keep ) {
					preset.time = keep;
					triedKeep = keep;
				}
				loadDepartures();
			}
		}
		window.addEventListener( 'pageshow', function ( e ) {
			if ( e.persisted ) {
				for ( var b = 0; b < buttons.length; b++ ) {
					buttons[ b ].classList.remove( 'is-busy' );
				}
				if ( el.sending ) {
					el.sending.textContent = '';
				}
				summary();
			}
			syncRestored();
			// Uneori browserul pune câmpurile la loc abia după „pageshow”: mai verificăm o dată puțin mai târziu.
			var nav = window.performance && performance.getEntriesByType ? performance.getEntriesByType( 'navigation' )[ 0 ] : null;
			if ( e.persisted || ( nav && 'back_forward' === nav.type ) ) {
				setTimeout( syncRestored, 300 );
				setTimeout( syncRestored, 1000 );
			}
		} );
	}

	var LETTERS;
	try {
		LETTERS = new RegExp( '\\p{L}', 'gu' );
	} catch ( x ) {
		LETTERS = /[A-Za-z\u00C0-\u024F\u0400-\u04FF]/g; // Browsere vechi fără \p{L}.
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

	// Browser prea vechi (fără fetch/Promise/TextDecoder) sau formular care nu pornește: clientul vede un mesaj cu
	// telefonul, nu un formular care nu răspunde.
	function showFail( root ) {
		var note = root.querySelector( '[data-lbb-js-fail]' );
		if ( note ) {
			note.hidden = false;
		}
	}

	function boot() {
		var roots = document.querySelectorAll( '.lbb-booking[data-lbb-config]' );
		var able = !! ( window.fetch && window.Promise && window.TextDecoder );
		for ( var i = 0; i < roots.length; i++ ) {
			if ( ! able ) {
				showFail( roots[ i ] );
				continue;
			}
			try {
				init( roots[ i ], i );
			} catch ( e ) {
				showFail( roots[ i ] );
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
