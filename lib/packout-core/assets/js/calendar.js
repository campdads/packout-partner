/**
 * The calendar block, once the page has loaded.
 *
 * The server draws every view in full, and every control is a link or a form
 * that works on its own. This saves the page load: it asks the calendar route
 * for the view a control names, puts the answer in place of what was shown,
 * and keeps the address in step so a view can be linked and Back works. It
 * also lets the month be walked with the keyboard, and adds the time on the
 * reader's own clock to an event in another time zone.
 */
import * as interactivity from '@wordpress/interactivity';

const { store, getContext, getElement } = interactivity;

// An action that stops a link or a key has to run at once. WordPress 6.8 and later say so with withSyncEvent.
const now = interactivity.withSyncEvent || ( ( action ) => action );

const SWITCHABLE = [ 'day', 'week', 'month', 'agenda' ];

// The request each calendar last made, so a slow answer never replaces a newer one.
const asked = new WeakMap();

function region( root ) {
	return root.querySelector( '.pos-cal__region' );
}

/* ---------- Days ---------- */

function parse( day ) {
	const parts = String( day ).split( '-' ).map( Number );
	return new Date( Date.UTC( parts[ 0 ], parts[ 1 ] - 1, parts[ 2 ] ) );
}

function format( date ) {
	return date.toISOString().slice( 0, 10 );
}

function shift( day, days ) {
	const date = parse( day );
	date.setUTCDate( date.getUTCDate() + days );
	return format( date );
}

// The same day of the month before or the month after, or that month's last day when it is shorter.
function shiftMonth( day, way ) {
	const date = parse( day );
	const first = new Date( Date.UTC( date.getUTCFullYear(), date.getUTCMonth() + way, 1 ) );
	const last = new Date( Date.UTC( first.getUTCFullYear(), first.getUTCMonth() + 1, 0 ) ).getUTCDate();
	first.setUTCDate( Math.min( date.getUTCDate(), last ) );
	return format( first );
}

/* ---------- The reader's own clock ---------- */

// An event keeps the time of the place it happens. A reader somewhere else is also told the time where they are.
function localTimes( root, text ) {
	root.querySelectorAll( 'time[data-cal-offset]' ).forEach( ( time ) => {
		if ( time.dataset.calLocal ) {
			return;
		}
		time.dataset.calLocal = '1';
		const at = new Date( time.getAttribute( 'datetime' ) );
		if ( Number.isNaN( at.getTime() ) || -at.getTimezoneOffset() * 60 === Number( time.dataset.calOffset ) ) {
			return;
		}
		const own = at.toLocaleTimeString( [], { hour: 'numeric', minute: '2-digit' } );
		const note = document.createElement( 'span' );
		note.className = 'pos-cal__local';
		note.textContent = ' (' + String( text || '%s' ).replace( '%s', own ) + ')';
		time.after( note );
	} );
}

/* ---------- Showing another view ---------- */

// The address of the page showing a view, which is what a link to it would be.
function pageUrl( context, view, date ) {
	const url = new URL( context.base || window.location.href, window.location.href );
	url.searchParams.set( 'cal-view', view );
	url.searchParams.set( 'cal-date', date );
	[ [ 'cal-cat', context.category ], [ 'cal-org', context.org ] ].forEach( ( [ name, value ] ) => {
		if ( value && '0' !== String( value ) ) {
			url.searchParams.set( name, value );
		} else {
			url.searchParams.delete( name );
		}
	} );
	return url.toString();
}

/**
 * Ask for a view and put it in place of what is shown.
 *
 * @param {HTMLElement} root    The block.
 * @param {Object}      context The block's context.
 * @param {string}      view    The form to show.
 * @param {string}      date    The day to show.
 * @param {Object}      options `push` to add the view to the history, `focus` as a selector to focus afterward,
 *                              `fallback` as an address to load when the request fails.
 */
