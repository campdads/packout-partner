/**
 * Shared MapLibre editor for Place outlines and map features.
 *
 *     packoutMapEditor.mount( box, { mode: 'outline', textarea, count } )
 *     packoutMapEditor.open( { feature: payload | null } )
 */
( function () {
	'use strict';

	var settings = window.packoutMapEditor || {};

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

	function pageCamera() {
		var host = document.querySelector( '.pos-map__canvas' );
		var other = host && host._packoutMap;
		if ( other && typeof other.getCenter === 'function' ) {
			var at = other.getCenter();
			return { center: [ at.lng, at.lat ], zoom: other.getZoom() };
		}
		return { center: settings.center, zoom: settings.zoom };
	}

	/** A labelled search field. Typing calls `onChange`. */
	function searchField( label, onChange ) {
		var wrap = el( 'label', 'pos-edit__icon-group pos-edit__icon-find' );
		wrap.appendChild( el( 'span', '', label ) );
		var input = el( 'input', 'pos-edit__icon-search' );
		input.type = 'search';
		input.autocomplete = 'off';
		input.addEventListener( 'input', function () {
			onChange( input.value );
		} );
		// Enter belongs to the form around the editor. Here it only keeps the list as typed.
		input.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key ) {
				event.preventDefault();
			}
		} );
		wrap.appendChild( input );
		return { wrap: wrap, input: input };
	}

	function iconSlug( value ) {
		var picker = settings.iconPicker || {};
		var aliases = picker.aliases || {};
		var legacy = ( picker.library && picker.library.legacy ) || {};
		var slug = String( value || '' );
		return aliases[ slug ] || legacy[ slug ] || slug;
	}

	function iconGlyph( value ) {
		var picker = settings.iconPicker || {};
		var markup = picker.markup || {};
		var resolved = iconSlug( value );
		var span = el( 'span', 'pos-edit__glyph' );
		span.setAttribute( 'aria-hidden', 'true' );
		if ( markup[ resolved ] ) {
			span.innerHTML = markup[ resolved ];
		}
		return span;
	}

	function colorPalette( value, onChange ) {
		var palette = settings.palette && settings.palette.length ? settings.palette : ( settings.colors || [] ).map( function ( hex ) {
			return { name: hex, color: hex };
		} );
		var wrap = el( 'div', 'pos-edit__swatches' );
		wrap.setAttribute( 'role', 'listbox' );
		wrap.setAttribute( 'aria-label', ( settings.text || {} ).color || '' );
		var hidden = el( 'input' );
		hidden.type = 'hidden';
		hidden.value = value || ( palette[ 0 ] && palette[ 0 ].color ) || '#415840';
		wrap.appendChild( hidden );

		function paint() {
			Array.prototype.slice.call( wrap.querySelectorAll( '.pos-edit__swatch' ) ).forEach( function ( button ) {
				button.remove();
			} );
			palette.forEach( function ( row ) {
				var button = el( 'button', 'components-button pos-edit__swatch' );
				button.type = 'button';
				button.setAttribute( 'role', 'option' );
				button.setAttribute( 'aria-label', row.name || row.color );
				button.title = row.name || row.color;
				button.style.background = row.color;
				if ( row.color === hidden.value ) {
					button.className += ' is-pressed';
					button.setAttribute( 'aria-selected', 'true' );
				}
				button.addEventListener( 'click', function () {
					hidden.value = row.color;
					paint();
					if ( 'function' === typeof onChange ) {
						onChange( row.color );
					}
				} );
				wrap.appendChild( button );
			} );
		}
		paint();
		return { wrap: wrap, input: hidden };
	}

	function iconPicker( value, onChange ) {
		var text = settings.text || {};
		var picker = settings.iconPicker || {};
		var library = picker.library || {};
		var icons = Array.isArray( library.icons ) ? library.icons : [];
		var groups = library.groups || {};
		var wrap = el( 'div', 'pos-edit__icon' );
		var hidden = el( 'input' );
		hidden.type = 'hidden';
		hidden.value = value || 'mdi:map-marker';
		wrap.appendChild( hidden );

		if ( ! icons.length ) {
			var fallback = select( settings.icons || {}, hidden.value );
			fallback.addEventListener( 'change', function () {
				hidden.value = fallback.value;
				if ( 'function' === typeof onChange ) {
					onChange( fallback.value );
				}
			} );
			wrap.appendChild( fallback );
			return { wrap: wrap, input: hidden };
		}

		var row = el( 'div', 'pos-edit__choose-row' );
		var toggle = el( 'button', 'pos-edit__choose' );
		toggle.type = 'button';
		var current = el( 'span', 'pos-edit__icon-current' );
		row.appendChild( toggle );
		row.appendChild( current );
		wrap.appendChild( row );

		var panel = el( 'div', 'pos-edit__icon-panel' );
		panel.hidden = true;
		wrap.appendChild( panel );

		function iconLabel( slug ) {
			var resolved = iconSlug( slug );
			return ( settings.icons || {} )[ resolved ] || ( settings.icons || {} )[ slug ] || resolved;
		}

		function paintChosen() {
			var resolved = iconSlug( hidden.value );
			toggle.textContent = '';
			toggle.appendChild( iconGlyph( resolved ) );
			toggle.appendChild( document.createTextNode( text.chooseIcon || 'Choose icon' ) );
			toggle.setAttribute( 'aria-expanded', panel.hidden ? 'false' : 'true' );
			toggle.setAttribute( 'aria-label', ( text.chooseIcon || 'Choose icon' ) + ': ' + iconLabel( resolved ) );
			current.textContent = iconLabel( resolved );
		}

		var searchHost = searchField( text.searchIcons || 'Search icons', function () {
			paintGrid();
		} );
		panel.appendChild( searchHost.wrap );

		var groupField = el( 'label', 'pos-edit__icon-group' );
		groupField.appendChild( el( 'span', '', text.iconGroup || 'Group' ) );
		var groupSelect = el( 'select', 'pos-edit__icon-groups' );
		groupSelect.appendChild( new Option( text.allIcons || 'All icons', '' ) );
		Object.keys( groups ).forEach( function ( id ) {
			groupSelect.appendChild( new Option( groups[ id ], id ) );
		} );
		groupSelect.addEventListener( 'change', function () {
			paintGrid();
		} );
		groupField.appendChild( groupSelect );
		panel.appendChild( groupField );

		var grid = el( 'div', 'pos-edit__icon-grid' );
		grid.setAttribute( 'role', 'listbox' );
		grid.setAttribute( 'aria-label', text.icon || '' );
		panel.appendChild( grid );

		function closePanel() {
			if ( panel.hidden ) {
				return;
			}
			panel.hidden = true;
			paintChosen();
		}

		function paintGrid() {
			var query = String( searchHost.input.value || '' ).trim().toLowerCase();
			var group = String( groupSelect.value || '' );
			var selected = iconSlug( hidden.value );
			grid.textContent = '';
			icons.forEach( function ( icon ) {
				if ( group && icon.group !== group ) {
					return;
				}
				if ( query ) {
					var hay = ( icon.slug + ' ' + ( icon.label || '' ) + ' ' + ( icon.keywords || '' ) ).toLowerCase();
					if ( hay.indexOf( query ) === -1 ) {
						return;
					}
				}
				var button = el( 'button', 'pos-edit__icon-swatch' );
				button.type = 'button';
				button.setAttribute( 'role', 'option' );
				button.setAttribute( 'aria-label', icon.label || icon.slug );
				button.title = icon.label || icon.slug;
				if ( icon.slug === selected ) {
					button.className += ' is-pressed';
					button.setAttribute( 'aria-selected', 'true' );
				}
				button.appendChild( iconGlyph( icon.slug ) );
				button.addEventListener( 'click', function () {
					hidden.value = icon.slug;
					closePanel();
					if ( 'function' === typeof onChange ) {
						onChange( icon.slug );
					}
				} );
				grid.appendChild( button );
			} );
		}

		toggle.addEventListener( 'click', function () {
			panel.hidden = ! panel.hidden;
			paintChosen();
			if ( ! panel.hidden ) {
				paintGrid();
			}
		} );
		function onDocumentClick( event ) {
			if ( ! wrap.isConnected ) {
				document.removeEventListener( 'click', onDocumentClick );
				return;
			}
			if ( panel.hidden || wrap.contains( event.target ) ) {
				return;
			}
			closePanel();
		}
		document.addEventListener( 'click', onDocumentClick );
		paintChosen();
		return { wrap: wrap, input: hidden };
	}

	function readPairs( value ) {
		var found = [];
		String( value || '' ).split( /\n/ ).forEach( function ( line ) {
			line = line.trim();
			if ( ! line ) {
				return;
			}
			var parts = line.split( /[\s,;]+/ );
			var lat = Number( parts[ 0 ] );
			var lng = Number( parts[ 1 ] );
			if ( parts.length === 2 && isFinite( lat ) && isFinite( lng ) && Math.abs( lat ) <= 90 && Math.abs( lng ) <= 180 ) {
				found.push( [ lng, lat ] );
			}
		} );
		return found;
	}

	function writePairs( points ) {
		return points.map( function ( point ) {
			return point[ 1 ].toFixed( 6 ) + ', ' + point[ 0 ].toFixed( 6 );
		} ).join( '\n' );
	}

	function geometryOf( kind, points ) {
		if ( 'point' === kind ) {
			return points[ 0 ] ? { type: 'Point', coordinates: points[ 0 ] } : null;
		}
		if ( 'route' === kind ) {
			return points.length >= 2 ? { type: 'LineString', coordinates: points } : null;
		}
		if ( points.length < 3 ) {
			return null;
		}
		return { type: 'Polygon', coordinates: [ points.concat( [ points[ 0 ] ] ) ] };
	}

	function pointsOf( geometry ) {
		if ( ! geometry || ! geometry.coordinates ) {
			return [];
		}
		if ( 'Point' === geometry.type ) {
			return [ geometry.coordinates ];
		}
		if ( 'LineString' === geometry.type ) {
			return geometry.coordinates.slice();
		}
		if ( 'Polygon' === geometry.type && geometry.coordinates[ 0 ] ) {
			var ring = geometry.coordinates[ 0 ].slice();
			if ( ring.length > 1 && ring[ 0 ][ 0 ] === ring[ ring.length - 1 ][ 0 ] && ring[ 0 ][ 1 ] === ring[ ring.length - 1 ][ 1 ] ) {
				ring.pop();
			}
			return ring;
		}
		return [];
	}

	function shapes( kind, points ) {
		var features = points.map( function ( point ) {
			return { type: 'Feature', geometry: { type: 'Point', coordinates: point }, properties: {} };
		} );
		var geometry = geometryOf( kind, points );
		if ( geometry && 'Point' !== geometry.type ) {
			features.push( { type: 'Feature', geometry: geometry, properties: {} } );
		}
		return { type: 'FeatureCollection', features: features };
	}

	function mount( box, options ) {
		options = options || {};
		if ( ! box || box.dataset.ready || ! window.maplibregl ) {
			return null;
		}
		box.dataset.ready = '1';
		var text = ( settings.text || {} );
		var kind = options.kind || ( 'outline' === options.mode ? 'boundary' : 'point' );
		var color = options.color || '#415840';
		var icon = options.icon || 'mdi:map-marker';
		var points = options.points ? options.points.slice() : pointsOf( options.geometry );
		var textarea = options.textarea || box.querySelector( 'textarea' );
		var count = options.count || box.querySelector( '.packout-draw__count, .pos-edit__count' );
		var canvas = box.querySelector( '.packout-draw__map, .pos-edit__map' );
		if ( ! canvas ) {
			return null;
		}
		if ( textarea && ! points.length ) {
			points = readPairs( textarea.value );
		}

		var camera = pageCamera();
		var map = new window.maplibregl.Map( {
			container: canvas,
			style: settings.style,
			center: camera.center,
			zoom: camera.zoom,
			attributionControl: { compact: true },
			clickTolerance: 16,
			dragRotate: false,
			pitchWithRotate: false,
			touchPitch: false,
			doubleClickZoom: false,
			boxZoom: false,
			maxPitch: 0,
		} );
		map.addControl( new window.maplibregl.NavigationControl( { showCompass: false } ), 'top-right' );
		if ( map.touchZoomRotate && map.touchZoomRotate.disableRotation ) {
			map.touchZoomRotate.disableRotation();
		}
		map.getCanvas().style.cursor = 'crosshair';

		var markers = [];
		var labels = [];
		var popover = null;
		var hoverPopup = null;
		var hoverTimer = null;
		var dragging = false;
		var pendingPreview = null;

		function hideHover() {
			clearTimeout( hoverTimer );
			if ( hoverPopup ) {
				hoverPopup.remove();
				hoverPopup = null;
			}
		}

		function hideHoverSoon() {
			clearTimeout( hoverTimer );
			hoverTimer = setTimeout( hideHover, 180 );
		}

		function showHover( lngLat ) {
			clearTimeout( hoverTimer );
			if ( ! lngLat || 'function' !== typeof options.preview ) {
				return;
			}
			var info = options.preview() || {};
			if ( ! info.label && ! info.description && ! info.image ) {
				return;
			}
			hideHover();
			var box = el( 'div', 'pos-tip' );
			if ( info.image ) {
				var image = el( 'img', 'pos-tip__img' );
				image.src = info.image;
				image.alt = '';
				box.appendChild( image );
			}
			if ( info.label ) {
				box.appendChild( el( 'p', 'pos-tip__label', info.label ) );
			}
			if ( info.description ) {
				box.appendChild( el( 'p', 'pos-tip__note', info.description ) );
			}
			hoverPopup = new window.maplibregl.Popup( { closeButton: false, closeOnClick: false, offset: 22, className: 'pos-tip-popup', maxWidth: '240px' } )
				.setLngLat( lngLat )
				.setDOMContent( box )
				.addTo( map );
			var shell = hoverPopup.getElement();
			if ( shell ) {
				shell.addEventListener( 'mouseenter', function () {
					clearTimeout( hoverTimer );
				} );
				shell.addEventListener( 'mouseleave', hideHoverSoon );
			}
		}

		function paintLabels() {
			var caption = 'function' === typeof options.label ? options.label() : '';
			labels.forEach( function ( node ) {
				if ( node.isConnected ) {
					node.textContent = caption;
				}
			} );
		}

		function applyPreview( collection ) {
			collection = collection || { type: 'FeatureCollection', features: [] };
			if ( ! map.getSource( 'preview' ) ) {
				if ( ! map.loaded() ) {
					pendingPreview = collection;
					return;
				}
				map.addSource( 'preview', { type: 'geojson', data: collection } );
				map.addLayer( { id: 'preview-fill', type: 'fill', source: 'preview', filter: [ '==', [ 'geometry-type' ], 'Polygon' ], paint: { 'fill-color': color, 'fill-opacity': 0.12 } } );
				map.addLayer( { id: 'preview-line', type: 'line', source: 'preview', filter: [ '!=', [ 'geometry-type' ], 'Point' ], paint: { 'line-color': color, 'line-width': 2, 'line-dasharray': [ 2, 2 ] } } );
				map.addLayer( { id: 'preview-points', type: 'circle', source: 'preview', filter: [ '==', [ 'geometry-type' ], 'Point' ], paint: { 'circle-color': color, 'circle-radius': 6, 'circle-stroke-width': 2, 'circle-stroke-color': '#ffffff' } } );
			} else {
				map.getSource( 'preview' ).setData( collection );
			}
			var bounds = new window.maplibregl.LngLatBounds();
			( collection.features || [] ).forEach( function ( feature ) {
				pointsOf( feature.geometry ).forEach( function ( point ) {
					bounds.extend( point );
				} );
			} );
			if ( ! bounds.isEmpty() ) {
				map.fitBounds( bounds, { padding: 40, maxZoom: 14, duration: 0 } );
			}
		}

		function hidePopover() {
			if ( popover ) {
				popover.remove();
				popover = null;
			}
		}

		function onPointerDown( event ) {
			if ( ! popover ) {
				return;
			}
			var target = event.target;
			if ( target && target.closest && target.closest( '.pos-edit__popover, .pos-edit__pin, .pos-edit__vertex' ) ) {
				return;
			}
			if ( target && target.closest && target.closest( '.maplibregl-canvas, .maplibregl-canvas-container' ) ) {
				return;
			}
			hidePopover();
		}

		function onKeyDown( event ) {
			if ( ! popover || 'Escape' !== event.key ) {
				return;
			}
			event.preventDefault();
			event.stopPropagation();
			hidePopover();
		}

		document.addEventListener( 'mousedown', onPointerDown );
		document.addEventListener( 'keydown', onKeyDown );

		function showPopover( index, node ) {
			hideHover();
			hidePopover();
			popover = el( 'div', 'pos-edit__popover' );
			var actions = el( 'div', 'pos-edit__popactions' );
			var remove = el( 'button', 'pos-edit__popbtn', text.delete );
			remove.type = 'button';
			remove.addEventListener( 'click', function () {
				if ( 'point' === kind ) {
					points = [];
				} else {
					points.splice( index, 1 );
				}
				hidePopover();
				draw();
			} );
			actions.appendChild( remove );
			var wipe = el( 'button', 'pos-edit__popbtn', text.clear );
			wipe.type = 'button';
			wipe.addEventListener( 'click', function () {
				points = [];
				hidePopover();
				draw();
			} );
			actions.appendChild( wipe );
			popover.appendChild( actions );
			popover.addEventListener( 'click', function ( event ) {
				event.stopPropagation();
			} );
			node.appendChild( popover );
		}

		function draw() {
			if ( textarea && 'outline' === options.mode ) {
				textarea.value = writePairs( points );
			}
			if ( count ) {
				count.textContent = points.length ? ( text.points || '%d points' ).replace( '%d', String( points.length ) ) : '';
			}
			if ( map.getSource( 'draft' ) ) {
				map.getSource( 'draft' ).setData( shapes( 'outline' === options.mode ? 'boundary' : kind, points ) );
			}
			markers.forEach( function ( marker ) {
				marker.remove();
			} );
			labels = [];
			markers = points.map( function ( point, index ) {
				var vertex;
				var host;
				if ( 'point' === kind && 'outline' !== options.mode ) {
					host = el( 'div', 'pos-edit__marker' );
					vertex = el( 'button', 'pos-edit__pin' );
					vertex.style.color = color;
					vertex.appendChild( iconGlyph( icon ) );
					var caption = el( 'span', 'pos-edit__pinlabel', 'function' === typeof options.label ? options.label() : '' );
					labels.push( caption );
					host.appendChild( vertex );
					host.appendChild( caption );
				} else {
					vertex = el( 'button', 'pos-edit__vertex' );
					vertex.style.color = color;
					vertex.style.borderColor = color;
					host = vertex;
				}
				vertex.type = 'button';
				vertex.setAttribute( 'aria-label', text.popover || '' );
				vertex.addEventListener( 'mouseenter', function () {
					showHover( point );
				} );
				vertex.addEventListener( 'mouseleave', hideHoverSoon );
				var marker = new window.maplibregl.Marker( { element: host, draggable: true, color: color } ).setLngLat( point ).addTo( map );
				marker.on( 'dragstart', function () {
					dragging = true;
					hidePopover();
				} );
				marker.on( 'dragend', function () {
					var at = marker.getLngLat();
					points[ index ] = [ at.lng, at.lat ];
					dragging = false;
					draw();
				} );
				vertex.addEventListener( 'click', function ( event ) {
					event.stopPropagation();
					if ( dragging || event.target.closest( '.pos-edit__popover' ) ) {
						return;
					}
					showPopover( index, vertex );
				} );
				return marker;
			} );
			if ( 'point' !== kind && 'outline' !== options.mode && points.length && 'function' === typeof options.label && options.label() ) {
				var sum = points.reduce( function ( total, point ) {
					return [ total[ 0 ] + point[ 0 ], total[ 1 ] + point[ 1 ] ];
				}, [ 0, 0 ] );
				var caption = el( 'span', 'pos-edit__pinlabel pos-edit__pinlabel--place', options.label() );
				labels.push( caption );
				caption.addEventListener( 'mouseenter', function () {
					showHover( [ sum[ 0 ] / points.length, sum[ 1 ] / points.length ] );
				} );
				caption.addEventListener( 'mouseleave', hideHoverSoon );
				markers.push( new window.maplibregl.Marker( { element: caption, anchor: 'center' } ).setLngLat( [ sum[ 0 ] / points.length, sum[ 1 ] / points.length ] ).addTo( map ) );
			}
			if ( 'function' === typeof options.onChange ) {
				options.onChange( geometryOf( 'outline' === options.mode ? 'boundary' : kind, points ), points.slice() );
			}
		}

		map.on( 'load', function () {
			map.addSource( 'draft', { type: 'geojson', data: shapes( 'outline' === options.mode ? 'boundary' : kind, points ) } );
			map.addLayer( { id: 'draft-fill', type: 'fill', source: 'draft', filter: [ '==', [ 'geometry-type' ], 'Polygon' ], paint: { 'fill-color': color, 'fill-opacity': 0.2 } } );
			map.addLayer( { id: 'draft-line', type: 'line', source: 'draft', filter: [ '!=', [ 'geometry-type' ], 'Point' ], paint: { 'line-color': color, 'line-width': 2 } } );
			// An outline with points is framed on those points. Spots are only a backdrop;
			// including every Spot on the site pulled the view too far out to draw.
			var around = points.length ? points.slice() : ( options.spots || settings.spots || [] ).slice();
			if ( around.length ) {
				var bounds = new window.maplibregl.LngLatBounds();
				around.forEach( function ( point ) {
					bounds.extend( point );
				} );
				map.fitBounds( bounds, { padding: 40, maxZoom: 14, duration: 0 } );
			}
			if ( pendingPreview ) {
				applyPreview( pendingPreview );
				pendingPreview = null;
			}
			draw();
			[ 'draft-line', 'draft-fill' ].forEach( function ( layer ) {
				map.on( 'mousemove', layer, function ( event ) {
					if ( 'point' === kind ) {
						return;
					}
					showHover( event.lngLat );
				} );
				map.on( 'mouseleave', layer, hideHoverSoon );
			} );
		} );

		map.on( 'click', function ( event ) {
			if ( dragging || ( event.originalEvent && event.originalEvent.target.closest && event.originalEvent.target.closest( '.pos-edit__vertex, .pos-edit__pin, .pos-edit__popover, .pos-edit__mapfoot, .pos-edit__pinlabel, .pos-tip' ) ) ) {
				return;
			}
			if ( map.stop ) {
				map.stop();
			}
			if ( popover ) {
				hidePopover();
				return;
			}
			if ( 'point' === kind ) {
				points = [ [ event.lngLat.lng, event.lngLat.lat ] ];
			} else {
				points.push( [ event.lngLat.lng, event.lngLat.lat ] );
			}
			draw();
		} );

		box.addEventListener( 'click', function ( event ) {
			var tool = event.target.closest( '[data-draw]' );
			if ( ! tool ) {
				return;
			}
			hidePopover();
			if ( tool.getAttribute( 'data-draw' ) === 'undo' ) {
				points.pop();
			} else {
				points = [];
			}
			draw();
		} );

		if ( textarea && 'outline' === options.mode ) {
			textarea.addEventListener( 'input', function () {
				var raw = textarea.value.trim();
				if ( '{' === raw.charAt( 0 ) || '[' === raw.charAt( 0 ) ) {
					try {
						var parsed = JSON.parse( raw );
						var geometry = parsed;
						if ( parsed && 'Feature' === parsed.type ) {
							geometry = parsed.geometry;
						} else if ( parsed && 'FeatureCollection' === parsed.type && parsed.features && parsed.features[ 0 ] ) {
							geometry = parsed.features[ 0 ].geometry;
						}
						points = pointsOf( geometry );
						if ( points.length > 1 && points[ 0 ][ 0 ] === points[ points.length - 1 ][ 0 ] && points[ 0 ][ 1 ] === points[ points.length - 1 ][ 1 ] ) {
							points = points.slice( 0, -1 );
						}
					} catch ( error ) {
						return;
					}
					draw();
					return;
				}
				points = readPairs( textarea.value );
				draw();
			} );
		}

		var searchHost = box.querySelector( '.packout-draw__search, .pos-edit__search' );
		var found = { marker: null };
		if ( searchHost && window.packoutSearch ) {
			window.packoutSearch.mount( searchHost, {
				pages: false,
				credentials: 'same-origin',
				headers: settings.nonce ? { 'X-WP-Nonce': settings.nonce } : {},
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
				choose: function ( result, group ) {
					window.packoutSearch.go( map, result, found, 'locations' === group );
				},
			} );
		}

		return {
			getGeometry: function () {
				return geometryOf( 'outline' === options.mode ? 'boundary' : kind, points );
			},
			getPoints: function () {
				return points.slice();
			},
			setKind: function ( next ) {
				kind = next;
				if ( 'point' === kind && points.length > 1 ) {
					points = [ points[ points.length - 1 ] ];
				}
				draw();
			},
			setColor: function ( next ) {
				color = next;
				if ( map.getLayer( 'draft-fill' ) ) {
					map.setPaintProperty( 'draft-fill', 'fill-color', color );
					map.setPaintProperty( 'draft-line', 'line-color', color );
				}
				draw();
			},
			setIcon: function ( next ) {
				icon = next || icon;
				draw();
			},
			refreshLabel: paintLabels,
			setGeometry: function ( geometry ) {
				points = pointsOf( geometry );
				draw();
			},
			setPreview: function ( collection ) {
				applyPreview( collection );
			},
			resize: function () {
				map.resize();
			},
			destroy: function () {
				document.removeEventListener( 'mousedown', onPointerDown );
				document.removeEventListener( 'keydown', onKeyDown );
				hidePopover();
				markers.forEach( function ( marker ) {
					marker.remove();
				} );
				map.remove();
				delete box.dataset.ready;
			},
		};
	}

	function field( label, control, asLabel ) {
		var wrap = el( false === asLabel ? 'div' : 'label', 'pos-edit__field' );
		wrap.appendChild( el( 'span', '', label ) );
		wrap.appendChild( control );
		return wrap;
	}

	function select( options, value ) {
		var control = el( 'select' );
		Object.keys( options ).forEach( function ( key ) {
			control.appendChild( new Option( options[ key ], key ) );
		} );
		control.value = value;
		return control;
	}

	function open( options ) {
		options = options || {};
		var text = settings.text || {};
		var existing = document.querySelector( '.pos-edit--open' );
		if ( existing ) {
			existing.remove();
		}
		var feature = options.feature || null;
		var overlay = el( 'div', 'pos-edit pos-edit--open' );
		overlay.setAttribute( 'role', 'dialog' );
		overlay.setAttribute( 'aria-modal', 'true' );
		var panel = el( 'div', 'pos-edit__panel' );
		var bar = el( 'header', 'pos-edit__bar' );
		bar.appendChild( el( 'h2', 'pos-edit__title', feature ? text.edit : text.add ) );
		var close = el( 'button', 'button pos-edit__action', text.close );
		close.type = 'button';
		bar.appendChild( close );
		panel.appendChild( bar );

		var body = el( 'div', 'pos-edit__body' );
		var form = el( 'div', 'pos-edit__form' );
		var name = el( 'input' );
		name.type = 'text';
		name.maxLength = 120;
		name.value = feature ? feature.name : '';
		form.appendChild( field( text.name, name ) );
		var note = el( 'textarea' );
		note.rows = 3;
		note.value = feature ? ( feature.description || '' ) : '';
		form.appendChild( field( text.note, note ) );
		var photos = el( 'input' );
		photos.type = 'file';
		photos.accept = 'image/*';
		photos.multiple = true;
		var shots = ( feature && feature.gallery ? feature.gallery : [] ).map( function ( photo ) {
			return { id: photo.id, url: photo.url, alt: photo.alt || '' };
		} );
		var dragFrom = -1;
		var photoList = el( 'div', 'pos-edit__photos' );
		photoList.setAttribute( 'role', 'list' );
		var photoHelp = el( 'p', 'pos-edit__photohelp', text.photosHelp || '' );
		function paintPhotos() {
			photoList.textContent = '';
			shots.forEach( function ( item, index ) {
				var shot = el( 'span', 'pos-edit__shot' );
				shot.draggable = true;
				shot.title = text.dragPhoto || 'Drag to change the order';
				shot.setAttribute( 'role', 'listitem' );
				shot.setAttribute( 'aria-label', ( item.alt || text.photos || 'Photo' ) + '. ' + ( text.dragPhoto || 'Drag to change the order' ) );
				var image = el( 'img' );
				image.src = item.url;
				image.alt = item.alt || '';
				image.draggable = false;
				shot.appendChild( image );
				if ( item.file ) {
					var drop = el( 'button', 'pos-edit__photoremove', text.removePhoto || 'Remove' );
					drop.type = 'button';
					drop.draggable = false;
					drop.addEventListener( 'click', function ( event ) {
						event.preventDefault();
						event.stopPropagation();
						URL.revokeObjectURL( item.url );
						shots.splice( index, 1 );
						paintPhotos();
					} );
					shot.appendChild( drop );
				}
				shot.addEventListener( 'dragstart', function ( event ) {
					if ( event.target.closest && event.target.closest( 'button' ) ) {
						event.preventDefault();
						return;
					}
					dragFrom = index;
					shot.classList.add( 'is-dragging' );
					event.dataTransfer.effectAllowed = 'move';
					event.dataTransfer.setData( 'text/plain', String( index ) );
				} );
				shot.addEventListener( 'dragend', function () {
					dragFrom = -1;
					shot.classList.remove( 'is-dragging' );
					photoList.querySelectorAll( '.is-drop' ).forEach( function ( node ) {
						node.classList.remove( 'is-drop' );
					} );
				} );
				shot.addEventListener( 'dragover', function ( event ) {
					if ( dragFrom < 0 ) {
						return;
					}
					event.preventDefault();
					event.dataTransfer.dropEffect = 'move';
					photoList.querySelectorAll( '.is-drop' ).forEach( function ( node ) {
						node.classList.remove( 'is-drop' );
					} );
					if ( dragFrom !== index ) {
						shot.classList.add( 'is-drop' );
					}
				} );
				shot.addEventListener( 'drop', function ( event ) {
					event.preventDefault();
					var from = dragFrom;
					dragFrom = -1;
					if ( from < 0 || from === index ) {
						return;
					}
					var moved = shots.splice( from, 1 )[ 0 ];
					shots.splice( index, 0, moved );
					paintPhotos();
				} );
				photoList.appendChild( shot );
			} );
		}
		photos.addEventListener( 'change', function () {
			Array.prototype.forEach.call( photos.files || [], function ( file ) {
				shots.push( { file: file, url: URL.createObjectURL( file ), alt: file.name } );
			} );
			photos.value = '';
			paintPhotos();
		} );
		var photoField = field( text.photos || 'Photos', photos, false );
		photoField.appendChild( photoList );
		photoField.appendChild( photoHelp );
		form.appendChild( photoField );
		paintPhotos();
		var kind = select( settings.kinds || {}, feature ? feature.kind : 'point' );
		form.appendChild( field( text.kind, kind ) );
		var iconField = iconPicker( feature && feature.icon ? feature.icon : 'mdi:map-marker', function ( next ) {
			if ( editor ) {
				editor.setIcon( next );
			}
		} );
		var iconBox = field( text.icon, iconField.wrap, false );
		form.appendChild( iconBox );
		var file = el( 'input' );
		file.type = 'file';
		file.accept = '.gpx,.xml,.geojson,.json,application/gpx+xml,application/geo+json';
		var importField = field( text.import, file );
		form.appendChild( importField );
		var place = select( { '0': text.none }, feature && feature.place ? String( feature.place.id ) : '0' );
		( settings.places || [] ).forEach( function ( row ) {
			place.appendChild( new Option( row.name, String( row.id ) ) );
		} );
		if ( feature && feature.place ) {
			place.value = String( feature.place.id );
		}
		form.appendChild( field( text.place, place ) );
		var startColor = feature && feature.color ? feature.color : ( ( settings.palette || [] )[ 0 ] || {} ).color || ( settings.colors || [ '#415840' ] )[ 0 ];
		var colorField = colorPalette( startColor, function ( next ) {
			if ( editor && next ) {
				editor.setColor( next );
			}
		} );
		form.appendChild( field( text.color, colorField.wrap, false ) );
		var status = el( 'p', 'pos-edit__status' );
		form.appendChild( status );
		var save = el( 'button', 'button button-primary pos-edit__action', text.save );
		save.type = 'button';
		form.appendChild( save );

		var mapWrap = el( 'div', 'pos-edit__mapwrap packout-draw' );
		mapWrap.appendChild( el( 'div', 'pos-edit__search packout-draw__search' ) );
		var stage = el( 'div', 'pos-edit__stage' );
		var mapBox = el( 'div', 'pos-edit__map packout-draw__map' );
		mapBox.setAttribute( 'role', 'application' );
		mapBox.setAttribute( 'aria-label', text.map || '' );
		stage.appendChild( mapBox );
		var tools = el( 'footer', 'pos-edit__mapfoot' );
		var undo = el( 'button', 'components-button is-link button-link pos-edit__toollink' );
		undo.type = 'button';
		undo.setAttribute( 'data-draw', 'undo' );
		undo.textContent = text.undo;
		var clear = el( 'button', 'components-button is-link button-link pos-edit__toollink' );
		clear.type = 'button';
		clear.setAttribute( 'data-draw', 'clear' );
		clear.textContent = text.clear;
		tools.appendChild( undo );
		tools.appendChild( clear );
		tools.appendChild( el( 'span', 'packout-draw__count pos-edit__count' ) );
		stage.appendChild( tools );
		mapWrap.appendChild( stage );

		body.appendChild( form );
		body.appendChild( mapWrap );
		panel.appendChild( body );
		overlay.appendChild( panel );
		document.body.appendChild( overlay );

		function say( message ) {
			status.textContent = message || '';
		}

		function syncKind() {
			iconBox.hidden = 'point' !== kind.value;
			importField.hidden = 'route' !== kind.value;
			tools.hidden = 'point' === kind.value;
			stage.classList.toggle( 'pos-edit__stage--tools', 'point' !== kind.value );
			if ( editor ) {
				editor.setKind( kind.value );
			}
		}

		function previewPhoto() {
			return shots[ 0 ] ? shots[ 0 ].url : '';
		}
		var editor = mount( mapWrap, {
			kind: kind.value,
			color: colorField.input.value,
			icon: iconField.input.value,
			geometry: feature ? feature.geometry : null,
			noteInput: note,
			label: function () {
				return name.value.trim();
			},
			preview: function () {
				return {
					label: name.value.trim(),
					description: note.value.trim(),
					image: previewPhoto(),
				};
			},
		} );
		name.addEventListener( 'input', function () {
			if ( editor && editor.refreshLabel ) {
				editor.refreshLabel();
			}
		} );
		var pendingImport = null;
		window.requestAnimationFrame( function () {
			window.requestAnimationFrame( function () {
				if ( editor ) {
					editor.resize();
				}
			} );
		} );
		syncKind();
		kind.addEventListener( 'change', syncKind );

		function stop() {
			if ( editor ) {
				editor.destroy();
			}
			overlay.remove();
			if ( 'function' === typeof options.onClose ) {
				options.onClose();
			}
		}
		close.addEventListener( 'click', stop );
		overlay.addEventListener( 'click', function ( event ) {
			if ( event.target === overlay ) {
				stop();
			}
		} );

		save.addEventListener( 'click', function () {
			if ( pendingImport ) {
				say( text.saving );
				save.disabled = true;
				var commit = new FormData();
				commit.append( 'file', pendingImport );
				commit.append( 'commit', '1' );
				commit.append( 'color', colorField.input.value );
				commit.append( 'icon', iconField.input.value );
				commit.append( 'place', place.value );
				fetch( settings.rest + 'features/import', {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						Accept: 'application/json',
						'X-WP-Nonce': settings.nonce || '',
					},
					body: commit,
				} )
					.then( function ( response ) {
						return response.json().then( function ( payload ) {
							return { ok: response.ok, data: payload };
						} );
					} )
					.then( function ( result ) {
						save.disabled = false;
						if ( ! result.ok ) {
							say( ( result.data && result.data.message ) || text.failed );
							return;
						}
						if ( 'function' === typeof options.onSave ) {
							options.onSave( result.data.features );
						}
						stop();
						window.location.reload();
					} )
					.catch( function () {
						save.disabled = false;
						say( text.failed );
					} );
				return;
			}
			var geometry = editor ? editor.getGeometry() : null;
			if ( ! name.value.trim() ) {
				say( text.needName );
				name.focus();
				return;
			}
			if ( ! geometry ) {
				say( text.needDraw );
				return;
			}
			say( text.saving );
			save.disabled = true;
			var body = {
				name: name.value.trim(),
				description: note.value,
				kind: kind.value,
				color: colorField.input.value,
				icon: iconField.input.value,
				geometry: geometry,
				place: parseInt( place.value, 10 ) || 0,
			};
			var url = settings.rest + 'features' + ( feature ? '/' + feature.id : '' );
			fetch( url, {
				method: feature ? 'PUT' : 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
					'X-WP-Nonce': settings.nonce || '',
				},
				body: JSON.stringify( body ),
			} )
				.then( function ( response ) {
					return response.json().then( function ( data ) {
						return { ok: response.ok, data: data };
					} );
				} )
				.then( function ( result ) {
					if ( ! result.ok ) {
						save.disabled = false;
						say( ( result.data && result.data.message ) || text.failed );
						return;
					}
					var saved = result.data.feature;
					var fresh = [];
					var chain = Promise.resolve();
					shots.forEach( function ( item ) {
						if ( item.id || ! item.file ) {
							return;
						}
						chain = chain.then( function () {
							var data = new FormData();
							data.append( 'file', item.file );
							return fetch( settings.rest + 'features/' + saved.id + '/photos', {
								method: 'POST',
								credentials: 'same-origin',
								headers: {
									Accept: 'application/json',
									'X-WP-Nonce': settings.nonce || '',
								},
								body: data,
							} ).then( function ( response ) {
								return response.json().then( function ( payload ) {
									if ( ! response.ok ) {
										throw new Error( ( payload && payload.message ) || text.failed );
									}
									fresh.push( payload.photo );
								} );
							} );
						} );
					} );
					chain = chain.then( function () {
						var next = 0;
						var order = shots.map( function ( item ) {
							return item.id || fresh[ next++ ];
						} ).filter( function ( id ) {
							return id;
						} );
						if ( ! order.length ) {
							return null;
						}
						return fetch( settings.rest + 'features/' + saved.id + '/photos/order', {
							method: 'POST',
							credentials: 'same-origin',
							headers: {
								'Content-Type': 'application/json',
								Accept: 'application/json',
								'X-WP-Nonce': settings.nonce || '',
							},
							body: JSON.stringify( { order: order } ),
						} ).then( function ( response ) {
							if ( ! response.ok ) {
								return response.json().then( function ( payload ) {
									throw new Error( ( payload && payload.message ) || text.failed );
								} );
							}
						} );
					} );
					return chain.then( function () {
						save.disabled = false;
						if ( 'function' === typeof options.onSave ) {
							options.onSave( saved );
						}
						stop();
						if ( options.reload !== false ) {
							window.location.reload();
						}
					} );
				} )
				.catch( function () {
					save.disabled = false;
					say( text.failed );
				} );
		} );

		file.addEventListener( 'change', function () {
			if ( ! file.files || ! file.files[ 0 ] ) {
				return;
			}
			pendingImport = null;
			say( text.importing );
			var data = new FormData();
			data.append( 'file', file.files[ 0 ] );
			fetch( settings.rest + 'features/import', {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					Accept: 'application/json',
					'X-WP-Nonce': settings.nonce || '',
				},
				body: data,
			} )
				.then( function ( response ) {
					return response.json().then( function ( payload ) {
						return { ok: response.ok, data: payload };
					} );
				} )
				.then( function ( result ) {
					if ( ! result.ok ) {
						say( ( result.data && result.data.message ) || text.failed );
						return;
					}
					var drafts = result.data.drafts || [];
					if ( ! drafts.length ) {
						say( text.failed );
						return;
					}
					pendingImport = file.files[ 0 ];
					say( 1 === drafts.length ? text.previewOne : ( text.preview || '%d features ready. Save to add them to the map.' ).replace( '%d', String( drafts.length ) ) );
					if ( editor && editor.setPreview ) {
						editor.setPreview( {
							type: 'FeatureCollection',
							features: drafts.map( function ( draft ) {
								return { type: 'Feature', geometry: draft.geometry, properties: { name: draft.name } };
							} ),
						} );
					}
					if ( 1 === drafts.length ) {
						name.value = drafts[ 0 ].name || name.value;
						note.value = drafts[ 0 ].description || note.value;
						if ( drafts[ 0 ].kind ) {
							kind.value = drafts[ 0 ].kind;
							syncKind();
						}
						if ( editor && drafts[ 0 ].geometry ) {
							editor.setGeometry( drafts[ 0 ].geometry );
						}
					}
				} )
				.catch( function () {
					say( text.failed );
				} );
		} );
	}

	window.packoutMapEditor = Object.assign( settings, {
		mount: mount,
		open: open,
	} );

	document.addEventListener( 'click', function ( event ) {
		var opener = event.target.closest( '[data-pos-feature-edit]' );
		if ( ! opener || ! window.packoutMapEditor.open ) {
			return;
		}
		event.preventDefault();
		var raw = opener.getAttribute( 'data-pos-feature-edit' );
		var feature = null;
		if ( raw && '{}' !== raw && '1' !== raw ) {
			try {
				feature = JSON.parse( raw );
			} catch ( error ) {
				feature = null;
			}
		}
		window.packoutMapEditor.open( { feature: feature } );
	} );
} )();
