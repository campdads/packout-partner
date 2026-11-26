/**
 * Pack-Out Spot report form helper.
 *
 * Adds a map and a "Use my location" button to the website report form. The
 * map has a search box that narrows it to a town, a road, a Spot, or a Place,
 * and a click on it places the pin. The pin and the button fill the latitude
 * and longitude fields. A photo that says where it was taken fills them too,
 * when they are empty. The photo field offers the
 * camera and the photo library, and says at once when a photo is larger than
 * the form takes. The form works without any of it.
 */
( function () {
	'use strict';

	/** One part of the form, by the CSS selector the page gave for it. */
	function part( form, selector ) {
		if ( ! selector ) {
			return null;
		}
		try {
			return form.querySelector( selector );
		} catch ( error ) {
			return null;
		}
	}

	function fill( latitude, longitude, lat, lng ) {
		latitude.value = Number( lat ).toFixed( 6 );
		longitude.value = Number( lng ).toFixed( 6 );
		[ latitude, longitude ].forEach( function ( node ) {
			node.dispatchEvent( new window.Event( 'input', { bubbles: true } ) );
			node.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );
		} );
	}

	/**
	 * The photo field: offer the camera and the photo library, and say at once when a photo is too large to send.
	 */
	function photoField( form, setup ) {
		var picker = part( form, setup.picture );
		if ( ! picker ) {
			return;
		}
		if ( setup.accept && ! picker.getAttribute( 'accept' ) ) {
			picker.setAttribute( 'accept', setup.accept );
		}
		var limit = Number( setup.limit ) || 0;
		if ( limit < 1 || ! setup.large ) {
			return;
		}
		var note = document.createElement( 'p' );
		note.className = 'pos-report__photo-note';
		note.setAttribute( 'role', 'alert' );
		note.hidden = true;
		picker.parentNode.insertBefore( note, picker.nextSibling );
		picker.addEventListener( 'change', function () {
			var file = picker.files && picker.files[ 0 ];
			if ( ! file || file.size <= limit * 1048576 ) {
				note.hidden = true;
				note.textContent = '';
				return;
			}
			var size = ( Math.ceil( file.size / 104857.6 ) / 10 ).toFixed( 1 );
			note.textContent = setup.large.replace( '%1$s', size ).replace( '%2$s', String( limit ) );
			note.hidden = false;
			// The photo would be refused after a long wait. The rest of the report can still go.
			picker.value = '';
		} );
	}

	/**
	 * The map a pin is placed on. A search narrows it to a town, a road, a Spot, or a Place, and a click places the pin.
	 *
	 * The coordinate fields stay the record: the pin fills them, and typing in them moves the pin.
	 */
	function pinMap( setup, latitude, longitude, before, status ) {
		if ( ! setup.map || ! setup.map.style || ! window.maplibregl ) {
			return;
		}
		var wrap = document.createElement( 'div' );
		wrap.className = 'pos-report__pin';
		var hint = document.createElement( 'p' );
		hint.className = 'pos-report__pin-hint';
		hint.textContent = setup.map.hint;
		var searchHost = document.createElement( 'div' );
		var canvas = document.createElement( 'div' );
		canvas.className = 'pos-report__pin-map';
		canvas.setAttribute( 'role', 'region' );
		canvas.setAttribute( 'aria-label', setup.map.label );
		wrap.appendChild( hint );
		wrap.appendChild( searchHost );
		wrap.appendChild( canvas );
		before.parentNode.insertBefore( wrap, before );

		var map;
		try {
			map = new window.maplibregl.Map( { container: canvas, style: setup.map.style, center: setup.map.center, zoom: setup.map.zoom, attributionControl: { compact: true }, cooperativeGestures: true } );
		} catch ( error ) {
			// A browser without WebGL still has the fields.
			wrap.parentNode.removeChild( wrap );
			return;
		}
		map.addControl( new window.maplibregl.NavigationControl( { showCompass: false } ), 'top-right' );
		var pin = null;
		var found = { marker: null };

		function place( lat, lng ) {
			if ( ! pin ) {
				var dot = document.createElement( 'span' );
				dot.className = 'pos-report__pin-marker';
				dot.setAttribute( 'aria-hidden', 'true' );
				pin = new window.maplibregl.Marker( { element: dot } );
			}
			pin.setLngLat( [ lng, lat ] ).addTo( map );
		}

		/** Follow what the fields say, whoever filled them. */
		function follow( move ) {
			var lat = parseFloat( latitude.value );
			var lng = parseFloat( longitude.value );
			if ( ! isFinite( lat ) || ! isFinite( lng ) || Math.abs( lat ) > 90 || Math.abs( lng ) > 180 || '' === latitude.value.trim() || '' === longitude.value.trim() ) {
				if ( pin ) {
					pin.remove();
				}
				return;
			}
			place( lat, lng );
			if ( move ) {
				map.easeTo( { center: [ lng, lat ], zoom: Math.max( map.getZoom(), 13 ) } );
			}
		}

		var placing = false;
		map.on( 'click', function ( event ) {
			placing = true;
			fill( latitude, longitude, event.lngLat.lat, event.lngLat.lng );
			placing = false;
			place( event.lngLat.lat, event.lngLat.lng );
			status.textContent = setup.map.placed;
		} );
		[ latitude, longitude ].forEach( function ( node ) {
			node.addEventListener( 'change', function () {
				if ( ! placing ) {
					follow( true );
				}
			} );
		} );
		map.on( 'load', function () {
			follow( true );
		} );

		if ( window.packoutSearch ) {
			window.packoutSearch.mount( searchHost, {
				pages: false,
				near: function () {
					var middle = map.getCenter();
					return [ middle.lat, middle.lng ];
				},
				cleared: function () {
					if ( found.marker ) {
						found.marker.remove();
						found.marker = null;
					}
				},
				choose: function ( result, kind ) {
					// The result narrows the map. The person still places the pin.
					window.packoutSearch.go( map, result, found, 'locations' === kind );
				},
			} );
		}
	}

	document.querySelectorAll( '.pos-report__form[data-pos-locate]' ).forEach( function ( form ) {
		var setup = JSON.parse( form.getAttribute( 'data-pos-locate' ) || '{}' );
		photoField( form, setup );
		var latitude = part( form, setup.latitude );
		var longitude = part( form, setup.longitude );
		if ( ! latitude || ! longitude ) {
			return;
		}

		var row = document.createElement( 'p' );
		row.className = 'pos-report__locate';
		var status = document.createElement( 'span' );
		status.setAttribute( 'role', 'status' );
		// The button and the map go above the latitude field: above its wrapper when the form has one.
		var field = ( setup.row && latitude.closest( setup.row ) ) || latitude;
		field.parentNode.insertBefore( row, field );

		if ( window.navigator.geolocation ) {
			var button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'pos-report__locate-button';
			button.textContent = setup.label;
			row.appendChild( button );

			button.addEventListener( 'click', function () {
				button.disabled = true;
				status.textContent = setup.waiting;
				window.navigator.geolocation.getCurrentPosition( function ( position ) {
					fill( latitude, longitude, position.coords.latitude, position.coords.longitude );
					button.disabled = false;
					status.textContent = setup.done;
				}, function () {
					button.disabled = false;
					status.textContent = setup.failed;
				}, { enableHighAccuracy: true, timeout: 20000, maximumAge: 10000 } );
			} );
		}
		row.appendChild( status );
		pinMap( setup, latitude, longitude, row, status );

		// A photo can say where it was taken. It fills the coordinates only when the person has given none.
		form.addEventListener( 'change', function ( event ) {
			var picker = event.target;
			if ( ! window.packoutExif || ! picker || picker.type !== 'file' || ! picker.files || ! picker.files.length || latitude.value.trim() || longitude.value.trim() ) {
				return;
			}
			window.packoutExif.first( picker.files ).then( function ( taken ) {
				if ( taken && ! latitude.value.trim() && ! longitude.value.trim() ) {
					fill( latitude, longitude, taken.lat, taken.lng );
					status.textContent = setup.photo;
				}
			} );
		} );
	} );
} )();
