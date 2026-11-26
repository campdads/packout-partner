/**
 * Pack-Out Spot: My teams and My account.
 *
 * Five conveniences on top of plain forms and plain sections:
 *
 * - A question in a dialog before a change that cannot be undone.
 * - Sections of a page shown one at a time, chosen from tabs.
 * - A row of tabs that scrolls when it does not fit, and fades at the edge
 *   where more of it is out of view.
 * - A filter over the Spot list of a mission.
 * - The time zone of a mission or a campaign, offered once it has a start time.
 *
 * Every form and every section works without this file.
 */
( function () {
	'use strict';

	var words = window.packoutWorkspace || {};

	/* ---------- Asking before a change that cannot be undone ---------- */

	var dialog = null;
	var asking = null;

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text ) {
			node.textContent = text;
		}
		return node;
	}

	function send( button ) {
		var form = button.form;
		if ( ! form ) {
			return;
		}
		button.setAttribute( 'data-pos-confirmed', '1' );
		if ( 'function' === typeof form.requestSubmit ) {
			form.requestSubmit( button );
		} else {
			button.click();
		}
		button.removeAttribute( 'data-pos-confirmed' );
	}

	function build() {
		// The class of the workspace gives the dialog its buttons and its colors.
		dialog = el( 'dialog', 'pos-ws pos-confirm' );
		dialog.setAttribute( 'aria-labelledby', 'pos-confirm-question' );
		dialog.setAttribute( 'aria-describedby', 'pos-confirm-note' );
		var question = el( 'h2', 'pos-confirm__question' );
		question.id = 'pos-confirm-question';
		var note = el( 'p', 'pos-confirm__note' );
		note.id = 'pos-confirm-note';
		var actions = el( 'div', 'pos-confirm__actions' );
		var cancel = el( 'button', 'pos-ws__button pos-confirm__cancel', words.cancel || 'Cancel' );
		cancel.type = 'button';
		var yes = el( 'button', 'pos-ws__button pos-ws__button--primary pos-confirm__yes' );
		yes.type = 'button';
		actions.appendChild( cancel );
		actions.appendChild( yes );
		dialog.appendChild( question );
		dialog.appendChild( note );
		dialog.appendChild( actions );
		document.body.appendChild( dialog );

		cancel.addEventListener( 'click', function () {
			dialog.close();
		} );
		yes.addEventListener( 'click', function () {
			var button = asking;
			dialog.close();
			if ( button ) {
				send( button );
			}
		} );
		// A click on the backdrop is a click on the dialog itself.
		dialog.addEventListener( 'click', function ( event ) {
			if ( event.target === dialog ) {
				dialog.close();
			}
		} );
		// Escape closes the question, and nothing under it.
		dialog.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				event.stopPropagation();
			}
		} );
		dialog.addEventListener( 'close', function () {
			var button = asking;
			asking = null;
			if ( button && document.contains( button ) ) {
				button.focus();
			}
		} );
	}

	function ask( button ) {
		if ( ! dialog ) {
			build();
		}
		asking = button;
		var danger = button.classList.contains( 'pos-ws__button--danger' );
		var note = button.getAttribute( 'data-pos-confirm-note' ) || '';
		dialog.querySelector( '.pos-confirm__question' ).textContent = button.getAttribute( 'data-pos-confirm' );
		dialog.querySelector( '.pos-confirm__note' ).textContent = note;
		dialog.querySelector( '.pos-confirm__note' ).hidden = ! note;
		var yes = dialog.querySelector( '.pos-confirm__yes' );
		yes.textContent = button.getAttribute( 'data-pos-confirm-yes' ) || words.confirm || 'Go ahead';
		yes.classList.toggle( 'pos-confirm__yes--danger', danger );
		dialog.showModal();
		// The safe answer has the focus, so Enter does not go ahead by habit.
		dialog.querySelector( '.pos-confirm__cancel' ).focus();
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '[data-pos-confirm]' ) : null;
		if ( ! button || button.getAttribute( 'data-pos-confirmed' ) ) {
			return;
		}
		if ( 'function' !== typeof window.HTMLDialogElement || 'function' !== typeof document.createElement( 'dialog' ).showModal ) {
			if ( ! window.confirm( button.getAttribute( 'data-pos-confirm' ) ) ) {
				event.preventDefault();
			}
			return;
		}
		event.preventDefault();
		// The form's own rules are checked first, so the question is not asked about a form that cannot be sent.
		if ( button.form && 'function' === typeof button.form.reportValidity && ! button.form.reportValidity() ) {
			return;
		}
		ask( button );
	} );

	/* ---------- Sections chosen from tabs ---------- */

	function inPane( node ) {
		return !! ( node.closest && node.closest( '.packout-drawer__pane' ) );
	}

	function setUp( box ) {
		var nav = box.querySelector( ':scope > .pos-sections__nav' );
		var list = nav ? nav.querySelector( 'ul' ) : null;
		var body = box.querySelector( ':scope > .pos-sections__body' );
		if ( ! nav || ! list || ! body || box.classList.contains( 'is-tabs' ) ) {
			return;
		}
		var tabs = Array.prototype.slice.call( list.querySelectorAll( 'a[href^="#"]' ) );
		var panels = tabs.map( function ( tab ) {
			return document.getElementById( tab.getAttribute( 'href' ).slice( 1 ) );
		} );
		if ( ! tabs.length || panels.some( function ( panel ) {
			return ! panel;
		} ) ) {
			return;
		}

		var side = box.classList.contains( 'pos-sections--side' );
		list.setAttribute( 'role', 'tablist' );
		list.setAttribute( 'aria-label', nav.getAttribute( 'aria-label' ) || '' );
		if ( side ) {
			list.setAttribute( 'aria-orientation', 'vertical' );
		}
		tabs.forEach( function ( tab, index ) {
			tab.parentNode.setAttribute( 'role', 'presentation' );
			tab.setAttribute( 'role', 'tab' );
			tab.id = panels[ index ].id + '-tab';
			tab.setAttribute( 'aria-controls', panels[ index ].id );
			panels[ index ].setAttribute( 'role', 'tabpanel' );
			panels[ index ].setAttribute( 'aria-labelledby', tab.id );
		} );

		function select( index, focus ) {
			tabs.forEach( function ( tab, each ) {
				var on = each === index;
				tab.setAttribute( 'aria-selected', on ? 'true' : 'false' );
				tab.tabIndex = on ? 0 : -1;
				panels[ each ].hidden = ! on;
			} );
			box.setAttribute( 'data-pos-shown', panels[ index ].id );
			if ( focus ) {
				tabs[ index ].focus();
			}
			reveal( list, tabs[ index ] );
			edges( list );
			// A map or a list that was drawn while hidden measures itself again.
			window.dispatchEvent( new window.Event( 'resize' ) );
		}

		/** The section that holds an element, or -1. */
		function holding( node ) {
			for ( var index = 0; index < panels.length; index++ ) {
				if ( node && panels[ index ].contains( node ) ) {
					return index;
				}
			}
			return -1;
		}

		box.posShow = function ( node ) {
			var index = holding( node );
			if ( index >= 0 ) {
				select( index, false );
			}
			return index >= 0;
		};

		tabs.forEach( function ( tab, index ) {
			tab.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				select( index, false );
				// Inside a drawer the address belongs to the page under it.
				if ( ! inPane( box ) && window.history && window.history.replaceState ) {
					window.history.replaceState( null, '', '#' + panels[ index ].id );
				}
			} );
			tab.addEventListener( 'keydown', function ( event ) {
				var next = -1;
				if ( 'ArrowDown' === event.key || 'ArrowRight' === event.key ) {
					next = ( index + 1 ) % tabs.length;
				} else if ( 'ArrowUp' === event.key || 'ArrowLeft' === event.key ) {
					next = ( index - 1 + tabs.length ) % tabs.length;
				} else if ( 'Home' === event.key ) {
					next = 0;
				} else if ( 'End' === event.key ) {
					next = tabs.length - 1;
				} else if ( ' ' === event.key ) {
					// A tab is a link underneath. Space works on it as it does on a button.
					event.preventDefault();
					tab.click();
				}
				if ( next >= 0 ) {
					event.preventDefault();
					select( next, true );
					if ( ! inPane( box ) && window.history && window.history.replaceState ) {
						window.history.replaceState( null, '', '#' + panels[ next ].id );
					}
				}
			} );
		} );

		// The address names a section, or something inside one. Then the form that was refused. Then the first.
		var first = -1;
		if ( ! inPane( box ) && window.location.hash.length > 1 ) {
			var named = null;
			try {
				named = document.getElementById( decodeURIComponent( window.location.hash.slice( 1 ) ) );
			} catch ( error ) {
				named = null;
			}
			first = holding( named );
		}
		if ( first < 0 && box.getAttribute( 'data-pos-open' ) ) {
			first = holding( document.getElementById( box.getAttribute( 'data-pos-open' ) ) );
		}
		box.classList.add( 'is-tabs' );
		watch( list );
		select( first < 0 ? 0 : first, false );
	}

	/** Show every section that holds an element. */
	function showHolder( node ) {
		var shown = false;
		var box = node && node.closest ? node.closest( '.pos-sections.is-tabs' ) : null;
		while ( box ) {
			if ( 'function' === typeof box.posShow && box.posShow( node ) ) {
				shown = true;
			}
			box = box.parentNode && box.parentNode.closest ? box.parentNode.closest( '.pos-sections.is-tabs' ) : null;
		}
		return shown;
	}

	function fromHash() {
		if ( window.location.hash.length < 2 ) {
			return;
		}
		var named = null;
		try {
			named = document.getElementById( decodeURIComponent( window.location.hash.slice( 1 ) ) );
		} catch ( error ) {
			return;
		}
		if ( named && showHolder( named ) && named.scrollIntoView && ! named.classList.contains( 'pos-sections__panel' ) ) {
			named.scrollIntoView( { block: 'start' } );
		}
	}

	// A link anywhere on the page to something inside a section shows that section first.
	document.addEventListener( 'click', function ( event ) {
		var link = event.target.closest ? event.target.closest( 'a[href^="#"]' ) : null;
		if ( ! link || 'tab' === link.getAttribute( 'role' ) || link.getAttribute( 'href' ).length < 2 ) {
			return;
		}
		var named = document.getElementById( link.getAttribute( 'href' ).slice( 1 ) );
		if ( named ) {
			showHolder( named );
		}
	} );
	window.addEventListener( 'hashchange', fromHash );

	/* ---------- A row of tabs that scrolls ---------- */

	/** Mark the edges of a scrolling row where more of it is out of view. */
	function edges( row ) {
		if ( ! row ) {
			return;
		}
		var most = row.scrollWidth - row.clientWidth;
		row.classList.toggle( 'is-clipped-start', row.scrollLeft > 2 );
		row.classList.toggle( 'is-clipped-end', most > 2 && row.scrollLeft < most - 2 );
	}

	/** Bring a tab into view inside its row, without moving the page. */
	function reveal( row, tab ) {
		if ( ! row || ! tab || row.scrollWidth <= row.clientWidth ) {
			return;
		}
		var item = tab.getBoundingClientRect();
		var frame = row.getBoundingClientRect();
		if ( item.left < frame.left + 24 ) {
			row.scrollLeft -= frame.left + 24 - item.left;
		} else if ( item.right > frame.right - 24 ) {
			row.scrollLeft += item.right - ( frame.right - 24 );
		}
	}

	function watch( row ) {
		if ( ! row || row.getAttribute( 'data-pos-scrolls' ) ) {
			return;
		}
		row.setAttribute( 'data-pos-scrolls', '1' );
		row.addEventListener( 'scroll', function () {
			edges( row );
		}, { passive: true } );
		if ( window.ResizeObserver ) {
			new window.ResizeObserver( function () {
				edges( row );
			} ).observe( row );
		} else {
			window.addEventListener( 'resize', function () {
				edges( row );
			} );
		}
		edges( row );
	}

	function start() {
		Array.prototype.forEach.call( document.querySelectorAll( '.pos-ws__tabs > ul' ), function ( row ) {
			watch( row );
			reveal( row, row.querySelector( '[aria-current]' ) );
			edges( row );
		} );
		Array.prototype.forEach.call( document.querySelectorAll( '[data-pos-sections]' ), setUp );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}

	/* ---------- The Spot filter of a mission ---------- */

	document.addEventListener( 'input', function ( event ) {
		var filter = event.target;
		if ( ! filter.classList || ! filter.classList.contains( 'pos-ws__filter' ) ) {
			return;
		}
		var typed = filter.value.trim().toLowerCase();
		var list = filter.parentNode.querySelector( '.pos-ws__list' );
		Array.prototype.forEach.call( list ? list.querySelectorAll( '.pos-ws__item' ) : [], function ( item ) {
			var box = item.querySelector( 'input' );
			// A ticked Spot stays in view, so nothing is sent that the person cannot see.
			item.hidden = '' !== typed && ! box.checked && -1 === item.textContent.toLowerCase().indexOf( typed );
		} );
	} );

	/* ---------- The time zone of a mission or a campaign ---------- */

	// A time zone means something only with a time, so it is offered once a start time is set.
	function zone( times ) {
		var start = times.querySelector( 'input[name="start_time"]' );
		var field = times.querySelector( '[data-pos-zone]' );
		if ( start && field ) {
			field.hidden = '' === start.value;
		}
	}

	function zones() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-pos-times]' ), zone );
	}

	document.addEventListener( 'input', function ( event ) {
		var times = event.target.closest ? event.target.closest( '[data-pos-times]' ) : null;
		if ( times ) {
			zone( times );
		}
	} );

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', zones );
	} else {
		zones();
	}
} )();
