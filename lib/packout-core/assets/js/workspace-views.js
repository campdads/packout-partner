/**
 * Pack-Out Spot: the lists of My teams, and the drawer beside them.
 *
 * Each list arrives as plain markup with the configuration of a Data
 * View beside it. This file mounts the Data View and takes the plain list
 * away once the view has drawn itself. Three buttons above each list switch
 * its layout: a table, cards, or a list. The choice is kept in the person's
 * browser and used the next time.
 *
 * A link to `#pos-pane-…` opens that pane in the drawer. Without
 * the drawer script the panes stay on the page and the link jumps to one.
 */
( function () {
	'use strict';

	var LAYOUTS = [ 'table', 'grid', 'list' ];
	var PREFIX = 'packout-view:';

	function remembered( key ) {
		try {
			var layout = window.localStorage.getItem( PREFIX + key );
			return -1 !== LAYOUTS.indexOf( layout ) ? layout : '';
		} catch ( error ) {
			return '';
		}
	}

	function remember( key, layout ) {
		try {
			window.localStorage.setItem( PREFIX + key, layout );
		} catch ( error ) {
			// A browser that keeps nothing still shows the list.
		}
	}

	/**
	 * What a Data View is given for one layout.
	 *
	 * A table has a column for every value. Cards and the list keep the
	 * title, the picture, and the line under the title apart from the rest.
	 */
	function configFor( base, setup, layout ) {
		var view = Object.assign( {}, base.view, { type: layout } );
		if ( 'table' === layout ) {
			view.fields = setup.table.slice();
			view.showMedia = false;
			view.showDescription = false;
		}
		return Object.assign( {}, base, { view: view } );
	}

	function mountView( view ) {
		var runtime = window.packoutLists;
		var mount = view.querySelector( '.pos-view__mount' );
		var plain = view.querySelector( '.pos-view__plain' );
		var key = view.getAttribute( 'data-pos-view' ) || '';
		if ( ! runtime || 'function' !== typeof runtime.render || ! mount ) {
			return;
		}

		var base;
		var setup;
		try {
			base = JSON.parse( mount.getAttribute( 'data-config' ) || '' );
			setup = JSON.parse( view.getAttribute( 'data-pos-setup' ) || '' );
		} catch ( error ) {
			return;
		}
		var layout = remembered( key ) || setup.layout;
		if ( ! remembered( key ) && 'table' === layout && window.matchMedia && window.matchMedia( '(max-width: 640px)' ).matches ) {
			// A table is wider than a phone. A person who has not chosen gets the list there.
			layout = 'list';
		}

		// The buttons that switch the layout.
		var bar = document.createElement( 'div' );
		bar.className = 'pos-view__layouts';
		bar.setAttribute( 'role', 'group' );
		bar.setAttribute( 'aria-label', setup.choose );
		var buttons = {};
		LAYOUTS.forEach( function ( name ) {
			var button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'pos-view__layout';
			button.setAttribute( 'data-pos-layout', name );
			button.textContent = setup.labels[ name ];
			button.addEventListener( 'click', function () {
				if ( name !== layout ) {
					remember( key, name );
					show( name );
				}
			} );
			buttons[ name ] = button;
			bar.appendChild( button );
		} );

		function show( name ) {
			layout = name;
			LAYOUTS.forEach( function ( each ) {
				buttons[ each ].setAttribute( 'aria-pressed', each === name ? 'true' : 'false' );
			} );
			view.setAttribute( 'data-pos-layout', name );
			runtime.render( mount, configFor( base, setup, name ) );
		}

		// The plain list stays until the view has drawn its rows.
		var watch = new window.MutationObserver( function () {
			if ( ! mount.querySelector( '.dataviews-view-table, .dataviews-view-grid, .dataviews-view-list' ) ) {
				return;
			}
			watch.disconnect();
			mount.hidden = false;
			view.insertBefore( bar, mount );
			if ( plain && plain.parentNode ) {
				plain.parentNode.removeChild( plain );
			}
			view.classList.add( 'is-mounted' );
		} );
		watch.observe( mount, { childList: true, subtree: true } );

		try {
			show( layout );
		} catch ( error ) {
			watch.disconnect();
		}
	}

	function bindDrawer() {
		var holder = document.querySelector( '.pos-ws__panes--drawer' );
		var drawer = document.querySelector( '.pos-drawer' );
		if ( ! holder || ! drawer || ! window.packoutDrawer ) {
			if ( holder ) {
				// No drawer to open them in, so the panes stay on the page.
				holder.classList.remove( 'pos-ws__panes--drawer' );
			}
			return;
		}

		// The drawer covers the window, so it sits on the body, clear of any section that clips or moves.
		var body = drawer.querySelector( '.packout-drawer__body' );
		Array.prototype.forEach.call( holder.querySelectorAll( '.packout-drawer__pane' ), function ( pane ) {
			pane.hidden = true;
			body.appendChild( pane );
		} );
		document.body.appendChild( drawer );

		var api = window.packoutDrawer.bind( document.body, {
			drawer: drawer,
			// A question asked over the drawer is what Escape closes.
			ownsEscape: function () {
				return !! document.querySelector( 'dialog.pos-confirm[open]' );
			},
		} );
		if ( ! api ) {
			return;
		}

		function open( id ) {
			var pane = drawer.querySelector( '.packout-drawer__pane[data-pane="' + String( id ).replace( /"/g, '' ) + '"]' );
			if ( ! pane ) {
				return false;
			}
			api.open( id, pane.getAttribute( 'data-pos-label' ) || '' );
			return true;
		}

		document.addEventListener( 'click', function ( event ) {
			var link = event.target.closest ? event.target.closest( 'a[href^="#pos-pane-"]' ) : null;
			if ( ! link || event.defaultPrevented ) {
				return;
			}
			if ( open( link.getAttribute( 'href' ).slice( '#pos-pane-'.length ) ) ) {
				event.preventDefault();
			}
		} );

		// A form that was refused comes back with its pane open. So does an address that names a pane.
		var first = holder.getAttribute( 'data-pos-reopen' ) || ( 0 === window.location.hash.indexOf( '#pos-pane-' ) ? window.location.hash.slice( '#pos-pane-'.length ) : '' );
		if ( first ) {
			open( first );
		}
	}

	function start() {
		Array.prototype.forEach.call( document.querySelectorAll( '.pos-view' ), mountView );
		bindDrawer();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
