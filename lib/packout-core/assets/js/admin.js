/**
 * Pack-Out Spot administration screens.
 *
 * Binds the drawer to each list, reopens the record that was
 * just changed, draws the map preview when a Spot with a pin opens, filters
 * the Spot picker of a mission, and lets a coordinator draw the outline of a
 * Place by clicking points on a map.
 */
( function () {
	'use strict';

	var root = document.querySelector( '.packout-admin' );
	if ( ! root ) {
		return;
	}

	// A form that asks before it acts: the question is on the form.
	root.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		var question = form && form.getAttribute ? form.getAttribute( 'data-pos-confirm' ) : '';
		if ( question && ! window.confirm( question ) ) {
			event.preventDefault();
		}
	} );

	/**
	 * The classic table stays available when the Data View cannot draw.
	 *
	 * @param {HTMLElement} view View wrapper.
	 */
	function revealFallback( view ) {
		view.classList.add( 'is-fallback' );
	}

	/**
	 * Replace each plain table with a Data View once the runtime has drawn it.
	 * The plain table stays when the runtime is not on the page.
	 */
	function mountViews() {
		var runtime = window.packoutLists;
		var views = root.querySelectorAll( '.packout-admin-view' );
		if ( ! runtime || 'function' !== typeof runtime.render ) {
			Array.prototype.forEach.call( views, revealFallback );
			return;
		}
		Array.prototype.forEach.call( views, function ( view ) {
			var mount = view.querySelector( '.packout-admin-view__mount' );
			var plain = view.querySelector( '.packout-admin-view__plain' );
			if ( ! mount ) {
				revealFallback( view );
				return;
			}
			var config;
			try {
				config = JSON.parse( mount.getAttribute( 'data-config' ) || '' );
			} catch ( error ) {
				revealFallback( view );
				return;
			}
			var watch = new window.MutationObserver( function () {
				if ( ! drawn( mount ) ) {
					return;
				}
				watch.disconnect();
				mount.hidden = false;
				if ( plain ) {
					plain.hidden = true;
				}
				view.classList.add( 'is-mounted' );
				bindCount( view, mount );
			} );
			watch.observe( mount, { childList: true, subtree: true } );
			try {
				runtime.render( mount, config );
			} catch ( error ) {
				watch.disconnect();
				revealFallback( view );
			}
		} );
	}

	mountViews();

	/**
	 * The Data Views footer, with its page controls, is drawn only when a
	 * list has more than one page. The count belongs at the bottom either way.
	 */
	function bindCount( view, mount ) {
		var count = document.createElement( 'p' );
		count.className = 'packout-admin-view__count';
		var bar = document.createElement( 'div' );
		bar.className = 'packout-admin-view__bar';
		bar.appendChild( count );
		view.appendChild( bar );

		function paint() {
			var props = paginationOf( mount );
			count.textContent = props ? countLabel( props ) : '';
			var footer = mount.querySelector( '.dataviews-footer__content' ) || mount.querySelector( '.dataviews-footer' );
			if ( footer ) {
				if ( count.parentNode !== footer ) {
					footer.insertBefore( count, footer.firstChild );
				}
				bar.hidden = true;
			} else if ( count.parentNode !== bar ) {
				bar.appendChild( count );
				bar.hidden = '' === count.textContent;
			} else {
				bar.hidden = '' === count.textContent;
			}
		}

		var frame = 0;
		var observer = new window.MutationObserver( function () {
			window.cancelAnimationFrame( frame );
			frame = window.requestAnimationFrame( paint );
		} );
		observer.observe( mount, { childList: true, subtree: true } );
		paint();
	}

	/**
	 * What the runtime drew in a mount: its outermost element, once the Data View is inside it.
	 *
	 * @param {HTMLElement} mount View mount.
	 */
	function drawn( mount ) {
		return mount.querySelector( '.dataviews-wrapper' ) ? mount.firstElementChild : null;
	}

	/**
	 * Page size and totals from the Data View, once it has drawn.
	 *
	 * @param {HTMLElement} mount View mount.
	 */
	function paginationOf( mount ) {
		var host = drawn( mount );
		if ( ! host ) {
			return null;
		}
		var key = Object.keys( host ).find( function ( name ) {
			return 0 === name.indexOf( '__reactFiber' ) || 0 === name.indexOf( '__reactInternalInstance' );
		} );
		if ( ! key ) {
			return null;
		}
		var seen = new window.WeakSet();
		var stack = [ host[ key ] ];
		var steps = 0;
		while ( stack.length && steps < 500 ) {
			var fiber = stack.pop();
			steps++;
			if ( ! fiber || seen.has( fiber ) ) {
				continue;
			}
			seen.add( fiber );
			var props = fiber.memoizedProps;
			if ( props && props.paginationInfo && 'number' === typeof props.paginationInfo.totalItems && props.view ) {
				return props;
			}
			if ( fiber.child ) {
				stack.push( fiber.child );
			}
			if ( fiber.sibling ) {
				stack.push( fiber.sibling );
			}
		}
		return null;
	}

	/**
	 * "6 items", or "1–25 of 80" once the list has a second page.
	 *
	 * @param {{paginationInfo: {totalItems: number, totalPages: number}, view: {page?: number, perPage?: number}}} props Data View props.
	 */
	function countLabel( props ) {
		var total = props.paginationInfo.totalItems;
		var pages = props.paginationInfo.totalPages || 1;
		var page = props.view.page || 1;
		var per = props.view.perPage || 25;
		var i18n = window.wp && window.wp.i18n;
		var amount = function ( value ) {
			return Number( value ).toLocaleString();
		};
		if ( pages > 1 ) {
			var start = ( page - 1 ) * per + 1;
			var end = Math.min( total, page * per );
			if ( i18n ) {
				return i18n.sprintf(
					/* translators: 1: first item on this page, 2: last item, 3: all matching items. */
					i18n.__( '%1$s–%2$s of %3$s', 'packout-core' ),
					amount( start ),
					amount( end ),
					amount( total )
				);
			}
			return amount( start ) + '\u2013' + amount( end ) + ' of ' + amount( total );
		}
		if ( i18n ) {
			return i18n.sprintf( i18n._n( '%s item', '%s items', total, 'packout-core' ), amount( total ) );
		}
		return 1 === total ? '1 item' : amount( total ) + ' items';
	}

	function preview( pane ) {
		var box = pane ? pane.querySelector( '.packout-minimap' ) : null;
		if ( ! box || box.dataset.ready || ! window.maplibregl ) {
			return;
		}
		box.dataset.ready = '1';
		var center = [ Number( box.dataset.lng ), Number( box.dataset.lat ) ];
		var map = new window.maplibregl.Map( {
			container: box,
			style: window.packoutAdmin.mapStyle,
			center: center,
			zoom: 13,
			attributionControl: { compact: true },
		} );
		map.addControl( new window.maplibregl.NavigationControl( { showCompass: false } ), 'top-right' );
		new window.maplibregl.Marker( { color: '#bb5e28' } ).setLngLat( center ).addTo( map );
	}

	/**
	 * The outline of a Place. Shared MapLibre editor: click to add, drag to
	 * move, popover to delete. The textarea is what the form sends.
	 */
	function outline( pane ) {
		var box = pane ? pane.querySelector( '.packout-draw' ) : null;
		if ( ! box || box.dataset.ready ) {
			return;
		}
		if ( window.packoutMapEditor && window.packoutMapEditor.mount ) {
			window.packoutMapEditor.mount( box, {
				mode: 'outline',
				spots: ( window.packoutAdmin && window.packoutAdmin.spots ) || [],
				textarea: box.querySelector( '.packout-draw__text' ),
				count: box.querySelector( '.packout-draw__count' ),
			} );
			return;
		}
	}

	function paneFor( id ) {
		return root.querySelector( '.packout-drawer__pane[data-pane="' + String( id ).replace( /"/g, '' ) + '"]' );
	}

	function paneIdFrom( href ) {
		var mark = String( href || '' ).indexOf( '#packout-pane-' );
		if ( mark < 0 ) {
			return '';
		}
		var id = String( href ).slice( mark + '#packout-pane-'.length ).split( '&' )[ 0 ].split( '?' )[ 0 ];
		try {
			id = decodeURIComponent( id );
		} catch ( error ) {
			id = '';
		}
		return id;
	}

	function paneLabel( id, link ) {
		var row = link && link.closest ? link.closest( 'tr, [role="row"]' ) : null;
		var titled = row ? row.querySelector( 'a[href*="#packout-pane-"]' ) : null;
		var text = titled ? ( titled.textContent || '' ).trim() : '';
		if ( ! text && link ) {
			text = ( link.textContent || '' ).trim();
		}
		if ( text && text.length < 180 ) {
			return text;
		}
		var opener = root.querySelector( '[data-packout-open="' + String( id ).replace( /"/g, '' ) + '"]' );
		return opener ? ( opener.getAttribute( 'data-packout-label' ) || '' ) : '';
	}

	var drawer = window.packoutDrawer && root.querySelector( '.packout-drawer' ) ? window.packoutDrawer.bind( root ) : null;

	function showPane( id, label ) {
		if ( ! drawer || ! id || ! paneFor( id ) ) {
			return;
		}
		drawer.open( id, label || paneLabel( id, null ) );
		window.setTimeout( function () {
			preview( paneFor( id ) );
			outline( paneFor( id ) );
		}, 260 );
	}

	// Capture runs before the Data View follows the row link.
	root.addEventListener( 'click', function ( event ) {
		var link = event.target.closest ? event.target.closest( 'a[href*="#packout-pane-"]' ) : null;
		if ( ! link || ! drawer ) {
			return;
		}
		var id = paneIdFrom( link.getAttribute( 'href' ) || '' );
		if ( ! id || ! paneFor( id ) ) {
			return;
		}
		event.preventDefault();
		event.stopPropagation();
		showPane( id, paneLabel( id, link ) );
	}, true );

	root.addEventListener( 'click', function ( event ) {
		var opener = event.target.closest( '[data-packout-open]' );
		if ( opener ) {
			// The panel slides in first, so the map measures its real size.
			window.setTimeout( function () {
				preview( paneFor( opener.getAttribute( 'data-packout-open' ) ) );
				outline( paneFor( opener.getAttribute( 'data-packout-open' ) ) );
			}, 260 );
		}
	} );

	// The Spot picker of a mission can be long. Typing narrows it. Ticked Spots always stay in view.
	root.addEventListener( 'input', function ( event ) {
		if ( ! event.target.classList.contains( 'packout-pick__filter' ) ) {
			return;
		}
		var words = event.target.value.trim().toLowerCase();
		event.target.parentNode.querySelectorAll( '.packout-pick__item' ).forEach( function ( item ) {
			var ticked = item.querySelector( 'input' ).checked;
			item.hidden = '' !== words && ! ticked && item.textContent.toLowerCase().indexOf( words ) === -1;
		} );
	} );

	var open = new URLSearchParams( window.location.search ).get( 'open' ) || paneIdFrom( window.location.hash );
	if ( open ) {
		var opener = root.querySelector( '[data-packout-open="' + open.replace( /"/g, '' ) + '"]' );
		showPane( open, opener ? opener.getAttribute( 'data-packout-label' ) : '' );
	}

	overview();

	/**
	 * WordPress Help tab titles are plain text. Put the status icon first.
	 *
	 * @param {Object} tabs Tab ids to icon markup.
	 */
	function decorateSetupTabs( tabs ) {
		Object.keys( tabs || {} ).forEach( function ( id ) {
			var item = document.getElementById( 'tab-link-' + id );
			var link = item && item.querySelector( 'a' );
			var meta = tabs[ id ] || {};
			if ( ! link || link.querySelector( '.packout-setup-tab__icon' ) ) {
				return;
			}
			var icon = document.createElement( 'span' );
			icon.className = 'packout-setup-tab__icon packout-setup-tab__icon--' + ( meta.ok ? 'ready' : 'attention' );
			icon.setAttribute( 'aria-hidden', 'true' );
			icon.innerHTML = meta.icon || '';
			link.insertBefore( icon, link.firstChild );
			if ( meta.status ) {
				link.setAttribute( 'aria-label', ( link.textContent || '' ).trim() + ': ' + meta.status );
			}
		} );
	}

	/**
	 * Refresh Overview snapshots after the page has rendered with cached figures.
	 */
	function overview() {
		var cfg = window.packoutAdmin || {};
		decorateSetupTabs( cfg.setupTabs || {} );
		var page = document.querySelector( '.packout-overview' );
		if ( ! page ) {
			return;
		}
		var base = cfg.overview || '';
		// One organization's or one partner's overview names itself: "kind=team&id=12".
		var scope = page.getAttribute( 'data-packout-scope' ) || '';
		var nonce = cfg.nonce || '';
		var text = cfg.text || {};
		var fresh = Number( cfg.fresh || 60 );
		var now = readJson( 'packout-overview-now' );
		var period = readJson( 'packout-overview-period' );
		var chartHost = page.querySelector( '.packout-overview__chart' );

		Array.prototype.forEach.call( document.querySelectorAll( '[data-packout-open-setup]' ), function ( button ) {
			button.addEventListener( 'click', function () {
				var link = document.getElementById( 'contextual-help-link' );
				if ( link ) {
					link.click();
				}
			} );
		} );

		function readJson( id ) {
			var node = document.getElementById( id );
			if ( ! node ) {
				return {};
			}
			try {
				return JSON.parse( node.textContent || '{}' );
			} catch ( error ) {
				return {};
			}
		}

		function isFresh( payload ) {
			var at = Date.parse( payload && payload.computed_at ? payload.computed_at : '' );
			return ! isNaN( at ) && ( Date.now() - at ) / 1000 < fresh;
		}

		function get( obj, path ) {
			return String( path || '' ).split( '.' ).reduce( function ( cursor, key ) {
				return cursor && Object.prototype.hasOwnProperty.call( cursor, key ) ? cursor[ key ] : undefined;
			}, obj );
		}

		function format( value, signed ) {
			if ( value === null || typeof value === 'undefined' || value === '' ) {
				return '—';
			}
			var n = Number( value );
			if ( signed && n > 0 ) {
				return '+' + n.toLocaleString();
			}
			return n.toLocaleString();
		}

		function token( name, fallback ) {
			var value = window.getComputedStyle( page ).getPropertyValue( name ).trim();
			return value || fallback;
		}

		function fade( color, alpha ) {
			var hex = String( color || '' ).replace( '#', '' );
			if ( 3 === hex.length ) {
				hex = hex.charAt( 0 ) + hex.charAt( 0 ) + hex.charAt( 1 ) + hex.charAt( 1 ) + hex.charAt( 2 ) + hex.charAt( 2 );
			}
			if ( 6 !== hex.length ) {
				return color;
			}
			return 'rgba(' + parseInt( hex.slice( 0, 2 ), 16 ) + ',' + parseInt( hex.slice( 2, 4 ), 16 ) + ',' + parseInt( hex.slice( 4, 6 ), 16 ) + ',' + alpha + ')';
		}

		function dayLabel( iso ) {
			var parts = String( iso || '' ).split( '-' );
			if ( 3 !== parts.length ) {
				return iso;
			}
			var date = new Date( Number( parts[ 0 ] ), Number( parts[ 1 ] ) - 1, Number( parts[ 2 ] ) );
			if ( isNaN( date.getTime() ) ) {
				return iso;
			}
			return date.toLocaleDateString( undefined, { month: 'short', day: 'numeric' } );
		}

		function setUpdated( payload, failed ) {
			var node = page.querySelector( '[data-packout-updated]' );
			if ( ! node ) {
				return;
			}
			node.textContent = '';
			if ( failed ) {
				node.appendChild( document.createTextNode( text.refreshFail || 'Could not refresh' ) );
				node.appendChild( document.createTextNode( ' ' ) );
				var button = document.createElement( 'button' );
				button.type = 'button';
				button.className = 'button-link';
				button.textContent = text.retry || 'Retry';
				button.addEventListener( 'click', function () {
					refresh( true );
				} );
				node.appendChild( button );
				return;
			}
			var at = Date.parse( payload && payload.computed_at ? payload.computed_at : '' );
			if ( isNaN( at ) || ( Date.now() - at ) < 60000 ) {
				node.textContent = text.justNow || 'Updated just now';
				return;
			}
			var mins = Math.max( 1, Math.round( ( Date.now() - at ) / 60000 ) );
			node.textContent = ( text.updatedAgo || 'Updated %s ago' ).replace( '%s', mins + ( 1 === mins ? ' minute' : ' minutes' ) );
		}

		function applyPaths( payload ) {
			Array.prototype.forEach.call( page.querySelectorAll( '[data-packout-path]' ), function ( node ) {
				var value = get( payload, node.getAttribute( 'data-packout-path' ) );
				if ( typeof value === 'undefined' ) {
					return;
				}
				var signed = -1 !== ( node.getAttribute( 'data-packout-path' ) || '' ).indexOf( 'net_open' );
				var next = typeof value === 'string' ? value : format( value, signed );
				if ( node.textContent !== next ) {
					node.textContent = next;
				}
			} );
			Array.prototype.forEach.call( page.querySelectorAll( '[data-packout-prev]' ), function ( node ) {
				var value = get( payload, node.getAttribute( 'data-packout-prev' ) );
				if ( typeof value === 'undefined' ) {
					return;
				}
				var signed = -1 !== ( node.getAttribute( 'data-packout-prev' ) || '' ).indexOf( 'net_open' );
				var next = ( text.was || 'was %s' ).replace( '%s', format( value, signed ) );
				if ( node.textContent !== next ) {
					node.textContent = next;
				}
			} );
		}

		function applyNow( data ) {
			now = data || now;
			applyPaths( now );
			var waiting = now.waiting || {};
			var open = 0;
			var worst = '';
			var worstDays = -1;
			Object.keys( waiting ).forEach( function ( key ) {
				var row = waiting[ key ] || {};
				var count = Number( row.count || 0 );
				open += count;
				var tr = page.querySelector( '[data-packout-queue-key="' + key + '"]' );
				var zero = page.querySelector( '[data-packout-zero="' + key + '"]' );
				if ( tr ) {
					tr.hidden = count <= 0;
				}
				if ( zero ) {
					zero.hidden = count > 0;
				}
				if ( count > 0 && Number( row.days || 0 ) > worstDays ) {
					worstDays = Number( row.days || 0 );
					worst = row.age || '';
				}
			} );
			var table = page.querySelector( '[data-packout-queue-table]' );
			var empty = page.querySelector( '[data-packout-queue-empty]' );
			var zeros = page.querySelector( '[data-packout-queue-zeros]' );
			var heading = page.querySelector( '[data-packout-queue-heading]' );
			if ( table ) {
				table.hidden = open <= 0;
			}
			if ( empty ) {
				empty.hidden = open > 0;
			}
			if ( zeros ) {
				zeros.hidden = open <= 0;
			}
			if ( heading ) {
				heading.hidden = open <= 0;
				heading.textContent = open <= 0
					? ''
					: ( worst
						? ( text.tally || '%1$s open, oldest %2$s' ).replace( '%1$s', open.toLocaleString() ).replace( '%2$s', worst )
						: ( text.tallyPlain || '%s open' ).replace( '%s', open.toLocaleString() ) );
			}
			var stages = now.stages || {};
			Array.prototype.forEach.call( page.querySelectorAll( '.packout-overview__stack-seg' ), function ( seg ) {
				var match = /packout-overview__stack-seg--([a-z_]+)/.exec( seg.className );
				if ( ! match ) {
					return;
				}
				var count = Number( stages[ match[ 1 ] ] || 0 );
				seg.style.flexGrow = String( count );
				seg.hidden = count <= 0;
			} );
			// A row with nothing in it goes quiet.
			Array.prototype.forEach.call( page.querySelectorAll( '.packout-overview__lever' ), function ( lever ) {
				var badge = lever.querySelector( '.packout-overview__badge' );
				lever.classList.toggle( 'is-zero', ! badge || '0' === ( badge.textContent || '' ).trim() );
			} );
			setUpdated( now, false );
		}

		function applyPeriod( data ) {
			period = data || period;
			applyPaths( period );
			drawChart( period );
			var daysField = page.querySelector( '.packout-overview__export input[name="days"]' );
			if ( daysField && period.days ) {
				daysField.value = String( period.days );
			}
		}

		function drawChart( data ) {
			if ( ! chartHost || ! window.Chart ) {
				return;
			}
			if ( chartHost.packoutChart && typeof chartHost.packoutChart.destroy === 'function' ) {
				chartHost.packoutChart.destroy();
			}
			var canvas = chartHost.querySelector( 'canvas' );
			if ( ! canvas ) {
				canvas = document.createElement( 'canvas' );
				canvas.setAttribute( 'role', 'img' );
				canvas.setAttribute( 'aria-label', text.activity || 'Reports and cleanups' );
				chartHost.appendChild( canvas );
			}
			var series = data.series || [];
			var rust = token( '--packout-accent', '#c45c26' );
			var forest = token( '--packout-primary', '#415840' );
			var ink = token( '--packout-ink', '#1d2327' );
			var quiet = token( '--packout-muted', '#8c8f94' );
			var reduce = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
			chartHost.packoutChart = new window.Chart( canvas.getContext( '2d' ), {
				type: 'bar',
				data: {
					labels: series.map( function ( row ) {
						return row.day;
					} ),
					datasets: [
						{
							label: data.reports || text.reports,
							data: series.map( function ( row ) {
								return row.reports;
							} ),
							backgroundColor: rust,
							borderWidth: 0,
							borderRadius: 3,
							maxBarThickness: 14
						},
						{
							label: data.cleanups || text.cleanups,
							data: series.map( function ( row ) {
								return row.cleanups;
							} ),
							backgroundColor: forest,
							borderWidth: 0,
							borderRadius: 3,
							maxBarThickness: 14
						}
					]
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					animation: reduce ? false : { duration: 300 },
					interaction: { mode: 'index', intersect: false },
					plugins: {
						legend: {
							display: true,
							position: 'top',
							align: 'start',
							labels: {
								color: ink,
								usePointStyle: true,
								pointStyle: 'circle',
								boxWidth: 7,
								boxHeight: 7,
								padding: 16,
								font: { size: 12 }
							}
						},
						tooltip: {
							enabled: true,
							backgroundColor: ink,
							padding: 10,
							cornerRadius: 8,
							boxPadding: 4,
							usePointStyle: true,
							callbacks: {
								title: function ( items ) {
									if ( ! items.length ) {
										return '';
									}
									var row = series[ items[ 0 ].dataIndex ];
									if ( ! row ) {
										return '';
									}
									return ( data.bucket === 'week' ? ( text.week || 'Week of' ) + ' ' : '' ) + dayLabel( row.day );
								}
							}
						}
					},
					scales: {
						x: {
							ticks: {
								color: quiet,
								font: { size: 11 },
								maxRotation: 0,
								autoSkip: true,
								maxTicksLimit: series.length <= 7 ? 7 : 8,
								callback: function ( value ) {
									return dayLabel( this.getLabelForValue( value ) );
								}
							},
							grid: { display: false },
							border: { color: fade( ink, 0.16 ) }
						},
						y: {
							beginAtZero: true,
							ticks: { color: quiet, precision: 0, maxTicksLimit: 5, padding: 6, font: { size: 11 } },
							grid: { color: fade( ink, 0.08 ) },
							border: { display: false }
						}
					}
				}
			} );
		}

		function request( path ) {
			if ( scope ) {
				path += ( -1 === path.indexOf( '?' ) ? '?' : '&' ) + scope;
			}
			return window.fetch( base + path, {
				headers: { 'X-WP-Nonce': nonce },
				credentials: 'same-origin'
			} ).then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error();
				}
				return response.json();
			} );
		}

		function refresh( force ) {
			if ( ! base ) {
				return;
			}
			var jobs = [];
			if ( force || ! isFresh( now ) ) {
				jobs.push( request( 'now' ).then( applyNow ) );
			}
			var days = page.getAttribute( 'data-packout-days' ) || '30';
			if ( force || ! isFresh( period ) || String( period.days || '' ) !== String( days ) ) {
				jobs.push( request( 'period?days=' + encodeURIComponent( days ) ).then( applyPeriod ) );
			}
			if ( ! jobs.length ) {
				setUpdated( now, false );
				return;
			}
			Promise.all( jobs ).catch( function () {
				setUpdated( now, true );
			} );
		}

		applyNow( now );
		applyPeriod( period );
		refresh( false );

		var windowSelect = page.querySelector( '[data-packout-activity]' );
		if ( windowSelect ) {
			windowSelect.addEventListener( 'change', function () {
				var days = windowSelect.value || '30';
				page.setAttribute( 'data-packout-days', days );
				var daysField = page.querySelector( '.packout-overview__export input[name="days"]' );
				if ( daysField ) {
					daysField.value = days;
				}
				if ( ! base ) {
					return;
				}
				request( 'period?days=' + encodeURIComponent( days ) ).then( applyPeriod ).catch( function () {
					setUpdated( period, true );
				} );
			} );
		}
	}
} )();
