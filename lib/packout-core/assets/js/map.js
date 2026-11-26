/**
 * Pack-Out Spot map block.
 *
 * Reads the Spots a visitor may see from the REST API and draws them with
 * MapLibre. A Spot opens a panel with its public fields. Badges, the hazard
 * notice, and the empty state are components (see `ui()`). Listed Places are
 * drawn as outlines with their names. On the page of a Place the map shows
 * that Place's Spots only.
 */
( function () {
	'use strict';

	var COLORS = {
		spotted: '#bb5e28',
		planned: '#25486b',
		in_progress: '#25486b',
		pending: '#9a7b12',
		cleaned: '#415840',
		watch: '#863d14',
	};
	var INTENTS = {
		spotted: 'medium',
		planned: 'informational',
		in_progress: 'informational',
		pending: 'low',
		cleaned: 'stable',
		watch: 'high',
	};
	// Group the style's own layers. Liberty and Positron name them differently.
	// Paths and pedestrian ways are trails. Unpaved tracks stay with roads when the style draws them with service roads.
	function basemapGroups( styleLayers ) {
		var groups = { roads: [], trails: [], points: [] };
		( styleLayers || [] ).forEach( function ( layer ) {
			var id = layer.id || '';
			if ( /^poi_/.test( id ) ) {
				groups.points.push( id );
			} else if ( /rail/.test( id ) || /pier/.test( id ) ) {
				return;
			} else if ( /path|pedestrian/.test( id ) ) {
				groups.trails.push( id );
			} else if ( /^(road_|tunnel_|bridge_|highway_)/.test( id ) || /^highway-(name-minor|name-major|shield)/.test( id ) || 'road_shield_us' === id ) {
				groups.roads.push( id );
			}
		} );
		return groups;
	}
	// Public Forest Service map images. OpenFreeMap does not publish these.
	function forestTiles( service, layers ) {
		return 'https://apps.fs.usda.gov/arcx/rest/services/EDW/' + service + '/MapServer/export?bbox={bbox-epsg-3857}&bboxSR=3857&imageSR=3857&size=256,256&format=png32&transparent=true&layers=show:' + layers + '&f=image';
	}
	var MVUM_SERVICE = 'https://apps.fs.usda.gov/arcx/rest/services/EDW/EDW_MVUM_01/MapServer';
	// Identify returns these names. A value of open or seasonal is a vehicle the route allows.
	var MVUM_VEHICLES = [
		[ 'Passenger vehicle', [ 'PassengerVehicle', 'passengervehicle' ], [ 'Passenger Vehicle  (Dates Open)', 'passengervehicle_datesopen' ] ],
		[ 'High clearance vehicle', [ 'High Clearance Vehicle', 'highclearancevehicle' ], [ 'High Clearance Vehicles (Dates Open)', 'highclearancevehicle_datesopen' ] ],
		[ 'Truck', [ 'Truck', 'truck' ], [ 'Truck (Dates Open)', 'truck_datesopen' ] ],
		[ 'Bus', [ 'Bus', 'bus' ], [ 'Bus (Dates Open)', 'bus_datesopen' ] ],
		[ 'Motorhome', [ 'Motorhome', 'motorhome' ], [ 'Motor Home (Dates Open)', 'motorhome_datesopen' ] ],
		[ 'Four-wheel drive over 50 inches', [ 'Four WD >50 inches in width', 'fourwd_gt50inches' ], [ 'Four WD >50 (Dates Open)', 'fourwd_gt50_datesopen' ] ],
		[ 'Two-wheel drive over 50 inches', [ 'Two WD >50 inches in width', 'twowd_gt50inches' ], [ 'Two WD >50 (Dates Open)', 'twowd_gt50_datesopen' ] ],
		[ 'Tracked OHV over 50 inches', [ 'Tracked OHV >50 inches in width', 'tracked_ohv_gt50inches' ], [ 'Tracked OHV >50 (Dates Open)', 'tracked_ohv_gt50_datesopen' ] ],
		[ 'Other OHV over 50 inches', [ 'Other OHV >50 inches in width', 'other_ohv_gt50inches' ], [ 'Other OHV >50 (Dates Open)', 'other_ohv_gt50_datesopen' ] ],
		[ 'ATV', [ 'ATV', 'atv' ], [ 'ATV (Dates Open)', 'atv_datesopen' ] ],
		[ 'Motorcycle', [ 'Motorcycle', 'motorcycle' ], [ 'Motorcyle (Dates Open)', 'motorcycle_datesopen' ] ],
		[ 'Other wheeled OHV', [ 'Other Wheeled OHV', 'otherwheeled_ohv' ], [ 'Other Wheeled OHV (Dates Open)', 'otherwheeled_ohv_datesopen' ] ],
		[ 'Tracked OHV under 50 inches', [ 'Tracked OHV <50 inches in width', 'tracked_ohv_lt50inches' ], [ 'Tracked OHV <50 (Dates Open)', 'tracked_ohv_lt50_datesopen' ] ],
		[ 'Other OHV under 50 inches', [ 'Other OHV <50 inches in width', 'other_ohv_lt50inches' ], [ 'Other OHV <50 (Dates Open)', 'other_ohv_lt50_datesopen' ] ],
		[ 'Class 1 e-bike', [ 'E_BIKE_CLASS1', 'e_bike_class1' ], [ 'E_BIKE_CLASS1_DUR', 'e_bike_class1_dur' ] ],
		[ 'Class 2 e-bike', [ 'E_BIKE_CLASS2', 'e_bike_class2' ], [ 'E_BIKE_CLASS2_DUR', 'e_bike_class2_dur' ] ],
		[ 'Class 3 e-bike', [ 'E_BIKE_CLASS3', 'e_bike_class3' ], [ 'E_BIKE_CLASS3_DUR', 'e_bike_class3_dur' ] ],
	];
	var LINE_CLASS = {
		motorway: 'Motorway',
		trunk: 'Trunk road',
		primary: 'Primary road',
		secondary: 'Secondary road',
		tertiary: 'Tertiary road',
		minor: 'Minor road',
		street: 'Street',
		street_limited: 'Street',
		service: 'Service road',
		track: 'Track',
		path: 'Path',
		pedestrian: 'Pedestrian path',
	};
	var FOREST = {
		mvum: { tiles: forestTiles( 'EDW_MVUM_01', '1,2' ), attribution: 'Motor Vehicle Use Map, U.S. Forest Service', minzoom: 6, opacity: 0.9 },
		nfstrails: { tiles: forestTiles( 'EDW_TrailNFSPublish_01', '0' ), attribution: 'National Forest System trails, U.S. Forest Service', minzoom: 11, opacity: 0.95 },
		forests: { tiles: forestTiles( 'EDW_ForestSystemBoundaries_01', '0,1' ), attribution: 'National Forest System boundaries, U.S. Forest Service', minzoom: 4, opacity: 0.55 },
		wilderness: { tiles: forestTiles( 'EDW_Wilderness_01', '0' ), attribution: 'National Wilderness Areas, U.S. Forest Service', minzoom: 5, opacity: 0.45 },
	};

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

	/** A component: a badge, a notice, or an empty state. The text shows as it is, or until the site's own component mounts over it. */
	function ui( id, props ) {
		var node = el( 'span', 'packout-ui packout-ui--' + id + ' is-' + ( props.intent || 'none' ) + ' pos-ui pos-ui--' + id );
		node.setAttribute( 'data-packout-ui', id );
		node.setAttribute( 'data-config', JSON.stringify( { id: id, props: props } ) );
		var fallback = el( 'span', 'packout-ui__fallback' );
		if ( props.title && ! props.text ) {
			fallback.appendChild( el( 'strong', '', props.title + ' ' ) );
		}
		fallback.appendChild( document.createTextNode( props.text || props.description || '' ) );
		node.appendChild( fallback );
		if ( window.packoutUi && 'function' === typeof window.packoutUi.mount ) {
			try {
				window.packoutUi.mount( node );
			} catch ( error ) {
				// The plain text stays.
			}
		}
		return node;
	}

	function start( root ) {
		var config = JSON.parse( root.getAttribute( 'data-pos-map' ) || '{}' );
		var text = config.text || {};
		var bar = root.querySelector( '.pos-map__bar' );
		var canvas = root.querySelector( '.pos-map__canvas' );
		var panel = root.querySelector( '.pos-map__panel' );
		var state = root.querySelector( '.pos-map__state' );
		var list = root.querySelector( '.pos-map__list' );
		var preset = config.preset || {};
		var spots = [];
		var count = el( 'span', 'pos-map__count' );
		var map = null;
		var ready = false;
		var featureMarkers = [];
		var lineToken = 0;
		var selectedKind = '';
		// The road or trail that is highlighted, so a pan or a zoom can follow it onto the tiles now in view.
		var routePick = null;
		// Spots start on. Places, routes, points, and boundaries wait until someone asks for them.
		var layers = { places: false, spots: true, route: false, point: false, boundary: false };
		// Roads, trails, and the basemap's own points start on. Forest Service maps start off.
		var basemap = { roads: true, trails: true, points: true };
		var forest = { mvum: false, nfstrails: false, forests: false, wilderness: false };
		// On a partner's site: what packout.spot shows in the site's region. Each starts off, and is read once, when first asked for.
		var hub = { hubSpots: false, hubPlaces: false, hubFeatures: false };
		var hubData = null;
		var hubAsked = false;
		var categoryMenu = null;
		var stageMenu = null;
		var submittedMenu = null;

		function selection( options, only ) {
			var on = {};
			Object.keys( options || {} ).forEach( function ( key ) {
				on[ key ] = ! only || only === key;
			} );
			return on;
		}
		var categories = selection( config.categories, preset.category || '' );
		var stages = selection( config.statuses, preset.status || '' );
		// One partner, or 0 for every Spot. Only partners the site lists are offered.
		var partnerNames = config.partners || {};
		var partner = partnerNames[ preset.partner ] ? Number( preset.partner ) : 0;
		var partnerSelect = null;
		// Whether the Spots have been answered for, and whether the answer was a failure.
		var answered = false;
		var failed = false;

		// A checkbox list. summaryAll is the button text when every box is checked.
		function checks( label, choices, summaryAll ) {
			var menuId = 'pos-checks-' + Math.random().toString( 36 ).slice( 2, 8 );
			var wrap = el( 'div', 'pos-map__filter pos-map__layers' );
			wrap.appendChild( el( 'span', 'pos-map__filter-label', label ) );
			var button = el( 'button', 'pos-map__layers-button' );
			button.type = 'button';
			button.setAttribute( 'aria-haspopup', 'true' );
			button.setAttribute( 'aria-expanded', 'false' );
			button.setAttribute( 'aria-controls', menuId );
			var menu = el( 'div', 'pos-map__layers-menu' );
			menu.id = menuId;
			menu.hidden = true;
			menu.setAttribute( 'role', 'group' );
			menu.setAttribute( 'aria-label', label );
			var boxes = {};
			var group = '';
			choices.forEach( function ( choice ) {
				if ( choice.group && choice.group !== group ) {
					group = choice.group;
					menu.appendChild( el( 'p', 'pos-map__layers-heading', choice.group ) );
					if ( choice.note ) {
						menu.appendChild( el( 'p', 'pos-map__layers-note', choice.note ) );
					}
				}
				var row = el( 'label' );
				var box = el( 'input' );
				box.type = 'checkbox';
				box.checked = !! choice.on;
				box.addEventListener( 'change', function () {
					choice.on = box.checked;
					apply();
					if ( choice.changed ) {
						choice.changed();
					}
				} );
				boxes[ choice.key ] = box;
				row.appendChild( box );
				row.appendChild( document.createTextNode( ' ' + choice.name ) );
				menu.appendChild( row );
			} );
			function summary() {
				var names = choices.filter( function ( choice ) {
					return choice.on;
				} ).map( function ( choice ) {
					return choice.name;
				} );
				if ( ! names.length ) {
					return text.layerNone || 'None';
				}
				if ( summaryAll && names.length === choices.length ) {
					return summaryAll;
				}
				return names.join( ', ' );
			}
			function apply() {
				var value = summary();
				button.textContent = value;
				button.setAttribute( 'aria-label', label + ', ' + value );
			}
			function setOpen( open ) {
				menu.hidden = ! open;
				button.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			}
			apply();
			button.addEventListener( 'click', function () {
				setOpen( menu.hidden );
			} );
			wrap.addEventListener( 'keydown', function ( event ) {
				if ( 'Escape' === event.key ) {
					setOpen( false );
					button.focus();
				}
			} );
			wrap.addEventListener( 'focusout', function ( event ) {
				if ( ! wrap.contains( event.relatedTarget ) ) {
					setOpen( false );
				}
			} );
			document.addEventListener( 'click', function ( event ) {
				if ( ! wrap.contains( event.target ) ) {
					setOpen( false );
				}
			} );
			wrap.appendChild( button );
			wrap.appendChild( menu );
			return {
				wrap: wrap,
				check: function ( key ) {
					var choice = choices.find( function ( item ) {
						return item.key === key;
					} );
					if ( ! choice || choice.on ) {
						return;
					}
					choice.on = true;
					boxes[ key ].checked = true;
					apply();
				},
				// The style has nothing for this choice, so the box cannot change the map.
				unavailable: function ( key, reason ) {
					var choice = choices.find( function ( item ) {
						return item.key === key;
					} );
					var box = boxes[ key ];
					if ( ! choice || ! box ) {
						return;
					}
					choice.on = false;
					box.checked = false;
					box.disabled = true;
					box.nextSibling.textContent = ' ' + choice.name + ' (' + reason + ')';
					apply();
				},
			};
		}

		function optionChoices( options, set, changed ) {
			return Object.keys( options || {} ).map( function ( key ) {
				var choice = { key: key, name: options[ key ], on: !! set[ key ] };
				choice.changed = function () {
					set[ key ] = choice.on;
					changed();
				};
				return choice;
			} );
		}

		// A site lists what it has. Places and map features are kept on packout.spot alone.
		var kept = Array.isArray( config.kinds ) ? config.kinds : [ 'places', 'spots', 'route', 'point', 'boundary' ];
		submittedMenu = checks( text.layers || 'Contributions', flagChoices( [
			[ 'places', text.layerPlaces || 'Places' ],
			[ 'spots', text.layerSpots || 'Spots' ],
			[ 'route', text.layerRoutes || 'Routes' ],
			[ 'point', text.layerPoints || 'Points of Interest' ],
			[ 'boundary', text.layerBounds || 'Boundaries' ],
		].filter( function ( pair ) {
			return kept.indexOf( pair[ 0 ] ) !== -1;
		} ), layers, function () {
			syncSpotChrome( shown() );
			paintLayers();
		} ) );
		bar.appendChild( submittedMenu.wrap );

		if ( config.filters ) {
			categoryMenu = checks( text.category || 'Category', optionChoices( config.categories, categories, function () {
				draw();
			} ), text.allCats || 'All categories' );
			stageMenu = checks( text.stage || 'Stage', optionChoices( config.statuses, stages, function () {
				draw();
			} ), text.allStages || 'Any stage' );
			bar.appendChild( categoryMenu.wrap );
			bar.appendChild( stageMenu.wrap );
			if ( Object.keys( partnerNames ).length ) {
				var partnerWrap = el( 'label', 'pos-map__filter' );
				partnerWrap.appendChild( el( 'span', 'pos-map__filter-label', text.partner || 'Partner' ) );
				partnerSelect = el( 'select' );
				var everyPartner = el( 'option', '', text.allPartners || 'Every partner' );
				everyPartner.value = '0';
				partnerSelect.appendChild( everyPartner );
				Object.keys( partnerNames ).sort( function ( a, b ) {
					return String( partnerNames[ a ] ).localeCompare( String( partnerNames[ b ] ) );
				} ).forEach( function ( id ) {
					var option = el( 'option', '', partnerNames[ id ] );
					option.value = id;
					partnerSelect.appendChild( option );
				} );
				partnerSelect.value = String( partner );
				partnerSelect.addEventListener( 'change', function () {
					partner = Number( partnerSelect.value ) || 0;
					draw();
				} );
				partnerWrap.appendChild( partnerSelect );
				bar.appendChild( partnerWrap );
			}
		}

		function flagChoices( names, set, changed ) {
			return names.map( function ( item ) {
				var choice = { key: item[ 0 ], name: item[ 1 ], on: !! set[ item[ 0 ] ], group: item[ 2 ] || '', note: item[ 3 ] || '' };
				choice.changed = function () {
					set[ item[ 0 ] ] = choice.on;
					changed();
				};
				return choice;
			} );
		}

		var overlayRows = [
			[ 'roads', text.baseRoads || 'Roads', text.baseGroup || 'OpenFreeMap', '', basemap ],
			[ 'trails', text.baseTrails || 'Trails', '', '', basemap ],
			[ 'points', text.basePoints || 'Existing points of interest', '', '', basemap ],
			[ 'mvum', text.forestMvum || 'Motor vehicle use map', text.forestGroup || 'Forest Service', text.forestNote || '', forest ],
			[ 'nfstrails', text.forestTrails || 'National Forest trails', '', '', forest ],
			[ 'forests', text.forestLands || 'National Forests', '', '', forest ],
			[ 'wilderness', text.forestWild || 'Wilderness', '', '', forest ],
		];
		if ( config.hub && config.hub.rest ) {
			overlayRows.push( [ 'hubSpots', config.hub.spots, config.hub.group, '', hub ] );
			overlayRows.push( [ 'hubPlaces', config.hub.places, '', '', hub ] );
			overlayRows.push( [ 'hubFeatures', config.hub.features, '', '', hub ] );
		}
		var overlayMenu = checks( text.overlays || 'Map overlays', overlayRows.map( function ( item ) {
			var choice = { key: item[ 0 ], name: item[ 1 ], on: !! item[ 4 ][ item[ 0 ] ], group: item[ 2 ], note: item[ 3 ] };
			choice.changed = function () {
				item[ 4 ][ item[ 0 ] ] = choice.on;
				paintLayers();
				releaseLine();
			};
			return choice;
		} ) );
		bar.appendChild( overlayMenu.wrap );
		bar.appendChild( count );

		function showLayer( key ) {
			if ( layers[ key ] ) {
				return;
			}
			layers[ key ] = true;
			submittedMenu.check( key );
		}

		function allow( set, menu, key ) {
			if ( ! key || ! Object.prototype.hasOwnProperty.call( set, key ) || set[ key ] ) {
				return;
			}
			set[ key ] = true;
			if ( menu ) {
				menu.check( key );
			}
		}

		// A value the menu does not list stays visible while every listed choice is on.
		function picked( set, value ) {
			if ( Object.prototype.hasOwnProperty.call( set, value ) ) {
				return !! set[ value ];
			}
			return Object.keys( set ).every( function ( key ) {
				return set[ key ];
			} );
		}

		function shown() {
			return spots.filter( function ( spot ) {
				return picked( categories, spot.category && spot.category.slug ) && picked( stages, spot.status ) && ( ! partner || -1 !== ( spot.partners || [] ).indexOf( partner ) );
			} );
		}

		function setState( message, empty ) {
			state.textContent = '';
			state.hidden = ! message && ! empty;
			if ( empty ) {
				state.appendChild( ui( 'empty-state', { title: empty.title, description: empty.description } ) );
			} else if ( message ) {
				state.textContent = message;
			}
			state.classList.toggle( 'pos-map__state--empty', !! empty );
		}

		function openPanel( spot ) {
			dismissLine();
			panel.dataset.posCard = 'spot';
			panel.textContent = '';
			var close = el( 'button', 'pos-map__close', text.close );
			close.type = 'button';
			close.addEventListener( 'click', function () {
				panel.hidden = true;
			} );
			panel.appendChild( close );

			// The photos everyone may see, across the top of the card.
			var gallery = spot.gallery || [];
			panel.classList.toggle( 'pos-map__panel--photos', gallery.length > 0 );
			if ( gallery.length && window.packoutSlider ) {
				panel.appendChild( window.packoutSlider.build( gallery, { className: 'pos-slider--card', small: true, label: text.photos.replace( '%s', spot.title ) } ) );
			}

			// A Spot a partner's site sent, on the hub. Until the partner reviews it, the card says so in place of a stage.
			var through = spot.through || null;
			var awaiting = !! ( through && through.awaiting );
			panel.classList.toggle( 'pos-map__panel--partner', !! through );
			var badges = el( 'p', 'pos-map__badges' );
			badges.appendChild( awaiting ? ui( 'badge', { text: text.awaiting, intent: 'none' } ) : ui( 'badge', { text: config.statuses[ spot.status ] || spot.status, intent: INTENTS[ spot.status ] || 'none' } ) );
			badges.appendChild( el( 'span', 'pos-map__category', spot.category.name ) );
			panel.appendChild( badges );
			panel.appendChild( el( 'h3', 'pos-map__title', spot.title ) );
			if ( through ) {
				panel.appendChild( named( 'pos-map__where pos-map__through', text.through, through.name, through.url ) );
			}

			if ( spot.needs_authorization ) {
				panel.appendChild( ui( 'notice', { intent: 'warning', title: config.hazards[ spot.hazard ] || '', description: spot.guidance } ) );
			} else if ( spot.authorized ) {
				panel.appendChild( ui( 'notice', { intent: 'info', title: text.authorized, description: spot.guidance } ) );
			}
			panel.appendChild( el( 'p', 'pos-map__description', spot.description ) );
			if ( spot.place && ! config.place ) {
				var place = el( 'p', 'pos-map__where', text.place + ': ' );
				if ( spot.place.url ) {
					var placeLink = el( 'a', '', spot.place.name );
					placeLink.href = spot.place.url;
					place.appendChild( placeLink );
				} else {
					place.appendChild( document.createTextNode( spot.place.name ) );
				}
				panel.appendChild( place );
			}

			var where = '';
			if ( spot.location.lat === null ) {
				where = text.noPin;
			} else if ( awaiting ) {
				where = text.rounded;
			} else if ( spot.location.precision === 'coarse' ) {
				where = through ? text.approximatePartner : text.approximate;
			}
			if ( where ) {
				panel.appendChild( el( 'p', 'pos-map__where', where ) );
			}
			if ( spot.location.landmark ) {
				panel.appendChild( el( 'p', 'pos-map__where', text.landmark + ': ' + spot.location.landmark ) );
			}
			( spot.also || [] ).forEach( function ( other ) {
				panel.appendChild( named( 'pos-map__where pos-map__also', text.also, other.name, other.url ) );
			} );
			( spot.notes || [] ).forEach( function ( note ) {
				panel.appendChild( named( 'pos-map__where pos-map__also pos-map__note', note.text, note.name, note.url ) );
			} );
			if ( spot.reported_by && spot.reported && ! awaiting ) {
				var day = new Date( spot.reported + 'T12:00:00' );
				var reported = el( 'p', 'pos-map__where pos-map__reported' );
				var parts = text.reported.replace( '%1$s', isNaN( day ) ? spot.reported : day.toLocaleDateString( undefined, { year: 'numeric', month: 'long', day: 'numeric' } ) ).split( '%2$s' );
				reported.appendChild( document.createTextNode( parts[ 0 ] ) );
				if ( spot.reported_by.url ) {
					var reporter = el( 'a', '', spot.reported_by.label );
					reporter.href = spot.reported_by.url;
					reported.appendChild( reporter );
				} else {
					reported.appendChild( document.createTextNode( spot.reported_by.label ) );
				}
				reported.appendChild( document.createTextNode( parts[ 1 ] || '' ) );
				panel.appendChild( reported );
			}
			if ( through && through.site ) {
				// The record is kept on the partner's site. That is where a person acts on it.
				var kept = el( 'a', 'pos-map__link pos-map__link--partner', text.onPartner.replace( '%s', through.name ) );
				kept.href = through.site;
				kept.rel = 'noopener';
				panel.appendChild( kept );
			}
			if ( spot.url ) {
				var link = el( 'a', 'pos-map__link', text.open );
				link.href = spot.url;
				panel.appendChild( link );
			}
			panel.hidden = false;
			close.focus();

			if ( map && spot.location.lat !== null ) {
				map.easeTo( { center: [ spot.location.lng, spot.location.lat ], zoom: Math.max( map.getZoom(), 11 ) } );
			}
		}

		/** A line of the card that names something, as a link when it has a page: "Reported through {name}". */
		function named( className, pattern, name, url ) {
			var line = el( 'p', className );
			var parts = pattern.split( '%s' );
			line.appendChild( document.createTextNode( parts[ 0 ] ) );
			if ( url ) {
				var link = el( 'a', '', name );
				link.href = url;
				line.appendChild( link );
			} else {
				line.appendChild( document.createTextNode( name ) );
			}
			line.appendChild( document.createTextNode( parts[ 1 ] || '' ) );
			return line;
		}

		/** The outlines to draw: every listed Place, or the one this page is about. */
		function outlines() {
			return ( config.outlines || [] ).filter( function ( outline ) {
				return ! config.place || outline.id === config.place;
			} );
		}

		function outlineFeatures( kind ) {
			return {
				type: 'FeatureCollection',
				features: outlines().filter( function ( outline ) {
					// The page of a Place is headed with its name, so its own map does not repeat it over the Spots.
					return kind !== 'label' || outline.id !== config.place;
				} ).map( function ( outline ) {
					var ring = outline.ring.concat( [ outline.ring[ 0 ] ] );
					var middle = outline.ring.reduce( function ( sum, point ) {
						return [ sum[ 0 ] + point[ 0 ] / outline.ring.length, sum[ 1 ] + point[ 1 ] / outline.ring.length ];
					}, [ 0, 0 ] );
					return {
						type: 'Feature',
						geometry: kind === 'label' ? { type: 'Point', coordinates: middle } : { type: 'Polygon', coordinates: [ ring ] },
						properties: { id: outline.id, name: outline.name },
					};
				} ),
			};
		}

		function features( items ) {
			return {
				type: 'FeatureCollection',
				features: items.filter( function ( spot ) {
					return spot.location.lat !== null;
				} ).map( function ( spot ) {
					return {
						type: 'Feature',
						geometry: { type: 'Point', coordinates: [ spot.location.lng, spot.location.lat ] },
						properties: { id: spot.id, status: spot.status, hazard: spot.needs_authorization ? 1 : 0, coarse: spot.location.precision === 'coarse' ? 1 : 0, awaiting: spot.through && spot.through.awaiting ? 1 : 0 },
					};
				} ),
			};
		}

		function syncSpotChrome( items ) {
			var links = list.querySelector( 'ul' );
			links.textContent = '';
			if ( ! layers.spots ) {
				count.textContent = '';
				list.hidden = true;
				setState( failed ? text.failed : '' );
				return;
			}
			count.textContent = failed ? '' : ( items.length === 1 ? text.one : text.count.replace( '%d', items.length ) );
			list.querySelector( 'summary' ).textContent = text.list + ' (' + items.length + ')';
			list.hidden = items.length === 0;
			items.forEach( function ( spot ) {
				var item = el( 'li' );
				var button = el( 'button', 'pos-map__list-item', spot.title );
				button.type = 'button';
				button.addEventListener( 'click', function () {
					openPanel( spot );
					root.scrollIntoView( { block: 'nearest' } );
				} );
				item.appendChild( button );
				item.appendChild( el( 'span', 'pos-map__list-meta', ' ' + ( config.statuses[ spot.status ] || spot.status ) ) );
				links.appendChild( item );
			} );

			if ( failed ) {
				setState( text.failed );
			} else if ( ! spots.length && config.place ) {
				// The outline is what there is to see.
				setState( '' );
			} else if ( ! spots.length ) {
				setState( '', { title: text.emptyTitle, description: text.emptyText } );
			} else if ( ! items.length ) {
				setState( text.noneMatch );
			} else {
				setState( '' );
			}
		}

		function draw() {
			if ( ! answered ) {
				// The map can be ready before the Spots arrive. The page keeps saying it is loading them.
				return;
			}
			var items = shown();
			syncSpotChrome( items );
			panel.hidden = true;
			if ( 'line' === panel.dataset.posCard ) {
				dismissLine();
			}

			if ( ! ready ) {
				return;
			}
			var data = features( items );
			map.getSource( 'spots' ).setData( data );
			paintLayers();
			var bounds = new window.maplibregl.LngLatBounds();
			data.features.forEach( function ( feature ) {
				bounds.extend( feature.geometry.coordinates );
			} );
			if ( config.place ) {
				outlines().forEach( function ( outline ) {
					outline.ring.forEach( function ( point ) {
						bounds.extend( point );
					} );
				} );
			}
			if ( ! bounds.isEmpty() ) {
				map.fitBounds( bounds, { padding: 60, maxZoom: config.place ? 15 : 12, duration: 0 } );
			}
		}

		function shownFeatures() {
			return ( config.features || [] ).filter( function ( feature ) {
				return ! config.place || ( feature.place && feature.place.id === config.place );
			} );
		}

		function featureCollection() {
			return {
				type: 'FeatureCollection',
				features: shownFeatures().map( function ( feature ) {
					return {
						type: 'Feature',
						geometry: feature.geometry,
						properties: {
							id: feature.id,
							name: feature.name,
							kind: feature.kind,
							color: feature.color || '#415840',
							icon: feature.icon || '',
							note: feature.description || '',
							place: feature.place && feature.place.name ? feature.place.name : '',
							url: feature.place && feature.place.url ? feature.place.url : '',
						},
					};
				} ),
			};
		}

		var tip = null;
		var tipId = 0;
		var tipTimer = null;

		function hideTip() {
			clearTimeout( tipTimer );
			if ( tip ) {
				tip.remove();
				tip = null;
				tipId = 0;
			}
		}

		function hideTipSoon() {
			clearTimeout( tipTimer );
			tipTimer = setTimeout( hideTip, 180 );
		}

		function featureAt( feature ) {
			var geometry = feature.geometry;
			if ( ! geometry || ! geometry.coordinates ) {
				return null;
			}
			if ( 'Point' === geometry.type ) {
				return geometry.coordinates;
			}
			var ring = 'Polygon' === geometry.type ? ( geometry.coordinates[ 0 ] || [] ) : geometry.coordinates;
			if ( ! ring.length ) {
				return null;
			}
			var sum = ring.reduce( function ( total, point ) {
				return [ total[ 0 ] + point[ 0 ], total[ 1 ] + point[ 1 ] ];
			}, [ 0, 0 ] );
			return [ sum[ 0 ] / ring.length, sum[ 1 ] / ring.length ];
		}

		function showTip( feature, lngLat ) {
			clearTimeout( tipTimer );
			if ( ! lngLat || ! window.maplibregl ) {
				return;
			}
			if ( tip && tipId === feature.id ) {
				return;
			}
			hideTip();
			var box = el( 'div', 'pos-tip' );
			var photo = ( feature.gallery || [] )[ 0 ];
			if ( photo && photo.url ) {
				var image = el( 'img', 'pos-tip__img' );
				image.src = photo.url;
				image.alt = '';
				box.appendChild( image );
			}
			box.appendChild( el( 'p', 'pos-tip__label', feature.label || feature.name || '' ) );
			if ( feature.description ) {
				box.appendChild( el( 'p', 'pos-tip__note', feature.description ) );
			}
			tip = new window.maplibregl.Popup( { closeButton: false, closeOnClick: false, offset: 22, className: 'pos-tip-popup', maxWidth: '240px' } )
				.setLngLat( lngLat )
				.setDOMContent( box )
				.addTo( map );
			tipId = feature.id;
			var shell = tip.getElement();
			if ( shell ) {
				shell.addEventListener( 'mouseenter', function () {
					clearTimeout( tipTimer );
				} );
				shell.addEventListener( 'mouseleave', hideTipSoon );
			}
		}

		function openFeature( feature ) {
			hideTip();
			dismissLine();
			panel.dataset.posCard = 'feature';
			panel.textContent = '';
			var close = el( 'button', 'pos-map__close', text.close );
			close.type = 'button';
			close.addEventListener( 'click', function () {
				panel.hidden = true;
			} );
			panel.appendChild( close );

			var gallery = feature.gallery || [];
			panel.classList.toggle( 'pos-map__panel--photos', gallery.length > 0 );
			if ( gallery.length && window.packoutSlider ) {
				panel.appendChild( window.packoutSlider.build( gallery, { className: 'pos-slider--card', small: true, label: ( text.photos || 'Photos of %s' ).replace( '%s', feature.name || '' ) } ) );
			}

			if ( feature.kind_label ) {
				panel.appendChild( el( 'p', 'pos-map__meta', feature.kind_label ) );
			}
			panel.appendChild( el( 'h3', 'pos-map__title', feature.label || feature.name || '' ) );
			if ( feature.description ) {
				panel.appendChild( el( 'p', 'pos-map__description', feature.description ) );
			}
			if ( feature.place && feature.place.url ) {
				var where = el( 'p', 'pos-map__where', ( text.place || 'Place' ) + ': ' );
				var placeLink = el( 'a', '', feature.place.name );
				placeLink.href = feature.place.url;
				where.appendChild( placeLink );
				panel.appendChild( where );
			}
			( feature.sponsors || [] ).forEach( function ( sponsor ) {
				panel.appendChild( el( 'p', 'pos-map__meta', sponsor.name ) );
			} );
			if ( feature.url ) {
				var viewLabel = text.openFeature || 'View this %s';
				if ( -1 !== viewLabel.indexOf( '%s' ) ) {
					viewLabel = viewLabel.replace( '%s', feature.kind_label || '' );
				}
				var page = el( 'a', 'pos-map__link', viewLabel );
				page.href = feature.url;
				panel.appendChild( page );
			}
			if ( feature.editable && window.packoutMapEditor && window.packoutMapEditor.open ) {
				var editLabel = text.editFeature || 'Edit this %s';
				if ( -1 !== editLabel.indexOf( '%s' ) ) {
					editLabel = editLabel.replace( '%s', feature.kind_label || '' );
				}
				var edit = el( 'button', 'pos-map__link', editLabel );
				edit.type = 'button';
				edit.addEventListener( 'click', function () {
					window.packoutMapEditor.open( { feature: feature } );
				} );
				panel.appendChild( edit );
			}
			panel.hidden = false;
			close.focus();
		}

		function watchFeature( node, feature, at ) {
			node.addEventListener( 'mouseenter', function () {
				showTip( feature, at || featureAt( feature ) );
			} );
			node.addEventListener( 'mouseleave', hideTipSoon );
		}

		function featurePin( feature ) {
			var wrap = el( 'div', 'pos-map__marker' );
			var button = el( 'button', 'pos-map__pin' );
			button.type = 'button';
			button.style.color = feature.color || '#415840';
			button.setAttribute( 'aria-label', feature.label || feature.name || '' );
			var glyph = el( 'span', 'pos-map__glyph' );
			glyph.setAttribute( 'aria-hidden', 'true' );
			var markup = ( config.icons || {} )[ feature.icon ] || ( config.icons || {} )[ 'mdi:map-marker' ] || '';
			if ( markup ) {
				glyph.innerHTML = markup;
			}
			button.appendChild( glyph );
			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				event.stopPropagation();
				openFeature( feature );
			} );
			wrap.appendChild( button );
			wrap.appendChild( el( 'span', 'pos-map__label', feature.label || feature.name || '' ) );
			watchFeature( wrap, feature, feature.geometry.coordinates );
			return wrap;
		}

		function refreshFeatureMarkers() {
			featureMarkers.forEach( function ( marker ) {
				marker.remove();
			} );
			featureMarkers = [];
			if ( ! map ) {
				return;
			}
			shownFeatures().forEach( function ( feature ) {
				if ( ! layers[ feature.kind ] ) {
					return;
				}
				var at = featureAt( feature );
				if ( ! at ) {
					return;
				}
				var marker;
				if ( feature.geometry && 'Point' === feature.geometry.type ) {
					marker = new window.maplibregl.Marker( { element: featurePin( feature ), anchor: 'center' } ).setLngLat( at ).addTo( map );
				} else {
					var label = el( 'button', 'pos-map__label pos-map__label--place', feature.label || feature.name || '' );
					label.type = 'button';
					label.addEventListener( 'click', function ( event ) {
						event.preventDefault();
						event.stopPropagation();
						openFeature( feature );
					} );
					watchFeature( label, feature, at );
					marker = new window.maplibregl.Marker( { element: label, anchor: 'center' } ).setLngLat( at ).addTo( map );
				}
				featureMarkers.push( marker );
			} );
		}

		function filled( value ) {
			if ( value === undefined || value === null ) {
				return '';
			}
			var reading = String( value ).trim();
			if ( ! reading || 'Null' === reading || '<Null>' === reading || 'null' === reading ) {
				return '';
			}
			return reading;
		}

		function firstAttr( attrs, keys ) {
			var found = '';
			( keys || [] ).some( function ( key ) {
				found = filled( attrs[ key ] );
				return '' !== found;
			} );
			return found;
		}

		function dismissLine() {
			lineToken += 1;
			selectedKind = '';
			routePick = null;
			if ( map && map.getSource( 'selection' ) ) {
				map.getSource( 'selection' ).setData( { type: 'FeatureCollection', features: [] } );
			}
		}

		function releaseLine() {
			var hidden = ( 'road' === selectedKind && ! basemap.roads ) || ( 'trail' === selectedKind && ! basemap.trails ) || ( 'mvum' === selectedKind && ! forest.mvum );
			if ( hidden ) {
				dismissLine();
				if ( 'line' === panel.dataset.posCard ) {
					panel.hidden = true;
				}
			}
		}

		function showHighlight( lines ) {
			if ( ! map.getSource( 'selection' ) || ! lines.length ) {
				return;
			}
			map.getSource( 'selection' ).setData( {
				type: 'Feature',
				properties: {},
				geometry: { type: 'MultiLineString', coordinates: lines },
			} );
		}

		function lineCard( kind, title, facts, source, focus ) {
			hideTip();
			panel.dataset.posCard = 'line';
			panel.classList.remove( 'pos-map__panel--photos' );
			panel.textContent = '';
			var close = el( 'button', 'pos-map__close', text.close );
			close.type = 'button';
			close.addEventListener( 'click', function () {
				panel.hidden = true;
				dismissLine();
			} );
			panel.appendChild( close );
			panel.appendChild( el( 'p', 'pos-map__meta', kind ) );
			panel.appendChild( el( 'h3', 'pos-map__title', title ) );
			facts.forEach( function ( fact ) {
				if ( ! fact[ 1 ] ) {
					return;
				}
				var row = el( 'p', 'pos-map__where' );
				var label = el( 'strong', '', fact[ 0 ] + ': ' );
				row.appendChild( label );
				row.appendChild( document.createTextNode( fact[ 1 ] ) );
				panel.appendChild( row );
			} );
			if ( source ) {
				panel.appendChild( el( 'p', 'pos-map__source', source ) );
			}
			panel.hidden = false;
			if ( false !== focus ) {
				close.focus();
			}
		}

		function asLines( features ) {
			var lines = [];
			features.forEach( function ( feature ) {
				var geometry = feature.geometry || {};
				if ( 'LineString' === geometry.type && geometry.coordinates && geometry.coordinates.length > 1 ) {
					lines.push( geometry.coordinates );
				} else if ( 'MultiLineString' === geometry.type ) {
					( geometry.coordinates || [] ).forEach( function ( line ) {
						if ( line.length > 1 ) {
							lines.push( line );
						}
					} );
				}
			} );
			return lines;
		}

		function lineGeometry( feature ) {
			var geometry = feature.geometry || {};
			return 'LineString' === geometry.type || 'MultiLineString' === geometry.type;
		}

		function lineLayerIds( group ) {
			if ( ! map.getStyle() || ! map.getStyle().layers ) {
				return [];
			}
			return basemapGroups( map.getStyle().layers )[ group ].filter( function ( id ) {
				var layer = map.getLayer( id );
				if ( ! layer || 'none' === map.getLayoutProperty( id, 'visibility' ) ) {
					return false;
				}
				// Names are drawn on symbol layers that still follow the road.
				return 'line' === layer.type || ( 'symbol' === layer.type && 0 === id.indexOf( 'highway-name-' ) );
			} );
		}

		function linesOf( feature ) {
			var geometry = feature.geometry || {};
			if ( 'LineString' === geometry.type ) {
				return [ geometry.coordinates ];
			}
			if ( 'MultiLineString' === geometry.type ) {
				return geometry.coordinates || [];
			}
			return [];
		}

		function nearestLine( feature, point ) {
			var best = null;
			var bestDist = Infinity;
			linesOf( feature ).forEach( function ( line ) {
				if ( ! line || line.length < 2 ) {
					return;
				}
				var dist = Infinity;
				var index;
				for ( index = 0; index < line.length; index++ ) {
					var px = map.project( line[ index ] );
					var dx = px.x - point.x;
					var dy = px.y - point.y;
					var apart = ( dx * dx ) + ( dy * dy );
					if ( apart < dist ) {
						dist = apart;
					}
				}
				if ( dist < bestDist ) {
					bestDist = dist;
					best = line;
				}
			} );
			return best ? { line: best, dist: bestDist } : null;
		}

		function pickLine( ids, point ) {
			if ( ! ids.length ) {
				return null;
			}
			var box = [ [ point.x - 14, point.y - 14 ], [ point.x + 14, point.y + 14 ] ];
			var found = map.queryRenderedFeatures( box, { layers: ids } ).filter( lineGeometry );
			var best = null;
			var named = null;
			var casing = null;
			found.forEach( function ( feature ) {
				var near = nearestLine( feature, point );
				if ( ! near || near.dist > 14 * 14 ) {
					return;
				}
				var props = feature.properties || {};
				var candidate = { feature: feature, line: near.line, dist: near.dist };
				if ( ! best || near.dist < best.dist ) {
					best = candidate;
				}
				if ( ( filled( props.name ) || filled( props.ref ) ) && ( ! named || near.dist < named.dist ) ) {
					named = candidate;
				}
				if ( feature.layer && 'line' === feature.layer.type && ( ! casing || near.dist < casing.dist ) ) {
					casing = candidate;
				}
			} );
			// The drawn line is often closer than its name. Keep the name when it is on that same line.
			var chosen = named && ( ! best || named.dist <= best.dist + ( 12 * 12 ) ) ? named : best;
			if ( ! chosen ) {
				return null;
			}
			return {
				properties: Object.assign( {}, ( casing && casing.feature.properties ) || {}, chosen.feature.properties || {} ),
				line: chosen.line,
				dist: chosen.dist,
			};
		}

		function routeNumber( props ) {
			return filled( ( props || {} ).ref ) || filled( ( props || {} ).route_1_ref );
		}

		function sameRouteNumber( left, right ) {
			if ( ! left || ! right ) {
				return false;
			}
			if ( left === right ) {
				return true;
			}
			return left.replace( /[^a-z0-9]/gi, '' ).toLowerCase() === right.replace( /[^a-z0-9]/gi, '' ).toLowerCase();
		}

		// Shields and road names are drawn along the whole route, so they join pieces the line layers leave apart.
		function identityLayers() {
			if ( ! map.getStyle() || ! map.getStyle().layers ) {
				return [];
			}
			return map.getStyle().layers.filter( function ( layer ) {
				var id = layer.id || '';
				if ( 'symbol' !== layer.type ) {
					return false;
				}
				if ( -1 === id.indexOf( 'shield' ) && 0 !== id.indexOf( 'highway-name-' ) ) {
					return false;
				}
				return 'none' !== map.getLayoutProperty( id, 'visibility' );
			} ).map( function ( layer ) {
				return layer.id;
			} );
		}

		// A freeway and its frontage sit next to each other. The shield says which one was meant.
		function preferRoute( hit, point, ids ) {
			var identified = readIdentity( hit, point );
			var want = ( identified.properties || {} ).class || '';
			if ( ! want || ( ( hit.properties || {} ).class || '' ) === want ) {
				return identified;
			}
			var box = [ [ point.x - 18, point.y - 18 ], [ point.x + 18, point.y + 18 ] ];
			var best = null;
			map.queryRenderedFeatures( box, { layers: ids } ).filter( lineGeometry ).forEach( function ( feature ) {
				var layerId = ( feature.layer && feature.layer.id ) || '';
				if ( ! feature.layer || 'line' !== feature.layer.type || -1 !== layerId.indexOf( 'casing' ) || -1 !== layerId.indexOf( 'link' ) ) {
					return;
				}
				if ( ( ( feature.properties || {} ).class || '' ) !== want ) {
					return;
				}
				var near = nearestLine( feature, point );
				if ( near && ( ! best || near.dist < best.dist ) ) {
					best = { feature: feature, line: near.line, dist: near.dist };
				}
			} );
			if ( ! best ) {
				return identified;
			}
			return {
				properties: Object.assign( {}, best.feature.properties, {
					name: identified.properties.name || '',
					ref: identified.properties.ref || '',
					network: identified.properties.network || '',
				} ),
				line: best.line,
				dist: best.dist,
			};
		}

		function readIdentity( hit, point ) {
			var ids = identityLayers();
			if ( ! hit || ! ids.length ) {
				return hit;
			}
			var bestShield = null;
			var bestName = null;
			map.queryRenderedFeatures( { layers: ids } ).filter( lineGeometry ).forEach( function ( feature ) {
				var near = nearestLine( feature, point );
				if ( ! near || near.dist > 28 * 28 ) {
					return;
				}
				var chosen = routeNumber( feature.properties ) ? 'shield' : 'name';
				var best = 'shield' === chosen ? bestShield : bestName;
				if ( best && near.dist >= best.dist ) {
					return;
				}
				var found = { feature: feature, dist: near.dist };
				if ( 'shield' === chosen ) {
					bestShield = found;
				} else {
					bestName = found;
				}
			} );
			var best = bestShield || bestName;
			if ( ! best ) {
				return hit;
			}
			var props = best.feature.properties || {};
			var next = {
				name: filled( props.name ) || ( hit.properties || {} ).name || '',
				ref: routeNumber( props ) || ( hit.properties || {} ).ref || '',
				network: props.network || ( hit.properties || {} ).network || '',
			};
			// The shield's class is the freeway, even when a frontage road is the line under the cursor.
			if ( routeNumber( props ) && props.class ) {
				next.class = props.class;
			}
			hit.properties = Object.assign( {}, hit.properties, next );
			return hit;
		}

		// The road's name is drawn along it, not stored on the line that takes the click.
		function attachName( hit, point, ids ) {
			var nameIds = ids.filter( function ( id ) {
				return 0 === id.indexOf( 'highway-name-' );
			} );
			if ( ! hit || ! nameIds.length ) {
				return hit;
			}
			var best = null;
			map.queryRenderedFeatures( { layers: nameIds } ).filter( lineGeometry ).forEach( function ( feature ) {
				var near = nearestLine( feature, point );
				if ( near && near.dist <= 16 * 16 && ( ! best || near.dist < best.dist ) ) {
					best = { feature: feature, line: near.line, dist: near.dist };
				}
			} );
			if ( ! best ) {
				return hit;
			}
			hit.properties = Object.assign( {}, hit.properties, best.feature.properties );
			return hit;
		}

		function metersApart( left, right ) {
			var mean = ( ( left[ 1 ] + right[ 1 ] ) / 2 ) * Math.PI / 180;
			var dx = ( left[ 0 ] - right[ 0 ] ) * 111320 * Math.cos( mean );
			var dy = ( left[ 1 ] - right[ 1 ] ) * 110540;
			return Math.sqrt( ( dx * dx ) + ( dy * dy ) );
		}

		function drawnLineIds( ids ) {
			return ( ids || [] ).filter( function ( id ) {
				var layer = map.getLayer( id );
				// The casing is drawn beside the road, so its ends do not meet the next tile.
				return layer && 'line' === layer.type && 'none' !== map.getLayoutProperty( id, 'visibility' ) && -1 === id.indexOf( 'casing' );
			} );
		}

		// Direction of travel away from one end, skipping a vertex that sits on top of it.
		function endBearing( coords, atStart ) {
			var origin = atStart ? 0 : coords.length - 1;
			var step = atStart ? 1 : -1;
			var index = origin;
			var guard = 0;
			while ( guard < 8 ) {
				index += step;
				if ( index < 0 || index >= coords.length ) {
					break;
				}
				if ( metersApart( coords[ origin ], coords[ index ] ) > 1 ) {
					return Math.atan2( coords[ index ][ 0 ] - coords[ origin ][ 0 ], coords[ index ][ 1 ] - coords[ origin ][ 1 ] ) * 180 / Math.PI;
				}
				guard += 1;
			}
			var other = atStart ? Math.min( 1, coords.length - 1 ) : Math.max( 0, coords.length - 2 );
			return Math.atan2( coords[ other ][ 0 ] - coords[ origin ][ 0 ], coords[ other ][ 1 ] - coords[ origin ][ 1 ] ) * 180 / Math.PI;
		}

		function turnBetween( left, right ) {
			var diff = Math.abs( left - right ) % 360;
			return diff > 180 ? 360 - diff : diff;
		}

		// Pieces of one road are cut at tile edges. Follow the piece that continues, across every tile in view.
		function routeLines( pick ) {
			var lineIds = drawnLineIds( pick.ids );
			var segments = [];
			if ( lineIds.length ) {
				map.queryRenderedFeatures( { layers: lineIds } ).filter( lineGeometry ).forEach( function ( feature ) {
					var props = feature.properties || {};
					if ( ( props.class || '' ) !== pick.className ) {
						return;
					}
					var layerId = ( feature.layer && feature.layer.id ) || '';
					linesOf( feature ).forEach( function ( line ) {
						if ( line && line.length > 1 ) {
							segments.push( { coords: line, name: filled( props.name ), ref: filled( props.ref ), link: -1 !== layerId.indexOf( 'link' ) } );
						}
					} );
				} );
			}
			if ( ! segments.length ) {
				return pick.seed ? [ pick.seed ] : [];
			}
			// A numbered highway, or a named road, is one route across every tile now on screen.
			var guides = [];
			var identityIds = identityLayers();
			if ( identityIds.length && ( pick.ref || pick.name ) ) {
				map.queryRenderedFeatures( { layers: identityIds } ).filter( lineGeometry ).forEach( function ( feature ) {
					var props = feature.properties || {};
					var ref = routeNumber( props );
					var name = filled( props.name );
					if ( pick.network && props.network && props.network !== pick.network ) {
						return;
					}
					if ( pick.ref ) {
						if ( ! sameRouteNumber( pick.ref, ref ) ) {
							return;
						}
					} else if ( name !== pick.name ) {
						return;
					}
					linesOf( feature ).forEach( function ( line ) {
						if ( line && line.length > 1 ) {
							guides.push( line );
						}
					} );
				} );
			}
			if ( guides.length ) {
				var along = [];
				segments.forEach( function ( segment ) {
					if ( segment.link ) {
						return;
					}
					var nearest = Infinity;
					var guideBearing = null;
					segment.coords.forEach( function ( coord ) {
						guides.forEach( function ( guide ) {
							var index;
							for ( index = 0; index < guide.length; index++ ) {
								var apart = metersApart( coord, guide[ index ] );
								if ( apart < nearest ) {
									nearest = apart;
									var from = guide[ Math.max( 0, index - 1 ) ];
									var to = guide[ Math.min( guide.length - 1, index + 1 ) ];
									guideBearing = roadBearing( [ from, to ] );
								}
							}
						} );
					} );
					if ( nearest <= 100 && ( null === guideBearing || bearingDelta( roadBearing( segment.coords ), guideBearing ) <= 30 ) ) {
						along.push( segment.coords );
					}
				} );
				if ( along.length ) {
					return along;
				}
			}
			function sameEnds( line ) {
				if ( ! pick.seed || line.length < 2 || pick.seed.length < 2 ) {
					return false;
				}
				var forward = metersApart( line[ 0 ], pick.seed[ 0 ] ) < 1 && metersApart( line[ line.length - 1 ], pick.seed[ pick.seed.length - 1 ] ) < 1;
				var back = metersApart( line[ 0 ], pick.seed[ pick.seed.length - 1 ] ) < 1 && metersApart( line[ line.length - 1 ], pick.seed[ 0 ] ) < 1;
				return forward || back;
			}
			var seed = -1;
			var index;
			for ( index = 0; index < segments.length; index++ ) {
				if ( sameEnds( segments[ index ].coords ) ) {
					seed = index;
					break;
				}
			}
			if ( seed < 0 && pick.at ) {
				var best = Infinity;
				segments.forEach( function ( segment, segmentIndex ) {
					if ( pick.name && segment.name && segment.name !== pick.name ) {
						return;
					}
					segment.coords.forEach( function ( coord ) {
						var apart = metersApart( coord, pick.at );
						if ( apart < best ) {
							best = apart;
							seed = segmentIndex;
						}
					} );
				} );
				if ( best > 80 ) {
					seed = -1;
				}
			}
			if ( seed < 0 ) {
				return pick.seed ? [ pick.seed ] : [];
			}
			var buckets = {};
			function bucketKey( coord ) {
				return Math.round( coord[ 0 ] * 4000 ) + ':' + Math.round( coord[ 1 ] * 4000 );
			}
			segments.forEach( function ( segment, segmentIndex ) {
				[ segment.coords[ 0 ], segment.coords[ segment.coords.length - 1 ] ].forEach( function ( coord, which ) {
					var key = bucketKey( coord );
					buckets[ key ] = buckets[ key ] || [];
					buckets[ key ].push( { index: segmentIndex, which: which, coord: coord } );
				} );
			} );
			function touches( coord ) {
				var cx = Math.round( coord[ 0 ] * 4000 );
				var cy = Math.round( coord[ 1 ] * 4000 );
				var found = [];
				var dx;
				var dy;
				for ( dx = -5; dx <= 5; dx++ ) {
					for ( dy = -5; dy <= 5; dy++ ) {
						( buckets[ ( cx + dx ) + ':' + ( cy + dy ) ] || [] ).forEach( function ( item ) {
							if ( metersApart( coord, item.coord ) <= 110 ) {
								found.push( item );
							}
						} );
					}
				}
				return found;
			}
			function canJoin( from, to ) {
				if ( pick.name && from.name && to.name && from.name !== to.name ) {
					return false;
				}
				if ( pick.ref && from.ref && to.ref && from.ref !== to.ref ) {
					return false;
				}
				return true;
			}
			function roadBearing( coords ) {
				var from = coords[ 0 ];
				var to = coords[ coords.length - 1 ];
				var deg = Math.atan2( to[ 0 ] - from[ 0 ], to[ 1 ] - from[ 1 ] ) * 180 / Math.PI;
				return ( deg % 180 + 180 ) % 180;
			}
			function bearingDelta( left, right ) {
				var diff = Math.abs( left - right ) % 180;
				return diff > 90 ? 180 - diff : diff;
			}
			var course = roadBearing( segments[ seed ].coords );
			function sameCourse( segment ) {
				var length = 0;
				var index;
				for ( index = 1; index < segment.coords.length; index++ ) {
					length += metersApart( segment.coords[ index - 1 ], segment.coords[ index ] );
				}
				// A short link at an intersection can sit at an angle. A longer piece has to be the same road.
				return length < 40 || bearingDelta( course, roadBearing( segment.coords ) ) <= 20;
			}
			var used = {};
			var queue = [];
			function enqueue( segmentIndex ) {
				if ( segmentIndex < 0 || used[ segmentIndex ] ) {
					return;
				}
				used[ segmentIndex ] = true;
				queue.push( segmentIndex );
			}
			enqueue( seed );
			segments.forEach( function ( segment, segmentIndex ) {
				if ( ! sameCourse( segment ) ) {
					return;
				}
				if ( pick.name && segment.name === pick.name ) {
					enqueue( segmentIndex );
				} else if ( ! pick.name && pick.ref && segment.ref === pick.ref ) {
					enqueue( segmentIndex );
				}
			} );
			var guard = 0;
			while ( queue.length && guard < 4000 ) {
				guard += 1;
				var current = queue.shift();
				var segment = segments[ current ];
				[ true, false ].forEach( function ( atStart ) {
					var end = atStart ? segment.coords[ 0 ] : segment.coords[ segment.coords.length - 1 ];
					// endBearing points back along this piece. The next piece leaves in the direction we were heading.
					var travel = endBearing( segment.coords, atStart ) + 180;
					var closest = {};
					touches( end ).forEach( function ( item ) {
						if ( item.index === current || used[ item.index ] || ! canJoin( segment, segments[ item.index ] ) || ! sameCourse( segments[ item.index ] ) ) {
							return;
						}
						var gap = metersApart( end, item.coord );
						if ( ! closest[ item.index ] || gap < closest[ item.index ].gap ) {
							closest[ item.index ] = { which: item.which, gap: gap };
						}
					} );
					var options = [];
					Object.keys( closest ).forEach( function ( key ) {
						var item = closest[ key ];
						var leave = endBearing( segments[ key ].coords, 0 === item.which );
						options.push( { index: Number( key ), turn: turnBetween( travel, leave ) } );
					} );
					if ( ! options.length ) {
						return;
					}
					options.sort( function ( left, right ) {
						return left.turn - right.turn;
					} );
					// One piece across a tile edge can bend. Where several meet, keep the ones that continue.
					var limit = 1 === options.length ? 50 : 22;
					options.forEach( function ( option ) {
						if ( option.turn <= limit ) {
							enqueue( option.index );
						}
					} );
				} );
			}
			return Object.keys( used ).map( function ( key ) {
				return segments[ key ].coords;
			} );
		}

		function paintRoute() {
			if ( ! routePick ) {
				return;
			}
			var lines = routeLines( routePick );
			if ( lines.length ) {
				showHighlight( lines );
			}
		}

		function renderLineCard( focus ) {
			if ( ! routePick ) {
				return;
			}
			var pick = routePick;
			var title = pick.name || pick.ref || ( 'trail' === pick.kind ? ( text.lineUnnamedTrail || 'Unnamed trail' ) : ( text.lineUnnamedRoad || 'Unnamed road' ) );
			var kindLabel = LINE_CLASS[ pick.className ] || ( 'trail' === pick.kind ? ( text.lineTrail || 'Trail' ) : ( text.lineRoad || 'Road' ) );
			var facts = [];
			if ( pick.ref && pick.ref !== title ) {
				facts.push( [ text.lineRef || 'Reference', pick.ref ] );
			}
			if ( pick.surface ) {
				facts.push( [ text.lineSurface || 'Surface', pick.surface ] );
			}
			if ( 1 === pick.oneway || '1' === String( pick.oneway ) || -1 === pick.oneway || '-1' === String( pick.oneway ) ) {
				facts.push( [ text.lineOneWay || 'One way', text.lineYes || 'Yes' ] );
			}
			if ( 'bridge' === pick.brunnel ) {
				facts.push( [ text.lineBridge || 'Bridge', text.lineYes || 'Yes' ] );
			} else if ( 'tunnel' === pick.brunnel ) {
				facts.push( [ text.lineTunnel || 'Tunnel', text.lineYes || 'Yes' ] );
			}
			lineCard( kindLabel, title, facts, text.lineOsm || 'OpenStreetMap, via OpenFreeMap', focus );
		}

		// The label layers are not drawn at every zoom. When they are, copy the name they carry.
		function nameFromLabels( pick ) {
			if ( ! pick.at || pick.namedByMap ) {
				return;
			}
			var point = map.project( pick.at );
			var named = attachName( { properties: { class: pick.className }, line: pick.seed }, point, pick.ids );
			var labelClass = ( named.properties || {} ).class || '';
			if ( pick.className && labelClass && labelClass !== pick.className ) {
				return;
			}
			var name = filled( ( named.properties || {} ).name );
			var ref = filled( ( named.properties || {} ).ref );
			if ( ! name && ! ref ) {
				return;
			}
			pick.name = name || pick.name;
			pick.ref = ref || pick.ref;
			pick.surface = pick.surface || filled( ( named.properties || {} ).surface );
			pick.namedByMap = true;
			if ( 'line' === panel.dataset.posCard ) {
				renderLineCard( false );
			}
		}

		function lookupLine( at, token ) {
			if ( ! config.rest ) {
				return;
			}
			var address = config.rest + 'line' + ( config.rest.indexOf( '?' ) === -1 ? '?' : '&' ) + 'lat=' + encodeURIComponent( at[ 1 ] ) + '&lng=' + encodeURIComponent( at[ 0 ] );
			fetch( address ).then( function ( response ) {
				return response.ok ? response.json() : null;
			} ).then( function ( row ) {
				if ( token !== lineToken || ! routePick || ! row || routePick.namedByMap ) {
					return;
				}
				// A different kind of road nearby must not replace the one that was clicked.
				var rowClass = filled( row.class );
				if ( rowClass && routePick.className && rowClass !== routePick.className ) {
					return;
				}
				var named = filled( row.name );
				var ref = filled( row.ref );
				if ( ! routePick.name && named ) {
					routePick.name = named;
				}
				if ( ! routePick.ref && ref ) {
					routePick.ref = ref;
				}
				if ( ! routePick.surface && filled( row.surface ) ) {
					routePick.surface = filled( row.surface );
				}
				paintRoute();
				if ( 'line' === panel.dataset.posCard && ( routePick.name || routePick.ref ) ) {
					renderLineCard( false );
				}
			} ).catch( function () {} );
		}

		function showBasemapLine( hit, kind, ids ) {
			var props = hit.properties || {};
			routePick = {
				kind: kind,
				ids: ids.slice(),
				className: props.class || '',
				name: filled( props.name ),
				ref: filled( props.ref ),
				network: props.network || '',
				surface: filled( props.surface ),
				oneway: props.oneway,
				brunnel: props.brunnel || '',
				seed: hit.line,
				at: hit.at || null,
				token: lineToken,
				namedByMap: '' !== filled( props.name ) || '' !== filled( props.ref ),
			};
			selectedKind = kind;
			paintRoute();
			renderLineCard( true );
			if ( ! routePick.namedByMap && routePick.at ) {
				lookupLine( routePick.at, lineToken );
			}
		}

		function mvumPaths( geometry ) {
			var lines = [];
			( ( geometry && geometry.paths ) || [] ).forEach( function ( path ) {
				if ( path.length > 1 ) {
					lines.push( path );
				}
			} );
			return lines;
		}

		function showMvum( result, token ) {
			var attrs = result.attributes || {};
			var trail = 2 === result.layerId;
			var name = firstAttr( attrs, [ 'Name', 'name' ] );
			var number = firstAttr( attrs, [ 'ID for Map Label', 'ID', 'id', 'field_id' ] );
			var title = name || number || ( trail ? ( text.lineMvumTrail || 'Motor vehicle trail' ) : ( text.lineMvumRoad || 'Motor vehicle road' ) );
			var facts = [];
			var designation = firstAttr( attrs, [ 'MVUM_Symbol_Name', 'mvum_symbol_name' ] );
			if ( designation ) {
				facts.push( [ trail ? ( text.lineTrail || 'Trail' ) : ( text.lineRoad || 'Road' ), designation ] );
			}
			facts.push( [ text.lineForest || 'Forest', firstAttr( attrs, [ 'Forest Name', 'forestname' ] ) ] );
			facts.push( [ text.lineDistrict || 'Ranger district', firstAttr( attrs, [ 'Ranger District Name', 'districtname' ] ) ] );
			var length = Number( firstAttr( attrs, [ 'GIS_Miles', 'gis_miles' ] ) );
			if ( isFinite( length ) && length > 0 ) {
				facts.push( [ text.lineMiles || 'Miles', String( Math.round( length * 100 ) / 100 ) ] );
			}
			facts.push( [ text.lineClass || 'Class', firstAttr( attrs, [ 'TrailClass', 'trailclass' ] ) ] );
			facts.push( [ text.lineStatus || 'Status', firstAttr( attrs, [ 'TrailStatus', 'trailstatus' ] ) ] );
			var season = firstAttr( attrs, [ 'Seasonal', 'seasonal' ] );
			if ( season ) {
				facts.push( [ text.lineSeason || 'Season', season.charAt( 0 ).toUpperCase() + season.slice( 1 ) ] );
			}
			if ( number && number !== title ) {
				facts.push( [ text.lineNumber || 'Number', number ] );
			}
			MVUM_VEHICLES.forEach( function ( vehicle ) {
				var value = firstAttr( attrs, vehicle[ 1 ] ).toLowerCase();
				if ( 'open' !== value && 'seasonal' !== value ) {
					return;
				}
				var dates = firstAttr( attrs, vehicle[ 2 ] );
				facts.push( [ vehicle[ 0 ], dates ? value + ', ' + dates : value ] );
			} );
			selectedKind = 'mvum';
			showHighlight( mvumPaths( result.geometry ) );
			lineCard( trail ? ( text.lineMvumTrail || 'Motor vehicle trail' ) : ( text.lineMvumRoad || 'Motor vehicle road' ), title, facts, text.lineMvum || 'Motor Vehicle Use Map, U.S. Forest Service' );
			var route = firstAttr( attrs, [ 'RTE_CN', 'rte_cn' ] );
			if ( ! route ) {
				return;
			}
			var where = "rte_cn='" + route.replace( /'/g, '' ) + "'";
			fetch( MVUM_SERVICE + '/' + result.layerId + '/query?where=' + encodeURIComponent( where ) + '&returnGeometry=true&outSR=4326&outFields=rte_cn&f=json' )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( data ) {
					if ( token !== lineToken ) {
						return;
					}
					var lines = [];
					( data.features || [] ).forEach( function ( feature ) {
						mvumPaths( feature.geometry ).forEach( function ( path ) {
							lines.push( path );
						} );
					} );
					if ( lines.length ) {
						showHighlight( lines );
					}
				} )
				.catch( function () {} );
		}

		function identifyMvum( lngLat ) {
			// A fixed patch of ground, so the same click finds the same route when the map is zoomed out.
			var reach = 250;
			var dLat = reach / 110540;
			var dLng = reach / ( 111320 * Math.cos( lngLat.lat * Math.PI / 180 ) );
			var params = new URLSearchParams( {
				geometry: lngLat.lng + ',' + lngLat.lat,
				geometryType: 'esriGeometryPoint',
				sr: '4326',
				layers: 'all:1,2',
				tolerance: '24',
				mapExtent: [ lngLat.lng - dLng, lngLat.lat - dLat, lngLat.lng + dLng, lngLat.lat + dLat ].join( ',' ),
				imageDisplay: '256,256,96',
				returnGeometry: 'true',
				f: 'json',
			} );
			return fetch( MVUM_SERVICE + '/identify?' + params.toString() ).then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'HTTP ' + response.status );
				}
				return response.json();
			} ).then( function ( data ) {
				var results = ( data.results || [] ).slice();
				results.sort( function ( left, right ) {
					return ( 2 === right.layerId ? 1 : 0 ) - ( 2 === left.layerId ? 1 : 0 );
				} );
				return results[ 0 ] || null;
			} );
		}

		function ownedClick( point ) {
			var owners = [ 'spot-points', 'spot-clusters', 'feature-fill', 'feature-line-boundary', 'feature-line-route', 'hub-spot-points', 'hub-place-fill', 'hub-feature-line', 'hub-feature-point' ].filter( function ( id ) {
				return map.getLayer( id ) && 'none' !== map.getLayoutProperty( id, 'visibility' );
			} );
			return owners.length > 0 && map.queryRenderedFeatures( point, { layers: owners } ).length > 0;
		}

		function selectLineAt( event ) {
			if ( ownedClick( event.point ) ) {
				return;
			}
			var token = ++lineToken;
			var trailIds = basemap.trails ? lineLayerIds( 'trails' ) : [];
			var roadIds = basemap.roads ? lineLayerIds( 'roads' ) : [];
			var trail = pickLine( trailIds, event.point );
			var road = pickLine( roadIds, event.point );
			function useBasemap() {
				if ( token !== lineToken ) {
					return;
				}
				// The closer line wins, so a road is not taken by a path somewhere else in the tile.
				if ( trail && ( ! road || trail.dist <= road.dist ) ) {
					var trailHit = preferRoute( attachName( trail, event.point, trailIds ), event.point, trailIds );
					trailHit.at = [ event.lngLat.lng, event.lngLat.lat ];
					showBasemapLine( trailHit, 'trail', trailIds );
					return;
				}
				if ( road ) {
					var roadHit = preferRoute( attachName( road, event.point, roadIds ), event.point, roadIds );
					roadHit.at = [ event.lngLat.lng, event.lngLat.lat ];
					showBasemapLine( roadHit, 'road', roadIds );
					return;
				}
				selectedKind = '';
				if ( map.getSource( 'selection' ) ) {
					map.getSource( 'selection' ).setData( { type: 'FeatureCollection', features: [] } );
				}
				if ( 'line' === panel.dataset.posCard ) {
					panel.hidden = true;
				}
			}
			var mvumOn = forest.mvum && map.getLayer( 'overlay-mvum' ) && 'none' !== map.getLayoutProperty( 'overlay-mvum', 'visibility' );
			if ( ! mvumOn ) {
				useBasemap();
				return;
			}
			identifyMvum( event.lngLat ).then( function ( hit ) {
				if ( token !== lineToken ) {
					return;
				}
				if ( hit ) {
					showMvum( hit, token );
					return;
				}
				useBasemap();
			} ).catch( function () {
				useBasemap();
			} );
		}

		function watchLines() {
			map.on( 'click', selectLineAt );
			map.on( 'idle', function () {
				if ( ! routePick || routePick.token !== lineToken || ( 'road' !== routePick.kind && 'trail' !== routePick.kind ) ) {
					return;
				}
				// Tiles that scroll into view add more of this same route. They do not choose a different one.
				nameFromLabels( routePick );
				paintRoute();
			} );
			map.on( 'mousemove', function ( event ) {
				if ( ownedClick( event.point ) ) {
					map.getCanvas().style.cursor = 'pointer';
					return;
				}
				var ids = ( basemap.trails ? lineLayerIds( 'trails' ) : [] ).concat( basemap.roads ? lineLayerIds( 'roads' ) : [] );
				var hit = pickLine( ids, event.point );
				if ( '' === map.getCanvas().style.cursor || 'pointer' === map.getCanvas().style.cursor ) {
					map.getCanvas().style.cursor = hit ? 'pointer' : '';
				}
			} );
		}

		function paintOverlay( key, on ) {
			var spec = FOREST[ key ];
			var id = 'overlay-' + key;
			if ( on && ! map.getSource( id ) ) {
				map.addSource( id, {
					type: 'raster',
					tiles: [ spec.tiles ],
					tileSize: 256,
					attribution: spec.attribution,
					minzoom: spec.minzoom,
					maxzoom: 16,
				} );
				map.addLayer( {
					id: id,
					type: 'raster',
					source: id,
					minzoom: spec.minzoom,
					paint: { 'raster-opacity': spec.opacity },
				}, map.getLayer( 'place-fill' ) ? 'place-fill' : undefined );
			}
			if ( map.getLayer( id ) ) {
				map.setLayoutProperty( id, 'visibility', on ? 'visible' : 'none' );
			}
		}

		/** The card of something packout.spot shows: its name, what it is, and a link to its page there. Nothing here changes it. */
		function openHub( title, kind, lines, url ) {
			dismissLine();
			panel.dataset.posCard = 'hub';
			panel.textContent = '';
			panel.classList.remove( 'pos-map__panel--photos' );
			panel.classList.add( 'pos-map__panel--partner' );
			var close = el( 'button', 'pos-map__close', text.close );
			close.type = 'button';
			close.addEventListener( 'click', function () {
				panel.hidden = true;
			} );
			panel.appendChild( close );
			var badges = el( 'p', 'pos-map__badges' );
			badges.appendChild( ui( 'badge', { text: config.hub.badge, intent: 'none' } ) );
			if ( kind ) {
				badges.appendChild( el( 'span', 'pos-map__category', kind ) );
			}
			panel.appendChild( badges );
			panel.appendChild( el( 'h3', 'pos-map__title', title ) );
			lines.forEach( function ( line ) {
				if ( line ) {
					panel.appendChild( el( 'p', 'pos-map__where', line ) );
				}
			} );
			if ( url ) {
				var link = el( 'a', 'pos-map__link pos-map__link--partner', config.hub.open );
				link.href = url;
				link.rel = 'noopener';
				panel.appendChild( link );
			}
			panel.hidden = false;
			close.focus();
		}

		function hubCollection( kind ) {
			var data = hubData || { spots: [], places: [], features: [] };
			if ( 'spots' === kind ) {
				return { type: 'FeatureCollection', features: data.spots.map( function ( spot ) {
					return { type: 'Feature', geometry: { type: 'Point', coordinates: [ spot.lng, spot.lat ] }, properties: { id: spot.id, status: spot.status, coarse: spot.coarse ? 1 : 0 } };
				} ) };
			}
			if ( 'places' === kind ) {
				return { type: 'FeatureCollection', features: data.places.map( function ( place ) {
					return { type: 'Feature', geometry: { type: 'Polygon', coordinates: [ place.shape.concat( [ place.shape[ 0 ] ] ) ] }, properties: { id: place.id } };
				} ) };
			}
			return { type: 'FeatureCollection', features: data.features.map( function ( feature ) {
				return { type: 'Feature', geometry: feature.geometry, properties: { id: feature.id } };
			} ) };
		}

		function hubFind( list, id ) {
			return ( hubData && hubData[ list ] || [] ).find( function ( item ) {
				return item.id === id;
			} );
		}

		/** Draw what packout.spot shows, in a quieter style than the site's own records, under them. */
		function paintHub() {
			var wanted = hub.hubSpots || hub.hubPlaces || hub.hubFeatures;
			if ( ! config.hub || ! config.hub.rest ) {
				return;
			}
			if ( wanted && ! hubAsked ) {
				hubAsked = true;
				fetch( config.hub.rest, { credentials: 'omit', headers: { Accept: 'application/json' } } ).then( function ( response ) {
					return response.ok ? response.json() : null;
				} ).then( function ( data ) {
					if ( data && Array.isArray( data.spots ) && Array.isArray( data.places ) && Array.isArray( data.features ) ) {
						hubData = data;
						paintHub();
					}
				} ).catch( function () {
					// The site's own records are on the map. The layer stays empty.
					hubAsked = false;
				} );
			}
			if ( hubData && ! map.getSource( 'hub-spots' ) ) {
				var under = map.getLayer( 'place-fill' ) ? 'place-fill' : undefined;
				map.addSource( 'hub-places', { type: 'geojson', data: hubCollection( 'places' ) } );
				map.addSource( 'hub-features', { type: 'geojson', data: hubCollection( 'features' ) } );
				map.addSource( 'hub-spots', { type: 'geojson', data: hubCollection( 'spots' ) } );
				map.addLayer( { id: 'hub-place-fill', type: 'fill', source: 'hub-places', paint: { 'fill-color': '#5b6770', 'fill-opacity': 0.08 } }, under );
				map.addLayer( { id: 'hub-place-line', type: 'line', source: 'hub-places', paint: { 'line-color': '#5b6770', 'line-width': 1.5, 'line-dasharray': [ 2, 2 ] } }, under );
				map.addLayer( { id: 'hub-feature-line', type: 'line', source: 'hub-features', filter: [ '!=', [ 'geometry-type' ], 'Point' ], paint: { 'line-color': '#5b6770', 'line-width': 2, 'line-opacity': 0.8 } }, under );
				map.addLayer( { id: 'hub-feature-point', type: 'circle', source: 'hub-features', filter: [ '==', [ 'geometry-type' ], 'Point' ], paint: { 'circle-color': '#ffffff', 'circle-radius': 5, 'circle-stroke-width': 2, 'circle-stroke-color': '#5b6770' } }, under );
				// A hollow marker: the Spot is packout.spot's, not this site's.
				map.addLayer( { id: 'hub-spot-points', type: 'circle', source: 'hub-spots', paint: { 'circle-color': '#ffffff', 'circle-radius': [ 'case', [ '==', [ 'get', 'coarse' ], 1 ], 12, 7 ], 'circle-opacity': 0.85, 'circle-stroke-width': 2.5, 'circle-stroke-color': '#5b6770' } }, map.getLayer( 'spot-clusters' ) ? 'spot-clusters' : undefined );
				map.on( 'click', 'hub-spot-points', function ( event ) {
					var spot = hubFind( 'spots', event.features[ 0 ].properties.id );
					if ( spot ) {
						openHub( spot.title, spot.category, [
							config.statuses[ spot.status ] || '',
							spot.through ? config.hub.through.replace( '%s', spot.through ) : '',
							spot.coarse ? config.hub.coarse : '',
							spot.through ? '' : config.hub.number.replace( '%s', spot.id ),
						], spot.url );
					}
				} );
				map.on( 'click', 'hub-place-fill', function ( event ) {
					var place = hubFind( 'places', event.features[ 0 ].properties.id );
					if ( place && ! map.queryRenderedFeatures( event.point, { layers: [ 'hub-spot-points', 'spot-points', 'spot-clusters' ].filter( function ( id ) {
						return map.getLayer( id );
					} ) } ).length ) {
						openHub( place.name, place.kind, [ place.adopters.length ? config.hub.adopted.replace( '%s', place.adopters.join( ', ' ) ) : '' ], place.url );
					}
				} );
				[ 'hub-feature-line', 'hub-feature-point' ].forEach( function ( layer ) {
					map.on( 'click', layer, function ( event ) {
						var feature = hubFind( 'features', event.features[ 0 ].properties.id );
						if ( feature ) {
							openHub( feature.name, feature.kind, [], feature.url );
						}
					} );
				} );
				[ 'hub-spot-points', 'hub-feature-line', 'hub-feature-point' ].forEach( function ( layer ) {
					map.on( 'mouseenter', layer, function () {
						map.getCanvas().style.cursor = 'pointer';
					} );
					map.on( 'mouseleave', layer, function () {
						map.getCanvas().style.cursor = '';
					} );
				} );
			}
			[ [ 'hub-spot-points', hub.hubSpots ], [ 'hub-place-fill', hub.hubPlaces ], [ 'hub-place-line', hub.hubPlaces ], [ 'hub-feature-line', hub.hubFeatures ], [ 'hub-feature-point', hub.hubFeatures ] ].forEach( function ( pair ) {
				if ( map.getLayer( pair[ 0 ] ) ) {
					map.setLayoutProperty( pair[ 0 ], 'visibility', pair[ 1 ] ? 'visible' : 'none' );
				}
			} );
		}

		function paintLayers() {
			if ( ! map || ! ready ) {
				return;
			}
			paintHub();
			function show( id, on ) {
				if ( map.getLayer( id ) ) {
					map.setLayoutProperty( id, 'visibility', on ? 'visible' : 'none' );
				}
			}
			var groups = basemapGroups( map.getStyle().layers );
			Object.keys( groups ).forEach( function ( group ) {
				groups[ group ].forEach( function ( id ) {
					show( id, basemap[ group ] );
				} );
			} );
			Object.keys( forest ).forEach( function ( key ) {
				paintOverlay( key, forest[ key ] );
			} );
			show( 'place-fill', layers.places );
			show( 'place-line', layers.places );
			show( 'place-name', layers.places );
			show( 'feature-fill', layers.boundary );
			show( 'feature-line-boundary', layers.boundary );
			show( 'feature-line-route', layers.route );
			show( 'spot-clusters', layers.spots );
			show( 'spot-cluster-count', layers.spots );
			show( 'spot-points', layers.spots );
			refreshFeatureMarkers();
		}

		function addLayers() {
			// Places sit under the Spots, so a Spot inside one is still the thing a click opens.
			map.addSource( 'places', { type: 'geojson', data: outlineFeatures( 'area' ) } );
			map.addSource( 'place-names', { type: 'geojson', data: outlineFeatures( 'label' ) } );
			map.addLayer( { id: 'place-fill', type: 'fill', source: 'places', paint: { 'fill-color': '#415840', 'fill-opacity': 0.12 } } );
			map.addLayer( { id: 'place-line', type: 'line', source: 'places', paint: { 'line-color': '#415840', 'line-width': 2, 'line-dasharray': [ 3, 2 ] } } );
			map.addLayer( {
				id: 'place-name',
				type: 'symbol',
				source: 'place-names',
				minzoom: 8,
				layout: { 'text-field': [ 'get', 'name' ], 'text-size': 13, 'text-font': [ 'Noto Sans Bold' ], 'text-max-width': 10 },
				paint: { 'text-color': '#2f3a2e', 'text-halo-color': '#ffffff', 'text-halo-width': 1.5 },
			} );
			map.addSource( 'features', { type: 'geojson', data: featureCollection() } );
			map.addLayer( { id: 'feature-fill', type: 'fill', source: 'features', filter: [ '==', [ 'get', 'kind' ], 'boundary' ], paint: { 'fill-color': [ 'coalesce', [ 'get', 'color' ], '#415840' ], 'fill-opacity': 0.18 } } );
			map.addLayer( { id: 'feature-line-boundary', type: 'line', source: 'features', filter: [ '==', [ 'get', 'kind' ], 'boundary' ], paint: { 'line-color': [ 'coalesce', [ 'get', 'color' ], '#415840' ], 'line-width': 3 } } );
			map.addLayer( { id: 'feature-line-route', type: 'line', source: 'features', filter: [ '==', [ 'get', 'kind' ], 'route' ], paint: { 'line-color': [ 'coalesce', [ 'get', 'color' ], '#415840' ], 'line-width': 3 } } );
			[ 'feature-fill', 'feature-line-boundary', 'feature-line-route' ].forEach( function ( layer ) {
				map.on( 'click', layer, function ( event ) {
					var id = event.features[ 0 ].properties.id;
					var feature = shownFeatures().find( function ( item ) {
						return String( item.id ) === String( id );
					} );
					if ( feature ) {
						openFeature( feature );
					}
				} );
				map.on( 'mousemove', layer, function ( event ) {
					var id = event.features[ 0 ].properties.id;
					var feature = shownFeatures().find( function ( item ) {
						return String( item.id ) === String( id );
					} );
					map.getCanvas().style.cursor = 'pointer';
					if ( feature ) {
						showTip( feature, event.lngLat );
					}
				} );
				map.on( 'mouseleave', layer, function () {
					map.getCanvas().style.cursor = '';
					hideTipSoon();
				} );
			} );
			map.addSource( 'selection', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } } );
			map.addLayer( { id: 'selection-casing', type: 'line', source: 'selection', paint: { 'line-color': '#ffffff', 'line-width': 9, 'line-opacity': 0.95 } } );
			map.addLayer( { id: 'selection-line', type: 'line', source: 'selection', paint: { 'line-color': '#bb5e28', 'line-width': 5 } } );
			map.addSource( 'spots', { type: 'geojson', data: features( [] ), cluster: true, clusterRadius: 40, clusterMaxZoom: 11 } );
			map.addLayer( {
				id: 'spot-clusters',
				type: 'circle',
				source: 'spots',
				filter: [ 'has', 'point_count' ],
				paint: { 'circle-color': '#2f3232', 'circle-radius': [ 'step', [ 'get', 'point_count' ], 16, 10, 20, 50, 26 ], 'circle-stroke-width': 2, 'circle-stroke-color': '#ffffff' },
			} );
			map.addLayer( {
				id: 'spot-cluster-count',
				type: 'symbol',
				source: 'spots',
				filter: [ 'has', 'point_count' ],
				layout: { 'text-field': [ 'get', 'point_count_abbreviated' ], 'text-size': 13, 'text-font': [ 'Noto Sans Bold' ] },
				paint: { 'text-color': '#ffffff' },
			} );
			map.addLayer( {
				id: 'spot-points',
				type: 'circle',
				source: 'spots',
				filter: [ '!', [ 'has', 'point_count' ] ],
				paint: {
					// A report a partner has not reviewed is drawn in a quiet gray, over the area its rounded point stands for.
					'circle-color': [ 'case', [ '==', [ 'get', 'awaiting' ], 1 ], '#8a8f8f', [ 'match', [ 'get', 'status' ], 'spotted', COLORS.spotted, 'planned', COLORS.planned, 'in_progress', COLORS.in_progress, 'pending', COLORS.pending, 'cleaned', COLORS.cleaned, 'watch', COLORS.watch, COLORS.spotted ] ],
					// A cleaned Spot stays on the map in a quiet style. One that came back is drawn like an open Spot.
					'circle-radius': [ 'case', [ '==', [ 'get', 'coarse' ], 1 ], 14, [ '==', [ 'get', 'status' ], 'cleaned' ], 7, 9 ],
					'circle-opacity': [ 'case', [ '==', [ 'get', 'awaiting' ], 1 ], 0.45, [ '==', [ 'get', 'coarse' ], 1 ], 0.55, [ '==', [ 'get', 'status' ], 'cleaned' ], 0.7, 1 ],
					'circle-stroke-width': [ 'case', [ '==', [ 'get', 'hazard' ], 1 ], 3, 2 ],
					'circle-stroke-color': [ 'case', [ '==', [ 'get', 'hazard' ], 1 ], '#2f3232', '#ffffff' ],
				},
			} );

			map.on( 'click', 'spot-points', function ( event ) {
				var id = event.features[ 0 ].properties.id;
				var spot = spots.find( function ( item ) {
					return item.id === id;
				} );
				if ( spot ) {
					openPanel( spot );
				}
			} );
			map.on( 'click', 'spot-clusters', function ( event ) {
				map.easeTo( { center: event.features[ 0 ].geometry.coordinates, zoom: map.getZoom() + 2 } );
			} );
			[ 'spot-points', 'spot-clusters' ].forEach( function ( layer ) {
				map.on( 'mouseenter', layer, function () {
					map.getCanvas().style.cursor = 'pointer';
				} );
				map.on( 'mouseleave', layer, function () {
					map.getCanvas().style.cursor = '';
				} );
			} );
			watchLines();
		}

		// The search box: a town or a road moves the map, a Spot opens its panel, a Place fits its outline.
		var searchHost = root.querySelector( '.pos-map__search' );
		var found = { marker: null };
		if ( searchHost && window.packoutSearch ) {
			window.packoutSearch.mount( searchHost, {
				near: function () {
					if ( ! map ) {
						return null;
					}
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
					if ( 'spots' === kind ) {
						showLayer( 'spots' );
						var spot = spots.find( function ( item ) {
							return item.id === result.id;
						} );
						if ( spot ) {
							allow( categories, categoryMenu, spot.category && spot.category.slug );
							allow( stages, stageMenu, spot.status );
							// A Spot found by searching is shown, whichever partner the map was narrowed to.
							if ( partner && -1 === ( spot.partners || [] ).indexOf( partner ) ) {
								partner = 0;
								if ( partnerSelect ) {
									partnerSelect.value = '0';
								}
							}
						}
						var visible = shown();
						syncSpotChrome( visible );
						if ( ready && map.getSource( 'spots' ) ) {
							map.getSource( 'spots' ).setData( features( visible ) );
						}
						paintLayers();
						if ( found.marker ) {
							found.marker.remove();
							found.marker = null;
						}
						spot = visible.find( function ( item ) {
							return item.id === result.id;
						} );
						if ( spot ) {
							openPanel( spot );
							if ( map ) {
								map.easeTo( { center: [ result.lng, result.lat ], zoom: Math.max( map.getZoom(), 13 ) } );
							}
						} else {
							// Filtered out, or reviewed since the page loaded. The map still goes there.
							panel.hidden = true;
							setState( text.hidden );
							window.packoutSearch.go( map, result, found, true );
						}
					} else if ( 'features' === kind ) {
						panel.hidden = true;
						var feature = shownFeatures().find( function ( item ) {
							return item.id === result.id;
						} );
						if ( feature ) {
							showLayer( feature.kind );
							paintLayers();
							openFeature( feature );
						}
						window.packoutSearch.go( map, result, found, false );
					} else {
						panel.hidden = true;
						if ( 'places' === kind ) {
							showLayer( 'places' );
							paintLayers();
						}
						window.packoutSearch.go( map, result, found, 'locations' === kind );
					}
					canvas.scrollIntoView( { block: 'nearest' } );
				},
			} );
		}

		if ( window.maplibregl ) {
			map = new window.maplibregl.Map( { container: canvas, style: config.style, center: config.center, zoom: config.zoom, attributionControl: { compact: true }, cooperativeGestures: true } );
			canvas._packoutMap = map;
			map.addControl( new window.maplibregl.NavigationControl( { showCompass: false } ), 'top-right' );
			map.on( 'load', function () {
				var groups = basemapGroups( map.getStyle().layers );
				Object.keys( groups ).forEach( function ( group ) {
					if ( ! groups[ group ].length ) {
						basemap[ group ] = false;
						overlayMenu.unavailable( group, text.notInStyle || 'not in this style' );
					}
				} );
				addLayers();
				ready = true;
				paintLayers();
				draw();
			} );
			map.on( 'moveend', function () {
				window.clearTimeout( fillTimer );
				fillTimer = window.setTimeout( fill, 400 );
			} );
		}

		// A site with more Spots than one answer carries sends the most recently
		// changed. The map then asks for the area on view each time it comes to
		// rest and adds the Spots it had not been given, so an area looked at
		// closely is complete. Nothing is asked twice, and the view does not move.
		var more = false;
		var fillTimer = 0;
		var filled = {};
		function fill() {
			if ( ! more || ! ready || failed || ! answered ) {
				return;
			}
			var view = map.getBounds();
			var box = [
				Math.max( -180, view.getWest() ),
				Math.max( -90, view.getSouth() ),
				Math.min( 180, view.getEast() ),
				Math.min( 90, view.getNorth() ),
			].map( function ( edge ) {
				return edge.toFixed( 3 );
			} ).join( ',' );
			if ( filled[ box ] ) {
				return;
			}
			filled[ box ] = true;
			fetch( address + '&bbox=' + box, { credentials: 'omit', headers: { Accept: 'application/json' } } )
				.then( function ( response ) {
					return response.ok ? response.json() : { spots: [] };
				} )
				.then( function ( data ) {
					var have = {};
					spots.forEach( function ( spot ) {
						have[ spot.id ] = true;
					} );
					var added = ( data.spots || [] ).filter( function ( spot ) {
						return ! have[ spot.id ] && ( ! config.place || ( spot.place && spot.place.id === config.place ) );
					} );
					if ( ! added.length ) {
						return;
					}
					spots = spots.concat( added );
					var items = shown();
					syncSpotChrome( items );
					if ( map.getSource( 'spots' ) ) {
						map.getSource( 'spots' ).setData( features( items ) );
					}
				} )
				.catch( function () {
					// The Spots already on the map stay. The next move asks again.
					delete filled[ box ];
				} );
		}

		// Hosts with a page cache keep this answer for visitors whatever the
		// server says, some for ten minutes. A new address each minute lets the
		// map follow a coordinator's decision while a busy page still costs
		// the server one query a minute.
		var minute = Math.floor( Date.now() / 60000 );
		var address = config.rest + 'spots' + ( config.rest.indexOf( '?' ) === -1 ? '?' : '&' ) + 'at=' + minute;

		fetch( address, { credentials: 'omit', headers: { Accept: 'application/json' } } )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'HTTP ' + response.status );
				}
				return response.json();
			} )
			.then( function ( data ) {
				spots = ( data.spots || [] ).filter( function ( spot ) {
					return ! config.place || ( spot.place && spot.place.id === config.place );
				} );
				more = !! data.more;
				answered = true;
				draw();
				if ( more ) {
					fill();
				}
			} )
			.catch( function () {
				// The map still fits a Place's outline. The page says the Spots did not load.
				answered = true;
				failed = true;
				draw();
			} );
	}

	function init() {
		document.querySelectorAll( '[data-pos-map]' ).forEach( function ( root ) {
			if ( ! root.dataset.posReady ) {
				root.dataset.posReady = '1';
				start( root );
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
