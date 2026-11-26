/**
 * Pack-Out Spot field app.
 *
 * Reports are saved on the device first, photos included, and sent when the
 * phone has service. The client id of a report is the idempotency key, so a
 * retry never creates a second Spot.
 *
 * A crew member's checklist ticks and Spot outcomes on a mission are saved the
 * same way. Each is sent with the mission revision the phone last saw. When
 * someone else answered the same thing differently in the meantime, the
 * server keeps its copy and the lead chooses.
 *
 * The work done at a Spot (hours, bags, pounds, photos taken afterward) and a
 * return visit to a cleaned Spot are saved and sent the same way. Each has a
 * client id, so a retry never writes a second record.
 *
 * On a partner's site that shows packout.spot's layers, the hub's Spots inside
 * a downloaded region are kept with the region and drawn in a quieter style.
 * They can be read and nothing else: no report, tick, or record touches one.
 *
 * Storage on the device:
 * - IndexedDB "pos-field": reports, photos, and a small key-value store that
 *   also holds the missions, the changes waiting to send, and the hub's Spots.
 * - Cache "pos-regions": downloaded map packages (PMTiles).
 */
( function () {
	'use strict';

	var root = document.getElementById( 'pos-field' );
	if ( ! root ) {
		return;
	}

	var config = JSON.parse( root.getAttribute( 'data-config' ) || '{}' );
	var __ = window.wp && window.wp.i18n ? function ( text ) {
		return window.wp.i18n.__( text, 'packout-core' );
	} : function ( text ) {
		return text;
	};

	var COLORS = { spotted: '#bb5e28', planned: '#25486b', in_progress: '#25486b', pending: '#9a7b12', cleaned: '#415840', watch: '#863d14' };
	var INTENTS = { spotted: 'medium', planned: 'informational', in_progress: 'informational', pending: 'low', cleaned: 'stable', watch: 'high' };
	var MISSION_INTENTS = { planned: 'informational', in_progress: 'medium', completed: 'stable', called_off: 'none' };
	var STATES = {
		pending: [ __( 'Waiting to send' ), 'draft' ],
		sent: [ __( 'Sent' ), 'stable' ],
		failed: [ __( 'Not sent' ), 'high' ],
		conflict: [ __( 'Changed by a coordinator' ), 'medium' ],
		reviewed: [ __( 'Reviewed' ), 'informational' ],
		removed: [ __( 'Removed by a coordinator' ), 'none' ],
	};

	var state = {
		tab: 'map',
		user: null,
		session: 'unknown', // unknown, signed-in, signed-out, denied.
		reports: [],
		photos: [],
		server: {}, // Review state of the reporter's Spots, by client id.
		spots: [],
		missions: [],
		changes: {}, // Ticks and outcomes waiting to send, by mission id.
		work: [], // Records of work and return visits made on this device.
		records: [], // The same person's records as the server holds them.
		regions: [],
		downloaded: {},
		hub: [], // packout.spot's Spots inside the downloaded regions. Read only.
		basemap: 'online',
		syncing: false,
	};

	/* ---------- Small helpers ---------- */

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

	function button( label, className, onClick ) {
		var node = el( 'button', 'pf-button ' + ( className || '' ), label );
		node.type = 'button';
		node.addEventListener( 'click', onClick );
		return node;
	}

	/** A component: a badge, a notice, or an empty state. The text is what shows on a site that draws components as plain markup, and until the component mounts on one that does not. */
	function ui( id, props ) {
		var node = el( 'span', 'packout-ui pf-ui pf-ui--' + id + ( props.intent ? ' pf-ui--' + props.intent : '' ) );
		node.setAttribute( 'data-packout-ui', id );
		node.setAttribute( 'data-config', JSON.stringify( { id: id, props: props } ) );
		var fallback = el( 'span', 'packout-ui__fallback' );
		if ( props.title ) {
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

	function uuid() {
		if ( window.crypto && window.crypto.randomUUID ) {
			return window.crypto.randomUUID();
		}
		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace( /[xy]/g, function ( c ) {
			var r = Math.floor( Math.random() * 16 );
			return ( c === 'x' ? r : ( r % 4 ) + 8 ).toString( 16 );
		} );
	}

	function toast( message ) {
		var node = root.querySelector( '.pf-toast' );
		node.textContent = message;
		node.hidden = false;
		window.clearTimeout( toast.timer );
		toast.timer = window.setTimeout( function () {
			node.hidden = true;
		}, 4500 );
	}

	/* ---------- Device storage ---------- */

	var opening = null;

	function db() {
		if ( ! opening ) {
			opening = new Promise( function ( resolve, reject ) {
				var request = window.indexedDB.open( 'pos-field', 1 );
				request.onupgradeneeded = function () {
					var database = request.result;
					database.createObjectStore( 'reports', { keyPath: 'client_id' } );
					database.createObjectStore( 'photos', { keyPath: 'id' } ).createIndex( 'client_id', 'client_id' );
					database.createObjectStore( 'kv', { keyPath: 'key' } );
				};
				request.onsuccess = function () {
					resolve( request.result );
				};
				request.onerror = function () {
					reject( request.error );
				};
			} );
		}
		return opening;
	}

	function store( name, mode, work ) {
		return db().then( function ( database ) {
			return new Promise( function ( resolve, reject ) {
				var tx = database.transaction( name, mode );
				var request = work( tx.objectStore( name ) );
				tx.oncomplete = function () {
					resolve( request ? request.result : undefined );
				};
				tx.onerror = tx.onabort = function () {
					reject( tx.error );
				};
			} );
		} );
	}

	function put( name, value ) {
		return store( name, 'readwrite', function ( s ) {
			return s.put( value );
		} );
	}

	function remove( name, key ) {
		return store( name, 'readwrite', function ( s ) {
			return s.delete( key );
		} );
	}

	function all( name ) {
		return store( name, 'readonly', function ( s ) {
			return s.getAll();
		} );
	}

	function remember( key, value ) {
		return put( 'kv', { key: key, value: value } );
	}

	function recall( key, fallback ) {
		return store( 'kv', 'readonly', function ( s ) {
			return s.get( key );
		} ).then( function ( row ) {
			return row ? row.value : fallback;
		} );
	}

	function load() {
		return Promise.all( [ all( 'reports' ), all( 'photos' ), recall( 'spots', [] ), recall( 'regions', [] ), recall( 'server', {} ), recall( 'user', null ), window.caches ? window.caches.open( 'pos-regions' ).then( function ( cache ) {
			return cache.keys();
		} ) : [], recall( 'missions', [] ), recall( 'changes', {} ), recall( 'work', [] ), recall( 'records', [] ), recall( 'hub', [] ) ] ).then( function ( found ) {
			state.hub = found[ 11 ];
			state.missions = found[ 7 ];
			state.changes = found[ 8 ];
			state.work = found[ 9 ];
			state.records = found[ 10 ];
			state.reports = found[ 0 ].sort( function ( a, b ) {
				return b.created - a.created;
			} );
			state.photos = found[ 1 ];
			state.spots = found[ 2 ];
			state.regions = found[ 3 ];
			state.server = found[ 4 ];
			state.user = found[ 5 ];
			state.downloaded = {};
			found[ 6 ].forEach( function ( request ) {
				state.downloaded[ request.url ] = true;
			} );
		} );
	}

	function photosOf( clientId ) {
		return state.photos.filter( function ( photo ) {
			return photo.client_id === clientId;
		} );
	}

	/** Phone photos are large. A 2048 pixel JPEG is enough for review and far quicker to send on a weak signal. */
	function shrink( file ) {
		if ( ! window.createImageBitmap ) {
			return Promise.resolve( file );
		}
		return window.createImageBitmap( file ).then( function ( bitmap ) {
			var scale = Math.min( 1, 2048 / Math.max( bitmap.width, bitmap.height ) );
			var canvas = document.createElement( 'canvas' );
			canvas.width = Math.round( bitmap.width * scale );
			canvas.height = Math.round( bitmap.height * scale );
			canvas.getContext( '2d' ).drawImage( bitmap, 0, 0, canvas.width, canvas.height );
			return new Promise( function ( resolve ) {
				canvas.toBlob( function ( blob ) {
					resolve( blob || file );
				}, 'image/jpeg', 0.85 );
			} );
		} ).catch( function () {
			return file;
		} );
	}

	/**
	 * Shrinking redraws a photo, which drops the place its camera recorded.
	 * The place is read first and kept beside the smaller copy.
	 */
	function prepare( file ) {
		var taken = window.packoutExif ? window.packoutExif.gps( file ) : Promise.resolve( null );
		return Promise.all( [ shrink( file ), taken ] ).then( function ( parts ) {
			return { blob: parts[ 0 ], taken: parts[ 1 ] };
		} );
	}

	/** Add where a photo was taken to the form that sends it. */
	function withPlace( form, photo ) {
		if ( photo.taken ) {
			form.append( 'photo_lat', photo.taken.lat );
			form.append( 'photo_lng', photo.taken.lng );
		}
		return form;
	}

	/* ---------- Server ---------- */

	var nonce = '';

	function freshNonce() {
		return fetch( config.nonce, { credentials: 'same-origin' } ).then( function ( response ) {
			return response.ok ? response.text() : '';
		} ).then( function ( text ) {
			nonce = /^[a-f0-9]{8,}$/i.test( text.trim() ) ? text.trim() : '';
			return nonce;
		} );
	}

	function api( method, path, body ) {
		var options = { method: method, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-WP-Nonce': nonce } };
		if ( body instanceof window.FormData ) {
			options.body = body;
		} else if ( body ) {
			options.headers[ 'Content-Type' ] = 'application/json';
			options.body = JSON.stringify( body );
		}
		return fetch( config.rest + path, options ).then( function ( response ) {
			return response.json().catch( function () {
				return {};
			} ).then( function ( data ) {
				return { status: response.status, ok: response.ok, data: data };
			} );
		} );
	}

	function fieldsOf( report ) {
		return {
			category: report.category,
			description: report.description,
			landmark: report.landmark,
			lat: report.lat,
			lng: report.lng,
			accuracy: report.accuracy,
			hazard: report.hazard,
			credit_team: report.credit_team === undefined || report.credit_team === null ? creditTeams().length ? creditTeams()[ 0 ].id : 0 : report.credit_team,
			credit_named: !! report.credit_named,
			revision: report.revision || 0,
			photos_pending: photosOf( report.client_id ).filter( function ( photo ) {
				return ! photo.sent;
			} ).length,
		};
	}

	// The organizations this person can report for, as the last sync gave them.
	function creditTeams() {
		return state.user && Array.isArray( state.user.teams ) ? state.user.teams : [];
	}

	function sendReport( report ) {
		return api( 'PUT', 'spots/client/' + report.client_id, fieldsOf( report ) ).then( function ( result ) {
			var copy = result.data && result.data.data ? result.data.data.spot : null;
			if ( result.ok ) {
				report.state = 'sent';
				report.error = '';
				report.server_id = result.data.spot.id;
				report.revision = result.data.spot.revision;
				report.duplicates = result.data.duplicates || [];
			} else if ( result.status === 409 ) {
				report.state = result.data.code === 'packout_reviewed' ? 'reviewed' : 'conflict';
				report.server_copy = copy;
				report.server_id = copy ? copy.id : report.server_id;
				report.error = result.data.message || '';
			} else if ( result.status === 410 ) {
				report.state = 'removed';
				report.error = result.data.message || '';
			} else {
				report.state = 'failed';
				report.error = result.data.message || __( 'The server refused this report.' );
			}
			return put( 'reports', report );
		} );
	}

	function sendPhoto( photo, report ) {
		var form = new window.FormData();
		form.append( 'file', photo.blob, photo.name || 'spot.jpg' );
		form.append( 'client_photo_id', photo.id );
		withPlace( form, photo );
		return api( 'POST', 'spots/' + report.server_id + '/photos', form ).then( function ( result ) {
			if ( result.ok ) {
				// The server has the photo. The copy on the device is no longer needed.
				return remove( 'photos', photo.id );
			}
			photo.error = result.data.message || __( 'This photo could not be sent.' );
			return put( 'photos', photo );
		} );
	}

	/** Send the record of work at a Spot, or a return visit. A dropped connection leaves it for the next try. */
	function sendWork( entry ) {
		var body = { kind: entry.kind, spot: entry.spot, narrative: entry.narrative };
		if ( entry.kind === 'cleanup' ) {
			[ 'hours', 'people', 'bags', 'bags_source', 'pounds', 'pounds_source', 'come_back', 'come_back_note' ].forEach( function ( key ) {
				body[ key ] = entry[ key ];
			} );
		}
		return api( 'PUT', 'impact/client/' + entry.client_id, body ).then( function ( result ) {
			if ( result.ok ) {
				entry.state = 'sent';
				entry.error = '';
				entry.server_id = result.data.impact.id;
			} else if ( result.status >= 400 && result.status < 500 ) {
				entry.state = 'failed';
				entry.error = result.data.message || __( 'The server refused this record.' );
			} else {
				return;
			}
			return remember( 'work', state.work );
		} );
	}

	function sendWorkPhoto( photo, entry ) {
		var form = new window.FormData();
		form.append( 'file', photo.blob, photo.name || 'after.jpg' );
		form.append( 'client_photo_id', photo.id );
		withPlace( form, photo );
		return api( 'POST', 'impact/' + entry.server_id + '/photos', form ).then( function ( result ) {
			if ( result.ok ) {
				return remove( 'photos', photo.id );
			}
			photo.error = result.data.message || __( 'This photo could not be sent.' );
			return put( 'photos', photo );
		} );
	}

	/** An entry the server holds, with every photo sent, no longer needs its copy on the device. */
	function pruneWork() {
		return all( 'photos' ).then( function ( photos ) {
			state.work = state.work.filter( function ( entry ) {
				var held = state.records.some( function ( record ) {
					return record.id === entry.server_id;
				} );
				var unsent = photos.some( function ( photo ) {
					return photo.client_id === entry.client_id;
				} );
				return ! ( entry.state === 'sent' && held && ! unsent );
			} );
			return remember( 'work', state.work );
		} );
	}

	function missionById( id ) {
		return state.missions.find( function ( mission ) {
			return mission.id === id;
		} ) || null;
	}

	function changesOf( id ) {
		if ( ! state.changes[ id ] ) {
			state.changes[ id ] = { lines: {}, outcomes: {}, refused: {}, conflicts: {}, force: false };
		}
		return state.changes[ id ];
	}

	/** How many ticks and outcomes of a mission are waiting to send. */
	function pendingOf( id ) {
		var changes = state.changes[ id ];
		return changes ? Object.keys( changes.lines ).length + Object.keys( changes.outcomes ).length : 0;
	}

	function saveChanges() {
		Object.keys( state.changes ).forEach( function ( id ) {
			var changes = state.changes[ id ];
			if ( ! pendingOf( id ) && ! Object.keys( changes.refused ).length && ! Object.keys( changes.conflicts ).length ) {
				delete state.changes[ id ];
			}
		} );
		return remember( 'changes', state.changes );
	}

	function keepMission( copy ) {
		state.missions = state.missions.map( function ( mission ) {
			return mission.id === copy.id ? copy : mission;
		} );
		return remember( 'missions', state.missions );
	}

	/** Send one mission's ticks and outcomes. A dropped connection leaves them for the next try. */
	function sendMission( mission ) {
		var changes = state.changes[ mission.id ];
		return api( 'PUT', 'missions/' + mission.id + '/progress', { revision: mission.revision, lines: changes.lines, outcomes: changes.outcomes, force: !! changes.force } ).then( function ( result ) {
			var copy = result.ok ? result.data.mission : ( result.data && result.data.data ? result.data.data.mission : null );
			if ( result.ok ) {
				var next = { lines: {}, outcomes: {}, refused: {}, conflicts: {}, force: false };
				( result.data.refused || [] ).forEach( function ( item ) {
					next.refused[ item.type + ':' + item.id ] = item.message;
				} );
				// The lead chooses on their own phone. A crew member's answer waits with the lead.
				( result.data.conflicts || [] ).forEach( function ( item ) {
					if ( copy.can_manage ) {
						next.conflicts[ item.type + ':' + item.id ] = item.mine;
					}
				} );
				state.changes[ mission.id ] = next;
			} else if ( result.status >= 400 && result.status < 500 ) {
				// The mission ended, or it is no longer this person's. Nothing more can be recorded.
				delete state.changes[ mission.id ];
				toast( result.data.message || __( 'This mission no longer takes changes.' ) );
			} else {
				return;
			}
			return Promise.all( [ saveChanges(), copy ? keepMission( copy ) : null ] );
		} );
	}

	function refreshMissions() {
		return api( 'GET', 'missions' ).then( function ( result ) {
			if ( ! result.ok ) {
				return;
			}
			state.missions = result.data.missions || [];
			// Changes for a mission that left the list have nowhere to go.
			Object.keys( state.changes ).forEach( function ( id ) {
				if ( ! missionById( Number( id ) ) ) {
					delete state.changes[ id ];
				}
			} );
			return Promise.all( [ remember( 'missions', state.missions ), saveChanges() ] );
		} );
	}

	function refreshSpots() {
		return api( 'GET', 'spots?own=1' ).then( function ( result ) {
			if ( result.ok ) {
				state.spots = result.data.spots || [];
				return remember( 'spots', state.spots );
			}
		} );
	}

	/**
	 * Send what is waiting, then refresh what the server knows.
	 *
	 * A network failure leaves everything on the device for the next try.
	 */
	function sync( announce ) {
		if ( state.syncing ) {
			return Promise.resolve();
		}
		if ( ! window.navigator.onLine ) {
			if ( announce ) {
				toast( __( 'No connection. Your reports are saved on this device.' ) );
			}
			return Promise.resolve();
		}

		state.syncing = true;
		render();

		return freshNonce().then( function ( value ) {
			if ( ! value ) {
				state.session = 'signed-out';
				return refreshSpots();
			}
			return api( 'GET', 'sync' ).then( function ( result ) {
				if ( ! result.ok ) {
					state.session = result.status === 403 ? 'denied' : 'signed-out';
					return refreshSpots();
				}

				state.session = 'signed-in';
				state.user = result.data.user;
				remember( 'user', state.user );

				var chain = Promise.resolve();
				state.reports.filter( function ( report ) {
					return report.state === 'pending' || report.state === 'failed';
				} ).forEach( function ( report ) {
					chain = chain.then( function () {
						return sendReport( report );
					} );
				} );

				return chain.then( function () {
					var photos = Promise.resolve();
					state.photos.filter( function ( photo ) {
						return ! photo.sent;
					} ).forEach( function ( photo ) {
						var report = state.reports.find( function ( item ) {
							return item.client_id === photo.client_id;
						} );
						if ( report && report.server_id && report.state !== 'removed' ) {
							photos = photos.then( function () {
								return sendPhoto( photo, report );
							} );
						}
					} );
					return photos;
				} ).then( function () {
					var rest = Promise.resolve();
					state.missions.filter( function ( mission ) {
						return pendingOf( mission.id ) > 0;
					} ).forEach( function ( mission ) {
						rest = rest.then( function () {
							return sendMission( mission );
						} );
					} );
					// Records of work go after mission outcomes, so the Spot is already cleaned on the server.
					state.work.filter( function ( entry ) {
						return entry.state === 'pending';
					} ).forEach( function ( entry ) {
						rest = rest.then( function () {
							return sendWork( entry );
						} );
					} );
					return rest.then( function () {
						var photos = Promise.resolve();
						state.photos.filter( function ( photo ) {
							return ! photo.sent;
						} ).forEach( function ( photo ) {
							var entry = state.work.find( function ( item ) {
								return item.client_id === photo.client_id;
							} );
							if ( entry && entry.server_id && entry.state === 'sent' ) {
								photos = photos.then( function () {
									return sendWorkPhoto( photo, entry );
								} );
							}
						} );
						return photos;
					} );
				} ).then( function () {
					return api( 'GET', 'sync' );
				} ).then( function ( after ) {
					if ( after.ok ) {
						state.server = {};
						after.data.spots.forEach( function ( spot ) {
							if ( spot.client_id ) {
								state.server[ spot.client_id ] = spot;
							}
						} );
						remember( 'server', state.server );
						state.records = after.data.work || [];
						remember( 'records', state.records );
						// A Spot a coordinator moved to the trash is shown as removed, not as sent.
						state.reports.forEach( function ( report ) {
							var known = state.server[ report.client_id ];
							if ( known && known.post_status === 'trash' && report.state !== 'removed' ) {
								report.state = 'removed';
								put( 'reports', report );
							}
						} );
					}
					return Promise.all( [ refreshSpots(), refreshMissions(), pruneWork(), api( 'GET', 'regions' ).then( function ( regions ) {
						if ( regions.ok ) {
							state.regions = regions.data.regions || [];
							return remember( 'regions', state.regions );
						}
					} ).then( refreshHub ) ] );
				} );
			} );
		} ).catch( function () {
			if ( announce ) {
				toast( __( 'The connection dropped. Your reports are still saved on this device.' ) );
			}
		} ).then( function () {
			state.syncing = false;
			return load();
		} ).then( function () {
			render();
			drawSpots();
			redrawMission();
		} );
	}

	/** After a tick, wait a moment for the next one, then send them together. */
	function syncSoon() {
		window.clearTimeout( syncSoon.timer );
		syncSoon.timer = window.setTimeout( function () {
			sync( false );
		}, 4000 );
	}

	/* ---------- Map ---------- */

	var map = null;
	var protocol = null;
	var BLANK = { version: 8, sources: {}, layers: [ { id: 'background', type: 'background', paint: { 'background-color': '#e7e3da' } } ] };

	/** Absolute address of a region's package, as the cache stores it. */
	function address( region ) {
		return new window.URL( region.package, window.location.href ).href;
	}

	function regionById( id ) {
		return state.regions.find( function ( region ) {
			return region.id === id;
		} );
	}

	/** A label-free style for a downloaded vector package. Labels need fonts from the network. */
	function vectorLayers( metadata ) {
		var names = ( metadata.vector_layers || [] ).map( function ( layer ) {
			return layer.id;
		} );
		var layers = [ { id: 'background', type: 'background', paint: { 'background-color': '#ece8df' } } ];
		function add( type, source, paint, filter ) {
			if ( names.indexOf( source ) === -1 ) {
				return;
			}
			var layer = { id: 'region-' + layers.length, type: type, source: 'region', 'source-layer': source, paint: paint };
			if ( filter ) {
				layer.filter = filter;
			}
			layers.push( layer );
		}
		var road = { 'line-color': '#8d8576', 'line-width': [ 'interpolate', [ 'linear' ], [ 'zoom' ], 8, 0.5, 14, 2.5 ] };
		var border = { 'line-color': '#8a8f8f', 'line-width': 1, 'line-dasharray': [ 3, 2 ] };
		add( 'fill', 'earth', { 'fill-color': '#f1eee6' } );
		add( 'fill', 'landcover', { 'fill-color': '#dfe6d2' } );
		add( 'fill', 'landuse', { 'fill-color': '#e2e8d5', 'fill-opacity': 0.6 } );
		add( 'fill', 'park', { 'fill-color': '#dbe5cc' } );
		add( 'fill', 'water', { 'fill-color': '#a9c7d8' } );
		add( 'line', 'waterway', { 'line-color': '#a9c7d8', 'line-width': 1.2 } );
		add( 'line', 'roads', road );
		add( 'line', 'transportation', road );
		add( 'line', 'boundaries', border );
		add( 'line', 'boundary', border );
		return layers;
	}

	function regionStyle( region ) {
		if ( ! window.caches || ! window.pmtiles ) {
			return Promise.resolve( null );
		}
		return window.caches.open( 'pos-regions' ).then( function ( cache ) {
			return cache.match( address( region ) );
		} ).then( function ( response ) {
			return response ? response.blob() : null;
		} ).then( function ( blob ) {
			if ( ! blob ) {
				return null;
			}
			if ( ! protocol ) {
				protocol = new window.pmtiles.Protocol();
				window.maplibregl.addProtocol( 'pmtiles', protocol.tile );
			}
			var file = new window.File( [ blob ], 'region-' + region.id + '.pmtiles' );
			var archive = new window.pmtiles.PMTiles( new window.pmtiles.FileSource( file ) );
			protocol.add( archive );
			return Promise.all( [ archive.getHeader(), archive.getMetadata().catch( function () {
				return {};
			} ) ] ).then( function ( parts ) {
				var address = 'pmtiles://' + file.name;
				if ( parts[ 0 ].tileType === 1 ) {
					return { version: 8, sources: { region: { type: 'vector', url: address } }, layers: vectorLayers( parts[ 1 ] || {} ) };
				}
				return { version: 8, sources: { region: { type: 'raster', url: address, tileSize: 256 } }, layers: [ BLANK.layers[ 0 ], { id: 'region', type: 'raster', source: 'region' } ] };
			} );
		} ).catch( function () {
			return null;
		} );
	}

	/** Show the online map, a downloaded region, or a blank canvas. */
	function setBasemap( choice ) {
		var region = choice !== 'online' ? regionById( choice ) : null;
		var pick = region ? regionStyle( region ) : Promise.resolve( choice === 'online' && window.navigator.onLine ? config.style : null );
		return pick.then( function ( style ) {
			state.basemap = style ? choice : 'none';
			map.setStyle( style || BLANK );
			if ( region && style ) {
				map.fitBounds( [ [ region.west, region.south ], [ region.east, region.north ] ], { padding: 20, duration: 0 } );
			}
			render();
		} );
	}

	/** With no signal, fall back to the first downloaded region, or a blank canvas. */
	function offlineBasemap() {
		var available = state.regions.find( function ( region ) {
			return state.downloaded[ address( region ) ];
		} );
		return setBasemap( available ? available.id : 'none' );
	}

	function collection( items ) {
		return { type: 'FeatureCollection', features: items };
	}

	/** Whether a point is inside a region that is on this device. */
	function inDownloaded( lng, lat ) {
		return state.regions.some( function ( region ) {
			return state.downloaded[ address( region ) ] && lng >= region.west && lng <= region.east && lat >= region.south && lat <= region.north;
		} );
	}

	/**
	 * Keep packout.spot's Spots inside the downloaded regions.
	 *
	 * The site answers from what it last read of the hub, so the phone never
	 * calls the hub. With no answer, what the phone has stays.
	 */
	function refreshHub() {
		if ( ! config.hub || ! config.hub.rest ) {
			return Promise.resolve();
		}
		var kept = function ( spots ) {
			state.hub = spots.filter( function ( spot ) {
				return spot && 'number' === typeof spot.lat && 'number' === typeof spot.lng && inDownloaded( spot.lng, spot.lat );
			} );
			return remember( 'hub', state.hub ).then( drawSpots );
		};
		var any = state.regions.some( function ( region ) {
			return state.downloaded[ address( region ) ];
		} );
		if ( ! any ) {
			return state.hub.length ? kept( [] ) : Promise.resolve();
		}
		if ( ! window.navigator.onLine ) {
			return kept( state.hub );
		}
		return fetch( config.hub.rest, { credentials: 'omit', headers: { Accept: 'application/json' } } ).then( function ( response ) {
			return response.ok ? response.json() : null;
		} ).then( function ( data ) {
			return data && Array.isArray( data.spots ) ? kept( data.spots ) : kept( state.hub );
		} ).catch( function () {
			return kept( state.hub );
		} );
	}

	function drawSpots() {
		if ( ! map || ! map.getSource( 'spots' ) ) {
			return;
		}
		var mine = {};
		state.reports.forEach( function ( report ) {
			if ( report.server_id ) {
				mine[ report.server_id ] = true;
			}
		} );
		map.getSource( 'spots' ).setData( collection( state.spots.filter( function ( spot ) {
			return spot.location.lat !== null && ! mine[ spot.id ];
		} ).map( function ( spot ) {
			return { type: 'Feature', geometry: { type: 'Point', coordinates: [ spot.location.lng, spot.location.lat ] }, properties: { kind: 'spot', id: spot.id, status: spot.status } };
		} ) ) );
		if ( map.getSource( 'hub' ) ) {
			map.getSource( 'hub' ).setData( collection( state.hub.map( function ( spot ) {
				return { type: 'Feature', geometry: { type: 'Point', coordinates: [ spot.lng, spot.lat ] }, properties: { kind: 'hub', id: spot.id, coarse: spot.coarse ? 1 : 0 } };
			} ) ) );
		}
		map.getSource( 'reports' ).setData( collection( state.reports.filter( function ( report ) {
			return report.lat !== null && report.lat !== undefined && report.state !== 'removed';
		} ).map( function ( report ) {
			return { type: 'Feature', geometry: { type: 'Point', coordinates: [ report.lng, report.lat ] }, properties: { kind: 'report', id: report.client_id, sent: report.state === 'sent' ? 1 : 0 } };
		} ) ) );
	}

	function addSpotLayers() {
		if ( map.getSource( 'spots' ) ) {
			return;
		}
		map.addSource( 'spots', { type: 'geojson', data: collection( [] ) } );
		map.addSource( 'reports', { type: 'geojson', data: collection( [] ) } );
		if ( config.hub ) {
			// Under the site's own Spots, and in the quieter style the site's map gives them.
			map.addSource( 'hub', { type: 'geojson', data: collection( [] ) } );
			map.addLayer( {
				id: 'hub',
				type: 'circle',
				source: 'hub',
				paint: {
					'circle-color': '#ffffff',
					'circle-radius': [ 'case', [ '==', [ 'get', 'coarse' ], 1 ], 12, 7 ],
					'circle-opacity': 0.85,
					'circle-stroke-width': 2.5,
					'circle-stroke-color': '#5b6770',
				},
			} );
		}
		map.addLayer( {
			id: 'spots',
			type: 'circle',
			source: 'spots',
			paint: {
				'circle-radius': 8,
				'circle-color': [ 'match', [ 'get', 'status' ], 'spotted', COLORS.spotted, 'planned', COLORS.planned, 'in_progress', COLORS.in_progress, 'pending', COLORS.pending, 'cleaned', COLORS.cleaned, 'watch', COLORS.watch, COLORS.spotted ],
				'circle-stroke-width': 2,
				'circle-stroke-color': '#ffffff',
			},
		} );
		map.addLayer( {
			id: 'reports',
			type: 'circle',
			source: 'reports',
			paint: {
				'circle-radius': 9,
				'circle-color': [ 'case', [ '==', [ 'get', 'sent' ], 1 ], COLORS.spotted, '#ffffff' ],
				'circle-stroke-width': 3,
				'circle-stroke-color': [ 'case', [ '==', [ 'get', 'sent' ], 1 ], '#ffffff', COLORS.spotted ],
			},
		} );
		drawSpots();
	}

	function startMap( container ) {
		map = new window.maplibregl.Map( { container: container, style: BLANK, center: config.center, zoom: config.zoom, attributionControl: { compact: true } } );
		map.addControl( new window.maplibregl.NavigationControl( { showCompass: false } ), 'top-right' );
		map.addControl( new window.maplibregl.GeolocateControl( { positionOptions: { enableHighAccuracy: true }, trackUserLocation: true } ), 'top-right' );
		map.on( 'style.load', addSpotLayers );
		map.on( 'click', 'spots', function ( event ) {
			var spot = state.spots.find( function ( item ) {
				return item.id === event.features[ 0 ].properties.id;
			} );
			if ( spot ) {
				openSpot( spot );
			}
		} );
		map.on( 'click', 'hub', function ( event ) {
			// One of the site's own Spots at the same point opens instead.
			if ( map.queryRenderedFeatures( event.point, { layers: [ 'spots', 'reports' ] } ).length ) {
				return;
			}
			var spot = state.hub.find( function ( item ) {
				return item.id === event.features[ 0 ].properties.id;
			} );
			if ( spot ) {
				openHubSpot( spot );
			}
		} );
		map.on( 'click', 'reports', function ( event ) {
			var report = state.reports.find( function ( item ) {
				return item.client_id === event.features[ 0 ].properties.id;
			} );
			if ( report ) {
				state.tab = 'reports';
				render();
			}
		} );
		// A base map that fails to load while the phone thinks it is online is treated as no signal.
		map.on( 'error', function ( event ) {
			if ( state.basemap === 'online' && ! map.isStyleLoaded() && event && event.error && /style|fetch|network/i.test( String( event.error.message ) ) ) {
				offlineBasemap();
			}
		} );
		mountSearch();
		return window.navigator.onLine ? setBasemap( 'online' ) : offlineBasemap();
	}

	/**
	 * The search box over the map.
	 *
	 * A location or a Place moves the map. A Spot moves the map and opens.
	 * Reporting then uses the center of the map, so a search is a way to
	 * place a report somewhere the person is not standing.
	 */
	function mountSearch() {
		var host = root.querySelector( '.pf-search' );
		if ( ! host || ! window.packoutSearch || ! config.search ) {
			return;
		}
		var found = { marker: null };
		window.packoutSearch.mount( host, {
			settings: config.search,
			pages: false,
			collapse: true,
			credentials: 'same-origin',
			headers: function () {
				return nonce ? { 'X-WP-Nonce': nonce } : {};
			},
			online: function () {
				return window.navigator.onLine;
			},
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
				window.packoutSearch.go( map, result, found, 'locations' === kind );
				if ( 'spots' === kind ) {
					var spot = state.spots.find( function ( item ) {
						return item.id === result.id;
					} );
					if ( spot ) {
						openSpot( spot );
					}
				}
			},
		} );
	}

	/* ---------- Regions ---------- */

	function download( region, control ) {
		control.disabled = true;
		control.textContent = __( 'Downloading…' );
		return fetch( address( region ), { credentials: 'omit' } ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'HTTP ' + response.status );
			}
			return window.caches.open( 'pos-regions' ).then( function ( cache ) {
				return cache.put( address( region ), response );
			} );
		} ).then( function () {
			if ( window.navigator.storage && window.navigator.storage.persist ) {
				window.navigator.storage.persist();
			}
			// Keep the Spots inside the box with the region.
			return api( 'GET', 'spots?own=1&bbox=' + [ region.west, region.south, region.east, region.north ].join( ',' ) );
		} ).then( function ( result ) {
			if ( result.ok ) {
				var known = {};
				state.spots.forEach( function ( spot ) {
					known[ spot.id ] = true;
				} );
				( result.data.spots || [] ).forEach( function ( spot ) {
					if ( ! known[ spot.id ] ) {
						state.spots.push( spot );
					}
				} );
				remember( 'spots', state.spots );
			}
			toast( __( 'Region saved on this device.' ) );
		} ).catch( function () {
			toast( __( 'The region could not be downloaded. Check your connection and try again.' ) );
		} ).then( load ).then( refreshHub ).then( render );
	}

	function forget( region ) {
		return window.caches.open( 'pos-regions' ).then( function ( cache ) {
			return cache.delete( address( region ) );
		} ).then( function () {
			return state.basemap === region.id ? setBasemap( window.navigator.onLine ? 'online' : 'none' ) : null;
		} ).then( load ).then( refreshHub ).then( render );
	}

	/* ---------- Report sheet ---------- */

	function closeSheet() {
		var sheet = root.querySelector( '.pf-sheet' );
		sheet.hidden = true;
		sheet.textContent = '';
		openMissionId = 0;
	}

	function sheet( title ) {
		var node = root.querySelector( '.pf-sheet' );
		openMissionId = 0;
		node.textContent = '';
		node.hidden = false;
		var head = el( 'div', 'pf-sheet__head' );
		head.appendChild( el( 'h2', '', title ) );
		head.appendChild( button( __( 'Close' ), 'pf-button--plain', closeSheet ) );
		node.appendChild( head );
		var body = el( 'div', 'pf-sheet__body' );
		node.appendChild( body );
		return body;
	}

	function field( label, control, help ) {
		var wrap = el( 'label', 'pf-field' );
		wrap.appendChild( el( 'span', 'pf-field__label', label ) );
		wrap.appendChild( control );
		if ( help ) {
			wrap.appendChild( el( 'span', 'pf-field__help', help ) );
		}
		return wrap;
	}

	function openReport( existing ) {
		var report = existing ? Object.assign( {}, existing ) : {
			client_id: uuid(),
			category: config.defaultCategory,
			description: '',
			landmark: '',
			lat: null,
			lng: null,
			accuracy: null,
			hazard: 'none',
			state: 'pending',
			created: Date.now(),
		};
		var photos = existing ? photosOf( report.client_id ).slice() : [];
		var removed = [];
		var body = sheet( existing ? __( 'Edit report' ) : __( 'Report a Spot' ) );

		var category = el( 'select' );
		Object.keys( config.categories ).forEach( function ( slug ) {
			category.appendChild( new window.Option( config.categories[ slug ], slug ) );
		} );
		category.value = report.category;
		body.appendChild( field( __( 'Category' ), category ) );

		var description = el( 'textarea' );
		description.rows = 4;
		description.value = report.description;
		body.appendChild( field( __( 'What is there?' ), description, __( 'What you found and why it needs attention.' ) ) );

		var where = el( 'div', 'pf-where' );
		var pinText = el( 'p', 'pf-where__pin' );
		function showPin() {
			pinText.textContent = report.lat === null || report.lat === undefined
				? __( 'No pin. A landmark or a photo is enough.' )
				: __( 'Pin' ) + ': ' + Number( report.lat ).toFixed( 5 ) + ', ' + Number( report.lng ).toFixed( 5 ) + ( report.accuracy ? ' (±' + Math.round( report.accuracy ) + ' m)' : '' );
		}
		showPin();
		where.appendChild( el( 'span', 'pf-field__label', __( 'Location' ) ) );
		where.appendChild( pinText );
		var locate = button( __( 'Use my GPS position' ), '', function () {
			if ( ! window.navigator.geolocation ) {
				toast( __( 'This device does not share its position.' ) );
				return;
			}
			locate.disabled = true;
			pinText.textContent = __( 'Waiting for a GPS fix…' );
			window.navigator.geolocation.getCurrentPosition( function ( position ) {
				report.lat = position.coords.latitude;
				report.lng = position.coords.longitude;
				report.accuracy = position.coords.accuracy;
				locate.disabled = false;
				showPin();
			}, function () {
				locate.disabled = false;
				showPin();
				toast( __( 'No GPS fix. Save a landmark or a photo instead.' ) );
			}, { enableHighAccuracy: true, timeout: 20000, maximumAge: 10000 } );
		} );
		var row = el( 'div', 'pf-row' );
		row.appendChild( locate );
		row.appendChild( button( __( 'Use the center of the map' ), '', function () {
			var center = map.getCenter();
			report.lat = center.lat;
			report.lng = center.lng;
			report.accuracy = null;
			showPin();
		} ) );
		row.appendChild( button( __( 'Remove the pin' ), 'pf-button--plain', function () {
			report.lat = null;
			report.lng = null;
			report.accuracy = null;
			showPin();
		} ) );
		where.appendChild( row );
		body.appendChild( where );

		var landmark = el( 'input' );
		landmark.type = 'text';
		landmark.value = report.landmark;
		body.appendChild( field( __( 'Landmark' ), landmark, __( 'For example: pullout past the second cattle guard on FS 214.' ) ) );

		var picker = el( 'input' );
		picker.type = 'file';
		picker.accept = 'image/*';
		picker.multiple = true;
		var thumbs = el( 'div', 'pf-thumbs' );
		function showPhotos() {
			thumbs.textContent = '';
			photos.forEach( function ( photo, index ) {
				var item = el( 'div', 'pf-thumb' );
				var image = el( 'img' );
				image.alt = __( 'Photo for this report' );
				image.src = window.URL.createObjectURL( photo.blob );
				item.appendChild( image );
				item.appendChild( button( __( 'Remove' ), 'pf-button--plain', function () {
					removed.push( photo.id );
					photos.splice( index, 1 );
					showPhotos();
				} ) );
				thumbs.appendChild( item );
			} );
		}
		picker.addEventListener( 'change', function () {
			var files = Array.prototype.slice.call( picker.files || [] ).slice( 0, config.photoLimit - photos.length );
			Promise.all( files.map( prepare ) ).then( function ( ready ) {
				ready.forEach( function ( item ) {
					photos.push( { id: uuid(), client_id: report.client_id, blob: item.blob, taken: item.taken, name: 'spot.jpg', sent: false } );
					// A report with no pin takes it from the first photo that says where it was taken.
					if ( item.taken && ( report.lat === null || report.lat === undefined ) ) {
						report.lat = item.taken.lat;
						report.lng = item.taken.lng;
						report.accuracy = null;
						showPin();
						toast( __( 'Pin set from where the photo was taken. Move it if that is wrong.' ) );
					}
				} );
				picker.value = '';
				showPhotos();
			} );
		} );
		showPhotos();
		body.appendChild( field( __( 'Photos' ), picker, __( 'Shown on the Spot\'s page once a coordinator publishes the report.' ) ) );
		body.appendChild( thumbs );

		var hazard = el( 'select' );
		Object.keys( config.hazards ).forEach( function ( value ) {
			hazard.appendChild( new window.Option( config.hazards[ value ], value ) );
		} );
		hazard.value = report.hazard;
		var guidance = el( 'div', 'pf-guidance' );
		function showGuidance() {
			guidance.textContent = '';
			if ( config.guidance[ hazard.value ] ) {
				guidance.appendChild( ui( 'notice', { intent: 'warning', title: config.hazards[ hazard.value ], description: config.guidance[ hazard.value ] } ) );
			}
		}
		hazard.addEventListener( 'change', showGuidance );
		showGuidance();
		body.appendChild( field( __( 'Is it safe to handle?' ), hazard ) );
		body.appendChild( guidance );

		// Who the Spot's page says reported it.
		var teams = creditTeams();
		var creditTeam = null;
		if ( teams.length ) {
			creditTeam = el( 'select' );
			teams.forEach( function ( team ) {
				creditTeam.appendChild( new window.Option( team.name, String( team.id ) ) );
			} );
			creditTeam.appendChild( new window.Option( __( 'Myself, not an organization' ), '0' ) );
			var chosen = report.credit_team === undefined || report.credit_team === null ? teams[ 0 ].id : report.credit_team;
			creditTeam.value = String( teams.some( function ( team ) {
				return team.id === chosen;
			} ) ? chosen : 0 );
			body.appendChild( field( __( 'Report it for' ), creditTeam, __( 'The Spot\'s page says who reported it: the organization you choose.' ) ) );
		}
		var creditNamed = el( 'input' );
		creditNamed.type = 'checkbox';
		creditNamed.checked = !! report.credit_named;
		var creditRow = el( 'label', 'pf-check' );
		creditRow.appendChild( creditNamed );
		creditRow.appendChild( el( 'span', '', __( 'Show my name' ) ) );
		body.appendChild( creditRow );
		body.appendChild( el( 'p', 'pf-field__help pf-credit__help', __( 'Reporting as yourself, the page says “an individual” unless you tick this.' ) ) );

		var problem = el( 'div', 'pf-problem' );
		problem.setAttribute( 'role', 'alert' );
		body.appendChild( problem );

		body.appendChild( button( __( 'Save on this device' ), 'pf-button--primary pf-button--wide', function () {
			report.category = category.value;
			report.description = description.value.trim();
			report.landmark = landmark.value.trim();
			report.hazard = hazard.value;
			report.credit_team = creditTeam ? parseInt( creditTeam.value, 10 ) || 0 : 0;
			report.credit_named = creditNamed.checked;

			var message = '';
			if ( ! report.description ) {
				message = __( 'Describe what is there and why it needs attention.' );
			} else if ( ( report.lat === null || report.lat === undefined ) && ! report.landmark && ! photos.length && ! report.server_id ) {
				message = __( 'Add a photo, a pin, or a landmark so someone can find this place.' );
			}
			if ( message ) {
				problem.textContent = '';
				problem.appendChild( ui( 'notice', { intent: 'error', description: message } ) );
				return;
			}

			report.state = 'pending';
			report.error = '';
			report.updated = Date.now();
			Promise.all( [ put( 'reports', report ) ].concat( photos.filter( function ( photo ) {
				return ! photo.sent;
			} ).map( function ( photo ) {
				return put( 'photos', photo );
			} ), removed.map( function ( id ) {
				return remove( 'photos', id );
			} ) ) ).then( load ).then( function () {
				closeSheet();
				state.tab = 'reports';
				render();
				drawSpots();
				toast( window.navigator.onLine ? __( 'Saved. Sending now.' ) : __( 'Saved on this device. It will send when you have service.' ) );
				return sync( false );
			} );
		} ) );
	}

	/** What a clearance lets the crew do, with what authorizes it. */
	function clearanceText( spot ) {
		var parts = [];
		if ( spot.clearance && spot.clearance.scope ) {
			parts.push( spot.clearance.scope );
		} else {
			parts.push( spot.guidance );
		}
		if ( spot.clearance && spot.clearance.reference ) {
			parts.push( __( 'Reference' ) + ': ' + spot.clearance.reference );
		}
		return parts.join( ' ' );
	}

	function openSpot( spot ) {
		var body = sheet( spot.title );
		var badges = el( 'p', 'pf-badges' );
		badges.appendChild( ui( 'badge', { text: config.statuses[ spot.status ] || spot.status, intent: INTENTS[ spot.status ] || 'none' } ) );
		badges.appendChild( el( 'span', '', spot.category.name ) );
		body.appendChild( badges );
		if ( spot.needs_authorization ) {
			body.appendChild( ui( 'notice', { intent: 'warning', title: config.hazards[ spot.hazard ], description: spot.guidance } ) );
		} else if ( spot.authorized ) {
			body.appendChild( ui( 'notice', { intent: 'info', title: __( 'Work is authorized' ), description: clearanceText( spot ) } ) );
		}
		body.appendChild( el( 'p', '', spot.description ) );
		if ( spot.place ) {
			body.appendChild( el( 'p', 'pf-muted', __( 'Place' ) + ': ' + spot.place.name ) );
		}
		if ( spot.location.landmark ) {
			body.appendChild( el( 'p', 'pf-muted', __( 'Landmark' ) + ': ' + spot.location.landmark ) );
		}
		if ( spot.location.precision === 'coarse' ) {
			body.appendChild( el( 'p', 'pf-muted', __( 'Approximate location.' ) ) );
		}
		// Anyone who reports can say how a cleaned place looks now.
		if ( spot.status === 'cleaned' && state.session === 'signed-in' ) {
			var visit = el( 'section', 'pf-block' );
			visit.appendChild( el( 'h3', '', __( 'Been back since?' ) ) );
			var choices = el( 'div', 'pf-row' );
			choices.appendChild( button( __( 'Still clear' ), '', function () {
				openVisit( spot, 'still_clear' );
			} ) );
			choices.appendChild( button( __( 'It\'s back' ), '', function () {
				openVisit( spot, 'returned' );
			} ) );
			visit.appendChild( choices );
			body.appendChild( visit );
		} else if ( spot.status === 'watch' ) {
			body.appendChild( ui( 'notice', { intent: 'warning', description: __( 'A visitor reported that the problem came back after a cleanup. This Spot is open for a new mission.' ) } ) );
		}
	}

	/** A Spot packout.spot shows. It is read here and changed nowhere on this site. */
	function openHubSpot( spot ) {
		var body = sheet( spot.title || spot.category || config.hub.badge );
		body.setAttribute( 'data-pf-hub', String( spot.id ) );
		var badges = el( 'p', 'pf-badges' );
		badges.appendChild( ui( 'badge', { text: config.hub.badge, intent: 'none' } ) );
		badges.appendChild( ui( 'badge', { text: config.statuses[ spot.status ] || spot.status, intent: INTENTS[ spot.status ] || 'none' } ) );
		if ( spot.category ) {
			badges.appendChild( el( 'span', '', spot.category ) );
		}
		body.appendChild( badges );
		if ( spot.hazard && spot.hazard !== 'none' && config.hazards[ spot.hazard ] ) {
			body.appendChild( ui( 'notice', { intent: 'warning', title: config.hazards[ spot.hazard ], description: config.guidance[ spot.hazard ] || '' } ) );
		}
		[ spot.through ? config.hub.through.replace( '%s', spot.through ) : '', spot.coarse ? config.hub.coarse : '', spot.through ? '' : config.hub.number.replace( '%s', spot.id ) ].forEach( function ( line ) {
			if ( line ) {
				body.appendChild( el( 'p', 'pf-muted', line ) );
			}
		} );
		if ( spot.url ) {
			var link = el( 'a', 'pf-button', config.hub.open );
			link.href = spot.url;
			link.rel = 'noopener';
			body.appendChild( link );
		}
	}

	/* ---------- Views ---------- */

	function waiting() {
		return state.reports.filter( function ( report ) {
			return report.state === 'pending' || report.state === 'failed';
		} ).length + state.photos.filter( function ( photo ) {
			return ! photo.sent && ! photo.error;
		} ).length + state.work.filter( function ( entry ) {
			return entry.state === 'pending';
		} ).length + state.missions.reduce( function ( total, mission ) {
			return total + pendingOf( mission.id );
		}, 0 );
	}

	function discard( report ) {
		return Promise.all( [ remove( 'reports', report.client_id ) ].concat( photosOf( report.client_id ).map( function ( photo ) {
			return remove( 'photos', photo.id );
		} ) ) ).then( load ).then( function () {
			render();
			drawSpots();
		} );
	}

	function reportCard( report ) {
		var card = el( 'article', 'pf-card' );
		var known = STATES[ report.state ] || STATES.pending;
		var head = el( 'div', 'pf-card__head' );
		head.appendChild( ui( 'badge', { text: known[ 0 ], intent: known[ 1 ] } ) );
		var server = state.server[ report.client_id ];
		if ( server && report.state === 'sent' ) {
			head.appendChild( ui( 'badge', { text: config.review[ server.post_status ] || server.post_status, intent: server.post_status === 'publish' ? 'stable' : 'none' } ) );
		}
		var done = config.doneWords[ report.category ] || config.statuses.cleaned;
		var written = report.server_id ? workFor( report.server_id ) : null;
		var cleaned = !! written || ( server && server.status === 'cleaned' );
		if ( cleaned && report.state === 'sent' ) {
			head.appendChild( ui( 'badge', { text: written && written.state === 'pending' ? done + ': ' + __( 'waiting to send' ) : done, intent: written && written.state === 'pending' ? 'draft' : 'stable' } ) );
		}
		if ( server && server.status === 'watch' && report.state === 'sent' ) {
			head.appendChild( ui( 'badge', { text: config.statuses.watch, intent: 'high' } ) );
		}
		card.appendChild( head );
		card.appendChild( el( 'h3', '', config.categories[ report.category ] || report.category ) );
		card.appendChild( el( 'p', '', report.description ) );

		var facts = [];
		if ( report.landmark ) {
			facts.push( report.landmark );
		}
		if ( report.lat !== null && report.lat !== undefined ) {
			facts.push( Number( report.lat ).toFixed( 5 ) + ', ' + Number( report.lng ).toFixed( 5 ) );
		}
		var unsent = photosOf( report.client_id ).filter( function ( photo ) {
			return ! photo.sent;
		} );
		if ( unsent.length ) {
			facts.push( unsent.length === 1 ? __( '1 photo waiting' ) : unsent.length + ' ' + __( 'photos waiting' ) );
		}
		if ( facts.length ) {
			card.appendChild( el( 'p', 'pf-muted', facts.join( ' · ' ) ) );
		}
		if ( report.error ) {
			card.appendChild( ui( 'notice', { intent: report.state === 'failed' ? 'error' : 'warning', description: report.error } ) );
		}
		unsent.filter( function ( photo ) {
			return photo.error;
		} ).slice( 0, 1 ).forEach( function ( photo ) {
			card.appendChild( ui( 'notice', { intent: 'error', description: photo.error } ) );
		} );
		if ( server && server.return_reason && report.state !== 'removed' ) {
			card.appendChild( ui( 'notice', { intent: 'warning', title: __( 'Returned by a coordinator' ), description: server.return_reason } ) );
		}
		if ( report.duplicates && report.duplicates.length && report.state === 'sent' ) {
			card.appendChild( ui( 'notice', { intent: 'info', title: __( 'Possibly already reported' ), description: report.duplicates.map( function ( hint ) {
				return hint.title + ' (' + hint.distance + ' m)';
			} ).join( ', ' ) + '. ' + __( 'A coordinator decides whether they are the same place.' ) } ) );
		}

		var actions = el( 'div', 'pf-row' );
		if ( report.state === 'conflict' && report.server_copy ) {
			card.appendChild( el( 'p', 'pf-muted', __( 'Coordinator copy' ) + ': ' + report.server_copy.description ) );
			actions.appendChild( button( __( 'Keep my copy' ), '', function () {
				report.revision = report.server_copy.revision;
				report.state = 'pending';
				report.error = '';
				put( 'reports', report ).then( load ).then( function () {
					return sync( true );
				} );
			} ) );
			actions.appendChild( button( __( 'Use the coordinator copy' ), '', function () {
				var copy = report.server_copy;
				Object.assign( report, { category: copy.category.slug, description: copy.description, landmark: copy.location.landmark, lat: copy.location.lat, lng: copy.location.lng, hazard: copy.hazard, revision: copy.revision, state: 'sent', error: '', server_copy: null } );
				put( 'reports', report ).then( load ).then( render );
			} ) );
		} else if ( report.state === 'pending' || report.state === 'failed' ) {
			actions.appendChild( button( __( 'Edit' ), '', function () {
				openReport( report );
			} ) );
		} else if ( report.state === 'sent' && server && server.post_status === 'pending' ) {
			actions.appendChild( button( __( 'Edit' ), '', function () {
				openReport( report );
			} ) );
		}
		// A reporter can take care of their own Spot without a mission and record the work. A flagged Spot is left in place.
		if ( report.state === 'sent' && report.server_id && report.hazard === 'none' && ! ( server && server.status === 'watch' ) ) {
			var target = { id: report.server_id, title: config.categories[ report.category ] || report.category, category: report.category };
			if ( ! cleaned ) {
				actions.appendChild( button( __( 'Record the work' ), '', function () {
					openWork( target, 0, null );
				} ) );
			} else if ( written && written.verification !== 'verified' ) {
				actions.appendChild( button( __( 'Edit the record' ), '', function () {
					openWork( target, 0, written );
				} ) );
			}
		}
		if ( report.state !== 'sent' || ! unsent.length ) {
			actions.appendChild( button( report.state === 'sent' || report.state === 'reviewed' || report.state === 'removed' ? __( 'Remove from this device' ) : __( 'Discard' ), 'pf-button--plain', function () {
				if ( window.confirm( report.state === 'pending' || report.state === 'failed' ? __( 'Discard this report? It has not been sent.' ) : __( 'Remove this report from the device? The Spot on the server is not affected.' ) ) ) {
					discard( report );
				}
			} ) );
		}
		card.appendChild( actions );
		return card;
	}

	/* ---------- Missions ---------- */

	var openMissionId = 0;

	function lineDone( mission, line ) {
		var changes = state.changes[ mission.id ];
		return changes && changes.lines[ line.id ] !== undefined ? changes.lines[ line.id ] : line.done;
	}

	function outcomeOf( mission, spot ) {
		var changes = state.changes[ mission.id ];
		return changes && changes.outcomes[ spot.id ] !== undefined ? changes.outcomes[ spot.id ] : spot.outcome;
	}

	function missionOpen( mission ) {
		return mission.status === 'planned' || mission.status === 'in_progress';
	}

	function cleanedCount( mission ) {
		return mission.spots.filter( function ( spot ) {
			return outcomeOf( mission, spot ) === 'cleaned';
		} ).length;
	}

	/** Record a tick or an outcome on the device. An answer equal to the server's is not a change. */
	function record( mission, kind, item, value ) {
		var changes = changesOf( mission.id );
		var known = kind === 'lines' ? item.done : item.outcome;
		var key = ( kind === 'lines' ? 'line:' : 'spot:' ) + item.id;
		if ( value === known ) {
			delete changes[ kind ][ item.id ];
		} else {
			changes[ kind ][ item.id ] = value;
		}
		delete changes.refused[ key ];
		delete changes.conflicts[ key ];
		if ( kind === 'outcomes' && value === 'cleaned' && known !== 'cleaned' ) {
			toast( config.celebration );
		}
		return saveChanges().then( function () {
			render();
			redrawMission();
			syncSoon();
		} );
	}

	/** What the device knows about one answer: refused, in conflict, or waiting with the lead. */
	function itemNotes( mission, key, describe ) {
		var notes = [];
		var changes = state.changes[ mission.id ];
		if ( changes && changes.refused[ key ] ) {
			notes.push( ui( 'notice', { intent: 'warning', description: changes.refused[ key ] } ) );
		}
		if ( changes && changes.conflicts[ key ] !== undefined ) {
			var mine = changes.conflicts[ key ];
			var box = el( 'div', 'pf-conflict' );
			box.appendChild( ui( 'notice', { intent: 'warning', title: __( 'Someone answered this differently' ), description: __( 'Your answer' ) + ': ' + describe( mine ) + '. ' + __( 'Choose which one stands.' ) } ) );
			var row = el( 'div', 'pf-row' );
			row.appendChild( button( __( 'Keep mine' ), '', function () {
				var parts = key.split( ':' );
				changes[ parts[ 0 ] === 'line' ? 'lines' : 'outcomes' ][ parts[ 1 ] ] = mine;
				changes.force = true;
				delete changes.conflicts[ key ];
				saveChanges().then( function () {
					redrawMission();
					return sync( true );
				} );
			} ) );
			row.appendChild( button( __( 'Keep what is recorded' ), 'pf-button--plain', function () {
				delete changes.conflicts[ key ];
				saveChanges().then( function () {
					render();
					redrawMission();
				} );
			} ) );
			box.appendChild( row );
			notes.push( box );
		}
		( mission.waiting || [] ).filter( function ( item ) {
			return item.key === key && item.mine;
		} ).forEach( function ( item ) {
			notes.push( ui( 'notice', { intent: 'info', description: __( 'Someone answered this differently before your phone synced. Your answer' ) + ' (' + describe( item.value ) + ') ' + __( 'is with the lead to decide.' ) } ) );
		} );
		return notes;
	}

	function showOnMap( lng, lat, label ) {
		closeSheet();
		state.tab = 'map';
		render();
		if ( ! map ) {
			return;
		}
		map.resize();
		if ( showOnMap.marker ) {
			showOnMap.marker.remove();
		}
		showOnMap.marker = new window.maplibregl.Marker( { color: '#25486b' } ).setLngLat( [ lng, lat ] ).setPopup( new window.maplibregl.Popup( { offset: 24 } ).setText( label ) ).addTo( map );
		map.flyTo( { center: [ lng, lat ], zoom: Math.max( map.getZoom(), 13 ) } );
	}

	function leadAction( mission, status, question ) {
		if ( ! window.confirm( question ) ) {
			return;
		}
		api( 'POST', 'missions/' + mission.id + '/status', { status: status } ).then( function ( result ) {
			if ( result.ok ) {
				return keepMission( result.data.mission ).then( function () {
					return sync( false );
				} );
			}
			toast( result.data.message || __( 'The mission could not be changed.' ) );
		} ).catch( function () {
			toast( __( 'No connection. Try again when the phone has service.' ) );
		} );
	}

	/** The campaign a mission belongs to and the sub-teams it is assigned to, as one line. */
	function missionPlan( mission ) {
		var parts = [];
		if ( mission.campaign ) {
			parts.push( __( 'Campaign' ) + ': ' + mission.campaign );
		}
		if ( mission.subteams && mission.subteams.length ) {
			parts.push( __( 'Assigned to' ) + ' ' + mission.subteams.join( ', ' ) );
		}
		return parts.join( ' · ' );
	}

	function drawMission( body, mission ) {
		var open = missionOpen( mission );
		var labels = config.outcomes;

		var badges = el( 'p', 'pf-badges' );
		badges.appendChild( ui( 'badge', { text: config.missionStatuses[ mission.status ] || mission.status, intent: MISSION_INTENTS[ mission.status ] || 'none' } ) );
		badges.appendChild( el( 'span', '', [ mission.team.name, mission.window.label ].filter( Boolean ).join( ' · ' ) ) );
		body.appendChild( badges );
		var plan = missionPlan( mission );
		if ( plan ) {
			body.appendChild( el( 'p', 'pf-muted', plan ) );
		}

		if ( pendingOf( mission.id ) ) {
			body.appendChild( ui( 'notice', { intent: 'info', description: pendingOf( mission.id ) === 1 ? __( '1 change is saved on this device and waiting to send.' ) : pendingOf( mission.id ) + ' ' + __( 'changes are saved on this device and waiting to send.' ) } ) );
		}
		var others = ( mission.waiting || [] ).filter( function ( item ) {
			return ! item.mine;
		} ).length;
		if ( mission.can_manage && others ) {
			body.appendChild( ui( 'notice', { intent: 'warning', title: __( 'The crew is waiting for your decision' ), description: ( others === 1 ? __( '1 answer from a phone disagrees with what is recorded.' ) : others + ' ' + __( 'answers from phones disagree with what is recorded.' ) ) + ' ' + __( 'Open the mission on the website to choose.' ) } ) );
		}
		if ( mission.objective ) {
			body.appendChild( el( 'p', '', mission.objective ) );
		}

		if ( mission.meeting.note || mission.meeting.lat !== null ) {
			var meet = el( 'section', 'pf-block' );
			meet.appendChild( el( 'h3', '', __( 'Where the crew meets' ) ) );
			if ( mission.meeting.note ) {
				meet.appendChild( el( 'p', '', mission.meeting.note ) );
			}
			if ( mission.meeting.lat !== null ) {
				meet.appendChild( button( __( 'Show on the map' ), '', function () {
					showOnMap( mission.meeting.lng, mission.meeting.lat, mission.meeting.note || __( 'Meeting place' ) );
				} ) );
			}
			body.appendChild( meet );
		}
		if ( mission.gear ) {
			body.appendChild( ui( 'notice', { intent: 'info', title: __( 'Gear and caution' ), description: mission.gear } ) );
		}

		// What the team shares for this mission: its own, its sub-teams', and the team's.
		if ( mission.resources && mission.resources.length ) {
			var shared = el( 'section', 'pf-block' );
			shared.appendChild( el( 'h3', '', __( 'Resources' ) ) );
			var sharedList = el( 'ul', 'pf-resources' );
			mission.resources.forEach( function ( resource ) {
				var item = el( 'li' );
				var link = el( 'a', '', resource.title );
				link.href = resource.link;
				link.target = '_blank';
				link.rel = 'noopener';
				item.appendChild( link );
				item.appendChild( el( 'span', 'pf-muted', [ resource.for, resource.file ? __( 'File' ) : __( 'Link' ), resource.note ].filter( Boolean ).join( ' · ' ) ) );
				sharedList.appendChild( item );
			} );
			shared.appendChild( sharedList );
			shared.appendChild( el( 'p', 'pf-muted', __( 'A resource opens when the phone has a signal.' ) ) );
			body.appendChild( shared );
		}

		if ( mission.checklist.length ) {
			var list = el( 'section', 'pf-block' );
			list.appendChild( el( 'h3', '', __( 'Checklist' ) ) );
			mission.checklist.forEach( function ( line ) {
				var row = el( 'label', 'pf-check' );
				var box = el( 'input' );
				box.type = 'checkbox';
				box.checked = lineDone( mission, line );
				box.disabled = ! open;
				box.addEventListener( 'change', function () {
					record( mission, 'lines', line, box.checked );
				} );
				row.appendChild( box );
				row.appendChild( el( 'span', '', line.text ) );
				list.appendChild( row );
				itemNotes( mission, 'line:' + line.id, function ( value ) {
					return value === true || value === '1' ? __( 'done' ) : __( 'not done' );
				} ).forEach( function ( note ) {
					list.appendChild( note );
				} );
			} );
			body.appendChild( list );
		}

		var places = el( 'section', 'pf-block' );
		places.appendChild( el( 'h3', '', __( 'Spots' ) + ' (' + cleanedCount( mission ) + ' ' + __( 'of' ) + ' ' + mission.spots.length + ' ' + __( 'cleaned' ) + ')' ) );
		mission.spots.forEach( function ( spot ) {
			var card = el( 'article', 'pf-card' );
			card.appendChild( el( 'h4', '', spot.title ) );
			card.appendChild( el( 'p', '', spot.description ) );
			if ( spot.location.landmark ) {
				card.appendChild( el( 'p', 'pf-muted', __( 'Landmark' ) + ': ' + spot.location.landmark ) );
			}
			if ( spot.place ) {
				card.appendChild( el( 'p', 'pf-muted', __( 'Place' ) + ': ' + spot.place.name ) );
			}
			if ( spot.needs_authorization ) {
				card.appendChild( ui( 'notice', { intent: 'warning', title: config.hazards[ spot.hazard ], description: spot.guidance } ) );
			} else if ( spot.authorized ) {
				card.appendChild( ui( 'notice', { intent: 'info', title: __( 'Work is authorized' ), description: clearanceText( spot ) } ) );
			}

			var choice = el( 'select' );
			choice.appendChild( new window.Option( __( 'Not recorded' ), '' ) );
			Object.keys( labels ).forEach( function ( value ) {
				if ( value === 'reopen' && ! mission.can_manage ) {
					return;
				}
				var option = new window.Option( labels[ value ], value );
				// A flagged Spot stays where it is until a coordinator clears the flag.
				option.disabled = value === 'cleaned' && spot.needs_authorization;
				choice.appendChild( option );
			} );
			choice.value = outcomeOf( mission, spot ) || '';
			choice.disabled = ! open;
			choice.addEventListener( 'change', function () {
				if ( choice.value === '' ) {
					choice.value = outcomeOf( mission, spot ) || '';
					return;
				}
				record( mission, 'outcomes', spot, choice.value );
			} );
			card.appendChild( field( __( 'Outcome' ), choice ) );
			itemNotes( mission, 'spot:' + spot.id, function ( value ) {
				return labels[ value ] || value;
			} ).forEach( function ( note ) {
				card.appendChild( note );
			} );

			var actions = el( 'div', 'pf-row' );
			if ( spot.location.lat !== null ) {
				actions.appendChild( button( __( 'Show on the map' ), '', function () {
					showOnMap( spot.location.lng, spot.location.lat, spot.title );
				} ) );
			}
			// The lead writes up each Spot this mission cleaned: figures and photos taken afterward.
			// A cleanup marked on this device counts too: the outcome is sent before the record.
			if ( spot.record || ( mission.can_manage && outcomeOf( mission, spot ) === 'cleaned' ) ) {
				var written = workFor( spot.id ) || spot.impact;
				if ( written && written.verification === 'verified' ) {
					card.appendChild( ui( 'badge', { text: __( 'Impact recorded and verified' ), intent: 'stable' } ) );
				} else {
					if ( written ) {
						card.appendChild( ui( 'badge', { text: written.state === 'pending' ? __( 'Impact: waiting to send' ) : __( 'Impact recorded, unverified' ), intent: 'draft' } ) );
					}
					actions.appendChild( button( written ? __( 'Edit the record' ) : __( 'Record the work' ), written ? '' : 'pf-button--primary', function () {
						openWork( { id: spot.id, title: spot.title, category: spot.category.slug }, mission.id, written );
					} ) );
				}
			}
			if ( actions.childNodes.length ) {
				card.appendChild( actions );
			}
			places.appendChild( card );
		} );
		body.appendChild( places );

		if ( mission.can_manage && open ) {
			var lead = el( 'section', 'pf-block' );
			lead.appendChild( el( 'h3', '', __( 'Lead' ) ) );
			lead.appendChild( el( 'p', 'pf-muted', __( 'Completing does not mark any Spot cleaned. Record each Spot above first. These need a connection.' ) ) );
			var row = el( 'div', 'pf-row' );
			if ( mission.status === 'planned' ) {
				var start = button( __( 'Start the mission' ), '', function () {
					leadAction( mission, 'in_progress', __( 'Start this mission? Its planned Spots become in progress.' ) );
				} );
				start.disabled = ! window.navigator.onLine;
				row.appendChild( start );
			}
			var finish = button( __( 'Complete the mission' ), 'pf-button--primary', function () {
				leadAction( mission, 'completed', __( 'Complete this mission? Spots not marked cleaned stay open.' ) );
			} );
			finish.disabled = ! window.navigator.onLine || pendingOf( mission.id ) > 0;
			row.appendChild( finish );
			lead.appendChild( row );
			body.appendChild( lead );
		}
	}

	function openMission( id ) {
		var mission = missionById( id );
		if ( ! mission ) {
			return;
		}
		var body = sheet( mission.title );
		openMissionId = id;
		drawMission( body, mission );
	}

	/** Redraw the open mission in place, keeping the reader's position. */
	function redrawMission() {
		var node = root.querySelector( '.pf-sheet' );
		var mission = openMissionId && ! node.hidden ? missionById( openMissionId ) : null;
		if ( ! mission ) {
			return;
		}
		var body = node.querySelector( '.pf-sheet__body' );
		var at = node.scrollTop;
		var inner = body.scrollTop;
		body.textContent = '';
		drawMission( body, mission );
		node.scrollTop = at;
		body.scrollTop = inner;
	}

	function renderMissions( panel ) {
		panel.appendChild( el( 'h2', '', __( 'Missions' ) ) );
		if ( ! state.missions.length ) {
			panel.appendChild( ui( 'empty-state', { title: __( 'No missions for you yet' ), description: state.session === 'signed-in' ? __( 'Missions appear here when you are in an organization and its lead plans one. Join or start an organization under My organizations on the website.' ) : __( 'Sign in to see your organization\'s missions.' ) } ) );
			return;
		}
		state.missions.forEach( function ( mission ) {
			var card = el( 'article', 'pf-card' );
			var head = el( 'div', 'pf-card__head' );
			head.appendChild( ui( 'badge', { text: config.missionStatuses[ mission.status ] || mission.status, intent: MISSION_INTENTS[ mission.status ] || 'none' } ) );
			if ( pendingOf( mission.id ) ) {
				head.appendChild( ui( 'badge', { text: __( 'Waiting to send' ), intent: 'draft' } ) );
			}
			card.appendChild( head );
			card.appendChild( el( 'h3', '', mission.title ) );
			card.appendChild( el( 'p', 'pf-muted', [ mission.team.name, mission.window.label, cleanedCount( mission ) + ' ' + __( 'of' ) + ' ' + mission.spots.length + ' ' + __( 'Spots cleaned' ) ].filter( Boolean ).join( ' · ' ) ) );
			if ( missionPlan( mission ) ) {
				card.appendChild( el( 'p', 'pf-muted', missionPlan( mission ) ) );
			}
			var actions = el( 'div', 'pf-row' );
			actions.appendChild( button( __( 'Open' ), 'pf-button--primary', function () {
				openMission( mission.id );
			} ) );
			card.appendChild( actions );
			panel.appendChild( card );
		} );
	}

	/* ---------- Work and return visits ---------- */

	/**
	 * What is known about the record of a Spot's cleanup: the copy on this
	 * device while it waits, otherwise the newest one the server holds.
	 */
	function workFor( spotId ) {
		var local = state.work.find( function ( entry ) {
			return entry.kind === 'cleanup' && entry.spot === spotId && entry.state !== 'sent';
		} );
		if ( local ) {
			return local;
		}
		return state.records.find( function ( record ) {
			return record.kind === 'cleanup' && record.spot === spotId;
		} ) || state.work.find( function ( entry ) {
			return entry.kind === 'cleanup' && entry.spot === spotId;
		} ) || null;
	}

	/** A photo picker. Returns the nodes to show and the photos chosen so far. */
	function photoPicker( clientId, label, help ) {
		var chosen = photosOf( clientId ).filter( function ( photo ) {
			return ! photo.sent;
		} );
		var removed = [];
		var picker = el( 'input' );
		picker.type = 'file';
		picker.accept = 'image/*';
		picker.multiple = true;
		var thumbs = el( 'div', 'pf-thumbs' );
		function show() {
			thumbs.textContent = '';
			chosen.forEach( function ( photo, index ) {
				var item = el( 'div', 'pf-thumb' );
				var image = el( 'img' );
				image.alt = label;
				image.src = window.URL.createObjectURL( photo.blob );
				item.appendChild( image );
				item.appendChild( button( __( 'Remove' ), 'pf-button--plain', function () {
					removed.push( photo.id );
					chosen.splice( index, 1 );
					show();
				} ) );
				thumbs.appendChild( item );
			} );
		}
		picker.addEventListener( 'change', function () {
			var files = Array.prototype.slice.call( picker.files || [] ).slice( 0, config.photoLimit - chosen.length );
			Promise.all( files.map( prepare ) ).then( function ( ready ) {
				ready.forEach( function ( item ) {
					chosen.push( { id: uuid(), client_id: clientId, blob: item.blob, taken: item.taken, name: 'after.jpg', sent: false } );
				} );
				picker.value = '';
				show();
			} );
		} );
		show();
		return {
			nodes: [ field( label, picker, help ), thumbs ],
			save: function () {
				return Promise.all( chosen.map( function ( photo ) {
					return put( 'photos', photo );
				} ).concat( removed.map( function ( id ) {
					return remove( 'photos', id );
				} ) ) );
			},
			count: function () {
				return chosen.length;
			},
		};
	}

	function numberInput( value, step ) {
		var input = el( 'input' );
		input.type = 'number';
		input.min = '0';
		input.step = step;
		input.inputMode = 'decimal';
		input.value = value ? String( value ) : '';
		return input;
	}

	function sourceSelect( value ) {
		var select = el( 'select' );
		Object.keys( config.sources ).forEach( function ( key ) {
			select.appendChild( new window.Option( config.sources[ key ], key ) );
		} );
		select.value = value || 'self_reported';
		return select;
	}

	function keepWork( entry ) {
		state.work = state.work.filter( function ( item ) {
			return item.client_id !== entry.client_id;
		} ).concat( [ entry ] );
		return remember( 'work', state.work );
	}

	/**
	 * Record the work done at a Spot: what changed, the photos taken
	 * afterward, and the figures. Saved on the device first.
	 *
	 * @param {Object}      spot     id, title, and category slug.
	 * @param {number}      mission  Mission id, or 0 for a cleanup with no mission.
	 * @param {Object|null} existing The record so far, from this device or the server.
	 */
	function openWork( spot, mission, existing ) {
		var known = existing || {};
		// A record the server already holds keeps its own id there. A new copy on the device gets a new one.
		var entry = { client_id: known.state && known.state !== 'sent' ? known.client_id : uuid(), kind: 'cleanup', spot: spot.id, title: spot.title, category: spot.category, mission: mission, created: Date.now() };
		var done = config.doneWords[ spot.category ] || config.statuses.cleaned;
		var body = sheet( __( 'Record the work' ) );
		body.appendChild( el( 'p', 'pf-muted', spot.title ) );

		var narrative = el( 'textarea' );
		narrative.rows = 3;
		narrative.value = known.narrative || '';
		body.appendChild( field( __( 'What changed' ), narrative, __( 'What you removed or fixed, and what the place looks like now.' ) ) );

		var photos = photoPicker( entry.client_id, __( 'Photos taken afterward' ), __( 'Shown on the Spot\'s page, after the photos of the report.' ) );
		photos.nodes.forEach( function ( node ) {
			body.appendChild( node );
		} );
		if ( known.photos ) {
			body.appendChild( el( 'p', 'pf-muted', known.photos === 1 ? __( '1 photo is already with this record.' ) : known.photos + ' ' + __( 'photos are already with this record.' ) ) );
		}

		var hours = numberInput( known.hours, '0.25' );
		var people = numberInput( known.people, '1' );
		var pair = el( 'div', 'pf-pair' );
		pair.appendChild( field( __( 'Hours, everyone together' ), hours ) );
		pair.appendChild( field( __( 'People' ), people ) );
		body.appendChild( pair );

		var bags = numberInput( known.bags, '1' );
		var bagsSource = sourceSelect( known.bags_source );
		pair = el( 'div', 'pf-pair' );
		pair.appendChild( field( __( 'Bags' ), bags ) );
		pair.appendChild( field( __( 'Bags: source' ), bagsSource ) );
		body.appendChild( pair );

		var pounds = numberInput( known.pounds, '0.1' );
		var poundsSource = sourceSelect( known.pounds_source );
		pair = el( 'div', 'pf-pair' );
		pair.appendChild( field( __( 'Pounds' ), pounds ) );
		pair.appendChild( field( __( 'Pounds: source' ), poundsSource ) );
		body.appendChild( pair );
		body.appendChild( el( 'p', 'pf-muted', __( 'Measured means counted or weighed. An estimate is self-reported.' ) ) );

		var again = el( 'input' );
		again.type = 'checkbox';
		again.checked = !! known.come_back;
		var row = el( 'label', 'pf-check' );
		row.appendChild( again );
		row.appendChild( el( 'span', '', __( 'This place needs another visit' ) ) );
		body.appendChild( row );
		var note = el( 'input' );
		note.type = 'text';
		note.value = known.come_back_note || '';
		body.appendChild( field( __( 'Why, and what to bring' ), note ) );

		body.appendChild( button( done, 'pf-button--primary pf-button--wide', function () {
			Object.assign( entry, {
				narrative: narrative.value.trim(),
				hours: Number( hours.value ) || 0,
				people: Math.round( Number( people.value ) || 0 ),
				bags: Math.round( Number( bags.value ) || 0 ),
				bags_source: bagsSource.value,
				pounds: Number( pounds.value ) || 0,
				pounds_source: poundsSource.value,
				come_back: again.checked,
				come_back_note: note.value.trim(),
				state: 'pending',
				error: '',
			} );
			Promise.all( [ keepWork( entry ), photos.save() ] ).then( load ).then( function () {
				closeSheet();
				if ( mission ) {
					// Back to the mission the lead was working through.
					openMission( mission );
				} else {
					state.tab = 'reports';
				}
				render();
				var saved = window.navigator.onLine ? __( 'Saved. Sending now.' ) : __( 'Saved on this device. It will send when you have service.' );
				toast( existing ? saved : config.celebration + ' ' + saved );
				return sync( false );
			} );
		} ) );
	}

	/** A return visit to a cleaned Spot: still clear, or the problem is back. */
	function openVisit( spot, result ) {
		var entry = { client_id: uuid(), kind: result, spot: spot.id, title: spot.title, category: spot.category.slug, mission: 0, created: Date.now() };
		var body = sheet( result === 'returned' ? __( 'It\'s back' ) : __( 'Still clear' ) );
		body.appendChild( el( 'p', 'pf-muted', spot.title ) );
		body.appendChild( ui( 'notice', { intent: result === 'returned' ? 'warning' : 'info', description: result === 'returned' ? __( 'The Spot goes back on the map as needing attention. The record of the earlier cleanup is kept.' ) : __( 'The Spot stays cleaned. Your visit is evidence that the work held.' ) } ) );

		var description = el( 'textarea' );
		description.rows = 3;
		body.appendChild( field( __( 'What you found' ), description ) );
		var photos = photoPicker( entry.client_id, __( 'Photos from this visit' ), __( 'Shown on the Spot\'s page.' ) );
		photos.nodes.forEach( function ( node ) {
			body.appendChild( node );
		} );
		var problem = el( 'div', 'pf-problem' );
		problem.setAttribute( 'role', 'alert' );
		body.appendChild( problem );

		body.appendChild( button( __( 'Save on this device' ), 'pf-button--primary pf-button--wide', function () {
			entry.narrative = description.value.trim();
			if ( ! entry.narrative ) {
				problem.textContent = '';
				problem.appendChild( ui( 'notice', { intent: 'error', description: __( 'Describe what you found.' ) } ) );
				return;
			}
			entry.state = 'pending';
			entry.error = '';
			Promise.all( [ keepWork( entry ), photos.save() ] ).then( load ).then( function () {
				closeSheet();
				state.tab = 'reports';
				render();
				toast( window.navigator.onLine ? __( 'Saved. Sending now.' ) : __( 'Saved on this device. It will send when you have service.' ) );
				return sync( false );
			} );
		} ) );
	}

	function figuresOf( record ) {
		var parts = [];
		if ( record.bags ) {
			parts.push( record.bags + ' ' + ( record.bags === 1 ? __( 'bag' ) : __( 'bags' ) ) + ', ' + String( config.sources[ record.bags_source ] || '' ).toLowerCase() );
		}
		if ( record.pounds ) {
			parts.push( record.pounds + ' lb, ' + String( config.sources[ record.pounds_source ] || '' ).toLowerCase() );
		}
		if ( record.hours ) {
			parts.push( record.hours + ' h' );
		}
		return parts.join( ' · ' );
	}

	/** One record of work or one visit: waiting on this device, or as the server holds it. */
	function workCard( record, local ) {
		var card = el( 'article', 'pf-card' );
		var head = el( 'div', 'pf-card__head' );
		head.appendChild( ui( 'badge', { text: config.kinds[ record.kind ] || record.kind, intent: record.kind === 'returned' ? 'high' : record.kind === 'still_clear' ? 'informational' : 'stable' } ) );
		if ( local ) {
			var known = STATES[ record.state ] || STATES.pending;
			head.appendChild( ui( 'badge', { text: known[ 0 ], intent: known[ 1 ] } ) );
		} else if ( record.kind === 'cleanup' || record.verification === 'verified' ) {
			// A visit is evidence in itself. Only a cleanup waits on a lead.
			head.appendChild( ui( 'badge', { text: config.verifications[ record.verification ] || record.verification, intent: record.verification === 'verified' ? 'stable' : 'draft' } ) );
		}
		card.appendChild( head );
		card.appendChild( el( 'h3', '', local ? record.title : record.spot_title ) );
		if ( record.narrative ) {
			card.appendChild( el( 'p', '', record.narrative ) );
		}
		var facts = [ figuresOf( record ) ];
		var unsent = local ? photosOf( record.client_id ).filter( function ( photo ) {
			return ! photo.sent;
		} ) : [];
		if ( unsent.length ) {
			facts.push( unsent.length === 1 ? __( '1 photo waiting' ) : unsent.length + ' ' + __( 'photos waiting' ) );
		} else if ( ! local && record.photos ) {
			facts.push( record.photos === 1 ? __( '1 photo' ) : record.photos + ' ' + __( 'photos' ) );
		}
		facts = facts.filter( Boolean );
		if ( facts.length ) {
			card.appendChild( el( 'p', 'pf-muted', facts.join( ' · ' ) ) );
		}
		if ( local && record.error ) {
			card.appendChild( ui( 'notice', { intent: 'error', description: record.error } ) );
		}
		unsent.filter( function ( photo ) {
			return photo.error;
		} ).slice( 0, 1 ).forEach( function ( photo ) {
			card.appendChild( ui( 'notice', { intent: 'error', description: photo.error } ) );
		} );
		if ( local && record.state !== 'sent' ) {
			var actions = el( 'div', 'pf-row' );
			actions.appendChild( button( __( 'Discard' ), 'pf-button--plain', function () {
				if ( window.confirm( __( 'Discard this record? It has not been sent.' ) ) ) {
					state.work = state.work.filter( function ( item ) {
						return item.client_id !== record.client_id;
					} );
					Promise.all( [ remember( 'work', state.work ) ].concat( photosOf( record.client_id ).map( function ( photo ) {
						return remove( 'photos', photo.id );
					} ) ) ).then( load ).then( render );
				}
			} ) );
			card.appendChild( actions );
		}
		return card;
	}

	function renderReports( panel ) {
		panel.appendChild( el( 'h2', '', __( 'Your reports' ) ) );
		if ( ! state.reports.length ) {
			panel.appendChild( ui( 'empty-state', { title: __( 'No reports on this device' ), description: __( 'Reports you save here stay on the device until they send.' ) } ) );
		}
		state.reports.forEach( function ( report ) {
			panel.appendChild( reportCard( report ) );
		} );

		// What is still on this device, then what the server holds.
		var waitingWork = state.work.filter( function ( entry ) {
			return ! state.records.some( function ( record ) {
				return record.id === entry.server_id;
			} ) || photosOf( entry.client_id ).length > 0;
		} );
		var held = state.records.filter( function ( record ) {
			return ! waitingWork.some( function ( entry ) {
				return entry.server_id === record.id;
			} );
		} );
		if ( ! waitingWork.length && ! held.length ) {
			return;
		}
		panel.appendChild( el( 'h2', '', __( 'Your work and visits' ) ) );
		waitingWork.forEach( function ( entry ) {
			panel.appendChild( workCard( entry, true ) );
		} );
		held.forEach( function ( record ) {
			panel.appendChild( workCard( record, false ) );
		} );
	}

	function renderRegions( panel ) {
		panel.appendChild( el( 'h2', '', __( 'Offline map regions' ) ) );
		panel.appendChild( el( 'p', 'pf-muted', __( 'Download a region before you leave coverage. The map and the Spots inside it stay on this device.' ) ) );
		if ( config.hub && state.hub.length ) {
			var hubLine = el( 'p', 'pf-muted', state.hub.length === 1 ? config.hub.kept_one : config.hub.kept.replace( '%s', String( state.hub.length ) ) );
			hubLine.setAttribute( 'data-pf-hub-count', String( state.hub.length ) );
			panel.appendChild( hubLine );
		}

		if ( window.navigator.onLine ) {
			var online = el( 'article', 'pf-card' );
			online.appendChild( el( 'h3', '', __( 'Online map' ) ) );
			online.appendChild( state.basemap === 'online' ? ui( 'badge', { text: __( 'Showing' ), intent: 'stable' } ) : button( __( 'Show on the map' ), '', function () {
				setBasemap( 'online' ).then( function () {
					state.tab = 'map';
					render();
				} );
			} ) );
			panel.appendChild( online );
		}

		if ( ! window.caches ) {
			panel.appendChild( ui( 'notice', { intent: 'warning', description: __( 'This browser cannot keep map regions. Open the field app over https in a current browser.' ) } ) );
			return;
		}
		if ( ! state.regions.length ) {
			panel.appendChild( ui( 'empty-state', { title: __( 'No regions published yet' ), description: state.session === 'signed-in' ? __( 'A coordinator publishes regions. Reports still save without one.' ) : __( 'Sign in to see the regions a coordinator has published.' ) } ) );
			return;
		}
		state.regions.forEach( function ( region ) {
			var card = el( 'article', 'pf-card' );
			var saved = !! state.downloaded[ address( region ) ];
			card.appendChild( el( 'h3', '', region.name ) );
			var actions = el( 'div', 'pf-row' );
			if ( saved ) {
				card.appendChild( ui( 'badge', { text: state.basemap === region.id ? __( 'Showing' ) : __( 'On this device' ), intent: 'stable' } ) );
				actions.appendChild( button( __( 'Show on the map' ), '', function () {
					setBasemap( region.id ).then( function () {
						state.tab = 'map';
						render();
					} );
				} ) );
				actions.appendChild( button( __( 'Remove' ), 'pf-button--plain', function () {
					forget( region );
				} ) );
			} else {
				var get = button( __( 'Download' ), 'pf-button--primary', function () {
					download( region, get );
				} );
				get.disabled = ! window.navigator.onLine;
				actions.appendChild( get );
			}
			card.appendChild( actions );
			panel.appendChild( card );
		} );
	}

	function render() {
		var online = window.navigator.onLine;
		var top = root.querySelector( '.pf-top__status' );
		top.textContent = '';
		top.appendChild( ui( 'badge', { text: online ? __( 'Online' ) : __( 'Offline' ), intent: online ? 'stable' : 'medium' } ) );
		var count = waiting();
		var send = button( state.syncing ? __( 'Sending…' ) : ( count ? __( 'Send' ) + ' (' + count + ')' : __( 'Sync' ) ), 'pf-button--primary', function () {
			sync( true );
		} );
		send.disabled = state.syncing;
		top.appendChild( send );

		var notices = root.querySelector( '.pf-notices' );
		notices.textContent = '';
		if ( online && state.session === 'signed-out' ) {
			var sign = el( 'div', 'pf-notice' );
			sign.appendChild( ui( 'notice', { intent: 'info', title: __( 'Sign in to send reports' ), description: __( 'You can keep drafting reports on this device. They send after you sign in.' ) } ) );
			var link = el( 'a', 'pf-button pf-button--primary', __( 'Sign in' ) );
			link.href = config.login;
			sign.appendChild( link );
			if ( config.signup ) {
				var join = el( 'a', 'pf-button', __( 'Sign up' ) );
				join.href = config.signup;
				sign.appendChild( document.createTextNode( ' ' ) );
				sign.appendChild( join );
			}
			notices.appendChild( sign );
		} else if ( state.session === 'denied' ) {
			notices.appendChild( ui( 'notice', { intent: 'warning', title: __( 'This account cannot send reports yet' ), description: config.teams ? __( 'Open My organizations on the website to join Pack-Out Spot with this account.' ) : __( 'Ask a coordinator to make you a reporter.' ) } ) );
			if ( config.teams ) {
				var mine = el( 'a', 'pf-button', __( 'My organizations' ) );
				mine.href = config.teams;
				notices.appendChild( mine );
			}
		}
		if ( state.tab === 'map' && state.basemap === 'none' ) {
			notices.appendChild( ui( 'notice', { intent: 'info', description: online ? __( 'The base map did not load. Reports still save.' ) : __( 'No offline map on this device. Reports still save, with or without a pin.' ) } ) );
		}

		root.querySelectorAll( '.pf-tabs button' ).forEach( function ( tab ) {
			var active = tab.getAttribute( 'data-tab' ) === state.tab;
			tab.setAttribute( 'aria-current', active ? 'page' : 'false' );
			if ( tab.getAttribute( 'data-tab' ) === 'reports' ) {
				tab.textContent = __( 'Reports' ) + ( state.reports.length ? ' (' + state.reports.length + ')' : '' );
			}
			if ( tab.getAttribute( 'data-tab' ) === 'missions' ) {
				var live = state.missions.filter( missionOpen ).length;
				tab.textContent = __( 'Missions' ) + ( live ? ' (' + live + ')' : '' );
			}
		} );

		var panel = root.querySelector( '.pf-panel' );
		panel.textContent = '';
		panel.hidden = state.tab === 'map';
		root.querySelector( '.pf-fab' ).hidden = state.tab !== 'map';
		root.querySelector( '.pf-search' ).hidden = state.tab !== 'map';
		if ( state.tab === 'reports' ) {
			renderReports( panel );
		} else if ( state.tab === 'missions' ) {
			renderMissions( panel );
		} else if ( state.tab === 'regions' ) {
			renderRegions( panel );
		}
	}

	function build() {
		root.textContent = '';

		var top = el( 'header', 'pf-top' );
		top.appendChild( el( 'h1', '', config.name || 'Pack-Out Spot' ) );
		top.appendChild( el( 'div', 'pf-top__status' ) );
		root.appendChild( top );
		root.appendChild( el( 'div', 'pf-notices' ) );

		var main = el( 'main', 'pf-main' );
		var canvas = el( 'div', 'pf-map' );
		canvas.setAttribute( 'role', 'region' );
		canvas.setAttribute( 'aria-label', __( 'Map' ) );
		main.appendChild( canvas );
		// A town, a road, a Spot, or a Place by name. It needs a connection, and narrows the map before a report.
		var finder = el( 'div', 'pf-search' );
		main.appendChild( finder );
		var fab = button( __( 'Report a Spot' ), 'pf-button--primary pf-fab', function () {
			openReport( null );
		} );
		main.appendChild( fab );
		var panel = el( 'section', 'pf-panel' );
		panel.hidden = true;
		main.appendChild( panel );
		root.appendChild( main );

		var tabs = el( 'nav', 'pf-tabs' );
		tabs.setAttribute( 'aria-label', __( 'Field app' ) );
		[ [ 'map', __( 'Map' ) ], [ 'missions', __( 'Missions' ) ], [ 'reports', __( 'Reports' ) ], [ 'regions', __( 'Regions' ) ] ].forEach( function ( tab ) {
			var node = button( tab[ 1 ], 'pf-button--tab', function () {
				state.tab = tab[ 0 ];
				render();
				if ( tab[ 0 ] === 'map' && map ) {
					map.resize();
				}
			} );
			node.setAttribute( 'data-tab', tab[ 0 ] );
			tabs.appendChild( node );
		} );
		root.appendChild( tabs );

		var dialog = el( 'div', 'pf-sheet' );
		dialog.hidden = true;
		dialog.setAttribute( 'role', 'dialog' );
		dialog.setAttribute( 'aria-modal', 'true' );
		root.appendChild( dialog );

		var note = el( 'div', 'pf-toast' );
		note.hidden = true;
		note.setAttribute( 'role', 'status' );
		root.appendChild( note );

		return canvas;
	}

	/* ---------- Start ---------- */

	var canvas = build();

	load().then( function () {
		render();
		return window.maplibregl ? startMap( canvas ) : null;
	} ).then( function () {
		return sync( false );
	} ).catch( function () {
		toast( __( 'This browser could not open the device storage the field app needs.' ) );
	} );

	window.addEventListener( 'online', function () {
		render();
		redrawMission();
		if ( state.basemap === 'none' ) {
			setBasemap( 'online' );
		}
		sync( false );
	} );
	window.addEventListener( 'offline', function () {
		render();
		redrawMission();
		if ( state.basemap === 'online' ) {
			offlineBasemap();
		}
	} );

	if ( 'serviceWorker' in window.navigator ) {
		window.navigator.serviceWorker.register( config.worker, { scope: config.scope } ).catch( function () {
			// The app still works online without the worker.
		} );
	}
} )();