function show( root, context, view, date, options ) {
	const url = new URL( context.endpoint, window.location.href );
	Object.keys( context.params || {} ).forEach( ( name ) => url.searchParams.set( name, context.params[ name ] ) );
	url.searchParams.set( 'view', view );
	url.searchParams.set( 'date', date );
	url.searchParams.set( 'html', '1' );
	url.searchParams.set( 'base', context.base || '' );
	if ( context.category ) {
		url.searchParams.set( 'category', context.category );
	}
	if ( context.org && '0' !== String( context.org ) ) {
		url.searchParams.set( 'organization', context.org );
	}

	const request = {};
	asked.set( root, request );
	root.setAttribute( 'aria-busy', 'true' );

	return window
		.fetch( url.toString(), {
			credentials: 'same-origin',
			headers: context.nonce ? { 'X-WP-Nonce': context.nonce } : {},
		} )
		.then( ( response ) => {
			if ( ! response.ok ) {
				throw new Error( String( response.status ) );
			}
			return response.json();
		} )
		.then( ( answer ) => {
			if ( asked.get( root ) !== request ) {
				return;
			}
			if ( 'string' !== typeof answer.html ) {
				throw new Error( 'empty' );
			}
			root.removeAttribute( 'aria-busy' );
			region( root ).innerHTML = answer.html;
			context.view = view;
			context.date = date;
			SWITCHABLE.forEach( ( name ) => root.classList.toggle( 'pos-cal--' + name, name === view ) );
			localTimes( root, context.local );

			if ( options.push ) {
				// WordPress marks the history entry of a page it drew, and reloads on Back to an entry
				// without its mark. What is already in the entry is kept, so Back stays on this page.
				window.history.pushState( Object.assign( {}, window.history.state, { packoutCalendar: true } ), '', pageUrl( context, view, date ) );
			}
			const body = root.querySelector( '.pos-cal__body' );
			const status = root.querySelector( '.pos-cal__status' );
			if ( status && body ) {
				status.textContent = body.dataset.calTitle || '';
			}
			const target = options.focus ? root.querySelector( options.focus ) : null;
			if ( target ) {
				if ( target.matches( '[data-cal-day]' ) ) {
					hold( root, target );
				}
				target.focus();
			}
		} )
		.catch( () => {
			if ( asked.get( root ) !== request ) {
				return;
			}
			root.removeAttribute( 'aria-busy' );
			// The page itself can still show the view.
			if ( options.fallback ) {
				window.location.assign( options.fallback );
			}
		} );
}

// Where the keyboard should be after a control replaced itself.
function focusAfter( link ) {
	if ( link.matches( '[data-cal-day]' ) ) {
		return '.pos-cal__title';
	}
	if ( link.closest( '.pos-cal__views' ) ) {
		return '.pos-cal__views a[data-cal-view="' + link.dataset.calView + '"]';
	}
	if ( link.closest( '.pos-cal__nav' ) ) {
		const rel = link.getAttribute( 'rel' );
		return rel ? '.pos-cal__nav a[rel="' + rel + '"]' : '.pos-cal__nav a:not([rel])';
	}
	return '.pos-cal__title';
}

/* ---------- The month, by keyboard ---------- */

// One day of the month is in the tab order at a time: the one the keyboard is on.
function hold( root, link ) {
	root.querySelectorAll( 'a[data-cal-day][tabindex="0"]' ).forEach( ( other ) => other.setAttribute( 'tabindex', '-1' ) );
	link.setAttribute( 'tabindex', '0' );
}

