/* LibertBus Bilete — formularul de rezervare. Fără dependențe. */
( function () {
	'use strict';

	function init( root ) {
		var cfg = JSON.parse( root.getAttribute( 'data-lbb' ) );
		var t = cfg.i18n;
		var el = {};
		[ 'from', 'route', 'date', 'time', 'status', 'adults', 'children', 'names', 'summary', 'submit' ].forEach( function ( k ) {
			el[ k ] = root.querySelector( '[data-lbb="' + k + '"]' );
		} );
		var childrenWrap = root.querySelector( '[data-lbb="children-wrap"]' );
		var departures = [];
		var request = 0;
		var preset = cfg.preset || {};

		function option( value, label, disabled ) {
			var o = document.createElement( 'option' );
			o.value = value;
			o.textContent = label;
			if ( disabled ) {
				o.disabled = true;
			}
			return o;
		}

		function money( v ) {
			var n = Number( v ).toFixed( cfg.decimals );
			return n.replace( '.', ',' ) + ' ' + cfg.currency;
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

		function fillCounts() {
			var route = currentRoute();
			var dep = currentDeparture();
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
			el.submit.disabled = ! ok;
			if ( ! route ) {
				el.summary.hidden = true;
				return;
			}
			var childPrice = route.child_price === null ? route.price : route.child_price;
			var total = adults * route.price + children * childPrice;
			var base = route.orig_cur !== cfg.currencyCode ? ' (' + t.approx + ': ' + route.orig_price + ' ' + route.orig_cur + ')' : '';
			el.summary.textContent = '';
			var line = document.createElement( 'div' );
			line.className = 'lbb-summary-route';
			line.textContent = el.from.value + ' → ' + route.to + ( dep ? ', ' + el.date.value.split( '-' ).reverse().join( '.' ) + ' ' + dep.time : '' );
			var price = document.createElement( 'div' );
			price.className = 'lbb-summary-total';
			price.textContent = t.total + ': ' + money( total );
			var note = document.createElement( 'div' );
			note.className = 'lbb-summary-note';
			note.textContent = money( route.price ) + ' / ' + t.passenger.toLowerCase() + base;
			el.summary.appendChild( line );
			el.summary.appendChild( price );
			el.summary.appendChild( note );
			el.summary.hidden = false;
		}

		function loadDepartures() {
			var route = currentRoute();
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
			fetch( url, { credentials: 'same-origin', headers: { Accept: 'application/json' } } )
				.then( function ( r ) {
					if ( ! r.ok ) {
						throw new Error( r.status );
					}
					return r.json();
				} )
				.then( function ( data ) {
					if ( id !== request ) {
						return;
					}
					departures = data.departures || [];
					el.status.textContent = departures.length ? '' : t.noDeparture;
					departures.forEach( function ( d ) {
						var label = d.time + ' — ' + ( d.bookable ? d.free + ' ' + t.free : ( d.reason === 'full' ? t.full : t.closed ) );
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

		fillFrom();
		fillRoutes();
		el.date.min = cfg.today;
		el.date.max = cfg.maxDate;
		el.date.value = preset.date && preset.date >= cfg.today ? preset.date : cfg.today;
		fillCounts();
		loadDepartures();

		el.from.addEventListener( 'change', function () {
			preset.route = 0;
			fillRoutes();
			loadDepartures();
		} );
		el.route.addEventListener( 'change', loadDepartures );
		el.date.addEventListener( 'change', loadDepartures );
		el.time.addEventListener( 'change', fillCounts );
		el.adults.addEventListener( 'change', fillCounts );
		if ( el.children ) {
			el.children.addEventListener( 'change', fillCounts );
		}
		root.querySelector( 'form' ).addEventListener( 'submit', function ( e ) {
			if ( el.submit.disabled ) {
				e.preventDefault();
				return;
			}
			el.submit.disabled = true;
			el.submit.classList.add( 'is-busy' );
		} );
	}

	function boot() {
		var roots = document.querySelectorAll( '.lbb-booking[data-lbb]' );
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
