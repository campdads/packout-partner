/**
 * The drawer: one pane at a time, over the page.
 *
 * Markup:
 *
 *   .packout-drawer[hidden]
 *     [data-packout-close]            the backdrop
 *     .packout-drawer__panel
 *       .packout-drawer__bar
 *         .packout-drawer__title
 *         [data-packout-close]
 *       .packout-drawer__body
 *         .packout-drawer__pane[data-pane][hidden]
 *
 * A control with `data-packout-open="<pane>"` opens that pane, and its
 * `data-packout-label` is the title. `window.packoutDrawer.bind( root )`
 * returns `{ open, close, current, drawer }`, or null when the drawer is
 * missing or already bound.
 */
( function ( window, document ) {
	'use strict';

	/** How long the panel takes to slide away, in milliseconds. The styles use the same figure. */
	var SLIDE = 200;

	function clean( value ) {
		return String( value || '' ).replace( /"/g, '' );
	}

	function bind( root, options ) {
		options = options || {};
		var drawer = options.drawer || ( root && root.querySelector( '.packout-drawer' ) );
		if ( ! root || ! drawer || '1' === drawer.getAttribute( 'data-packout-bound' ) ) {
			return null;
		}
		drawer.setAttribute( 'data-packout-bound', '1' );

		var title = drawer.querySelector( '.packout-drawer__title' );
		var shown = null;
		var opener = null;
		var timer = 0;

		function paneOf( id ) {
			return drawer.querySelector( '.packout-drawer__pane[data-pane="' + clean( id ) + '"]' );
		}

		function focusClose() {
			var button = drawer.querySelector( '.packout-drawer__bar [data-packout-close]' );
			if ( button ) {
				button.focus( { preventScroll: true } );
			}
		}

		function open( id, label ) {
			var pane = paneOf( id );
			if ( ! pane ) {
				return;
			}
			window.clearTimeout( timer );
			if ( shown && shown !== pane ) {
				shown.hidden = true;
			}
			if ( ! shown ) {
				// Where focus returns to when the drawer closes.
				opener = document.activeElement;
			}
			shown = pane;
			pane.hidden = false;
			if ( title ) {
				title.textContent = label || '';
			}
			drawer.hidden = false;
			// Read the layout once so the slide starts from the closed position.
			void drawer.offsetWidth;
			window.requestAnimationFrame( function () {
				window.requestAnimationFrame( function () {
					drawer.classList.add( 'is-open' );
					if ( 'function' === typeof options.onOpen ) {
						options.onOpen( pane );
					}
					focusClose();
				} );
			} );
		}

		function close() {
			if ( drawer.hidden && ! drawer.classList.contains( 'is-open' ) ) {
				return;
			}
			if ( 'function' === typeof options.onClose ) {
				options.onClose( shown );
			}
			drawer.classList.remove( 'is-open' );
			timer = window.setTimeout( function () {
				drawer.hidden = true;
				if ( shown ) {
					shown.hidden = true;
					shown = null;
				}
			}, SLIDE );
			if ( opener && 'function' === typeof opener.focus ) {
				opener.focus( { preventScroll: true } );
			}
			opener = null;
		}

		root.addEventListener( 'click', function ( event ) {
			var target = event.target;
			if ( ! target || ! target.closest ) {
				return;
			}
			var control = target.closest( '[data-packout-open]' );
			if ( control && root.contains( control ) ) {
				open( control.getAttribute( 'data-packout-open' ), control.getAttribute( 'data-packout-label' ) );
				return;
			}
			if ( target.closest( '[data-packout-close]' ) && drawer.contains( target ) ) {
				close();
			}
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' !== event.key || ! drawer.classList.contains( 'is-open' ) ) {
				return;
			}
			// Something opened over the drawer answers Escape first.
			if ( 'function' === typeof options.ownsEscape && options.ownsEscape( event ) ) {
				return;
			}
			close();
		} );

		return {
			open: open,
			close: close,
			current: function () {
				return shown;
			},
			drawer: drawer,
		};
	}

	window.packoutDrawer = { bind: bind };
} )( window, document );