function dayKey( event, root, context, link ) {
	const day = link.dataset.calDay;
	const row = link.closest( 'tr' );
	const inRow = row ? row.querySelectorAll( 'a[data-cal-day]' ) : [];
	let target = '';
	switch ( event.key ) {
		case 'ArrowLeft':
			target = shift( day, -1 );
			break;
		case 'ArrowRight':
			target = shift( day, 1 );
			break;
		case 'ArrowUp':
			target = shift( day, -7 );
			break;
		case 'ArrowDown':
			target = shift( day, 7 );
			break;
		case 'Home':
			target = inRow.length ? inRow[ 0 ].dataset.calDay : '';
			break;
		case 'End':
			target = inRow.length ? inRow[ inRow.length - 1 ].dataset.calDay : '';
			break;
		case 'PageUp':
			target = shiftMonth( day, -1 );
			break;
		case 'PageDown':
			target = shiftMonth( day, 1 );
			break;
		default:
			return;
	}
	if ( ! target ) {
		return;
	}
	event.preventDefault();
	const selector = 'a[data-cal-day="' + target + '"]';
	const changesMonth = 'PageUp' === event.key || 'PageDown' === event.key;
	const there = changesMonth ? null : root.querySelector( '.pos-cal__month ' + selector );
	if ( there ) {
		hold( root, there );
		there.focus();
		return;
	}
	show( root, context, 'month', target, { push: true, focus: '.pos-cal__month ' + selector, fallback: pageUrl( context, 'month', target ) } );
}

/* ---------- The block ---------- */

store( 'packout/calendar', {
	actions: {
		// A link that moves the calendar: another day, another form, "N more".
		click: now( ( event ) => {
			const root = getElement().ref;
			const context = getContext();
			const link = event.target && event.target.closest ? event.target.closest( 'a[data-cal-go]' ) : null;
			if ( ! root || ! link || ! root.contains( link ) || event.defaultPrevented ) {
				return;
			}
			// A click that asks for a new tab or window is left to do that.
			if ( 0 !== event.button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
				return;
			}
			event.preventDefault();
			show( root, context, link.dataset.calView, link.dataset.calDate, { push: true, focus: focusAfter( link ), fallback: link.href } );
		} ),

		key: now( ( event ) => {
			const root = getElement().ref;
			const context = getContext();
			const link = event.target && event.target.closest ? event.target.closest( '.pos-cal__month a[data-cal-day]' ) : null;
			if ( root && link && root.contains( link ) && ! event.metaKey && ! event.ctrlKey && ! event.altKey ) {
				dayKey( event, root, context, link );
			}
		} ),

		// A filter was changed.
		change: ( event ) => {
			const root = getElement().ref;
			const context = getContext();
			const field = event.target;
			if ( ! root || ! field || ! field.matches || ! field.matches( '.pos-cal__filters select' ) ) {
				return;
			}
			if ( 'cal-cat' === field.name ) {
				context.category = field.value;
			} else if ( 'cal-org' === field.name ) {
				context.org = Number( field.value ) || 0;
			}
			show( root, context, context.view, context.date, {
				push: true,
				focus: '.pos-cal__filters select[name="' + field.name + '"]',
				fallback: pageUrl( context, context.view, context.date ),
			} );
		},
	},
	callbacks: {
		start: () => {
			const root = getElement().ref;
			const context = getContext();
			if ( ! root ) {
				return;
			}
			localTimes( root, context.local );
			if ( ! context.endpoint ) {
				return;
			}
			// The filters apply as they are changed, so their button is not needed.
			root.classList.add( 'is-live' );

			// Back and Forward show the view the address names, or the one the page started on.
			const back = () => {
				const query = new URLSearchParams( window.location.search );
				const named = query.get( 'cal-view' );
				const view = context.switches && SWITCHABLE.includes( named ) ? named : context.home.view;
				const date = /^\d{4}-\d{2}-\d{2}$/.test( query.get( 'cal-date' ) || '' ) ? query.get( 'cal-date' ) : context.home.date;
				context.category = context.fixed.includes( 'category' ) ? '' : query.get( 'cal-cat' ) || '';
				context.org = context.fixed.includes( 'organization' ) ? 0 : Number( query.get( 'cal-org' ) ) || 0;
				show( root, context, view, date, { push: false, fallback: '' } );
			};
			window.addEventListener( 'popstate', back );
			return () => window.removeEventListener( 'popstate', back );
		},
	},
} );
