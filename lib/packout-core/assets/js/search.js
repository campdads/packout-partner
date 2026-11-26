/**
 * Pack-Out Spot: the search box of a map.
 *
 * One box on every map: the Map page, the pin of a report, the field app, and
 * the outline of a Place. It asks this site, which answers with locations
 * from the map's own data, the Spots the person may see, and listed Places.
 * The results are listed under a heading for each kind. Choosing one is the
 * caller's to act on: it moves its map.
 *
 * The search runs when the person presses Search or Enter, not while they
 * type. The box is not a form element, so it can sit inside a form.
 *
 *     window.packoutSearch.mount( host, {
 *         near: function () { return [ lat, lng ]; },   // The middle of the map, or null.
 *         choose: function ( result, kind ) {},        // kind: 'locations', 'spots', or 'places'.
 *         headers: {},                                 // Sent with the request, such as a nonce. Or a function that returns them.
 *         credentials: 'omit',                         // Or 'same-origin' for a signed-in person.
 *         online: function () { return true; },        // False when there is no connection.
 *         collapse: false,                             // True where the results lie over the map: choosing one closes the list.
 *         pages: true,                                 // False to leave out the link to a Place's page.
 *     } );
 */
( function () {
	'use strict';

	var KINDS = [ 'locations', 'spots', 'places', 'features' ];
	var serial = 0;

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text !== undefined && text !== null ) {
			node.textContent = text;
		}
		return node;
	}

	function mount( host, options ) {
		var settings = ( options && options.settings ) || window.packoutSearchSettings || {};
		var text = settings.text || {};
		if ( ! host || ! settings.url || host.getAttribute( 'data-pos-search-ready' ) ) {
			return null;
		}
		host.setAttribute( 'data-pos-search-ready', '1' );
		options = options || {};
		serial += 1;
		var id = 'pos-search-' + serial;
		var asked = 0;

		var root = el( 'div', 'pos-search' );
		var bar = el( 'div', 'pos-search__form' );
		bar.setAttribute( 'role', 'search' );
		var label = el( 'label', 'pos-search__label', text.label );
		label.setAttribute( 'for', id );
		var input = el( 'input', 'pos-search__input' );
		input.type = 'search';
		input.id = id;
		input.placeholder = text.placeholder || '';
		input.autocomplete = 'off';
		input.setAttribute( 'enterkeyhint', 'search' );
		input.maxLength = 120;
		var button = el( 'button', 'pos-search__button', text.search );
		button.type = 'button';
		bar.appendChild( label );
		bar.appendChild( input );
		bar.appendChild( button );

		var status = el( 'p', 'pos-search__status' );
		status.setAttribute( 'role', 'status' );
		var results = el( 'div', 'pos-search__results' );
		results.setAttribute( 'role', 'region' );
		results.setAttribute( 'aria-label', text.results || '' );
		results.hidden = true;

		root.appendChild( bar );
		root.appendChild( status );
		root.appendChild( results );
		host.appendChild( root );

		function say( message ) {
			status.textContent = message || '';
			status.hidden = ! message;
		}
		say( '' );

		function clear() {
			asked += 1;
			results.textContent = '';
			results.hidden = true;
			say( '' );
			root.classList.remove( 'is-open' );
			if ( 'function' === typeof options.cleared ) {
				options.cleared();
			}
		}

		function row( result, kind ) {
			var item = el( 'li', 'pos-search__item' );
			var choose = el( 'button', 'pos-search__result' );
			choose.type = 'button';
			choose.appendChild( el( 'span', 'pos-search__name', result.name ) );
			if ( result.detail ) {
				choose.appendChild( el( 'span', 'pos-search__detail', result.detail ) );
			}
			choose.addEventListener( 'click', function () {
				Array.prototype.forEach.call( results.querySelectorAll( '.pos-search__result[aria-current]' ), function ( other ) {
					other.removeAttribute( 'aria-current' );
				} );
				choose.setAttribute( 'aria-current', 'true' );
				if ( 'function' === typeof options.choose ) {
					options.choose( result, kind );
				}
				if ( options.collapse ) {
					// The list lies over the map. It gets out of the way of what was chosen.
					results.hidden = true;
					root.classList.remove( 'is-open' );
				}
			} );
			item.appendChild( choose );
			// A Place has a page of its own. A Spot opens in the map's panel, which links to its page.
			if ( 'places' === kind && result.url && false !== options.pages ) {
				var page = el( 'a', 'pos-search__page', text.page );
				page.href = result.url;
				page.setAttribute( 'aria-label', ( text.page || '' ) + ': ' + result.name );
				item.appendChild( page );
			}
			return item;
		}

		function show( data ) {
			results.textContent = '';
			var total = 0;
			KINDS.forEach( function ( kind ) {
				var rows = data[ kind ] || [];
				if ( ! rows.length ) {
					return;
				}
				total += rows.length;
				var group = el( 'section', 'pos-search__group' );
				group.setAttribute( 'data-kind', kind );
				var heading = el( 'h3', 'pos-search__heading', text[ kind ] );
				heading.id = id + '-' + kind;
				var list = el( 'ul', 'pos-search__list' );
				list.setAttribute( 'aria-labelledby', heading.id );
				rows.forEach( function ( result ) {
					list.appendChild( row( result, kind ) );
				} );
				group.appendChild( heading );
				group.appendChild( list );
				if ( 'locations' === kind && data.attribution ) {
					group.appendChild( el( 'p', 'pos-search__credit', data.attribution ) );
				}
				results.appendChild( group );
			} );

			var notes = [];
			if ( false === data.located ) {
				notes.push( text.no_places );
			}
			if ( ! total ) {
				notes.unshift( text.none );
			} else {
				notes.unshift( 1 === total ? text.found_one : ( text.found || '' ).replace( '%d', total ) );
			}
			say( notes.join( ' ' ) );

			var close = el( 'button', 'pos-search__clear', text.clear );
			close.type = 'button';
			close.addEventListener( 'click', function () {
				input.value = '';
				clear();
				input.focus();
			} );
			results.appendChild( close );
			results.hidden = false;
			root.classList.add( 'is-open' );
		}

		function search() {
			var query = input.value.trim().replace( /\s+/g, ' ' );
			if ( query.length < 2 ) {
				results.hidden = true;
				say( text.short );
				return;
			}
			if ( 'function' === typeof options.online && ! options.online() ) {
				say( text.offline );
				return;
			}
			asked += 1;
			var mine = asked;
			var near = 'function' === typeof options.near ? options.near() : null;
			var address = settings.url + ( settings.url.indexOf( '?' ) === -1 ? '?' : '&' ) + 'q=' + encodeURIComponent( query );
			if ( near && isFinite( near[ 0 ] ) && isFinite( near[ 1 ] ) ) {
				address += '&near=' + Number( near[ 0 ] ).toFixed( 4 ) + ',' + Number( near[ 1 ] ).toFixed( 4 );
			}
			say( text.searching );
			button.disabled = true;
			root.setAttribute( 'aria-busy', 'true' );

			var headers = { Accept: 'application/json' };
			var extra = 'function' === typeof options.headers ? options.headers() : options.headers;
			Object.keys( extra || {} ).forEach( function ( name ) {
				headers[ name ] = extra[ name ];
			} );
			window.fetch( address, { credentials: options.credentials || 'omit', headers: headers } )
				.then( function ( response ) {
					if ( 429 === response.status ) {
						throw new Error( 'busy' );
					}
					if ( ! response.ok ) {
						throw new Error( 'failed' );
					}
					return response.json();
				} )
				.then( function ( data ) {
					if ( mine === asked ) {
						show( data || {} );
					}
				} )
				.catch( function ( error ) {
					if ( mine === asked ) {
						results.hidden = true;
						say( error && 'busy' === error.message ? text.busy : text.failed );
					}
				} )
				.then( function () {
					if ( mine === asked ) {
						button.disabled = false;
						root.removeAttribute( 'aria-busy' );
					}
				} );
		}

		button.addEventListener( 'click', search );
		input.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key ) {
				// Inside a form, Enter would send the form.
				event.preventDefault();
				search();
			} else if ( 'Escape' === event.key && ! results.hidden ) {
				event.stopPropagation();
				clear();
			}
		} );
		input.addEventListener( 'search', function () {
			// The browser's own clear button.
			if ( '' === input.value ) {
				clear();
			}
		} );

		return { element: root, input: input, clear: clear, search: search };
	}

	/**
	 * Move a MapLibre map to a result, and mark a location.
	 *
	 * @param {Object} map     MapLibre map.
	 * @param {Object} result  A result: lat, lng, and optionally bbox (west, south, east, north).
	 * @param {Object} holder  An object that keeps the marker between searches.
	 * @param {boolean} mark   Whether to leave a marker.
	 */
	function go( map, result, holder, mark ) {
		if ( ! map || ! result ) {
			return;
		}
		if ( holder && holder.marker ) {
			holder.marker.remove();
			holder.marker = null;
		}
		if ( result.bbox && 4 === result.bbox.length ) {
			map.fitBounds( [ [ result.bbox[ 0 ], result.bbox[ 1 ] ], [ result.bbox[ 2 ], result.bbox[ 3 ] ] ], { padding: 40, maxZoom: 15, duration: 600 } );
		} else {
			map.easeTo( { center: [ result.lng, result.lat ], zoom: Math.max( map.getZoom(), 13 ), duration: 600 } );
		}
		if ( mark && holder && window.maplibregl ) {
			var dot = el( 'span', 'pos-search__marker' );
			dot.setAttribute( 'aria-hidden', 'true' );
			holder.marker = new window.maplibregl.Marker( { element: dot } ).setLngLat( [ result.lng, result.lat ] ).addTo( map );
		}
	}

	window.packoutSearch = { mount: mount, go: go };
} )();
