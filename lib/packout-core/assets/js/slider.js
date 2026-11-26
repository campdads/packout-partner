/**
 * The photo slider of a Spot: on its page, and at the top of its card on the map.
 *
 * The page arrives with the photos in a row that scrolls sideways and snaps,
 * so it works by touch and by keyboard before this script runs. The script
 * adds the arrows, the count, the row of thumbnails, and the larger view.
 */
( function () {
	'use strict';

	var text = Object.assign(
		{
			label: 'Photos',
			previous: 'Previous photo',
			next: 'Next photo',
			count: 'Photo %1$s of %2$s',
			short: '%1$s / %2$s',
			show: 'Show photo %s',
			zoom: 'See the photo larger',
			close: 'Close',
			alt: 'Photo %1$s of %2$s from the report',
		},
		window.packoutSliderText || {}
	);

	function el( tag, className, content ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( content ) {
			node.textContent = content;
		}
		return node;
	}

	function fill( pattern, first, second ) {
		return String( pattern ).replace( '%1$s', first ).replace( '%2$s', second ).replace( '%s', first );
	}

	function arrow( direction, label ) {
		var button = el( 'button', 'pos-slider__arrow pos-slider__arrow--' + direction );
		button.type = 'button';
		button.setAttribute( 'aria-label', label );
		button.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="' + ( direction === 'previous' ? 'M15 5l-7 7 7 7' : 'M9 5l7 7-7 7' ) + '" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
		return button;
	}

	/** The larger view: one for the page, made the first time it is asked for. */
	var viewer = null;

	function openViewer( photos, index, opener ) {
		if ( ! window.HTMLDialogElement ) {
			window.open( photos[ index ].full, '_blank', 'noopener' );
			return;
		}
		if ( ! viewer ) {
			viewer = { dialog: el( 'dialog', 'pos-viewer' ) };
			viewer.dialog.setAttribute( 'aria-label', text.label );
			viewer.image = el( 'img', 'pos-viewer__image' );
			viewer.count = el( 'p', 'pos-viewer__count' );
			viewer.count.setAttribute( 'aria-live', 'polite' );
			viewer.close = el( 'button', 'pos-viewer__close', text.close );
			viewer.close.type = 'button';
			viewer.previous = arrow( 'previous', text.previous );
			viewer.next = arrow( 'next', text.next );
			[ viewer.close, viewer.image, viewer.previous, viewer.next, viewer.count ].forEach( function ( node ) {
				viewer.dialog.appendChild( node );
			} );
			document.body.appendChild( viewer.dialog );

			viewer.show = function ( to ) {
				var all = viewer.photos;
				viewer.index = ( to + all.length ) % all.length;
				viewer.image.src = all[ viewer.index ].full;
				viewer.image.alt = all[ viewer.index ].alt || fill( text.alt, viewer.index + 1, all.length );
				viewer.count.textContent = fill( text.count, viewer.index + 1, all.length );
				viewer.previous.hidden = viewer.next.hidden = all.length < 2;
			};
			viewer.close.addEventListener( 'click', function () {
				viewer.dialog.close();
			} );
			viewer.previous.addEventListener( 'click', function () {
				viewer.show( viewer.index - 1 );
			} );
			viewer.next.addEventListener( 'click', function () {
				viewer.show( viewer.index + 1 );
			} );
			viewer.dialog.addEventListener( 'click', function ( event ) {
				// A click on the dark surround, not on the photo or a button.
				if ( event.target === viewer.dialog ) {
					viewer.dialog.close();
				}
			} );
			viewer.dialog.addEventListener( 'keydown', function ( event ) {
				if ( event.key === 'ArrowLeft' ) {
					viewer.show( viewer.index - 1 );
				} else if ( event.key === 'ArrowRight' ) {
					viewer.show( viewer.index + 1 );
				}
			} );
			viewer.dialog.addEventListener( 'close', function () {
				document.documentElement.classList.remove( 'pos-viewer-open' );
				if ( viewer.opener && viewer.opener.focus ) {
					viewer.opener.focus();
				}
			} );
		}
		viewer.photos = photos;
		viewer.opener = opener;
		viewer.show( index );
		document.documentElement.classList.add( 'pos-viewer-open' );
		viewer.dialog.showModal();
		viewer.close.focus();
	}

	/** Turn a row of photos into a slider. Safe to call twice. */
	function mount( root ) {
		if ( ! root || root.getAttribute( 'data-pos-slider' ) === 'ready' ) {
			return;
		}
		var track = root.querySelector( '.pos-slider__track' );
		var slides = track ? Array.prototype.slice.call( track.querySelectorAll( '.pos-slider__slide' ) ) : [];
		if ( ! slides.length ) {
			return;
		}
		root.setAttribute( 'data-pos-slider', 'ready' );
		var photos = slides.map( function ( slide ) {
			var link = slide.querySelector( 'a' );
			var image = slide.querySelector( 'img' );
			return { full: link ? link.href : image.src, thumb: image.getAttribute( 'data-thumb' ) || image.src, alt: image.alt };
		} );
		var current = 0;
		var count = null;
		var previous = null;
		var next = null;
		var thumbs = [];

		function show( index ) {
			current = Math.max( 0, Math.min( slides.length - 1, index ) );
			if ( count ) {
				count.textContent = fill( text.short, current + 1, slides.length );
				count.setAttribute( 'aria-label', fill( text.count, current + 1, slides.length ) );
			}
			if ( previous ) {
				previous.disabled = current === 0;
				next.disabled = current === slides.length - 1;
			}
			thumbs.forEach( function ( thumb, at ) {
				thumb.classList.toggle( 'is-current', at === current );
				if ( at === current ) {
					thumb.setAttribute( 'aria-current', 'true' );
				} else {
					thumb.removeAttribute( 'aria-current' );
				}
			} );
		}

		function go( index ) {
			var to = Math.max( 0, Math.min( slides.length - 1, index ) );
			track.scrollTo( { left: slides[ to ].offsetLeft - track.offsetLeft, behavior: window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ? 'auto' : 'smooth' } );
			show( to );
		}

		slides.forEach( function ( slide, index ) {
			var link = slide.querySelector( 'a' );
			if ( link ) {
				link.setAttribute( 'aria-label', text.zoom );
				link.addEventListener( 'click', function ( event ) {
					event.preventDefault();
					openViewer( photos, index, link );
				} );
			}
		} );

		if ( slides.length > 1 ) {
			previous = arrow( 'previous', text.previous );
			next = arrow( 'next', text.next );
			count = el( 'p', 'pos-slider__count' );
			previous.addEventListener( 'click', function () {
				go( current - 1 );
			} );
			next.addEventListener( 'click', function () {
				go( current + 1 );
			} );
			var stage = root.querySelector( '.pos-slider__stage' ) || root;
			stage.appendChild( previous );
			stage.appendChild( next );
			stage.appendChild( count );

			if ( root.hasAttribute( 'data-pos-thumbs' ) ) {
				var row = el( 'div', 'pos-slider__thumbs' );
				photos.forEach( function ( photo, index ) {
					var thumb = el( 'button', 'pos-slider__thumb' );
					thumb.type = 'button';
					thumb.setAttribute( 'aria-label', fill( text.show, index + 1 ) );
					var image = el( 'img' );
					image.src = photo.thumb;
					image.alt = '';
					image.loading = 'lazy';
					thumb.appendChild( image );
					thumb.addEventListener( 'click', function () {
						go( index );
					} );
					row.appendChild( thumb );
					thumbs.push( thumb );
				} );
				root.appendChild( row );
			}

			var waiting = false;
			track.addEventListener( 'scroll', function () {
				if ( waiting ) {
					return;
				}
				waiting = true;
				window.requestAnimationFrame( function () {
					waiting = false;
					var width = track.clientWidth || 1;
					show( Math.round( track.scrollLeft / width ) );
				} );
			}, { passive: true } );
			track.addEventListener( 'keydown', function ( event ) {
				if ( event.key === 'ArrowLeft' ) {
					event.preventDefault();
					go( current - 1 );
				} else if ( event.key === 'ArrowRight' ) {
					event.preventDefault();
					go( current + 1 );
				}
			} );
		}
		show( 0 );
	}

	/**
	 * Make a slider from a list of photos, for a card that is built in the page.
	 *
	 * @param {Array}  photos  Each with url, large, and full, and optionally alt and caption.
	 * @param {Object} options label, and thumbs to add the row of thumbnails.
	 */
	function build( photos, options ) {
		options = options || {};
		var root = el( 'div', 'pos-slider' + ( options.className ? ' ' + options.className : '' ) );
		root.setAttribute( 'role', 'group' );
		root.setAttribute( 'aria-roledescription', 'carousel' );
		root.setAttribute( 'aria-label', options.label || text.label );
		if ( options.thumbs ) {
			root.setAttribute( 'data-pos-thumbs', '' );
		}
		var stage = el( 'div', 'pos-slider__stage' );
		var track = el( 'div', 'pos-slider__track' );
		track.tabIndex = 0;
		photos.forEach( function ( photo, index ) {
			var slide = el( 'figure', 'pos-slider__slide' );
			var link = el( 'a' );
			link.href = photo.full;
			var image = el( 'img' );
			image.src = options.small ? photo.url : photo.large;
			image.setAttribute( 'data-thumb', photo.url );
			image.alt = photo.alt || fill( text.alt, index + 1, photos.length );
			image.loading = index ? 'lazy' : 'eager';
			image.decoding = 'async';
			link.appendChild( image );
			slide.appendChild( link );
			if ( photo.caption ) {
				// Which photo this is, when it is not one from the report: after the cleanup, or a return visit.
				slide.appendChild( el( 'figcaption', 'pos-slider__caption', photo.caption ) );
			}
			track.appendChild( slide );
		} );
		stage.appendChild( track );
		root.appendChild( stage );
		mount( root );
		return root;
	}

	window.packoutSlider = { mount: mount, build: build };

	function ready() {
		Array.prototype.forEach.call( document.querySelectorAll( '.pos-slider' ), mount );
	}
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', ready );
	} else {
		ready();
	}
}() );
